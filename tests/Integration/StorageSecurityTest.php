<?php
namespace MOBookingStudio\Tests\Integration;

use MOBookingStudio\Infrastructure\PrivateStorage;
use MOBookingStudio\Infrastructure\Schema;
use MOBookingStudio\Infrastructure\Settings;

/**
 * SEG-01 (carpeta privada) y SEG-02 (desinstalación segura).
 */
final class StorageSecurityTest extends IntegrationTestCase {
	/** @var string */
	private $base;
	/** @var callable|null */
	private $dirFilter;
	/** @var callable|null */
	private $http;

	protected function setUp(): void {
		parent::setUp();
		$this->base = wp_normalize_path( sys_get_temp_dir() ) . '/mo-seg-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->base, 0700, true );
	}

	protected function tearDown(): void {
		if ( $this->dirFilter ) {
			remove_filter( 'mo_booking_private_dir', $this->dirFilter );
		}
		if ( $this->http ) {
			remove_filter( 'pre_http_request', $this->http, 10 );
		}
		exec( 'rm -rf ' . escapeshellarg( $this->base ) );
		parent::tearDown();
	}

	private function useDir( string $dir ): void {
		if ( $this->dirFilter ) {
			remove_filter( 'mo_booking_private_dir', $this->dirFilter );
		}
		$this->dirFilter = static function () use ( $dir ) {
			return $dir;
		};
		add_filter( 'mo_booking_private_dir', $this->dirFilter );
	}

	private function fakeProbe( int $code, bool $echoMarker, ?array &$seenArgs = null ): void {
		$this->http = static function ( $pre, $args, $url ) use ( $code, $echoMarker, &$seenArgs ) {
			if ( false === strpos( $url, '/mo-booking-private/probe-' ) ) {
				return $pre;
			}
			$seenArgs = $args;
			$body     = '';
			if ( $echoMarker ) {
				$file = PrivateStorage::dir() . '/' . basename( wp_parse_url( $url, PHP_URL_PATH ) );
				$body = is_file( $file ) ? (string) file_get_contents( $file ) : '';
			}
			return array( 'headers' => array(), 'body' => $body, 'response' => array( 'code' => $code, 'message' => '' ), 'cookies' => array(), 'filename' => null );
		};
		add_filter( 'pre_http_request', $this->http, 10, 3 );
	}

	public function test_protected_and_shared_folders_are_rejected(): void {
		$uploads = wp_upload_dir( null, false );
		foreach ( array( '/', ABSPATH, WP_CONTENT_DIR, $uploads['basedir'], sys_get_temp_dir(), dirname( ABSPATH ) ) as $dir ) {
			$this->assertNotSame( '', PrivateStorage::pathProblem( $dir ), 'Debe rechazar ' . $dir );
		}
		// Carpeta ajena con otros archivos y sin marcador.
		file_put_contents( $this->base . '/documento.txt', 'de otra persona' );
		$this->assertStringContainsString( 'no son de MO Booking Studio', PrivateStorage::pathProblem( $this->base ) );
		// Carpeta nueva y vacía: apta.
		$this->assertSame( '', PrivateStorage::pathProblem( $this->base . '/nueva' ) );
		// Carpeta de una versión anterior (solo años): se adopta.
		mkdir( $this->base . '/antigua/2026/10', 0700, true );
		$this->assertSame( '', PrivateStorage::pathProblem( $this->base . '/antigua' ) );
		// Enlaces y ".." se resuelven antes de comparar.
		$this->assertNotSame( '', PrivateStorage::pathProblem( WP_CONTENT_DIR . '/uploads/..' ) );
	}

	public function test_outside_folder_is_private_and_gets_marker(): void {
		$this->useDir( $this->base . '/privada' );
		$this->assertSame( 'outside', PrivateStorage::location() );
		$this->assertSame( 'ok', PrivateStorage::verify() );
		$this->assertFileExists( $this->base . '/privada/' . PrivateStorage::MARKER );
		$this->assertTrue( PrivateStorage::uploadsEnabled() );

		// Si la ruta cambia, la verificación anterior deja de valer.
		$this->useDir( $this->base . '/otra' );
		$this->assertFalse( PrivateStorage::uploadsEnabled() );
	}

