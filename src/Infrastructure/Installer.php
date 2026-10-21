<?php
namespace MOBookingStudio\Infrastructure;

/**
 * Activación, actualización y desactivación. Ninguna de estas acciones borra datos.
 */
final class Installer {
	public const CRON_HOOK = 'mo_booking_process_queue';

	public static function activate(): void {
		if ( version_compare( PHP_VERSION, MO_BOOKING_MIN_PHP, '<' ) ) {
			mo_booking_reject_activation();
			return;
		}
		self::upgrade();
		Capabilities::install();
		PrivateStorage::ensure();
		self::schedule();
	}

	/**
	 * Migraciones idempotentes. Se llama al activar y si cambia la versión del esquema.
	 * Si hay datos de una versión anterior, espera la confirmación de respaldo.
	 */
	public static function upgrade(): void {
		if ( Migrations::waitingForBackup() ) {
			return;
		}
		Migrations::run();
		Capabilities::install();
		self::ensureDefaultResource();
		if ( false === get_option( Settings::OPTION ) ) {
			add_option( Settings::OPTION, Settings::defaults(), '', false );
		}
		update_option( 'mo_booking_version', MO_BOOKING_VERSION, false );
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, 'mo_booking_five_minutes', self::CRON_HOOK );
		}
	}

	/** Recurso compartido inicial: la agenda de la fotógrafa o el estudio. */
	private static function ensureDefaultResource(): void {
		$wpdb  = Db::wpdb();
		$table = Schema::table( 'resources' );
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( 0 === $count ) {
			$wpdb->insert(
				$table,
				array(
					'name'       => 'Agenda principal',
					'active'     => 1,
					'created_at' => gmdate( 'Y-m-d H:i:s' ),
				)
			);
		}
	}
}
