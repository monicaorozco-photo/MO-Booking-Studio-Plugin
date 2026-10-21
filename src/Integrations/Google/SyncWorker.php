<?php
namespace MOBookingStudio\Integrations\Google;

use MOBookingStudio\Application\Bookings;
use MOBookingStudio\Domain\BookingStatus;
use MOBookingStudio\Domain\DomainException;
use MOBookingStudio\Infrastructure\Audit;
use MOBookingStudio\Infrastructure\Db;
use MOBookingStudio\Infrastructure\Logger;
use MOBookingStudio\Infrastructure\Schema;
use MOBookingStudio\Plugin;

/**
 * Procesa la cola de sincronización (SYNC-01 a SYNC-05).
 *
 * Cada trabajo lleva una reserva al estado deseado en Google:
 * - Pendiente de pago, comprobante recibido, en revisión o corrección: evento interno "MO PENDIENTE".
 * - Confirmada: evento interno "MO CONFIRMADA" y evento del cliente con invitación.
 * - Vencida, rechazada o cancelada: se borran solo los eventos del plugin.
 * - Completada o inasistencia: no se toca nada.
 *
 * Es idempotente: si el contenido no cambió no se llama a Google, y los identificadores
 * deterministas evitan duplicados aunque un intento anterior haya quedado a medias.
 */
final class SyncWorker {
	public const MAX_ATTEMPTS = 8;

	public static function processQueue( int $limit = 20 ): int {
		if ( ! Connection::isActive() ) {
			return 0; // Desconectada o revocada: los trabajos esperan (SYNC-05).
		}
		$wpdb  = Db::wpdb();
		$table = Schema::table( 'sync_jobs' );
		$now   = Db::toSql( Plugin::clock()->now() );
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $table WHERE status = 'pending' AND next_run_at <= %s AND (locked_until IS NULL OR locked_until < %s) ORDER BY next_run_at LIMIT %d", $now, $now, $limit ) ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$done  = 0;
		foreach ( $ids as $id ) {
			if ( ! Connection::isActive() ) {
				break;
			}
			$done += self::runJob( (int) $id ) ? 1 : 0;
		}
		Connection::save( array( 'last_sync_at' => Plugin::clock()->now()->format( 'c' ) ) );
		return $done;
	}

