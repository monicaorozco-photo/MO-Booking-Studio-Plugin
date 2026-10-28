<?php
namespace MOBookingStudio\Tests\Integration;

use MOBookingStudio\Application\Catalog;
use MOBookingStudio\Application\Notifications;
use MOBookingStudio\Domain\DomainException;
use MOBookingStudio\Infrastructure\Settings;

/**
 * beta.6.3: máximo por adultos, mascotas como asistentes, todos los avisos en el WhatsApp
 * manual y correos con la identidad visual de la marca.
 */
final class Beta63Test extends IntegrationTestCase {
	private const NOV20 = '2026-11-20T17:00:00Z';

	public function test_max_counts_adults_only_and_pets_still_count_for_price(): void {
		$service = $this->christmasService( array( 'pets_allowed' => true, 'pets_count_as_participants' => true ) );
		// 10 adultos + 3 niños + 2 mascotas: caben (el límite es de adultos) y se cobran 15 asistentes.
		$q = Catalog::quote( $service, array( 'start' => self::NOV20, 'children' => 3, 'adults' => 10, 'pets' => 2 ) );
		$extra = array_values( array_filter( $q['lines'], static function ( $l ) { return 'participants' === ( $l['kind'] ?? '' ); } ) );
		$this->assertSame( '9 asistentes adicionales', $extra[0]['label'] ?? $extra[0]['label_txt'] ?? '' );
		try {
			Catalog::quote( $service, array( 'start' => self::NOV20, 'children' => 0, 'adults' => 11 ) );
			$this->fail( 'Once adultos superan el máximo de 10.' );
		} catch ( DomainException $e ) {
			$this->assertSame( 'too_many_participants', $e->errorCode() );
			$this->assertStringContainsString( '10 adultos', $e->getMessage() );
		}
	}

	public function test_public_service_tells_the_form_whether_pets_count(): void {
		$public = Catalog::publicService( $this->christmasService( array( 'pets_allowed' => true, 'pets_count_as_participants' => true ) ) );
		$this->assertTrue( $public['pets_count'] );
		$this->assertStringContainsString( 'espacio doble', $public['group_note'] );
	}

	public function test_manual_whatsapp_lists_every_client_notice(): void {
		$booking = $this->book( $this->christmasService(), self::NOV20 )['booking'];
		$method  = new \ReflectionMethod( \MOBookingStudio\Admin\Pages\BookingsPage::class, 'messagesBlock' );
		$method->setAccessible( true );
		$html = $method->invoke( null, $booking, array( 'booking_id' => $booking['id'] ) );
		foreach ( array( 'booking_expired', 'payment_approved', 'proof_received', 'booking_rejected', 'booking_confirmed', 'reminder' ) as $event ) {
			$this->assertStringContainsString( 'value="' . $event . '"', $html );
		}
		$this->assertStringNotContainsString( 'value="client_rescheduled"', $html, 'Los avisos solo para ti no se ofrecen.' );
		$this->assertStringContainsString( 'El plazo para pagar el anticipo', Notifications::whatsappText( $booking, 'booking_expired' ) );
	}

	public function test_manual_whatsapp_reuses_the_amount_of_the_last_notice(): void {
		$booking = $this->book( $this->christmasService(), self::NOV20 )['booking'];
		Notifications::bookingEvent( 'payment_approved', $booking, array( 'amount_cents' => 50000 ) );
		$this->assertStringContainsString( 'Verificamos tu pago de $500 MXN', Notifications::whatsappText( $booking, 'payment_approved' ) );
	}

	public function test_client_emails_use_the_brand_design(): void {
		Settings::update( array( 'email_mode' => 'live' ) );
		$sent    = array();
		$capture = static function ( $short, $atts ) use ( &$sent ) {
			$sent[] = $atts;
			return true;
		};
		add_filter( 'pre_wp_mail', $capture, 10, 2 );
		$booking = $this->book( $this->christmasService(), self::NOV20, array( 'is_test' => false ) )['booking'];
		Notifications::bookingEvent( 'booking_confirmed', array_merge( $booking, array( 'status' => 'confirmed', 'version' => 99 ) ) );
		remove_filter( 'pre_wp_mail', $capture, 10 );
		Settings::update( array( 'email_mode' => 'simulate' ) );

		$client = null;
		foreach ( $sent as $mail ) {
			if ( false !== strpos( (string) $mail['subject'], 'confirmada' ) ) {
				$client = $mail;
			}
		}
		$this->assertNotNull( $client, 'Se envió el correo de cita confirmada.' );
		$html = (string) $client['message'];
		$this->assertStringContainsString( '#63422B', $html, 'Botón y títulos en el marrón de la marca.' );
		$this->assertStringContainsString( '#FAF7F2', $html, 'Franja y tarjeta crema.' );
		// WordPress cambia los emojis por imágenes en los correos (con el emoji como texto alternativo).
		$this->assertMatchesRegularExpression( '/¡Tu cita está confirmada! (📅|<img[^>]+alt="📅")/u', $html );
		$this->assertStringContainsString( 'Detalles de tu cita', $html );
		$this->assertStringContainsString( 'Consultar mi reserva', $html );
		$this->assertStringContainsString( 'Este es un mensaje automático del sistema de reservas de', $html );
		$this->assertStringContainsString( 'max-width:600px', $html );
	}

	public function test_title_and_card_can_be_changed_or_turned_off(): void {
		$tpl                                 = Notifications::templates();
		$tpl['booking_confirmed']['title']   = 'Nos vemos pronto ✨';
		$tpl['booking_confirmed']['card']    = '';
		Notifications::saveTemplates( $tpl );
		$saved = Notifications::templates()['booking_confirmed'];
		$this->assertSame( 'Nos vemos pronto ✨', $saved['title'] );
		$this->assertFalse( $saved['card'] );
		$html = Notifications::designedEmail( '<p>Hola</p>', $saved, Notifications::sampleVars(), array( 'status' => 'confirmed' ) );
		$this->assertStringContainsString( 'Nos vemos pronto ✨', $html );
		$this->assertStringNotContainsString( 'Detalles de tu cita', $html );
	}
}
