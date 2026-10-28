<?php
namespace MOBookingStudio\Tests\Integration;

use MOBookingStudio\Application\Bookings;
use MOBookingStudio\Application\Catalog;
use MOBookingStudio\Application\Services;
use MOBookingStudio\Domain\DomainException;

/**
 * Formulario final (6.3, 6.4) y entrega rápida, del lado del servidor (AC-01).
 * El orden visual y el cálculo en pantalla se prueban en el navegador (tests/e2e).
 */
final class BookingFormTest extends IntegrationTestCase {
	private const START = '2026-11-20T17:00:00Z';

	private function fastService( array $overrides = array() ): array {
		return $this->christmasService(
			array_merge(
				array(
					'pets_allowed'  => true,
					'fast_delivery' => array(
						'enabled'      => true,
						'label'        => 'Entrega rápida',
						'price_cents'  => 30000,
						'question'     => '¿Deseas agregar entrega rápida de 2 días hábiles a tu sesión?',
						'regular_text' => 'Entrega regular de 5 a 7 días hábiles.',
					),
				),
				$overrides
			)
		);
	}

	private function rest( string $slug, array $body ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', '/mo-booking/v1/services/' . $slug . '/quote' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
		return rest_do_request( $request );
	}

	public function test_attendees_are_children_plus_adults_and_pets_are_separate(): void {
		$service = $this->fastService();
		$q       = Catalog::quote( $service, array( 'start' => self::START, 'children' => '3', 'adults' => '5', 'pets' => '2' ) );
		$booking = Bookings::create( $service, array_merge( $this->contact(), array( 'quote_id' => $q['quote_id'], 'idempotency_key' => wp_generate_uuid4() ) ) )['booking'];
		$this->assertSame( 3, $booking['children'] );
		$this->assertSame( 5, $booking['adults'] );
		$this->assertSame( 8, $booking['participants'] );
		$this->assertSame( 2, $booking['pets'] );
		// 6 incluidas + 2 personas adicionales a $100; las mascotas no suman personas.
		$this->assertSame( 140000 + 20000, $booking['total_cents'] ); // Tarifa del 20/11 + 2 adicionales.
	}

	public function test_pets_count_toward_price_only_when_configured(): void {
		// beta.6.3: el máximo es de adultos; con la opción, las mascotas se cobran como asistentes.
		$service = $this->fastService( array( 'pets_count_as_participants' => true ) );
		$counted = Catalog::quote( $service, array( 'start' => self::START, 'children' => 4, 'adults' => 5, 'pets' => 2 ) );
		$noPets  = Catalog::quote( $service, array( 'start' => self::START, 'children' => 4, 'adults' => 5, 'pets' => 0 ) );
		$this->assertSame( 20000, $counted['total'] - $noPets['total'], 'Las 2 mascotas se cobran como asistentes adicionales.' );
	}

	public function test_invalid_counts_are_rejected(): void {
		$service = $this->fastService();
		$cases   = array(
			array( 'children' => '-1', 'adults' => '2' ),
			array( 'children' => '1.5', 'adults' => '2' ),
			array( 'children' => 'dos', 'adults' => '2' ),
			array( 'children' => '0', 'adults' => '0' ),
			array( 'children' => '', 'adults' => '' ),
			array( 'children' => '1', 'adults' => '1', 'pets' => '-2' ),
		);
		foreach ( $cases as $case ) {
			try {
				Catalog::quote( $service, array_merge( array( 'start' => self::START ), $case ) );
				$this->fail( 'Debió rechazar ' . wp_json_encode( $case ) );
			} catch ( DomainException $e ) {
				$this->assertSame( 400, $e->httpStatus() );
			}
		}
	}

	/** En línea no se acepta un "número de personas" suelto: siempre niños + adultos. */
	public function test_public_api_requires_children_and_adults(): void {
		$service = $this->fastService();
		$noCounts = $this->rest( $service['slug'], array( 'start' => self::START, 'participants' => 6 ) );
		$this->assertSame( 400, $noCounts->get_status() );
		$ok = $this->rest( $service['slug'], array( 'start' => self::START, 'children' => '1', 'adults' => '1', 'participants' => 99 ) );
		$this->assertSame( 200, $ok->get_status() );
		$this->assertSame( 140000, $ok->get_data()['total'], 'Se ignora el número suelto.' );
	}

	public function test_public_service_texts(): void {
		$public = Catalog::publicService( $this->fastService() );
		$this->assertTrue( $public['pet_friendly'] );
		$this->assertStringNotContainsString( 'no cuentan', wp_json_encode( $public ) );
		$this->assertSame( '¿Deseas agregar entrega rápida de 2 días hábiles a tu sesión?', $public['fast_delivery']['question'] );
		$this->assertSame( 'Entrega regular de 5 a 7 días hábiles.', $public['fast_delivery']['regular_text'] );
	}

