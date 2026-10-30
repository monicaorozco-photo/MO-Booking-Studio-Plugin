<?php
namespace MOBookingStudio\Tests\Integration;

use MOBookingStudio\Application\Adjustments;
use MOBookingStudio\Application\Bookings;
use MOBookingStudio\Application\Catalog;
use MOBookingStudio\Application\Notifications;
use MOBookingStudio\Application\Portal;
use MOBookingStudio\Application\Proofs;
use MOBookingStudio\Application\Services;
use MOBookingStudio\Domain\BookingStatus;
use MOBookingStudio\Domain\DomainException;

/**
 * beta.6.5: descuentos y cargos extra, cortesía, comprobantes del saldo, registro de
 * mensajes, página de reserva, texto de información importante, guía de vestimenta y
 * reactivar vencidas desde el panel.
 */
final class Beta65Test extends IntegrationTestCase {
	private const NOV20 = '2026-11-20T17:00:00Z';

	private function confirmed( array $service, int $paid = 50000 ): array {
		$booking = $this->book( $service, self::NOV20 )['booking'];
		$up      = Proofs::upload( $booking['id'], $this->fakeUpload( 'png' ), false );
		return Bookings::approvePayment( $booking['id'], array( 'proof_id' => $up['proof_id'], 'amount_cents' => $paid, 'received_date' => '2026-10-21' ) );
	}

	public function test_discounts_and_charges_change_total_and_balance_not_deposit(): void {
		$booking = $this->book( $this->christmasService(), self::NOV20 )['booking'];
		$total   = (int) $booking['total_cents'];
		$deposit = (int) $booking['deposit_cents'];
		$booking = Adjustments::add( $booking['id'], array( 'kind' => 'charge', 'label' => 'Impresión 8×10', 'value' => '150' ), (int) $booking['version'] );
		$booking = Adjustments::add( $booking['id'], array( 'kind' => 'discount', 'label' => 'Descuento especial', 'mode' => 'percent', 'value' => '10' ), (int) $booking['version'] );
		$this->assertSame( $total + 15000 - (int) round( ( $total + 15000 ) * 0.10 ), (int) $booking['total_cents'] );
		$this->assertSame( $deposit, (int) $booking['deposit_cents'], 'El anticipo no cambia.' );
		$rows = array_column( Portal::view( $booking )['price_rows'], 'amount_txt', 'label' );
		$this->assertSame( '+$150 MXN', $rows['Impresión 8×10'] );
		$this->assertArrayHasKey( 'Descuento especial', $rows );

		$charge  = Adjustments::rows( $booking['quote'] )[0];
		$booking = Adjustments::remove( $booking['id'], $charge['id'], (int) $booking['version'] );
		$this->assertSame( $total - (int) round( $total * 0.10 ), (int) $booking['total_cents'], 'Al quitar el cargo, el % se recalcula.' );
	}

	public function test_discount_cannot_go_below_what_was_paid_and_survives_edits(): void {
		$booking = $this->confirmed( $this->christmasService() );
		try {
			Adjustments::add( $booking['id'], array( 'kind' => 'discount', 'label' => 'Demasiado', 'value' => (string) ( ( (int) $booking['total_cents'] ) / 100 ) ), (int) $booking['version'] );
			$this->fail( 'Un descuento no puede dejar el total debajo de lo pagado.' );
		} catch ( DomainException $e ) {
			$this->assertSame( 'below_paid', $e->errorCode() );
		}
		$booking = Adjustments::add( $booking['id'], array( 'kind' => 'discount', 'label' => 'Cortesía de impresión', 'value' => '100' ), (int) $booking['version'] );
		$before  = (int) $booking['total_cents'];
		$booking = Bookings::applyEdit( $booking['id'], array( 'name' => 'Ana María López' ), (int) $booking['version'] );
		$this->assertSame( $before, (int) $booking['total_cents'] );
	}

	public function test_courtesy_blocks_adjustments(): void {
		$booking = $this->book( $this->christmasService(), self::NOV20 )['booking'];
		$booking = Bookings::markCourtesy( $booking['id'], 'familia', (int) $booking['version'] );
		$this->expectException( DomainException::class );
		Adjustments::add( $booking['id'], array( 'kind' => 'charge', 'label' => 'X', 'value' => '100' ), (int) $booking['version'] );
	}

	public function test_confirmed_booking_with_balance_accepts_more_proofs_until_paid(): void {
		$booking = $this->confirmed( $this->christmasService() );
		$this->assertSame( BookingStatus::CONFIRMED, $booking['status'] );
		$this->assertTrue( Portal::view( $booking )['can_upload'], 'Con saldo, puede subir el comprobante del abono.' );
		$up      = Proofs::upload( $booking['id'], $this->fakeUpload( 'pdf' ), false );
		$this->assertSame( BookingStatus::CONFIRMED, $up['status'], 'La cita sigue confirmada.' );
		$balance = (int) Bookings::summary( Bookings::get( $booking['id'] ) )['balance_cents'];
		$booking = Bookings::approvePayment( $booking['id'], array( 'proof_id' => $up['proof_id'], 'amount_cents' => $balance, 'received_date' => '2026-10-22' ) );
		$view    = Portal::view( $booking );
		$this->assertFalse( $view['can_upload'], 'Liquidada: ya no recibe comprobantes.' );
		$this->assertFalse( $view['balance_due'] );
		$this->assertSame( '', $view['bank'] );
	}

