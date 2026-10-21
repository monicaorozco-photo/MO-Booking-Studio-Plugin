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
	public static function enqueue( int $bookingId, string $operation = 'sync_booking' ): void {
		if ( ! Connection::isConfigured() ) {
			return;
		}
		$wpdb = Db::wpdb();
		$now  = Db::toSql( Plugin::clock()->now() );
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . Schema::table( 'sync_jobs' ) . " (booking_id, operation, dedupe_key, status, attempts, next_run_at, created_at, updated_at) VALUES (%d, %s, %s, 'pending', 0, %s, %s, %s)
				ON DUPLICATE KEY UPDATE next_run_at = LEAST(next_run_at, VALUES(next_run_at)), updated_at = VALUES(updated_at)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$bookingId,
				$operation,
				$operation . ':' . $bookingId . ':pending',
				$now,
				$now,
				$now
			)
		);
		$wpdb->update( Schema::table( 'bookings' ), array( 'sync_state' => 'pending' ), array( 'id' => $bookingId ) );
	}
}