	public static function runJob( int $id ): bool {
		$wpdb  = Db::wpdb();
		$table = Schema::table( 'sync_jobs' );
		$nowDt = Plugin::clock()->now();
		$now   = Db::toSql( $nowDt );
		$taken = $wpdb->query( $wpdb->prepare( "UPDATE $table SET locked_until = %s, attempts = attempts + 1, updated_at = %s WHERE id = %d AND status = 'pending' AND (locked_until IS NULL OR locked_until < %s)", Db::toSql( $nowDt->modify( '+2 minutes' ) ), $now, $id, $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( 1 !== $taken ) {
			return false;
		}
		$job = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		try {
			self::syncBooking( (int) $job['booking_id'], (bool) $job['force_sync'] );
			// Si la reserva cambió mientras se procesaba (revisión nueva), el trabajo sigue pendiente.
			$finished = $wpdb->query( $wpdb->prepare( "UPDATE $table SET status = 'done', dedupe_key = NULL, locked_until = NULL, last_error = '', last_http = NULL, updated_at = %s WHERE id = %d AND revision = %d", $now, $id, (int) $job['revision'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! $finished ) {
				$wpdb->query( $wpdb->prepare( "UPDATE $table SET locked_until = NULL, force_sync = 0 WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			} else {
				$wpdb->update( Schema::table( 'bookings' ), array( 'sync_state' => 'synced' ), array( 'id' => (int) $job['booking_id'] ) );
			}
			return true;
		} catch ( GoogleException $e ) {
			self::fail( $job, $e->type, $e->http, $e->getMessage(), $e->retryable(), $e->requestId );
			return false;
		} catch ( DomainException $e ) {
			self::fail( $job, 'permanent', 0, $e->getMessage(), false, '' );
			return false;
		}
	}

	private static function fail( array $job, string $type, int $http, string $message, bool $retryable, string $requestId ): void {
		$wpdb     = Db::wpdb();
		$table    = Schema::table( 'sync_jobs' );
		$now      = Plugin::clock()->now();
		$attempts = (int) $job['attempts'];
		if ( 'auth' === $type ) {
			// Credenciales revocadas: el trabajo espera sin gastar intentos.
			$wpdb->query( $wpdb->prepare( "UPDATE $table SET locked_until = NULL, attempts = GREATEST(0, attempts - 1), last_http = %d, last_error = %s, request_id = %s WHERE id = %d", $http, mb_substr( $message, 0, 250 ), $requestId, (int) $job['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return;
		}
		if ( $retryable && $attempts < self::MAX_ATTEMPTS ) {
			// Espera exponencial con variación aleatoria, máximo una hora (SYNC-02).
			$delay = min( 3600, 30 * ( 2 ** ( $attempts - 1 ) ) ) + random_int( 0, 30 );
			$wpdb->query( $wpdb->prepare( "UPDATE $table SET locked_until = NULL, next_run_at = %s, last_http = %d, last_error = %s, request_id = %s WHERE id = %d", Db::toSql( $now->modify( '+' . $delay . ' seconds' ) ), $http, mb_substr( $message, 0, 250 ), $requestId, (int) $job['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->update( Schema::table( 'bookings' ), array( 'sync_state' => 'retrying' ), array( 'id' => (int) $job['booking_id'] ) );
			return;
		}
		$wpdb->query( $wpdb->prepare( "UPDATE $table SET status = 'failed', dedupe_key = NULL, locked_until = NULL, last_http = %d, last_error = %s, request_id = %s WHERE id = %d", $http, mb_substr( $message, 0, 250 ), $requestId, (int) $job['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->update( Schema::table( 'bookings' ), array( 'sync_state' => 'error' ), array( 'id' => (int) $job['booking_id'] ) );
		Audit::log( 'booking', (int) $job['booking_id'], 'google_sync_failed', array( 'http' => $http, 'request' => $requestId ), 'system', 0 );
	}

	/** Reintento manual de un trabajo fallido. */
	public static function retry( int $jobId ): void {
		$wpdb = Db::wpdb();
		$job  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'sync_jobs' ) . ' WHERE id = %d', $jobId ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $job ) {
			\MOBookingStudio\Application\CalendarSync::enqueue( (int) $job['booking_id'], 'sync_booking', true );
		}
	}

	/**
	 * Lleva una reserva a su estado deseado en Google.
	 *
	 * @param bool $force Reescribe aunque el contenido no haya cambiado (restaurar tras una edición externa).
	 */
	public static function syncBooking( int $bookingId, bool $force = false ): void {
		$booking = Bookings::find( $bookingId );
		if ( ! $booking ) {
			return;
		}
		$status = $booking['status'];
		if ( in_array( $status, array( BookingStatus::COMPLETED, BookingStatus::NO_SHOW ), true ) ) {
			return;
		}
		if ( in_array( $status, array( BookingStatus::EXPIRED, BookingStatus::REJECTED, BookingStatus::CANCELLED ), true ) ) {
			self::remove( $booking, 'client' );
			self::remove( $booking, 'internal' );
			return;
		}
		self::upsert( $booking, 'internal', EventBuilder::internal( $booking ), $force );
		if ( BookingStatus::CONFIRMED === $status ) {
			self::upsert( $booking, 'client', EventBuilder::client( $booking ), $force );
		} else {
			// Si volvió a pendiente (por ejemplo, reprogramación con nuevo anticipo), sin invitación.
			self::remove( $booking, 'client' );
		}
	}

	private static function row( int $bookingId, string $kind ): ?array {
		$wpdb = Db::wpdb();
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'calendar_events' ) . ' WHERE booking_id = %d AND kind = %s', $bookingId, $kind ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ?: null;
	}

	private static function upsert( array $booking, string $kind, array $body, bool $force ): void {
		$row = self::row( (int) $booking['id'], $kind );
		$fp  = EventBuilder::fingerprint( $body );
		if ( $row && 'active' === $row['state'] && $row['fingerprint'] === $fp && ! $force ) {
			return;
		}
		$api      = new Api();
		$calendar = $row ? $row['calendar_id'] : Connection::data()['destination'];
		$eventId  = $row ? $row['event_id'] : EventBuilder::eventId( (int) $booking['id'], $kind );
		// Solo el evento del cliente notifica; el interno nunca tiene invitados.
		$send     = 'client' === $kind ? 'all' : 'none';
		$isNew    = ! $row || 'active' !== $row['state'];
		try {
			if ( $isNew ) {
				$result = $api->insertEvent( $calendar, array_merge( $body, array( 'id' => $eventId ) ), $send );
			} else {
				// status=confirmed reactiva el evento si alguien lo borró en Google.
				$result = $api->patchEvent( $calendar, $eventId, array_merge( $body, array( 'status' => 'confirmed' ) ), $send );
			}
		} catch ( GoogleException $e ) {
			if ( 'conflict' === $e->type ) {
				// Ya existía (reintento tras falla parcial o evento borrado antes): se actualiza y reactiva.
				$result = $api->patchEvent( $calendar, $eventId, array_merge( $body, array( 'status' => 'confirmed' ) ), $send );
			} elseif ( in_array( $e->type, array( 'not_found', 'gone' ), true ) && ! $isNew ) {
				// Lo borraron fuera del plugin: se recrea con el mismo identificador.
				try {
					$result = $api->insertEvent( $calendar, array_merge( $body, array( 'id' => $eventId ) ), $send );
				} catch ( GoogleException $e2 ) {
					if ( 'conflict' !== $e2->type ) {
						throw $e2;
					}
					$result = $api->patchEvent( $calendar, $eventId, array_merge( $body, array( 'status' => 'confirmed' ) ), $send );
				}
			} else {
				throw $e;
			}
		}
		$now  = Db::toSql( Plugin::clock()->now() );
		$wpdb = Db::wpdb();
		$data = array(
			'calendar_id'     => $calendar,
			'event_id'        => $eventId,
			'etag'            => (string) ( $result['etag'] ?? '' ),
			'state'           => 'active',
			'fingerprint'     => $fp,
			'external_change' => '',
			'synced_at'       => $now,
		);
		$invites = 'client' === $kind && ! empty( $body['attendees'] );
		if ( $invites && ( $isNew || ! $row['invited_at'] ) ) {
			$data['invited_at'] = $now;
		}
		if ( $row ) {
			$wpdb->update( Schema::table( 'calendar_events' ), $data, array( 'id' => $row['id'] ) );
		} else {
			$wpdb->insert( Schema::table( 'calendar_events' ), array_merge( $data, array( 'booking_id' => (int) $booking['id'], 'kind' => $kind, 'created_at' => $now ) ) );
		}
		if ( $invites && $isNew ) {
			Audit::log( 'booking', (int) $booking['id'], 'google_invitation_sent', array( 'event' => 'client' ), 'system', 0 );
		}
	}

	/** Borra solo eventos que el plugin registró como suyos (CAL-09). */
	private static function remove( array $booking, string $kind ): void {
		$row = self::row( (int) $booking['id'], $kind );
		if ( ! $row || 'active' !== $row['state'] ) {
			return;
		}
		try {
			( new Api() )->deleteEvent( $row['calendar_id'], $row['event_id'], 'client' === $kind ? 'all' : 'none' );
		} catch ( GoogleException $e ) {
			if ( ! in_array( $e->type, array( 'not_found', 'gone' ), true ) ) {
				throw $e; // 404 o 410: ya no existe, es un estado final.
			}
		}
		Db::wpdb()->update(
			Schema::table( 'calendar_events' ),
			array(
				'state'     => 'deleted',
				'synced_at' => Db::toSql( Plugin::clock()->now() ),
			),
			array( 'id' => $row['id'] )
		);
	}

	/** Eventos del plugin para una reserva (para el detalle en el panel). */
	public static function eventsFor( int $bookingId ): array {
		$wpdb = Db::wpdb();
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'calendar_events' ) . ' WHERE booking_id = %d ORDER BY kind', $bookingId ), ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function jobsSummary(): array {
		$wpdb  = Db::wpdb();
		$table = Schema::table( 'sync_jobs' );
		return array(
			'pending' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE status = 'pending'" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'failed'  => $wpdb->get_results( "SELECT j.*, b.public_ref FROM $table j LEFT JOIN " . Schema::table( 'bookings' ) . " b ON b.id = j.booking_id WHERE j.status = 'failed' ORDER BY j.id DESC LIMIT 50", ARRAY_A ) ?: array(), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'next'    => (string) $wpdb->get_var( "SELECT MIN(next_run_at) FROM $table WHERE status = 'pending'" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}
}
