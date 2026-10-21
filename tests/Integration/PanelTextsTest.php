<?php
namespace MOBookingStudio\Tests\Integration;

use MOBookingStudio\Infrastructure\Settings;

/**
 * DEC-07 (aviso de zona horaria) y DEC-10 (textos de bienvenida editables).
 */
final class PanelTextsTest extends IntegrationTestCase {
	public function test_welcome_texts_from_shortcode(): void {
		$this->christmasService();
		if ( ! shortcode_exists( 'mo_booking' ) ) {
			( new \MOBookingStudio\Frontend\Frontend() )->shortcodes();
		}
		$html   = do_shortcode( '[mo_booking service="minis-navidad" welcome_title="Hola <b>familia</b>" welcome_new_text="" welcome_returning_text=""]' );
		$this->assertSame( 1, preg_match( '/data-mo-booking="([^"]+)"/', $html, $m ) );
		$config = json_decode( html_entity_decode( $m[1], ENT_QUOTES ), true );
		$this->assertSame( 'Hola familia', $config['welcome']['title'], 'Solo texto plano.' );
		$this->assertSame( '', $config['welcome']['new_text'], 'Vacío oculta el subtítulo.' );
		$this->assertSame( '', $config['welcome']['returning_text'] );
		$this->assertSame( 'Soy cliente nuevo', $config['welcome']['new_title'], 'Lo no indicado usa el texto por omisión.' );
	}

	public function test_wordpress_timezone_mismatch_warning(): void {
		$before = array( get_option( 'timezone_string' ), get_option( 'gmt_offset' ) );
		$now    = $this->clock->now();
		try {
			update_option( 'timezone_string', 'America/Denver' );
			$this->assertSame( '', Settings::wordpressZoneMismatch( $now ), 'Denver da la misma hora que Ciudad Juárez.' );
			update_option( 'timezone_string', 'America/Mexico_City' );
			$this->assertSame( 'America/Mexico_City', Settings::wordpressZoneMismatch( $now ) );
			update_option( 'timezone_string', '' );
			update_option( 'gmt_offset', -7 );
			$this->assertNotSame( '', Settings::wordpressZoneMismatch( $now ), 'Un desfase fijo no sigue el horario de verano.' );
		} finally {
			update_option( 'timezone_string', $before[0] );
			update_option( 'gmt_offset', $before[1] );
		}
	}
}
