<?php
namespace MOBookingStudio\Integrations\Google;

use MOBookingStudio\Domain\DomainException;
use MOBookingStudio\Infrastructure\Audit;
use MOBookingStudio\Infrastructure\Crypto;
use MOBookingStudio\Infrastructure\Logger;
use MOBookingStudio\Plugin;

/**
 * Conexión OAuth 2.0 de servidor con una sola cuenta de Google del negocio (CAL-01, CAL-02).
 *
 * - Client ID y client secret: constantes MO_BOOKING_GOOGLE_CLIENT_ID y
 *   MO_BOOKING_GOOGLE_CLIENT_SECRET en wp-config.php (nunca en el repositorio).
 * - Tokens de acceso y de actualización: cifrados con MO_BOOKING_SECRET (CAL-04).
 * - El parámetro state se valida contra la sesión de la administradora.
 * - Si Google revoca el acceso, se detienen las escrituras sin borrar nada (SYNC-05).
 */
final class Connection {
	public const OPTION = 'mo_booking_google';

	/** Permisos exactos (CAL-03). */
	public const SCOPES = array(
		'https://www.googleapis.com/auth/calendar.calendarlist.readonly',
		'https://www.googleapis.com/auth/calendar.freebusy',
		'https://www.googleapis.com/auth/calendar.events',
	);

	public const AUTH_URL   = 'https://accounts.google.com/o/oauth2/v2/auth';
	public const TOKEN_URL  = 'https://oauth2.googleapis.com/token';
	public const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

	public static function data(): array {
		$d = get_option( self::OPTION, array() );
		return array_merge(
			array(
				'status'           => 'disconnected', // disconnected | connected | revoked
				'refresh'          => '',
				'access'           => '',
				'access_expires'   => 0,
				'account'          => '',
				'connected_at'     => '',
				'read_calendars'   => array(),
				'calendar_names'   => array(),
				'destination'      => '',
				// beta.4: calendario aparte para las invitaciones a clientas ('' = el mismo destino).
				'client_destination' => '',
				'last_sync_at'     => '',
				'last_scan_at'     => '',
				'last_error'       => '',
			),
			is_array( $d ) ? $d : array()
		);
	}

	/** Calendario donde se escribe cada tipo de evento propio. */
	public static function targetFor( string $kind ): string {
		$d = self::data();
		return 'client' === $kind && '' !== (string) $d['client_destination'] ? (string) $d['client_destination'] : (string) $d['destination'];
	}

	public static function save( array $changes ): void {
		update_option( self::OPTION, array_merge( self::data(), $changes ), false );
	}

	public static function clientId(): string {
		return defined( 'MO_BOOKING_GOOGLE_CLIENT_ID' ) ? (string) MO_BOOKING_GOOGLE_CLIENT_ID : '';
	}

	private static function clientSecret(): string {
		return defined( 'MO_BOOKING_GOOGLE_CLIENT_SECRET' ) ? (string) MO_BOOKING_GOOGLE_CLIENT_SECRET : '';
	}

	/** Qué falta en wp-config.php para poder conectar. */
	public static function missingConfig(): array {
		$missing = array();
		if ( '' === self::clientId() ) {
			$missing[] = 'MO_BOOKING_GOOGLE_CLIENT_ID';
		}
		if ( '' === self::clientSecret() ) {
			$missing[] = 'MO_BOOKING_GOOGLE_CLIENT_SECRET';
		}
		if ( ! Crypto::available() ) {
			$missing[] = 'MO_BOOKING_SECRET';
		}
		return $missing;
	}

	/** Conectada alguna vez (aunque ahora esté revocada): se registran trabajos para no perderlos. */
	public static function isConfigured(): bool {
		$d = self::data();
		return in_array( $d['status'], array( 'connected', 'revoked' ), true ) && '' !== $d['destination'];
	}

	/** Lista para leer y escribir. */
	public static function isActive(): bool {
		$d = self::data();
		return 'connected' === $d['status'] && '' !== $d['destination'];
	}

	/** Dirección de retorno exacta que se registra en Google Cloud. */
	public static function redirectUri(): string {
		return admin_url( 'admin-post.php?action=mo_booking_google_callback' );
	}

