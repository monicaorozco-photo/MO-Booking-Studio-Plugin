<?php
namespace MOBookingStudio\Application;

use MOBookingStudio\Domain\Availability;
use MOBookingStudio\Domain\BookingStatus;
use MOBookingStudio\Domain\DomainException;
use MOBookingStudio\Domain\Interval;
use MOBookingStudio\Infrastructure\Audit;
use MOBookingStudio\Infrastructure\Db;
use MOBookingStudio\Infrastructure\PrivateStorage;
use MOBookingStudio\Infrastructure\Schema;
use MOBookingStudio\Infrastructure\Settings;
use MOBookingStudio\Plugin;

/**
 * Comprobantes de transferencia. Un comprobante es evidencia pendiente de revisión,
 * no un pago verificado.
 */
final class Proofs {
	private const TYPES = array(
		'jpg' => array( 'image/jpeg' ),
		'png' => array( 'image/png' ),
		'pdf' => array( 'application/pdf' ),
	);

	public const RATE_LIMIT_PER_HOUR = 6;

	/**
	 * Recibe un archivo del portal. Si llega antes del vencimiento, la reserva pasa a
	 * "Pago en revisión" y el horario sigue apartado mientras se verifica. Si llega tarde,
	 * se guarda para conciliación manual sin recuperar el horario.
	 *
	 * @param array $file Elemento de $_FILES (tmp_name, name, size, error).
	 * @param bool  $requireUploaded Exigir is_uploaded_file (se desactiva solo en pruebas).
	 * @return array{proof_id:int,late:bool,status:string}
	 */
	public static function upload( int $bookingId, array $file, bool $requireUploaded = true ): array {
		if ( ! PrivateStorage::uploadsEnabled() ) {
			throw new DomainException( 'uploads_disabled', 'Por ahora no podemos recibir archivos aquí. Envíanos tu comprobante por WhatsApp o correo indicando tu número de reserva.', 503 );
		}
		$err = (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE );
		if ( UPLOAD_ERR_INI_SIZE === $err || UPLOAD_ERR_FORM_SIZE === $err ) {
			throw new DomainException( 'file_too_large', self::sizeMessage(), 413 );
		}
		if ( UPLOAD_ERR_OK !== $err || empty( $file['tmp_name'] ) ) {
			throw new DomainException( 'no_file', 'Elige un archivo para subir.', 400 );
		}
		$tmp = (string) $file['tmp_name'];
		if ( $requireUploaded && ! is_uploaded_file( $tmp ) ) {
			throw new DomainException( 'no_file', 'Elige un archivo para subir.', 400 );
		}
		$size = (int) filesize( $tmp );
		if ( $size <= 0 ) {
			throw new DomainException( 'no_file', 'El archivo está vacío.', 400 );
		}
		if ( $size > self::maxBytes() ) {
			throw new DomainException( 'file_too_large', self::sizeMessage(), 413 );
		}
		$ext  = self::detectType( $tmp, (string) ( $file['name'] ?? '' ) );
		$hash = hash_file( 'sha256', $tmp );
		$now  = Plugin::clock()->now();

		$result = Db::transaction(
			static function () use ( $bookingId, $tmp, $ext, $hash, $size, $file, $now ) {
				$booking = Bookings::get( $bookingId, true );
				$service = Services::find( $booking['service_id'] );
				// Mismos bloqueos que una reserva nueva: si la retención vence en este instante
				// y otra persona reserva el horario, solo una de las dos operaciones gana.
				$occupy = new Interval(
					$booking['start']->modify( sprintf( '-%d minutes', (int) ( $service['buffer_before'] ?? 0 ) ) ),
					$booking['end']->modify( sprintf( '+%d minutes', (int) ( $service['buffer_after'] ?? 0 ) ) )
				);
				Bookings::lockDays( $booking['resource_id'], $occupy->utcDays() );

				$wpdb   = Db::wpdb();
				$table  = Schema::table( 'proofs' );
				$count  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE booking_id = %d", $bookingId ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$recent = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE booking_id = %d AND created_at > %s", $bookingId, Db::toSql( $now->modify( '-1 hour' ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( $count >= max( 1, (int) Settings::get( 'proof_max_files' ) ) ) {
					throw new DomainException( 'too_many_files', 'Ya recibimos el máximo de archivos para esta reserva. Escríbenos si necesitas enviar otro.', 429 );
				}
				if ( $recent >= self::RATE_LIMIT_PER_HOUR ) {
					throw new DomainException( 'rate_limited', 'Subiste varios archivos en poco tiempo. Intenta más tarde.', 429 );
				}

				$status  = $booking['status'];
				$late    = false;
				$advance = false;
				if ( BookingStatus::PENDING_PAYMENT === $status ) {
					if ( $booking['expires'] && $booking['expires'] <= $now ) {
						$late = true;
					} else {
						$advance = true;
					}
				} elseif ( BookingStatus::CORRECTION_REQUESTED === $status ) {
					$until = Db::fromSql( $booking['correction_until'] );
					if ( $until && $until <= $now ) {
						$late = true;
					} else {
						$advance = true;
					}
				} elseif ( in_array( $status, array( BookingStatus::PAYMENT_REVIEW, BookingStatus::PENDING_APPROVAL, BookingStatus::CONFIRMED ), true ) ) {
					$advance = false; // Comprobante adicional o de saldo.
				} elseif ( in_array( $status, array( BookingStatus::EXPIRED, BookingStatus::REJECTED ), true ) ) {
					$late = true;
				} else {
					throw new DomainException( 'not_allowed', 'Esta reserva ya no recibe comprobantes. Escríbenos si necesitas ayuda.', 409 );
				}

				$duplicate = $wpdb->get_var( $wpdb->prepare( "SELECT booking_id FROM $table WHERE sha256 = %s AND booking_id <> %d LIMIT 1", $hash, $bookingId ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$stored    = PrivateStorage::store( $tmp, $ext );
				Db::check(
					$wpdb->insert(
						$table,
						array(
							'booking_id'    => $bookingId,
							'stored_name'   => $stored,
							'original_name' => mb_substr( sanitize_file_name( (string) ( $file['name'] ?? 'comprobante.' . $ext ) ), 0, 190 ),
							'mime'          => self::TYPES[ $ext ][0],
							'size_bytes'    => $size,
							'sha256'        => $hash,
							'status'        => 'pending',
							'review_note'   => $duplicate ? 'Revisar: el mismo archivo se subió en otra reserva.' : '',
							'received_late' => $late ? 1 : 0,
							'created_at'    => Db::toSql( $now ),
						)
					),
					'proof_insert'
				);
				$proofId = (int) $wpdb->insert_id;

				if ( $advance ) {
					Bookings::transition( $booking, BookingStatus::PAYMENT_REVIEW, 'Comprobante recibido a tiempo.', array( 'expires_at' => null, 'correction_until' => null ) );
					// Mientras se revisa, el horario sigue apartado aunque pase el plazo original.
					Bookings::holdIndefinitely( $bookingId );
				}
				Audit::log(
					'booking',
					$bookingId,
					'proof_uploaded',
					array(
						'proof_id'  => $proofId,
						'late'      => $late,
						'duplicate' => (bool) $duplicate,
					),
					'client'
				);
				return array(
					'proof_id' => $proofId,
					'late'     => $late,
					'status'   => $advance ? BookingStatus::PAYMENT_REVIEW : $status,
				);
			}
		);
		Notifications::bookingEvent( $result['late'] ? 'proof_received_late' : 'proof_received', Bookings::get( $bookingId ), array( 'proof_id' => $result['proof_id'] ) );
		return $result;
	}

	/**
	 * Determina el tipo real del archivo: extensión permitida, MIME detectado y contenido
	 * coherentes. Rechaza SVG, ejecutables y archivos renombrados.
	 */
	public static function detectType( string $path, string $originalName ): string {
		$ext = strtolower( pathinfo( $originalName, PATHINFO_EXTENSION ) );
		if ( 'jpeg' === $ext ) {
			$ext = 'jpg';
		}
		if ( ! isset( self::TYPES[ $ext ] ) ) {
			throw new DomainException( 'invalid_type', 'Solo aceptamos comprobantes en JPG, PNG o PDF.', 415 );
		}
		$mime = '';
		if ( function_exists( 'finfo_open' ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );
			$mime  = (string) finfo_file( $finfo, $path );
			finfo_close( $finfo );
		}
		if ( ! in_array( $mime, self::TYPES[ $ext ], true ) ) {
			throw new DomainException( 'invalid_type', 'El archivo no coincide con su tipo. Sube una foto (JPG o PNG) o un PDF.', 415 );
		}
		$head = (string) file_get_contents( $path, false, null, 0, 8 );
		if ( 'pdf' === $ext ) {
			if ( 0 !== strpos( $head, '%PDF-' ) ) {
				throw new DomainException( 'invalid_type', 'El PDF no es válido.', 415 );
			}
		} else {
			$info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$want = 'jpg' === $ext ? IMAGETYPE_JPEG : IMAGETYPE_PNG;
			if ( ! $info || $want !== $info[2] ) {
				throw new DomainException( 'invalid_type', 'La imagen no es válida.', 415 );
			}
		}
		return $ext;
	}

	public static function maxBytes(): int {
		return (int) round( max( 1, (float) Settings::get( 'proof_max_mb' ) ) * 1024 * 1024 );
	}

	private static function sizeMessage(): string {
		return sprintf( 'El archivo pesa demasiado. El máximo es de %s MB.', (string) Settings::get( 'proof_max_mb' ) );
	}

	public static function find( int $proofId ): ?array {
		$wpdb = Db::wpdb();
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'proofs' ) . ' WHERE id = %d', $proofId ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ?: null;
	}

	/** Comprobantes pendientes de revisión, los más antiguos primero. */
	public static function queue(): array {
		$wpdb = Db::wpdb();
		return $wpdb->get_results(
			'SELECT p.*, b.public_ref, b.status AS booking_status, b.start_utc, b.deposit_cents, b.total_cents, c.name AS client_name, s.title AS service_title FROM ' . Schema::table( 'proofs' ) . ' p JOIN ' . Schema::table( 'bookings' ) . ' b ON b.id = p.booking_id LEFT JOIN ' . Schema::table( 'clients' ) . ' c ON c.id = b.client_id LEFT JOIN ' . Schema::table( 'services' ) . " s ON s.id = b.service_id WHERE p.status = 'pending' ORDER BY p.created_at ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		) ?: array();
	}

	/**
	 * Envía el archivo a una persona autorizada. Imágenes en línea con sandbox; PDF como
	 * descarga para que el navegador no ejecute su contenido en el sitio.
	 */
	public static function stream( array $proof, bool $download = false ): void {
		$path = PrivateStorage::path( $proof['stored_name'] );
		if ( ! is_readable( $path ) ) {
			wp_die( esc_html__( 'El archivo no está disponible.', 'mo-booking-studio' ), '', array( 'response' => 404 ) );
		}
		nocache_headers();
		header( 'Cache-Control: no-store, private' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Security-Policy: default-src \'none\'; img-src \'self\'; sandbox' );
		header( 'Referrer-Policy: no-referrer' );
		header( 'Content-Type: ' . $proof['mime'] );
		header( 'Content-Length: ' . filesize( $path ) );
		$disposition = ( $download || 'application/pdf' === $proof['mime'] ) ? 'attachment' : 'inline';
		$name        = 'comprobante-' . (int) $proof['id'] . '.' . pathinfo( $proof['stored_name'], PATHINFO_EXTENSION );
		header( 'Content-Disposition: ' . $disposition . '; filename="' . $name . '"' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}
}
