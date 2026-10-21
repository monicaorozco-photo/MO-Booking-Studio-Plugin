<?php
namespace MOBookingStudio\Integrations\Google;

use MOBookingStudio\Infrastructure\Logger;

/**
 * Cliente mínimo de Google Calendar API v3 sobre las funciones HTTP de WordPress.
 * Sin librerías externas (SEC-08). Registra solo operación, código HTTP e identificador
 * de solicitud; nunca nombres, teléfonos, tokens ni contenido de eventos (SYNC-06).
 */
final class Api {
	public const BASE = 'https://www.googleapis.com/calendar/v3';

	/**
	 * @return array Respuesta decodificada.
	 */
	public function request( string $method, string $path, array $query = array(), ?array $body = null, string $op = '' ): array {
		$requestId = substr( bin2hex( random_bytes( 8 ) ), 0, 16 );
		$attempt   = 0;
		while ( true ) {
			$token = Connection::accessToken( $attempt > 0 );
			$url   = self::BASE . $path . ( $query ? '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ) : '' );
			$args  = array(
				'method'  => $method,
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/json',
				),
			);
			if ( null !== $body ) {
				$args['headers']['Content-Type'] = 'application/json';
				$args['body']                    = wp_json_encode( $body );
			}
			$resp = wp_remote_request( $url, $args );
			if ( is_wp_error( $resp ) ) {
				Logger::error( 'Google sin respuesta', array( 'op' => $op, 'request' => $requestId ) );
				throw new GoogleException( 'transient', 0, 'Google no respondió a tiempo.', $requestId );
			}
			$code = (int) wp_remote_retrieve_response_code( $resp );
			$raw  = (string) wp_remote_retrieve_body( $resp );
			$data = '' === $raw ? array() : json_decode( $raw, true );
			if ( $code >= 200 && $code < 300 ) {
				return is_array( $data ) ? $data : array();
			}
			// Un 401 puede ser solo un token de acceso vencido: se renueva una vez.
			if ( 401 === $code && 0 === $attempt ) {
				++$attempt;
				continue;
			}
			$reason = '';
			if ( is_array( $data ) && isset( $data['error']['errors'][0]['reason'] ) ) {
				$reason = (string) $data['error']['errors'][0]['reason'];
			}
			Logger::error( 'Error de Google Calendar', array( 'op' => $op, 'http' => $code, 'reason' => $reason, 'request' => $requestId ) );
			throw new GoogleException( self::classify( $code, $reason ), $code, self::message( $code, $reason ), $requestId );
		}
	}

	public static function classify( int $code, string $reason ): string {
		if ( 401 === $code ) {
			return 'auth';
		}
		if ( 429 === $code || ( 403 === $code && in_array( $reason, array( 'rateLimitExceeded', 'userRateLimitExceeded', 'quotaExceeded' ), true ) ) ) {
			return 'rate';
		}
		if ( $code >= 500 ) {
			return 'transient';
		}
		if ( 410 === $code ) {
			return 'gone';
		}
		if ( 404 === $code ) {
			return 'not_found';
		}
		if ( 409 === $code ) {
			return 'conflict';
		}
		return 'permanent';
	}

	private static function message( int $code, string $reason ): string {
		switch ( self::classify( $code, $reason ) ) {
			case 'auth':
				return 'Google rechazó el acceso. Vuelve a conectar la cuenta.';
			case 'rate':
				return 'Google pidió esperar (límite de uso). Se reintentará.';
			case 'transient':
				return 'Google tuvo un error temporal. Se reintentará.';
			case 'not_found':
				return 'Google no encontró el calendario o el evento.';
			case 'gone':
				return 'El evento ya no existe en Google.';
			case 'conflict':
				return 'El evento ya existe en Google.';
		}
		return 'Google rechazó la solicitud (' . $code . ( $reason ? ', ' . $reason : '' ) . ').';
	}