	public static function authUrl(): string {
		if ( self::missingConfig() ) {
			throw new DomainException( 'google_config', 'Faltan datos en wp-config.php: ' . implode( ', ', self::missingConfig() ) . '.' );
		}
		$state = bin2hex( random_bytes( 24 ) );
		set_transient( 'mo_google_state_' . get_current_user_id(), hash( 'sha256', $state ), 15 * MINUTE_IN_SECONDS );
		return self::AUTH_URL . '?' . http_build_query(
			array(
				'client_id'              => self::clientId(),
				'redirect_uri'           => self::redirectUri(),
				'response_type'          => 'code',
				'scope'                  => implode( ' ', self::SCOPES ),
				'access_type'            => 'offline',
				'prompt'                 => 'consent',
				'include_granted_scopes' => 'true',
				'state'                  => $state,
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
	}

	/**
	 * Recibe el código de Google, valida state y guarda los tokens cifrados.
	 */
	public static function handleCallback( string $code, string $state ): void {
		$key      = 'mo_google_state_' . get_current_user_id();
		$expected = (string) get_transient( $key );
		delete_transient( $key );
		if ( '' === $expected || ! hash_equals( $expected, hash( 'sha256', $state ) ) ) {
			throw new DomainException( 'google_state', 'La respuesta de Google no corresponde a esta sesión. Vuelve a intentar la conexión.' );
		}
		$token = self::tokenRequest(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'redirect_uri'  => self::redirectUri(),
				'client_id'     => self::clientId(),
				'client_secret' => self::clientSecret(),
			)
		);
		if ( empty( $token['refresh_token'] ) ) {
			throw new DomainException( 'google_no_refresh', 'Google no entregó acceso permanente. Quita el acceso de "MO Booking Studio" en tu cuenta de Google (Seguridad → Apps de terceros) y vuelve a conectar.' );
		}
		$granted = explode( ' ', (string) ( $token['scope'] ?? '' ) );
		$missing = array_diff( self::SCOPES, $granted );
		if ( $missing ) {
			throw new DomainException( 'google_scopes', 'Faltan permisos al conectar. Vuelve a conectar y marca todas las casillas que pide Google.' );
		}
		$now = Plugin::clock()->now();
		self::save(
			array(
				'status'         => 'connected',
				'refresh'        => Crypto::encrypt( (string) $token['refresh_token'] ),
				'access'         => Crypto::encrypt( (string) $token['access_token'] ),
				'access_expires' => $now->getTimestamp() + max( 60, (int) ( $token['expires_in'] ?? 3600 ) - 60 ),
				'connected_at'   => $now->format( 'c' ),
				'last_error'     => '',
			)
		);
		try {
			$account = ( new Api() )->primaryCalendarId();
			self::save( array( 'account' => $account ) );
		} catch ( GoogleException $e ) {
			Logger::error( 'No se pudo leer la cuenta de Google', array( 'http' => $e->http ) );
		}
		Audit::log( 'google', 0, 'connected', array( 'account' => self::data()['account'] ) );
		// Reconexión: lo que cambió mientras estuvo desconectada vuelve a la cola.
		if ( '' !== self::data()['destination'] ) {
			\MOBookingStudio\Application\CalendarSync::enqueueActive();
		}
	}

	/**
	 * Token de acceso vigente; lo renueva si venció.
	 */
	public static function accessToken( bool $forceRefresh = false ): string {
		$d   = self::data();
		$now = Plugin::clock()->now()->getTimestamp();
		if ( 'connected' !== $d['status'] || '' === $d['refresh'] ) {
			throw new GoogleException( 'auth', 401, 'Google no está conectado.' );
		}
		if ( ! $forceRefresh && '' !== $d['access'] && $d['access_expires'] > $now ) {
			return Crypto::decrypt( $d['access'] );
		}
		try {
			$token = self::tokenRequest(
				array(
					'grant_type'    => 'refresh_token',
					'refresh_token' => Crypto::decrypt( $d['refresh'] ),
					'client_id'     => self::clientId(),
					'client_secret' => self::clientSecret(),
				)
			);
		} catch ( GoogleException $e ) {
			if ( 'auth' === $e->type ) {
				self::markRevoked( 'Google rechazó la conexión (acceso revocado o vencido).' );
			}
			throw $e;
		}
		self::save(
			array(
				'access'         => Crypto::encrypt( (string) $token['access_token'] ),
				'access_expires' => $now + max( 60, (int) ( $token['expires_in'] ?? 3600 ) - 60 ),
			)
		);
		return (string) $token['access_token'];
	}

	public static function markRevoked( string $reason ): void {
		if ( 'revoked' === self::data()['status'] ) {
			return;
		}
		self::save(
			array(
				'status'     => 'revoked',
				'access'     => '',
				'last_error' => $reason,
			)
		);
		Audit::log( 'google', 0, 'revoked', array( 'reason' => $reason ), 'system', 0 );
	}

	/**
	 * Desconecta: revoca en Google (si se puede) y olvida los tokens. No borra reservas,
	 * trabajos pendientes ni eventos ya creados.
	 */
	public static function disconnect(): void {
		$d = self::data();
		if ( '' !== $d['refresh'] ) {
			try {
				wp_remote_post( self::REVOKE_URL, array( 'timeout' => 10, 'body' => array( 'token' => Crypto::decrypt( $d['refresh'] ) ) ) );
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// Si no se pudo revocar en Google, igual se olvida localmente.
			}
		}
		self::save(
			array(
				'status'  => 'disconnected',
				'refresh' => '',
				'access'  => '',
			)
		);
		Audit::log( 'google', 0, 'disconnected' );
	}

	private static function tokenRequest( array $body ): array {
		$resp = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 15,
				'body'    => $body,
			)
		);
		if ( is_wp_error( $resp ) ) {
			throw new GoogleException( 'transient', 0, 'No hubo respuesta de Google.' );
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$data = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		if ( 200 !== $code || ! is_array( $data ) || empty( $data['access_token'] ) ) {
			$error = is_array( $data ) ? (string) ( $data['error'] ?? '' ) : '';
			Logger::error( 'Error de token de Google', array( 'http' => $code, 'error' => $error ) );
			if ( in_array( $error, array( 'invalid_grant', 'unauthorized_client', 'invalid_client' ), true ) ) {
				throw new GoogleException( 'auth', $code, 'Google rechazó las credenciales.' );
			}
			throw new GoogleException( $code >= 500 ? 'transient' : 'permanent', $code, 'Google no entregó el token.' );
		}
		return $data;
	}
}
