<?php
/**
 * Plugin Name:       MO Booking Studio
 * Description:       Reservas, disponibilidad, pagos por transferencia y administración para estudio fotográfico.
 * Version:           0.2.0-beta.4.1
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Mónica Orozco
 * License:           GPL-2.0-or-later
 * Text Domain:       mo-booking-studio
 * Domain Path:       /languages
 *
 * Este archivo debe poder interpretarse en versiones antiguas de PHP para
 * mostrar el aviso de requisitos sin error fatal: no usar sintaxis moderna aquí.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MO_BOOKING_VERSION', '0.2.0-beta.4.1' );
define( 'MO_BOOKING_MIN_PHP', '7.4' );
define( 'MO_BOOKING_FILE', __FILE__ );
define( 'MO_BOOKING_DIR', plugin_dir_path( __FILE__ ) );
define( 'MO_BOOKING_URL', plugin_dir_url( __FILE__ ) );

/**
 * SEG-06: requisitos del servidor. Si falta alguno, el plugin no se activa ni carga a
 * medias; solo muestra un aviso comprensible.
 */
function mo_booking_missing_requirements() {
	$missing = array();
	if ( version_compare( PHP_VERSION, MO_BOOKING_MIN_PHP, '<' ) ) {
		$missing[] = sprintf( 'PHP %1$s o superior (este sitio usa PHP %2$s)', MO_BOOKING_MIN_PHP, PHP_VERSION );
	}
	if ( ! extension_loaded( 'mbstring' ) ) {
		$missing[] = 'la extensión mbstring de PHP';
	}
	if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
		$missing[] = 'la extensión sodium (libsodium) de PHP, necesaria para cifrar la conexión con Google';
	}
	return $missing;
}

function mo_booking_php_message() {
	return 'MO Booking Studio no se puede activar porque falta: ' . implode( '; ', mo_booking_missing_requirements() ) . '. Pide a tu hosting que lo habilite y vuelve a activar el plugin.';
}

if ( mo_booking_missing_requirements() ) {
	register_activation_hook( __FILE__, 'mo_booking_reject_activation' );
	add_action( 'admin_notices', 'mo_booking_php_notice' );
	return;
}

function mo_booking_reject_activation() {
	deactivate_plugins( plugin_basename( MO_BOOKING_FILE ) );
	wp_die( esc_html( mo_booking_php_message() ), 'MO Booking Studio', array( 'back_link' => true ) );
}

function mo_booking_php_notice() {
	echo '<div class="notice notice-error"><p>' . esc_html( mo_booking_php_message() ) . '</p></div>';
}

require_once MO_BOOKING_DIR . 'src/autoload.php';

register_activation_hook( __FILE__, array( 'MOBookingStudio\\Infrastructure\\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'MOBookingStudio\\Infrastructure\\Installer', 'deactivate' ) );

MOBookingStudio\Plugin::instance()->boot();
