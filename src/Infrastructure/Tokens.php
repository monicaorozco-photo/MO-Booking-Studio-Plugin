<?php
namespace MOBookingStudio\Infrastructure;

use MOBookingStudio\Domain\DomainException;

/**
 * Enlaces privados del cliente y sesiones del portal.
 *
 * El token del enlace es un HMAC-SHA256 (256 bits) de la reserva y un valor aleatorio
 * guardado en la reserva, firmado con una clave que vive fuera de la base de datos
 * (constante MO_BOOKING_SECRET o, si no existía al instalar, las sales de wp-config.php). En la
 * base de datos solo se guarda el hash SHA-256 del token, así que una copia de la base
 * de datos no basta para entrar al portal. Gracias al HMAC el servidor puede volver a
 * escribir el enlace en un correo sin haberlo guardado. Rotar = cambiar el valor aleatorio.
 *
 * El enlace usa un fragmento (#acceso=...) que el navegador no envía al servidor, de modo
 * que no queda en registros de acceso. El JS lo intercambia por una cookie HttpOnly.
 */
final class Tokens {
	public const COOKIE          = 'mo_booking_portal';
	public const SESSION_MINUTES = 120;

	public static function newNonce(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	public static function tokenFor( int $bookingId, string $nonce ): string {
		$raw = hash_hmac( 'sha256', 'mo-access|' . $bookingId . '|' . $nonce, self::key(), true );
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
	}

	public static function hash( string $token ): string {
		return hash( 'sha256', $token );
	}

	/**
	 * Registra el token vigente de una reserva (idempotente).
	 */
	public static function register( int $bookingId, string $nonce, ?\DateTimeImmutable $expiresAt ): string {
		$wpdb  = Db::wpdb();
		$token = self::tokenFor( $bookingId, $nonce );
		$hash  = self::hash( $token );
		$wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO ' . Schema::table( 'access_tokens' ) . ' (booking_id, scope, token_hash, expires_at, created_at) VALUES (%d, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$bookingId,
				'booking',
				$hash,
				Db::toSql( $expiresAt ),
				Db::toSql( \MOBookingStudio\Plugin::clock()->now() )
			)
		);
		return $token;
	}

