<?php
namespace MOBookingStudio\Tests\Integration;

use MOBookingStudio\Application\Bookings;
use MOBookingStudio\Application\Clients;
use MOBookingStudio\Application\Contracts;
use MOBookingStudio\Application\Notifications;
use MOBookingStudio\Application\Portal;
use MOBookingStudio\Application\Proofs;
use MOBookingStudio\Application\Services;
use MOBookingStudio\Application\Trash;
use MOBookingStudio\Domain\BookingStatus;
use MOBookingStudio\Domain\DomainException;
use MOBookingStudio\Domain\Names;
use MOBookingStudio\Frontend\PrintView;
use MOBookingStudio\Infrastructure\Audit;
use MOBookingStudio\Infrastructure\PrivateStorage;
use MOBookingStudio\Infrastructure\Schema;
use MOBookingStudio\Infrastructure\Settings;

/**
 * beta.3: duplicados y fusión, papelera, eliminar movimientos, comprobantes desde el panel,
 * WhatsApp del negocio, página imprimible y plantillas de notificación.
 */
final class Beta3Test extends IntegrationTestCase {
	private const NOV20 = '2026-11-20T17:00:00Z';

	protected function setUp(): void {
		parent::setUp();
		delete_option( Clients::NOT_DUPLICATES_OPTION );
		delete_option( Notifications::CUSTOM_OPTION );
	}

