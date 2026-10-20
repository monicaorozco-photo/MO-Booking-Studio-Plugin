<?php
/**
 * Carga un WordPress real de pruebas (base de datos propia) e instala el plugin.
 * Preparar con bin/test-env.sh y ejecutar con MO_WP_DIR=/ruta vendor/bin/phpunit --testsuite integration
 */
$wpDir = rtrim( (string) getenv( 'MO_WP_DIR' ), '/' );
if ( ! defined( 'WP_USE_THEMES' ) ) {
	define( 'WP_USE_THEMES', false );
}
$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/';

if ( ! defined( 'WP_INSTALLING' ) && ! getenv( 'MO_WP_INSTALLED' ) ) {
	// Instalar WordPress una vez si la base está vacía.
	define( 'WP_INSTALLING', true );
	require_once $wpDir . '/wp-load.php';
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	if ( ! is_blog_installed() ) {
		wp_install( 'Pruebas MO', 'admin', 'admin@example.com', false, '', 'admin-password-123' );
	}
} else {
	require_once $wpDir . '/wp-load.php';
}
require_once ABSPATH . 'wp-admin/includes/plugin.php';

if ( ! defined( 'MO_BOOKING_VERSION' ) ) {
	// El plugin aún no estaba activo: cargarlo y activarlo como lo haría WordPress.
	require_once WP_PLUGIN_DIR . '/mo-booking-studio/mo-booking-studio.php';
	\MOBookingStudio\Infrastructure\Installer::activate();
	$active   = (array) get_option( 'active_plugins', array() );
	$active[] = 'mo-booking-studio/mo-booking-studio.php';
	update_option( 'active_plugins', array_values( array_unique( $active ) ) );
	do_action( 'plugins_loaded' );
	do_action( 'init' );
}
wp_set_current_user( 1 );