	/** Lista de calendarios de la cuenta. */
	public function calendarList(): array {
		$out   = array();
		$query = array( 'minAccessRole' => 'freeBusyReader', 'maxResults' => 250 );
		do {
			$data = $this->request( 'GET', '/users/me/calendarList', $query, null, 'calendarList' );
			foreach ( (array) ( $data['items'] ?? array() ) as $c ) {
				$out[] = array(
					'id'      => (string) $c['id'],
					'name'    => (string) ( $c['summaryOverride'] ?? $c['summary'] ?? $c['id'] ),
					'role'    => (string) ( $c['accessRole'] ?? '' ),
					'primary' => ! empty( $c['primary'] ),
				);
			}
			$query['pageToken'] = $data['nextPageToken'] ?? null;
		} while ( ! empty( $query['pageToken'] ) );
		return $out;
	}

	public function primaryCalendarId(): string {
		foreach ( $this->calendarList() as $c ) {
			if ( $c['primary'] ) {
				return $c['id'];
			}
		}
		return '';
	}

	/**
	 * Ocupación de varios calendarios. Si Google no puede leer alguno, se trata como
	 * falla: no se puede prometer un horario sin verificarlo (DEC-06).
	 *
	 * @return array<string,array<array{start:string,end:string}>> calendario => bloques
	 */
	public function freeBusy( string $timeMin, string $timeMax, array $calendarIds ): array {
		if ( ! $calendarIds ) {
			return array();
		}
		$data = $this->request(
			'POST',
			'/freeBusy',
			array(),
			array(
				'timeMin' => $timeMin,
				'timeMax' => $timeMax,
				'items'   => array_map(
					static function ( $id ) {
						return array( 'id' => $id );
					},
					array_values( $calendarIds )
				),
			),
			'freeBusy'
		);
		$out = array();
		foreach ( $calendarIds as $id ) {
			$cal = $data['calendars'][ $id ] ?? null;
			if ( ! is_array( $cal ) || ! empty( $cal['errors'] ) ) {
				throw new GoogleException( 'permanent', 200, 'Google no pudo leer la ocupación de uno de los calendarios seleccionados.' );
			}
			$out[ $id ] = array_values( (array) ( $cal['busy'] ?? array() ) );
		}
		return $out;
	}

	/** Eventos de un calendario en un rango (expande eventos recurrentes). */
	public function listEvents( string $calendarId, string $timeMin, string $timeMax, array $extra = array() ): array {
		$out   = array();
		$query = array_merge(
			array(
				'timeMin'      => $timeMin,
				'timeMax'      => $timeMax,
				'singleEvents' => 'true',
				'showDeleted'  => 'false',
				'maxResults'   => 250,
			),
			$extra
		);
		do {
			$data = $this->request( 'GET', '/calendars/' . rawurlencode( $calendarId ) . '/events', $query, null, 'listEvents' );
			foreach ( (array) ( $data['items'] ?? array() ) as $e ) {
				$out[] = $e;
			}
			$query['pageToken'] = $data['nextPageToken'] ?? null;
		} while ( ! empty( $query['pageToken'] ) );
		return $out;
	}

	public function getEvent( string $calendarId, string $eventId ): array {
		return $this->request( 'GET', '/calendars/' . rawurlencode( $calendarId ) . '/events/' . rawurlencode( $eventId ), array(), null, 'getEvent' );
	}

	public function insertEvent( string $calendarId, array $event, string $sendUpdates ): array {
		return $this->request( 'POST', '/calendars/' . rawurlencode( $calendarId ) . '/events', array( 'sendUpdates' => $sendUpdates ), $event, 'insertEvent' );
	}

	public function patchEvent( string $calendarId, string $eventId, array $event, string $sendUpdates ): array {
		return $this->request( 'PATCH', '/calendars/' . rawurlencode( $calendarId ) . '/events/' . rawurlencode( $eventId ), array( 'sendUpdates' => $sendUpdates ), $event, 'patchEvent' );
	}

	public function deleteEvent( string $calendarId, string $eventId, string $sendUpdates ): void {
		$this->request( 'DELETE', '/calendars/' . rawurlencode( $calendarId ) . '/events/' . rawurlencode( $eventId ), array( 'sendUpdates' => $sendUpdates ), null, 'deleteEvent' );
	}
}
