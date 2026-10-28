<?php
namespace MOBookingStudio\Tests\Integration;

use MOBookingStudio\Application\Bookings;
use MOBookingStudio\Application\Catalog;
use MOBookingStudio\Application\Notifications;
use MOBookingStudio\Application\Portal;
use MOBookingStudio\Application\Proofs;
use MOBookingStudio\Domain\DomainException;
use MOBookingStudio\Frontend\PrintView;
use MOBookingStudio\Infrastructure\Settings;

/**
 * beta.4: vista de reserva (contrato, comprobantes, pagos, ubicación solo con la cita
 * confirmada), casilla de avisos obligatoria, textos editables y correos con formato.
 */
final class Beta4Test extends IntegrationTestCase {
	private const NOV20 = '2026-11-20T17:00:00Z';

	private function located(): array {
		return $this->christmasService( array( 'location' => 'Estudio MO, Calle Secreta 7', 'instructions' => 'Trae ropa de colores.' ) );
	}

	private function pay( array $booking, int $cents ): array {
		return Bookings::approvePayment( $booking['id'], array( 'amount_cents' => $cents, 'received_date' => '2026-10-20', 'method' => 'transfer', 'form_key' => wp_generate_uuid4() ) );
	}

	public function test_location_and_instructions_only_after_confirmation(): void {
		$booking = $this->book( $this->located(), self::NOV20 )['booking'];
		$view    = Portal::view( $booking );
		$this->assertSame( '', $view['location'] );
		$this->assertSame( '', $view['instructions'] );
		$this->assertTrue( $view['location_later'] );
		$html = PrintView::document( $booking );
		$this->assertStringNotContainsString( 'Calle Secreta', $html );
		$this->assertStringNotContainsString( 'Trae ropa', $html );

		$confirmed = $this->pay( $booking, $booking['deposit_cents'] );
		$view      = Portal::view( $confirmed );
		$this->assertSame( 'Estudio MO, Calle Secreta 7', $view['location'] );
		$this->assertSame( 'Trae ropa de colores.', $view['instructions'] );
		$this->assertFalse( $view['location_later'] );
		$this->assertStringContainsString( 'Calle Secreta', PrintView::document( $confirmed ) );
	}

	public function test_portal_shows_contract_proofs_and_payments(): void {
		$booking = $this->book( $this->christmasService(), self::NOV20 )['booking'];
		Proofs::upload( $booking['id'], $this->fakeUpload( 'png' ), false );
		$after = $this->pay( Bookings::get( $booking['id'] ), 50000 );
		$view  = Portal::view( $after );
		$this->assertSame( 'Contrato de minis', $view['contract']['title'] );
		$this->assertStringContainsString( 'Acepto las condiciones', $view['contract']['body'] );
		$this->assertArrayNotHasKey( 'version', $view['contract'], 'La clienta no ve el número de versión.' );
		$this->assertCount( 1, $view['proofs'] );
		$this->assertStringContainsString( '/portal/proofs/' . $view['proofs'][0]['id'], $view['proofs'][0]['url'] );
		$this->assertSame( 'Imagen', $view['proofs'][0]['kind'] );
		$this->assertCount( 1, $view['payments'] );
		$this->assertSame( 'Transferencia', $view['payments'][0]['method'] );
		$this->assertStringContainsString( '500', $view['payments'][0]['amount_txt'] );
		$this->assertStringContainsString( 'Pagos recibidos', PrintView::document( $after ) );
	}

	public function test_whatsapp_consent_is_required_when_configured(): void {
		$service = $this->christmasService();
		$quote   = Catalog::quote( $service, array( 'start' => self::NOV20, 'participants' => 6 ) );
		try {
			Bookings::create( $service, array_merge( $this->contact( array( 'whatsapp_consent' => false ) ), array( 'quote_id' => $quote['quote_id'], 'idempotency_key' => wp_generate_uuid4() ) ) );
			$this->fail( 'Sin la casilla no se reserva.' );
		} catch ( DomainException $e ) {
			$this->assertSame( 'consent_required', $e->errorCode() );
			$this->assertSame( 'whatsapp_consent', $e->details()['field'] ?? null );
		}
		Settings::update( array( 'whatsapp_consent_required' => false ) );
		$r = Bookings::create( $service, array_merge( $this->contact( array( 'whatsapp_consent' => false ) ), array( 'quote_id' => $quote['quote_id'], 'idempotency_key' => wp_generate_uuid4() ) ) );
		$this->assertFalse( $r['booking']['consent']['whatsapp'] );
	}

