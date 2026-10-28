<?php
namespace MOBookingStudio\Tests\Integration;

use MOBookingStudio\Application\Notifications;
use MOBookingStudio\Infrastructure\OptionStore;
use MOBookingStudio\Infrastructure\Settings;

/**
 * beta.6: avisos y ajustes a prueba de emojis, nombre corto en avisos, cortesía,
 * cambio de fecha por la clienta y controles de Elementor.
 */
final class Beta6Test extends IntegrationTestCase {
	private const NOV20 = '2026-11-20T17:00:00Z';

	public function test_templates_with_emojis_are_stored_safely_and_read_back(): void {
		$tpl                                 = Notifications::templates();
		$tpl['booking_confirmed']['subject'] = '¡Confirmada! 🎄';
		$tpl['booking_confirmed']['body']    = '<p>Hola {{nombre}} 📸</p>';
		$tpl['booking_confirmed']['whatsapp'] = "Hola ✨\nNos vemos";
		Notifications::saveTemplates( $tpl );
		$raw = get_option( Notifications::TEMPLATES_OPTION );
		$this->assertStringNotContainsString( '🎄', $raw['booking_confirmed']['subject'], 'En la base de datos va como entidad (la aceptan tablas utf8).' );
		$this->assertStringContainsString( '&#x1f384;', $raw['booking_confirmed']['subject'] );
		$back = Notifications::templates()['booking_confirmed'];
		$this->assertSame( '¡Confirmada! 🎄', $back['subject'] );
		$this->assertSame( "Hola ✨\nNos vemos", $back['whatsapp'] );
		$this->assertStringContainsString( '📸', $back['body'] );
	}

	public function test_settings_with_emojis_and_html_entities(): void {
		Settings::update( array( 'contract_notice' => 'Lee con calma 💛 &#60;no es etiqueta&#62;' ) );
		$this->assertSame( 'Lee con calma 💛 &#60;no es etiqueta&#62;', Settings::get( 'contract_notice' ), 'Solo se recuperan emojis; &#60; se queda igual.' );
		$this->assertSame( 'a 🎄 b', OptionStore::decodeText( 'a &#x1f384; b' ) );
		$this->assertSame( 'a 🎄 b', OptionStore::decodeText( 'a &#127876; b' ) );
	}

	public function test_nombre_is_first_name_and_full_name_available(): void {
		Notifications::saveTemplates(
			array(
				'booking_created' => array( 'enabled' => 1, 'subject' => 'Hola {{nombre}}', 'body' => '<p>Hola {{nombre}} ({{nombre_completo}})</p>', 'admin' => 'Nueva solicitud de {{nombre}}', 'whatsapp' => 'Hola {{nombre}}' ),
			)
		);
		$booking = $this->book( $this->christmasService(), self::NOV20, array( 'name' => 'María José González' ) )['booking'];
		$this->assertSame( 'Hola María', Notifications::whatsappText( $booking, 'booking_created' ) );
		$rows = Notifications::forBooking( $booking['id'] );
		$byAudience = array();
		foreach ( $rows as $n ) {
			if ( 'booking_created' === $n['event'] && 'email' === $n['channel'] ) {
				$p = json_decode( $n['payload'], true );
				$byAudience[ $p['audience'] ?? 'client' ] = $p;
			}
		}
		$this->assertStringContainsString( 'Hola María (María José González)', $byAudience['client']['text'] );
		$this->assertStringContainsString( 'Nueva solicitud de María José González', $byAudience['admin']['text'] ?? '', 'En tus avisos internos va el nombre completo.' );
		$this->assertSame( 'María', \MOBookingStudio\Domain\Names::first( '  María   José ' ) );
	}
}
