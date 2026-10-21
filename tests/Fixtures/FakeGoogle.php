<?php
namespace MOBookingStudio\Tests\Fixtures;

use MOBookingStudio\Infrastructure\Crypto;
use MOBookingStudio\Integrations\Google\Connection;

/**
 * Google Calendar simulado sobre el filtro pre_http_request de WordPress.
 * Cubre token, revocación, lista de calendarios, FreeBusy y CRUD de eventos, cuenta
 * invitaciones enviadas y permite inyectar fallas. No sale ninguna solicitud a internet.
 */
final class FakeGoogle {
	public const DESTINATION = 'estudio@example.com';
	public const BUSY_CAL    = 'ocupado@group.calendar.google.com';

	/** @var array<string,array<string,array>> calendario => id => evento */
	public $events = array();
	/** @var array<string,array<array{start:string,end:string}>> calendario => bloques FreeBusy */
	public $busy = array();
	/** @var array<array{method:string,url:string,body:mixed}> */
	public $requests = array();
	/** Invitaciones (inserciones o cambios con sendUpdates=all y personas invitadas). */
	public $invitations = 0;
	/** Avisos de cancelación a invitados. */
	public $cancellations = 0;
	/** @var int[] Códigos HTTP a devolver en las siguientes solicitudes a Calendar. */
	public $failNext = array();
	/** Google caído: toda solicitud falla con error de conexión. */
	public $down = false;
	/** Acceso revocado: el token se rechaza con invalid_grant y Calendar con 401. */
	public $revoked = false;
	/** @var string[] Calendarios que FreeBusy no puede leer. */
	public $brokenCalendars = array();
	private $tokens = 0;

	public static function install(): self {
		$fake = new self();
		add_filter( 'pre_http_request', array( $fake, 'handle' ), 10, 3 );
		return $fake;
	}

	public function uninstall(): void {
		remove_filter( 'pre_http_request', array( $this, 'handle' ), 10 );
	}

	/** Deja la conexión lista, como después de autorizar y elegir calendarios. */
	public function connect( array $read = array( self::DESTINATION, self::BUSY_CAL ) ): void {
		Connection::save(
			array(
				'status'         => 'connected',
				'refresh'        => Crypto::encrypt( 'refresh-fake' ),
				'access'         => Crypto::encrypt( 'access-fake' ),
				'access_expires' => time() + 10 * YEAR_IN_SECONDS,
				'account'        => self::DESTINATION,
				'read_calendars' => $read,
				'calendar_names' => array( self::DESTINATION => 'MO Reservas (prueba)', self::BUSY_CAL => 'Ocupado (prueba)' ),
				'destination'    => self::DESTINATION,
			)
		);
	}

	/** Agrega un evento externo (no creado por el plugin) al calendario indicado. */
	public function addExternal( string $calendar, string $startUtc, string $endUtc, array $extra = array() ): string {
		$id = 'ext' . count( $this->events[ $calendar ] ?? array() ) . bin2hex( random_bytes( 3 ) );
		$this->events[ $calendar ][ $id ] = array_merge(
			array(
				'id'     => $id,
				'status' => 'confirmed',
				'start'  => array( 'dateTime' => $startUtc ),
				'end'    => array( 'dateTime' => $endUtc ),
			),
			$extra
		);
		return $id;
	}

	/** Eventos vigentes del plugin en el calendario destino. */
	public function owned( ?string $kind = null ): array {
		return array_values(
			array_filter(
				$this->events[ self::DESTINATION ] ?? array(),
				static function ( $e ) use ( $kind ) {
					return 'cancelled' !== $e['status'] && isset( $e['extendedProperties']['private']['mo_owner'] )
						&& ( null === $kind || $e['extendedProperties']['private']['mo_kind'] === $kind );
				}
			)
		);
	}

