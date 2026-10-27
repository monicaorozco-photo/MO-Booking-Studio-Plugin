<?php
namespace MOBookingStudio\Tests\Integration;

use MOBookingStudio\Application\Catalog;
use MOBookingStudio\Application\Portal;
use MOBookingStudio\Frontend\PrintView;
use MOBookingStudio\Infrastructure\Settings;

/**
 * Rediseño: desglose del precio (precio de referencia tachado y descuentos). Solo cambia
 * cómo se muestra; el total es el mismo de siempre.
 */
final class RedesignTest extends IntegrationTestCase {
	private const NOV20 = '2026-11-20T17:00:00Z';

	public function test_quote_shows_reference_discounts_and_discounted_price(): void {
		$service = $this->christmasTableService();
		$quote   = Catalog::quote( $service, array( 'start' => self::NOV20, 'participants' => 7 ) );
		$this->assertSame(
			array(
				array( 'reference', 'Precio', '$1,800 MXN' ),
				array( 'discount', 'Descuento por fecha anticipada', '−$200 MXN' ),
				array( 'discount', 'Descuento por preventa', '−$200 MXN' ),
				array( 'discounted', 'Precio con descuento', '$1,400 MXN' ),
				array( 'participants', $quote['price_rows'][4]['label'], '$100 MXN' ),
			),
			array_map(
				static function ( $r ) {
					return array( $r['kind'], $r['label'], $r['amount_txt'] );
				},
				$quote['price_rows']
			)
		);
		$this->assertSame( 150000, $quote['total'], 'El total no cambia.' );

		$day = array_column( Catalog::availability( $service, '2026-11-01', '2026-11-30' )['days'], null, 'date' )['2026-11-20'];
		$this->assertSame( '$1,800 MXN', $day['reference_txt'] );
		$this->assertSame( '$1,400 MXN', $day['price_txt'] );
	}

	public function test_labels_are_editable_and_saved_booking_keeps_breakdown(): void {
		Settings::update( array( 'label_discount_date' => 'Ahorro por fecha' ) );
		$booking = $this->book( $this->christmasTableService(), self::NOV20 )['booking'];
		$rows    = Portal::view( $booking )['price_rows'];
		$this->assertSame( 'Ahorro por fecha', $rows[1]['label'] );
		$this->assertSame( '$1,400 MXN', $rows[3]['amount_txt'] );
		$html = PrintView::document( $booking );
		$this->assertStringContainsString( 'class="reference"', $html );
		$this->assertStringContainsString( 'Ahorro por fecha', $html );
	}

	public function test_services_without_table_keep_their_lines(): void {
		$quote = Catalog::quote( $this->christmasService(), array( 'start' => self::NOV20, 'participants' => 6 ) );
		$this->assertNotEmpty( $quote['price_rows'] );
		$this->assertNotContains( 'reference', array_column( $quote['price_rows'], 'kind' ) );
	}
}
