<?php
namespace MOBookingStudio\Tests\Integration;

use MOBookingStudio\Application\Bookings;
use MOBookingStudio\Infrastructure\Installer;
use MOBookingStudio\Infrastructure\Migrations;
use MOBookingStudio\Infrastructure\Schema;
use MOBookingStudio\Plugin;

/**
 * Ensayo de actualización desde 0.1.1 (esquema 1): espera respaldo, migra, se puede
 * repetir sin efectos dobles y no borra datos.
 */
final class MigrationTest extends IntegrationTestCase {
	private function simulateV1Install(): array {
		$service = $this->christmasService();
		$a       = $this->book( $service, '2026-11-20T17:00:00Z' )['booking'];
		$b       = $this->book( $service, '2026-11-20T17:30:00Z', array( 'email' => 'b@example.com' ) )['booking'];
		global $wpdb;
		$t = Schema::table( 'bookings' );
		// Estado que solo existía en 0.1.x y datos sin las columnas nuevas.
		$wpdb->query( $wpdb->prepare( "UPDATE $t SET status = 'pending_approval', expires_at = NULL, deadline_at = NULL WHERE id = %d", $b['id'] ) );
		$wpdb->query( $wpdb->prepare( "UPDATE $t SET deadline_at = NULL WHERE id = %d", $a['id'] ) );
		$wpdb->query( 'DELETE FROM ' . Schema::table( 'notifications' ) );
		update_option( Schema::OPTION, '1' );
		delete_option( Migrations::DONE_OPTION );
		delete_option( Migrations::BACKUP_OPTION );
		return array( $a, $b );
	}

	public function test_upgrade_waits_for_backup_then_migrates_once(): void {
		list( $a, $b ) = $this->simulateV1Install();
		global $wpdb;
		$before = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'bookings' ) );

		Installer::upgrade();
		$this->assertTrue( Plugin::maintenance(), 'Debe esperar la confirmación de respaldo.' );
		$this->assertSame( 'pending_approval', Bookings::get( $b['id'] )['status'], 'Nada se modifica antes del respaldo.' );
		$response = rest_do_request( new \WP_REST_Request( 'GET', '/mo-booking/v1/services' ) );
		$this->assertSame( 503, $response->get_status() );

		Migrations::confirmBackup();
		Installer::upgrade();
		$this->assertFalse( Plugin::maintenance() );
		$this->assertSame( '2', get_option( Schema::OPTION ) );
		$this->assertSame( 'confirmed', Bookings::get( $b['id'] )['status'] );
		$this->assertSame( Bookings::get( $a['id'] )['expires_at'], Bookings::get( $a['id'] )['deadline_at'] );
		$this->assertSame( '0', $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'notifications' ) ), 'La migración no envía avisos.' );

		// Repetir no cambia nada ni duplica.
		$version = Bookings::get( $b['id'] )['version'];
		delete_option( Migrations::DONE_OPTION );
		Migrations::run();
		Migrations::run();
		$this->assertSame( $version, Bookings::get( $b['id'] )['version'] );
		$this->assertSame( $before, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'bookings' ) ) );
		$this->assertSame( '1', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'audit' ) . " WHERE entity = 'booking' AND entity_id = %d AND action = 'status:confirmed'", $b['id'] ) ) );
	}

	public function test_fresh_install_does_not_wait(): void {
		delete_option( Schema::OPTION );
		delete_option( Migrations::BACKUP_OPTION );
		$this->assertFalse( Migrations::waitingForBackup() );
		Installer::upgrade();
		$this->assertSame( Schema::VERSION, get_option( Schema::OPTION ) );
	}
}
