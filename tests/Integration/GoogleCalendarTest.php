<?php
namespace MOBookingStudio\Tests\Integration;

use MOBookingStudio\Application\Bookings;
use MOBookingStudio\Application\Catalog;
use MOBookingStudio\Application\Proofs;
use MOBookingStudio\Application\Schedules;
use MOBookingStudio\Domain\BookingStatus;
use MOBookingStudio\Domain\DomainException;
use MOBookingStudio\Infrastructure\Schema;
use MOBookingStudio\Integrations\Google\Connection;
use MOBookingStudio\Integrations\Google\Conflicts;
use MOBookingStudio\Integrations\Google\EventBuilder;
use MOBookingStudio\Integrations\Google\SyncWorker;
use MOBookingStudio\Tests\Fixtures\FakeGoogle;

/**
 * Google Calendar con un Google simulado: AC-CAL-01 a 10 y DEC-06/08.
 * Las Minis del 20 de noviembre abren de 10:00 a 13:00 (17:00 a 20:00 UTC).
 */
final class GoogleCalendarTest extends IntegrationTestCase {
	private const START = '2026-11-20T17:00:00Z';

	/** @var FakeGoogle */
	private $google;

	protected function setUp(): void {
		parent::setUp();
		$this->google = FakeGoogle::install();
	}

	protected function tearDown(): void {
		$this->google->uninstall();
		parent::tearDown();
	}

	/** Un dominio que sí recibe correo (los de ejemplo no se invitan). */
	protected function contact( array $overrides = array() ): array {
		return parent::contact( array_merge( array( 'email' => 'ana@clientes-mo.mx' ), $overrides ) );
	}

	private function approve( array $booking ): array {
		$up = Proofs::upload( $booking['id'], $this->fakeUpload( 'png' ), false );
		return Bookings::approvePayment( $booking['id'], array( 'proof_id' => $up['proof_id'], 'amount_cents' => 50000, 'received_date' => '2026-10-20' ) );
	}

	private function slot( array $days, string $start ): array {
		foreach ( $days as $day ) {
			foreach ( $day['slots'] as $s ) {
				if ( $s['start'] === $start ) {
					return $s;
				}
			}
		}
		$this->fail( 'No existe el horario ' . $start );
	}

