<?php
namespace MOBookingStudio\Tests\Integration;

use MOBookingStudio\Application\Bookings;
use MOBookingStudio\Application\Catalog;
use MOBookingStudio\Application\Clients;
use MOBookingStudio\Application\Proofs;
use MOBookingStudio\Application\Services;
use MOBookingStudio\Domain\BookingStatus;
use MOBookingStudio\Domain\DomainException;
use MOBookingStudio\Infrastructure\Audit;
use MOBookingStudio\Infrastructure\Schema;

/**
 * Acciones administrativas mínimas (12, 13, 10.4) y pagos (8.3): AC-13 y AC-14.
 */
final class AdminActionsTest extends IntegrationTestCase {
	private const START = '2026-11-20T17:00:00Z';

	private function confirmed( array $service ): array {
		$booking = $this->book( $service, self::START, array( 'children' => '2', 'adults' => '2' ) )['booking'];
		$up      = Proofs::upload( $booking['id'], $this->fakeUpload( 'png' ), false );
		return Bookings::approvePayment( $booking['id'], array( 'proof_id' => $up['proof_id'], 'amount_cents' => 50000, 'received_date' => '2026-10-20' ) );
	}

	private function actions( int $bookingId ): array {
		return array_column( Audit::forEntity( 'booking', $bookingId ), 'action' );
	}

	/** AC-13: archivar no borra ni cambia estado o pagos; restaurar lo recupera. */
	public function test_archive_and_restore_booking(): void {
		$service = $this->christmasService();
		$booking = $this->confirmed( $service );
		$summary = Bookings::summary( $booking );
		Bookings::setArchived( $booking['id'], true );
		$archived = Bookings::get( $booking['id'] );
		$this->assertTrue( $archived['archived'] );
		$this->assertSame( BookingStatus::CONFIRMED, $archived['status'] );
		$this->assertSame( $summary, Bookings::summary( $archived ) );
		$this->assertCount( 0, Bookings::list( array( 'archived' => false ) ) );
		$this->assertCount( 1, Bookings::list( array( 'archived' => true ) ) );
		$this->assertNull( Catalog::findAvailableSlot( $service, new \DateTimeImmutable( self::START ), $this->clock->now() ), 'Sigue ocupando su horario.' );
		Bookings::setArchived( $booking['id'], false );
		$this->assertFalse( Bookings::get( $booking['id'] )['archived'] );
		$this->assertContains( 'archived', $this->actions( $booking['id'] ) );
		$this->assertContains( 'restored', $this->actions( $booking['id'] ) );
	}

	/** AC-13: borrar algo con historial se bloquea y explica las dependencias. */
	public function test_delete_is_blocked_with_dependencies(): void {
		$service = $this->christmasService();
		$real    = $this->confirmed( $service );
		try {
			Bookings::deleteTest( $real['id'], $real['public_ref'] );
			$this->fail( 'Una reserva real no se borra.' );
		} catch ( DomainException $e ) {
			$this->assertSame( 'has_dependencies', $e->errorCode() );
			$blockers = implode( ' ', $e->details()['blockers'] );
			$this->assertStringContainsString( 'No es una reserva de prueba', $blockers );
			$this->assertStringContainsString( 'movimiento', $blockers );
			$this->assertStringContainsString( 'comprobante', $blockers );
			$this->assertStringContainsString( 'Contrato aceptado', $blockers );
		}
		$this->assertNotNull( Bookings::find( $real['id'] ) );

		// Una prueba registrada en el panel, sin pagos, comprobantes ni contrato aceptado: sí.
		$test = Bookings::create( $service, array_merge( $this->contact(), array( 'start' => '2026-11-20T18:00:00Z', 'children' => '1', 'adults' => '1', 'idempotency_key' => wp_generate_uuid4() ) ), array( 'source' => 'admin', 'is_test' => true ) )['booking'];
		$this->assertSame( array(), Bookings::deleteBlockers( $test ) );
		try {
			Bookings::deleteTest( $test['id'], 'MO-OTRA' );
			$this->fail( 'Exige escribir la referencia.' );
		} catch ( DomainException $e ) {
			$this->assertSame( 'confirm_required', $e->errorCode() );
		}
		Bookings::deleteTest( $test['id'], $test['public_ref'] );
		$this->assertNull( Bookings::find( $test['id'] ) );
		$this->assertContains( 'deleted', $this->actions( $test['id'] ), 'La bitácora conserva el registro.' );
		$this->assertNotNull( Catalog::findAvailableSlot( $service, new \DateTimeImmutable( '2026-11-20T18:00:00Z' ), $this->clock->now() ), 'Liberó su horario.' );
	}

