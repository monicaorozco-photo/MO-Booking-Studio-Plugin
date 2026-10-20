<?php
/**
 * Las pruebas unitarias no cargan WordPress. Las de integración sí, cuando existe
 * la variable MO_WP_DIR con una instalación de pruebas (ver tests/README.md).
 */
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

$wpDir = getenv( 'MO_WP_DIR' );
if ( $wpDir ) {
	require_once __DIR__ . '/Integration/wp-bootstrap.php';
} else {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
	require_once dirname( __DIR__ ) . '/src/autoload.php';
}
