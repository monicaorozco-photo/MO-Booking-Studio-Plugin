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

	/** Decisión B: correo O teléfono + nombre parecido (acentos y faltas leves) reconoce al frecuente. */
	public function test_identify_by_name_and_email_or_phone(): void {
		$this->frequentClient( 9 );
		$byMail = FrequentCheck::identify( array( 'name' => 'rosa maria lopes', 'email' => 'ROSA@example.com', 'phone' => '' ) );
		$this->assertTrue( $byMail['frequent'], 'Sin acentos y con una falta leve.' );
		$this->assertSame( array( 'email' => true, 'phone' => false ), $byMail['locked'] );
		$byPhone = FrequentCheck::identify( array( 'name' => 'Rosa López', 'email' => 'otro@example.com', 'phone' => '6562223344' ) );
		$this->assertTrue( $byPhone['frequent'], 'Basta el teléfono si el correo no es de otro cliente.' );
		$this->assertSame( array( 'email' => false, 'phone' => true ), $byPhone['locked'] );

		$wrongName = FrequentCheck::identify( array( 'name' => 'Pedro Pérez', 'email' => 'rosa@example.com' ) );
		$unknown   = FrequentCheck::identify( array( 'name' => 'Nadie', 'email' => 'nadie@example.com' ) );
		$this->assertFalse( $wrongName['frequent'] );
		$this->assertSame( $wrongName['message'], $unknown['message'], 'Mismo mensaje exista o no el expediente.' );
		$this->assertArrayNotHasKey( 'token', $unknown );
	}

	/** beta.4: si el correo es de una sola clienta, manda el correo aunque el teléfono sea de otro expediente (se marca para revisar). */
	public function test_email_wins_when_phone_belongs_to_another_client(): void {
		$service = $this->christmasTableService();
		$this->frequentClient( 9 );
		Clients::create( array( 'name' => 'Luisa Otra', 'email' => 'otra@example.com', 'phone' => '656 999 0000', 'historic_sessions' => 9 ) );
		$r = FrequentCheck::identify( array( 'name' => 'Rosa López', 'email' => 'rosa@example.com', 'phone' => '656 999 0000' ) );
		$this->assertTrue( $r['frequent'] );
		$this->assertSame( array( 'email' => true, 'phone' => false ), $r['locked'], 'Solo el correo queda fijo.' );
		$quote   = Catalog::quote( $service, array( 'start' => self::NOV20, 'participants' => 6, 'frequent_token' => $r['token'] ) );
		$booking = Bookings::create( $service, array_merge( $this->contact( array( 'name' => 'Rosa López', 'email' => 'rosa@example.com', 'phone' => '656 999 0000' ) ), array( 'quote_id' => $quote['quote_id'], 'idempotency_key' => wp_generate_uuid4(), 'frequent_token' => $r['token'] ) ) )['booking'];
		$this->assertSame( FrequentCheck::MATCH_AUTO_REVIEW, $booking['frequent_match'], 'Queda marcada para revisar.' );
		$this->assertSame( 'frequent', $booking['client_category'] );
		// Solo con el teléfono de otra persona: no.
		$this->assertFalse( FrequentCheck::identify( array( 'name' => 'Rosa López', 'phone' => '656 999 0000' ) )['frequent'] );
	}

	public function test_no_discount_when_email_is_shared_by_two_clients(): void {
		$this->frequentClient( 9 );
		Clients::create( array( 'name' => 'Rosa Gemela', 'email' => 'rosa@example.com', 'historic_sessions' => 9 ) );
		$this->assertFalse( FrequentCheck::identify( array( 'name' => 'Rosa López', 'email' => 'rosa@example.com' ) )['frequent'] );
	}

	public function test_additional_contact_is_recognized_and_general_client_is_not(): void {
		$id = $this->frequentClient( 9 );
		Clients::addContact( $id, 'email', 'rosa.trabajo@example.com' );
		$this->assertTrue( FrequentCheck::identify( array( 'name' => 'Rosa', 'email' => 'rosa.trabajo@example.com' ) )['frequent'] );
		Clients::create( array( 'name' => 'Luis Nuevo', 'email' => 'luis@example.com', 'historic_sessions' => 1 ) );
		$this->assertFalse( FrequentCheck::identify( array( 'name' => 'Luis Nuevo', 'email' => 'luis@example.com' ) )['frequent'], 'Con 1 sesión no llega al umbral.' );
	}

	/** El precio frecuente llega al calendario, la cotización y la reserva, y exige el mismo contacto. */
	public function test_frequent_price_applies_and_admin_can_revert(): void {
		$service = $this->christmasTableService();
		$this->frequentClient( 9 );
		$token = FrequentCheck::identify( array( 'name' => 'Rosa López', 'email' => 'rosa@example.com' ) )['token'];
		$this->assertSame( 'frequent', FrequentCheck::category( $token ) );
		$day = array_column( Catalog::availability( $service, '2026-11-01', '2026-11-30', 'frequent' )['days'], null, 'date' )['2026-11-20'];
		$this->assertSame( 120000, $day['price'], 'Frecuente con preventa.' );

		$quote = Catalog::quote( $service, array( 'start' => self::NOV20, 'participants' => 6, 'frequent_token' => $token ) );
		$this->assertSame( 120000, $quote['total'] );
		try {
			Bookings::create( $service, array_merge( $this->contact( array( 'name' => 'Rosa López', 'email' => 'otra-persona@example.com', 'phone' => '656 000 1111' ) ), array( 'quote_id' => $quote['quote_id'], 'idempotency_key' => wp_generate_uuid4(), 'frequent_token' => $token ) ) );
			$this->fail( 'Debe usar el contacto con el que se identificó.' );
		} catch ( DomainException $e ) {
			$this->assertSame( 'frequent_contact_mismatch', $e->errorCode() );
		}
		$booking = Bookings::create( $service, array_merge( $this->contact( array( 'name' => 'Rosa López', 'email' => 'rosa@example.com' ) ), array( 'quote_id' => $quote['quote_id'], 'idempotency_key' => wp_generate_uuid4(), 'frequent_token' => $token ) ) )['booking'];
		$this->assertSame( 'frequent', $booking['client_category'] );
		$this->assertSame( 120000, $booking['total_cents'] );
		$this->assertSame( FrequentCheck::MATCH_AUTO, $booking['frequent_match'] );

		$after = Bookings::revertFrequent( $booking['id'], $booking['version'] );
		$this->assertSame( 'general', $after['client_category'] );
		$this->assertSame( 140000, $after['total_cents'] );
		$this->assertSame( 'reverted', $after['frequent_match'] );
	}

	/** Un token inventado no concede nada. */
	public function test_fake_token_is_general(): void {
		$this->assertSame( 'general', FrequentCheck::category( str_repeat( 'a', 64 ) ) );
		$this->assertSame( 'general', FrequentCheck::category( 'x' ) );
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
		$this->assertTrue( FrequentCheck::identify( array( 'name' => 'Frecuente', 'email' => 'frecuente3@example.com' ) )['frequent'], 'Cliente de prueba con 3 sesiones.' );
		$days = Catalog::availability( $service, '2026-11-01', '2026-12-31' );
		$this->assertSame( 150, array_sum( array_column( $days['days'], 'available' ) ) );
		TestData::clear();
	}
}
