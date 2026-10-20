<?php
namespace MOBookingStudio\Tests\Integration;

use MOBookingStudio\Application\Bookings;
use MOBookingStudio\Application\Catalog;
use MOBookingStudio\Application\Proofs;
use MOBookingStudio\Application\Services;
use MOBookingStudio\Domain\BookingStatus;
use MOBookingStudio\Domain\DomainException;
use MOBookingStudio\Domain\Ledger;
use MOBookingStudio\Infrastructure\Schema;

final class BookingFlowTest extends IntegrationTestCase {
	private const NOV20_10AM = '2026-11-20T17:00:00Z';

	/** AC03 vía la capa de aplicación: 150 espacios con la agenda cargada desde el panel. */
	public function test_availability_shows_150_slots(): void {
		$service = $this->christmasService();
		$nov     = Catalog::availability( $service, '2026-11-01', '2026-11-30' );
		$dec     = Catalog::availability( $service, '2026-12-01', '2026-12-31' );
		$count   = static function ( $r ) {
			return array_sum( array_column( $r['days'], 'available' ) );
		};
		$this->assertSame( 44, $count( $nov ) );
		$this->assertSame( 106, $count( $dec ) );
		$byDate = array_column( $dec['days'], null, 'date' );
		$this->assertSame( 'closed', $byDate['2026-12-01']['status'] );
		$this->assertSame( '$1,600 MXN', $byDate['2026-12-12']['price_txt'] ); // Preventa pública en diciembre.
		$this->assertFalse( $byDate['2026-12-12']['special'] );
		$both    = Catalog::availability( $service, '2026-11-15', '2026-12-20' );
		$all     = array_column( $both['days'], null, 'date' );
		$this->assertTrue( $all['2026-11-20']['special'] );
		$this->assertSame( '$200 MXN', $all['2026-11-20']['saving_txt'] );
	}

	public function test_full_flow_booking_proof_approval(): void {
		$service = $this->christmasService();
		$result  = $this->book( $service, self::NOV20_10AM );
		$booking = $result['booking'];

		$this->assertSame( BookingStatus::PENDING_PAYMENT, $booking['status'] );
		$this->assertSame( 140000, $booking['total_cents'] ); // Preventa pública noviembre.
		$this->assertSame( 50000, $booking['deposit_cents'] );
		$this->assertSame( '2026-10-21 06:00:00', $booking['expires_at'] ); // 12 horas.
		$this->assertMatchesRegularExpression( '/^MO-[A-Z2-9]{6}$/', $booking['public_ref'] );
		$this->assertSame( 43, strlen( $result['token'] ) );

		// El horario ya no se ofrece.
		$this->assertNull( Catalog::findAvailableSlot( $service, new \DateTimeImmutable( self::NOV20_10AM ), $this->clock->now() ) );

		// Comprobante antes del vencimiento.
		$this->clock->advance( '+11 hours' );
		$upload = Proofs::upload( $booking['id'], $this->fakeUpload( 'png' ), false );
		$this->assertFalse( $upload['late'] );
		$booking = Bookings::get( $booking['id'] );
		$this->assertSame( BookingStatus::PAYMENT_REVIEW, $booking['status'] );

		// AC11: pasa el plazo original y sigue retenido durante la revisión.
		$this->clock->advance( '+3 days' );
		Bookings::expireDue();
		$this->assertSame( BookingStatus::PAYMENT_REVIEW, Bookings::get( $booking['id'] )['status'] );
		$this->assertNull( Catalog::findAvailableSlot( $service, new \DateTimeImmutable( self::NOV20_10AM ), $this->clock->now() ) );

		// Aprobación con confirmación.
		$booking = Bookings::approvePayment( $booking['id'], array( 'proof_id' => $upload['proof_id'], 'amount_cents' => 50000, 'received_date' => '2026-10-21', 'reference' => 'SPEI 123', 'confirm' => true ) );
		$this->assertSame( BookingStatus::CONFIRMED, $booking['status'] );
		$summary = Bookings::summary( $booking );
		$this->assertSame( Ledger::DEPOSIT_COVERED, $summary['status'] );
		$this->assertSame( 90000, $summary['balance_cents'] );

		// AC14: aprobar dos veces el mismo comprobante no duplica el depósito.
		try {
			Bookings::approvePayment( $booking['id'], array( 'proof_id' => $upload['proof_id'], 'amount_cents' => 50000, 'received_date' => '2026-10-21' ) );
			$this->fail( 'Se esperaba rechazo de la segunda aprobación.' );
		} catch ( DomainException $e ) {
			$this->assertSame( 'already_recorded', $e->errorCode() );
		}
		$this->assertCount( 1, Bookings::movements( $booking['id'] ) );

		// Una sola notificación de confirmación al cliente (no "pago aprobado" + "confirmada").
		global $wpdb;
		$events = $wpdb->get_col( $wpdb->prepare( 'SELECT event FROM ' . Schema::table( 'notifications' ) . " WHERE booking_id = %d AND channel = 'email' AND recipient = 'ana@example.com'", $booking['id'] ) );
		$this->assertSame( array( 'booking_created', 'proof_received', 'booking_confirmed', 'reminder' ), $events );
	}

