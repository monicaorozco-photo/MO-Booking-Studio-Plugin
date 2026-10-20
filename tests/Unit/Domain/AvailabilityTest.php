<?php
namespace MOBookingStudio\Tests\Unit\Domain;

use MOBookingStudio\Domain\Availability;
use MOBookingStudio\Domain\DomainException;
use MOBookingStudio\Domain\Interval;
use MOBookingStudio\Domain\TimeZoneResolver;
use MOBookingStudio\Tests\Fixtures\Christmas;
use PHPUnit\Framework\TestCase;

final class AvailabilityTest extends TestCase {
	private function engine(): Availability {
		return new Availability( TimeZoneResolver::zone( Christmas::ZONE ) );
	}

	private function now(): \DateTimeImmutable {
		return new \DateTimeImmutable( '2026-10-20T12:00:00Z' );
	}

	private function countAvailable( array $days, callable $filter = null ): int {
		$n = 0;
		foreach ( $days as $date => $day ) {
			if ( $filter && ! $filter( $date ) ) {
				continue;
			}
			foreach ( $day['slots'] as $slot ) {
				$n += $slot['available'] ? 1 : 0;
			}
		}
		return $n;
	}

	/** AC03: exactamente 150 espacios, 44 en noviembre y 106 en diciembre; 82 en fin de semana y 68 entre semana. */
	public function test_christmas_sample_has_exact_slot_distribution(): void {
		$days = $this->engine()->days( Christmas::schedule(), Christmas::service(), '2026-11-01', '2026-12-31', $this->now() );

		$this->assertSame( 150, $this->countAvailable( $days ) );
		$this->assertSame( 44, $this->countAvailable( $days, function ( $d ) { return substr( $d, 5, 2 ) === '11'; } ) );
		$this->assertSame( 106, $this->countAvailable( $days, function ( $d ) { return substr( $d, 5, 2 ) === '12'; } ) );
		$weekend = function ( $d ) { return (int) ( new \DateTimeImmutable( $d ) )->format( 'N' ) >= 6; };
		$this->assertSame( 82, $this->countAvailable( $days, $weekend ) );
		$this->assertSame( 68, $this->countAvailable( $days, function ( $d ) use ( $weekend ) { return ! $weekend( $d ); } ) );
	}

	public function test_unlisted_days_stay_closed(): void {
		$days = $this->engine()->days( Christmas::schedule(), Christmas::service(), '2026-11-16', '2026-11-17', $this->now() );
		$this->assertFalse( $days['2026-11-16']['open'] );
		$this->assertFalse( $days['2026-11-17']['open'] );
	}

	public function test_last_slot_starts_thirty_minutes_before_block_end(): void {
		$days  = $this->engine()->days( Christmas::schedule(), Christmas::service(), '2026-11-15', '2026-11-15', $this->now() );
		$times = array_column( $days['2026-11-15']['slots'], 'local' );
		$this->assertSame( array( '13:00', '13:30', '14:00', '14:30', '15:00' ), $times );
	}

	public function test_local_times_convert_with_seasonal_offset_not_fixed_six_hours(): void {
		$days = $this->engine()->days( Christmas::schedule(), Christmas::service(), '2026-11-15', '2026-11-15', $this->now() );
		// 15 de noviembre: horario estándar de montaña, UTC-7.
		$this->assertSame( '2026-11-15T20:00:00+00:00', $days['2026-11-15']['slots'][0]['start']->format( 'c' ) );
	}

	public function test_slots_skip_nonexistent_local_time_on_spring_forward(): void {
		$engine   = $this->engine();
		$schedule = array( 'dates' => array( '2027-03-14' => array( array( '01:00', '04:00' ) ) ) );
		$params   = array( 'duration' => 30, 'interval' => 30 );
		$days     = $engine->days( $schedule, $params, '2027-03-14', '2027-03-14', $this->now() );
		$times    = array_column( $days['2027-03-14']['slots'], 'local' );
		$this->assertNotContains( '02:00', $times );
		$this->assertNotContains( '02:30', $times );
		$this->assertContains( '01:00', $times );
		$this->assertContains( '03:00', $times );
	}

	/** AC09: servicios con duraciones distintas no venden el mismo tiempo. */
	public function test_overlapping_occupancy_from_other_service_blocks_slot(): void {
		$engine = $this->engine();
		// Otra sesión de 60 min ocupa 13:15 a 14:15 locales (20:15–21:15 UTC).
		$busy = array( new Interval( new \DateTimeImmutable( '2026-11-15T20:15:00Z' ), new \DateTimeImmutable( '2026-11-15T21:15:00Z' ) ) );
		$days = $engine->days( Christmas::schedule(), Christmas::service(), '2026-11-15', '2026-11-15', $this->now(), $busy );
		$map  = array();
		foreach ( $days['2026-11-15']['slots'] as $s ) {
			$map[ $s['local'] ] = $s['available'];
		}
		$this->assertFalse( $map['13:00'] ); // 13:00–13:30 se cruza con 13:15.
		$this->assertFalse( $map['13:30'] );
		$this->assertFalse( $map['14:00'] ); // 14:00–14:30 se cruza con 14:15.
		$this->assertTrue( $map['14:30'] );
	}

