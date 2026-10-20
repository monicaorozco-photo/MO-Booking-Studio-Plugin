<?php
namespace MOBookingStudio\Tests\Unit\Domain;

use MOBookingStudio\Domain\DomainException;
use MOBookingStudio\Domain\Pricing;
use MOBookingStudio\Domain\TimeZoneResolver;
use MOBookingStudio\Tests\Fixtures\Christmas;
use PHPUnit\Framework\TestCase;

final class PricingTest extends TestCase {
	private function engine(): Pricing {
		return new Pricing( TimeZoneResolver::zone( Christmas::ZONE ) );
	}

	private function quote( string $startUtc, string $requestedUtc, string $category, int $participants = 6, int $pets = 0 ): array {
		return $this->engine()->quote(
			Christmas::service(),
			Christmas::rules(),
			array(
				'start'        => new \DateTimeImmutable( $startUtc ),
				'requested_at' => new \DateTimeImmutable( $requestedUtc ),
				'category'     => $category,
				'participants' => $participants,
				'pets'         => $pets,
			)
		);
	}

	public function matrixProvider(): array {
		$nov      = '2026-11-20T17:00:00Z'; // 20 nov 10:00 local.
		$dec      = '2026-12-12T17:00:00Z'; // 12 dic 10:00 local.
		$presale  = '2026-10-25T18:00:00Z';
		$regular  = '2026-11-02T18:00:00Z';
		return array(
			'nov público regular'    => array( $nov, $regular, 'general', 160000 ),
			'nov público preventa'   => array( $nov, $presale, 'general', 140000 ),
			'nov frecuente regular'  => array( $nov, $regular, 'frequent', 140000 ),
			'nov frecuente preventa' => array( $nov, $presale, 'frequent', 120000 ),
			'dic público regular'    => array( $dec, $regular, 'general', 180000 ),
			'dic público preventa'   => array( $dec, $presale, 'general', 160000 ),
			'dic frecuente regular'  => array( $dec, $regular, 'frequent', 160000 ),
			'dic frecuente preventa' => array( $dec, $presale, 'frequent', 140000 ),
		);
	}

	/**
	 * AC04: las ocho combinaciones de tarifa coinciden con la matriz.
	 * @dataProvider matrixProvider
	 */
	public function test_christmas_rate_matrix( string $start, string $requested, string $category, int $expected ): void {
		$q = $this->quote( $start, $requested, $category );
		$this->assertSame( $expected, $q['total_cents'] );
		$this->assertSame( 50000, $q['deposit_cents'] );
		$this->assertSame( $expected - 50000, $q['balance_cents'] );
	}

	/** AC05: seis, ocho y diez personas; once se rechazan. */
	public function test_extra_participants(): void {
		$nov = '2026-11-20T17:00:00Z';
		$req = '2026-11-02T18:00:00Z';
		$this->assertSame( 0, $this->quote( $nov, $req, 'general', 6 )['extras_cents'] );
		$this->assertSame( 20000, $this->quote( $nov, $req, 'general', 8 )['extras_cents'] );
		$this->assertSame( 40000, $this->quote( $nov, $req, 'general', 10 )['extras_cents'] );
		$this->assertSame( 200000, $this->quote( $nov, $req, 'general', 10 )['total_cents'] );
		try {
			$this->quote( $nov, $req, 'general', 11 );
			$this->fail( 'Once personas debieron rechazarse.' );
		} catch ( DomainException $e ) {
			$this->assertSame( 'too_many_participants', $e->errorCode() );
		}
	}

	/** AC06: las mascotas no cuentan como personas ni cambian el precio. */
	public function test_pets_do_not_change_price(): void {
		$q = $this->quote( '2026-11-20T17:00:00Z', '2026-11-02T18:00:00Z', 'general', 10, 3 );
		$this->assertSame( 200000, $q['total_cents'] );
		$this->assertSame( 3, $q['pets'] );
	}

	/** AC07: cierre local exacto de la preventa: 31 oct 23:59:59 en Ciudad Juárez (UTC-6 en esa fecha). */
	public function test_presale_cutoff_is_local_and_inclusive(): void {
		$nov = '2026-11-20T17:00:00Z';
		$this->assertSame( 140000, $this->quote( $nov, '2026-11-01T05:59:59Z', 'general' )['total_cents'] );
		$this->assertSame( 160000, $this->quote( $nov, '2026-11-01T06:00:00Z', 'general' )['total_cents'] );
	}