	/** DEC-08: sin conectar Google, la reserva funciona solo con la agenda interna. */
	public function test_without_google_bookings_use_internal_availability(): void {
		$service = $this->christmasService();
		$booking = $this->book( $service, self::START )['booking'];
		$this->assertSame( BookingStatus::PENDING_PAYMENT, $booking['status'] );
		$this->assertSame( array(), $this->google->requests, 'No se llama a Google.' );
		global $wpdb;
		$this->assertSame( '0', $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'sync_jobs' ) ) );
	}

	/** AC-CAL-01: un evento en cualquiera de los calendarios de lectura bloquea el horario. */
	public function test_events_in_any_read_calendar_block_slots(): void {
		$service = $this->christmasService();
		$this->google->connect();
		$this->google->busy[ FakeGoogle::BUSY_CAL ] = array( array( 'start' => '2026-11-20T17:00:00Z', 'end' => '2026-11-20T17:15:00Z' ) );
		$this->google->addExternal( FakeGoogle::DESTINATION, '2026-11-20T18:00:00Z', '2026-11-20T18:30:00Z' );
		// Transparente ("disponible") no bloquea.
		$this->google->addExternal( FakeGoogle::DESTINATION, '2026-11-20T19:00:00Z', '2026-11-20T19:30:00Z', array( 'transparency' => 'transparent' ) );

		$days = Catalog::availability( $service, '2026-11-20', '2026-11-20' )['days'];
		$this->assertFalse( $this->slot( $days, '2026-11-20T17:00:00Z' )['available'] );
		$this->assertTrue( $this->slot( $days, '2026-11-20T17:30:00Z' )['available'] );
		$this->assertFalse( $this->slot( $days, '2026-11-20T18:00:00Z' )['available'] );
		$this->assertTrue( $this->slot( $days, '2026-11-20T19:00:00Z' )['available'] );

		// Aunque la vista estuviera en caché, cotizar consulta de nuevo y rechaza.
		try {
			Catalog::quote( $service, array( 'start' => self::START, 'participants' => 6 ) );
			$this->fail( 'Debió rechazar el horario ocupado en Google.' );
		} catch ( DomainException $e ) {
			$this->assertSame( 409, $e->httpStatus() );
		}
	}

	/** Un evento de día completo ocupa todo el día local del negocio. */
	public function test_all_day_event_blocks_whole_local_day(): void {
		$service = $this->christmasService();
		$this->google->connect();
		$this->google->events[ FakeGoogle::DESTINATION ]['vac'] = array(
			'id'     => 'vac',
			'status' => 'confirmed',
			'start'  => array( 'date' => '2026-11-20' ),
			'end'    => array( 'date' => '2026-11-21' ),
		);
		$day = Catalog::availability( $service, '2026-11-20', '2026-11-20' )['days'][0];
		$this->assertSame( 0, $day['available'] );
	}

	/** El evento externo aparece entre la vista y el envío: la reserva se rechaza. */
	public function test_fresh_check_at_booking_time(): void {
		$service = $this->christmasService();
		$this->google->connect();
		$quote = Catalog::quote( $service, array( 'start' => self::START, 'participants' => 6 ) );
		$this->google->busy[ FakeGoogle::BUSY_CAL ] = array( array( 'start' => '2026-11-20T17:10:00Z', 'end' => '2026-11-20T17:20:00Z' ) );
		$this->expectException( DomainException::class );
		Bookings::create( $service, array_merge( $this->contact(), array( 'quote_id' => $quote['quote_id'], 'idempotency_key' => wp_generate_uuid4() ) ) );
	}

	/** AC-CAL-02: dos personas, mismo horario, con Google activo: solo una lo obtiene. */
	public function test_same_slot_only_once(): void {
		$service = $this->christmasService();
		$this->google->connect();
		$q1 = Catalog::quote( $service, array( 'start' => self::START, 'participants' => 6 ) );
		$q2 = Catalog::quote( $service, array( 'start' => self::START, 'participants' => 6 ) );
		Bookings::create( $service, array_merge( $this->contact(), array( 'quote_id' => $q1['quote_id'], 'idempotency_key' => wp_generate_uuid4() ) ) );
		SyncWorker::processQueue();
		try {
			Bookings::create( $service, array_merge( $this->contact( array( 'email' => 'otra@example.com' ) ), array( 'quote_id' => $q2['quote_id'], 'idempotency_key' => wp_generate_uuid4() ) ) );
			$this->fail( 'El segundo no debe obtener el horario.' );
		} catch ( DomainException $e ) {
			$this->assertSame( 409, $e->httpStatus() );
		}
		// El evento interno del plugin no bloquea a la propia reserva, pero el bloqueo interno sí a otras.
		$this->assertCount( 1, $this->google->owned( 'internal' ) );
	}

	/** AC-CAL-03: reserva pendiente = un evento interno "MO PENDIENTE", sin invitados ni invitación. */
	public function test_pending_booking_creates_single_internal_event(): void {
		$service = $this->christmasService();
		$this->google->connect();
		$booking = $this->book( $service, self::START )['booking'];
		global $wpdb;
		$this->assertSame( 'pending', $wpdb->get_var( 'SELECT sync_state FROM ' . Schema::table( 'bookings' ) . ' WHERE id = ' . (int) $booking['id'] ) );

		SyncWorker::processQueue();
		$internal = $this->google->owned( 'internal' );
		$this->assertCount( 1, $internal );
		$this->assertSame( 'MO PENDIENTE · ' . $booking['public_ref'], $internal[0]['summary'] );
		$this->assertSame( array(), $internal[0]['attendees'] );
		$this->assertSame( 'private', $internal[0]['visibility'] );
		$this->assertSame( 'opaque', $internal[0]['transparency'] );
		$this->assertSame( EventBuilder::eventId( $booking['id'], 'internal' ), $internal[0]['id'] );
		$this->assertCount( 0, $this->google->owned( 'client' ) );
		$this->assertSame( 0, $this->google->invitations );
		$this->assertSame( 'synced', $wpdb->get_var( 'SELECT sync_state FROM ' . Schema::table( 'bookings' ) . ' WHERE id = ' . (int) $booking['id'] ) );

		// Subir comprobante cambia solo el interno; tampoco invita.
		Proofs::upload( $booking['id'], $this->fakeUpload( 'png' ), false );
		SyncWorker::processQueue();
		$this->assertCount( 1, $this->google->owned( 'internal' ) );
		$this->assertSame( 0, $this->google->invitations );
	}

	/** AC-CAL-04 y 05: al aprobar, el interno pasa a CONFIRMADA y se crea un evento del cliente con una invitación. */
	public function test_approval_creates_client_event_with_one_invitation(): void {
		$service = $this->christmasService( array( 'location' => 'Estudio Clics, Av. Siempre Viva 123', 'instructions' => 'Llega 10 minutos antes.' ) );
		$this->google->connect();
		$booking = $this->book( $service, self::START, array( 'phone' => '656 999 8888' ) )['booking'];
		SyncWorker::processQueue();
		$this->approve( $booking );
		SyncWorker::processQueue();

		$internal = $this->google->owned( 'internal' );
		$client   = $this->google->owned( 'client' );
		$this->assertCount( 1, $internal );
		$this->assertCount( 1, $client );
		$this->assertSame( 'MO CONFIRMADA · ' . $booking['public_ref'], $internal[0]['summary'] );
		$this->assertSame( 1, $this->google->invitations );
		$this->assertSame( array( array( 'email' => 'ana@clientes-mo.mx' ) ), $client[0]['attendees'] );
		$this->assertSame( 'Sesión con Clics con Amor Fotografía', $client[0]['summary'] );
		$this->assertSame( 'transparent', $client[0]['transparency'] );
		$this->assertSame( 'Estudio Clics, Av. Siempre Viva 123', $client[0]['location'] );
		$this->assertStringContainsString( 'Llega 10 minutos antes.', $client[0]['description'] );

		// AC-CAL-05: el evento del cliente no expone datos internos.
		$public = wp_json_encode( $client[0] );
		foreach ( array( '656', '999', '500', '$', 'wp-admin', 'admin.php', 'acceso=', 'Pago', 'Teléfono' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $public, 'El evento del cliente no debe contener: ' . $secret );
		}
		$this->assertStringContainsString( '656 999 8888', $internal[0]['description'], 'El interno sí lleva el teléfono.' );
		$this->assertStringContainsString( 'Correo: ana@clientes-mo.mx', $internal[0]['description'] );
		$this->assertStringContainsString( 'Pago: Anticipo cubierto', $internal[0]['description'] );
		$this->assertStringContainsString( 'admin.php?page=mo-booking-bookings', $internal[0]['description'] );

		// AC-CAL-08: repetir no duplica eventos ni invitaciones y no llama a Google si nada cambió.
		$before = count( $this->google->requests );
		SyncWorker::syncBooking( $booking['id'] );
		$this->assertSame( $before, count( $this->google->requests ) );
		$this->assertSame( 1, $this->google->invitations );
	}

	/** AC-CAL-06: si la reserva vence sin pago, se borra el evento interno. */
	public function test_expiry_deletes_internal_event(): void {
		$service = $this->christmasService();
		$this->google->connect();
		$booking = $this->book( $service, self::START )['booking'];
		SyncWorker::processQueue();
		$this->assertCount( 1, $this->google->owned( 'internal' ) );

		$this->clock->advance( '+12 hours' );
		Bookings::expireDue();
		SyncWorker::processQueue();
		$this->assertSame( BookingStatus::EXPIRED, Bookings::get( $booking['id'] )['status'] );
		$this->assertCount( 0, $this->google->owned() );
		$this->assertSame( 0, $this->google->cancellations, 'Nunca hubo invitado: no se avisa a nadie.' );
	}

	/** Cancelar una confirmada borra ambos y Google avisa a la persona invitada. */
	public function test_cancel_confirmed_deletes_both_events(): void {
		$service = $this->christmasService();
		$this->google->connect();
		$booking = $this->approve( $this->book( $service, self::START )['booking'] );
		SyncWorker::processQueue();
		Bookings::cancel( $booking['id'], true, 'Enfermedad', Bookings::get( $booking['id'] )['version'] );
		SyncWorker::processQueue();
		$this->assertCount( 0, $this->google->owned() );
		$this->assertSame( 1, $this->google->cancellations );
		// Los eventos ajenos nunca se tocan.
		$this->assertSame( 0, $this->google->count( 'DELETE', '/events/ext' ) );
	}

	/** Reprogramar mueve los mismos eventos; no crea otros ni reenvía la invitación inicial. */
	public function test_reschedule_moves_same_events(): void {
		$service = $this->christmasService();
		$this->google->connect();
		$booking = $this->approve( $this->book( $service, self::START )['booking'] );
		SyncWorker::processQueue();
		Bookings::reschedule( $booking['id'], new \DateTimeImmutable( '2026-11-27T17:00:00Z' ), 'Pidió cambio', Bookings::get( $booking['id'] )['version'] );
		SyncWorker::processQueue();
		$client = $this->google->owned( 'client' );
		$this->assertCount( 1, $client );
		$this->assertCount( 1, $this->google->owned( 'internal' ) );
		$this->assertSame( '2026-11-27T10:00:00-07:00', $client[0]['start']['dateTime'] );
		$this->assertSame( 1, $this->google->invitations );
	}

	/** AC-CAL-07: un evento externo posterior se marca como empalme y no cancela nada. */
	public function test_later_conflict_is_flagged_not_cancelled(): void {
		$service = $this->christmasService();
		$this->google->connect();
		$booking = $this->approve( $this->book( $service, self::START )['booking'] );
		SyncWorker::processQueue();

		$this->google->busy[ FakeGoogle::BUSY_CAL ] = array( array( 'start' => '2026-11-20T17:10:00Z', 'end' => '2026-11-20T18:00:00Z' ) );
		$result = Conflicts::scan();
		$this->assertSame( 1, $result['new'] );
		$booking = Bookings::get( $booking['id'] );
		$this->assertSame( BookingStatus::CONFIRMED, $booking['status'] );
		global $wpdb;
		$this->assertSame( 'open', $wpdb->get_var( 'SELECT conflict_state FROM ' . Schema::table( 'bookings' ) . ' WHERE id = ' . (int) $booking['id'] ) );
		$this->assertSame( '1', $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'notifications' ) . " WHERE event = 'admin_message'" ) );

		// Repetir el escaneo no duplica el empalme ni el aviso.
		$this->assertSame( 0, Conflicts::scan()['new'] );
		$this->assertSame( '1', $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'conflicts' ) ) );

		// No se puede marcar resuelto mientras Google siga ocupado.
		$id = (int) $wpdb->get_var( 'SELECT id FROM ' . Schema::table( 'conflicts' ) );
		try {
			Conflicts::act( $id, 'resolve' );
			$this->fail( 'Debe seguir abierto.' );
		} catch ( DomainException $e ) {
			$this->assertSame( 'still_conflict', $e->errorCode() );
		}
		Conflicts::act( $id, 'keep', 'Lo atiendo yo' );
		$this->assertSame( 'none', $wpdb->get_var( 'SELECT conflict_state FROM ' . Schema::table( 'bookings' ) . ' WHERE id = ' . (int) $booking['id'] ) );
	}

	/** CAL-11: si alguien mueve o borra el evento en Google, se marca para decidir; restaurar lo recrea. */
	public function test_external_edit_is_flagged_and_restorable(): void {
		$service = $this->christmasService();
		$this->google->connect();
		$booking = $this->approve( $this->book( $service, self::START )['booking'] );
		SyncWorker::processQueue();
		$clientId = EventBuilder::eventId( $booking['id'], 'client' );
		$this->google->events[ FakeGoogle::DESTINATION ][ $clientId ]['start']['dateTime'] = '2026-11-20T11:00:00-07:00';
		$this->google->events[ FakeGoogle::DESTINATION ][ EventBuilder::eventId( $booking['id'], 'internal' ) ]['status'] = 'cancelled';

		$this->assertSame( 2, Conflicts::scan()['external'] );
		$changes = Conflicts::externalChanges();
		$this->assertEqualsCanonicalizing( array( 'moved', 'deleted' ), array_column( $changes, 'external_change' ) );
		$this->assertSame( BookingStatus::CONFIRMED, Bookings::get( $booking['id'] )['status'], 'Nada se importa de Google.' );

		\MOBookingStudio\Application\CalendarSync::enqueue( $booking['id'], 'sync_booking', true );
		SyncWorker::processQueue();
		$this->assertCount( 2, $this->google->owned() );
		$this->assertSame( '2026-11-20T10:00:00-07:00', $this->google->events[ FakeGoogle::DESTINATION ][ $clientId ]['start']['dateTime'] );
		$this->assertSame( 0, Conflicts::scan()['external'] );
	}

	/** AC-CAL-08: falla temporal y respuesta perdida no duplican eventos. */
	public function test_transient_failure_retries_without_duplicates(): void {
		$service = $this->christmasService();
		$this->google->connect();
		$booking = $this->book( $service, self::START )['booking'];
		$this->google->failNext = array( 503 );
		SyncWorker::processQueue();
		global $wpdb;
		$job = $wpdb->get_row( 'SELECT * FROM ' . Schema::table( 'sync_jobs' ), ARRAY_A );
		$this->assertSame( 'pending', $job['status'] );
		$this->assertSame( '503', $job['last_http'] );
		$this->assertSame( 'retrying', $wpdb->get_var( 'SELECT sync_state FROM ' . Schema::table( 'bookings' ) ) );
		$this->assertCount( 0, $this->google->owned() );

		$this->clock->advance( '+2 minutes' );
		SyncWorker::processQueue();
		$this->assertCount( 1, $this->google->owned( 'internal' ) );

		// Google creó el evento pero la respuesta se perdió: el registro local no existe.
		$wpdb->query( 'DELETE FROM ' . Schema::table( 'calendar_events' ) );
		$this->approve( Bookings::get( $booking['id'] ) );
		SyncWorker::processQueue();
		$this->assertCount( 1, $this->google->owned( 'internal' ), 'El identificador determinista evita el duplicado (409 → actualizar).' );
		$this->assertCount( 1, $this->google->owned( 'client' ) );
		$this->assertSame( 1, $this->google->invitations );
	}

	public function test_permanent_failure_after_max_attempts(): void {
		$service = $this->christmasService();
		$this->google->connect();
		$this->book( $service, self::START );
		global $wpdb;
		for ( $i = 0; $i < SyncWorker::MAX_ATTEMPTS; $i++ ) {
			$this->google->failNext = array( 500 );
			$wpdb->query( 'UPDATE ' . Schema::table( 'sync_jobs' ) . " SET next_run_at = '2000-01-01 00:00:00'" );
			SyncWorker::processQueue();
		}
		$this->assertSame( 'failed', $wpdb->get_var( 'SELECT status FROM ' . Schema::table( 'sync_jobs' ) ) );
		$this->assertSame( 'error', $wpdb->get_var( 'SELECT sync_state FROM ' . Schema::table( 'bookings' ) ) );
		$this->assertCount( 1, SyncWorker::jobsSummary()['failed'] );

		SyncWorker::retry( (int) $wpdb->get_var( 'SELECT id FROM ' . Schema::table( 'sync_jobs' ) ) );
		SyncWorker::processQueue();
		$this->assertCount( 1, $this->google->owned( 'internal' ) );
	}

	/** DEC-06: con Google conectado y sin respuesta, se detiene la reserva en línea; la administradora puede forzarla. */
	public function test_google_down_stops_online_booking(): void {
		$service = $this->christmasService();
		$this->google->connect();
		$this->google->down = true;
		try {
			Catalog::quote( $service, array( 'start' => self::START, 'participants' => 6 ) );
			$this->fail( 'Sin Google no se promete el horario.' );
		} catch ( DomainException $e ) {
			$this->assertSame( 'calendar_unavailable', $e->errorCode() );
			$this->assertSame( 503, $e->httpStatus() );
		}
		try {
			Catalog::availability( $service, '2026-11-20', '2026-11-20' );
			$this->fail( 'Sin caché previa tampoco se muestra disponibilidad.' );
		} catch ( DomainException $e ) {
			$this->assertSame( 'calendar_unavailable', $e->errorCode() );
		}
		$admin = Bookings::create(
			$service,
			array_merge( $this->contact(), array( 'start' => self::START, 'participants' => 6, 'idempotency_key' => wp_generate_uuid4() ) ),
			array( 'source' => 'admin', 'google_override' => true )
		);
		global $wpdb;
		$this->assertSame( '1', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'audit' ) . " WHERE action = 'google_override' AND entity_id = %d", $admin['booking']['id'] ) ) );
	}

	/** La vista usa la copia anterior si Google falla un momento, con aviso; reservar sigue exigiendo consulta fresca. */
	public function test_stale_view_is_marked(): void {
		$service = $this->christmasService();
		$this->google->connect();
		Catalog::availability( $service, '2026-11-20', '2026-11-20' );
		// Pasan los 3 minutos de la caché; queda la copia de respaldo del día.
		$day = \DateTimeImmutable::createFromFormat( '!Y-m-d', '2026-11-20', \MOBookingStudio\Infrastructure\Settings::timezone() );
		delete_transient( \MOBookingStudio\Integrations\Google\Busy::cacheKey( $day->modify( '-1 day' ), $day->modify( '+2 days' ) ) );
		$this->google->down = true;
		$view = Catalog::availability( $service, '2026-11-20', '2026-11-20' );
		$this->assertTrue( $view['stale'] );
		$this->assertStringContainsString( 'Vista preliminar', $view['note'] );
	}

	/** Un calendario de lectura ilegible cuenta como falla, no como "libre". */
	public function test_unreadable_calendar_is_failure(): void {
		$service = $this->christmasService();
		$this->google->connect();
		$this->google->brokenCalendars = array( FakeGoogle::BUSY_CAL );
		$this->expectException( DomainException::class );
		Catalog::quote( $service, array( 'start' => self::START, 'participants' => 6 ) );
	}

	/** AC-CAL-09: revocación pausa la cola sin perder trabajos y detiene la reserva en línea; al reconectar continúa. */
	public function test_revocation_pauses_and_resumes(): void {
		$service = $this->christmasService();
		$this->google->connect();
		$booking = $this->book( $service, self::START )['booking'];
		$this->google->revoked = true;
		SyncWorker::processQueue();
		$this->assertSame( 'revoked', Connection::data()['status'] );
		global $wpdb;
		$job = $wpdb->get_row( 'SELECT * FROM ' . Schema::table( 'sync_jobs' ), ARRAY_A );
		$this->assertSame( 'pending', $job['status'] );
		$this->assertSame( '0', $job['attempts'], 'No gasta intentos.' );

		// Mientras esté revocada, la aprobación se encola y la reserva en línea se detiene.
		$this->approve( $booking );
		try {
			Catalog::quote( $service, array( 'start' => '2026-11-20T18:00:00Z', 'participants' => 6 ) );
			$this->fail( 'Debe detenerse.' );
		} catch ( DomainException $e ) {
			$this->assertSame( 'calendar_unavailable', $e->errorCode() );
		}
		$this->assertSame( 0, SyncWorker::processQueue() );

		$this->google->revoked = false;
		$this->google->connect();
		SyncWorker::processQueue();
		$this->assertCount( 1, $this->google->owned( 'internal' ) );
		$this->assertCount( 1, $this->google->owned( 'client' ) );
		$this->assertSame( 1, $this->google->invitations );
	}

	/** AC-CAL-10: horas correctas antes y después del cambio de horario (1 de noviembre de 2026). */
	public function test_timezone_across_dst(): void {
		$service = $this->christmasService();
		Schedules::saveDate( Schedules::defaultResourceId(), '2026-10-31', array( array( '10:00', '13:00' ) ) );
		$this->google->connect();
		$october  = $this->book( $service, '2026-10-31T16:00:00Z' )['booking']; // 10:00 con horario de verano (UTC-6).
		$november = $this->book( $service, self::START )['booking'];             // 10:00 con horario estándar (UTC-7).
		SyncWorker::processQueue();
		$byBooking = array();
		foreach ( $this->google->owned( 'internal' ) as $e ) {
			$byBooking[ $e['extendedProperties']['private']['mo_booking'] ] = $e;
		}
		$this->assertSame( '2026-10-31T10:00:00-06:00', $byBooking[ (string) $october['id'] ]['start']['dateTime'] );
		$this->assertSame( '2026-11-20T10:00:00-07:00', $byBooking[ (string) $november['id'] ]['start']['dateTime'] );
		$this->assertSame( '2026-11-20T10:20:00-07:00', $byBooking[ (string) $november['id'] ]['end']['dateTime'] );
		$this->assertSame( 'America/Ciudad_Juarez', $byBooking[ (string) $november['id'] ]['start']['timeZone'] );
	}

	/** CAL-02/04: la conexión valida state y guarda los tokens cifrados. */
	public function test_oauth_callback_validates_state_and_encrypts_tokens(): void {
		$url = Connection::authUrl();
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
		$this->assertSame( implode( ' ', Connection::SCOPES ), $q['scope'] );
		$this->assertSame( 'offline', $q['access_type'] );
		$this->assertSame( Connection::redirectUri(), $q['redirect_uri'] );
		try {
			Connection::handleCallback( 'code-123', 'otro-state' );
			$this->fail( 'Debe rechazar un state ajeno.' );
		} catch ( DomainException $e ) {
			$this->assertSame( 'google_state', $e->errorCode() );
		}
		Connection::authUrl();
		$url = Connection::authUrl();
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
		Connection::handleCallback( 'code-123', $q['state'] );
		$d = Connection::data();
		$this->assertSame( 'connected', $d['status'] );
		$this->assertSame( FakeGoogle::DESTINATION, $d['account'] );
		$raw = wp_json_encode( get_option( Connection::OPTION ) );
		$this->assertStringNotContainsString( 'refresh-', $raw, 'El token no se guarda en claro.' );
		$this->assertStringNotContainsString( 'access-', $raw );

		// Desconectar revoca en Google y conserva reservas y elección de calendarios.
		Connection::save( array( 'destination' => FakeGoogle::DESTINATION ) );
		Connection::disconnect();
		$this->assertSame( 1, $this->google->count( 'POST', 'oauth2.googleapis.com/revoke' ) );
		$this->assertSame( FakeGoogle::DESTINATION, Connection::data()['destination'] );
		$this->assertFalse( Connection::isConfigured() );
	}

	/** Un 401 por token de acceso vencido se renueva una vez y continúa. */
	public function test_expired_access_token_is_refreshed(): void {
		$service = $this->christmasService();
		$this->google->connect();
		Connection::save( array( 'access_expires' => 0 ) );
		$this->book( $service, self::START );
		SyncWorker::processQueue();
		$this->assertSame( 1, $this->google->count( 'POST', 'oauth2.googleapis.com/token' ) );
		$this->assertCount( 1, $this->google->owned( 'internal' ) );
	}

	/** Agregar MO_BOOKING_SECRET después de instalar no cambia los enlaces que reescriben los correos. */
	public function test_token_key_source_is_pinned(): void {
		$option = \MOBookingStudio\Infrastructure\Tokens::KEY_SOURCE_OPTION;
		update_option( $option, 'salt' );
		$expected = rtrim( strtr( base64_encode( hash_hmac( 'sha256', 'mo-access|7|abc', wp_salt( 'secure_auth' ) . '|mo-booking-studio', true ) ), '+/', '-_' ), '=' );
		$this->assertSame( $expected, \MOBookingStudio\Infrastructure\Tokens::tokenFor( 7, 'abc' ) );
		delete_option( $option );
		$this->assertNotSame( $expected, \MOBookingStudio\Infrastructure\Tokens::tokenFor( 7, 'abc' ) );
		$this->assertSame( 'secret', get_option( $option ) );
	}

	/** Correos de dominios de ejemplo (datos de prueba): evento sin invitados y sin marcarlo como editado. */
	public function test_example_domains_are_not_invited(): void {
		$service = $this->christmasService();
		$this->google->connect();
		$booking = $this->approve( $this->book( $service, self::START, array( 'email' => 'prueba@example.com' ) )['booking'] );
		SyncWorker::processQueue();
		$client = $this->google->owned( 'client' );
		$this->assertCount( 1, $client );
		$this->assertSame( array(), $client[0]['attendees'] );
		$this->assertSame( 0, $this->google->invitations );
		$this->assertSame( 0, Conflicts::scan()['external'] );
		$this->assertNull( SyncWorker::eventsFor( $booking['id'] )[0]['invited_at'], 'No se registra invitación.' );
		$this->assertStringStartsWith( 'MO CONFIRMADA', $this->google->owned( 'internal' )[0]['summary'] );
		$this->assertStringStartsWith( 'PRUEBA · MO PENDIENTE', EventBuilder::internal( array_merge( Bookings::get( $booking['id'] ), array( 'is_test' => 1, 'status' => BookingStatus::PENDING_PAYMENT ) ) )['summary'] );
		$this->assertFalse( EventBuilder::canInvite( 'x@demo.test' ) );
		$this->assertTrue( EventBuilder::canInvite( 'monica@gmail.com' ) );
	}

	/** SEG-03 / AC-07: confirmar por excepción no invita; la acción explícita sí, una sola vez. */
	public function test_exception_confirmation_does_not_invite(): void {
		$service = $this->christmasService();
		$this->google->connect();
		$booking = $this->book( $service, self::START )['booking'];
		Bookings::confirm( $booking['id'], 'Pagó en efectivo en el estudio; registro el pago mañana' );
		SyncWorker::processQueue();
		$this->assertSame( BookingStatus::CONFIRMED, Bookings::get( $booking['id'] )['status'] );
		$this->assertCount( 1, $this->google->owned( 'internal' ) );
		$this->assertStringStartsWith( 'MO CONFIRMADA', $this->google->owned( 'internal' )[0]['summary'] );
		$this->assertCount( 0, $this->google->owned( 'client' ), 'Sin invitación.' );
		$this->assertSame( 0, $this->google->invitations );
		$audit = array_column( \MOBookingStudio\Infrastructure\Audit::forEntity( 'booking', $booking['id'] ), 'action' );
		$this->assertContains( 'confirmed_by_exception', $audit );

		try {
			Bookings::inviteWithoutDeposit( $booking['id'], '  ' );
			$this->fail( 'Exige motivo.' );
		} catch ( DomainException $e ) {
			$this->assertSame( 'reason_required', $e->errorCode() );
		}
		Bookings::inviteWithoutDeposit( $booking['id'], 'Clienta de confianza; paga el día de la sesión' );
		Bookings::inviteWithoutDeposit( $booking['id'], 'Repetido por error' );
		SyncWorker::processQueue();
		SyncWorker::processQueue();
		$this->assertCount( 1, $this->google->owned( 'client' ) );
		$this->assertSame( 1, $this->google->invitations );
		$audit = array_count_values( array_column( \MOBookingStudio\Infrastructure\Audit::forEntity( 'booking', $booking['id'] ), 'action' ) );
		$this->assertSame( 1, $audit['invite_without_deposit'] );
	}

	/** SEG-03: confirmada por excepción y luego se registra el anticipo: entonces sí se invita. */
	public function test_payment_after_exception_invites(): void {
		$service = $this->christmasService();
		$this->google->connect();
		$booking = $this->book( $service, self::START )['booking'];
		Bookings::confirm( $booking['id'], 'Excepción' );
		SyncWorker::processQueue();
		$this->assertSame( 0, $this->google->invitations );
		Bookings::approvePayment( $booking['id'], array( 'amount_cents' => 50000, 'received_date' => '2026-10-20', 'form_key' => wp_generate_uuid4() ) );
		SyncWorker::processQueue();
		$this->assertSame( 1, $this->google->invitations );
	}

	/** Anticipo configurado en cero: confirmada al reservar e invitada. */
	public function test_zero_deposit_invites_on_creation(): void {
		$service = $this->christmasService( array( 'deposit_type' => 'fixed', 'deposit_value' => 0 ) );
		$this->google->connect();
		$booking = $this->book( $service, self::START )['booking'];
		$this->assertSame( BookingStatus::CONFIRMED, $booking['status'] );
		SyncWorker::processQueue();
		$this->assertSame( 1, $this->google->invitations );
	}

	/** SEG-05: cambiar el calendario destino mueve los eventos propios, verifica y no reenvía invitaciones. */
	public function test_destination_change_moves_own_events(): void {
		$service = $this->christmasService();
		$this->google->connect();
		$confirmed = $this->approve( $this->book( $service, self::START )['booking'] );
		$pending   = $this->book( $service, '2026-11-20T18:00:00Z' )['booking'];
		$foreign   = $this->google->addExternal( FakeGoogle::DESTINATION, '2026-11-21T17:00:00Z', '2026-11-21T18:00:00Z' );
		SyncWorker::processQueue();
		$this->assertCount( 3, $this->google->owned() );

		Connection::save( array( 'destination' => FakeGoogle::NEW_DEST ) );
		$this->assertCount( 3, SyncWorker::pendingMigration() );
		foreach ( array_unique( array_column( SyncWorker::pendingMigration(), 'booking_id' ) ) as $id ) {
			\MOBookingStudio\Application\CalendarSync::enqueue( (int) $id );
		}
		$this->google->failNext = array( 503 ); // El primer movimiento falla y se reintenta.
		SyncWorker::processQueue();
		$this->clock->advance( '+10 minutes' );
		SyncWorker::processQueue();

		$this->assertCount( 0, SyncWorker::pendingMigration() );
		$this->assertCount( 0, $this->google->owned(), 'No quedan eventos propios en el calendario anterior.' );
		$this->assertCount( 3, $this->google->owned( null, FakeGoogle::NEW_DEST ) );
		$this->assertArrayHasKey( $foreign, $this->google->events[ FakeGoogle::DESTINATION ], 'El evento ajeno no se toca.' );
		$this->assertSame( 1, $this->google->invitations, 'Mover no reenvía la invitación.' );
		$this->assertSame( 0, $this->google->cancellations );
		$this->assertSame( 0, $this->google->count( 'DELETE', '/events/' ) );
		$this->assertSame( 3, $this->google->moves );
		unset( $confirmed, $pending );
	}

	/** AC-09 y AC-10: contenido exacto de los dos eventos (9.4 y 9.5). */
	public function test_event_contents_follow_spec(): void {
		\MOBookingStudio\Infrastructure\Settings::update( array( 'public_contact' => 'WhatsApp 656 634 4849' ) );
		$service = $this->christmasService(
			array(
				'location'      => 'Estudio Clics',
				'instructions'  => 'Llega 10 minutos antes.',
				'delivery_text' => 'Entrega en 5 días',
				'fast_delivery' => array( 'enabled' => true, 'label' => 'Entrega rápida', 'price_cents' => 30000, 'question' => '¿Entrega rápida?', 'regular_text' => '' ),
			)
		);
		$this->google->connect();
		$quote   = Catalog::quote( $service, array( 'start' => self::START, 'children' => '2', 'adults' => '3', 'pets' => '1', 'fast_delivery' => true ) );
		$booking = Bookings::create( $service, array_merge( $this->contact( array( 'phone' => '656 999 8888' ) ), array( 'quote_id' => $quote['quote_id'], 'idempotency_key' => wp_generate_uuid4(), 'important_info' => 'Mi hija es alérgica al polen' ) ) )['booking'];
		SyncWorker::processQueue();
		$internal = $this->google->owned( 'internal' )[0];
		foreach ( array( 'Nombre: Ana Prueba', 'Teléfono: 656 999 8888', 'Correo: ana@clientes-mo.mx', 'Asistentes: 5 (niños 2, adultos 3)', 'Mascotas: 1', 'Extras: Entrega rápida', 'Pago: Sin pago', 'Información importante: Mi hija es alérgica al polen', $booking['public_ref'] ) as $text ) {
			$this->assertStringContainsString( $text, $internal['description'] );
		}
		$this->assertSame( array(), $internal['attendees'], 'El interno nunca invita a la clienta.' );

		$this->approve( $booking );
		SyncWorker::processQueue();
		$this->assertStringStartsWith( 'MO CONFIRMADA', $this->google->owned( 'internal' )[0]['summary'] );
		$client = $this->google->owned( 'client' )[0];
		$this->assertSame( 'Sesión con Clics con Amor Fotografía', $client['summary'] );
		foreach ( array( 'Servicio: Minis navideñas 2026', 'Duración: 20 minutos', 'Llega 10 minutos antes.', 'Contacto: WhatsApp 656 634 4849' ) as $text ) {
			$this->assertStringContainsString( $text, $client['description'] );
		}
		$this->assertSame( 'Estudio Clics', $client['location'] );
		$public = wp_json_encode( $client );
		foreach ( array( $booking['public_ref'], 'alérgica', '656 999 8888', '6569998888', 'Entrega en 5 días', 'Pago', 'admin.php', 'acceso=', 'comprobante', '$' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $public, 'El evento del cliente no debe contener: ' . $secret );
		}
	}
}
