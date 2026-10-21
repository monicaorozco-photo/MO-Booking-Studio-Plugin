<?php
namespace MOBookingStudio\Infrastructure;

use MOBookingStudio\Plugin;

/**
 * Migraciones versionadas, pequeñas y repetibles.
 *
 * - La estructura (tablas y columnas) la crea Schema::install con dbDelta, que solo agrega.
 * - Cada paso de datos se marca como hecho en la opción mo_booking_migrations y además
 *   comprueba su propia condición, así que repetirlo no produce efectos dobles.
 * - Nunca se borran ni renombran columnas, tablas o valores en la misma versión (UPD-03).
 * - Al actualizar una instalación con datos, los pasos esperan a que la administradora
 *   confirme que tiene un respaldo (UPD-01). Mientras tanto la reserva en línea se pausa.
 */
final class Migrations {
	public const DONE_OPTION   = 'mo_booking_migrations';
	public const BACKUP_OPTION = 'mo_booking_backup_confirmed';

	/** @return array<string,callable> paso => función */
	private static function steps(): array {
		return array(
			'2_pending_approval_to_confirmed' => array( self::class, 'pendingApprovalToConfirmed' ),
			'2_deadline_at'                   => array( self::class, 'fillDeadlines' ),
			'2_proof_versions'                => array( self::class, 'numberProofVersions' ),
			'2_correction_hours'              => array( self::class, 'correctionHours' ),
		);
	}

	/** ¿Hay una actualización con datos esperando confirmación de respaldo? */
	public static function waitingForBackup(): bool {
		$installed = Schema::installedVersion();
		return '' !== $installed && $installed !== Schema::VERSION && get_option( self::BACKUP_OPTION ) !== Schema::VERSION;
	}

	public static function confirmBackup(): void {
		update_option( self::BACKUP_OPTION, Schema::VERSION, false );
		Audit::log( 'system', 0, 'backup_confirmed', array( 'target_schema' => Schema::VERSION ) );
	}

	/**
	 * Ejecuta estructura y pasos pendientes. Devuelve los pasos ejecutados en esta llamada.
	 *
	 * @return string[]
	 */
	public static function run(): array {
		Schema::install();
		$done = (array) get_option( self::DONE_OPTION, array() );
		$ran  = array();
		foreach ( self::steps() as $name => $step ) {
			if ( in_array( $name, $done, true ) ) {
				continue;
			}
			$count  = (int) call_user_func( $step );
			$done[] = $name;
			$ran[]  = $name;
			update_option( self::DONE_OPTION, $done, false );
			Audit::log( 'system', 0, 'migration', array( 'step' => $name, 'rows' => $count ), 'system', 0 );
		}
		update_option( Schema::OPTION, Schema::VERSION, false );
		return $ran;
	}

	/**
	 * Decisión 2: aprobar el anticipo confirma. Las reservas que quedaron en
	 * "Pendiente de aprobación" pasan a "Confirmada" sin enviar avisos.
	 */
	public static function pendingApprovalToConfirmed(): int {
		$wpdb  = Db::wpdb();
		$table = Schema::table( 'bookings' );
		$ids   = array_map( 'intval', $wpdb->get_col( "SELECT id FROM $table WHERE status = 'pending_approval'" ) ?: array() ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$now   = Db::toSql( Plugin::clock()->now() );
		foreach ( $ids as $id ) {
			$changed = $wpdb->query( $wpdb->prepare( "UPDATE $table SET status = 'confirmed', version = version + 1, updated_at = %s WHERE id = %d AND status = 'pending_approval'", $now, $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $changed ) {
				$wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table( 'occupancies' ) . ' SET expires_at = NULL WHERE booking_id = %d AND released_at IS NULL', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				Audit::log( 'booking', $id, 'status:confirmed', array( 'from' => 'pending_approval', 'reason' => 'Migración 0.2: aprobar el anticipo confirma la cita. Sin avisos.' ), 'system', 0 );
			}
		}
		return count( $ids );
	}

	/** Hora límite original de pago: creación + retención (o el vencimiento ya guardado). */
	public static function fillDeadlines(): int {
		$wpdb  = Db::wpdb();
		$table = Schema::table( 'bookings' );
		$hold  = (int) round( (float) Settings::get( 'hold_hours' ) * 60 );
		$a     = (int) $wpdb->query( "UPDATE $table SET deadline_at = expires_at WHERE deadline_at IS NULL AND expires_at IS NOT NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$b     = (int) $wpdb->query( $wpdb->prepare( "UPDATE $table SET deadline_at = DATE_ADD(created_at, INTERVAL %d MINUTE) WHERE deadline_at IS NULL AND deposit_cents > 0", $hold ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $a + $b;
	}

	/** DEC-01: el plazo de corrección pasa de 12 a 2 horas (si seguía el valor anterior). */
	public static function correctionHours(): int {
		$stored = get_option( Settings::OPTION, array() );
		if ( is_array( $stored ) && isset( $stored['correction_hours'] ) && 12.0 === (float) $stored['correction_hours'] ) {
			Settings::update( array( 'correction_hours' => 2 ) );
			return 1;
		}
		return 0;
	}

	/** Numera las versiones de comprobante existentes por reserva (1, 2, 3...). */
	public static function numberProofVersions(): int {
		$wpdb  = Db::wpdb();
		$table = Schema::table( 'proofs' );
		$rows  = $wpdb->get_results( "SELECT id, booking_id FROM $table ORDER BY booking_id, id", ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n     = array();
		foreach ( $rows as $r ) {
			$n[ $r['booking_id'] ] = ( $n[ $r['booking_id'] ] ?? 0 ) + 1;
			$wpdb->update( $table, array( 'version' => $n[ $r['booking_id'] ] ), array( 'id' => $r['id'] ) );
		}
		return count( $rows );
	}
}
