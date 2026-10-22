<?php
namespace MOBookingStudio\Tests\Integration;

use MOBookingStudio\Application\Bookings;
use MOBookingStudio\Application\Catalog;
use MOBookingStudio\Application\Clients;
use MOBookingStudio\Application\FrequentCheck;
use MOBookingStudio\Application\TestData;
use MOBookingStudio\Domain\DomainException;

/**
 * Pantalla inicial: "Soy cliente nuevo" / "Ya me he tomado fotos con ustedes".
 * Tabla de precios con preventa que termina sola.
 */
final class FrequentClientTest extends IntegrationTestCase {
	private const NOV20 = '2026-11-20T17:00:00Z';

	private function frequentClient( int $sessions = 3 ): int {
		return Clients::create( array( 'name' => 'Rosa María López', 'email' => 'rosa@example.com', 'phone' => '656 222 3344', 'historic_sessions' => $sessions ) );
	}

	/** SEG-04 / Salida B: identificarse nunca concede precio ni revela si el expediente existe. */
	public function test_identify_is_only_a_review_request(): void {
		$this->frequentClient( 9 );
		$known   = FrequentCheck::identify( array( 'name' => 'Rosa López', 'contact' => 'rosa@example.com' ) );
		$unknown = FrequentCheck::identify( array( 'name' => 'Nadie', 'contact' => 'nadie@example.com' ) );
		$this->assertSame( $known, $unknown, 'Misma respuesta exista o no la persona.' );
		$this->assertFalse( $known['frequent'] );
		$this->assertTrue( $known['review'] );
		$this->assertArrayNotHasKey( 'token', $known );
		$this->assertFalse( FrequentCheck::automaticEnabled() );
	}

	/** Ni un token ni la cabecera de frecuente cambian el precio en calendario, cotización o reserva. */
	public function test_public_price_is_always_general(): void {
		$service = $this->christmasTableService();
		$this->frequentClient( 9 );
		$token   = str_repeat( 'a', 64 );
		set_transient( 'mo_fv_' . substr( hash( 'sha256', $token ), 0, 40 ), array( 'client_id' => 1, 'email' => 'rosa@example.com', 'phone' => null ), HOUR_IN_SECONDS );
		$this->assertSame( 'general', FrequentCheck::category( $token ) );

		$quote = Catalog::quote( $service, array( 'start' => self::NOV20, 'participants' => 6, 'frequent_token' => $token ) );
		$this->assertSame( 140000, $quote['total'], 'Precio general de preventa.' );
		$booking = Bookings::create( $service, array_merge( $this->contact( array( 'name' => 'Rosa López', 'email' => 'rosa@example.com' ) ), array( 'quote_id' => $quote['quote_id'], 'idempotency_key' => wp_generate_uuid4(), 'frequent_token' => $token, 'frequent_claim' => true ) ) )['booking'];
		$this->assertSame( 'general', $booking['client_category'] );
		$this->assertSame( 140000, $booking['total_cents'] );
		$this->assertSame( 1, $booking['frequent_claim'], 'Queda como solicitud de revisión.' );
	}

	/** La administradora aplica la tarifa frecuente con una acción auditada, conservando la fecha de solicitud (preventa). */
	public function test_admin_applies_frequent_price_with_audit(): void {
		$service = $this->christmasTableService();
		$booking = $this->book( $service, self::NOV20, array( 'frequent_claim' => true ) )['booking'];
		$this->clock->advance( '+12 days' ); // Ya terminó la preventa.
		try {
			Bookings::recalculate( $booking['id'], 'frequent', '', $booking['version'] );
			$this->fail( 'Exige motivo.' );
		} catch ( DomainException $e ) {
			$this->assertSame( 'reason_required', $e->errorCode() );
		}
		$after = Bookings::recalculate( $booking['id'], 'frequent', 'Verifiqué 4 sesiones en mi registro', $booking['version'] );
		$this->assertSame( 'frequent', $after['client_category'] );
		$this->assertSame( 120000, $after['total_cents'], 'Frecuente con preventa: se respeta la fecha de la solicitud.' );
		$audit = array_column( \MOBookingStudio\Infrastructure\Audit::forEntity( 'booking', $booking['id'] ), 'action' );
		$this->assertContains( 'recalculated', $audit );

		// Después de la preventa, el mismo enlace muestra los precios regulares.
		$later = array_column( Catalog::availability( $service, '2026-11-01', '2026-11-30' )['days'], null, 'date' )['2026-11-25'];
		$this->assertSame( 160000, $later['price'] );
		$this->assertSame( 'regular', $later['price_type'] );
	}

	public function test_test_data_uses_price_table(): void {
		TestData::load();
		$service = \MOBookingStudio\Application\Services::findBySlug( 'prueba-minis-navidenas-2026' );
		$this->assertCount( 2, $service['price_table']['rows'] );
		$this->assertFalse( FrequentCheck::identify( array( 'name' => 'Frecuente', 'contact' => 'frecuente3@example.com' ) )['frequent'], 'Salida B.' );
		$days = Catalog::availability( $service, '2026-11-01', '2026-12-31' );
		$this->assertSame( 150, array_sum( array_column( $days['days'], 'available' ) ) );
		TestData::clear();
	}
}