	public function test_frequent_price_is_not_universal(): void {
		$q = $this->quote( '2026-11-20T17:00:00Z', '2026-11-02T18:00:00Z', 'something-else' );
		$this->assertSame( 'general', $q['category'] );
		$this->assertSame( 160000, $q['total_cents'] );
	}

	public function test_ambiguous_rules_with_same_priority_are_rejected(): void {
		$rules                 = Christmas::rules();
		$rules[1]['priority']  = 10; // Preventa pública con la misma prioridad que la regular.
		$this->assertNotEmpty( Pricing::findConflicts( $rules ) );
		$this->expectException( DomainException::class );
		$this->engine()->quote(
			Christmas::service(),
			$rules,
			array(
				'start'        => new \DateTimeImmutable( '2026-11-20T17:00:00Z' ),
				'requested_at' => new \DateTimeImmutable( '2026-10-25T17:00:00Z' ),
				'category'     => 'general',
				'participants' => 6,
			)
		);
	}

	public function test_christmas_rules_have_no_conflicts(): void {
		$this->assertSame( array(), Pricing::findConflicts( Christmas::rules() ) );
	}

	public function test_discounts_do_not_stack_by_default_and_never_go_negative(): void {
		$service = array( 'price_cents' => 100000, 'deposit_type' => 'percent', 'deposit_value' => 50 );
		$rules   = array(
			array( 'id' => 1, 'name' => 'Cupón grande', 'kind' => 'discount', 'priority' => 5, 'amount_type' => 'fixed', 'value' => 500000, 'conditions' => array() ),
			array( 'id' => 2, 'name' => 'Cupón 10%', 'kind' => 'discount', 'priority' => 1, 'amount_type' => 'percent', 'value' => 10, 'conditions' => array() ),
		);
		$q = $this->engine()->quote( $service, $rules, array(
			'start'        => new \DateTimeImmutable( '2026-11-20T17:00:00Z' ),
			'requested_at' => new \DateTimeImmutable( '2026-11-02T17:00:00Z' ),
			'participants' => 1,
		) );
		$this->assertSame( 0, $q['total_cents'] );
		$this->assertSame( array( 1 ), $q['discount_ids'] );
		$this->assertSame( 0, $q['deposit_cents'] );
	}

	public function test_stackable_discounts_and_extras_excluded(): void {
		$service = array(
			'price_cents'   => 100000,
			'deposit_type'  => 'percent',
			'deposit_value' => 33.333,
			'extras'        => array( array( 'key' => 'print', 'label' => 'Impresión', 'price_cents' => 15000, 'max_qty' => 3 ) ),
		);
		$rules   = array(
			array( 'id' => 1, 'name' => 'A', 'kind' => 'discount', 'priority' => 5, 'amount_type' => 'percent', 'value' => 10, 'stackable' => true, 'conditions' => array() ),
			array( 'id' => 2, 'name' => 'B', 'kind' => 'discount', 'priority' => 1, 'amount_type' => 'fixed', 'value' => 5000, 'stackable' => true, 'conditions' => array() ),
		);
		$q = $this->engine()->quote( $service, $rules, array(
			'start'        => new \DateTimeImmutable( '2026-11-20T17:00:00Z' ),
			'requested_at' => new \DateTimeImmutable( '2026-11-02T17:00:00Z' ),
			'participants' => 1,
			'extras'       => array( 'print' => 2 ),
		) );
		// 1000 - 10% = 900 - 50 = 850 + 2 x 150 = 1150.
		$this->assertSame( 115000, $q['total_cents'] );
		$this->assertSame( 38333, $q['deposit_cents'] ); // 33.333 % de 1150 redondeado al centavo.
	}

	public function test_unknown_extra_rejected(): void {
		$this->expectException( DomainException::class );
		$this->engine()->quote( Christmas::service(), Christmas::rules(), array(
			'start'        => new \DateTimeImmutable( '2026-11-20T17:00:00Z' ),
			'requested_at' => new \DateTimeImmutable( '2026-11-02T17:00:00Z' ),
			'participants' => 1,
			'extras'       => array( 'entrega_prioritaria' => 1 ),
		) );
	}

	public function test_rule_validation(): void {
		$this->expectException( DomainException::class );
		Pricing::validateRule( array( 'name' => 'x', 'kind' => 'discount', 'amount_type' => 'percent', 'value' => 120 ) );
	}
}
