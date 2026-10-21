<?php
namespace MOBookingStudio\Rest;

use MOBookingStudio\Application\Bookings;
use MOBookingStudio\Application\Catalog;
use MOBookingStudio\Application\FrequentCheck;
use MOBookingStudio\Application\Portal;
use MOBookingStudio\Application\Proofs;
use MOBookingStudio\Application\Services;
use MOBookingStudio\Domain\DomainException;
use MOBookingStudio\Infrastructure\Logger;
use MOBookingStudio\Infrastructure\RateLimit;
use MOBookingStudio\Infrastructure\Tokens;

/**
 * API REST mo-booking/v1. Contrato documentado en docs/api.md.
 *
 * Las rutas públicas solo exponen servicios publicados, disponibilidad sin nombres y
 * cotizaciones. Las del portal exigen la cookie de sesión creada a partir del enlace
 * privado, y las que modifican datos exigen además la cabecera X-MO-CSRF.
 */
final class Routes {
	public const NS = 'mo-booking/v1';

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_filter( 'rest_post_dispatch', array( $this, 'headers' ), 10, 3 );
		add_filter( 'rest_pre_serve_request', array( $this, 'noCors' ), 20, 3 );
	}

	public function routes(): void {
		$public = '__return_true'; // Autorización explícita: rutas públicas sin datos personales.
		$slug   = '(?P<slug>[a-z0-9-]+)';

		register_rest_route( self::NS, '/services', array( 'methods' => 'GET', 'callback' => $this->wrap( array( $this, 'services' ) ), 'permission_callback' => $public ) );
		register_rest_route( self::NS, '/services/' . $slug, array( 'methods' => 'GET', 'callback' => $this->wrap( array( $this, 'service' ) ), 'permission_callback' => $public ) );
		register_rest_route(
			self::NS,
			'/services/' . $slug . '/availability',
			array(
				'methods'             => 'GET',
				'callback'            => $this->wrap( array( $this, 'availability' ) ),
				'permission_callback' => $public,
				'args'                => array(
					'from' => array( 'required' => true, 'type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$' ),
					'to'   => array( 'required' => true, 'type' => 'string', 'pattern' => '^\d{4}-\d{2}-\d{2}$' ),
				),
			)
		);
		register_rest_route( self::NS, '/services/' . $slug . '/identify', array( 'methods' => 'POST', 'callback' => $this->wrap( array( $this, 'identify' ) ), 'permission_callback' => $public ) );
		register_rest_route( self::NS, '/services/' . $slug . '/quote', array( 'methods' => 'POST', 'callback' => $this->wrap( array( $this, 'quote' ) ), 'permission_callback' => $public ) );
		register_rest_route( self::NS, '/services/' . $slug . '/bookings', array( 'methods' => 'POST', 'callback' => $this->wrap( array( $this, 'book' ) ), 'permission_callback' => $public ) );

		register_rest_route( self::NS, '/portal/session', array( 'methods' => 'POST', 'callback' => $this->wrap( array( $this, 'portalSession' ) ), 'permission_callback' => $public ) );
		register_rest_route( self::NS, '/portal/booking', array( 'methods' => 'GET', 'callback' => $this->wrap( array( $this, 'portalBooking' ) ), 'permission_callback' => $public ) );
		register_rest_route( self::NS, '/portal/proofs', array( 'methods' => 'POST', 'callback' => $this->wrap( array( $this, 'portalProof' ) ), 'permission_callback' => $public ) );
		register_rest_route( self::NS, '/portal/logout', array( 'methods' => 'POST', 'callback' => $this->wrap( array( $this, 'portalLogout' ) ), 'permission_callback' => $public ) );
	}

	/**
	 * Respuestas privadas y de disponibilidad sin caché: no deben servirse cupos viejos.
	 */
	public function headers( $response, $server, $request ) {
		if ( 0 === strpos( (string) $request->get_route(), '/' . self::NS ) && $response instanceof \WP_REST_Response ) {
			$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, private' );
			$response->header( 'Pragma', 'no-cache' );
			$response->header( 'Referrer-Policy', 'no-referrer' );
			$response->header( 'X-Robots-Tag', 'noindex, nofollow' );
		}
		return $response;
	}

	/**
	 * WordPress refleja el origen en las cabeceras CORS. Las rutas del plugin solo se usan
	 * desde el propio sitio, así que se retiran para no permitir lecturas desde otros orígenes.
	 */
	public function noCors( $served, $result, $request ) {
		if ( 0 === strpos( (string) $request->get_route(), '/' . self::NS ) && ! headers_sent() ) {
			header_remove( 'Access-Control-Allow-Origin' );
			header_remove( 'Access-Control-Allow-Credentials' );
		}
		return $served;
	}

	private function wrap( callable $handler ): callable {
		return static function ( \WP_REST_Request $request ) use ( $handler ) {
			if ( \MOBookingStudio\Plugin::maintenance() ) {
				return new \WP_Error( 'mo_maintenance', 'Estamos actualizando el sistema de reservas. Intenta de nuevo en unos minutos.', array( 'status' => 503 ) );
			}
			try {
				return rest_ensure_response( $handler( $request ) );
			} catch ( DomainException $e ) {
				$data = array_merge( array( 'status' => $e->httpStatus() ), $e->details() );
				return new \WP_Error( 'mo_' . $e->errorCode(), $e->getMessage(), $data );
			} catch ( \Throwable $e ) {
				Logger::error( 'Error inesperado en la API', array( 'route' => $request->get_route(), 'error' => $e->getMessage(), 'file' => basename( $e->getFile() ) . ':' . $e->getLine() ) );
				return new \WP_Error( 'mo_server_error', 'Ocurrió un error inesperado. Intenta de nuevo en unos minutos.', array( 'status' => 500 ) );
			}
		};
	}

	public function services(): array {
		return array_map( array( Catalog::class, 'publicService' ), Services::all( 'published' ) );
	}

	public function service( \WP_REST_Request $r ): array {
		return Catalog::publicService( Services::findPublished( (string) $r['slug'] ) );
	}

	public function availability( \WP_REST_Request $r ): array {
		RateLimit::hit( 'availability', 120, 600 );
		// La verificación de cliente frecuente viaja en cabecera, no en la URL.
		$category = FrequentCheck::category( (string) $r->get_header( 'x_mo_frequent' ) );
		return Catalog::availability( Services::findPublished( (string) $r['slug'] ), (string) $r['from'], (string) $r['to'], $category );
	}

	public function identify( \WP_REST_Request $r ): array {
		RateLimit::hit( 'identify', 10, 600 );
		Services::findPublished( (string) $r['slug'] );
		return FrequentCheck::identify( (array) $r->get_json_params() );
	}

	public function quote( \WP_REST_Request $r ): array {
		RateLimit::hit( 'quote', 60, 600 );
		$body = (array) $r->get_json_params();
		return Catalog::quote( Services::findPublished( (string) $r['slug'] ), $body );
	}

	public function book( \WP_REST_Request $r ): \WP_REST_Response {
		RateLimit::hit( 'booking', 8, 600 );
		$service = Services::findPublished( (string) $r['slug'] );
		$result  = Bookings::create( $service, (array) $r->get_json_params() );
		$booking = $result['booking'];
		$session = Tokens::startSession( $result['token'] );
		Portal::setCookie( $session );
		$response = new \WP_REST_Response(
			array(
				'reference'  => $booking['public_ref'],
				'replayed'   => $result['replayed'],
				'portal_url' => Bookings::portalUrl( $booking ),
				'csrf'       => $session['csrf'],
				'booking'    => Portal::view( $booking ),
			),
			$result['replayed'] ? 200 : 201
		);
		return $response;
	}

	public function portalSession( \WP_REST_Request $r ): array {
		RateLimit::hit( 'portal_session', 20, 600 );
		$token   = (string) ( $r->get_json_params()['token'] ?? '' );
		$session = Tokens::startSession( $token );
		Portal::setCookie( $session );
		return array(
			'csrf'    => $session['csrf'],
			'booking' => Portal::view( Bookings::get( $session['booking_id'] ) ),
		);
	}

	public function portalBooking( \WP_REST_Request $r ): array {
		$id = Tokens::sessionBooking( Portal::cookie(), null, false );
		return array(
			'csrf'    => Tokens::rotateCsrf( (string) Portal::cookie() ),
			'booking' => Portal::view( Bookings::get( $id ) ),
		);
	}

	public function portalProof( \WP_REST_Request $r ): array {
		RateLimit::hit( 'proof_upload', 10, 600 );
		$id    = Tokens::sessionBooking( Portal::cookie(), (string) $r->get_header( 'x_mo_csrf' ), true );
		$files = $r->get_file_params();
		if ( empty( $files['file'] ) ) {
			throw new DomainException( 'no_file', 'Elige un archivo para subir.', 400 );
		}
		// PAY-04: además del límite por IP, un límite por reserva.
		RateLimit::hit( 'proof_booking_' . $id, 6, 3600 );
		Proofs::upload( $id, $files['file'] );
		return array(
			'message' => 'Recibimos tu comprobante. Tu horario sigue apartado mientras verificamos tu depósito.',
			'booking' => Portal::view( Bookings::get( $id ) ),
		);
	}

	public function portalLogout( \WP_REST_Request $r ): array {
		$session = Portal::cookie();
		Tokens::sessionBooking( $session, (string) $r->get_header( 'x_mo_csrf' ), true );
		Tokens::endSession( $session );
		Portal::clearCookie();
		return array( 'ok' => true );
	}
}