	public function test_portal_shows_client_data_and_whatsapp_buttons(): void {
		\MOBookingStudio\Infrastructure\Settings::update( array( 'whatsapp_number' => '6561234567' ) );
		$view = Portal::view( $this->book( $this->christmasService(), self::NOV20 )['booking'] );
		$this->assertSame( 'ana@example.com', $view['email'] );
		$this->assertNotSame( '', $view['phone'] );
		$this->assertStringContainsString( 'cancelar', urldecode( (string) $view['cancel_url'] ) );
		$this->assertStringContainsString( 'corregir mis datos', urldecode( (string) $view['fix_data_url'] ) );
	}

	public function test_important_info_texts_are_editable(): void {
		\MOBookingStudio\Infrastructure\Settings::update( array( 'important_info_placeholder' => 'Ej.: alergias, edades' ) );
		$texts = Catalog::publicService( $this->christmasService() )['texts'];
		$this->assertSame( 'Ej.: alergias, edades', $texts['important_example'] );
		$this->assertStringContainsString( 'información importante', $texts['important_label'] );
	}

	public function test_dress_guide_in_confirmed_email_and_portal(): void {
		\MOBookingStudio\Infrastructure\Settings::update( array( 'email_mode' => 'live' ) );
		$sent    = array();
		$capture = static function ( $short, $atts ) use ( &$sent ) {
			$sent[] = $atts;
			return true;
		};
		add_filter( 'pre_wp_mail', $capture, 10, 2 );
		$booking = $this->confirmed( $this->christmasService( array( 'dress_guide_url' => 'https://example.com/guia.pdf' ) ) );
		remove_filter( 'pre_wp_mail', $capture, 10 );
		\MOBookingStudio\Infrastructure\Settings::update( array( 'email_mode' => 'simulate' ) );
		$confirmed = array_values(
			array_filter(
				$sent,
				static function ( $m ) {
					return false !== strpos( (string) $m['subject'], 'confirmada' );
				}
			)
		);
		$this->assertNotEmpty( $confirmed );
		$this->assertStringContainsString( 'https://example.com/guia.pdf', $confirmed[0]['message'] );
		$this->assertStringContainsString( 'Descargar guía de vestimenta', $confirmed[0]['message'] );
		$this->assertSame( 'https://example.com/guia.pdf', Portal::view( $booking )['dress_guide_url'] );
	}

	public function test_admin_reactivates_expired_booking_with_updated_price(): void {
		$service = $this->christmasService();
		$booking = $this->book( $service, self::NOV20 )['booking'];
		$this->clock->advance( '+3 days' );
		Bookings::expireDue();
		$booking = Bookings::get( $booking['id'] );
		$this->assertSame( BookingStatus::EXPIRED, $booking['status'] );
		$this->assertNotNull( Catalog::findAvailableSlot( $service, new \DateTimeImmutable( self::NOV20 ), $this->clock->now() ) );

		$booking = Bookings::reactivate( $booking['id'], true, (int) $booking['version'] );
		$this->assertSame( BookingStatus::PENDING_PAYMENT, $booking['status'] );
		$this->assertNotNull( $booking['expires'] );
		$this->assertNull( Catalog::findAvailableSlot( $service, new \DateTimeImmutable( self::NOV20 ), $this->clock->now() ), 'El horario vuelve a estar apartado.' );
		$created = array_filter(
			Notifications::forBooking( $booking['id'] ),
			static function ( $n ) {
				return 'booking_created' === $n['event'] && 'email' === $n['channel'];
			}
		);
		$this->assertGreaterThanOrEqual( 3, count( $created ), 'Con la casilla, la clienta recibe de nuevo "Solicitud recibida".' );
	}

	public function test_reactivation_refused_when_slot_was_taken(): void {
		$service = $this->christmasService();
		$booking = $this->book( $service, self::NOV20 )['booking'];
		$this->clock->advance( '+3 days' );
		Bookings::expireDue();
		$this->book( $service, self::NOV20, array( 'email' => 'otra@example.com', 'phone' => '656 765 4321', 'name' => 'Otra Persona' ) );
		$preview = Bookings::previewReactivate( Bookings::get( $booking['id'] ) );
		$this->assertFalse( $preview['available'] );
		$this->expectException( DomainException::class );
		Bookings::reactivate( $booking['id'], false, (int) Bookings::get( $booking['id'] )['version'] );
	}

	public function test_message_log_tabs_order_and_pages(): void {
		$booking = $this->confirmed( $this->christmasService() );
		$method  = new \ReflectionMethod( \MOBookingStudio\Admin\Pages\BookingsPage::class, 'messagesBlock' );
		$method->setAccessible( true );
		$sent = $method->invoke( null, $booking, array( 'booking_id' => $booking['id'] ), array() );
		$this->assertStringContainsString( 'Enviados (', $sent );
		$this->assertStringContainsString( 'Programados (', $sent );
		$scheduled = $method->invoke( null, $booking, array( 'booking_id' => $booking['id'] ), array( 'mensajes' => 'scheduled' ) );
		$this->assertStringContainsString( 'Recordatorio de la cita', $scheduled, 'El recordatorio está programado.' );
		$this->assertLessThanOrEqual( 5, substr_count( $sent, '<tr><td>' ), 'Máximo 5 por página.' );
	}
}
