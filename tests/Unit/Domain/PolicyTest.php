<?php
namespace MOBookingStudio\Tests\Unit\Domain;

use MOBookingStudio\Domain\DomainException;
use MOBookingStudio\Domain\Policy;
use MOBookingStudio\Tests\Fixtures\Christmas;
use PHPUnit\Framework\TestCase;

final class PolicyTest extends TestCase {
	public function test_exactly_24_hours_falls_in_upper_range(): void {
		$ranges = Policy::validateRanges( array(
			array( 'min_hours' => 0, 'max_hours' => 24, 'deposit' => 'retain' ),
			array( 'min_hours' => 24, 'max_hours' => 48, 'deposit' => 'credit', 'charge_type' => 'fixed', 'charge_value' => 20000 ),
			array( 'min_hours' => 48, 'max_hours' => null, 'deposit' => 'credit' ),
		) );
		$this->assertSame( 24.0, Policy::rangeFor( $ranges, 24 * 3600 )['min_hours'] );
		$this->assertSame( 0.0, Policy::rangeFor( $ranges, 24 * 3600 - 1 )['min_hours'] );
		$this->assertSame( 48.0, Policy::rangeFor( $ranges, 48 * 3600 )['min_hours'] );
		$this->assertSame( 0.0, Policy::rangeFor( $ranges, -100 )['min_hours'] );
	}

	public function test_custom_hours_are_free_values(): void {
		$ranges = Policy::validateRanges( array(
			array( 'min_hours' => 0, 'max_hours' => 36.5, 'deposit' => 'retain' ),
			array( 'min_hours' => 36.5, 'max_hours' => null, 'deposit' => 'credit' ),
		) );
		$this->assertSame( 36.5, Policy::rangeFor( $ranges, 36 * 3600 + 1800 )['min_hours'] );
		$this->assertSame( 0.0, Policy::rangeFor( $ranges, 36 * 3600 + 1799 )['min_hours'] );
	}

	public function invalidRangesProvider(): array {
		return array(
			'hueco'           => array( array( array( 'min_hours' => 0, 'max_hours' => 24 ), array( 'min_hours' => 48, 'max_hours' => null ) ) ),
			'solape'          => array( array( array( 'min_hours' => 0, 'max_hours' => 30 ), array( 'min_hours' => 24, 'max_hours' => null ) ) ),
			'sin cero'        => array( array( array( 'min_hours' => 12, 'max_hours' => null ) ) ),
			'último cerrado'  => array( array( array( 'min_hours' => 0, 'max_hours' => 24 ) ) ),
			'negativo'        => array( array( array( 'min_hours' => -1, 'max_hours' => null ) ) ),
			'porcentaje 150'  => array( array( array( 'min_hours' => 0, 'max_hours' => null, 'charge_type' => 'percent', 'charge_value' => 150 ) ) ),
			'dos abiertos'    => array( array( array( 'min_hours' => 0, 'max_hours' => null ), array( 'min_hours' => 24, 'max_hours' => null ) ) ),
		);
	}

	/** @dataProvider invalidRangesProvider */
	public function test_ambiguous_or_invalid_ranges_rejected( array $ranges ): void {
		$this->expectException( DomainException::class );
		Policy::validateRanges( $ranges );
	}

	/** AC17: reagendar a 24 horas exactas conserva el anticipo. */
	public function test_christmas_reschedule_at_exactly_24h_keeps_deposit(): void {
		$ranges  = Policy::validateRanges( Christmas::reschedulePolicy() );
		$range   = Policy::rangeFor( $ranges, 24 * 3600 );
		$outcome = Policy::outcome( 'reschedule', $range, array( 'total_cents' => 160000, 'deposit_cents' => 50000, 'paid_cents' => 50000 ), array( 'total_cents' => 160000, 'deposit_cents' => 50000 ) );
		$this->assertSame( 0, $outcome['retained'] );
		$this->assertSame( 50000, $outcome['credit'] );
		$this->assertSame( 0, $outcome['amount_due_now'] );
		$this->assertSame( 110000, $outcome['new_balance'] );
	}

	/** AC18: con menos de 24 horas se pierde el anticipo y la nueva fecha exige otro, por separado. */
	public function test_christmas_reschedule_under_24h_retains_and_requires_new_deposit(): void {
		$ranges  = Policy::validateRanges( Christmas::reschedulePolicy() );
		$range   = Policy::rangeFor( $ranges, 24 * 3600 - 1 );
		$outcome = Policy::outcome( 'reschedule', $range, array( 'total_cents' => 160000, 'deposit_cents' => 50000, 'paid_cents' => 50000 ), array( 'total_cents' => 160000, 'deposit_cents' => 50000 ) );
		$this->assertSame( 50000, $outcome['retained'] );
		$this->assertSame( 0, $outcome['penalty'] );
		$this->assertSame( 0, $outcome['credit'] );
		$this->assertSame( 50000, $outcome['new_deposit'] );
		$this->assertSame( 50000, $outcome['amount_due_now'] );
	}

	/** AC22: cancelación con anticipo: se retiene una vez y no hay devolución ficticia. */
	public function test_christmas_cancellation_retains_deposit_once(): void {
		$ranges  = Policy::validateRanges( Christmas::cancelPolicy() );
		$outcome = Policy::outcome( 'cancel', Policy::rangeFor( $ranges, 72 * 3600 ), array( 'total_cents' => 160000, 'deposit_cents' => 50000, 'paid_cents' => 50000 ) );
		$this->assertSame( 50000, $outcome['retained'] );
		$this->assertSame( 0, $outcome['refund_due'] );
		$this->assertSame( 0, $outcome['penalty'] );
	}

	public function test_cancellation_after_full_payment_refunds_only_non_deposit_part(): void {
		$ranges  = Policy::validateRanges( Christmas::cancelPolicy() );
		$outcome = Policy::outcome( 'cancel', Policy::rangeFor( $ranges, 72 * 3600 ), array( 'total_cents' => 160000, 'deposit_cents' => 50000, 'paid_cents' => 160000 ) );
		$this->assertSame( 50000, $outcome['retained'] );
		$this->assertSame( 110000, $outcome['refund_due'] );
	}

	public function test_penalty_is_covered_with_paid_money_first(): void {
		$ranges  = Policy::validateRanges( array(
			array( 'min_hours' => 0, 'max_hours' => null, 'charge_type' => 'percent', 'charge_value' => 50, 'charge_base' => 'total', 'deposit' => 'refund' ),
		) );
		$outcome = Policy::outcome( 'cancel', Policy::rangeFor( $ranges, 3600 ), array( 'total_cents' => 200000, 'deposit_cents' => 50000, 'paid_cents' => 50000 ) );
		$this->assertSame( 100000, $outcome['penalty'] );
		$this->assertSame( 50000, $outcome['penalty_from_paid'] );
		$this->assertSame( 50000, $outcome['penalty_due'] );
		$this->assertSame( 0, $outcome['refund_due'] );
	}

	public function test_description_is_readable(): void {
		$ranges = Policy::validateRanges( Christmas::reschedulePolicy() );
		$this->assertStringContainsString( 'menos de 24 horas', Policy::describeRange( $ranges[0] ) );
		$this->assertStringContainsString( '24 horas o más', Policy::describeRange( $ranges[1] ) );
	}
}