	public function test_touching_intervals_do_not_conflict(): void {
		$engine = $this->engine();
		$busy   = array( new Interval( new \DateTimeImmutable( '2026-11-15T20:00:00Z' ), new \DateTimeImmutable( '2026-11-15T20:30:00Z' ) ) );
		$days   = $engine->days( Christmas::schedule(), Christmas::service(), '2026-11-15', '2026-11-15', $this->now(), $busy );
		$this->assertFalse( $days['2026-11-15']['slots'][0]['available'] );
		$this->assertTrue( $days['2026-11-15']['slots'][1]['available'] );
	}

	public function test_closed_date_and_block_override_openings(): void {
		$engine             = $this->engine();
		$schedule           = Christmas::schedule();
		$schedule['closed'] = array( '2026-11-18' => true );
		$blocks             = array( new Interval( new \DateTimeImmutable( '2026-11-20T00:00:00Z' ), new \DateTimeImmutable( '2026-11-20T01:00:00Z' ) ) );
		$days               = $engine->days( $schedule, Christmas::service(), '2026-11-18', '2026-11-19', $this->now(), array(), $blocks );
		$this->assertFalse( $days['2026-11-18']['open'] );
		$this->assertSame( 'blocked', $days['2026-11-19']['slots'][0]['reason'] );
		$this->assertTrue( $days['2026-11-19']['slots'][2]['available'] );
	}

	public function test_date_override_replaces_weekly_schedule(): void {
		$engine   = $this->engine();
		$schedule = array(
			'weekly' => array( 1 => array( array( '09:00', '11:00' ) ) ),
			'dates'  => array( '2026-11-23' => array( array( '15:00', '16:00' ) ) ),
		);
		$params   = array( 'duration' => 60 );
		$days     = $engine->days( $schedule, $params, '2026-11-23', '2026-11-30', $this->now() );
		$this->assertSame( array( '15:00' ), array_column( $days['2026-11-23']['slots'], 'local' ) );
		$this->assertSame( array( '09:00', '10:00' ), array_column( $days['2026-11-30']['slots'], 'local' ) );
	}

	public function test_min_notice_and_horizon(): void {
		$engine = $this->engine();
		$params = array_merge( Christmas::service(), array( 'min_notice_minutes' => 24 * 60 ) );
		$now    = new \DateTimeImmutable( '2026-11-14T21:00:00Z' ); // 14:00 local del 14 nov.
		$days   = $engine->days( Christmas::schedule(), $params, '2026-11-15', '2026-11-15', $now );
		$this->assertSame( 'notice', $days['2026-11-15']['slots'][0]['reason'] );
		$this->assertTrue( $days['2026-11-15']['slots'][2]['available'] ); // 14:00 local, justo 24 h.

		$params = array_merge( Christmas::service(), array( 'horizon_days' => 30 ) );
		$days   = $engine->days( Christmas::schedule(), $params, '2026-12-20', '2026-12-20', $this->now() );
		$this->assertSame( 'horizon', $days['2026-12-20']['slots'][0]['reason'] );
	}

	public function test_max_per_day(): void {
		$params = array_merge( Christmas::service(), array( 'max_per_day' => 2 ) );
		$days   = $this->engine()->days( Christmas::schedule(), $params, '2026-11-15', '2026-11-15', $this->now(), array(), array(), array( '2026-11-15' => 2 ) );
		$this->assertSame( 'max_per_day', $days['2026-11-15']['slots'][0]['reason'] );
	}

	public function test_find_slot_rejects_times_outside_schedule(): void {
		$engine = $this->engine();
		$this->assertNotNull( $engine->findSlot( Christmas::schedule(), Christmas::service(), new \DateTimeImmutable( '2026-11-15T20:30:00Z' ), $this->now() ) );
		$this->assertNull( $engine->findSlot( Christmas::schedule(), Christmas::service(), new \DateTimeImmutable( '2026-11-15T20:10:00Z' ), $this->now() ) );
		$this->assertNull( $engine->findSlot( Christmas::schedule(), Christmas::service(), new \DateTimeImmutable( '2026-11-16T20:00:00Z' ), $this->now() ) );
	}

	public function test_window_validation(): void {
		$this->expectException( DomainException::class );
		Availability::validateWindows( array( array( '10:00', '12:00' ), array( '11:30', '13:00' ) ) );
	}

	public function test_window_end_before_start_rejected(): void {
		$this->expectException( DomainException::class );
		Availability::validateWindows( array( array( '12:00', '10:00' ) ) );
	}

	public function test_interval_utc_days(): void {
		$i = new Interval( new \DateTimeImmutable( '2026-11-18T23:30:00Z' ), new \DateTimeImmutable( '2026-11-19T00:30:00Z' ) );
		$this->assertSame( array( '2026-11-18', '2026-11-19' ), $i->utcDays() );
		$j = new Interval( new \DateTimeImmutable( '2026-11-18T23:30:00Z' ), new \DateTimeImmutable( '2026-11-19T00:00:00Z' ) );
		$this->assertSame( array( '2026-11-18' ), $j->utcDays() );
	}
}
