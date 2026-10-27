<?php
namespace MOBookingStudio\Tests\Unit\Domain;

use MOBookingStudio\Domain\DomainException;
use MOBookingStudio\Domain\Pricing;
use MOBookingStudio\Domain\TimeZoneResolver;
use MOBookingStudio\Tests\Fixtures\Christmas;
use PHPUnit\Framework\TestCase;

/**
 * Tabla de precios por temporada: un solo enlace de reserva; al pasar la fecha límite
 * de preventa se cobran automáticamente los precios regulares.
 */
final class PriceTableTest extends TestCase {
	private function quote( string $start, string $requested, string $category, array $table = null ): array {
		$service                = Christmas::service();
		$service['price_table'] = Pricing::validateTable( $table ?? Christmas::priceTable() );
		return ( new Pricing( TimeZoneResolver::zone( Christmas::ZONE ) ) )->quote(
			$service,
			array(),
			array(
				'start'        => new \DateTimeImmutable( $start ),
				'requested_at' => new \DateTimeImmutable( $requested ),
				'category'     => $category,
				'participants' => 6,
			)
		);
	}

	public function matrixProvider(): array {
		return ( new PricingTest() )->matrixProvider();
	}

	/**
	 * AC04 con la tabla simple: mismos ocho importes que la matriz.
	 * @dataProvider matrixProvider
	 */
	public function test_table_reproduces_christmas_matrix( string $start, string $requested, string $category, int $expected ): void {
		$this->assertSame( $expected, $this->quote( $start, $requested, $category )['total_cents'] );
	}

	public function test_presale_ends_automatically_at_local_deadline(): void {
		$nov = '2026-11-20T17:00:00Z';
		$pre = $this->quote( $nov, '2026-11-01T05:59:59Z', 'general' ); // 31 oct 23:59:59 local.
		$reg = $this->quote( $nov, '2026-11-01T06:00:00Z', 'general' ); // 1 nov 00:00 local.
		$this->assertSame( 'presale', $pre['price_type'] );
		$this->assertSame( 140000, $pre['total_cents'] );
		$this->assertSame( 'regular', $reg['price_type'] );
		$this->assertSame( 160000, $reg['total_cents'] );
		$this->assertStringContainsString( 'Preventa · público general', $pre['lines'][0]['label'] );
		$this->assertTrue( $pre['special'] );
		$this->assertFalse( $this->quote( '2026-12-12T17:00:00Z', '2026-10-25T00:00:00Z', 'general' )['special'] );
	}

	/** Rediseño: desglose solo para mostrar; el total no cambia. */
	public function test_breakdown_from_reference_price(): void {
		$pre = $this->quote( '2026-11-20T17:00:00Z', '2026-10-20T00:00:00Z', 'general' );
		$this->assertSame( 140000, $pre['total_cents'] );
		$this->assertSame(
			array( 'reference' => 180000, 'steps' => array( array( 'kind' => 'date', 'amount' => -20000 ), array( 'kind' => 'presale', 'amount' => -20000 ) ) ),
			$pre['breakdown']
		);
		$freq = $this->quote( '2026-11-20T17:00:00Z', '2026-10-20T00:00:00Z', 'frequent' );
		$this->assertSame( array( 'date', 'frequent', 'presale' ), array_column( $freq['breakdown']['steps'], 'kind' ) );
		$this->assertSame( 180000 - 120000, -array_sum( array_column( $freq['breakdown']['steps'], 'amount' ) ) );
		// Fecha de precio más alto, sin preventa: no hay nada que desglosar.
		$this->assertNull( $this->quote( '2026-12-12T17:00:00Z', '2026-11-05T00:00:00Z', 'general' )['breakdown'] );
	}

	public function test_missing_prices_fall_back_sensibly(): void {
		$table = array(
			'presale_until' => '2026-10-31 23:59',
			'rows'          => array( array( 'general' => 200000, 'presale_general' => 180000 ) ),
		);
		// Frecuente sin precio propio paga el general; sin preventa propia, su regular.
		$this->assertSame( 180000, $this->quote( '2026-11-20T17:00:00Z', '2026-10-25T00:00:00Z', 'general', $table )['total_cents'] );
		$this->assertSame( 200000, $this->quote( '2026-11-20T17:00:00Z', '2026-10-25T00:00:00Z', 'frequent', $table )['total_cents'] );
	}

	public function test_dates_outside_table_use_service_price(): void {
		$q = $this->quote( '2027-01-10T17:00:00Z', '2026-10-25T00:00:00Z', 'general' );
		$this->assertSame( 180000, $q['total_cents'] );
		$this->assertSame( 'Tarifa del servicio', $q['lines'][0]['label'] );
	}

	public function invalidTables(): array {
		return array(
			'rangos encimados'    => array( array( 'presale_until' => '', 'rows' => array( array( 'from' => '2026-11-01', 'to' => '2026-11-30', 'general' => 1 ), array( 'from' => '2026-11-30', 'to' => '2026-12-05', 'general' => 1 ) ) ) ),
			'preventa sin fecha'  => array( array( 'presale_until' => '', 'rows' => array( array( 'general' => 100, 'presale_general' => 90 ) ) ) ),
			'sin precio general'  => array( array( 'presale_until' => '', 'rows' => array( array( 'frequent' => 100 ) ) ) ),
			'dos filas sin fecha' => array( array( 'presale_until' => '', 'rows' => array( array( 'general' => 1 ), array( 'general' => 2 ) ) ) ),
		);
	}

	/** @dataProvider invalidTables */
	public function test_invalid_tables_rejected( array $table ): void {
		$this->expectException( DomainException::class );
		Pricing::validateTable( $table );
	}
}
