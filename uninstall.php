<?php
/**
 * Desinstalación. Por defecto conserva todos los datos. Solo borra si la administradora
 * activó explícitamente "Borrar todos los datos al eliminar" en Ajustes.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$mo_settings = get_option( 'mo_booking_settings', array() );
if ( empty( $mo_settings['delete_on_uninstall'] ) ) {
	return;
}

define( 'MO_BOOKING_DIR', __DIR__ . '/' );
require_once __DIR__ . '/src/autoload.php';

// Comprobantes privados.
$mo_dir = \MOBookingStudio\Infrastructure\PrivateStorage::dir();
if ( is_dir( $mo_dir ) ) {
	$mo_it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $mo_dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $mo_it as $mo_file ) {
		$mo_file->isDir() ? @rmdir( $mo_file->getPathname() ) : @unlink( $mo_file->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
	@rmdir( $mo_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
}

\MOBookingStudio\Infrastructure\Schema::drop();
\MOBookingStudio\Infrastructure\Capabilities::uninstall();
foreach ( array( 'mo_booking_settings', 'mo_booking_templates', 'mo_booking_version', 'mo_booking_frequent_threshold', 'mo_booking_queue_last_run' ) as $mo_option ) {
	delete_option( $mo_option );
}
wp_clear_scheduled_hook( 'mo_booking_process_queue' );
