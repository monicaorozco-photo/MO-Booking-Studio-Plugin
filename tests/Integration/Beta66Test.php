<?php
namespace MOBookingStudio\Tests\Integration;

use MOBookingStudio\Application\Bookings;
use MOBookingStudio\Application\Catalog;
use MOBookingStudio\Application\Clients;
use MOBookingStudio\Application\Delivery;
use MOBookingStudio\Application\FrequentCheck;
use MOBookingStudio\Application\Notifications;
use MOBookingStudio\Application\Portal;
use MOBookingStudio\Application\Proofs;
use MOBookingStudio\Domain\BookingStatus;
use MOBookingStudio\Domain\BusinessDays;
use MOBookingStudio\Domain\DomainException;

/**
 * beta.6.6: cliente reconocida desde 1 sesión (precio frecuente desde el umbral), aviso de
 * preventa, días hábiles con festivos de México, sesión realizada automática con
 * "Gracias por tu sesión", aviso al liquidar y entrega rápida desde la página de reserva.
 */
final class Beta66Test extends IntegrationTestCase {
	private const NOV20 = '2026-11-20T17:00:00Z'; // Viernes 20 de noviembre, 10:00 en Ciudad Juárez.

	private function deliveryService(): array {
		return $this->christmasService(
			array(
				'delivery_days_min' => 5,
				'delivery_days_max' => 7,
				'fast_days_min'     => 2,
				'fast_days_max'     => 3,
				'fast_delivery'     => array( 'enabled' => true, 'label' => 'Entrega rápida', 'price_cents' => 30000, 'question' => '¿Entrega rápida?', 'regular_text' => '' ),
			)
		);
	}

	private function confirmed( array $service ): array {
		$booking = $this->book( $service, self::NOV20 )['booking'];
		$up      = Proofs::upload( $booking['id'], $this->fakeUpload( 'png' ), false );
		return Bookings::approvePayment( $booking['id'], array( 'proof_id' => $up['proof_id'], 'amount_cents' => 50000, 'received_date' => '2026-10-21' ) );
	}

	private function events( int $bookingId, string $event ): array {
		return array_values(
			array_filter(
				Notifications::forBooking( $bookingId ),
				static function ( $n ) use ( $event ) {
					return $event === $n['event'] && 'email' === $n['channel'];
				}
			)
		);
	}

	public function test_mexican_holidays_and_business_days(): void {
		$h = BusinessDays::holidays( 2026 );
		foreach ( array( '2026-01-01', '2026-02-02', '2026-03-16', '2026-04-03', '2026-05-01', '2026-09-16', '2026-11-16', '2026-12-25' ) as $day ) {
			$this->assertArrayHasKey( $day, $h, $day );
		}
		$this->assertArrayNotHasKey( '2026-10-01', $h );
		$this->assertArrayHasKey( '2030-10-01', BusinessDays::holidays( 2030 ), 'Cambio de gobierno cada seis años.' );
		$this->assertSame( '2027-03-26', BusinessDays::goodFriday( 2027 ) );
		// Sesión el viernes 13: se cuenta desde el lunes 16, que es festivo.
		$this->assertSame( '2026-11-23', BusinessDays::after( new \DateTimeImmutable( '2026-11-13' ), 5 )->format( 'Y-m-d' ) );
	}

	public function test_client_with_one_session_is_recognized_but_frequent_price_needs_three(): void {
		Clients::create( array( 'name' => 'Rosa María López', 'email' => 'rosa@example.com', 'historic_sessions' => 1 ) );
		$r = FrequentCheck::identify( array( 'name' => 'Rosa López', 'email' => 'rosa@example.com' ) );
		$this->assertTrue( $r['recognized'] );
		$this->assertFalse( $r['frequent'], 'Con 1 sesión no hay precio de frecuente (umbral 3).' );
		$this->assertArrayNotHasKey( 'token', $r );
		$this->assertSame( '¡Qué gusto verte de nuevo, Rosa! Ya tenemos tus datos, así que puedes continuar con tu reserva.', $r['message'] );

		Clients::create( array( 'name' => 'Laura Pérez', 'email' => 'laura@example.com', 'historic_sessions' => 3 ) );
		$f = FrequentCheck::identify( array( 'name' => 'Laura Pérez', 'email' => 'laura@example.com' ) );
		$this->assertTrue( $f['frequent'] );
		$this->assertStringStartsWith( '¡Qué gusto verte de nuevo, Laura!', $f['message'] );

		$none = FrequentCheck::identify( array( 'name' => 'Nadie Conocido', 'email' => 'nadie@example.com' ) );
		$this->assertFalse( $none['recognized'] );
		$this->assertSame( FrequentCheck::NOT_CONFIRMED, $none['message'] );
		Clients::create( array( 'name' => 'Sin Sesiones', 'email' => 'cero@example.com', 'historic_sessions' => 0 ) );
		$this->assertFalse( FrequentCheck::identify( array( 'name' => 'Sin Sesiones', 'email' => 'cero@example.com' ) )['recognized'], 'Sin sesiones registradas no se reconoce.' );
	}

