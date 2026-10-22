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
		$this->assertSame( Schema::VERSION, get_option( Schema::OPTION ) );
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

	/**
	 * AC-17: actualizar una base de 0.2.0-beta.1 (esquema 2) conserva clientes, reservas,
	 * precios congelados, pagos y reversos, comprobantes, configuración, eventos de Google,
	 * cola de sincronización y auditoría; las condiciones pasan a contrato v1 sin borrar nada.
	 */
	public function test_upgrade_from_beta1_preserves_everything(): void {
		global $wpdb;
		$service = $this->christmasService( array( 'contract_id' => 0, 'terms_text' => 'Llega 10 minutos antes.' ) );
		\MOBookingStudio\Infrastructure\Settings::update( array( 'terms_text' => 'Condiciones generales del estudio.' ) );
		$booking = $this->bookLegacy( $service );
		$up      = \MOBookingStudio\Application\Proofs::upload( $booking['id'], $this->fakeUpload( 'png' ), false );
		Bookings::approvePayment( $booking['id'], array( 'proof_id' => $up['proof_id'], 'amount_cents' => 50000, 'received_date' => '2026-10-20' ) );
		$payment = (int) $wpdb->get_var( 'SELECT id FROM ' . Schema::table( 'payments' ) . ' LIMIT 1' );
		Bookings::addMovement( $booking['id'], 'reversal', 1, 'Prueba de reverso', wp_generate_uuid4(), $payment );
		$wpdb->insert( Schema::table( 'calendar_events' ), array( 'booking_id' => $booking['id'], 'kind' => 'internal', 'calendar_id' => 'cal@x', 'event_id' => 'mobabc', 'state' => 'active', 'fingerprint' => 'f', 'created_at' => '2026-10-20 18:00:00' ) );
		$wpdb->insert( Schema::table( 'sync_jobs' ), array( 'booking_id' => $booking['id'], 'operation' => 'sync_booking', 'dedupe_key' => 'k1', 'status' => 'pending', 'revision' => 1, 'next_run_at' => '2026-10-20 18:00:00', 'created_at' => '2026-10-20 18:00:00', 'updated_at' => '2026-10-20 18:00:00' ) );

		$tables = array( 'clients', 'bookings', 'payments', 'proofs', 'calendar_events', 'sync_jobs', 'audit', 'services', 'policies', 'access_tokens' );
		$counts = array();
		foreach ( $tables as $t ) {
			$counts[ $t ] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( $t ) );
		}
		$auditMax = (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . Schema::table( 'audit' ) );
		$frozen   = $wpdb->get_row( $wpdb->prepare( 'SELECT total_cents, deposit_cents, quote, policy, contact FROM ' . Schema::table( 'bookings' ) . ' WHERE id = %d', $booking['id'] ), ARRAY_A );
		$settings = get_option( \MOBookingStudio\Infrastructure\Settings::OPTION );

		update_option( Schema::OPTION, '2' );
		update_option( Migrations::DONE_OPTION, array( '2_pending_approval_to_confirmed', '2_deadline_at', '2_proof_versions', '2_correction_hours', '2_token_key_source' ) );
		delete_option( Migrations::BACKUP_OPTION );
		Installer::upgrade();
		$this->assertTrue( Plugin::maintenance(), 'beta.1 → beta.2 también pide respaldo.' );
		Migrations::confirmBackup();
		Installer::upgrade();
		Installer::upgrade();
		$this->assertFalse( Plugin::maintenance() );
		$this->assertSame( '3', get_option( Schema::OPTION ) );

		foreach ( $tables as $t ) {
			// La auditoría solo crece: se comparan las filas que ya existían.
			$where = 'audit' === $t ? ' WHERE id <= ' . $auditMax : '';
			$this->assertSame( $counts[ $t ], (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( $t ) . $where ), 'Tabla ' . $t );
		}
		$after = $wpdb->get_row( $wpdb->prepare( 'SELECT total_cents, deposit_cents, quote, policy, contact FROM ' . Schema::table( 'bookings' ) . ' WHERE id = %d', $booking['id'] ), ARRAY_A );
		$this->assertSame( $frozen, $after, 'Precio, cotización, política y contacto congelados intactos.' );
		$this->assertSame( 'mobabc', $wpdb->get_var( 'SELECT event_id FROM ' . Schema::table( 'calendar_events' ) ) );
		$this->assertSame( 'pending', $wpdb->get_var( 'SELECT status FROM ' . Schema::table( 'sync_jobs' ) ) );
		foreach ( (array) $settings as $key => $value ) {
			$this->assertSame( $value, get_option( \MOBookingStudio\Infrastructure\Settings::OPTION )[ $key ], 'Ajuste ' . $key );
		}

		// Las condiciones se convirtieron en contrato v1 y el texto anterior sigue ahí.
		$migrated = \MOBookingStudio\Application\Services::get( $service['id'] );
		$this->assertNotEmpty( $migrated['contract_id'] );
		$this->assertSame( 'Llega 10 minutos antes.', $migrated['terms_text'] );
		$v1 = \MOBookingStudio\Application\Contracts::forService( $migrated );
		$this->assertSame( 1, (int) $v1['version'] );
		$this->assertStringContainsString( 'Llega 10 minutos antes.', $v1['body'] );
		$this->assertStringContainsString( 'Condiciones generales del estudio.', $v1['body'] );
		// La reserva anterior no recibe un contrato inventado.
		$this->assertNull( Bookings::get( $booking['id'] )['contract_version'] );
		// Repetir no duplica contratos.
		delete_option( Migrations::DONE_OPTION );
		Migrations::run();
		$this->assertSame( '1', $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'contracts' ) . " WHERE name LIKE 'Contrato – %'" ) );
	}

	/** Reserva como la creaba beta.1: sin contrato (el servicio aún no tenía plantilla). */
	private function bookLegacy( array $service ): array {
		$quote = \MOBookingStudio\Application\Catalog::quote( $service, array( 'start' => '2026-11-20T17:00:00Z', 'participants' => 6 ) );
		return Bookings::create( $service, array_merge( $this->contact(), array( 'quote_id' => $quote['quote_id'], 'idempotency_key' => wp_generate_uuid4(), 'start' => '2026-11-20T17:00:00Z', 'participants' => 6 ) ), array( 'source' => 'admin' ) )['booking'];
	}
}