	private function rows( string $table, string $where = '1=1' ): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( $table ) . ' WHERE ' . $where ); // phpcs:ignore
	}

	private function pay( array $booking, int $cents ): array {
		return Bookings::approvePayment( $booking['id'], array( 'amount_cents' => $cents, 'received_date' => '2026-10-20', 'method' => 'transfer', 'form_key' => wp_generate_uuid4() ) );
	}

	// ----- Nombres -----

	public function test_names_ignore_accents_and_minor_typos(): void {
		$this->assertTrue( Names::similar( 'María José Núñez', 'maria jose nunez' ) );
		$this->assertTrue( Names::similar( 'Angélica Falis', 'Angelica Faliz' ) );
		$this->assertFalse( Names::similar( 'Ana Pérez', 'Luis Gómez' ) );
		$this->assertTrue( Names::shareWord( 'Rosa Lopes', 'Rosa María López' ) );
		$this->assertFalse( Names::shareWord( 'Ana', 'Anabel Ruiz' ), 'Palabras cortas: sin tolerancia.' );
	}

	// ----- Duplicados, contactos y fusión -----

	public function test_duplicate_suggestions_and_dismiss(): void {
		$a = Clients::create( array( 'name' => 'María José Núñez', 'email' => 'mj@x.mx' ) );
		$b = Clients::create( array( 'name' => 'Maria Jose Nunez', 'email' => 'otra@x.mx' ) );
		$c = Clients::create( array( 'name' => 'Otra Persona', 'phone' => '656 111 2233' ) );
		$d = Clients::create( array( 'name' => 'Distinto Nombre', 'phone' => '6561112233' ) );
		$pairs = Clients::duplicateSuggestions();
		$this->assertCount( 2, $pairs );
		$reasons = array();
		foreach ( $pairs as $p ) {
			$reasons[ min( $p['a']['id'], $p['b']['id'] ) . '-' . max( $p['a']['id'], $p['b']['id'] ) ] = $p['reasons'];
		}
		$this->assertContains( 'Mismo nombre', $reasons[ "$a-$b" ], 'Sin acentos es el mismo nombre.' );
		$this->assertContains( 'Mismo teléfono', $reasons[ "$c-$d" ] );
		Clients::dismissDuplicate( $a, $b );
		$this->assertCount( 1, Clients::duplicateSuggestions() );
		$this->assertCount( 1, Clients::duplicateSuggestions( $c ) );
	}

	public function test_additional_contacts_are_used_to_match(): void {
		$id = Clients::create( array( 'name' => 'Laura Uno', 'email' => 'laura@x.mx' ) );
		$this->assertNotNull( Clients::addContact( $id, 'phone', '656 222 0000', 'MX', 'Trabajo' ) );
		$this->assertNull( Clients::addContact( $id, 'email', 'LAURA@x.mx' ), 'El principal no se repite.' );
		$this->assertSame( array( $id ), Clients::idsByPhone( '+526562220000' ) );
		$match = Clients::matchOrCreate( 'Laura Uno', null, '+526562220000', '6562220000', 'MX' );
		$this->assertSame( $id, $match['id'] );
		try {
			Clients::addContact( $id, 'phone', '123' );
			$this->fail( 'Teléfono inválido.' );
		} catch ( DomainException $e ) {
			$this->assertSame( 'invalid_phone', $e->errorCode() );
		}
	}

	public function test_merge_moves_bookings_contacts_and_trashes_duplicate(): void {
		$service   = $this->christmasService();
		$booking   = $this->book( $service, self::NOV20, array( 'name' => 'Ana Prueba', 'email' => 'ana2@example.com', 'phone' => '656 999 8888' ) )['booking'];
		$duplicate = $booking['client_id'];
		Clients::update( $duplicate, array( 'historic_sessions' => 2, 'notes' => 'Prefiere mañanas' ) );
		$principal = Clients::create( array( 'name' => 'Ana Prueba', 'email' => 'ana@example.com', 'historic_sessions' => 3 ) );

		$preview = Clients::previewMerge( $principal, $duplicate );
		$this->assertSame( 1, $preview['bookings'] );
		$this->assertSame( 3, $preview['historic'], 'Se toma el mayor, no la suma.' );
		Clients::merge( $principal, $duplicate );

		$this->assertSame( $principal, Bookings::get( $booking['id'] )['client_id'] );
		$dup = Clients::find( $duplicate );
		$this->assertNotNull( $dup['trashed_at'] );
		$this->assertSame( $principal, (int) $dup['merged_into'] );
		$values = array_column( Clients::contacts( $principal ), 'value_norm' );
		$this->assertContains( 'ana2@example.com', $values );
		$this->assertContains( '+526569998888', $values );
		$this->assertStringContainsString( 'Prefiere mañanas', (string) Clients::find( $principal )['notes'] );
		// El duplicado ya no se encuentra por su correo: ahora apunta al principal.
		$this->assertSame( array( $principal ), Clients::idsByEmail( 'ana2@example.com' ) );
		$this->assertSame( array(), Clients::search( 'ana2@' ), 'Fuera de la búsqueda (está en la papelera).' );
	}

	// ----- Papelera y eliminación definitiva -----

	public function test_client_trash_restore_and_active_booking_guard(): void {
		$service = $this->christmasService();
		$booking = $this->book( $service, self::NOV20 )['booking'];
		try {
			Trash::trashClient( $booking['client_id'] );
			$this->fail( 'Con reserva vigente no va a la papelera.' );
		} catch ( DomainException $e ) {
			$this->assertSame( 'has_active_bookings', $e->errorCode() );
		}
		$free = Clients::create( array( 'name' => 'Sin reservas', 'email' => 'libre@x.mx' ) );
		Trash::trashClient( $free );
		$this->assertSame( array(), Clients::idsByEmail( 'libre@x.mx' ), 'En la papelera no se usa para identificar.' );
		Trash::restoreClient( $free );
		$this->assertSame( array( $free ), Clients::idsByEmail( 'libre@x.mx' ) );
	}

	public function test_purge_client_with_history_needs_name_and_removes_everything(): void {
		$service = $this->christmasService();
		$booking = $this->book( $service, self::NOV20 )['booking'];
		Proofs::upload( $booking['id'], $this->fakeUpload( 'png' ), false );
		$this->pay( Bookings::get( $booking['id'] ), 50000 );
		$file = $this->firstProofPath( $booking['id'] );
		$this->assertFileExists( $file );

		try {
			Trash::purgeClient( $booking['client_id'], 'Otra persona' );
			$this->fail( 'Exige el nombre.' );
		} catch ( DomainException $e ) {
			$this->assertSame( 'confirm_required', $e->errorCode() );
		}
		$r = Trash::purgeClient( $booking['client_id'], '  ana   prueba ' );
		$this->assertSame( array( 'bookings' => 1, 'payments' => 1, 'proofs' => 1 ), $r );
		$this->assertNull( Clients::find( $booking['client_id'] ) );
		$this->assertNull( Bookings::find( $booking['id'] ) );
		foreach ( array( 'payments', 'proofs', 'occupancies', 'access_tokens', 'notifications' ) as $t ) {
			$this->assertSame( 0, $this->rows( $t, 'booking_id = ' . (int) $booking['id'] ), $t );
		}
		$this->assertFileDoesNotExist( $file );
		// La bitácora conserva un registro sin datos personales.
		$log = Audit::forEntity( 'client', $booking['client_id'] );
		$this->assertCount( 1, $log );
		$this->assertSame( 'purged', $log[0]['action'] );
		$this->assertStringNotContainsString( 'ana@example.com', (string) $log[0]['data'] );
		$this->assertStringNotContainsString( 'Ana', (string) $log[0]['data'] );
		$bookingLog = Audit::forEntity( 'booking', $booking['id'] );
		$this->assertSame( array( 'purged' ), array_column( $bookingLog, 'action' ) );
	}

	private function firstProofPath( int $bookingId ): string {
		return PrivateStorage::path( Bookings::proofs( $bookingId )[0]['stored_name'] );
	}

	public function test_booking_trash_only_when_closed_and_purge_from_trash(): void {
		$service = $this->christmasService();
		$booking = $this->book( $service, self::NOV20 )['booking'];
		try {
			Trash::trashBooking( $booking['id'] );
			$this->fail( 'Vigente: primero cancelar.' );
		} catch ( DomainException $e ) {
			$this->assertSame( 'booking_active', $e->errorCode() );
		}
		Bookings::cancel( $booking['id'], true, 'Prueba', Bookings::get( $booking['id'] )['version'], 'manual' );
		Trash::trashBooking( $booking['id'] );
		$this->assertSame( 0, Bookings::count( array() ) );
		$this->assertSame( 1, Bookings::count( array( 'trash' => true ) ) );
		Trash::restoreBooking( $booking['id'] );
		$this->assertSame( 1, Bookings::count( array() ) );
		try {
			Trash::purgeBooking( $booking['id'] );
			$this->fail( 'Solo desde la papelera.' );
		} catch ( DomainException $e ) {
			$this->assertSame( 'not_in_trash', $e->errorCode() );
		}
		Trash::trashBooking( $booking['id'] );
		Trash::purgeBooking( $booking['id'] );
		$this->assertNull( Bookings::find( $booking['id'] ) );
	}

	public function test_service_and_contract_trash(): void {
		$service = $this->christmasService();
		Trash::trashService( $service['id'] );
		$this->assertSame( 'trash', Services::get( $service['id'] )['status'] );
		$this->assertNotContains( $service['id'], array_column( Services::all(), 'id' ) );
		try {
			Services::findPublished( $service['slug'] );
			$this->fail( 'No se ofrece.' );
		} catch ( DomainException $e ) {
			$this->assertSame( 'service_unavailable', $e->errorCode() );
		}
		// El contrato sigue asignado (aunque el servicio esté en la papelera): no se puede tirar.
		try {
			Trash::trashContract( $this->contractId() );
			$this->fail( 'Asignado a un servicio.' );
		} catch ( DomainException $e ) {
			$this->assertSame( 'contract_in_use', $e->errorCode() );
		}
		Trash::restoreService( $service['id'] );
		$this->assertSame( 'inactive', Services::get( $service['id'] )['status'] );
		Trash::trashService( $service['id'] );
		Trash::purgeService( $service['id'] );
		$this->assertNull( Services::find( $service['id'] ) );

		$contract = $this->contractId();
		Trash::trashContract( $contract );
		$this->assertNull( Contracts::current( $contract ) );
		Trash::purgeContract( $contract );
		$this->assertNull( Contracts::find( $contract ) );
		$this->assertSame( 0, $this->rows( 'contract_versions', 'contract_id = ' . (int) $contract ) );
	}

	// ----- Movimientos -----

	public function test_delete_movement_requires_reason_recalculates_and_warns(): void {
		$service = $this->christmasService();
		$booking = $this->book( $service, self::NOV20 )['booking'];
		$after   = $this->pay( $booking, $booking['deposit_cents'] );
		$this->assertSame( BookingStatus::CONFIRMED, $after['status'] );
		$payment = Bookings::movements( $booking['id'] )[0];
		try {
			Trash::deleteMovement( $booking['id'], (int) $payment['id'], '' );
			$this->fail( 'Exige motivo.' );
		} catch ( DomainException $e ) {
			$this->assertSame( 'reason_required', $e->errorCode() );
		}
		$r = Trash::deleteMovement( $booking['id'], (int) $payment['id'], 'Lo registré en la reserva equivocada' );
		$this->assertSame( $booking['deposit_cents'], $r['amount'] );
		$this->assertSame( $booking['total_cents'], $r['after']['balance_cents'] );
		$this->assertStringContainsString( 'ya no tiene el anticipo cubierto', $r['warning'] );
		$this->assertSame( array(), Bookings::movements( $booking['id'] ) );
		$this->assertSame( BookingStatus::CONFIRMED, Bookings::get( $booking['id'] )['status'], 'El estado no cambia solo.' );
		$log = array_values( array_filter( Audit::forEntity( 'booking', $booking['id'] ), static function ( $a ) {
			return 'movement_deleted' === $a['action'];
		} ) );
		$data = json_decode( $log[0]['data'], true );
		$this->assertSame( 'Lo registré en la reserva equivocada', $data['reason'] );
		$this->assertSame( $booking['deposit_cents'], $data['amount'] );
		$this->assertNotSame( '', $data['by'] );
	}

	public function test_delete_movement_also_removes_its_reversal(): void {
		$service = $this->christmasService();
		$booking = $this->book( $service, self::NOV20 )['booking'];
		$this->pay( $booking, 20000 );
		$payment = Bookings::movements( $booking['id'] )[0];
		Bookings::addMovement( $booking['id'], 'reversal', 1, 'Error', wp_generate_uuid4(), (int) $payment['id'] );
		$this->assertCount( 2, Bookings::movements( $booking['id'] ) );
		$r = Trash::deleteMovement( $booking['id'], (int) $payment['id'], 'Limpieza' );
		$this->assertSame( 2, $r['removed'] );
		$this->assertSame( '', $r['warning'] );
	}

	// ----- Comprobantes -----

	public function test_admin_proof_upload_records_who_and_moves_to_review(): void {
		$service = $this->christmasService();
		$booking = $this->book( $service, self::NOV20 )['booking'];
		$r       = Proofs::uploadByAdmin( $booking['id'], $this->fakeUpload( 'png' ), 'Llegó por WhatsApp', false );
		$this->assertSame( BookingStatus::PAYMENT_REVIEW, $r['status'] );
		$proof = Bookings::proofs( $booking['id'] )[0];
		$this->assertSame( 'admin', $proof['source'] );
		$this->assertSame( 1, (int) $proof['uploaded_by'] );
		$this->assertSame( 'Llegó por WhatsApp', $proof['review_note'] );
		$this->assertNull( Bookings::get( $booking['id'] )['expires_at'], 'Ya no vence mientras se revisa.' );
		$this->expectException( DomainException::class );
		Proofs::uploadByAdmin( $booking['id'], $this->fakeUpload( 'svg' ), '', false );
	}

	public function test_whatsapp_button_to_business_when_uploads_disabled(): void {
		Settings::update( array( 'uploads_verified' => '', 'whatsapp_number' => '656 123 0000' ) );
		$service = $this->christmasService();
		$booking = $this->book( $service, self::NOV20 )['booking'];
		$view    = Portal::view( Bookings::get( $booking['id'] ) );
		$this->assertFalse( $view['can_upload'] );
		$this->assertStringStartsWith( 'https://wa.me/526561230000?text=', $view['proof_whatsapp_url'], 'Al número del negocio, no al de la clienta.' );
		$text = rawurldecode( substr( $view['proof_whatsapp_url'], strpos( $view['proof_whatsapp_url'], '=' ) + 1 ) );
		$this->assertSame( 'Hola! Hago el envío de mi comprobante de anticipo a nombre de Ana Prueba con número de reserva ' . $booking['public_ref'], $text );
		$this->assertSame( 'Da clic en este botón para enviar tu comprobante por WhatsApp', $view['proof_whatsapp_label'] );
		Settings::update( array( 'whatsapp_proof_message' => 'Comprobante de {nombre} ({referencia})' ) );
		$this->assertStringEndsWith( rawurlencode( 'Comprobante de Ana Prueba (' . $booking['public_ref'] . ')' ), Portal::view( Bookings::get( $booking['id'] ) )['proof_whatsapp_url'] );
	}

	// ----- Página imprimible -----

	public function test_print_view_shows_exact_contract_and_no_internal_notes(): void {
		$service = $this->christmasService();
		$booking = $this->book( $service, self::NOV20, array( 'important_info' => 'Viene la abuela' ) )['booking'];
		Bookings::addNote( $booking['id'], 'NOTA INTERNA SECRETA' );
		Contracts::save( $this->contractId(), 'Contrato de prueba', 'Contrato de minis', '<p>Versión DOS.</p>', true );
		$html = PrintView::document( Bookings::get( $booking['id'] ) );
		$this->assertStringContainsString( 'Acepto las condiciones de la sesión.', $html, 'La versión que aceptó.' );
		$this->assertStringNotContainsString( 'Versión DOS', $html );
		$this->assertStringContainsString( 'versión 1', $html );
		$this->assertStringContainsString( $booking['public_ref'], $html );
		$this->assertStringContainsString( 'Viene la abuela', $html );
		$this->assertStringNotContainsString( 'NOTA INTERNA SECRETA', $html );
		$this->assertStringContainsString( 'window.print()', $html );
	}

	// ----- Plantillas -----

	public function test_custom_templates_unknown_codes_and_original_text_kept(): void {
		$this->assertSame( array( '{{nombr}}', '{nombre}' ), Notifications::unknownCodes( 'Hola {{nombr}} y {nombre}, ref {{referencia}}' ) );
		$r = Notifications::saveCustom( null, array( 'name' => 'Bienvenida preventa', 'channel' => 'email', 'trigger' => 'booking_created', 'subject' => 'Hola {{nombre}}', 'body' => 'Gracias {{nombre}} por tu reserva {{referencia}} {{nombr}}', 'enabled' => 1 ) );
		$this->assertSame( array( '{{nombr}}' ), $r['unknown'] );

		$service = $this->christmasService();
		$booking = $this->book( $service, self::NOV20 )['booking'];
		$rows    = array_values( array_filter( Notifications::forBooking( $booking['id'] ), static function ( $n ) use ( $r ) {
			return 'custom_' . $r['id'] === $n['event'];
		} ) );
		$this->assertCount( 1, $rows, 'Se envía junto con el evento.' );
		$stored = Notifications::storedText( $rows[0] );
		$this->assertSame( 'Gracias Ana Prueba por tu reserva ' . $booking['public_ref'] . ' ', $stored['text'] );

		// Cambiar o quitar la plantilla no altera el texto guardado.
		Notifications::saveCustom( $r['id'], array( 'name' => 'Bienvenida preventa', 'channel' => 'email', 'trigger' => 'booking_created', 'subject' => 'Otro', 'body' => 'Texto nuevo', 'enabled' => 1 ) );
		Notifications::deleteCustom( $r['id'] );
		$again = Notifications::storedText( Notifications::forBooking( $booking['id'] )[ array_search( $rows[0]['id'], array_column( Notifications::forBooking( $booking['id'] ), 'id' ), true ) ] );
		$this->assertSame( $stored, $again );
		$this->assertSame( 'Plantilla propia (ya eliminada)', Notifications::eventLabel( 'custom_' . $r['id'] ) );
	}

	public function test_whatsapp_custom_template_and_system_reset(): void {
		$r       = Notifications::saveCustom( null, array( 'name' => 'Saldo', 'channel' => 'whatsapp', 'trigger' => 'booking_created', 'body' => 'Hola {{nombre}}, tu saldo es {{saldo}}', 'enabled' => 1 ) );
		$service = $this->christmasService();
		$booking = $this->book( $service, self::NOV20 )['booking'];
		$this->assertSame( 'manual', Notifications::customTemplates()[ $r['id'] ]['trigger'], 'WhatsApp siempre es manual.' );
		$this->assertTrue( Notifications::whatsappEventAllowed( 'custom_' . $r['id'] ) );
		$this->assertStringContainsString( 'Hola Ana Prueba, tu saldo es', Notifications::whatsappText( $booking, 'custom_' . $r['id'] ) );

		$unknown = Notifications::saveTemplates( array( 'reminder' => array( 'enabled' => 1, 'subject' => 'Recordatorio {{fehca}}', 'body' => 'x', 'admin' => '' ) ) );
		$this->assertSame( array( '{{fehca}}' ), $unknown );
		Notifications::saveTemplates( array( 'reminder' => array( 'enabled' => 1, 'reset' => 1 ) ) );
		$this->assertSame( Notifications::defaultTemplates()['reminder']['subject'], Notifications::templates()['reminder']['subject'] );
	}
}
