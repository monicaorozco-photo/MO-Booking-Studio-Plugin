<?php
namespace MOBookingStudio\Frontend;

use MOBookingStudio\Application\Services;
use MOBookingStudio\Infrastructure\Settings;
use MOBookingStudio\Rest\Routes;

/**
 * Shortcodes [mo_booking service="slug"] y [mo_booking_portal]. El widget de Elementor
 * usa el mismo renderizado. Los assets solo se cargan en las páginas que los usan.
 */
final class Frontend {
	public function register(): void {
		add_action( 'init', array( $this, 'shortcodes' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'registerAssets' ) );
		add_action( 'template_redirect', array( $this, 'privateHeaders' ), 1 );
		add_action( 'template_redirect', array( $this, 'portalFallback' ), 5 );
	}

	public function shortcodes(): void {
		add_shortcode( 'mo_booking', array( $this, 'bookingShortcode' ) );
		add_shortcode( 'mo_booking_portal', array( $this, 'portalShortcode' ) );
	}

	public function registerAssets(): void {
		self::registerAssetHandles();
	}

	public static function registerAssetHandles(): void {
		$v = MO_BOOKING_VERSION;
		wp_register_style( 'mo-booking', MO_BOOKING_URL . 'assets/css/frontend.css', array(), $v );
		wp_register_script( 'mo-booking-common', MO_BOOKING_URL . 'assets/js/common.js', array(), $v, true );
		wp_register_script( 'mo-booking', MO_BOOKING_URL . 'assets/js/booking.js', array( 'mo-booking-common' ), $v, true );
		wp_register_script( 'mo-booking-portal', MO_BOOKING_URL . 'assets/js/portal.js', array( 'mo-booking-common' ), $v, true );
	}

	/**
	 * Textos editables de la pantalla de bienvenida (DEC-10). Clave => texto por omisión.
	 * Un subtítulo vacío no se muestra.
	 */
	public const WELCOME_TEXTS = array(
		'welcome_title'          => 'Antes de ver las fechas',
		'welcome_question'       => '¿Ya te has tomado fotos con nosotros?',
		'welcome_new_title'      => 'Soy cliente nuevo',
		'welcome_new_text'       => 'Ver fechas y precios',
		'welcome_returning_title' => 'Ya me he tomado fotos con ustedes',
		'welcome_returning_text' => 'Revisamos si aplica tu precio de cliente frecuente',
		'welcome_identify_title' => '¡Qué gusto verte de nuevo!',
		'welcome_identify_text'  => 'Escribe tu nombre y el correo y teléfono con los que reservaste antes.',
	);

	public function bookingShortcode( $atts ): string {
		$atts = shortcode_atts(
			array_merge(
				array(
					'service'    => '',
					'intro'      => '',
					'show_brand' => 'yes',
					'show_title' => 'yes',
				),
				self::WELCOME_TEXTS
			),
			(array) $atts,
			'mo_booking'
		);
		$texts = array();
		foreach ( self::WELCOME_TEXTS as $key => $default ) {
			$texts[ $key ] = (string) $atts[ $key ];
		}
		return self::renderBooking( (string) $atts['service'], array( 'intro' => (string) $atts['intro'], 'texts' => $texts, 'show_brand' => 'no' !== $atts['show_brand'], 'show_title' => 'no' !== $atts['show_title'] ) );
	}

	/** Solo texto plano; lo que no venga usa el texto por omisión. */
	public static function welcomeTexts( array $given ): array {
		$out = array();
		foreach ( self::WELCOME_TEXTS as $key => $default ) {
			$out[ substr( $key, 8 ) ] = array_key_exists( $key, $given ) ? sanitize_text_field( (string) $given[ $key ] ) : $default;
		}
		return $out;
	}

	public function portalShortcode( $atts = array() ): string {
		$atts = shortcode_atts( array( 'show_brand' => 'yes', 'show_title' => 'yes' ), (array) $atts, 'mo_booking_portal' );
		return self::renderPortal( false, array( 'show_brand' => 'no' !== $atts['show_brand'], 'show_title' => 'no' !== $atts['show_title'] ) );
	}

