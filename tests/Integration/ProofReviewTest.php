<?php
namespace MOBookingStudio\Tests\Integration;

use MOBookingStudio\Application\Bookings;
use MOBookingStudio\Application\Catalog;
use MOBookingStudio\Application\Portal;
use MOBookingStudio\Application\Proofs;
use MOBookingStudio\Domain\BookingStatus;
use MOBookingStudio\Domain\DomainException;
use MOBookingStudio\Infrastructure\Schema;

/**
 * Requisitos de pago v0.2: AC-PAY-01 a 05, DEC-01, DEC-02, P1, P2 y P5.
 */
final class ProofReviewTest extends IntegrationTestCase {
	private const START = '2026-11-20T17:00:00Z';

	/** AC-PAY-01: a las 11 h 59 min detiene el vencimiento y entra a revisión. */
	public function test_upload_at_11h59_stops_expiry(): void {
		$service = $this->christmasService();
		$booking = $this->book( $service, self::START )['booking'];
		$this->clock->advance( '+11 hours +59 minutes' );
		Proofs::upload( $booking['id'], $this->fakeUpload( 'png' ), false );
		$this->clock->advance( '+2 days' );
		Bookings::expireDue();
		$this->assertSame( BookingStatus::PAYMENT_REVIEW, Bookings::get( $booking['id'] )['status'] );
		$this->assertNull( Catalog::findAvailableSlot( $service, new \DateTimeImmutable( self::START ), $this->clock->now() ) );
	}

	/** Exactamente a las 12 h ya venció (AC-PAY-02). */
	public function test_upload_at_exactly_12h_is_late(): void {
		$service = $this->christmasService();
		$booking = $this->book( $service, self::START )['booking'];
		$this->clock->advance( '+12 hours' );
		$this->expectExceptionMessage( 'Pasaste el tiempo límite' );
		Proofs::upload( $booking['id'], $this->fakeUpload( 'png' ), false );
	}

