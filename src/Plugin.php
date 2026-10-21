<?php
namespace MOBookingStudio;

use MOBookingStudio\Admin\Admin;
use MOBookingStudio\Application\Bookings;
use MOBookingStudio\Application\Notifications;
use MOBookingStudio\Domain\Clock;
use MOBookingStudio\Domain\SystemClock;
use MOBookingStudio\Frontend\Frontend;
use MOBookingStudio\Infrastructure\Installer;
use MOBookingStudio\Infrastructure\Schema;
use MOBookingStudio\Rest\Routes;

/**
 * Punto de arranque: registra los hooks del plugin.
 */
final class Plugin {
	/** @var Plugin|null */
	private static $instance;
	/** @var Clock|null */
	private static $clock;

	public static function instance(): self {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Reloj del sistema. Las pruebas lo sustituyen con setClock(); nunca se cambia el
	 * reloj del servidor.
	 */
	public static function clock(): Clock {
		if ( ! self::$clock ) {
			self::$clock = new SystemClock();
		}
		return self::$clock;
	}

	public static function setClock( ?Clock $clock ): void {
		self::$clock = $clock;
	}

	public function boot(): void {
		add_action( 'plugins_loaded', array( $this, 'maybeUpgrade' ), 5 );
		add_action( 'init', array( $this, 'loadTextdomain' ) );
		add_filter( 'cron_schedules', array( $this, 'cronSchedules' ) );
		add_action( Installer::CRON_HOOK, array( $this, 'runQueue' ) );

		( new Routes() )->register();
		( new Frontend() )->register();
		if ( is_admin() ) {
			( new Admin() )->register();
		}
		add_action( 'elementor/widgets/register', array( $this, 'registerElementorWidget' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( Infrastructure\Privacy::class, 'registerExporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( Infrastructure\Privacy::class, 'registerEraser' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'mo-booking', Infrastructure\Cli::class );
		}
	}

	/** Actualización de datos pendiente de confirmar respaldo: reservas en línea en pausa. */
	public static function maintenance(): bool {
		return Infrastructure\Migrations::waitingForBackup();
	}

	public function maybeUpgrade(): void {
		if ( ! Schema::isCurrent() || get_option( 'mo_booking_version' ) !== MO_BOOKING_VERSION ) {
			Installer::upgrade();
			Installer::schedule();
		}
	}

	public function loadTextdomain(): void {
		load_plugin_textdomain( 'mo-booking-studio', false, dirname( plugin_basename( MO_BOOKING_FILE ) ) . '/languages' );
	}

	public function cronSchedules( array $schedules ): array {
		$schedules['mo_booking_five_minutes'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => 'Cada 5 minutos (MO Booking Studio)',
		);
		return $schedules;
	}

	/**
	 * Tarea programada: vencimientos y cola de mensajes. También se puede ejecutar desde
	 * el servidor con "wp mo-booking process" cada minuto si el hosting lo permite.
	 */
	public function runQueue(): void {
		if ( self::maintenance() || get_transient( 'mo_booking_queue_lock' ) ) {
			return;
		}
		set_transient( 'mo_booking_queue_lock', 1, 4 * MINUTE_IN_SECONDS );
		try {
			Bookings::expireDue( 200 );
			Notifications::processQueue( 50 );
		} finally {
			delete_transient( 'mo_booking_queue_lock' );
		}
	}

	public function registerElementorWidget( $widgetsManager ): void {
		if ( class_exists( '\Elementor\Widget_Base' ) ) {
			$widgetsManager->register( new Elementor\BookingWidget() );
		}
	}
}