	public function test_public_texts_and_taken_label(): void {
		Settings::update( array( 'slot_taken_label' => 'Ocupado', 'contract_notice' => 'Lee todo, por favor.' ) );
		$service = $this->christmasService();
		$public  = Catalog::publicService( $service );
		$this->assertSame( 'Ocupado', $public['texts']['slot_taken'] );
		$this->assertSame( 'Lee todo, por favor.', $public['texts']['contract_notice'] );
		$this->assertTrue( $public['texts']['consent_required'] );
		$this->book( $service, self::NOV20 );
		$day   = array_column( Catalog::availability( $service, '2026-11-01', '2026-11-30' )['days'], null, 'date' )['2026-11-20'];
		$taken = array_values( array_filter( $day['slots'], static function ( $s ) {
			return ! $s['available'] && 'Ocupado' === $s['label'];
		} ) );
		$this->assertNotEmpty( $taken, 'El horario apartado dice "Ocupado".' );
	}

	public function test_html_email_escapes_data_and_converts_for_whatsapp(): void {
		$vars = array( 'nombre' => '<b>Ana</b> & Co', 'enlace' => 'https://ejemplo.mx/r/#acceso=abc', 'referencia' => 'MO-1' );
		$html = Notifications::renderHtml( '<h2>Hola {{nombre}}</h2><p><strong>Tu reserva {{referencia}}</strong></p><p>{{enlace}}</p><script>x</script>', $vars );
		$this->assertStringContainsString( 'Hola Ana &amp; Co', $html, 'Los datos se limpian y escapan.' );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringContainsString( '<a href="https://ejemplo.mx/r/#acceso=abc">', $html );
		$text = Notifications::htmlToText( $html );
		$this->assertStringContainsString( 'Hola Ana & Co', $text );
		$this->assertStringContainsString( "MO-1\n\nhttps://ejemplo.mx/r/#acceso=abc", $text );
		$wa = Notifications::htmlToText( $html, true );
		$this->assertStringContainsString( '*Tu reserva MO-1*', $wa );
		// Plantilla de texto simple: conserva sus renglones en el correo.
		$this->assertStringContainsString( "Hola<br />\nAna", Notifications::renderHtml( "Hola\n{{nombre}}", array( 'nombre' => 'Ana' ) ) );
	}

	public function test_email_body_is_stored_with_html_and_whatsapp_text_per_event(): void {
		Notifications::saveTemplates(
			array(
				'booking_created' => array(
					'enabled'  => 1,
					'subject'  => 'Recibimos {{referencia}}',
					'body'     => '<p><strong>Hola {{nombre}}</strong></p><p>Tu enlace: {{enlace}}</p>',
					'admin'    => '',
					'whatsapp' => "Hola *{{nombre}}* 👋\nTu reserva {{referencia}}",
				),
			)
		);
		$booking = $this->book( $this->christmasService(), self::NOV20 )['booking'];
		$row     = array_values( array_filter( Notifications::forBooking( $booking['id'] ), static function ( $n ) {
			return 'booking_created' === $n['event'] && 'email' === $n['channel'];
		} ) )[0];
		$payload = json_decode( $row['payload'], true );
		$this->assertStringContainsString( '<strong>Hola Ana</strong>', $payload['html'] );
		$this->assertStringContainsString( 'Hola Ana', $payload['text'] );
		$this->assertStringNotContainsString( '<strong>', $payload['text'] );
		$this->assertSame( "Hola *Ana* 👋\nTu reserva " . $booking['public_ref'], Notifications::whatsappText( $booking, 'booking_created' ) );
		$url = Notifications::whatsappUrl( $booking, 'booking_created' );
		$this->assertStringContainsString( '%0A', $url, 'El salto de línea va codificado en el enlace.' );
		$this->assertStringContainsString( rawurlencode( '👋' ), $url );
		// beta.5: api.whatsapp.com conserva los emojis (wa.me los perdía en computadora).
		$this->assertStringStartsWith( 'https://api.whatsapp.com/send?phone=', $url );
		// Base de datos sin utf8mb4: el emoji se guarda como entidad y vuelve a ser emoji al enviar.
		$this->assertSame( "Hola 🎄\nAdiós", \MOBookingStudio\Domain\Contact::decodeEmoji( "Hola &#x1f384;\nAdiós" ) );
		$this->assertStringContainsString( rawurlencode( '🎄' ), \MOBookingStudio\Domain\Contact::whatsappLink( '526560000000', 'Hola &#x1f384;' ) );
		Settings::update( array( 'whatsapp_open' => 'web' ) );
		$this->assertStringStartsWith( 'https://web.whatsapp.com/send?phone=', Notifications::whatsappUrl( $booking, 'booking_created' ), 'Opción para abrir en WhatsApp Web (cuenta Business).' );
	}
}