	/** AC-PAY-03 y DEC-01: una sola corrección de 2 horas, versiones conservadas. */
	public function test_single_two_hour_correction_with_versions(): void {
		$service = $this->christmasService();
		$booking = $this->book( $service, self::START )['booking'];
		$v1      = Proofs::upload( $booking['id'], $this->fakeUpload( 'png' ), false );
		Bookings::startReview( $booking['id'] );
		$this->assertSame( BookingStatus::UNDER_REVIEW, Bookings::get( $booking['id'] )['status'] );

		$booking = Bookings::requestCorrection( $booking['id'], $v1['proof_id'], 'archivo-ilegible', 'Se ve borroso, revisar con banco' );
		$this->assertSame( BookingStatus::CORRECTION_REQUESTED, $booking['status'] );
		$this->assertSame( '2026-10-20 20:00:00', $booking['correction_until'] ); // 2 horas.

		// El portal muestra el motivo de la lista, nunca la nota interna.
		$view = Portal::view( $booking );
		$this->assertSame( 'Archivo ilegible', $view['correction']['reason'] );
		$this->assertStringNotContainsString( 'borroso', json_encode( $view ) );
		$this->assertTrue( $view['can_upload'] );

		$this->clock->advance( '+1 hour +59 minutes' );
		$v2 = Proofs::upload( $booking['id'], $this->fakeUpload( 'pdf' ), false );
		$this->assertSame( 2, $v2['version'] );
		$this->assertCount( 2, Bookings::proofs( $booking['id'] ), 'La versión anterior se conserva.' );
		$byVersion = array_column( Bookings::proofs( $booking['id'] ), 'status', 'version' );
		$this->assertSame( 'replaced', $byVersion[1], 'La versión anterior queda marcada como sustituida.' );
		$this->assertSame( 'pending', $byVersion[2] );
		$this->assertFalse( Portal::view( Bookings::get( $booking['id'] ) )['can_upload'], 'Ya no puede subir más mientras se revisa.' );

		try {
			Bookings::requestCorrection( $booking['id'], $v2['proof_id'], 'archivo-ilegible' );
			$this->fail( 'Solo se permite una corrección.' );
		} catch ( DomainException $e ) {
			$this->assertSame( 'correction_used', $e->errorCode() );
		}
		global $wpdb;
		$this->assertSame( '0', $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'notifications' ) . " WHERE event = 'booking_confirmed'" ), 'Sin invitación ni confirmación.' );
	}

	public function test_correction_window_expires_and_releases(): void {
		$service = $this->christmasService();
		$booking = $this->book( $service, self::START )['booking'];
		$v1      = Proofs::upload( $booking['id'], $this->fakeUpload( 'png' ), false );
		Bookings::requestCorrection( $booking['id'], $v1['proof_id'], 'referencia-incorrecta' );
		$this->clock->advance( '+2 hours' );
		Bookings::expireDue();
		$this->assertSame( BookingStatus::EXPIRED, Bookings::get( $booking['id'] )['status'] );
		$this->assertNotNull( Catalog::findAvailableSlot( $service, new \DateTimeImmutable( self::START ), $this->clock->now() ) );
	}

	/** DEC-01: el rechazo final libera el horario de inmediato. */
	public function test_final_rejection_releases_slot_and_hides_internal_note(): void {
		$service = $this->christmasService();
		$booking = $this->book( $service, self::START )['booking'];
		Proofs::upload( $booking['id'], $this->fakeUpload( 'png' ), false );
		$booking = Bookings::reject( $booking['id'], 'no-recibimos-el-deposito', 'Nota privada' );
		$this->assertSame( BookingStatus::REJECTED, $booking['status'] );
		$this->assertNotNull( Catalog::findAvailableSlot( $service, new \DateTimeImmutable( self::START ), $this->clock->now() ) );
		$this->assertSame( 'rejected', Bookings::proofs( $booking['id'] )[0]['status'] );
		$this->assertStringNotContainsString( 'privada', json_encode( Portal::view( $booking ) ) );
		$this->expectException( DomainException::class );
		Bookings::reject( $booking['id'], 'motivo-que-no-existe' );
	}

	/** AC-PAY-04: archivo vacío y demasiado grande. */
	public function test_empty_and_oversized_files_rejected(): void {
		$service = $this->christmasService();
		$booking = $this->book( $service, self::START )['booking'];
		$empty   = tempnam( sys_get_temp_dir(), 'mo' );
		try {
			Proofs::upload( $booking['id'], array( 'tmp_name' => $empty, 'name' => 'x.png', 'error' => UPLOAD_ERR_OK ), false );
			$this->fail( 'Archivo vacío aceptado.' );
		} catch ( DomainException $e ) {
			$this->assertSame( 'no_file', $e->errorCode() );
		}
		\MOBookingStudio\Infrastructure\Settings::update( array( 'proof_max_mb' => 1 ) );
		$big = tempnam( sys_get_temp_dir(), 'mo' );
		file_put_contents( $big, '%PDF-1.4' . str_repeat( 'x', 1024 * 1024 + 10 ) );
		try {
			Proofs::upload( $booking['id'], array( 'tmp_name' => $big, 'name' => 'x.pdf', 'error' => UPLOAD_ERR_OK ), false );
			$this->fail( 'Archivo grande aceptado.' );
		} catch ( DomainException $e ) {
			$this->assertSame( 413, $e->httpStatus() );
		}
		$this->assertCount( 0, Bookings::proofs( $booking['id'] ) );
	}

	/** AC-PAY-05: la sesión de una reserva no abre otra. */
	public function test_token_of_other_booking_cannot_read_or_upload(): void {
		$service = $this->christmasService();
		$a       = $this->book( $service, self::START );
		$b       = $this->book( $service, '2026-11-20T17:30:00Z', array( 'email' => 'b@example.com' ) );
		$session = \MOBookingStudio\Infrastructure\Tokens::startSession( $a['token'] );
		$id      = \MOBookingStudio\Infrastructure\Tokens::sessionBooking( $session['session'], $session['csrf'], true );
		$this->assertSame( $a['booking']['id'], $id );
		$this->assertNotSame( $b['booking']['id'], $id );
		$this->expectException( DomainException::class );
		\MOBookingStudio\Infrastructure\Tokens::sessionBooking( $session['session'], 'csrf-falso', true );
	}

	/** P2: la copia segura de una imagen se re-codifica y no conserva bytes extra. */
	public function test_sanitized_image_copy(): void {
		if ( ! function_exists( 'imagecreatefromstring' ) ) {
			$this->markTestSkipped( 'GD no disponible en este PHP.' );
		}
		$file = $this->fakeUpload( 'png' );
		file_put_contents( $file['tmp_name'], '<script>alert(1)</script>', FILE_APPEND );
		$copy = Proofs::sanitizedImage( $file['tmp_name'] );
		$this->assertNotNull( $copy );
		$this->assertStringStartsWith( "\x89PNG", $copy );
		$this->assertStringNotContainsString( '<script>', $copy );
	}

	/** 8.4: si el registro en la base de datos falla, el archivo guardado no queda huérfano. */
	public function test_failed_insert_leaves_no_orphan_file(): void {
		$service = $this->christmasService();
		$booking = $this->book( $service, self::START )['booking'];
		$dir     = \MOBookingStudio\Infrastructure\PrivateStorage::dir();
		$count   = static function () use ( $dir ) {
			$n = 0;
			foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) ) as $f ) {
				$n += preg_match( '/[a-f0-9]{40}\.(png|pdf|jpg)$/', $f->getFilename() ) ? 1 : 0;
			}
			return $n;
		};
		$before = $count();
		$break  = static function ( $sql ) {
			return 0 === strpos( ltrim( $sql ), 'INSERT INTO `' . Schema::table( 'proofs' ) ) ? 'INSERT INTO tabla_que_no_existe VALUES (1)' : $sql;
		};
		add_filter( 'query', $break );
		try {
			Proofs::upload( $booking['id'], $this->fakeUpload( 'png' ), false );
			$this->fail( 'Debió fallar el registro.' );
		} catch ( \Throwable $e ) {
			$this->assertSame( $before, $count(), 'El archivo se retiró.' );
		} finally {
			remove_filter( 'query', $break );
		}
		$this->assertCount( 0, Bookings::proofs( $booking['id'] ) );
	}
}
