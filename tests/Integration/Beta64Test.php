<?php
namespace MOBookingStudio\Tests\Integration;

use MOBookingStudio\Application\Notifications;
use MOBookingStudio\Infrastructure\OptionStore;
use MOBookingStudio\Infrastructure\Settings;
use MOBookingStudio\Infrastructure\Tokens;

/**
 * beta.6.4: párrafos del editor, plantillas nuevas de correo (WhatsApp sin cambios),
 * tarjeta en {{detalles}}, envío de prueba, botón solo con enlace vigente y logo de 150 px.
 */
final class Beta64Test extends IntegrationTestCase {
	private const NOV20 = '2026-11-20T17:00:00Z';

	public function test_editor_paragraphs_become_html_paragraphs(): void {
		// Así guarda el editor de WordPress: sin <p>, con renglones en blanco entre párrafos.
		$html = Notifications::renderHtml( "Hola, {{nombre}}:\n\n<strong>Importante:</strong> tu horario\nqueda apartado.", array( 'nombre' => 'Ana' ) );
		$this->assertStringContainsString( '<p>Hola, Ana:</p>', $html );
		$this->assertStringContainsString( '<p><strong>Importante:</strong> tu horario<br', $html );
	}

	public function test_upgrade_replaces_emails_and_keeps_whatsapp_exactly(): void {
		$booking = $this->book( $this->christmasService(), self::NOV20 )['booking'];
		// Plantilla guardada como en el sitio: correo con formato y WhatsApp vacío (usa el correo).
		$stored                     = Notifications::templates();
		$stored['booking_created']  = array_merge( $stored['booking_created'], array( 'body' => "Hola, {{nombre}}:\n\n<strong>Fecha:</strong> {{fecha}}\n\nMónica Orozco | Fotógrafa", 'whatsapp' => '', 'enabled' => false ) );
		$stored['booking_confirmed'] = array_merge( $stored['booking_confirmed'], array( 'whatsapp' => 'Mi WhatsApp propio {{nombre}}' ) );
		OptionStore::save( Notifications::TEMPLATES_OPTION, $stored );
		$whatsBefore = Notifications::whatsappText( $booking, 'booking_created' );

		Notifications::migrateEmailTemplates();
		$after = Notifications::templates();

		$this->assertSame( $whatsBefore, Notifications::whatsappText( $booking, 'booking_created' ), 'El WhatsApp sale igual que antes.' );
		$this->assertSame( 'Mi WhatsApp propio {{nombre}}', $after['booking_confirmed']['whatsapp'] );
		$this->assertSame( 'Recibimos tu solicitud de reserva 📋', $after['booking_created']['subject'] );
		$this->assertSame( 'Recibimos tu solicitud de reserva 📋', $after['booking_created']['title'] );
		$this->assertSame( '📅 Detalles de la solicitud', $after['booking_created']['card_title'] );
		$this->assertStringContainsString( '{{detalles}}', $after['booking_created']['body'] );
		$this->assertFalse( $after['booking_created']['enabled'], 'Se respeta si el aviso estaba apagado.' );
	}

	public function test_card_goes_where_detalles_is_and_details_are_not_repeated(): void {
		$tpl  = Notifications::templates()['booking_created'];
		$mail = Notifications::composeClientEmail( $tpl, Notifications::sampleVars(), 'pending_payment' );
		$html = $mail['html'];
		$card = strpos( $html, 'Detalles de la solicitud' );
		$this->assertNotFalse( $card );
		$this->assertLessThan( strpos( $html, 'Información de pago' ), $card, 'La tarjeta va antes del pago, donde está {{detalles}}.' );
		$this->assertSame( 1, substr_count( $html, 'Número de reserva:' ), 'Los detalles aparecen una sola vez.' );
		$this->assertStringNotContainsString( 'Ubicación:', $html, 'Sin ubicación antes de confirmar.' );
		$this->assertStringNotContainsString( '%%MO_DETALLES%%', $html . $mail['text'] );
		$this->assertStringContainsString( '🔖 Número de reserva: MO-AB12CD', $mail['text'], 'En texto simple, los detalles van en renglones.' );
		$confirmed = Notifications::composeClientEmail( Notifications::templates()['booking_confirmed'], Notifications::sampleVars(), 'confirmed' )['html'];
		$this->assertStringContainsString( 'Ubicación:', $confirmed );
	}

	public function test_test_email_is_really_sent_with_prueba_even_in_simulation(): void {
		Settings::update( array( 'email_mode' => 'simulate' ) );
		$sent    = array();
		$capture = static function ( $short, $atts ) use ( &$sent ) {
			$sent[] = $atts;
			return true;
		};
		add_filter( 'pre_wp_mail', $capture, 10, 2 );
		Notifications::sendTest( 'booking_confirmed', 'monica@example.com' );
		remove_filter( 'pre_wp_mail', $capture, 10 );
		$this->assertCount( 1, $sent );
		$this->assertSame( 'monica@example.com', $sent[0]['to'] );
		$this->assertStringStartsWith( '[PRUEBA] ', $sent[0]['subject'] );
		$this->assertStringContainsString( 'Consultar mi reserva', $sent[0]['message'] );
		$row = Notifications::recent( 1 )[0];
		$this->assertSame( 'accepted', $row['status'] );
		$this->assertTrue( json_decode( $row['payload'], true )['test'] );
	}

	public function test_button_only_while_private_link_is_valid(): void {
		$booking = $this->book( $this->christmasService(), self::NOV20 )['booking'];
		$this->assertTrue( Tokens::linkActive( $booking['id'] ) );
		Tokens::revokeAll( $booking['id'] );
		$this->assertFalse( Tokens::linkActive( $booking['id'] ) );
		$tpl = Notifications::templates()['booking_expired'];
		$this->assertStringNotContainsString( 'Consultar mi reserva', Notifications::composeClientEmail( $tpl, Notifications::sampleVars(), 'expired', false )['html'] );
		$this->assertStringContainsString( 'Consultar mi reserva', Notifications::composeClientEmail( $tpl, Notifications::sampleVars(), 'expired', true )['html'] );
	}

	public function test_logo_is_at_most_150_pixels(): void {
		Settings::update( array( 'email_logo_url' => 'https://example.com/logo.png' ) );
		$html = Notifications::composeClientEmail( Notifications::templates()['reminder'], Notifications::sampleVars(), 'confirmed' )['html'];
		Settings::update( array( 'email_logo_url' => '' ) );
		$this->assertStringContainsString( 'max-width:150px;max-height:150px', $html );
	}
}
