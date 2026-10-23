<?php
namespace MOBookingStudio\Application;

use MOBookingStudio\Domain\BookingStatus;
use MOBookingStudio\Domain\DomainException;
use MOBookingStudio\Infrastructure\Audit;
use MOBookingStudio\Infrastructure\Db;
use MOBookingStudio\Infrastructure\PrivateStorage;
use MOBookingStudio\Infrastructure\Schema;
use MOBookingStudio\Plugin;

/**
 * Papelera, eliminación definitiva y eliminación de movimientos registrados por error.
 *
 * Decisión de la administradora (beta.3), que sustituye las reglas de "no borrar" de la
 * especificación de preventa: solo personas con acceso al panel pueden eliminar; los
 * clientes nunca. La papelera guarda clientes, servicios, reservas y contratos y permite
 * restaurarlos. Los movimientos de dinero no pasan por la papelera: se eliminan con motivo
 * obligatorio y queda una nota en la bitácora (quién, cuándo, importe y motivo). Al eliminar
 * definitivamente un cliente o una reserva, la bitácora conserva un registro sin datos
 * personales.
 */
final class Trash {
	public const SERVICE_TRASH  = 'trash';
	public const CONTRACT_TRASH = 'trash';

	// ----- Clientes -----

	public static function trashClient( int $id ): void {
		$client = self::client( $id );
		if ( $client['trashed_at'] ) {
			return;
		}
		$active = self::activeBookings( 'client_id', $id );
		if ( $active ) {
			throw new DomainException( 'has_active_bookings', sprintf( 'Tiene %d reserva(s) vigentes. Cancélalas, fusiona al cliente con otro o elimínalo definitivamente.', $active ), 409 );
		}
		Db::wpdb()->update( Schema::table( 'clients' ), array( 'trashed_at' => self::now() ), array( 'id' => $id ) );
		Audit::log( 'client', $id, 'trashed' );
	}

	public static function restoreClient( int $id ): void {
		self::client( $id );
		Db::wpdb()->update( Schema::table( 'clients' ), array( 'trashed_at' => null, 'merged_into' => null ), array( 'id' => $id ) );
		Audit::log( 'client', $id, 'restored_from_trash' );
	}

