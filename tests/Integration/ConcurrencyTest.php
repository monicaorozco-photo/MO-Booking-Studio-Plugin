<?php
namespace MOBookingStudio\Tests\Integration;

use MOBookingStudio\Application\Catalog;
use MOBookingStudio\Infrastructure\Schema;

/**
 * AC08: dos solicitudes simultáneas para el mismo recurso y tiempo. Se ejecutan en dos
 * procesos PHP separados contra la misma base de datos.
 */
final class ConcurrencyTest extends IntegrationTestCase {
	private function race( array $service, array $starts ): array {
		$procs = array();
		foreach ( $starts as $i => $start ) {
			$quote = Catalog::quote( $service, array( 'start' => $start, 'participants' => 6 ) );
			$args  = array(
				'service_id' => $service['id'],
				'input'      => array_merge(
					$this->contact( array( 'email' => "persona$i@example.com", 'phone' => '656 100 000' . $i ) ),
					array( 'quote_id' => $quote['quote_id'], 'idempotency_key' => wp_generate_uuid4() )
				),
			);
			$env   = array_merge( getenv(), array( 'MO_TEST_NOW' => $this->clock->now()->format( 'c' ), 'MO_TEST_DELAY_US' => '700000' ) );
			$cmd   = array( PHP_BINARY, __DIR__ . '/concurrency-worker.php', json_encode( $args ) );
			$procs[] = proc_open( $cmd, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, null, $env );
			$outs[]  = $pipes;
		}
		$results = array();
		foreach ( $procs as $i => $p ) {
			$out = stream_get_contents( $outs[ $i ][1] );
			$err = stream_get_contents( $outs[ $i ][2] );
			proc_close( $p );
			$decoded = json_decode( trim( substr( $out, (int) strrpos( $out, '{' ) ) ), true );
			$this->assertIsArray( $decoded, "Salida inesperada del proceso: $out $err" );
			$results[] = $decoded;
		}
		return $results;
	}

	public function test_two_simultaneous_requests_only_one_wins(): void {
		$service = $this->christmasService();
		$results = $this->race( $service, array( '2026-11-20T17:00:00Z', '2026-11-20T17:00:00Z' ) );
		$ok      = array_filter( $results, static function ( $r ) { return $r['ok']; } );
		$failed  = array_filter( $results, static function ( $r ) { return ! $r['ok']; } );
		$this->assertCount( 1, $ok, json_encode( $results ) );
		$this->assertCount( 1, $failed );
		$this->assertSame( 'slot_unavailable', array_values( $failed )[0]['code'] );
		global $wpdb;
		$this->assertSame( '1', $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'occupancies' ) . ' WHERE released_at IS NULL' ) );
	}

	public function test_different_slots_both_succeed(): void {
		$service = $this->christmasService();
		$results = $this->race( $service, array( '2026-11-20T17:00:00Z', '2026-11-20T17:30:00Z' ) );
		$this->assertTrue( $results[0]['ok'] && $results[1]['ok'], json_encode( $results ) );
	}
}
