<?php
namespace MOBookingStudio\Tests\Integration;

use MOBookingStudio\Application\Bookings;
use MOBookingStudio\Application\Catalog;
use MOBookingStudio\Application\Contracts;
use MOBookingStudio\Domain\DomainException;

/**
 * Contrato editable, obligatorio y congelado por reserva (6.5, AC-08).
 */
final class ContractTest extends IntegrationTestCase {
	private const START = '2026-11-20T17:00:00Z';

	private function quote( array $service ): array {
		return Catalog::quote( $service, array( 'start' => self::START, 'children' => 1, 'adults' => 2 ) );
	}

	/** AC-08: editar la plantilla después de reservar no cambia lo aceptado, palabra por palabra. */
	public function test_booking_keeps_exact_accepted_version(): void {
		$id      = Contracts::save( null, 'Minis', 'Contrato de minis', '<p>Versión <strong>uno</strong>: llega 10 minutos antes.</p>', true );
		$service = $this->christmasService( array( 'contract_id' => $id ) );
		$this->assertSame( 1, Catalog::publicService( $service )['contract']['version'] );

		$booking = Bookings::create( $service, array_merge( $this->contact(), array( 'quote_id' => $this->quote( $service )['quote_id'], 'idempotency_key' => wp_generate_uuid4(), 'contract_version' => 1 ) ) )['booking'];
		$this->assertSame( $id, $booking['contract_id'] );
		$this->assertSame( 1, $booking['contract_version'] );
		$this->assertSame( 'Contrato de minis', $booking['contract_title'] );
		$this->assertSame( '<p>Versión <strong>uno</strong>: llega 10 minutos antes.</p>', $booking['contract_body'] );
		$this->assertSame( hash( 'sha256', "Contrato de minis\n" . $booking['contract_body'] ), $booking['contract_hash'] );
		$this->assertSame( '2026-10-20 18:00:00', $booking['contract_accepted_at'] );

		// Borrador: no afecta a nadie.
		Contracts::save( $id, 'Minis', 'Contrato de minis', '<p>Borrador sin publicar.</p>', false );
		$this->assertSame( 1, Catalog::publicService( $service )['contract']['version'] );
		// Publicar crea la versión 2; la reserva sigue con la 1.
		Contracts::save( $id, 'Minis', 'Contrato de minis 2027', '<p>Versión dos, con otras reglas.</p>', true );
		$this->assertSame( 2, Catalog::publicService( $service )['contract']['version'] );
		$kept = Bookings::get( $booking['id'] );
		$this->assertSame( 1, $kept['contract_version'] );
		$this->assertSame( '<p>Versión <strong>uno</strong>: llega 10 minutos antes.</p>', $kept['contract_body'] );
		$this->assertSame( '<p>Versión <strong>uno</strong>: llega 10 minutos antes.</p>', Contracts::version( $id, 1 )['body'], 'La versión 1 no se sobrescribe.' );
		// Publicar el mismo texto otra vez no crea una versión duplicada.
		Contracts::save( $id, 'Minis', 'Contrato de minis 2027', '<p>Versión dos, con otras reglas.</p>', true );
		$this->assertCount( 2, Contracts::versions( $id ) );
	}

	public function test_acceptance_and_version_are_enforced(): void {
		$id      = Contracts::save( null, 'Minis', 'Contrato', '<p>Uno</p>', true );
		$service = $this->christmasService( array( 'contract_id' => $id ) );
		try {
			Bookings::create( $service, array_merge( $this->contact( array( 'accept_terms' => false ) ), array( 'quote_id' => $this->quote( $service )['quote_id'], 'idempotency_key' => wp_generate_uuid4() ) ) );
			$this->fail( 'Sin aceptar no se reserva.' );
		} catch ( DomainException $e ) {
			$this->assertSame( 'terms_required', $e->errorCode() );
		}
		Contracts::save( $id, 'Minis', 'Contrato', '<p>Dos</p>', true );
		try {
			Bookings::create( $service, array_merge( $this->contact(), array( 'quote_id' => $this->quote( $service )['quote_id'], 'idempotency_key' => wp_generate_uuid4(), 'contract_version' => 1 ) ) );
			$this->fail( 'Aceptó una versión que ya no está vigente.' );
		} catch ( DomainException $e ) {
			$this->assertSame( 'contract_changed', $e->errorCode() );
			$this->assertSame( 2, $e->details()['contract']['version'] );
		}
	}

	public function test_public_booking_requires_published_contract(): void {
		$service = $this->christmasService( array( 'contract_id' => 0 ) );
		$this->assertNull( Catalog::publicService( $service )['contract'] );
		$this->expectExceptionMessage( 'no tiene un contrato publicado' );
		Bookings::create( $service, array_merge( $this->contact(), array( 'quote_id' => $this->quote( $service )['quote_id'], 'idempotency_key' => wp_generate_uuid4() ) ) );
	}

	public function test_html_is_restricted_and_archiving_in_use_is_blocked(): void {
		$id = Contracts::save( null, 'Seguro', 'Contrato', '<p onclick="x()">Hola</p><script>alert(1)</script><a href="javascript:alert(1)">mal</a><a href="https://example.com">bien</a><style>p{}</style>', true );
		$v  = Contracts::current( $id );
		$this->assertStringNotContainsString( '<script', $v['body'] );
		$this->assertStringNotContainsString( 'onclick', $v['body'] );
		$this->assertStringNotContainsString( 'javascript:', $v['body'] );
		$this->assertStringNotContainsString( '<style', $v['body'] );
		$this->assertStringContainsString( 'href="https://example.com"', $v['body'] );

		$this->christmasService( array( 'contract_id' => $id ) );
		try {
			Contracts::setArchived( $id, true );
			$this->fail( 'Está asignado a un servicio.' );
		} catch ( DomainException $e ) {
			$this->assertSame( 'contract_in_use', $e->errorCode() );
		}
	}
}
