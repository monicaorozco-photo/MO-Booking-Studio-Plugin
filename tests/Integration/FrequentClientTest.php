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

	public function test_frequent_client_found_by_email_or_phone_with_name_support(): void {
		$this->frequentClient( 3 );
		$byEmail = FrequentCheck::identify( array( 'name' => 'rosa lopez', 'contact' => 'ROSA@example.com' ) );
		$this->assertTrue( $byEmail['frequent'] );
		$this->assertSame( 64, strlen( $byEmail['token'] ) );
		$byPhone = FrequentCheck::identify( array( 'name' => 'Rosa', 'contact' => '(656) 222-3344' ) );
		$this->assertTrue( $byPhone['frequent'] );
		// La respuesta no expone datos del expediente.
		$this->assertArrayNotHasKey( 'sessions', $byEmail );
		$this->assertStringNotContainsString( 'López', json_encode( $byEmail ) );
	}

	public function test_not_confirmed_cases_share_one_message(): void {
		$this->frequentClient( 2 ); // Menos sesiones que el umbral.
		$few      = FrequentCheck::identify( array( 'name' => 'Rosa', 'contact' => 'rosa@example.com' ) );
		$unknown  = FrequentCheck::identify( array( 'name' => 'Nadie', 'contact' => 'nadie@example.com' ) );
		$wrongNm  = FrequentCheck::identify( array( 'name' => 'Pedro', 'contact' => 'rosa@example.com' ) );
		foreach ( array( $few, $unknown, $wrongNm ) as $r ) {
			$this->assertFalse( $r['frequent'] );
			$this->assertArrayNotHasKey( 'token', $r );
			$this->assertSame( FrequentCheck::NOT_CONFIRMED, $r['message'] );
		}
	}

	public function test_manual_category_counts_and_shared_contact_is_not_resolved(): void {
		Clients::create( array( 'name' => 'Ana Uno', 'email' => 'ana1@example.com', 'phone' => '656 999 0000', 'manual_category' => 'frequent' ) );
		$this->assertTrue( FrequentCheck::identify( array( 'name' => 'Ana', 'contact' => 'ana1@example.com' ) )['frequent'] );
		Clients::create( array( 'name' => 'Ana Dos', 'email' => 'ana2@example.com', 'phone' => '656 999 0000', 'historic_sessions' => 9 ) );
		$this->assertFalse( FrequentCheck::identify( array( 'name' => 'Ana', 'contact' => '6569990000' ) )['frequent'] );
	}

	public function test_frequent_presale_price_end_to_end_with_single_link(): void {
		$service = $this->christmasTableService();
		$clientId = $this->frequentClient( 3 );
		$check    = FrequentCheck::identify( array( 'name' => 'Rosa', 'contact' => 'rosa@example.com' ) );

		$general  = Catalog::availability( $service, '2026-11-01', '2026-11-30' );
		$frequent = Catalog::availability( $service, '2026-11-01', '2026-11-30', FrequentCheck::category( $check['token'] ) );
		$g        = array_column( $general['days'], null, 'date' )['2026-11-20'];
		$f        = array_column( $frequent['days'], null, 'date' )['2026-11-20'];
		$this->assertSame( 140000, $g['price'] );
		$this->assertSame( 120000, $f['price'] );
		$this->assertSame( 'presale', $g['price_type'] );
		$this->assertTrue( $g['special'] );
		$this->assertStringContainsString( 'Preventa hasta el sábado 31 de octubre de 2026, 23:59', $general['presale_txt'] );

		$quote   = Catalog::quote( $service, array( 'start' => self::NOV20, 'participants' => 6, 'frequent_token' => $check['token'] ) );
		$this->assertSame( 120000, $quote['total'] );
		$booking = Bookings::create( $service, array_merge( $this->contact( array( 'name' => 'Rosa López', 'email' => 'rosa@example.com', 'phone' => '656 000 0000' ) ), array( 'quote_id' => $quote['quote_id'], 'idempotency_key' => wp_generate_uuid4(), 'frequent_token' => $check['token'], 'frequent_claim' => true ) ) )['booking'];
		$this->assertSame( 'frequent', $booking['client_category'] );
		$this->assertSame( 120000, $booking['total_cents'] );
		$this->assertSame( $clientId, $booking['client_id'] );
		$this->assertSame( 'verified_by_contact', $booking['quote']['frequent_check'] );

		// Después de la fecha límite, el mismo enlace muestra precios regulares.
		$this->clock->advance( '+12 days' );
		$after = array_column( Catalog::availability( $service, '2026-11-01', '2026-11-30' )['days'], null, 'date' )['2026-11-25'];
		$this->assertSame( 160000, $after['price'] );
		$this->assertSame( 'regular', $after['price_type'] );
		$afterF = array_column( Catalog::availability( $service, '2026-11-01', '2026-11-30', 'frequent' )['days'], null, 'date' )['2026-11-25'];
		$this->assertSame( 140000, $afterF['price'] );
	}

	public function test_frequent_price_requires_same_contact_and_valid_token(): void {
		$service = $this->christmasTableService();
		$this->frequentClient( 3 );
		$check = FrequentCheck::identify( array( 'name' => 'Rosa', 'contact' => 'rosa@example.com' ) );
		$quote = Catalog::quote( $service, array( 'start' => self::NOV20, 'participants' => 6, 'frequent_token' => $check['token'] ) );
		try {
			Bookings::create( $service, array_merge( $this->contact( array( 'email' => 'otra@example.com', 'phone' => '656 000 0001' ) ), array( 'quote_id' => $quote['quote_id'], 'idempotency_key' => wp_generate_uuid4(), 'frequent_token' => $check['token'] ) ) );
			$this->fail( 'Se esperaba rechazo por contacto distinto.' );
		} catch ( DomainException $e ) {
			$this->assertSame( 'frequent_contact_mismatch', $e->errorCode() );
		}
		// Un token inventado no da precio de frecuente.
		$fake = Catalog::quote( $service, array( 'start' => self::NOV20, 'participants' => 6, 'frequent_token' => str_repeat( 'a', 64 ) ) );
		$this->assertSame( 140000, $fake['total'] );
	}

	public function test_test_data_uses_price_table(): void {
		TestData::load();
		$service = \MOBookingStudio\Application\Services::findBySlug( 'prueba-minis-navidenas-2026' );
		$this->assertCount( 2, $service['price_table']['rows'] );
		$this->assertTrue( FrequentCheck::identify( array( 'name' => 'Frecuente', 'contact' => 'frecuente3@example.com' ) )['frequent'] );
		$this->assertFalse( FrequentCheck::identify( array( 'name' => 'Beneficio', 'contact' => 'pendiente@example.com' ) )['frequent'] );
		$days = Catalog::availability( $service, '2026-11-01', '2026-12-31' );
		$this->assertSame( 150, array_sum( array_column( $days['days'], 'available' ) ) );
		TestData::clear();
	}
}
