<?php
namespace MOBookingStudio\Domain;

/**
 * Intervalo semiabierto [start, end) en UTC.
 */
final class Interval {
	/** @var \DateTimeImmutable */
	public $start;
	/** @var \DateTimeImmutable */
	public $end;

	public function __construct( \DateTimeImmutable $start, \DateTimeImmutable $end ) {
		if ( $end <= $start ) {
			throw new DomainException( 'invalid_interval', 'El final debe ser posterior al inicio.' );
		}
		$utc         = new \DateTimeZone( 'UTC' );
		$this->start = $start->setTimezone( $utc );
		$this->end   = $end->setTimezone( $utc );
	}

	public function overlaps( Interval $other ): bool {
		return $this->start < $other->end && $other->start < $this->end;
	}

	public function contains( Interval $other ): bool {
		return $this->start <= $other->start && $other->end <= $this->end;
	}

	/**
	 * Días UTC (Y-m-d) que toca el intervalo, en orden. Se usan para tomar
	 * los bloqueos de calendario en orden estable.
	 *
	 * @return string[]
	 */
	public function utcDays(): array {
		$days = array();
		$day  = $this->start->setTime( 0, 0 );
		$last = $this->end->modify( '-1 second' )->setTime( 0, 0 );
		while ( $day <= $last ) {
			$days[] = $day->format( 'Y-m-d' );
			$day    = $day->modify( '+1 day' );
		}
		return $days;
	}
}
