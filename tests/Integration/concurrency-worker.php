<?php
/**
 * Proceso independiente para la prueba de concurrencia: intenta reservar con una
 * cotización dada. Retrasa la transacción entre la comprobación de disponibilidad y la inserción para que la
 * competencia sea real.
 */
putenv( 'MO_WP_INSTALLED=1' );
require dirname( __DIR__, 2 ) . '/vendor/autoload.php';
require __DIR__ . '/wp-bootstrap.php';

\MOBookingStudio\Plugin::setClock( \MOBookingStudio\Domain\FixedClock::at( getenv( 'MO_TEST_NOW' ) ) );
add_action( 'mo_booking_before_insert', static function () {
	usleep( (int) getenv( 'MO_TEST_DELAY_US' ) );
} );
$args    = json_decode( $argv[1], true );
$service = \MOBookingStudio\Application\Services::get( (int) $args['service_id'] );
try {
	$r = \MOBookingStudio\Application\Bookings::create( $service, $args['input'] );
	echo json_encode( array( 'ok' => true, 'id' => $r['booking']['id'] ) );
} catch ( \MOBookingStudio\Domain\DomainException $e ) {
	echo json_encode( array( 'ok' => false, 'code' => $e->errorCode() ) );
}
