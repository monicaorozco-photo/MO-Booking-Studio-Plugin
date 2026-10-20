<?php
namespace MOBookingStudio\Infrastructure;

use MOBookingStudio\Domain\DomainException;

/**
 * Almacenamiento privado de comprobantes.
 *
 * Preferencia: carpeta fuera del directorio público definida con la constante
 * MO_BOOKING_PRIVATE_DIR en wp-config.php. Si no existe, se usa
 * wp-content/uploads/mo-booking-private con .htaccess (Apache/LiteSpeed) e index.php.
 * Como no todos los servidores respetan .htaccess, la carga de comprobantes queda
 * deshabilitada hasta que una verificación confirme que los archivos no se pueden
 * descargar con una URL directa.
 */
final class PrivateStorage {
	public static function dir(): string {
		if ( defined( 'MO_BOOKING_PRIVATE_DIR' ) && MO_BOOKING_PRIVATE_DIR ) {
			return rtrim( (string) MO_BOOKING_PRIVATE_DIR, '/\\' );
		}
		$uploads = wp_upload_dir( null, false );
		return rtrim( $uploads['basedir'], '/\\' ) . '/mo-booking-private';
	}

	public static function isOutsideWebRoot(): bool {
		if ( ! defined( 'MO_BOOKING_PRIVATE_DIR' ) || ! MO_BOOKING_PRIVATE_DIR ) {
			return false;
		}
		$dir  = wp_normalize_path( realpath( self::dir() ) ?: self::dir() );
		$root = wp_normalize_path( untrailingslashit( ABSPATH ) );
		return 0 !== strpos( $dir, $root );
	}

	public static function ensure(): bool {
		$dir = self::dir();
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "# MO Booking Studio: acceso denegado\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\nOptions -Indexes\n" );
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silencio.\n" );
		}
		return is_writable( $dir );
	}

	/**
	 * Comprueba si un archivo de prueba se puede descargar por URL. Guarda el resultado.
	 *
	 * @return string ok | public | error
	 */
	public static function verify(): string {
		$now = \MOBookingStudio\Plugin::clock()->now()->format( 'c' );
		if ( ! self::ensure() ) {
			Settings::update( array( 'uploads_verified' => 'error', 'uploads_checked_at' => $now ) );
			return 'error';
		}
		if ( self::isOutsideWebRoot() ) {
			Settings::update( array( 'uploads_verified' => 'ok', 'uploads_checked_at' => $now ) );
			return 'ok';
		}
		$name   = 'probe-' . bin2hex( random_bytes( 8 ) ) . '.txt';
		$marker = 'mo-probe-' . bin2hex( random_bytes( 8 ) );
		file_put_contents( self::dir() . '/' . $name, $marker );
		$uploads = wp_upload_dir( null, false );
		$url     = rtrim( $uploads['baseurl'], '/' ) . '/mo-booking-private/' . $name;
		$resp    = wp_remote_get( $url, array( 'timeout' => 10, 'redirection' => 0, 'sslverify' => false ) );
		@unlink( self::dir() . '/' . $name ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( is_wp_error( $resp ) ) {
			$result = 'error';
		} else {
			$body   = (string) wp_remote_retrieve_body( $resp );
			$result = false !== strpos( $body, $marker ) ? 'public' : 'ok';
		}
		Settings::update( array( 'uploads_verified' => $result, 'uploads_checked_at' => $now ) );
		return $result;
	}

	public static function uploadsEnabled(): bool {
		return 'ok' === Settings::get( 'uploads_verified' );
	}

	public static function store( string $tmpPath, string $extension ): string {
		if ( ! self::ensure() ) {
			throw new DomainException( 'storage_unavailable', 'No pudimos guardar el archivo. Escríbenos para enviarlo por otro medio.', 500 );
		}
		$sub = gmdate( 'Y/m' );
		$dir = self::dir() . '/' . $sub;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			file_put_contents( $dir . '/index.php', "<?php\n// Silencio.\n" );
		}
		$name = $sub . '/' . bin2hex( random_bytes( 20 ) ) . '.' . $extension;
		if ( ! @rename( $tmpPath, self::dir() . '/' . $name ) && ! @copy( $tmpPath, self::dir() . '/' . $name ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			throw new DomainException( 'storage_unavailable', 'No pudimos guardar el archivo. Intenta de nuevo.', 500 );
		}
		@chmod( self::dir() . '/' . $name, 0640 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return $name;
	}

	public static function path( string $storedName ): string {
		if ( ! preg_match( '#^\d{4}/\d{2}/[a-f0-9]{40}\.(jpg|png|pdf)$#', $storedName ) ) {
			throw new DomainException( 'not_found', 'Archivo no disponible.', 404 );
		}
		return self::dir() . '/' . $storedName;
	}
}
