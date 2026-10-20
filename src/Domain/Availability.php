<?php
namespace MOBookingStudio\Domain;

/**
 * Calcula los horarios que se pueden ofrecer para un servicio.
 *
 * Reglas:
 * - Las ventanas de una fecha concreta reemplazan el horario semanal de ese día.
 * - Una fecha cerrada o un bloqueo manual prevalecen sobre cualquier apertura.
 * - Un horario se ofrece solo si caben la duración y los márgenes completos dentro
 *   de la ventana, y si su ocupación no se solapa con otra ocupación del recurso.
 * - Las horas locales se convierten a UTC con la zona IANA del negocio. Una hora
 *   local inexistente (salto de horario de verano) se omite; nunca se usa una resta fija.
 */
final class Availability {
	public const REASON_BOOKED      = 'booked';
	public const REASON_BLOCKED     = 'blocked';
	public const REASON_NOTICE      = 'notice';
	public const REASON_HORIZON     = 'horizon';
	public const REASON_MAX_PER_DAY = 'max_per_day';
	public const REASON_SALE_WINDOW = 'sale_window';

	/** @var \DateTimeZone */
	private $zone;

	public function __construct( \DateTimeZone $zone ) {
		$this->zone = $zone;
	}

	/**
	 * @param array $schedule {weekly: array<int,array<array{0:string,1:string}>>, dates: array<string,array>, closed: array<string,bool>}
	 * @param array $params   {duration:int, buffer_before:int, buffer_after:int, interval:int, min_notice_minutes:int, horizon_days:int, max_per_day:int, sale_from:?DateTimeImmutable, sale_until:?DateTimeImmutable}
	 * @param Interval[] $busy      Ocupaciones activas del recurso (incluyen márgenes).
	 * @param Interval[] $blocks    Bloqueos manuales del recurso.
	 * @param array<string,int> $dayCounts Citas activas del servicio por fecha local.
	 * @return array<string,array{date:string,open:bool,slots:array}>
	 */
	public function days( array $schedule, array $params, string $fromDate, string $toDate, \DateTimeImmutable $now, array $busy = array(), array $blocks = array(), array $dayCounts = array() ): array {
		$params = self::normalizeParams( $params );
		$from   = $this->localDate( $fromDate );
		$to     = $this->localDate( $toDate );
		if ( $to < $from ) {
			throw new DomainException( 'invalid_range', 'El rango de fechas no es válido.' );
		}
		if ( $from->diff( $to )->days > 400 ) {
			throw new DomainException( 'invalid_range', 'El rango de fechas es demasiado amplio.' );
		}

		$result = array();
		for ( $day = $from; $day <= $to; $day = $day->modify( '+1 day' ) ) {
			$date            = $day->format( 'Y-m-d' );
			$slots           = $this->slotsForDate( $schedule, $params, $date, $now, $busy, $blocks, $dayCounts[ $date ] ?? 0 );
			$result[ $date ] = array(
				'date'  => $date,
				'open'  => count( $slots ) > 0,
				'slots' => $slots,
			);
		}
		return $result;
	}

	/**
	 * Busca un horario concreto y confirma si está disponible. Lo usa el servidor
	 * al cotizar y al reservar; el navegador nunca decide la disponibilidad.
	 *
	 * @return array|null El horario o null si no existe en la agenda.
	 */
	public function findSlot( array $schedule, array $params, \DateTimeImmutable $startUtc, \DateTimeImmutable $now, array $busy = array(), array $blocks = array(), array $dayCounts = array() ): ?array {
		$params = self::normalizeParams( $params );
		$date   = $startUtc->setTimezone( $this->zone )->format( 'Y-m-d' );
		$slots  = $this->slotsForDate( $schedule, $params, $date, $now, $busy, $blocks, $dayCounts[ $date ] ?? 0 );
		$want   = $startUtc->getTimestamp();
		foreach ( $slots as $slot ) {
			if ( $slot['start']->getTimestamp() === $want ) {
				return $slot;
			}
		}
		return null;
	}

