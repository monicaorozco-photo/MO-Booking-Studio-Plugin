<?php
namespace MOBookingStudio\Infrastructure;

use MOBookingStudio\Domain\DomainException;

/**
 * Almacenamiento privado de comprobantes (SEG-01).
 *
 * - Carpeta exclusiva del plugin: MO_BOOKING_PRIVATE_DIR en wp-config.php (recomendado,
 *   fuera de public_html) o, si no existe, wp-content/uploads/mo-booking-private.
 * - La carpeta lleva un marcador de propiedad. Una carpeta que ya tenga otros archivos y
 *   no tenga el marcador se considera compartida y se rechaza.
 * - Se rechazan la raíz del sistema, el directorio del usuario, la raíz de WordPress,
 *   wp-content, uploads completo, el document root y la carpeta temporal.
 * - Fuera del document root y de WordPress: privada sin más pruebas. Dentro de uploads:
 *   se pide el archivo de prueba por su URL exacta con TLS verificado; solo una respuesta
 *   401/403/404/410 sin el contenido cuenta como privada. Cualquier otro caso
 *   (incluida una carpeta dentro del sitio que no sea la de uploads) deja la carga
 *   deshabilitada.
 * - La verificación se guarda junto con la ruta: si la ruta cambia, hay que verificar
 *   de nuevo.
 */
final class PrivateStorage {
	public const MARKER = '.mo-booking-studio-owner';

	public static function dir(): string {
		$dir = ( defined( 'MO_BOOKING_PRIVATE_DIR' ) && MO_BOOKING_PRIVATE_DIR ) ? (string) MO_BOOKING_PRIVATE_DIR : self::uploadsDir();
		/** Solo para código (pruebas o un plugin propio); no hay ajuste en el panel. */
		$dir = (string) apply_filters( 'mo_booking_private_dir', $dir );
		return rtrim( wp_normalize_path( $dir ), '/' );
	}

	private static function uploadsDir(): string {
		$uploads = wp_upload_dir( null, false );
		return rtrim( wp_normalize_path( $uploads['basedir'] ), '/' ) . '/mo-booking-private';
	}

	/** Ruta canónica (resuelve enlaces y ".."), aunque la carpeta todavía no exista. */
	public static function canonical( string $path ): string {
		$path = wp_normalize_path( $path );
		$real = realpath( $path );
		if ( false !== $real ) {
			return rtrim( wp_normalize_path( $real ), '/' );
		}
		$parent = dirname( $path );
		if ( $parent === $path ) {
			return rtrim( $path, '/' );
		}
		return self::canonical( $parent ) . '/' . basename( $path );
	}

