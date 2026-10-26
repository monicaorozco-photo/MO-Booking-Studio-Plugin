<?php
namespace MOBookingStudio\Integrations\Google;

use MOBookingStudio\Application\Bookings;
use MOBookingStudio\Application\Notifications;
use MOBookingStudio\Application\Services;
use MOBookingStudio\Domain\Availability;
use MOBookingStudio\Domain\BookingStatus;
use MOBookingStudio\Domain\DomainException;
use MOBookingStudio\Domain\Interval;
use MOBookingStudio\Infrastructure\Audit;
use MOBookingStudio\Infrastructure\Db;
use MOBookingStudio\Infrastructure\Schema;
use MOBookingStudio\Plugin;

/**
 * Conciliación periódica (SYNC-03):
 * - Empalmes posteriores: un evento externo ocupa el horario de una reserva existente.
 *   Se marca y se avisa; nunca se cancela, mueve ni confirma nada solo.
 * - Ediciones externas (CAL-11): un evento del plugin se movió o se borró en Google.
 *   Se marca para que la administradora decida; no se importa nada de Google.
 */
final class Conflicts {
	public const SCAN_DAYS    = 90;
	public const STATUS_LABEL = array(
		'open'    => 'Abierto',
		'kept'    => 'Mantener reserva',
		'ignored' => 'Ignorado temporalmente',
		'resolved'=> 'Resuelto',
		'cleared' => 'Desapareció (el evento externo ya no ocupa ese horario)',
	);