	public function test_services_lifecycle(): void {
		$service = $this->christmasService();
		$copyId  = Services::duplicate( $service['id'] );
		$copy    = Services::get( $copyId );
		$this->assertSame( 'draft', $copy['status'] );
		$this->assertSame( 'Copia de ' . $service['title'], $copy['title'] );
		$this->assertSame( $service['contract_id'], $copy['contract_id'] );
		$this->assertCount( count( Services::rules( $service['id'], false ) ), Services::rules( $copyId, false ) );
		Services::delete( $copyId );
		$this->assertNull( Services::find( $copyId ), 'Nunca se usó: se puede eliminar.' );

		$booking = $this->book( $service, self::START )['booking'];
		try {
			Services::delete( $service['id'] );
			$this->fail( 'Con reservas no se elimina.' );
		} catch ( DomainException $e ) {
			$this->assertSame( 'has_dependencies', $e->errorCode() );
		}
		Services::setStatus( $service['id'], 'archived' );
		try {
			Services::findPublished( $service['slug'] );
			$this->fail( 'Archivado no acepta reservas nuevas.' );
		} catch ( DomainException $e ) {
			$this->assertSame( 404, $e->httpStatus() );
		}
		$this->assertSame( $service['title'], Bookings::get( $booking['id'] )['quote']['service_title'], 'La reserva histórica conserva su información.' );

		$noContract = $this->christmasService( array( 'slug' => 'sin-contrato', 'title' => 'Sin contrato', 'status' => 'draft', 'contract_id' => 0 ) );
		$this->expectExceptionMessage( 'contrato publicado' );
		Services::setStatus( $noContract['id'], 'published' );
	}

	public function test_clients_archive_and_protected_delete(): void {
		$service = $this->christmasService();
		$booking = $this->book( $service, self::START )['booking'];
		Clients::setArchived( $booking['client_id'], true );
		$this->assertCount( 0, Clients::search( 'Ana' ) );
		$this->assertCount( 1, Clients::search( 'Ana', 50, 0, true ) );
		Clients::setArchived( $booking['client_id'], false );
		$this->assertCount( 1, Clients::search( 'Ana' ) );
		try {
			Clients::delete( $booking['client_id'] );
			$this->fail( 'Con reservas no se elimina.' );
		} catch ( DomainException $e ) {
			$this->assertSame( 'has_dependencies', $e->errorCode() );
		}
		$lonely = Clients::create( array( 'name' => 'Sin reservas', 'email' => 'sola@example.com' ) );
		Clients::delete( $lonely );
		$this->assertNull( Clients::find( $lonely ) );
	}