	/**
	 * Ventanas efectivas (horas locales) de una fecha.
	 *
	 * @return array<array{0:string,1:string}>
	 */
	public static function windowsForDate( array $schedule, string $date ): array {
		if ( ! empty( $schedule['closed'][ $date ] ) ) {
			return array();
		}
		if ( isset( $schedule['dates'] ) && array_key_exists( $date, $schedule['dates'] ) ) {
			return array_values( (array) $schedule['dates'][ $date ] );
		}
		$weekday = (int) ( new \DateTimeImmutable( $date ) )->format( 'N' );
		return array_values( (array) ( $schedule['weekly'][ $weekday ] ?? array() ) );
	}

	/**
	 * Ocupación completa de un horario: [inicio - margen anterior, fin + margen posterior).
	 */
	public static function occupancyFor( \DateTimeImmutable $startUtc, array $params ): Interval {
		$params = self::normalizeParams( $params );
		return new Interval(
			$startUtc->modify( sprintf( '-%d minutes', $params['buffer_before'] ) ),
			$startUtc->modify( sprintf( '+%d minutes', $params['duration'] + $params['buffer_after'] ) )
		);
	}

	public static function normalizeParams( array $params ): array {
		$out = array(
			'duration'           => (int) ( $params['duration'] ?? 0 ),
			'buffer_before'      => max( 0, (int) ( $params['buffer_before'] ?? 0 ) ),
			'buffer_after'       => max( 0, (int) ( $params['buffer_after'] ?? 0 ) ),
			'interval'           => (int) ( $params['interval'] ?? 0 ),
			'min_notice_minutes' => max( 0, (int) ( $params['min_notice_minutes'] ?? 0 ) ),
			'horizon_days'       => max( 0, (int) ( $params['horizon_days'] ?? 0 ) ),
			'max_per_day'        => max( 0, (int) ( $params['max_per_day'] ?? 0 ) ),
			'sale_from'          => $params['sale_from'] ?? null,
			'sale_until'         => $params['sale_until'] ?? null,
		);
		if ( $out['duration'] < 5 ) {
			throw new DomainException( 'invalid_service', 'La duración del servicio debe ser de al menos 5 minutos.' );
		}
		if ( $out['interval'] <= 0 ) {
			$out['interval'] = $out['duration'] + $out['buffer_before'] + $out['buffer_after'];
		}
		if ( $out['interval'] < 5 ) {
			throw new DomainException( 'invalid_service', 'El intervalo entre inicios debe ser de al menos 5 minutos.' );
		}
		return $out;
	}

	public static function parseTime( string $hhmm ): int {
		if ( ! preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', trim( $hhmm ), $m ) ) {
			throw new DomainException( 'invalid_time', sprintf( 'La hora "%s" no es válida. Usa el formato 24 h, por ejemplo 13:30.', $hhmm ) );
		}
		return (int) $m[1] * 60 + (int) $m[2];
	}

	/**
	 * Valida una lista de ventanas de un día: formato, fin posterior al inicio y sin solapes.
	 *
	 * @return array<array{0:string,1:string}> Ventanas ordenadas.
	 */
	public static function validateWindows( array $windows ): array {
		$parsed = array();
		foreach ( $windows as $w ) {
			$start = self::parseTime( (string) ( $w[0] ?? '' ) );
			$end   = self::parseTime( (string) ( $w[1] ?? '' ) );
			if ( $end <= $start ) {
				throw new DomainException( 'invalid_window', sprintf( 'El horario %s a %s termina antes de empezar.', $w[0], $w[1] ) );
			}
			$parsed[] = array( $start, $end, array( (string) $w[0], (string) $w[1] ) );
		}
		usort(
			$parsed,
			static function ( $a, $b ) {
				return $a[0] <=> $b[0];
			}
		);
		for ( $i = 1, $n = count( $parsed ); $i < $n; $i++ ) {
			if ( $parsed[ $i ][0] < $parsed[ $i - 1 ][1] ) {
				throw new DomainException( 'invalid_window', 'Hay horarios que se solapan en el mismo día.' );
			}
		}
		return array_map(
			static function ( $p ) {
				return $p[2];
			},
			$parsed
		);
	}

