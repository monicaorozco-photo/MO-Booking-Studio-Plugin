<?php
/**
 * Desinstalación (SEG-02). Por defecto conserva todos los datos. Solo borra si la
 * administradora activó "Borrar todos los datos al desinstalar" Y confirmó por separado
 * la ruta exacta de la carpeta de comprobantes.
 *
 * De los comprobantes solo se borran los archivos registrados en la base de datos, y
 * solo si la carpeta tiene el marcador de propiedad del plugin y no es una carpeta
 * protegida o compartida. Nunca se borra una carpeta de forma recursiva.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$mo_settings = get_option( 'mo_booking_settings', array() );
if ( empty( $mo_settings['delete_on_uninstall'] ) ) {
	return;
}

if ( ! defined( 'MO_BOOKING_DIR' ) ) {
	define( 'MO_BOOKING_DIR', __DIR__ . '/' );
}
require_once __DIR__ . '/src/autoload.php';

use MOBookingStudio\Infrastructure\PrivateStorage;

// Sin la confirmación de la ruta exacta no se borra nada.
$mo_dir = PrivateStorage::canonical( PrivateStorage::dir() );
if ( empty( $mo_settings['delete_confirm_path'] ) || $mo_settings['delete_confirm_path'] !== $mo_dir ) {
	return;
}

global $wpdb;
$mo_proofs = $wpdb->prefix . 'mo_proofs';
$mo_names  = array();
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $mo_proofs ) ) ) {
	$mo_names = $wpdb->get_col( "SELECT stored_name FROM $mo_proofs WHERE stored_name <> ''" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}
$mo_result = PrivateStorage::purge( (array) $mo_names );
if ( '' !== $mo_result['refused'] ) {
	// La carpeta no es claramente del plugin: se conservan archivos Y datos para no dejar comprobantes huérfanos.
	return;
}

\MOBookingStudio\Infrastructure\Schema::drop();
\MOBookingStudio\Infrastructure\Capabilities::uninstall();
foreach ( array( 'mo_booking_settings', 'mo_booking_templates', 'mo_booking_version', 'mo_booking_frequent_threshold', 'mo_booking_queue_last_run', 'mo_booking_google', 'mo_booking_site_key', 'mo_booking_token_key', 'mo_booking_migrations', 'mo_booking_backup_confirmed', 'mo_booking_schema_version', 'mo_booking_custom_templates', 'mo_booking_not_duplicates' ) as $mo_option ) {
	delete_option( $mo_option );
}
wp_clear_scheduled_hook( 'mo_booking_process_queue' );