	/** Carpetas que nunca pueden ser la carpeta privada ni contenerla como subcarpeta directa compartida. */
	private static function protectedDirs(): array {
		$uploads = wp_upload_dir( null, false );
		$list    = array(
			'/',
			ABSPATH,
			WP_CONTENT_DIR,
			$uploads['basedir'],
			sys_get_temp_dir(),
			(string) ( $_SERVER['DOCUMENT_ROOT'] ?? '' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			(string) getenv( 'HOME' ),
		);
		if ( function_exists( 'posix_getpwuid' ) && function_exists( 'posix_geteuid' ) ) {
			$info   = posix_getpwuid( posix_geteuid() );
			$list[] = is_array( $info ) ? (string) $info['dir'] : '';
		}
		$out = array();
		foreach ( $list as $dir ) {
			if ( '' !== trim( $dir ) ) {
				$out[] = self::canonical( $dir );
			}
		}
		return array_values( array_unique( $out ) );
	}

	private static function inside( string $path, string $parent ): bool {
		if ( '/' === $parent ) {
			return true;
		}
		return $path === $parent || 0 === strpos( $path . '/', $parent . '/' );
	}

	/**
	 * Comprueba que la ruta sea apta como carpeta exclusiva.
	 *
	 * @return string Motivo del rechazo; vacío si es apta.
	 */
	public static function pathProblem( string $dir ): string {
		$canon = self::canonical( $dir );
		foreach ( self::protectedDirs() as $protected ) {
			if ( $canon === $protected || ( '/' !== $protected && self::inside( $protected, $canon ) ) ) {
				return 'La carpeta configurada es (o contiene) una carpeta protegida o compartida: ' . $protected . '. Usa una carpeta nueva y exclusiva, por ejemplo /home/usuario/mo-booking-private.';
			}
		}
		if ( is_dir( $canon ) && ! file_exists( $canon . '/' . self::MARKER ) && ! self::looksOwned( $canon ) ) {
			return 'La carpeta ya contiene archivos que no son de MO Booking Studio. Usa una carpeta nueva y vacía.';
		}
		return '';
	}

	/** Carpeta de una versión anterior del plugin (solo sus archivos, sin marcador todavía). */
	private static function looksOwned( string $dir ): bool {
		foreach ( (array) scandir( $dir ) as $entry ) {
			if ( in_array( $entry, array( '.', '..', '.htaccess', 'index.php', self::MARKER ), true ) ) {
				continue;
			}
			if ( ! preg_match( '/^\d{4}$/', (string) $entry ) || ! is_dir( $dir . '/' . $entry ) ) {
				return false;
			}
		}
		return true;
	}

	/** Dónde está la carpeta respecto al sitio: outside | uploads | site (dentro, sin URL exacta). */
	public static function location(): string {
		$canon = self::canonical( self::dir() );
		if ( $canon === self::canonical( self::uploadsDir() ) ) {
			return 'uploads';
		}
		$roots = array( self::canonical( ABSPATH ) );
		$doc   = (string) ( $_SERVER['DOCUMENT_ROOT'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( '' !== trim( $doc ) ) {
			$roots[] = self::canonical( $doc );
		}
		foreach ( $roots as $root ) {
			if ( self::inside( $canon, $root ) ) {
				return 'site';
			}
		}
		return 'outside';
	}

	public static function isOutsideWebRoot(): bool {
		return 'outside' === self::location();
	}

	public static function ensure(): bool {
		$dir = self::dir();
		if ( '' !== self::pathProblem( $dir ) ) {
			return false;
		}
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		if ( ! file_exists( $dir . '/' . self::MARKER ) ) {
			file_put_contents( $dir . '/' . self::MARKER, "MO Booking Studio\nCarpeta exclusiva de comprobantes. No guardes otros archivos aquí.\n" );
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
	 * Verifica la carpeta y guarda el resultado con la ruta comprobada.
	 *
	 * @return string ok | public | error
	 */
	public static function verify(): string {
		$now     = \MOBookingStudio\Plugin::clock()->now()->format( 'c' );
		$dir     = self::dir();
		$problem = self::pathProblem( $dir );
		$save    = static function ( string $result, string $reason ) use ( $now, $dir ) {
			Settings::update(
				array(
					'uploads_verified'      => $result,
					'uploads_checked_at'    => $now,
					'uploads_verified_path' => 'ok' === $result ? self::canonical( $dir ) : '',
					'uploads_reason'        => $reason,
				)
			);
			return $result;
		};
		if ( '' !== $problem ) {
			return $save( 'error', $problem );
		}
		if ( ! self::ensure() ) {
			return $save( 'error', 'No se pudo crear o escribir la carpeta privada.' );
		}
		$location = self::location();
		if ( 'outside' === $location ) {
			return $save( 'ok', 'La carpeta está fuera de WordPress y del document root.' );
		}
		if ( 'site' === $location ) {
			return $save( 'error', 'La carpeta está dentro del sitio pero no es la carpeta de uploads del plugin; no se puede comprobar su URL exacta. Usa una carpeta fuera de public_html.' );
		}
		$name   = 'probe-' . bin2hex( random_bytes( 8 ) ) . '.txt';
		$marker = 'mo-probe-' . bin2hex( random_bytes( 8 ) );
		file_put_contents( $dir . '/' . $name, $marker );
		$uploads = wp_upload_dir( null, false );
		$url     = rtrim( $uploads['baseurl'], '/' ) . '/mo-booking-private/' . $name;
		// TLS verificado (SEG-01). Si el certificado no es válido, el resultado es ambiguo y se niega la carga.
		$resp = wp_remote_get( $url, array( 'timeout' => 10, 'redirection' => 0, 'sslverify' => true ) );
		@unlink( $dir . '/' . $name ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( is_wp_error( $resp ) ) {
			return $save( 'error', 'El sitio no respondió a la prueba (' . $resp->get_error_code() . '). La carga queda deshabilitada.' );
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body = (string) wp_remote_retrieve_body( $resp );
		if ( false !== strpos( $body, $marker ) ) {
			return $save( 'public', 'El archivo de prueba se pudo descargar por URL.' );
		}
		if ( in_array( $code, array( 401, 403, 404, 410 ), true ) ) {
			return $save( 'ok', 'El servidor negó el acceso por URL (HTTP ' . $code . ').' );
		}
		return $save( 'error', 'Respuesta ambigua del servidor (HTTP ' . $code . '). La carga queda deshabilitada.' );
	}

	public static function uploadsEnabled(): bool {
		if ( 'ok' !== Settings::get( 'uploads_verified' ) ) {
			return false;
		}
		$verified = (string) Settings::get( 'uploads_verified_path' );
		// Verificaciones anteriores a 0.2.0-beta.2 no guardaban la ruta: se exige verificar de nuevo.
		return '' !== $verified && $verified === self::canonical( self::dir() ) && '' === self::pathProblem( self::dir() );
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

	/** Borra un archivo guardado (por ejemplo, si falló el registro en la base de datos). */
	public static function remove( string $storedName ): void {
		try {
			$path = self::path( $storedName );
		} catch ( DomainException $e ) {
			return;
		}
		if ( is_file( $path ) ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	public static function path( string $storedName ): string {
		if ( ! preg_match( '#^\d{4}/\d{2}/[a-f0-9]{40}\.(jpg|png|pdf)$#', $storedName ) ) {
			throw new DomainException( 'not_found', 'Archivo no disponible.', 404 );
		}
		return self::dir() . '/' . $storedName;
	}

	/**
	 * SEG-02: borra solo los archivos registrados por el plugin, su marcador y las
	 * subcarpetas que queden vacías. Nunca borra de forma recursiva.
	 *
	 * @param string[] $storedNames Nombres internos registrados en la base de datos.
	 * @return array{deleted:int,kept:int,refused:string}
	 */
	public static function purge( array $storedNames ): array {
		$dir = self::dir();
		if ( ! is_dir( $dir ) ) {
			return array( 'deleted' => 0, 'kept' => 0, 'refused' => '' );
		}
		if ( ! file_exists( $dir . '/' . self::MARKER ) ) {
			return array( 'deleted' => 0, 'kept' => 0, 'refused' => 'Falta el marcador de propiedad.' );
		}
		$problem = self::pathProblem( $dir );
		if ( '' !== $problem ) {
			return array( 'deleted' => 0, 'kept' => 0, 'refused' => $problem );
		}
		$deleted = 0;
		$subdirs = array();
		foreach ( $storedNames as $name ) {
			try {
				$path = self::path( (string) $name );
			} catch ( DomainException $e ) {
				continue;
			}
			if ( is_file( $path ) && @unlink( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				++$deleted;
			}
			$subdirs[ dirname( $path ) ] = true;
		}
		foreach ( array_keys( $subdirs ) as $sub ) {
			// Solo se retira la subcarpeta si únicamente queda el index.php que creó el plugin.
			if ( is_dir( $sub ) && array( 'index.php' ) === array_values( array_diff( (array) scandir( $sub ), array( '.', '..' ) ) ) ) {
				@unlink( $sub . '/index.php' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			@rmdir( $sub ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- falla si no está vacía.
			@rmdir( dirname( $sub ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- falla si no está vacía.
		}
		$kept = 0;
		foreach ( (array) scandir( $dir ) as $entry ) {
			if ( ! in_array( $entry, array( '.', '..', '.htaccess', 'index.php', self::MARKER ), true ) ) {
				++$kept;
			}
		}
		if ( 0 === $kept ) {
			foreach ( array( '.htaccess', 'index.php', self::MARKER ) as $own ) {
				@unlink( $dir . '/' . $own ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		return array( 'deleted' => $deleted, 'kept' => $kept, 'refused' => '' );
	}
}
