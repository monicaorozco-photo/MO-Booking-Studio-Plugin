<?php
namespace MOBookingStudio\Domain;

/**
 * beta.6.6: días hábiles para las fechas de entrega. Lunes a viernes, sin los días de
 * descanso obligatorio de México (Ley Federal del Trabajo) ni el Viernes Santo:
 * 1 de enero, primer lunes de febrero, tercer lunes de marzo, Viernes Santo, 1 de mayo,
 * 16 de septiembre, 1 de octubre cada seis años (cambio de gobierno: 2024, 2030…),
 * tercer lunes de noviembre y 25 de diciembre.
 *
 * Los días se cuentan a partir del día siguiente: 5 días hábiles después de una sesión
 * en viernes caen el viernes siguiente.
 */
final class BusinessDays {
	/** Fecha (Y-m-d) del n-ésimo día hábil después de $from (fecha local). */
	public static function after( \DateTimeImmutable $from, int $days ): \DateTimeImmutable {
		$date = $from->setTime( 0, 0 );
		$left = max( 0, $days );
		while ( $left > 0 ) {
			$date = $date->modify( '+1 day' );
			if ( self::isBusinessDay( $date ) ) {
				--$left;
			}
		}
		return $date;
	}

	public static function isBusinessDay( \DateTimeImmutable $date ): bool {
		return (int) $date->format( 'N' ) <= 5 && ! isset( self::holidays( (int) $date->format( 'Y' ) )[ $date->format( 'Y-m-d' ) ] );
	}

	/** @return array<string,string> Y-m-d => nombre del día festivo. */
	public static function holidays( int $year ): array {
		$nth = static function ( int $month, int $n ) use ( $year ): string {
			$first = new \DateTimeImmutable( sprintf( '%04d-%02d-01', $year, $month ) );
			$shift = ( 8 - (int) $first->format( 'N' ) ) % 7; // Días hasta el primer lunes.
			return $first->modify( '+' . ( $shift + 7 * ( $n - 1 ) ) . ' days' )->format( 'Y-m-d' );
		};
		$out = array(
			sprintf( '%04d-01-01', $year ) => 'Año Nuevo',
			$nth( 2, 1 )                   => 'Día de la Constitución',
			$nth( 3, 3 )                   => 'Natalicio de Benito Juárez',
			self::goodFriday( $year )      => 'Viernes Santo',
			sprintf( '%04d-05-01', $year ) => 'Día del Trabajo',
			sprintf( '%04d-09-16', $year ) => 'Día de la Independencia',
			$nth( 11, 3 )                  => 'Día de la Revolución',
			sprintf( '%04d-12-25', $year ) => 'Navidad',
		);
		if ( $year >= 2024 && 0 === ( $year - 2024 ) % 6 ) {
			$out[ sprintf( '%04d-10-01', $year ) ] = 'Transmisión del Poder Ejecutivo Federal';
		}
		return $out;
	}

	/** Viernes Santo: dos días antes del Domingo de Pascua (algoritmo de Meeus/Butcher). */
	public static function goodFriday( int $year ): string {
		$a     = $year % 19;
		$b     = intdiv( $year, 100 );
		$c     = $year % 100;
		$d     = intdiv( $b, 4 );
		$e     = $b % 4;
		$f     = intdiv( $b + 8, 25 );
		$g     = intdiv( $b - $f + 1, 3 );
		$h     = ( 19 * $a + $b - $d - $g + 15 ) % 30;
		$i     = intdiv( $c, 4 );
		$k     = $c % 4;
		$l     = ( 32 + 2 * $e + 2 * $i - $h - $k ) % 7;
		$m     = intdiv( $a + 11 * $h + 22 * $l, 451 );
		$month = intdiv( $h + $l - 7 * $m + 114, 31 );
		$day   = ( ( $h + $l - 7 * $m + 114 ) % 31 ) + 1;
		return ( new \DateTimeImmutable( sprintf( '%04d-%02d-%02d', $year, $month, $day ) ) )->modify( '-2 days' )->format( 'Y-m-d' );
	}
}