	public function count( string $method, string $pathContains ): int {
		return count(
			array_filter(
				$this->requests,
				static function ( $r ) use ( $method, $pathContains ) {
					return $r['method'] === $method && false !== strpos( $r['url'], $pathContains );
				}
			)
		);
	}

	/**
	 * @param false|array $pre
	 * @return false|array|\WP_Error
	 */
	public function handle( $pre, array $args, string $url ) {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( ! in_array( $host, array( 'oauth2.googleapis.com', 'www.googleapis.com' ), true ) ) {
			return $pre;
		}
		$method = strtoupper( (string) ( $args['method'] ?? 'GET' ) );
		$body   = $args['body'] ?? null;
		if ( is_string( $body ) && '' !== $body ) {
			$body = json_decode( $body, true );
		}
		$this->requests[] = array( 'method' => $method, 'url' => $url, 'body' => $body );
		if ( $this->down ) {
			return new \WP_Error( 'http_request_failed', 'cURL error 28: timeout' );
		}
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		if ( 'oauth2.googleapis.com' === $host ) {
			return $this->oauth( $path, (array) $body );
		}
		if ( $this->revoked ) {
			return $this->error( 401, 'authError' );
		}
		if ( $this->failNext ) {
			$code = array_shift( $this->failNext );
			return $this->error( $code, 429 === $code ? 'rateLimitExceeded' : 'backendError' );
		}
		$path = substr( $path, strlen( '/calendar/v3' ) );

		if ( '/users/me/calendarList' === $path ) {
			return $this->json(
				array(
					'items' => array(
						array( 'id' => self::DESTINATION, 'summary' => 'MO Reservas (prueba)', 'accessRole' => 'owner', 'primary' => true ),
						array( 'id' => self::BUSY_CAL, 'summary' => 'Ocupado (prueba)', 'accessRole' => 'reader' ),
					),
				)
			);
		}
		if ( '/freeBusy' === $path ) {
			$out = array();
			foreach ( (array) $body['items'] as $item ) {
				$id = $item['id'];
				if ( in_array( $id, $this->brokenCalendars, true ) ) {
					$out[ $id ] = array( 'errors' => array( array( 'domain' => 'global', 'reason' => 'notFound' ) ), 'busy' => array() );
					continue;
				}
				$blocks = array();
				foreach ( $this->busyFor( $id ) as $b ) {
					if ( strtotime( $b['end'] ) > strtotime( $body['timeMin'] ) && strtotime( $b['start'] ) < strtotime( $body['timeMax'] ) ) {
						$blocks[] = $b;
					}
				}
				$out[ $id ] = array( 'busy' => $blocks );
			}
			return $this->json( array( 'calendars' => $out ) );
		}
		if ( preg_match( '#^/calendars/([^/]+)/events(?:/([^/]+))?$#', $path, $m ) ) {
			$cal = rawurldecode( $m[1] );
			$id  = isset( $m[2] ) ? rawurldecode( $m[2] ) : '';
			return $this->events( $method, $cal, $id, $query, (array) $body );
		}
		return $this->error( 404, 'notFound' );
	}

	/** FreeBusy de un calendario: bloques declarados más sus eventos opacos. */
	private function busyFor( string $calendar ): array {
		$blocks = $this->busy[ $calendar ] ?? array();
		foreach ( $this->events[ $calendar ] ?? array() as $e ) {
			if ( 'cancelled' !== $e['status'] && 'transparent' !== ( $e['transparency'] ?? 'opaque' ) && isset( $e['start']['dateTime'] ) ) {
				$blocks[] = array( 'start' => $e['start']['dateTime'], 'end' => $e['end']['dateTime'] );
			}
		}
		return $blocks;
	}