	public function test_presale_banner_text(): void {
		$service = $this->christmasTableService();
		$txt     = Catalog::availability( $service, '2026-11-01', '2026-11-30' )['presale_txt'];
		$this->assertStringStartsWith( '✨ Estás reservando con precio de preventa, válido hasta el ', $txt );
	}

	public function test_session_completed_automatically_15_minutes_after_it_ends(): void {
		$booking = $this->confirmed( $this->deliveryService() );
		$end     = $booking['end'];
		$this->clock->set( $end->modify( '+10 minutes' ) );
		$this->assertSame( 0, Delivery::completeDue() );
		$this->clock->set( $end->modify( '+15 minutes' ) );
		$this->assertSame( 1, Delivery::completeDue() );
		$booking = Bookings::get( $booking['id'] );
		$this->assertSame( BookingStatus::COMPLETED, $booking['status'] );
		$this->assertSame( 1, (int) Clients::find( (int) $booking['client_id'] )['completed_sessions'], 'Suma la sesión a su expediente.' );

		$sent = $this->events( $booking['id'], 'session_completed' );
		$this->assertCount( 1, $sent );
		$text = json_decode( $sent[0]['payload'], true )['text'];
		// Sesión el viernes 20: 7 días hábiles (lunes 23 … martes 1 de diciembre).
		$this->assertStringContainsString( 'a más tardar el martes 1 de diciembre de 2026', $text );
		$this->assertStringContainsString( 'empiezan a correr cuando tu sesión esté liquidada', $text, 'Tiene saldo pendiente.' );
	}

	public function test_no_show_marked_before_is_not_completed(): void {
		$booking = $this->confirmed( $this->deliveryService() );
		Bookings::markNoShow( $booking['id'], (int) $booking['version'] );
		$this->clock->set( $booking['end']->modify( '+1 hour' ) );
		$this->assertSame( 0, Delivery::completeDue() );
		$this->assertCount( 0, $this->events( $booking['id'], 'session_completed' ) );
	}

	public function test_paying_after_the_session_sends_delivery_date_from_payment(): void {
		$booking = $this->confirmed( $this->deliveryService() );
		$this->clock->set( $booking['end']->modify( '+20 minutes' ) );
		Delivery::completeDue();
		$this->clock->set( new \DateTimeImmutable( '2026-11-25T18:00:00Z' ) ); // Miércoles 25.
		$balance = (int) Bookings::summary( Bookings::get( $booking['id'] ) )['balance_cents'];
		Bookings::approvePayment( $booking['id'], array( 'amount_cents' => $balance, 'received_date' => '2026-11-25', 'method' => 'cash', 'form_key' => wp_generate_uuid4() ) );
		$sent = $this->events( $booking['id'], 'session_paid_delivery' );
		$this->assertCount( 1, $sent );
		// Desde el miércoles 25: 7 días hábiles = viernes 4 de diciembre.
		$this->assertStringContainsString( 'a más tardar el viernes 4 de diciembre de 2026', json_decode( $sent[0]['payload'], true )['text'] );
	}

	public function test_portal_shows_range_and_client_adds_fast_delivery_until_end_of_session_day(): void {
		$booking = $this->confirmed( $this->deliveryService() );
		$view    = Portal::view( $booking );
		$this->assertSame( 'entre el viernes 27 de noviembre y el martes 1 de diciembre de 2026', $view['delivery']['range_txt'] );
		$this->assertFalse( $view['delivery']['paid'] );
		$this->assertNotNull( $view['delivery']['fast'] );
		$total = (int) $booking['total_cents'];

		$booking = Delivery::addFast( $booking['id'] );
		$this->assertSame( $total + 30000, (int) $booking['total_cents'] );
		$this->assertTrue( Delivery::hasFast( $booking ) );
		$view = Portal::view( $booking );
		$this->assertTrue( $view['delivery']['has_fast'] );
		$this->assertNull( $view['delivery']['fast'], 'Ya no se ofrece otra vez.' );
		$this->assertSame( 'entre el martes 24 y el miércoles 25 de noviembre de 2026', $view['delivery']['range_txt'] );
		$this->assertCount( 2, $this->events( $booking['id'], 'fast_delivery_added' ), 'Aviso a la clienta y a ti.' );
	}

	public function test_fast_delivery_closes_after_the_session_day(): void {
		$booking = $this->confirmed( $this->deliveryService() );
		$this->clock->set( new \DateTimeImmutable( '2026-11-21T08:00:00Z' ) ); // 01:00 del sábado 21 en Ciudad Juárez.
		$this->assertFalse( Delivery::canAddFast( $booking ) );
		$this->expectException( DomainException::class );
		Delivery::addFast( $booking['id'] );
	}
}