	/** Entrega rápida: extra con precio, desmarcada por defecto, congelada en la reserva. */
	public function test_fast_delivery_is_priced_and_frozen(): void {
		$service = $this->fastService();
		$without = Catalog::quote( $service, array( 'start' => self::START, 'children' => 0, 'adults' => 2 ) );
		$with    = Catalog::quote( $service, array( 'start' => self::START, 'children' => 0, 'adults' => 2, 'fast_delivery' => true ) );
		$this->assertSame( 30000, $with['total'] - $without['total'] );
		$this->assertContains( 'Entrega rápida', array_column( $with['lines'], 'label' ) );

		$booking = Bookings::create( $service, array_merge( $this->contact(), array( 'quote_id' => $with['quote_id'], 'idempotency_key' => wp_generate_uuid4() ) ) )['booking'];
		$total   = $booking['total_cents'];

		// Cambia el precio y el nombre en el servicio: la reserva no se altera.
		Services::save( array_merge( $service, array( 'fast_delivery' => array_merge( $service['fast_delivery'], array( 'price_cents' => 90000, 'label' => 'Express' ) ) ) ), $service['id'] );
		$this->assertSame( $total, Bookings::get( $booking['id'] )['total_cents'] );

		// Reprogramar recotiza, pero conserva el nombre y precio contratados del extra.
		$preview = Bookings::previewReschedule( Bookings::get( $booking['id'] ), new \DateTimeImmutable( '2026-11-27T17:00:00Z' ) );
		$fast    = array_values( array_filter( $preview['quote']['lines'], static function ( $l ) { return 'extra' === $l['kind']; } ) );
		$this->assertSame( 'Entrega rápida', $fast[0]['label'] );
		$this->assertSame( 30000, $fast[0]['amount'] );
	}

	public function test_important_info_name_and_email(): void {
		$service = $this->fastService();
		$q       = Catalog::quote( $service, array( 'start' => self::START, 'children' => 1, 'adults' => 1 ) );
		$booking = Bookings::create(
			$service,
			array_merge(
				$this->contact( array( 'name' => "  María   José\tÑúñez  ", 'email' => 'Maria.Jose@Example.com' ) ),
				array( 'quote_id' => $q['quote_id'], 'idempotency_key' => wp_generate_uuid4(), 'important_info' => "Mi hijo usa silla de ruedas.\n<script>x</script>" )
			)
		)['booking'];
		$this->assertSame( 'María José Ñúñez', $booking['contact']['name'] );
		$this->assertSame( 'Maria.Jose@Example.com', $booking['contact']['email'], 'Se conserva como la escribió.' );
		$this->assertSame( 'maria.jose@example.com', $booking['contact']['email_norm'] );
		$this->assertStringContainsString( 'silla de ruedas', $booking['important_info'] );
		$this->assertStringNotContainsString( '<script>', $booking['important_info'] );

		try {
			Bookings::create( $service, array_merge( $this->contact(), array( 'quote_id' => $q['quote_id'], 'idempotency_key' => wp_generate_uuid4(), 'important_info' => str_repeat( 'a', 2001 ) ) ) );
			$this->fail( 'Más de 2,000 caracteres.' );
		} catch ( DomainException $e ) {
			$this->assertSame( 'info_too_long', $e->errorCode() );
		}
		try {
			Bookings::create( $service, array_merge( $this->contact( array( 'phone' => '123' ) ), array( 'quote_id' => $q['quote_id'], 'idempotency_key' => wp_generate_uuid4() ) ) );
			$this->fail( 'Teléfono que no se puede normalizar.' );
		} catch ( DomainException $e ) {
			$this->assertSame( 'invalid_phone', $e->errorCode() );
		}
	}

	/** 6.4: la reserva guarda una instantánea de la pregunta; editarla o retirarla no cambia la respuesta. */
	public function test_question_snapshot_survives_edits(): void {
		$service = $this->fastService(
			array(
				'questions' => array(
					array( 'key' => 'peinado', 'label' => '¿Quieres peinado?', 'type' => 'select', 'required' => true, 'options' => array( 'Sí', 'No' ) ),
				),
			)
		);
		$q = Catalog::quote( $service, array( 'start' => self::START, 'children' => 1, 'adults' => 1 ) );
		try {
			Bookings::create( $service, array_merge( $this->contact(), array( 'quote_id' => $q['quote_id'], 'idempotency_key' => wp_generate_uuid4() ) ) );
			$this->fail( 'Pregunta obligatoria.' );
		} catch ( DomainException $e ) {
			$this->assertSame( 'answer_required', $e->errorCode() );
		}
		$booking = Bookings::create( $service, array_merge( $this->contact(), array( 'quote_id' => $q['quote_id'], 'idempotency_key' => wp_generate_uuid4(), 'answers' => array( 'peinado' => 'Sí' ) ) ) )['booking'];
		Services::save( array_merge( Services::get( $service['id'] ), array( 'questions' => array() ) ), $service['id'] );
		$answer = Bookings::get( $booking['id'] )['answers']['peinado'];
		$this->assertSame( '¿Quieres peinado?', $answer['label'] );
		$this->assertSame( 'select', $answer['type'] );
		$this->assertSame( array( 'Sí', 'No' ), $answer['options'] );
		$this->assertSame( 'Sí', $answer['value'] );
	}
}