	/**
	 * Renderizado único para shortcode y widget.
	 *
	 * @param array $options intro, texts (WELCOME_TEXTS), preview (bool), show_description, show_policies, layout
	 */
	public static function renderBooking( string $slug, array $options = array() ): string {
		self::registerAssetHandles();
		$preview = ! empty( $options['preview'] );
		if ( ! $preview ) {
			$service = $slug ? Services::findBySlug( $slug ) : null;
			if ( ! $service || 'published' !== $service['status'] ) {
				if ( current_user_can( 'mo_manage_settings' ) ) {
					return '<div class="mo-booking mo-booking--notice">' . esc_html( $slug ? sprintf( 'MO Booking Studio: el servicio "%s" no existe o no está publicado.', $slug ) : 'MO Booking Studio: indica el servicio, por ejemplo [mo_booking service="mi-servicio"].' ) . '</div>';
				}
				return '';
			}
		}
		wp_enqueue_style( 'mo-booking' );
		wp_enqueue_script( 'mo-booking' );
		$config = array(
			'api'             => esc_url_raw( rest_url( Routes::NS . '/' ) ),
			'service'         => $slug,
			'preview'         => $preview,
			'intro'           => (string) ( $options['intro'] ?? '' ),
			'welcome'         => self::welcomeTexts( (array) ( $options['texts'] ?? array() ) ),
			'showDescription' => ! isset( $options['show_description'] ) || $options['show_description'],
			// beta.6: mostrar u ocultar la marca y el nombre del servicio arriba del formulario.
			'showBrand'       => ! isset( $options['show_brand'] ) || $options['show_brand'],
			'showTitle'       => ! isset( $options['show_title'] ) || $options['show_title'],
			// beta.4: las reglas de cancelación son de control interno; solo se muestran si se pide.
			'showPolicies'    => ! empty( $options['show_policies'] ) && 'no' !== $options['show_policies'],
			'portalUrl'       => esc_url_raw( \MOBookingStudio\Application\Bookings::portalBase() ),
		);
		$layout = (string) ( $options['layout'] ?? 'columns' );
		$layout = in_array( $layout, array( 'stacked', 'columns' ), true ) ? $layout : 'columns';
		return sprintf(
			'<div class="mo-booking mo-booking--%1$s" data-mo-booking="%2$s"><noscript>%3$s</noscript><p class="mo-booking__loading" role="status">%4$s</p></div>',
			esc_attr( $layout ),
			esc_attr( wp_json_encode( $config ) ),
			esc_html__( 'Para reservar necesitas activar JavaScript en tu navegador.', 'mo-booking-studio' ),
			esc_html__( 'Cargando disponibilidad…', 'mo-booking-studio' )
		);
	}

	public static function renderPortal( bool $preview = false, array $options = array() ): string {
		self::registerAssetHandles();
		wp_enqueue_style( 'mo-booking' );
		wp_enqueue_script( 'mo-booking-portal' );
		$config = array(
			'api'     => esc_url_raw( rest_url( Routes::NS . '/' ) ),
			'preview'   => $preview,
			'showBrand' => ! isset( $options['show_brand'] ) || $options['show_brand'],
			'showTitle' => ! isset( $options['show_title'] ) || $options['show_title'],
		);
		return sprintf(
			'<div class="mo-booking mo-booking-portal" data-mo-portal="%1$s"><noscript>%2$s</noscript><p class="mo-booking__loading" role="status">%3$s</p></div>',
			esc_attr( wp_json_encode( $config ) ),
			esc_html__( 'Para ver tu reserva necesitas activar JavaScript en tu navegador.', 'mo-booking-studio' ),
			esc_html__( 'Cargando tu reserva…', 'mo-booking-studio' )
		);
	}

	/**
	 * Las páginas de reserva y portal no deben guardarse en caché de página ni enviar
	 * el enlace como referencia a otros sitios.
	 */
	public function privateHeaders(): void {
		$isPortal = isset( $_GET['mo_portal'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$post     = get_queried_object();
		if ( ! $isPortal && $post instanceof \WP_Post ) {
			$isPortal = (int) Settings::get( 'portal_page_id' ) === (int) $post->ID || has_shortcode( (string) $post->post_content, 'mo_booking_portal' );
			$isBooking = has_shortcode( (string) $post->post_content, 'mo_booking' ) || (int) Settings::get( 'booking_page_id' ) === (int) $post->ID;
		} else {
			$isBooking = false;
		}
		if ( $isPortal || $isBooking ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
			nocache_headers();
			header( 'Referrer-Policy: no-referrer' );
		}
	}

	/** Ruta de respaldo del portal (?mo_portal=1) si todavía no hay página configurada. */
	public function portalFallback(): void {
		if ( ! isset( $_GET['mo_portal'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		status_header( 200 );
		header( 'X-Robots-Tag: noindex, nofollow' );
		add_filter( 'pre_get_document_title', static function () {
			return 'Mi reserva';
		} );
		add_action( 'wp_head', static function () {
			echo '<meta name="robots" content="noindex, nofollow">' . "\n";
		} );
		$content = self::renderPortal();
		if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			?><!doctype html><html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width, initial-scale=1"><?php wp_head(); ?></head><body <?php body_class(); ?>><main class="mo-booking-portal-page" style="max-width:1120px;margin:0 auto;padding:24px 16px"><?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput ?></main><?php wp_footer(); ?></body></html><?php
		} else {
			get_header();
			echo '<main class="mo-booking-portal-page" style="max-width:1120px;margin:0 auto;padding:24px 16px">' . $content . '</main>'; // phpcs:ignore WordPress.Security.EscapeOutput
			get_footer();
		}
		exit;
	}
}