	/** @return array{new:int,open:int,external:int} */
	public static function scan(): array {
		$result = array( 'new' => 0, 'open' => 0, 'external' => 0 );
		if ( ! Connection::isActive() ) {
			return $result;
		}
		$now   = Plugin::clock()->now();
		$to    = $now->modify( '+' . self::SCAN_DAYS . ' days' );
		$wpdb  = Db::wpdb();
		$table = Schema::table( 'conflicts' );
		try {
			$blocks = Busy::between( $now, $to, true )['blocks'];
		} catch ( DomainException $e ) {
			Connection::save( array( 'last_error' => 'Conciliación: ' . $e->getMessage() ) );
			return $result;
		}
		// Reactivar los que se ignoraron hasta una fecha ya pasada.
		$wpdb->query( $wpdb->prepare( "UPDATE $table SET status = 'open', ignore_until = NULL WHERE status = 'ignored' AND ignore_until IS NOT NULL AND ignore_until <= %s", Db::toSql( $now ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$bookings = Bookings::list(
			array(
				'status' => BookingStatus::active(),
				'limit'  => 500,
			)
		);
		$seen     = array();
		$newRows  = array();
		foreach ( $bookings as $b ) {
			if ( $b['start'] < $now || $b['start'] > $to ) {
				continue;
			}
			$occupy = self::occupancy( $b );
			foreach ( $blocks as $block ) {
				if ( $block['end'] <= $block['start'] || ! $occupy->overlaps( new Interval( $block['start'], $block['end'] ) ) ) {
					continue;
				}
				$s   = Db::toSql( $block['start'] );
				$e   = Db::toSql( $block['end'] );
				$id  = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE booking_id = %d AND calendar_id = %s AND busy_start = %s AND busy_end = %s", $b['id'], $block['calendar'], $s, $e ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( $id ) {
					$wpdb->query( $wpdb->prepare( "UPDATE $table SET last_seen = %s, status = IF(status = 'cleared', 'open', status) WHERE id = %d", Db::toSql( $now ), $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				} else {
					$wpdb->insert(
						$table,
						array(
							'booking_id'  => $b['id'],
							'calendar_id' => $block['calendar'],
							'busy_start'  => $s,
							'busy_end'    => $e,
							'status'      => 'open',
							'first_seen'  => Db::toSql( $now ),
							'last_seen'   => Db::toSql( $now ),
						)
					);
					$id        = (int) $wpdb->insert_id;
					$newRows[] = array( $b, $block );
					Audit::log( 'booking', (int) $b['id'], 'calendar_conflict', array( 'calendar' => $block['calendar'] ), 'system', 0 );
				}
				$seen[] = (int) $id;
			}
		}
		// Los que ya no aparecen se marcan como desaparecidos (no como resueltos por alguien).
		$openIds = array_map( 'intval', $wpdb->get_col( "SELECT id FROM $table WHERE status = 'open'" ) ?: array() ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( array_diff( $openIds, $seen ) as $gone ) {
			$wpdb->update( $table, array( 'status' => 'cleared' ), array( 'id' => $gone ) );
		}
		self::refreshBookingFlags();
		if ( $newRows ) {
			self::notify( $newRows );
		}
		$result['new']      = count( $newRows );
		$result['open']     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE status = 'open'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result['external'] = self::scanExternalEdits( $now, $to );
		Connection::save( array( 'last_scan_at' => $now->format( 'c' ) ) );
		return $result;
	}

	/**
	 * CAL-11: compara los eventos del plugin en Google con lo que dice WordPress.
	 */
	public static function scanExternalEdits( \DateTimeImmutable $from, \DateTimeImmutable $to ): int {
		$wpdb = Db::wpdb();
		$rows    = $wpdb->get_results( "SELECT ce.*, b.start_utc, b.end_utc FROM " . Schema::table( 'calendar_events' ) . ' ce JOIN ' . Schema::table( 'bookings' ) . " b ON b.id = ce.booking_id WHERE ce.state = 'active'", ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// Eventos propios de cada calendario usado (destino e invitaciones, beta.4).
		$byId      = array();
		$calendars = array_unique( array_merge( array( Connection::targetFor( 'internal' ), Connection::targetFor( 'client' ) ), array_column( $rows, 'calendar_id' ) ) );
		foreach ( array_filter( $calendars ) as $calendar ) {
			try {
				$events = ( new Api() )->listEvents( $calendar, $from->format( 'Y-m-d\TH:i:s\Z' ), $to->format( 'Y-m-d\TH:i:s\Z' ), array( 'privateExtendedProperty' => 'mo_owner=' . EventBuilder::siteKey() ) );
			} catch ( GoogleException $e ) {
				return 0;
			}
			foreach ( $events as $e ) {
				$byId[ $calendar . '|' . $e['id'] ] = $e;
			}
		}
		$flagged = 0;
		foreach ( $rows as $r ) {
			$start = Db::fromSql( $r['start_utc'] );
			if ( $start < $from || $start > $to ) {
				continue;
			}
			$change = '';
			$event  = $byId[ $r['calendar_id'] . '|' . $r['event_id'] ] ?? null;
			if ( ! $event ) {
				$change = 'deleted';
			} else {
				$gs = isset( $event['start']['dateTime'] ) ? new \DateTimeImmutable( $event['start']['dateTime'] ) : null;
				$ge = isset( $event['end']['dateTime'] ) ? new \DateTimeImmutable( $event['end']['dateTime'] ) : null;
				if ( ! $gs || ! $ge || $gs->getTimestamp() !== $start->getTimestamp() || $ge->getTimestamp() !== Db::fromSql( $r['end_utc'] )->getTimestamp() ) {
					$change = 'moved';
				} elseif ( 'client' === $r['kind'] && count( (array) ( $event['attendees'] ?? array() ) ) !== self::expectedGuests( (int) $r['booking_id'] ) ) {
					$change = 'attendees';
				}
			}
			if ( $change !== $r['external_change'] && 'dismissed' !== $r['external_change'] ) {
				$wpdb->update( Schema::table( 'calendar_events' ), array( 'external_change' => $change ), array( 'id' => $r['id'] ) );
				if ( '' !== $change ) {
					Audit::log( 'booking', (int) $r['booking_id'], 'google_external_change', array( 'kind' => $r['kind'], 'change' => $change ), 'system', 0 );
				}
			}
			if ( '' !== $change && 'dismissed' !== $r['external_change'] ) {
				++$flagged;
			}
		}
		return $flagged;
	}

	private static function expectedGuests( int $bookingId ): int {
		$booking = Bookings::find( $bookingId );
		return $booking && EventBuilder::canInvite( (string) $booking['contact']['email'] ) ? 1 : 0;
	}

	public static function occupancy( array $booking ): Interval {
		$service = Services::find( $booking['service_id'] );
		$before  = (int) ( $service['buffer_before'] ?? 0 );
		$after   = (int) ( $service['buffer_after'] ?? 0 );
		return new Interval( $booking['start']->modify( "-{$before} minutes" ), $booking['end']->modify( "+{$after} minutes" ) );
	}

	public static function refreshBookingFlags(): void {
		$wpdb = Db::wpdb();
		$b    = Schema::table( 'bookings' );
		$c    = Schema::table( 'conflicts' );
		$wpdb->query( "UPDATE $b SET conflict_state = 'none' WHERE conflict_state <> 'none' AND id NOT IN (SELECT booking_id FROM $c WHERE status = 'open')" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "UPDATE $b SET conflict_state = 'open' WHERE id IN (SELECT booking_id FROM $c WHERE status = 'open')" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Un solo aviso agrupado por escaneo con los empalmes nuevos. */
	private static function notify( array $rows ): void {
		$lines = array();
		foreach ( $rows as $r ) {
			list( $b, $block ) = $r;
			$lines[] = sprintf( '- %s · %s · ocupado en "%s" de %s a %s', $b['public_ref'], \MOBookingStudio\Application\Catalog::dateTimeText( $b['start'] ), Connection::data()['calendar_names'][ $block['calendar'] ] ?? 'otro calendario', $block['start']->setTimezone( \MOBookingStudio\Infrastructure\Settings::timezone() )->format( 'd/m H:i' ), $block['end']->setTimezone( \MOBookingStudio\Infrastructure\Settings::timezone() )->format( 'H:i' ) );
		}
		Notifications::adminMessage(
			'conflicts:' . Plugin::clock()->now()->format( 'YmdHi' ),
			'Empalmes en Google Calendar (' . count( $rows ) . ')',
			"Se detectaron eventos en Google que se enciman con reservas existentes. No se canceló ni movió nada.\n\n" . implode( "\n", $lines ) . "\n\nRevísalos en: " . admin_url( 'admin.php?page=mo-booking-google' )
		);
	}

	public static function open(): array {
		$wpdb = Db::wpdb();
		return $wpdb->get_results( 'SELECT c.*, b.public_ref, b.start_utc, b.status AS booking_status FROM ' . Schema::table( 'conflicts' ) . ' c JOIN ' . Schema::table( 'bookings' ) . " b ON b.id = c.booking_id WHERE c.status IN ('open','ignored','kept') ORDER BY b.start_utc", ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function forBooking( int $bookingId ): array {
		$wpdb = Db::wpdb();
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'conflicts' ) . ' WHERE booking_id = %d ORDER BY id DESC', $bookingId ), ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Acciones manuales: mantener, ignorar hasta una fecha o marcar resuelto. Antes de
	 * marcar resuelto se vuelve a consultar Google.
	 */
	public static function act( int $conflictId, string $action, string $note = '', string $until = '' ): void {
		$wpdb  = Db::wpdb();
		$table = Schema::table( 'conflicts' );
		$c     = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $conflictId ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $c ) {
			throw new DomainException( 'not_found', 'El empalme no existe.', 404 );
		}
		$now    = Plugin::clock()->now();
		$fields = array(
			'note'        => mb_substr( sanitize_text_field( $note ), 0, 250 ),
			'resolved_by' => get_current_user_id(),
			'resolved_at' => Db::toSql( $now ),
		);
		switch ( $action ) {
			case 'keep':
				$fields['status'] = 'kept';
				break;
			case 'ignore':
				$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $until, \MOBookingStudio\Infrastructure\Settings::timezone() );
				if ( ! $date || $date <= $now ) {
					throw new DomainException( 'invalid_date', 'Indica una fecha futura para dejar de ignorarlo.' );
				}
				$fields['status']       = 'ignored';
				$fields['ignore_until'] = Db::toSql( $date );
				break;
			case 'resolve':
				$booking = Bookings::get( (int) $c['booking_id'] );
				$occupy  = self::occupancy( $booking );
				foreach ( Busy::intervals( $occupy->start, $occupy->end, true ) as $busy ) {
					if ( $occupy->overlaps( $busy ) ) {
						throw new DomainException( 'still_conflict', 'Google sigue mostrando un evento encimado en ese horario. Reprograma, cancela o usa "Mantener reserva".', 409 );
					}
				}
				$fields['status'] = 'resolved';
				break;
			default:
				throw new DomainException( 'invalid_action', 'Acción no válida.' );
		}
		$wpdb->update( $table, $fields, array( 'id' => $conflictId ) );
		Audit::log( 'booking', (int) $c['booking_id'], 'conflict_' . $action, array( 'conflict' => $conflictId, 'note' => $note ) );
		self::refreshBookingFlags();
	}

	public static function externalChanges(): array {
		$wpdb = Db::wpdb();
		return $wpdb->get_results( 'SELECT ce.*, b.public_ref, b.start_utc FROM ' . Schema::table( 'calendar_events' ) . ' ce JOIN ' . Schema::table( 'bookings' ) . " b ON b.id = ce.booking_id WHERE ce.state = 'active' AND ce.external_change NOT IN ('', 'dismissed') ORDER BY b.start_utc", ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function dismissExternal( int $rowId ): void {
		Db::wpdb()->update( Schema::table( 'calendar_events' ), array( 'external_change' => 'dismissed' ), array( 'id' => $rowId ) );
		Audit::log( 'google', $rowId, 'external_change_dismissed' );
	}
}