	/** AC14: abono insuficiente no confirma y pide la diferencia. */
	public function test_partial_payment_requests_difference(): void {
		$service = $this->christmasService();
		$booking = $this->book( $service, self::NOV20_10AM )['booking'];
		$up      = Proofs::upload( $booking['id'], $this->fakeUpload( 'pdf' ), false );
		$booking = Bookings::approvePayment( $booking['id'], array( 'proof_id' => $up['proof_id'], 'amount_cents' => 30000, 'received_date' => '2026-10-20', 'confirm' => true ) );
		$this->assertSame( BookingStatus::CORRECTION_REQUESTED, $booking['status'] );
		$this->assertSame( 20000, Bookings::summary( $booking )['deposit_missing'] );
		$this->assertNotNull( $booking['correction_until'] );

		$up2     = Proofs::upload( $booking['id'], $this->fakeUpload( 'png' ), false );
		$booking = Bookings::approvePayment( $booking['id'], array( 'proof_id' => $up2['proof_id'], 'amount_cents' => 20000, 'received_date' => '2026-10-20', 'confirm' => true ) );
		$this->assertSame( BookingStatus::CONFIRMED, $booking['status'] );
		$this->assertCount( 2, Bookings::movements( $booking['id'] ) );
	}

	/** AC12: vencimiento sin cron; al consultar o reservar se concilia. */
	public function test_expiry_without_cron_is_reconciled_on_request(): void {
		$service = $this->christmasService();
		$first   = $this->book( $service, self::NOV20_10AM )['booking'];
		$this->clock->advance( '+12 hours' ); // Exactamente expires_at: ya venció.
		$slot = Catalog::findAvailableSlot( $service, new \DateTimeImmutable( self::NOV20_10AM ), $this->clock->now() );
		$this->assertNotNull( $slot, 'Una retención vencida no debe bloquear aunque no haya corrido la tarea.' );

		$second = $this->book( $service, self::NOV20_10AM, array( 'email' => 'beto@example.com', 'phone' => '656 765 4321' ) )['booking'];
		$this->assertSame( BookingStatus::EXPIRED, Bookings::get( $first['id'] )['status'] );
		$this->assertSame( BookingStatus::PENDING_PAYMENT, $second['status'] );

		// AC13: comprobante tardío no desplaza la reserva vigente.
		$late = Proofs::upload( $first['id'], $this->fakeUpload( 'png' ), false );
		$this->assertTrue( $late['late'] );
		$this->assertSame( BookingStatus::EXPIRED, Bookings::get( $first['id'] )['status'] );
		$this->assertSame( BookingStatus::PENDING_PAYMENT, Bookings::get( $second['id'] )['status'] );
	}

	/** AC07: la tarifa de preventa se conserva durante la retención aunque cierre la preventa. */
	public function test_presale_price_kept_during_hold(): void {
		$service = $this->christmasService();
		$this->clock->advance( '+11 days +11 hours +30 minutes' ); // 2026-11-01 05:30 UTC = 31 oct 23:30 local.
		$booking = $this->book( $service, self::NOV20_10AM )['booking'];
		$this->assertSame( 140000, $booking['total_cents'] );
		$this->clock->advance( '+2 hours' ); // Ya cerró la preventa.
		$this->assertSame( 140000, Bookings::get( $booking['id'] )['total_cents'] );
		$q = Catalog::quote( $service, array( 'start' => '2026-11-20T17:30:00Z', 'participants' => 6 ) );
		$this->assertSame( 160000, $q['total'] );
	}