	private function oauth( string $path, array $body ) {
		if ( '/revoke' === $path ) {
			$this->revoked = true;
			return $this->json( array() );
		}
		if ( $this->revoked ) {
			return $this->json( array( 'error' => 'invalid_grant' ), 400 );
		}
		++$this->tokens;
		$data = array(
			'access_token' => 'access-' . $this->tokens,
			'expires_in'   => 3599,
			'token_type'   => 'Bearer',
			'scope'        => implode( ' ', Connection::SCOPES ),
		);
		if ( 'authorization_code' === ( $body['grant_type'] ?? '' ) ) {
			$data['refresh_token'] = 'refresh-' . $this->tokens;
		}
		return $this->json( $data );
	}

	private function events( string $method, string $cal, string $id, array $query, array $body ) {
		$store = &$this->events[ $cal ];
		if ( null === $store ) {
			$store = array();
		}
		if ( '' === $id && 'GET' === $method ) {
			$items = array();
			$owner = isset( $query['privateExtendedProperty'] ) ? explode( '=', (string) $query['privateExtendedProperty'], 2 ) : null;
			foreach ( $store as $e ) {
				if ( 'cancelled' === $e['status'] ) {
					continue;
				}
				$s = strtotime( $e['start']['dateTime'] ?? $e['start']['date'] );
				$f = strtotime( $e['end']['dateTime'] ?? $e['end']['date'] );
				if ( $f <= strtotime( $query['timeMin'] ) || $s >= strtotime( $query['timeMax'] ) ) {
					continue;
				}
				if ( $owner && ( $e['extendedProperties']['private'][ $owner[0] ] ?? null ) !== $owner[1] ) {
					continue;
				}
				$items[] = $e;
			}
			return $this->json( array( 'items' => $items ) );
		}
		$notify = 'all' === ( $query['sendUpdates'] ?? 'none' );
		if ( '' === $id && 'POST' === $method ) {
			$new = (string) ( $body['id'] ?? 'gen' . bin2hex( random_bytes( 6 ) ) );
			if ( isset( $store[ $new ] ) ) {
				return $this->error( 409, 'duplicate' );
			}
			$store[ $new ] = array_merge( array( 'status' => 'confirmed' ), $body, array( 'id' => $new, 'etag' => '"' . bin2hex( random_bytes( 4 ) ) . '"' ) );
			if ( $notify && ! empty( $body['attendees'] ) ) {
				$this->invitations += count( $body['attendees'] );
			}
			return $this->json( $store[ $new ] );
		}
		if ( ! isset( $store[ $id ] ) ) {
			return $this->error( 404, 'notFound' );
		}
		if ( 'GET' === $method ) {
			return $this->json( $store[ $id ] );
		}
		if ( 'PATCH' === $method ) {
			$wasCancelled  = 'cancelled' === $store[ $id ]['status'];
			$store[ $id ]  = array_merge( $store[ $id ], $body, array( 'etag' => '"' . bin2hex( random_bytes( 4 ) ) . '"' ) );
			if ( $notify && ! empty( $store[ $id ]['attendees'] ) && $wasCancelled ) {
				$this->invitations += count( $store[ $id ]['attendees'] );
			}
			return $this->json( $store[ $id ] );
		}
		if ( 'DELETE' === $method ) {
			if ( 'cancelled' === $store[ $id ]['status'] ) {
				return $this->error( 410, 'deleted' );
			}
			$store[ $id ]['status'] = 'cancelled';
			if ( $notify && ! empty( $store[ $id ]['attendees'] ) ) {
				++$this->cancellations;
			}
			return array( 'headers' => array(), 'body' => '', 'response' => array( 'code' => 204, 'message' => 'No Content' ), 'cookies' => array(), 'filename' => null );
		}
		return $this->error( 400, 'badRequest' );
	}

	private function json( array $data, int $code = 200 ): array {
		return array(
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( $data ),
			'response' => array( 'code' => $code, 'message' => 'OK' ),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	private function error( int $code, string $reason ): array {
		return $this->json( array( 'error' => array( 'code' => $code, 'errors' => array( array( 'reason' => $reason ) ) ) ), $code );
	}
}
