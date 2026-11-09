<?php
namespace MOBookingStudio\Tests\Integration;

use MOBookingStudio\Application\Bookings;
use MOBookingStudio\Application\Catalog;
use MOBookingStudio\Application\Clients;
use MOBookingStudio\Domain\DomainException;
use MOBookingStudio\Infrastructure\Settings;

/**
 * beta.6.7: texto aparte para la leyenda de fechas agotadas y preguntas
 * "Solo para clientes nuevos".
 */
final class Beta67Test extends IntegrationTestCase {
	private const NOV20 = '2026-11-20T17:00:00Z';

	private function serviceWithReferral(): array {
		return $this->christmasService(
			array(
				'questions' => array(
					array( 'label' => '¿Dónde te enteraste de nosotros?', 'type' => 'select', 'required' => true, 'new_only' => true, 'options' => "Instagram\nRecomendación" ),
					array( 'label' => 'Colores de ropa', 'type' => 'text', 'required' => false ),
				),
			)
		);
	}

	public function test_soldout_date_label_is_separate_from_slot_taken_label(): void {
		$service = $this->christmasService();
		$texts   = Catalog::publicService( $service )['texts'];
		$this->assertSame( 'Horarios agotados', $texts['soldout_date'] );
		$this->assertSame( 'No disponible', $texts['slot_taken'] );

		Settings::update( array( 'soldout_date_label' => 'Agotado' ) );
		$this->assertSame( 'Agotado', Catalog::publicService( $service )['texts']['soldout_date'] );
		$this->assertSame( 'No disponible', Catalog::publicService( $service )['texts']['slot_taken'] );
	}

	public function test_new_only_flag_is_saved_and_published(): void {
		$service = $this->serviceWithReferral();
		$this->assertTrue( $service['questions'][0]['new_only'] );
		$this->assertFalse( $service['questions'][1]['new_only'] );
		$this->assertTrue( Catalog::publicService( $service )['questions'][0]['new_only'] );
	}

	public function test_new_client_must_answer_new_only_question(): void {
		$service = $this->serviceWithReferral();
		try {
			$this->book( $service, self::NOV20 );
			$this->fail( 'Se esperaba answer_required.' );
		} catch ( DomainException $e ) {
			$this->assertSame( 'answer_required', $e->errorCode() );
		}
		$key     = $service['questions'][0]['key'];
		$booking = Bookings::get( $this->book( $service, self::NOV20, array( 'answers' => array( $key => 'Instagram' ) ) )['booking']['id'] );
		$this->assertSame( 'Instagram', $booking['answers'][ $key ]['value'] );
	}

	public function test_known_client_skips_new_only_question(): void {
		$service = $this->serviceWithReferral();
		Clients::create( array( 'name' => 'Ana Prueba', 'email' => 'ana@example.com', 'historic_sessions' => 1 ) );
		$key     = $service['questions'][0]['key'];
		$booking = Bookings::get( $this->book( $service, self::NOV20, array( 'answers' => array( $key => 'Instagram' ) ) )['booking']['id'] );
		$this->assertArrayNotHasKey( $key, (array) $booking['answers'], 'A una clienta conocida no se le pide ni se guarda.' );
	}

	public function test_client_without_sessions_is_still_new(): void {
		Clients::create( array( 'name' => 'Ana Prueba', 'email' => 'ana@example.com' ) );
		$this->assertFalse( Bookings::isKnownClient( array( 'email_norm' => 'ana@example.com', 'phone_e164' => null ) ) );
		Clients::create( array( 'name' => 'Otra', 'email' => 'otra@example.com', 'phone' => '656 987 6543', 'historic_sessions' => 2 ) );
		$this->assertTrue( Bookings::isKnownClient( array( 'email_norm' => null, 'phone_e164' => '+526569876543' ) ) );
	}
}