	private function slotsForDate( array $schedule, array $params, string $date, \DateTimeImmutable $now, array $busy, array $blocks, int $dayCount ): array {
		$windows = self::windowsForDate( $schedule, $date );
		if ( ! $windows ) {
			return array();
		}
		$earliest = $now->modify( sprintf( '+%d minutes', $params['min_notice_minutes'] ) );
		$latest   = $params['horizon_days'] > 0 ? $now->modify( sprintf( '+%d days', $params['horizon_days'] ) ) : null;
		$full     = $params['max_per_day'] > 0 && $dayCount >= $params['max_per_day'];

		$slots = array();
		foreach ( $windows as $window ) {
			$openMin  = self::parseTime( (string) $window[0] );
			$closeMin = self::parseTime( (string) $window[1] );
			$openUtc  = $this->localToUtc( $date, $openMin, false );
			$closeUtc = $this->localToUtc( $date, $closeMin, false );
			if ( null === $openUtc || null === $closeUtc || $closeUtc <= $openUtc ) {
				continue;
			}
			$windowInterval = new Interval( $openUtc, $closeUtc );

			for ( $m = $openMin + $params['buffer_before']; $m + $params['duration'] + $params['buffer_after'] <= $closeMin; $m += $params['interval'] ) {
				$startUtc = $this->localToUtc( $date, $m, true );
				if ( null === $startUtc ) {
					continue; // Hora local inexistente por cambio de horario.
				}
				$occupy = self::occupancyFor( $startUtc, $params );
				if ( ! $windowInterval->contains( $occupy ) ) {
					continue; // En días de cambio de horario la duración real puede no caber.
				}
				$reason = null;
				if ( $startUtc < $earliest ) {
					$reason = self::REASON_NOTICE;
				} elseif ( null !== $latest && $startUtc > $latest ) {
					$reason = self::REASON_HORIZON;
				} elseif ( ( $params['sale_from'] && $now < $params['sale_from'] ) || ( $params['sale_until'] && $now > $params['sale_until'] ) ) {
					$reason = self::REASON_SALE_WINDOW;
				} elseif ( self::overlapsAny( $occupy, $blocks ) ) {
					$reason = self::REASON_BLOCKED;
				} elseif ( self::overlapsAny( $occupy, $busy ) ) {
					$reason = self::REASON_BOOKED;
				} elseif ( $full ) {
					$reason = self::REASON_MAX_PER_DAY;
				}
				$slots[] = array(
					'start'     => $startUtc,
					'end'       => $startUtc->modify( sprintf( '+%d minutes', $params['duration'] ) ),
					'occupy'    => $occupy,
					'local'     => $startUtc->setTimezone( $this->zone )->format( 'H:i' ),
					'date'      => $date,
					'available' => null === $reason,
					'reason'    => $reason,
				);
			}
		}
		usort(
			$slots,
			static function ( $a, $b ) {
				return $a['start'] <=> $b['start'];
			}
		);
		return $slots;
	}

	/**
	 * @param Interval[] $intervals
	 */
	private static function overlapsAny( Interval $interval, array $intervals ): bool {
		foreach ( $intervals as $other ) {
			if ( $interval->overlaps( $other ) ) {
				return true;
			}
		}
		return false;
	}

	private function localDate( string $date ): \DateTimeImmutable {
		$d = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date, $this->zone );
		if ( ! $d || $d->format( 'Y-m-d' ) !== $date ) {
			throw new DomainException( 'invalid_date', sprintf( 'La fecha "%s" no es válida.', $date ) );
		}
		return $d;
	}

	/**
	 * @param bool $strict Si es true, devuelve null cuando la hora local no existe.
	 */
	private function localToUtc( string $date, int $minutes, bool $strict ): ?\DateTimeImmutable {
		$text  = sprintf( '%s %02d:%02d', $date, intdiv( $minutes, 60 ), $minutes % 60 );
		$local = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $text, $this->zone );
		if ( ! $local ) {
			return null;
		}
		if ( $strict && $local->format( 'Y-m-d H:i' ) !== $text ) {
			return null;
		}
		return $local->setTimezone( new \DateTimeZone( 'UTC' ) );
	}
}