	/** El precio cambió entre la cotización y el envío: se pide revisar el nuevo resumen. */
	public function test_quote_changed_conflict(): void {
		$service = $this->christmasService();
		$quote   = Catalog::quote( $service, array( 'start' => self::NOV20_10AM, 'participants' => 6 ) );
		$this->clock->advance( '+11 days +11 hours +55 minutes' ); // 31 oct 23:55 local
		$quote   = Catalog::quote( $service, array( 'start' => self::NOV20_10AM, 'participants' => 6 ) );
		$this->clock->advance( '+10 minutes' ); // 1 nov 00:05 local: terminó la preventa.
		try {
			Bookings::create( $service, array_merge( $this->contact(), array( 'quote_id' => $quote['quote_id'], 'idempotency_key' => wp_generate_uuid4() ) ) );
			$this->fail( 'Se esperaba conflicto de cotización.' );
		} catch ( DomainException $e ) {
			$this->assertSame( 'quote_changed', $e->errorCode() );
			$this->assertSame( 160000, $e->details()['quote']['total'] );
		}
	}

	/** AC10: reintentar con la misma clave devuelve la misma reserva sin duplicar. */
	public function test_idempotent_retry(): void {
		$service = $this->christmasService();
		$quote   = Catalog::quote( $service, array( 'start' => self::NOV20_10AM, 'participants' => 6 ) );
		$input   = array_merge( $this->contact(), array( 'quote_id' => $quote['quote_id'], 'idempotency_key' => 'abcdef12-3456-7890-abcd-ef1234567890' ) );
		$a       = Bookings::create( $service, $input );
		$b       = Bookings::create( $service, $input );
		$this->assertFalse( $a['replayed'] );
		$this->assertTrue( $b['replayed'] );
		$this->assertSame( $a['booking']['id'], $b['booking']['id'] );
		$this->assertSame( $a['token'], $b['token'] );
		global $wpdb;
		$this->assertSame( '1', $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'bookings' ) ) );
		$this->assertSame( '1', $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'occupancies' ) ) );
		$this->assertSame( '1', $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'notifications' ) . " WHERE event = 'booking_created' AND recipient = 'ana@example.com'" ) );

		$input['name'] = 'Otra Persona';
		$this->expectException( DomainException::class );
		Bookings::create( $service, $input );
	}

	/** AC09: un servicio distinto que comparte el recurso no vende el mismo tiempo. */
	public function test_other_service_cannot_overlap(): void {
		$minis  = $this->christmasService();
		$studio = Services::get(
			Services::save(
				array(
					'title'            => 'Sesión de estudio',
					'status'           => 'published',
					'duration'         => 60,
					'interval'         => 15,
					'price_cents'      => 250000,
					'deposit_type'     => 'percent',
					'deposit_value'    => 30,
					'min_notice_hours' => 0,
					'horizon_days'     => 0,
				)
			)
		);
		$this->book( $minis, self::NOV20_10AM ); // 10:00–10:30 local.
		$this->assertNull( Catalog::findAvailableSlot( $studio, new \DateTimeImmutable( '2026-11-20T16:45:00Z' ), $this->clock->now() ) ); // 9:45–10:45
		$this->assertNull( Catalog::findAvailableSlot( $studio, new \DateTimeImmutable( '2026-11-20T17:15:00Z' ), $this->clock->now() ) ); // 10:15
		$this->assertNotNull( Catalog::findAvailableSlot( $studio, new \DateTimeImmutable( '2026-11-20T17:30:00Z' ), $this->clock->now() ) ); // 10:30–11:30
		$this->assertNull( Catalog::findAvailableSlot( $studio, new \DateTimeImmutable( '2026-11-20T16:00:00Z' ), $this->clock->now() ) ); // 9:00: fuera del horario de ese día.
	}

	/** AC26: archivos maliciosos se rechazan. */
	public function test_malicious_files_rejected(): void {
		$service = $this->christmasService();
		$booking = $this->book( $service, self::NOV20_10AM )['booking'];
		foreach ( array( 'svg', 'php-as-png' ) as $kind ) {
			try {
				Proofs::upload( $booking['id'], $this->fakeUpload( $kind ), false );
				$this->fail( "Se aceptó un archivo $kind" );
			} catch ( DomainException $e ) {
				$this->assertSame( 415, $e->httpStatus() );
				$this->assertStringNotContainsString( '/', $e->getMessage() );
			}
		}
		$this->assertSame( BookingStatus::PENDING_PAYMENT, Bookings::get( $booking['id'] )['status'] );
	}

	public function test_uploads_disabled_until_storage_verified(): void {
		$service = $this->christmasService();
		$booking = $this->book( $service, self::NOV20_10AM )['booking'];
		\MOBookingStudio\Infrastructure\Settings::update( array( 'uploads_verified' => 'public' ) );
		$this->expectExceptionMessage( 'no podemos recibir archivos' );
		Proofs::upload( $booking['id'], $this->fakeUpload( 'png' ), false );
	}

	/** AC17, AC18 y AC22 aplicados a reservas reales. */
	public function test_reschedule_and_cancel_follow_accepted_policy(): void {
		$service = $this->christmasService();
		$booking = $this->book( $service, self::NOV20_10AM )['booking'];
		$up      = Proofs::upload( $booking['id'], $this->fakeUpload( 'png' ), false );
		$booking = Bookings::approvePayment( $booking['id'], array( 'proof_id' => $up['proof_id'], 'amount_cents' => 50000, 'received_date' => '2026-10-20', 'confirm' => true ) );

		// A 24 horas exactas de la cita: conserva el anticipo.
		$this->clock->advance( '+' . ( ( new \DateTimeImmutable( self::NOV20_10AM ) )->getTimestamp() - $this->clock->now()->getTimestamp() - 86400 ) . ' seconds' );
		$preview = Bookings::previewReschedule( $booking, new \DateTimeImmutable( '2026-11-27T17:00:00Z' ) );
		$this->assertSame( 0, $preview['retained'] );
		$this->assertSame( 0, $preview['amount_due_now'] );
		$booking = Bookings::reschedule( $booking['id'], new \DateTimeImmutable( '2026-11-27T17:00:00Z' ), 'Pidió cambio', $booking['version'] );
		$this->assertSame( BookingStatus::CONFIRMED, $booking['status'] );
		$this->assertSame( '2026-11-27 17:00:00', $booking['start_utc'] );
		$this->assertSame( 140000, $booking['total_cents'] ); // Conserva la preventa por fecha de solicitud original.
		$this->assertNotNull( Catalog::findAvailableSlot( $service, new \DateTimeImmutable( self::NOV20_10AM ), $this->clock->now() ) );

		// AC24: el recordatorio anterior queda anulado y hay uno nuevo.
		global $wpdb;
		$reminders = $wpdb->get_results( $wpdb->prepare( 'SELECT status, valid_until FROM ' . Schema::table( 'notifications' ) . " WHERE booking_id = %d AND event = 'reminder' ORDER BY id", $booking['id'] ), ARRAY_A );
		$this->assertSame( 'cancelled', $reminders[0]['status'] );
		$this->assertSame( 'pending', end( $reminders )['status'] );
		$this->assertSame( '2026-11-27 17:00:00', end( $reminders )['valid_until'] );

		// Con menos de 24 horas: pierde el anticipo y la nueva fecha exige otro.
		$this->clock->advance( '+7 days +2 hours' ); // 26 nov 12:00 local: faltan 22 horas.
		$preview = Bookings::previewReschedule( $booking, new \DateTimeImmutable( '2026-12-04T17:00:00Z' ) );
		$this->assertSame( 50000, $preview['retained'] );
		$this->assertSame( 50000, $preview['new_deposit'] );
		$this->assertSame( 50000, $preview['amount_due_now'] );
		$booking = Bookings::reschedule( $booking['id'], new \DateTimeImmutable( '2026-12-04T17:00:00Z' ), 'Cambio tardío', $booking['version'] );
		$this->assertSame( BookingStatus::PENDING_PAYMENT, $booking['status'] );
		$summary = Bookings::summary( $booking );
		$this->assertSame( 50000, $summary['retained_cents'] );
		$this->assertSame( 50000, $summary['deposit_missing'] );

		// AC22: cancelar retiene una vez, libera el cupo y no registra devolución ficticia.
		$booking = Bookings::cancel( $booking['id'], false, 'Ya no puede', $booking['version'] );
		$summary = Bookings::summary( $booking );
		$this->assertSame( 50000, $summary['retained_cents'] );
		$this->assertSame( 0, $summary['refund_due_cents'] );
		$this->assertSame( 0, $summary['refunded_cents'] );
		$this->assertNotNull( Catalog::findAvailableSlot( $service, new \DateTimeImmutable( '2026-12-04T17:00:00Z' ), $this->clock->now() ) );
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'notifications' ) . " WHERE booking_id = %d AND event = 'reminder' AND status = 'pending'", $booking['id'] ) ) );
	}

	/** AC20: cambiar la política después de reservar no afecta la versión aceptada. */
	public function test_policy_version_is_frozen_on_booking(): void {
		$service = $this->christmasService();
		$booking = $this->book( $service, self::NOV20_10AM )['booking'];
		Services::savePolicy( $service['id'], 'cancel', array( array( 'min_hours' => 0, 'max_hours' => null, 'deposit' => 'refund' ) ) );
		$booking = Bookings::get( $booking['id'] );
		$this->assertSame( 1, $booking['policy']['cancel']['version'] );
		$this->assertSame( 'retain', $booking['policy']['cancel']['ranges'][0]['deposit'] );
		$new = $this->book( $service, '2026-11-20T17:30:00Z', array( 'email' => 'beto@example.com' ) )['booking'];
		$this->assertSame( 2, $new['policy']['cancel']['version'] );
	}

	/** AC23 y AC15: recalcular a frecuente tras liquidar deja excedente visible. */
	public function test_frequent_adjustment_after_full_payment(): void {
		$service = $this->christmasService();
		$this->clock->advance( '+15 days' ); // Periodo regular.
		$booking = $this->book( $service, self::NOV20_10AM, array( 'frequent_claim' => true ) )['booking'];
		$this->assertSame( 160000, $booking['total_cents'] );
		$this->assertSame( 'general', $booking['client_category'] );
		$this->assertSame( 1, $booking['frequent_claim'] );
		$booking = Bookings::approvePayment( $booking['id'], array( 'amount_cents' => 160000, 'received_date' => '2026-11-04', 'form_key' => 'test-form-key-123456789', 'confirm' => true ) );
		$booking = Bookings::recalculate( $booking['id'], 'frequent', 'Validé sus 3 sesiones', $booking['version'] );
		$this->assertSame( 140000, $booking['total_cents'] );
		$summary = Bookings::summary( $booking );
		$this->assertSame( 20000, $summary['excess_cents'] );
		$this->assertSame( 0, $summary['refunded_cents'] );
	}

	public function test_complete_counts_session_once_and_revert(): void {
		$service = $this->christmasService();
		$booking = $this->book( $service, self::NOV20_10AM )['booking'];
		$booking = Bookings::confirm( $booking['id'], 'Pago en efectivo acordado' );
		$booking = Bookings::complete( $booking['id'] );
		$client  = \MOBookingStudio\Application\Clients::find( $booking['client_id'] );
		$this->assertSame( '1', (string) $client['completed_sessions'] );
		$this->expectException( DomainException::class );
		Bookings::complete( $booking['id'] );
	}

	public function test_client_match_never_by_name_only(): void {
		$service = $this->christmasService();
		$a       = $this->book( $service, self::NOV20_10AM )['booking'];
		$b       = $this->book( $service, '2026-11-20T17:30:00Z', array( 'email' => 'otra@example.com', 'phone' => '656 000 1111' ) )['booking'];
		$this->assertNotSame( $a['client_id'], $b['client_id'] );
		$c = $this->book( $service, '2026-11-20T18:00:00Z', array( 'name' => 'Otro nombre', 'email' => 'ANA@example.com' ) )['booking'];
		$this->assertSame( $a['client_id'], $c['client_id'] );
		// Correo de un expediente y teléfono de otro: se crea uno nuevo marcado para revisión.
		$d      = $this->book( $service, '2026-11-20T18:30:00Z', array( 'email' => 'ana@example.com', 'phone' => '656 000 1111' ) )['booking'];
		$client = \MOBookingStudio\Application\Clients::find( $d['client_id'] );
		$this->assertNotSame( $a['client_id'], $d['client_id'] );
		$this->assertSame( '1', (string) $client['needs_review'] );
	}
}
