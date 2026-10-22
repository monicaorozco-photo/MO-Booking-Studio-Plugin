<?php
namespace MOBookingStudio\Application;

use MOBookingStudio\Infrastructure\Db;
use MOBookingStudio\Infrastructure\Schema;
use MOBookingStudio\Integrations\Google\Connection;
use MOBookingStudio\Plugin;

/**
 * Registra en la cola persistente que una reserva debe reflejarse en Google Calendar.
 * Se llama dentro de la misma transacción que el cambio de la reserva, así que el
 * trabajo nunca se pierde si Google falla después (SYNC-01). Hay a lo sumo un trabajo
 * pendiente por reserva: el trabajo calcula el estado deseado al ejecutarse.
 */
final class CalendarSync {
	/** @var int[] Reservas encoladas en esta solicitud. */
	private static $touched = array();

	/**
	 * Al terminar la solicitud intenta sincronizar de inmediato lo que cambió. Si falla,
	 * el trabajo queda en la cola para la tarea programada; la persona nunca espera a Google.
	 */
	public static function processAfterResponse(): void {
		if ( ! self::$touched || defined( 'MO_BOOKING_TESTING' ) || \MOBookingStudio\Plugin::maintenance() ) {
			return;
		}
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}
		self::$touched = array();
		try {
			\MOBookingStudio\Integrations\Google\SyncWorker::processQueue( 10 );
		} catch ( \Throwable $e ) {
			\MOBookingStudio\Infrastructure\Logger::error( 'Sincronización inmediata con Google', array( 'error' => get_class( $e ) ) );
		}
	}

	/**
	 * Encola todas las reservas vigentes futuras. Es seguro repetirlo: lo que ya está igual
	 * en Google no se vuelve a enviar. Se usa al elegir el destino y al reconectar, para que
	 * los cambios hechos mientras Google estuvo desconectado lleguen al calendario.
	 */
	public static function enqueueActive(): int {
		$wpdb     = Db::wpdb();
		$statuses = "'" . implode( "','", \MOBookingStudio\Domain\BookingStatus::active() ) . "'";
		$ids      = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'bookings' ) . " WHERE status IN ($statuses) AND start_utc >= %s", Db::toSql( Plugin::clock()->now() ) ) ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $ids as $id ) {
			self::enqueue( (int) $id );
		}
		return count( $ids );
	}

	public static function enqueue( int $bookingId, string $operation = 'sync_booking', bool $force = false ): void {
		if ( ! Connection::isConfigured() ) {
			return;
		}
		$wpdb = Db::wpdb();
		$now  = Db::toSql( Plugin::clock()->now() );
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . Schema::table( 'sync_jobs' ) . " (booking_id, operation, dedupe_key, status, attempts, revision, force_sync, next_run_at, created_at, updated_at) VALUES (%d, %s, %s, 'pending', 0, 1, %d, %s, %s, %s)
				ON DUPLICATE KEY UPDATE revision = revision + 1, attempts = 0, force_sync = GREATEST(force_sync, VALUES(force_sync)), next_run_at = LEAST(next_run_at, VALUES(next_run_at)), updated_at = VALUES(updated_at)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$bookingId,
				$operation,
				$operation . ':' . $bookingId . ':pending',
				$force ? 1 : 0,
				$now,
				$now,
				$now
			)
		);
		$wpdb->update( Schema::table( 'bookings' ), array( 'sync_state' => 'pending' ), array( 'id' => $bookingId ) );
		self::$touched[] = $bookingId;
	}
}