	/**
	 * Elimina para siempre al cliente con todo su historial: reservas, pagos, comprobantes
	 * (archivos incluidos), copias de contrato, enlaces privados, mensajes y eventos propios de
	 * Google. Se confirma escribiendo el nombre del cliente.
	 *
	 * @return array{bookings:int,payments:int,proofs:int}
	 */
	public static function purgeClient( int $id, string $typedName ): array {
		$client = self::client( $id );
		if ( ! self::sameText( $typedName, (string) $client['name'] ) ) {
			throw new DomainException( 'confirm_required', 'Para eliminar definitivamente escribe el nombre del cliente tal como aparece: ' . $client['name'] . '.', 400, array( 'field' => 'confirm_name' ) );
		}
		$wpdb     = Db::wpdb();
		$bookings = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'bookings' ) . ' WHERE client_id = %d', $id ) ) ?: array() ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// Primero Google: si falla, no se borra nada en el sitio.
		foreach ( $bookings as $bookingId ) {
			Bookings::removeGoogleEvents( $bookingId );
		}
		$totals = array( 'bookings' => count( $bookings ), 'payments' => 0, 'proofs' => 0 );
		$files  = array();
		Db::transaction(
			static function () use ( $wpdb, $id, $bookings, &$totals, &$files ) {
				foreach ( $bookings as $bookingId ) {
					$r                   = self::deleteBookingRows( $bookingId );
					$totals['payments'] += $r['payments'];
					$totals['proofs']   += count( $r['files'] );
					$files               = array_merge( $files, $r['files'] );
				}
				$wpdb->delete( Schema::table( 'client_contacts' ), array( 'client_id' => $id ) );
				$wpdb->delete( Schema::table( 'clients' ), array( 'id' => $id ) );
				$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Schema::table( 'audit' ) . " WHERE entity = 'client' AND entity_id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				Audit::log( 'client', $id, 'purged', $totals );
			}
		);
		self::removeFiles( $files );
		return $totals;
	}

	// ----- Reservas -----

	public static function trashBooking( int $id ): void {
		$booking = Bookings::get( $id );
		if ( ! empty( $booking['trashed_at'] ) ) {
			return;
		}
		if ( BookingStatus::isOpen( $booking['status'] ) ) {
			throw new DomainException( 'booking_active', 'La reserva sigue vigente (' . BookingStatus::label( $booking['status'] ) . '). Cancélala o recházala antes de enviarla a la papelera.', 409 );
		}
		Db::wpdb()->update( Schema::table( 'bookings' ), array( 'trashed_at' => self::now() ), array( 'id' => $id ) );
		Audit::log( 'booking', $id, 'trashed', array( 'ref' => $booking['public_ref'] ) );
	}

	public static function restoreBooking( int $id ): void {
		$booking = Bookings::get( $id );
		$client  = Clients::find( $booking['client_id'] );
		if ( $client && $client['trashed_at'] ) {
			throw new DomainException( 'client_in_trash', 'El cliente de esta reserva está en la papelera. Restaura primero al cliente.', 409 );
		}
		Db::wpdb()->update( Schema::table( 'bookings' ), array( 'trashed_at' => null ), array( 'id' => $id ) );
		Audit::log( 'booking', $id, 'restored_from_trash' );
	}

	/** Solo desde la papelera. */
	public static function purgeBooking( int $id ): array {
		$booking = Bookings::get( $id );
		if ( empty( $booking['trashed_at'] ) ) {
			throw new DomainException( 'not_in_trash', 'Primero envía la reserva a la papelera.', 409 );
		}
		Bookings::removeGoogleEvents( $id );
		$result = Db::transaction(
			static function () use ( $id ) {
				return self::deleteBookingRows( $id );
			}
		);
		self::removeFiles( $result['files'] );
		return array( 'payments' => $result['payments'], 'proofs' => count( $result['files'] ) );
	}

	/**
	 * Borra la reserva y todo lo que depende de ella. Devuelve los archivos de comprobante a
	 * retirar después de confirmar la transacción.
	 *
	 * @return array{payments:int,files:string[]}
	 */
	private static function deleteBookingRows( int $bookingId ): array {
		$wpdb    = Db::wpdb();
		$booking = $wpdb->get_row( $wpdb->prepare( 'SELECT public_ref, status, total_cents, is_test FROM ' . Schema::table( 'bookings' ) . ' WHERE id = %d', $bookingId ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$files   = $wpdb->get_col( $wpdb->prepare( 'SELECT stored_name FROM ' . Schema::table( 'proofs' ) . ' WHERE booking_id = %d', $bookingId ) ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$payments = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Schema::table( 'payments' ) . ' WHERE booking_id = %d', $bookingId ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( array( 'proofs', 'occupancies', 'access_tokens', 'portal_sessions', 'notifications', 'calendar_events', 'sync_jobs', 'conflicts' ) as $t ) {
			$wpdb->delete( Schema::table( $t ), array( 'booking_id' => $bookingId ) );
		}
		$wpdb->delete( Schema::table( 'bookings' ), array( 'id' => $bookingId ) );
		// La bitácora de la reserva puede contener datos de contacto (ediciones): se sustituye por un registro sin datos personales.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Schema::table( 'audit' ) . " WHERE entity = 'booking' AND entity_id = %d", $bookingId ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		Audit::log(
			'booking',
			$bookingId,
			'purged',
			array(
				'ref'      => $booking ? $booking['public_ref'] : '',
				'status'   => $booking ? $booking['status'] : '',
				'total'    => $booking ? (int) $booking['total_cents'] : 0,
				'payments' => $payments,
				'proofs'   => count( $files ),
				'is_test'  => $booking ? (bool) $booking['is_test'] : false,
			)
		);
		return array( 'payments' => $payments, 'files' => $files );
	}

	// ----- Servicios -----

	public static function trashService( int $id ): void {
		$service = Services::get( $id );
		if ( self::SERVICE_TRASH === $service['status'] ) {
			return;
		}
		Db::wpdb()->update( Schema::table( 'services' ), array( 'status' => self::SERVICE_TRASH, 'updated_at' => self::now() ), array( 'id' => $id ) );
		Audit::log( 'service', $id, 'trashed', array( 'from' => $service['status'] ) );
	}

	/** Regresa desactivado (o borrador si nunca se publicó) para revisarlo antes de ofrecerlo. */
	public static function restoreService( int $id ): void {
		$service = Services::get( $id );
		$status  = Services::usageCount( $id ) || Contracts::forService( $service ) ? 'inactive' : 'draft';
		Db::wpdb()->update( Schema::table( 'services' ), array( 'status' => $status, 'updated_at' => self::now() ), array( 'id' => $id ) );
		Audit::log( 'service', $id, 'restored_from_trash', array( 'to' => $status ) );
	}

	public static function purgeService( int $id ): void {
		$service = Services::get( $id );
		if ( self::SERVICE_TRASH !== $service['status'] ) {
			throw new DomainException( 'not_in_trash', 'Primero envía el servicio a la papelera.', 409 );
		}
		$used = Services::usageCount( $id );
		if ( $used ) {
			throw new DomainException( 'has_dependencies', sprintf( 'Este servicio aparece en %d reserva(s), que lo necesitan para mostrar qué se reservó. Se queda en la papelera (no se ofrece a nadie). Si eliminas esas reservas, podrás eliminarlo.', $used ), 409 );
		}
		Services::delete( $id );
	}

	// ----- Contratos -----

	public static function trashContract( int $id ): void {
		$contract = self::contract( $id );
		if ( self::CONTRACT_TRASH === $contract['status'] ) {
			return;
		}
		self::assertContractFree( $id );
		Db::wpdb()->update( Schema::table( 'contracts' ), array( 'status' => self::CONTRACT_TRASH, 'updated_at' => self::now() ), array( 'id' => $id ) );
		Audit::log( 'contract', $id, 'trashed', array( 'from' => $contract['status'] ) );
	}

	public static function restoreContract( int $id ): void {
		self::contract( $id );
		Db::wpdb()->update( Schema::table( 'contracts' ), array( 'status' => 'active', 'updated_at' => self::now() ), array( 'id' => $id ) );
		Audit::log( 'contract', $id, 'restored_from_trash' );
	}

	/** Las reservas conservan su copia exacta del contrato aceptado; solo se borra la plantilla. */
	public static function purgeContract( int $id ): void {
		$contract = self::contract( $id );
		if ( self::CONTRACT_TRASH !== $contract['status'] ) {
			throw new DomainException( 'not_in_trash', 'Primero envía el contrato a la papelera.', 409 );
		}
		self::assertContractFree( $id );
		$wpdb = Db::wpdb();
		$wpdb->delete( Schema::table( 'contract_versions' ), array( 'contract_id' => $id ) );
		$wpdb->delete( Schema::table( 'contracts' ), array( 'id' => $id ) );
		Audit::log( 'contract', $id, 'purged', array( 'name' => $contract['name'] ) );
	}

	private static function assertContractFree( int $id ): void {
		$wpdb  = Db::wpdb();
		$names = array();
		foreach ( $wpdb->get_results( 'SELECT title, config FROM ' . Schema::table( 'services' ), ARRAY_A ) ?: array() as $s ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$config = (array) Db::decode( $s['config'] );
			if ( (int) ( $config['contract_id'] ?? 0 ) === $id ) {
				$names[] = $s['title'];
			}
		}
		if ( $names ) {
			throw new DomainException( 'contract_in_use', 'Este contrato está asignado a: ' . implode( ', ', $names ) . '. Asigna otro contrato a esos servicios antes.', 409 );
		}
	}

	// ----- Movimientos de dinero -----

	/**
	 * Elimina un movimiento registrado por error (sin papelera). Si tenía un reverso, se
	 * elimina también. Devuelve el saldo antes y después, y un aviso si una cita confirmada
	 * se queda sin el anticipo cubierto. "Revertir" sigue disponible para conservar el rastro.
	 *
	 * @return array{removed:int,amount:int,before:array,after:array,warning:string}
	 */
	public static function deleteMovement( int $bookingId, int $paymentId, string $reason ): array {
		$reason = trim( sanitize_text_field( $reason ) );
		if ( mb_strlen( $reason ) < 3 ) {
			throw new DomainException( 'reason_required', 'Escribe por qué se elimina este movimiento.', 400, array( 'field' => 'reason' ) );
		}
		return Db::transaction(
			static function () use ( $bookingId, $paymentId, $reason ) {
				$wpdb    = Db::wpdb();
				$booking = Bookings::get( $bookingId, true );
				$target  = null;
				$linked  = array();
				foreach ( Bookings::movements( $bookingId ) as $m ) {
					if ( (int) $m['id'] === $paymentId ) {
						$target = $m;
					} elseif ( 'reversal' === $m['type'] && (int) $m['reverses_id'] === $paymentId ) {
						$linked[] = (int) $m['id'];
					}
				}
				if ( ! $target ) {
					throw new DomainException( 'not_found', 'Ese movimiento no existe en esta reserva.', 404 );
				}
				$before = Bookings::summary( $booking );
				$ids    = array_merge( array( $paymentId ), $linked );
				$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Schema::table( 'payments' ) . ' WHERE booking_id = %d AND id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', array_merge( array( $bookingId ), $ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$after = Bookings::summary( $booking );
				$user  = wp_get_current_user();
				Audit::log(
					'booking',
					$bookingId,
					'movement_deleted',
					array(
						'type'         => $target['type'],
						'amount'       => (int) $target['amount_cents'],
						'original_note' => $target['note'],
						'recorded_at'  => $target['created_at'],
						'reason'       => $reason,
						'by'           => $user && $user->exists() ? $user->display_name : '',
						'reversals'    => $linked,
						'balance'      => array( $before['balance_cents'], $after['balance_cents'] ),
					)
				);
				$warning = '';
				if ( BookingStatus::CONFIRMED === $booking['status'] && $before['deposit_covered'] && ! $after['deposit_covered'] && $booking['deposit_cents'] > 0 ) {
					$warning = 'Atención: la cita está confirmada y ya no tiene el anticipo cubierto (faltan ' . \MOBookingStudio\Domain\Money::format( $after['deposit_missing'] ) . '). Su estado no cambió; decide si registras el pago correcto o la regresas a pendiente de pago.';
				}
				return array(
					'removed' => count( $ids ),
					'amount'  => (int) $target['amount_cents'],
					'before'  => $before,
					'after'   => $after,
					'warning' => $warning,
				);
			}
		);
	}

	// ----- Lectura de la papelera -----

	public static function counts(): array {
		$wpdb = Db::wpdb();
		return array(
			'clients'   => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'clients' ) . ' WHERE trashed_at IS NOT NULL' ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'bookings'  => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'bookings' ) . ' WHERE trashed_at IS NOT NULL' ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'services'  => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'services' ) . ' WHERE status = %s', self::SERVICE_TRASH ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'contracts' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'contracts' ) . ' WHERE status = %s', self::CONTRACT_TRASH ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	// ----- Utilidades -----

	private static function client( int $id ): array {
		$client = Clients::find( $id );
		if ( ! $client ) {
			throw new DomainException( 'not_found', 'El cliente no existe.', 404 );
		}
		return $client;
	}

	private static function contract( int $id ): array {
		$contract = Contracts::find( $id );
		if ( ! $contract ) {
			throw new DomainException( 'not_found', 'El contrato no existe.', 404 );
		}
		return $contract;
	}

	private static function activeBookings( string $column, int $id ): int {
		$wpdb     = Db::wpdb();
		$statuses = BookingStatus::active();
		$sql      = 'SELECT COUNT(*) FROM ' . Schema::table( 'bookings' ) . " WHERE $column = %d AND status IN (" . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')';
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, array_merge( array( $id ), $statuses ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** Igual sin distinguir mayúsculas ni espacios de más (los acentos sí cuentan). */
	private static function sameText( string $typed, string $expected ): bool {
		$clean = static function ( string $s ): string {
			return mb_strtolower( trim( (string) preg_replace( '/\s+/u', ' ', $s ) ) );
		};
		return '' !== $clean( $typed ) && $clean( $typed ) === $clean( $expected );
	}

	private static function removeFiles( array $files ): void {
		foreach ( $files as $name ) {
			try {
				PrivateStorage::remove( (string) $name );
			} catch ( \Throwable $e ) {
				\MOBookingStudio\Infrastructure\Logger::error( 'No se pudo retirar un comprobante eliminado', array( 'file' => (string) $name ) );
			}
		}
	}

	private static function now(): string {
		return (string) Db::toSql( Plugin::clock()->now() );
	}
}