	public function test_folder_inside_site_but_not_uploads_is_refused(): void {
		$dir = wp_normalize_path( ABSPATH ) . 'mo-privado-' . bin2hex( random_bytes( 3 ) );
		$this->useDir( $dir );
		$this->assertSame( 'site', PrivateStorage::location() );
		$this->assertSame( 'error', PrivateStorage::verify() );
		$this->assertFalse( PrivateStorage::uploadsEnabled() );
		@unlink( $dir . '/' . PrivateStorage::MARKER );
		@unlink( $dir . '/.htaccess' );
		@unlink( $dir . '/index.php' );
		@rmdir( $dir );
	}

	public function test_uploads_probe_requires_denial_and_tls(): void {
		$this->assertSame( 'uploads', PrivateStorage::location() );

		$this->fakeProbe( 200, true, $args );
		$this->assertSame( 'public', PrivateStorage::verify() );
		$this->assertTrue( $args['sslverify'], 'La verificación TLS debe estar activa.' );
		$this->assertFalse( PrivateStorage::uploadsEnabled() );
		remove_filter( 'pre_http_request', $this->http, 10 );

		$this->fakeProbe( 200, false );
		$this->assertSame( 'error', PrivateStorage::verify(), 'Un 200 sin el contenido es ambiguo.' );
		remove_filter( 'pre_http_request', $this->http, 10 );

		$this->fakeProbe( 500, false );
		$this->assertSame( 'error', PrivateStorage::verify() );
		remove_filter( 'pre_http_request', $this->http, 10 );

		$this->fakeProbe( 403, false );
		$this->assertSame( 'ok', PrivateStorage::verify() );
		$this->assertTrue( PrivateStorage::uploadsEnabled() );
	}

	public function test_purge_deletes_only_registered_files(): void {
		$dir = $this->base . '/comprobantes';
		$this->useDir( $dir );
		PrivateStorage::ensure();
		$tmp = tempnam( sys_get_temp_dir(), 'mo' );
		file_put_contents( $tmp, 'a' );
		$registered = PrivateStorage::store( $tmp, 'png' );
		file_put_contents( $dir . '/' . dirname( $registered ) . '/ajeno.txt', 'no es del plugin' );
		file_put_contents( $dir . '/notas.txt', 'tampoco' );

		$result = PrivateStorage::purge( array( $registered, '../../etc/passwd', 'x/../../y.png' ) );
		$this->assertSame( 1, $result['deleted'] );
		$this->assertFileDoesNotExist( $dir . '/' . $registered );
		$this->assertFileExists( $dir . '/' . dirname( $registered ) . '/ajeno.txt' );
		$this->assertFileExists( $dir . '/notas.txt' );
		$this->assertDirectoryExists( $dir, 'Con archivos ajenos la carpeta se conserva.' );
	}

	public function test_purge_refuses_without_marker(): void {
		$dir = $this->base . '/sin-marcador';
		mkdir( $dir . '/2026/10', 0700, true );
		$name = '2026/10/' . str_repeat( 'a', 40 ) . '.png';
		file_put_contents( $dir . '/' . $name, 'x' );
		$this->useDir( $dir );
		$result = PrivateStorage::purge( array( $name ) );
		$this->assertNotSame( '', $result['refused'] );
		$this->assertFileExists( $dir . '/' . $name );
	}

	/** SEG-02: sin la confirmación de la ruta exacta (o sin marcador) no se borra nada. */
	public function test_uninstall_keeps_everything_without_exact_confirmation(): void {
		global $wpdb;
		$this->useDir( $this->base . '/u' );
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'mo-booking-studio/mo-booking-studio.php' );
		}
		$run = static function () {
			include dirname( __DIR__, 2 ) . '/uninstall.php';
		};
		Settings::update( array( 'delete_on_uninstall' => true, 'delete_confirm_path' => '' ) );
		$run();
		$this->assertNotNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Schema::table( 'bookings' ) ) ) );

		// Ruta confirmada pero la carpeta no tiene marcador: tampoco.
		mkdir( $this->base . '/u/2026', 0700, true );
		file_put_contents( $this->base . '/u/otro.txt', 'x' );
		Settings::update( array( 'delete_confirm_path' => PrivateStorage::canonical( $this->base . '/u' ) ) );
		$run();
		$this->assertNotNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Schema::table( 'bookings' ) ) ) );
		$this->assertFileExists( $this->base . '/u/otro.txt' );
	}
}
