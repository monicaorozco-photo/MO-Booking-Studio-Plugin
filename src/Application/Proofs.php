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
		list( $tmp, $ext, $hash, $size ) = self::validateFile( $file, $requireUploaded );
		$now    = Plugin::clock()->now();
		$stored = null;
		try {
			$result = self::storeAndRegister( $bookingId, $tmp, $ext, $hash, $size, $file, $now, $stored );
		} catch ( \Throwable $e ) {
			// 8.4: si el registro falló después de guardar el archivo, no se deja un huérfano.
			if ( $stored ) {
				PrivateStorage::remove( $stored );
			}
			throw $e;
		}
		Notifications::bookingEvent( 'proof_received', Bookings::get( $bookingId ), array( 'proof_id' => $result['proof_id'] ) );
		return $result;
	}

	/**
	 * Comprobante que la administradora recibió por otro medio (WhatsApp, correo) y adjunta
	 * desde el panel. Mismas validaciones de archivo y almacenamiento privado; queda
	 * registrado quién lo subió. No depende del plazo de carga de la persona: si la reserva
	 * esperaba el pago, pasa a "Comprobante recibido" y el horario sigue apartado; en otros
	 * estados solo se adjunta como evidencia.
	 *
	 * @return array{proof_id:int,version:int,status:string}
	 */
	public static function uploadByAdmin( int $bookingId, array $file, string $note = '', bool $requireUploaded = true ): array {
		list( $tmp, $ext, $hash, $size ) = self::validateFile( $file, $requireUploaded );
		$now    = Plugin::clock()->now();
		$stored = null;
		try {
			return Db::transaction(
				static function () use ( $bookingId, $tmp, $ext, $hash, $size, $file, $now, $note, &$stored ) {
					$booking = Bookings::get( $bookingId, true );
					$wpdb    = Db::wpdb();
					$table   = Schema::table( 'proofs' );
					$version = 1 + (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE booking_id = %d", $bookingId ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$user    = wp_get_current_user();
					$stored  = PrivateStorage::store( $tmp, $ext );
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
								'review_note'   => mb_substr( trim( sanitize_text_field( $note ) ), 0, 255 ),
								'received_late' => 0,
								'version'       => $version,
								'source'        => 'admin',
								'uploaded_by'   => get_current_user_id(),
								'created_at'    => Db::toSql( $now ),
							)
						),
						'proof_insert'
					);
					$proofId = (int) $wpdb->insert_id;
					$status  = $booking['status'];
					if ( in_array( $status, array( BookingStatus::PENDING_PAYMENT, BookingStatus::CORRECTION_REQUESTED ), true ) ) {
						$wpdb->query( $wpdb->prepare( "UPDATE $table SET status = 'replaced' WHERE booking_id = %d AND id <> %d AND status IN ('pending', 'correction')", $bookingId, $proofId ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						Bookings::transition( $booking, BookingStatus::PAYMENT_REVIEW, 'Comprobante adjuntado desde el panel.', array( 'expires_at' => null, 'correction_until' => null ) );
						Bookings::holdIndefinitely( $bookingId );
						$status = BookingStatus::PAYMENT_REVIEW;
					}
					Audit::log(
						'booking',
						$bookingId,
						'proof_uploaded_admin',
						array(
							'proof_id' => $proofId,
							'version'  => $version,
							'by'       => $user && $user->exists() ? $user->display_name : '',
							'note'     => $note,
						)
					);
					return array( 'proof_id' => $proofId, 'version' => $version, 'status' => $status );
				}
			);
		} catch ( \Throwable $e ) {
			if ( $stored ) {
				PrivateStorage::remove( $stored );
			}
			throw $e;
		}
	}

	/**
	 * Validaciones comunes del archivo: almacenamiento privado verificado, tamaño y tipo real.
	 *
	 * @return array{0:string,1:string,2:string,3:int} ruta temporal, extensión, sha256 y tamaño.
	 */
	private static function validateFile( array $file, bool $requireUploaded ): array {
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
		$ext = self::detectType( $tmp, (string) ( $file['name'] ?? '' ) );
		return array( $tmp, $ext, (string) hash_file( 'sha256', $tmp ), $size );
	}

	/** @param string|null $stored Recibe el nombre interno en cuanto el archivo se guarda. */
	private static function storeAndRegister( int $bookingId, string $tmp, string $ext, string $hash, int $size, array $file, \DateTimeImmutable $now, ?string &$stored ): array {
		return Db::transaction(
			static function () use ( $bookingId, $tmp, $ext, $hash, $size, $file, $now, &$stored ) {
				$booking = Bookings::get( $bookingId, true );
				$service = Services::find( $booking['service_id'] );
				// Mismos bloqueos que una reserva nueva: si la retención vence en este instante
				// y otra persona reserva el horario, solo una de las dos operaciones gana.
				$occupy = new Interval(
					$booking['start']->modify( sprintf( '-%d minutes', (int) ( $service['buffer_before'] ?? 0 ) ) ),
					$booking['end']->modify( sprintf( '+%d minutes', (int) ( $service['buffer_after'] ?? 0 ) ) )
				);
				Bookings::lockDays( $booking['resource_id'], $occupy->utcDays() );

				$window = self::uploadWindow( $booking, $now );
				if ( ! $window['open'] ) {
					throw new DomainException( 'late' === $window['reason'] ? 'upload_late' : 'upload_closed', $window['message'], 'late' === $window['reason'] ? 410 : 409 );
				}
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
				$version = 1 + (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE booking_id = %d", $bookingId ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

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
							'received_late' => 0,
							'version'       => $version,
							'created_at'    => Db::toSql( $now ),
						)
					),
					'proof_insert'
				);
				$proofId = (int) $wpdb->insert_id;
				if ( ! empty( $window['balance'] ) ) {
					// beta.6.5: abono o saldo de una cita confirmada: la cita sigue igual y el
					// comprobante queda "Por revisar" junto a los anteriores.
					$newStatus = $status;
				} else {
					// 7.3: la versión anterior queda marcada como sustituida y deja de presentarse como vigente.
					$wpdb->query( $wpdb->prepare( "UPDATE $table SET status = 'replaced' WHERE booking_id = %d AND id <> %d AND status IN ('pending', 'correction')", $bookingId, $proofId ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

					// DEC-02: recibido a tiempo, la reserva no vence mientras está en revisión.
					Bookings::transition( $booking, BookingStatus::PAYMENT_REVIEW, 1 === $version ? 'Comprobante recibido a tiempo.' : 'Comprobante sustituto recibido a tiempo.', array( 'expires_at' => null, 'correction_until' => null ) );
					Bookings::holdIndefinitely( $bookingId );
					$newStatus = BookingStatus::PAYMENT_REVIEW;
				}
				Audit::log(
					'booking',
					$bookingId,
					'proof_uploaded',
					array(
						'proof_id'  => $proofId,
						'version'   => $version,
						'from'      => $status,
						'duplicate' => (bool) $duplicate,
					),
					'client'
				);
				return array(
					'proof_id' => $proofId,
					'version'  => $version,
					'late'     => false,
					'status'   => $newStatus,
				);
			}
		);
	}

	public const LATE_MESSAGE = 'Pasaste el tiempo límite para enviar tu anticipo. Realiza de nuevo tu reserva.';

	/**
	 * ¿Puede la persona subir un comprobante ahora? (P1: la capacidad de carga caduca
	 * aunque el enlace siga sirviendo para consultar el estado.)
	 *
	 * - Pendiente de pago: hasta la hora límite (12 h desde que reservó; también tras un pago parcial).
	 * - Corrección solicitada: una sola sustitución dentro del plazo de corrección.
	 * - Después de esos plazos, o si la reserva venció o se rechazó: carga tardía rechazada.
	 * - Con un comprobante en revisión o una cita confirmada: no hay carga.
	 *
	 * @return array{open:bool,reason:?string,message:string,until:?\DateTimeImmutable}
	 */
	public static function uploadWindow( array $booking, \DateTimeImmutable $now ): array {
		$status = $booking['status'];
		if ( BookingStatus::PENDING_PAYMENT === $status ) {
			$until = $booking['expires'] ?: Db::fromSql( $booking['deadline_at'] ?? null );
			if ( $until && $until > $now ) {
				return array( 'open' => true, 'reason' => null, 'message' => '', 'until' => $until );
			}
			return array( 'open' => false, 'reason' => 'late', 'message' => self::LATE_MESSAGE, 'until' => null );
		}
		if ( BookingStatus::CORRECTION_REQUESTED === $status ) {
			$until = Db::fromSql( $booking['correction_until'] );
			if ( $until && $until > $now ) {
				return array( 'open' => true, 'reason' => null, 'message' => '', 'until' => $until );
			}
			return array( 'open' => false, 'reason' => 'late', 'message' => self::LATE_MESSAGE, 'until' => null );
		}
		if ( in_array( $status, array( BookingStatus::EXPIRED, BookingStatus::REJECTED ), true ) ) {
			return array( 'open' => false, 'reason' => 'late', 'message' => self::LATE_MESSAGE, 'until' => null );
		}
		// beta.6.5: con la cita confirmada (o realizada) y saldo por cubrir, la clienta sube los
		// comprobantes de sus abonos y del saldo. Al quedar liquidada deja de recibirlos.
		if ( in_array( $status, array( BookingStatus::CONFIRMED, BookingStatus::COMPLETED ), true ) ) {
			if ( Bookings::summary( $booking )['balance_cents'] > 0 ) {
				return array( 'open' => true, 'reason' => null, 'message' => '', 'until' => null, 'balance' => true );
			}
			return array( 'open' => false, 'reason' => 'paid', 'message' => 'Tu sesión está liquidada. ¡Gracias!', 'until' => null );
		}
		if ( in_array( $status, array( BookingStatus::PAYMENT_REVIEW, BookingStatus::UNDER_REVIEW ), true ) ) {
			return array( 'open' => false, 'reason' => 'review', 'message' => 'Ya recibimos tu comprobante y lo estamos revisando. Te avisaremos del resultado.', 'until' => null );
		}
		return array( 'open' => false, 'reason' => 'closed', 'message' => 'Esta reserva ya no recibe comprobantes en línea. Escríbenos si necesitas ayuda.', 'until' => null );
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
	 * Envía el archivo a una persona autorizada (P2, PAY-07). Sin antivirus en el hosting,
	 * nunca se muestra el archivo original dentro del navegador:
	 * - "Ver": las imágenes se re-codifican en el servidor (se descarta cualquier contenido
	 *   oculto) y se muestra esa copia. Si el servidor no tiene GD, se descarga.
	 * - "Descargar" y cualquier PDF: descarga del original con nombre neutro.
	 */
	public static function stream( array $proof, bool $download = false ): void {
		$path = PrivateStorage::path( $proof['stored_name'] );
		if ( ! is_readable( $path ) ) {
			wp_die( esc_html__( 'El archivo no está disponible.', 'mo-booking-studio' ), '', array( 'response' => 404 ) );
		}
		nocache_headers();
		header( 'Cache-Control: no-store, private' );
		header( 'X-Content-Type-Options: nosniff' );
		header( "Content-Security-Policy: default-src 'none'; img-src 'self'; sandbox" );
		header( 'Referrer-Policy: no-referrer' );
		$ext = pathinfo( $proof['stored_name'], PATHINFO_EXTENSION );

		if ( ! $download && 'application/pdf' !== $proof['mime'] ) {
			$safe = self::sanitizedImage( $path );
			if ( null !== $safe ) {
				header( 'Content-Type: image/png' );
				header( 'Content-Length: ' . strlen( $safe ) );
				header( 'Content-Disposition: inline; filename="comprobante-' . (int) $proof['id'] . '-copia.png"' );
				echo $safe; // phpcs:ignore WordPress.Security.EscapeOutput
				exit;
			}
		}
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'Content-Disposition: attachment; filename="comprobante-' . (int) $proof['id'] . '.' . $ext . '"' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/** Copia re-codificada en PNG (solo píxeles), o null si no se puede generar. */
	public static function sanitizedImage( string $path ): ?string {
		if ( ! function_exists( 'imagecreatefromstring' ) || ! function_exists( 'imagepng' ) ) {
			return null;
		}
		$data = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$img  = false === $data ? false : @imagecreatefromstring( $data ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $img ) {
			return null;
		}
		ob_start();
		imagepng( $img );
		imagedestroy( $img );
		return (string) ob_get_clean();
	}

}