	public static function updateExpiry( int $bookingId, ?\DateTimeImmutable $expiresAt ): void {
		$wpdb = Db::wpdb();
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . Schema::table( 'access_tokens' ) . ' SET expires_at = %s WHERE booking_id = %d AND revoked_at IS NULL', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				Db::toSql( $expiresAt ),
				$bookingId
			)
		);
	}

	/** beta.6.4: ¿la reserva tiene un enlace privado vigente? (para el botón de los correos). */
	public static function linkActive( int $bookingId ): bool {
		$wpdb = Db::wpdb();
		$now  = Db::toSql( \MOBookingStudio\Plugin::clock()->now() );
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'access_tokens' ) . ' WHERE booking_id = %d AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > %s)', $bookingId, $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Revoca todos los enlaces de la reserva. */
	public static function revokeAll( int $bookingId ): void {
		$wpdb = Db::wpdb();
		$now  = Db::toSql( \MOBookingStudio\Plugin::clock()->now() );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table( 'access_tokens' ) . ' SET revoked_at = %s WHERE booking_id = %d AND revoked_at IS NULL', $now, $bookingId ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Schema::table( 'portal_sessions' ) . ' WHERE booking_id = %d', $bookingId ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * @return array Fila del token válido.
	 */
	public static function validate( string $token ): array {
		$token = trim( $token );
		if ( ! preg_match( '/^[A-Za-z0-9_-]{43}$/', $token ) ) {
			throw new DomainException( 'invalid_token', 'El enlace no es válido. Revisa que lo hayas copiado completo.', 403 );
		}
		$wpdb = Db::wpdb();
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'access_tokens' ) . ' WHERE token_hash = %s', self::hash( $token ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $row || null !== $row['revoked_at'] ) {
			throw new DomainException( 'invalid_token', 'El enlace no es válido o fue reemplazado por uno nuevo.', 403 );
		}
		$expires = Db::fromSql( $row['expires_at'] );
		if ( $expires && $expires <= \MOBookingStudio\Plugin::clock()->now() ) {
			throw new DomainException( 'expired_token', 'Este enlace ya venció. Escríbenos y te enviaremos uno nuevo.', 410 );
		}
		return $row;
	}

	/**
	 * Crea una sesión del portal a partir de un token válido.
	 *
	 * @return array{session:string,csrf:string,booking_id:int,expires:\DateTimeImmutable}
	 */
	public static function startSession( string $token ): array {
		$row     = self::validate( $token );
		$session = bin2hex( random_bytes( 32 ) );
		$csrf    = bin2hex( random_bytes( 32 ) );
		$now     = \MOBookingStudio\Plugin::clock()->now();
		$expires = $now->modify( '+' . self::SESSION_MINUTES . ' minutes' );
		$wpdb    = Db::wpdb();
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Schema::table( 'portal_sessions' ) . ' WHERE expires_at <= %s', Db::toSql( $now ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		Db::check(
			$wpdb->insert(
				Schema::table( 'portal_sessions' ),
				array(
					'session_hash' => self::hash( $session ),
					'csrf_hash'    => self::hash( $csrf ),
					'booking_id'   => (int) $row['booking_id'],
					'token_id'     => (int) $row['id'],
					'expires_at'   => Db::toSql( $expires ),
					'created_at'   => Db::toSql( $now ),
				)
			),
			'portal_session'
		);
		return array(
			'session'    => $session,
			'csrf'       => $csrf,
			'booking_id' => (int) $row['booking_id'],
			'expires'    => $expires,
		);
	}

	/**
	 * Valida la sesión del portal (cookie) y, para operaciones que modifican datos,
	 * el token CSRF enviado en cabecera.
	 */
	public static function sessionBooking( ?string $session, ?string $csrf, bool $requireCsrf ): int {
		if ( ! $session || ! preg_match( '/^[a-f0-9]{64}$/', $session ) ) {
			throw new DomainException( 'portal_auth', 'Tu sesión terminó. Abre de nuevo el enlace de tu reserva.', 401 );
		}
		$wpdb = Db::wpdb();
		$row  = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT s.*, t.revoked_at, t.expires_at AS token_expires FROM ' . Schema::table( 'portal_sessions' ) . ' s JOIN ' . Schema::table( 'access_tokens' ) . ' t ON t.id = s.token_id WHERE s.session_hash = %s', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::hash( $session )
			),
			ARRAY_A
		);
		$now  = \MOBookingStudio\Plugin::clock()->now();
		if ( ! $row || null !== $row['revoked_at'] || Db::fromSql( $row['expires_at'] ) <= $now ) {
			throw new DomainException( 'portal_auth', 'Tu sesión terminó. Abre de nuevo el enlace de tu reserva.', 401 );
		}
		$tokenExpires = Db::fromSql( $row['token_expires'] );
		if ( $tokenExpires && $tokenExpires <= $now ) {
			throw new DomainException( 'expired_token', 'Este enlace ya venció. Escríbenos y te enviaremos uno nuevo.', 410 );
		}
		if ( $requireCsrf && ( ! $csrf || ! hash_equals( $row['csrf_hash'], self::hash( $csrf ) ) ) ) {
			throw new DomainException( 'csrf', 'No pudimos verificar la solicitud. Recarga la página e intenta de nuevo.', 403 );
		}
		return (int) $row['booking_id'];
	}

	/**
	 * Emite un CSRF nuevo para una sesión válida (por ejemplo, al recargar el portal).
	 * La cookie es SameSite=Strict, así que otro sitio no puede leer esta respuesta con ella.
	 */
	public static function rotateCsrf( string $session ): string {
		$csrf = bin2hex( random_bytes( 32 ) );
		$wpdb = Db::wpdb();
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table( 'portal_sessions' ) . ' SET csrf_hash = %s WHERE session_hash = %s', self::hash( $csrf ), self::hash( $session ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $csrf;
	}

	public static function endSession( ?string $session ): void {
		if ( ! $session ) {
			return;
		}
		$wpdb = Db::wpdb();
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Schema::table( 'portal_sessions' ) . ' WHERE session_hash = %s', self::hash( $session ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public const KEY_SOURCE_OPTION = 'mo_booking_token_key';

	/**
	 * La fuente de la clave se fija la primera vez que se usa. Así, agregar
	 * MO_BOOKING_SECRET después (por ejemplo, para conectar Google) no cambia los enlaces
	 * que los correos vuelven a escribir para reservas ya existentes.
	 */
	private static function key(): string {
		if ( 'secret' === self::pinKeySource() && self::hasSecret() ) {
			return (string) MO_BOOKING_SECRET;
		}
		return wp_salt( 'secure_auth' ) . '|mo-booking-studio';
	}

	/** Fija (una sola vez) la fuente de la clave según lo que hay ahora en wp-config.php. */
	public static function pinKeySource(): string {
		$source = (string) get_option( self::KEY_SOURCE_OPTION, '' );
		if ( '' === $source ) {
			$source = self::hasSecret() ? 'secret' : 'salt';
			add_option( self::KEY_SOURCE_OPTION, $source, '', false );
		}
		return $source;
	}

	private static function hasSecret(): bool {
		return defined( 'MO_BOOKING_SECRET' ) && strlen( (string) MO_BOOKING_SECRET ) >= 32;
	}
}
