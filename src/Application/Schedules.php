<?php
namespace MOBookingStudio\Application;

use MOBookingStudio\Domain\Availability;
use MOBookingStudio\Domain\BookingStatus;
use MOBookingStudio\Domain\DomainException;
use MOBookingStudio\Domain\Interval;
use MOBookingStudio\Infrastructure\Audit;
use MOBookingStudio\Infrastructure\Db;
use MOBookingStudio\Infrastructure\Schema;
use MOBookingStudio\Infrastructure\Settings;
use MOBookingStudio\Plugin;

/**
 * Horarios, excepciones, cierres y bloqueos por recurso, y ocupaciones activas.
 */
final class Schedules {
	public static function defaultResourceId(): int {
		$wpdb = Db::wpdb();
		return (int) $wpdb->get_var( 'SELECT id FROM ' . Schema::table( 'resources' ) . ' WHERE active = 1 ORDER BY id LIMIT 1' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function resources(): array {
		$wpdb = Db::wpdb();
		return $wpdb->get_results( 'SELECT * FROM ' . Schema::table( 'resources' ) . ' ORDER BY id', ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * @return array{weekly:array,dates:array,closed:array}
	 */
	public static function schedule( int $resourceId ): array {
		$wpdb = Db::wpdb();
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'availability' ) . " WHERE resource_id = %d AND type IN ('weekly','date','closed') ORDER BY start_time", $resourceId ), ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out  = array(
			'weekly' => array(),
			'dates'  => array(),
			'closed' => array(),
		);
		foreach ( $rows as $r ) {
			if ( 'weekly' === $r['type'] ) {
				$out['weekly'][ (int) $r['weekday'] ][] = array( $r['start_time'], $r['end_time'] );
			} elseif ( 'date' === $r['type'] ) {
				if ( null === $r['start_time'] ) {
					$out['dates'][ $r['date'] ] = $out['dates'][ $r['date'] ] ?? array();
				} else {
					$out['dates'][ $r['date'] ][] = array( $r['start_time'], $r['end_time'] );
				}
			} elseif ( 'closed' === $r['type'] ) {
				$out['closed'][ $r['date'] ] = true;
			}
		}
		ksort( $out['dates'] );
		return $out;
	}

	public static function rows( int $resourceId, string $type ): array {
		$wpdb = Db::wpdb();
		$order = 'block' === $type ? 'start_utc' : ( 'weekly' === $type ? 'weekday, start_time' : 'date, start_time' );
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'availability' ) . " WHERE resource_id = %d AND type = %s ORDER BY $order", $resourceId, $type ), ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** @return Interval[] */
	public static function blocks( int $resourceId, \DateTimeImmutable $from, \DateTimeImmutable $to ): array {
		$wpdb = Db::wpdb();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT start_utc, end_utc FROM ' . Schema::table( 'availability' ) . " WHERE resource_id = %d AND type = 'block' AND start_utc < %s AND end_utc > %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$resourceId,
				Db::toSql( $to ),
				Db::toSql( $from )
			),
			ARRAY_A
		) ?: array();
		return array_map(
			static function ( $r ) {
				return new Interval( Db::fromSql( $r['start_utc'] ), Db::fromSql( $r['end_utc'] ) );
			},
			$rows
		);
	}

	/**
	 * Ocupaciones activas del recurso en el rango. Una retención vencida no ocupa,
	 * aunque la tarea programada todavía no la haya marcado como vencida.
	 *
	 * @return Interval[]
	 */
	public static function busy( int $resourceId, \DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $now, ?int $excludeBookingId = null ): array {
		$wpdb = Db::wpdb();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT start_utc, end_utc FROM ' . Schema::table( 'occupancies' ) . ' WHERE resource_id = %d AND released_at IS NULL AND (expires_at IS NULL OR expires_at > %s) AND start_utc < %s AND end_utc > %s AND booking_id <> %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$resourceId,
				Db::toSql( $now ),
				Db::toSql( $to ),
				Db::toSql( $from ),
				(int) $excludeBookingId
			),
			ARRAY_A
		) ?: array();
		return array_map(
			static function ( $r ) {
				return new Interval( Db::fromSql( $r['start_utc'] ), Db::fromSql( $r['end_utc'] ) );
			},
			$rows
		);
	}

	/**
	 * Citas activas de un servicio por fecha local (para el máximo diario).
	 *
	 * @return array<string,int>
	 */
	public static function dayCounts( int $serviceId, \DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $now, ?int $excludeBookingId = null ): array {
		$wpdb     = Db::wpdb();
		$statuses = array_filter( array_keys( BookingStatus::LABELS ), array( BookingStatus::class, 'occupies' ) );
		$in       = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$params   = array_merge( array( $serviceId, Db::toSql( $from ), Db::toSql( $to ), Db::toSql( $now ), (int) $excludeBookingId ), array_values( $statuses ) );
		$rows     = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT b.start_utc FROM ' . Schema::table( 'bookings' ) . ' b JOIN ' . Schema::table( 'occupancies' ) . " o ON o.booking_id = b.id AND o.released_at IS NULL WHERE b.service_id = %d AND b.start_utc >= %s AND b.start_utc < %s AND (o.expires_at IS NULL OR o.expires_at > %s) AND b.id <> %d AND b.status IN ($in)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$params
			)
		) ?: array();
		$zone   = Settings::timezone();
		$counts = array();
		foreach ( $rows as $start ) {
			$date            = Db::fromSql( $start )->setTimezone( $zone )->format( 'Y-m-d' );
			$counts[ $date ] = ( $counts[ $date ] ?? 0 ) + 1;
		}
		return $counts;
	}

	// ----- Edición -----

	/**
	 * Reemplaza el horario semanal completo de un recurso.
	 *
	 * @param array<int,array> $weekly weekday (1=lunes ... 7=domingo) => ventanas.
	 */
	public static function saveWeekly( int $resourceId, array $weekly ): void {
		$clean = array();
		for ( $d = 1; $d <= 7; $d++ ) {
			$clean[ $d ] = Availability::validateWindows( $weekly[ $d ] ?? array() );
		}
		Db::transaction(
			static function () use ( $resourceId, $clean ) {
				$wpdb = Db::wpdb();
				$wpdb->delete( Schema::table( 'availability' ), array( 'resource_id' => $resourceId, 'type' => 'weekly' ) );
				foreach ( $clean as $day => $windows ) {
					foreach ( $windows as $w ) {
						self::insert( $resourceId, 'weekly', array( 'weekday' => $day, 'start_time' => $w[0], 'end_time' => $w[1] ) );
					}
				}
			}
		);
		Audit::log( 'resource', $resourceId, 'weekly_saved' );
	}

	/**
	 * Define las ventanas de una fecha concreta. Reemplazan el horario semanal de ese día.
	 * Una lista vacía significa "sin horario ese día" (se recomienda usar Cerrar fecha).
	 */
	public static function saveDate( int $resourceId, string $date, array $windows, bool $isTest = false ): void {
		self::assertDate( $date );
		$windows = Availability::validateWindows( $windows );
		Db::transaction(
			static function () use ( $resourceId, $date, $windows, $isTest ) {
				$wpdb = Db::wpdb();
				$wpdb->delete( Schema::table( 'availability' ), array( 'resource_id' => $resourceId, 'type' => 'date', 'date' => $date ) );
				if ( ! $windows ) {
					self::insert( $resourceId, 'date', array( 'date' => $date ), $isTest );
				}
				foreach ( $windows as $w ) {
					self::insert( $resourceId, 'date', array( 'date' => $date, 'start_time' => $w[0], 'end_time' => $w[1] ), $isTest );
				}
			}
		);
		Audit::log( 'resource', $resourceId, 'date_saved', array( 'date' => $date ) );
	}

	public static function removeDate( int $resourceId, string $date ): void {
		self::assertDate( $date );
		Db::wpdb()->delete( Schema::table( 'availability' ), array( 'resource_id' => $resourceId, 'type' => 'date', 'date' => $date ) );
		Audit::log( 'resource', $resourceId, 'date_removed', array( 'date' => $date ) );
	}

	public static function closeDate( int $resourceId, string $date, string $note = '' ): void {
		self::assertDate( $date );
		$wpdb = Db::wpdb();
		$wpdb->delete( Schema::table( 'availability' ), array( 'resource_id' => $resourceId, 'type' => 'closed', 'date' => $date ) );
		self::insert( $resourceId, 'closed', array( 'date' => $date, 'note' => $note ) );
		Audit::log( 'resource', $resourceId, 'date_closed', array( 'date' => $date ) );
	}

	public static function reopenDate( int $resourceId, string $date ): void {
		self::assertDate( $date );
		Db::wpdb()->delete( Schema::table( 'availability' ), array( 'resource_id' => $resourceId, 'type' => 'closed', 'date' => $date ) );
		Audit::log( 'resource', $resourceId, 'date_reopened', array( 'date' => $date ) );
	}

	/**
	 * Bloqueo manual entre dos fechas y horas locales.
	 */
	public static function addBlock( int $resourceId, string $startLocal, string $endLocal, string $note = '' ): int {
		$zone  = Settings::timezone();
		$start = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $startLocal, $zone );
		$end   = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $endLocal, $zone );
		if ( ! $start || ! $end ) {
			throw new DomainException( 'invalid_block', 'Indica inicio y fin del bloqueo con fecha y hora.' );
		}
		if ( $end <= $start ) {
			throw new DomainException( 'invalid_block', 'El bloqueo debe terminar después de empezar.' );
		}
		$id = self::insert(
			$resourceId,
			'block',
			array(
				'start_utc' => Db::toSql( $start ),
				'end_utc'   => Db::toSql( $end ),
				'note'      => $note,
			)
		);
		Audit::log( 'resource', $resourceId, 'block_added', array( 'block_id' => $id ) );
		return $id;
	}

	public static function removeRow( int $resourceId, int $rowId ): void {
		Db::wpdb()->delete( Schema::table( 'availability' ), array( 'resource_id' => $resourceId, 'id' => $rowId ) );
		Audit::log( 'resource', $resourceId, 'availability_removed', array( 'row_id' => $rowId ) );
	}

	/**
	 * Carga rápida de fechas, una por línea:
	 *   2026-11-15 13:00-15:30
	 *   2026-12-06 12:00-15:00, 17:00-19:00
	 *
	 * @return array{dates:int,errors:string[]}
	 */
	public static function bulkDates( int $resourceId, string $text, bool $dryRun = false ): array {
		$parsed = array();
		$errors = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $i => $line ) {
			$line = trim( $line );
			if ( '' === $line || 0 === strpos( $line, '#' ) ) {
				continue;
			}
			if ( ! preg_match( '/^(\d{4}-\d{2}-\d{2})\s+(.+)$/', $line, $m ) ) {
				$errors[] = sprintf( 'Línea %d: escribe la fecha (AAAA-MM-DD) seguida de los horarios.', $i + 1 );
				continue;
			}
			try {
				self::assertDate( $m[1] );
				$windows = array();
				foreach ( preg_split( '/\s*[,;y]\s+|\s*[,;]\s*/u', trim( $m[2] ) ) as $chunk ) {
					if ( ! preg_match( '/^(\d{1,2}:\d{2})\s*(?:-|–|a)\s*(\d{1,2}:\d{2})$/u', trim( $chunk ), $t ) ) {
						throw new DomainException( 'invalid_time', sprintf( 'no se entiende "%s"; usa 13:00-15:30.', trim( $chunk ) ) );
					}
					$windows[] = array( str_pad( $t[1], 5, '0', STR_PAD_LEFT ), str_pad( $t[2], 5, '0', STR_PAD_LEFT ) );
				}
				$parsed[ $m[1] ] = array_merge( $parsed[ $m[1] ] ?? array(), $windows );
				Availability::validateWindows( $parsed[ $m[1] ] );
			} catch ( DomainException $e ) {
				$errors[] = sprintf( 'Línea %d: %s', $i + 1, $e->getMessage() );
			}
		}
		if ( ! $errors && ! $dryRun ) {
			foreach ( $parsed as $date => $windows ) {
				self::saveDate( $resourceId, $date, $windows );
			}
		}
		return array(
			'dates'  => count( $parsed ),
			'errors' => $errors,
			'parsed' => $parsed,
		);
	}

	private static function insert( int $resourceId, string $type, array $fields, bool $isTest = false ): int {
		$wpdb = Db::wpdb();
		Db::check(
			$wpdb->insert(
				Schema::table( 'availability' ),
				array_merge(
					array(
						'resource_id' => $resourceId,
						'type'        => $type,
						'is_test'     => $isTest ? 1 : 0,
						'created_at'  => Db::toSql( Plugin::clock()->now() ),
					),
					$fields
				)
			),
			'availability_insert'
		);
		return (int) $wpdb->insert_id;
	}

	private static function assertDate( string $date ): void {
		$d = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date );
		if ( ! $d || $d->format( 'Y-m-d' ) !== $date ) {
			throw new DomainException( 'invalid_date', sprintf( 'La fecha "%s" no es válida.', $date ) );
		}
	}
}