	/** AC-14: un pago no se edita ni se borra; revertir crea un movimiento enlazado con motivo. */
	public function test_payment_reversal_is_linked_and_once(): void {
		$service = $this->christmasService();
		$booking = $this->confirmed( $service );
		global $wpdb;
		$payment = $wpdb->get_row( 'SELECT * FROM ' . Schema::table( 'payments' ) . " WHERE type = 'payment'", ARRAY_A );
		try {
			Bookings::addMovement( $booking['id'], 'reversal', 1, '', wp_generate_uuid4(), (int) $payment['id'] );
			$this->fail( 'Motivo obligatorio.' );
		} catch ( DomainException $e ) {
			$this->assertSame( 'reason_required', $e->errorCode() );
		}
		Bookings::addMovement( $booking['id'], 'reversal', 1, 'El banco rechazó la transferencia', wp_generate_uuid4(), (int) $payment['id'] );
		$reversal = $wpdb->get_row( 'SELECT * FROM ' . Schema::table( 'payments' ) . " WHERE type = 'reversal'", ARRAY_A );
		$this->assertSame( $payment['id'], $reversal['reverses_id'] );
		$this->assertSame( $payment['amount_cents'], $reversal['amount_cents'], 'Se revierte el importe completo del original.' );
		$this->assertSame( '1', $reversal['actor_id'] );
		$this->assertSame( $payment, $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'payments' ) . ' WHERE id = %d', $payment['id'] ), ARRAY_A ), 'El original no cambia.' );
		$this->assertSame( 0, Bookings::summary( Bookings::get( $booking['id'] ) )['received_cents'] );
		try {
			Bookings::addMovement( $booking['id'], 'reversal', 1, 'Otra vez', wp_generate_uuid4(), (int) $payment['id'] );
			$this->fail( 'Un movimiento solo se revierte una vez.' );
		} catch ( DomainException $e ) {
			$this->assertSame( 'already_recorded', $e->errorCode() );
		}
		$this->assertFalse( method_exists( Bookings::class, 'deletePayment' ) );
		$this->assertFalse( method_exists( Bookings::class, 'updatePayment' ) );
	}

	/** 7.7: cancelar libera el horario y registra qué pasa con el anticipo; nada se borra. */
	public function test_cancel_records_deposit_outcome(): void {
		$service = $this->christmasService();
		global $wpdb;
		foreach ( array( 'credit' => 'credit', 'lost' => 'retention', 'manual' => null ) as $outcome => $movement ) {
			$this->setUp();
			$service = $this->christmasService();
			$booking = $this->confirmed( $service );
			try {
				Bookings::cancel( $booking['id'], false, 'Enfermedad', $booking['version'], 'inventado' );
				$this->fail( 'Destino del anticipo inválido.' );
			} catch ( DomainException $e ) {
				$this->assertSame( 'invalid_outcome', $e->errorCode() );
			}
			$after = Bookings::cancel( $booking['id'], false, 'Enfermedad', $booking['version'], $outcome );
			$this->assertSame( BookingStatus::CANCELLED, $after['status'] );
			$this->assertSame( $outcome, $after['cancel_outcome'] );
			$types = $wpdb->get_col( $wpdb->prepare( 'SELECT type FROM ' . Schema::table( 'payments' ) . ' WHERE booking_id = %d', $booking['id'] ) );
			$this->assertContains( 'payment', $types, 'El pago no se borra.' );
			if ( $movement ) {
				$this->assertContains( $movement, $types );
			} else {
				$this->assertSame( array( 'payment' ), $types );
				$this->assertContains( 'reconciliation_needed', $this->actions( $booking['id'] ) );
			}
			$this->assertCount( 1, Bookings::proofs( $booking['id'] ), 'El comprobante se conserva.' );
			$this->assertNotEmpty( $after['contract_body'], 'El contrato se conserva.' );
			$this->assertNotNull( Catalog::findAvailableSlot( $service, new \DateTimeImmutable( self::START ), $this->clock->now() ), 'Horario liberado.' );
		}
	}

	/** 12.2: editar asistentes y extras genera una cotización nueva sin tocar pagos; se audita antes y después. */
	public function test_admin_edit_with_preview_and_audit(): void {
		$service = $this->christmasService(
			array(
				'fast_delivery' => array( 'enabled' => true, 'label' => 'Entrega rápida', 'price_cents' => 30000, 'question' => '¿Entrega rápida?', 'regular_text' => '' ),
			)
		);
		$booking = $this->confirmed( $service );
		global $wpdb;
		$paymentsBefore = $wpdb->get_results( 'SELECT * FROM ' . Schema::table( 'payments' ), ARRAY_A );

		$preview = Bookings::previewEdit( $booking, array( 'adults' => '6', 'fast_delivery' => '1', 'important_info' => 'Traemos perro grande', 'phone' => '656 765 4321' ) );
		$this->assertSame( array( 'from' => '2', 'to' => '6' ), $preview['changes']['Adultos'] );
		$this->assertTrue( $preview['economic'] );
		$this->assertSame( $booking['total_cents'], Bookings::get( $booking['id'] )['total_cents'], 'La vista previa no guarda nada.' );

		$after = Bookings::applyEdit( $booking['id'], array( 'adults' => '6', 'fast_delivery' => '1', 'important_info' => 'Traemos perro grande', 'phone' => '656 765 4321', 'reason' => 'Llamó para avisar' ), $booking['version'] );
		$this->assertSame( 8, $after['participants'] );
		$this->assertSame( 140000 + 20000 + 30000, $after['total_cents'] );
		$this->assertSame( 'Traemos perro grande', $after['important_info'] );
		$this->assertSame( '+526567654321', $after['contact']['phone_e164'] );
		$this->assertSame( $booking['total_cents'], $after['quote']['previous']['total'] );
		$this->assertSame( $paymentsBefore, $wpdb->get_results( 'SELECT * FROM ' . Schema::table( 'payments' ), ARRAY_A ), 'Los movimientos no se reescriben.' );
		$edit = array_values( array_filter( Audit::forEntity( 'booking', $booking['id'] ), static function ( $a ) { return 'edited' === $a['action']; } ) );
		$data = json_decode( $edit[0]['data'], true );
		$this->assertSame( '2', $data['changes']['Adultos']['from'] );
		$this->assertSame( 'Llamó para avisar', $data['reason'] );

		try {
			Bookings::applyEdit( $booking['id'], array( 'adults' => '7' ), $booking['version'] );
			$this->fail( 'Versión vieja.' );
		} catch ( DomainException $e ) {
			$this->assertSame( 'version_conflict', $e->errorCode() );
		}
	}

	/** 7.3: motivo escrito ("Otro") y una corrección adicional solo como excepción auditada. */
	public function test_correction_other_reason_and_exception(): void {
		$service = $this->christmasService();
		$booking = $this->book( $service, self::START )['booking'];
		$v1      = Proofs::upload( $booking['id'], $this->fakeUpload( 'png' ), false );
		try {
			Bookings::requestCorrection( $booking['id'], $v1['proof_id'], 'otro', '', '   ' );
			$this->fail( 'Otro sin texto.' );
		} catch ( DomainException $e ) {
			$this->assertSame( 'reason_required', $e->errorCode() );
		}
		Bookings::requestCorrection( $booking['id'], $v1['proof_id'], 'otro', '', 'La captura está cortada: falta la fecha' );
		$this->assertSame( 'La captura está cortada: falta la fecha', Bookings::proofs( $booking['id'] )[0]['client_reason'] );
		$v2 = Proofs::upload( $booking['id'], $this->fakeUpload( 'png' ), false );
		try {
			Bookings::requestCorrection( $booking['id'], $v2['proof_id'], 'archivo-ilegible' );
			$this->fail( 'Solo una corrección automática.' );
		} catch ( DomainException $e ) {
			$this->assertSame( 'correction_used', $e->errorCode() );
		}
		Bookings::requestCorrection( $booking['id'], $v2['proof_id'], 'archivo-ilegible', '', '', 'Mi banco tuvo una falla ese día' );
		$this->assertSame( BookingStatus::CORRECTION_REQUESTED, Bookings::get( $booking['id'] )['status'] );
		$this->assertContains( 'correction_exception', $this->actions( $booking['id'] ) );
	}
}
