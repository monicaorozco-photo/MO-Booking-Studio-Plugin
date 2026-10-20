<?php
namespace MOBookingStudio\Tests\Unit\Domain;

use MOBookingStudio\Domain\BookingStatus;
use MOBookingStudio\Domain\Contact;
use MOBookingStudio\Domain\DomainException;
use MOBookingStudio\Domain\Ledger;
use MOBookingStudio\Domain\Money;
use MOBookingStudio\Domain\TimeZoneResolver;
use PHPUnit\Framework\TestCase;

final class LedgerAndStatusTest extends TestCase {
	public function test_partial_deposit_does_not_cover_requirement(): void {
		$s = Ledger::summarize( array( array( 'id' => 1, 'type' => 'payment', 'amount_cents' => 30000 ) ), 160000, 50000 );
		$this->assertSame( Ledger::DEPOSIT_PARTIAL, $s['status'] );
		$this->assertSame( 20000, $s['deposit_missing'] );
		$this->assertFalse( $s['deposit_covered'] );
	}

	public function test_reversal_cancels_movement_without_deleting_it(): void {
		$s = Ledger::summarize( array(
			array( 'id' => 1, 'type' => 'payment', 'amount_cents' => 50000 ),
			array( 'id' => 2, 'type' => 'reversal', 'amount_cents' => 50000, 'reverses_id' => 1 ),
		), 160000, 50000, true );
		$this->assertSame( 0, $s['received_cents'] );
		$this->assertSame( Ledger::IN_REVIEW, $s['status'] );
	}

	/** AC23: ajuste a frecuente tras liquidar: excedente como devolución o crédito pendiente. */
	public function test_excess_after_price_adjustment(): void {
		$s = Ledger::summarize( array( array( 'id' => 1, 'type' => 'payment', 'amount_cents' => 160000 ) ), 140000, 50000 );
		$this->assertSame( 20000, $s['excess_cents'] );
		$this->assertSame( Ledger::PAID, $s['status'] );
		$s = Ledger::summarize( array(
			array( 'id' => 1, 'type' => 'payment', 'amount_cents' => 160000 ),
			array( 'id' => 2, 'type' => 'refund_due', 'amount_cents' => 20000 ),
		), 140000, 50000 );
		$this->assertSame( Ledger::REFUND_PENDING, $s['status'] );
		$this->assertSame( 160000, $s['net_cents'] );
	}

	public function test_retention_does_not_count_toward_new_deposit(): void {
		$s = Ledger::summarize( array(
			array( 'id' => 1, 'type' => 'payment', 'amount_cents' => 50000 ),
			array( 'id' => 2, 'type' => 'retention', 'amount_cents' => 50000 ),
		), 160000, 50000 );
		$this->assertSame( 50000, $s['received_cents'] );
		$this->assertSame( 0, $s['applied_cents'] );
		$this->assertSame( 50000, $s['deposit_missing'] );
		$this->assertSame( 160000, $s['balance_cents'] );
	}

	public function test_transitions(): void {
		$this->assertTrue( BookingStatus::canTransition( BookingStatus::PENDING_PAYMENT, BookingStatus::PAYMENT_REVIEW ) );
		$this->assertFalse( BookingStatus::canTransition( BookingStatus::EXPIRED, BookingStatus::CONFIRMED ) );
		$this->assertFalse( BookingStatus::occupies( BookingStatus::CANCELLED ) );
		$this->assertTrue( BookingStatus::occupies( BookingStatus::PAYMENT_REVIEW ) );
		$this->expectException( DomainException::class );
		BookingStatus::assertTransition( BookingStatus::CONFIRMED, BookingStatus::CONFIRMED );
	}

	public function phoneProvider(): array {
		return array(
			array( '656 123 4567', 'MX', '+526561234567' ),
			array( '(656) 123-4567', 'MX', '+526561234567' ),
			array( '52 656 123 4567', 'MX', '+526561234567' ),
			array( '521 656 123 4567', 'MX', '+526561234567' ),
			array( '+52 1 656 123 4567', 'MX', '+526561234567' ),
			array( '915 555 0100', 'US', '+19155550100' ),
			array( '1 915 555 0100', 'US', '+19155550100' ),
			array( '+1 915 555 0100', 'MX', '+19155550100' ),
			array( '12345', 'MX', null ),
			array( '656 123 456', 'MX', null ),
		);
	}

	/** @dataProvider phoneProvider */
	public function test_phone_normalization( string $raw, string $country, ?string $expected ): void {
		$this->assertSame( $expected, Contact::normalizePhone( $raw, $country ) );
	}

	public function test_email_normalization(): void {
		$this->assertSame( 'ana@example.com', Contact::normalizeEmail( '  Ana@Example.COM ' ) );
		$this->assertNull( Contact::normalizeEmail( 'no es correo' ) );
	}

	public function test_money(): void {
		$this->assertSame( 160000, Money::parse( '1,600' ) );
		$this->assertSame( 160050, Money::parse( '1600.5' ) );
		$this->assertNull( Money::parse( '1.600.00' ) );
		$this->assertSame( '$1,600 MXN', Money::format( 160000 ) );
		$this->assertSame( '$1,600.05 MXN', Money::format( 160005 ) );
		$this->assertSame( 1, Money::percentOf( 1, 50 ) ); // 0.5 centavos -> 1 (half-up).
	}

	public function test_timezone_resolver_falls_back_when_zone_missing(): void {
		$r = TimeZoneResolver::resolve( 'America/Ciudad_Juarez' );
		$this->assertContains( $r['effective'], array( 'America/Ciudad_Juarez', 'America/Denver' ) );
		// Ambas zonas deben dar el mismo desfase en fechas clave de 2026.
		foreach ( array( '2026-10-31 23:59:59', '2026-11-15 13:00', '2026-12-20 10:00' ) as $local ) {
			$a = new \DateTimeImmutable( $local, $r['zone'] );
			$b = new \DateTimeImmutable( $local, new \DateTimeZone( 'America/Denver' ) );
			$this->assertSame( $b->getOffset(), $a->getOffset() );
		}
		$this->expectException( DomainException::class );
		TimeZoneResolver::resolve( 'Mars/Olympus' );
	}
}
