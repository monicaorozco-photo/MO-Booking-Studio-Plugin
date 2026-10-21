<?php
namespace MOBookingStudio\Admin;

use MOBookingStudio\Application\Bookings;
use MOBookingStudio\Application\Catalog;
use MOBookingStudio\Application\Clients;
use MOBookingStudio\Application\Notifications;
use MOBookingStudio\Application\Proofs;
use MOBookingStudio\Application\Schedules;
use MOBookingStudio\Application\Services;
use MOBookingStudio\Application\TestData;
use MOBookingStudio\Domain\DomainException;
use MOBookingStudio\Domain\Money;
use MOBookingStudio\Infrastructure\Capabilities as Cap;
use MOBookingStudio\Infrastructure\PrivateStorage;
use MOBookingStudio\Infrastructure\Settings;

/**
 * Menú del panel y despachador de acciones. Cada acción valida su capacidad y su nonce;
 * ocultar un botón no es control de acceso.
 */
final class Admin {
	/** Acción => capacidad requerida. */
	private const ACTIONS = array(
		'save_settings'       => Cap::SETTINGS,
		'verify_storage'      => Cap::SETTINGS,
		'create_pages'        => Cap::SETTINGS,
		'load_test_data'      => Cap::SETTINGS,
		'clear_test_data'     => Cap::SETTINGS,
		'save_templates'      => Cap::SETTINGS,
		'save_service'        => Cap::SETTINGS,
		'save_category'       => Cap::SETTINGS,
		'save_rule'           => Cap::SETTINGS,
		'delete_rule'         => Cap::SETTINGS,
		'save_policy'         => Cap::SETTINGS,
		'remove_policy'       => Cap::SETTINGS,
		'save_weekly'         => Cap::SETTINGS,
		'save_date'           => Cap::SETTINGS,
		'remove_date'         => Cap::SETTINGS,
		'close_date'          => Cap::SETTINGS,
		'reopen_date'         => Cap::SETTINGS,
		'add_block'           => Cap::SETTINGS,
		'remove_row'          => Cap::SETTINGS,
		'bulk_dates'          => Cap::SETTINGS,
		'save_client'         => Cap::CLIENTS,
		'approve_payment'     => Cap::PAYMENTS,
		'request_correction'  => Cap::PAYMENTS,
		'add_movement'        => Cap::PAYMENTS,
		'proof_file'          => Cap::PAYMENTS,
		'confirm'             => Cap::BOOKINGS,
		'reject'              => Cap::BOOKINGS,
		'cancel'              => Cap::BOOKINGS,
		'reschedule'          => Cap::BOOKINGS,
		'recalculate'         => Cap::BOOKINGS,
		'complete'            => Cap::BOOKINGS,
		'no_show'             => Cap::BOOKINGS,
		'reopen'              => Cap::BOOKINGS,
		'add_note'            => Cap::BOOKINGS,
		'rotate_link'         => Cap::BOOKINGS,
		'whatsapp_open'       => Cap::BOOKINGS,
		'whatsapp_sent'       => Cap::BOOKINGS,
		'create_booking'      => Cap::BOOKINGS,
		'retry_notification'  => Cap::BOOKINGS,
		'process_queue'       => Cap::BOOKINGS,
	);

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_mo_booking', array( $this, 'dispatch' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_notices', array( $this, 'globalNotices' ) );
		add_action( 'admin_notices', array( $this, 'upgradeNotice' ) );
		add_action( 'admin_post_mo_booking_confirm_backup', array( $this, 'confirmBackup' ) );
	}

	public function menu(): void {
		$icon = 'dashicons-camera';
		if ( \MOBookingStudio\Plugin::maintenance() ) {
			add_menu_page( 'MO Booking Studio', 'Reservas', Cap::SETTINGS, 'mo-booking', array( self::class, 'renderUpgradeGate' ), $icon, 26 );
			return;
		}
		add_menu_page( 'MO Booking Studio', 'Reservas', Cap::AGENDA, 'mo-booking', array( Pages\Dashboard::class, 'render' ), $icon, 26 );
		add_submenu_page( 'mo-booking', 'Inicio', 'Inicio', Cap::AGENDA, 'mo-booking', array( Pages\Dashboard::class, 'render' ) );
		add_submenu_page( 'mo-booking', 'Agenda', 'Agenda', Cap::AGENDA, 'mo-booking-agenda', array( Pages\Agenda::class, 'render' ) );
		add_submenu_page( 'mo-booking', 'Reservas', 'Reservas', Cap::BOOKINGS, 'mo-booking-bookings', array( Pages\BookingsPage::class, 'render' ) );
		add_submenu_page( 'mo-booking', 'Servicios', 'Servicios', Cap::SETTINGS, 'mo-booking-services', array( Pages\ServicesPage::class, 'render' ) );
		add_submenu_page( 'mo-booking', 'Disponibilidad', 'Disponibilidad', Cap::SETTINGS, 'mo-booking-availability', array( Pages\AvailabilityPage::class, 'render' ) );
		add_submenu_page( 'mo-booking', 'Clientes', 'Clientes', Cap::CLIENTS, 'mo-booking-clients', array( Pages\ClientsPage::class, 'render' ) );
		add_submenu_page( 'mo-booking', 'Pagos y recibos', 'Pagos y recibos', Cap::PAYMENTS, 'mo-booking-payments', array( Pages\PaymentsPage::class, 'render' ) );
		add_submenu_page( 'mo-booking', 'Tarifas y descuentos', 'Tarifas y descuentos', Cap::SETTINGS, 'mo-booking-rates', array( Pages\RatesPage::class, 'render' ) );
		add_submenu_page( 'mo-booking', 'Políticas', 'Políticas', Cap::SETTINGS, 'mo-booking-policies', array( Pages\PoliciesPage::class, 'render' ) );
		add_submenu_page( 'mo-booking', 'Notificaciones', 'Notificaciones', Cap::SETTINGS, 'mo-booking-notifications', array( Pages\NotificationsPage::class, 'render' ) );
		add_submenu_page( 'mo-booking', 'Finanzas (próximamente)', 'Finanzas · próx.', Cap::FINANCE, 'mo-booking-finance', array( Pages\UpcomingPage::class, 'finance' ) );
		add_submenu_page( 'mo-booking', 'Galerías (próximamente)', 'Galerías · próx.', Cap::GALLERIES, 'mo-booking-galleries', array( Pages\UpcomingPage::class, 'galleries' ) );
		add_submenu_page( 'mo-booking', 'Ajustes', 'Ajustes', Cap::SETTINGS, 'mo-booking-settings', array( Pages\SettingsPage::class, 'render' ) );
	}

	public function assets( string $hook ): void {
		if ( false === strpos( $hook, 'mo-booking' ) ) {
			return;
		}
		wp_enqueue_style( 'mo-booking-admin', MO_BOOKING_URL . 'assets/css/admin.css', array(), MO_BOOKING_VERSION );
		wp_enqueue_script( 'mo-booking-admin', MO_BOOKING_URL . 'assets/js/admin.js', array(), MO_BOOKING_VERSION, true );
		if ( false !== strpos( $hook, 'mo-booking-services' ) ) {
			wp_enqueue_media();
		}
	}

	/** Avisos de configuración importantes, solo en las páginas del plugin. */
	public function globalNotices(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, 'mo-booking' ) || ! current_user_can( Cap::SETTINGS ) ) {
			return;
		}
		$notices = array();
		if ( 'live' !== Settings::get( 'email_mode' ) ) {
			$notices[] = 'Los correos están en <strong>modo simulación</strong>: se registran pero no se envían. Actívalos en Ajustes cuando el correo autenticado esté configurado.';
		}
		if ( ! PrivateStorage::uploadsEnabled() ) {
			$notices[] = 'La carga de comprobantes está <strong>deshabilitada</strong> hasta verificar que los archivos son privados. Ve a Ajustes &gt; Almacenamiento privado.';
		}
		$tz = Settings::timezoneInfo();
		if ( $tz['fallback'] ) {
			$notices[] = sprintf( 'Este servidor no conoce la zona %1$s; se usa %2$s, que tiene las mismas reglas de horario. Las horas son correctas.', esc_html( $tz['requested'] ), esc_html( $tz['effective'] ) );
		}
		foreach ( $notices as $n ) {
			echo '<div class="notice notice-warning"><p>' . wp_kses( $n, array( 'strong' => array() ) ) . '</p></div>';
		}
	}

	/** Aviso en todo el panel mientras la actualización espera el respaldo. */
	public function upgradeNotice(): void {
		if ( ! \MOBookingStudio\Plugin::maintenance() || ! current_user_can( Cap::SETTINGS ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && false !== strpos( (string) $screen->id, 'mo-booking' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p><strong>MO Booking Studio necesita actualizar su base de datos.</strong> Las reservas en línea están en pausa hasta entonces. <a href="' . esc_url( admin_url( 'admin.php?page=mo-booking' ) ) . '">Revisar y actualizar</a></p></div>';
	}

	public static function renderUpgradeGate(): void {
		Ui::header( 'Actualización de datos pendiente' );
		$installed = \MOBookingStudio\Infrastructure\Schema::installedVersion();
		echo '<div class="mo-card"><p>Instalaste una versión nueva del plugin (' . esc_html( MO_BOOKING_VERSION ) . '). Antes de que actualice tus datos (esquema ' . esc_html( $installed ) . ' → ' . esc_html( \MOBookingStudio\Infrastructure\Schema::VERSION ) . ') necesitas un respaldo. Mientras tanto <strong>las reservas en línea están en pausa</strong> y nada se ha modificado.</p>';
		echo '<ol><li>Ve a <em>Ajustes → UpdraftPlus Backups</em> y pulsa <strong>Respaldar ahora</strong> incluyendo base de datos, plugins y uploads.</li><li>Descarga los archivos del respaldo a tu computadora y comprueba que se abren (que no pesen 0 KB).</li><li>Si guardas comprobantes en una carpeta propia (MO_BOOKING_PRIVATE_DIR), respáldala también.</li><li>Vuelve aquí y confirma.</li></ol>';
		echo '<p>Esta actualización solo agrega información: no borra reservas, clientes, pagos ni comprobantes. Si necesitas volver a la versión anterior, sigue <em>docs/respaldo-y-reversion.md</em>.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="mo_booking_confirm_backup">' . wp_nonce_field( 'mo_booking_confirm_backup', '_mo_nonce', true, false ) . // phpcs:ignore WordPress.Security.EscapeOutput
			'<label class="mo-check"><input type="checkbox" name="backup_ok" value="1" required> Tengo un respaldo descargado y verificado</label><p><button class="button button-primary">Actualizar datos</button></p></form></div>';
		Ui::footer();
	}

	public function confirmBackup(): void {
		if ( ! current_user_can( Cap::SETTINGS ) ) {
			wp_die( 'No tienes permiso para esta acción.', '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'mo_booking_confirm_backup', '_mo_nonce' );
		if ( empty( $_POST['backup_ok'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			Ui::flash( 'error', 'Marca la casilla para confirmar que tienes respaldo.' );
		} else {
			\MOBookingStudio\Infrastructure\Migrations::confirmBackup();
			\MOBookingStudio\Infrastructure\Installer::upgrade();
			\MOBookingStudio\Infrastructure\Installer::schedule();
			Ui::flash( 'success', 'Datos actualizados. Las reservas en línea están activas de nuevo.' );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=mo-booking' ) );
		exit;
	}

	public function dispatch(): void {
		$do = isset( $_REQUEST['do'] ) ? sanitize_key( wp_unslash( $_REQUEST['do'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! isset( self::ACTIONS[ $do ] ) ) {
			wp_die( 'Acción no válida.', '', array( 'response' => 400 ) );
		}
		if ( ! current_user_can( self::ACTIONS[ $do ] ) ) {
			wp_die( 'No tienes permiso para esta acción.', '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'mo_booking_' . $do, '_mo_nonce' );
		$p        = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
		$redirect = wp_get_referer() ?: Ui::url( 'mo-booking' );
		try {
			$redirect = $this->handle( $do, $p, $redirect );
		} catch ( DomainException $e ) {
			Ui::flash( 'error', $e->getMessage() );
			Ui::rememberInput( $p );
		}
		wp_safe_redirect( $redirect );
		exit;
	}

	private function handle( string $do, array $p, string $redirect ): string {
		$bookingId = (int) ( $p['booking_id'] ?? 0 );
		$bookingUrl = Ui::url( 'mo-booking-bookings', array( 'booking' => $bookingId ) );
		switch ( $do ) {
			case 'save_settings':
				Pages\SettingsPage::save( $p );
				Ui::flash( 'success', 'Ajustes guardados.' );
				return $redirect;

			case 'verify_storage':
				$r = PrivateStorage::verify();
				$messages = array(
					'ok'     => array( 'success', 'Almacenamiento privado verificado: los comprobantes no se pueden abrir con una URL directa. La carga de comprobantes quedó habilitada.' ),
					'public' => array( 'error', 'Los archivos de la carpeta privada SE PUEDEN descargar por URL. La carga queda deshabilitada. Define MO_BOOKING_PRIVATE_DIR en wp-config.php con una carpeta fuera de public_html (ver README).' ),
					'error'  => array( 'warning', 'No se pudo completar la verificación (el servidor no respondió a sí mismo o la carpeta no se puede escribir). La carga queda deshabilitada; revisa el README.' ),
				);
				Ui::flash( $messages[ $r ][0], $messages[ $r ][1] );
				return $redirect;

			case 'create_pages':
				Pages\SettingsPage::createPages();
				Ui::flash( 'success', 'Páginas creadas como borrador. Revísalas y publícalas.' );
				return $redirect;

			case 'load_test_data':
				$n = TestData::load();
				Ui::flash( 'success', sprintf( 'Datos de prueba creados (%d espacios). Están marcados como PRUEBA y no envían mensajes.', $n ) );
				return $redirect;

			case 'clear_test_data':
				TestData::clear();
				Ui::flash( 'success', 'Datos de prueba eliminados. Tus datos reales no se tocaron.' );
				return $redirect;

			case 'save_templates':
				Notifications::saveTemplates( (array) ( $p['tpl'] ?? array() ) );
				Ui::flash( 'success', 'Plantillas guardadas.' );
				return $redirect;

			case 'save_service':
				$id = Pages\ServicesPage::save( $p );
				Ui::flash( 'success', 'Servicio guardado.' );
				return Ui::url( 'mo-booking-services', array( 'service' => $id ) );

			case 'save_category':
				Services::saveCategory( (string) ( $p['name'] ?? '' ), ! empty( $p['category_id'] ) ? (int) $p['category_id'] : null );
				Ui::flash( 'success', 'Categoría guardada.' );
				return $redirect;

			case 'save_rule':
				Pages\RatesPage::save( $p );
				Ui::flash( 'success', 'Regla guardada.' );
				return Ui::url( 'mo-booking-rates', array( 'service' => (int) $p['service_id'] ) );

			case 'delete_rule':
				Services::deleteRule( (int) $p['rule_id'] );
				Ui::flash( 'success', 'Regla eliminada. Las reservas existentes conservan su precio.' );
				return $redirect;

			case 'save_policy':
				Pages\PoliciesPage::save( $p );
				Ui::flash( 'success', 'Política guardada como nueva versión. Las reservas existentes conservan la versión que aceptaron.' );
				return $redirect;

			case 'remove_policy':
				Services::removeOwnPolicy( (int) $p['service_id'], (string) $p['policy_action'] );
				Ui::flash( 'success', 'El servicio vuelve a usar la política general.' );
				return $redirect;

			case 'save_weekly':
				$weekly = array();
				foreach ( Ui::weekdays() as $d => $label ) {
					$weekly[ $d ] = Pages\AvailabilityPage::parseWindows( (string) ( $p['weekly'][ $d ] ?? '' ) );
				}
				Schedules::saveWeekly( (int) $p['resource_id'], $weekly );
				Ui::flash( 'success', 'Horario semanal guardado.' );
				return $redirect;

			case 'save_date':
				Schedules::saveDate( (int) $p['resource_id'], (string) $p['date'], Pages\AvailabilityPage::parseWindows( (string) $p['windows'] ) );
				Ui::flash( 'success', 'Horario de la fecha guardado.' );
				return $redirect;

			case 'remove_date':
				Schedules::removeDate( (int) $p['resource_id'], (string) $p['date'] );
				Ui::flash( 'success', 'Fecha eliminada: vuelve a usar el horario semanal.' );
				return $redirect;

			case 'close_date':
				Schedules::closeDate( (int) $p['resource_id'], (string) $p['date'], sanitize_text_field( (string) ( $p['note'] ?? '' ) ) );
				Ui::flash( 'success', 'Fecha cerrada. Las citas existentes no se movieron; revisa la agenda.' );
				return $redirect;

			case 'reopen_date':
				Schedules::reopenDate( (int) $p['resource_id'], (string) $p['date'] );
				Ui::flash( 'success', 'Fecha reabierta.' );
				return $redirect;

			case 'add_block':
				Schedules::addBlock( (int) $p['resource_id'], Ui::localInputToSql( (string) $p['start'] ), Ui::localInputToSql( (string) $p['end'] ), sanitize_text_field( (string) ( $p['note'] ?? '' ) ) );
				Ui::flash( 'success', 'Bloqueo agregado.' );
				return $redirect;

			case 'remove_row':
				Schedules::removeRow( (int) $p['resource_id'], (int) $p['row_id'] );
				Ui::flash( 'success', 'Eliminado.' );
				return $redirect;

			case 'bulk_dates':
				$r = Schedules::bulkDates( (int) $p['resource_id'], (string) $p['lines'] );
				if ( $r['errors'] ) {
					Ui::rememberInput( $p );
					Ui::flash( 'error', 'No se guardó nada. ' . implode( ' ', $r['errors'] ) );
				} else {
					Ui::flash( 'success', sprintf( 'Se guardaron %d fechas.', $r['dates'] ) );
				}
				return $redirect;

			case 'save_client':
				$id = Pages\ClientsPage::save( $p );
				Ui::flash( 'success', 'Cliente guardado.' );
				return Ui::url( 'mo-booking-clients', array( 'client' => $id ) );

			case 'approve_payment':
				$amount = Money::parse( (string) ( $p['amount'] ?? '' ) );
				if ( null === $amount ) {
					throw new DomainException( 'invalid_amount', 'Escribe el importe recibido, por ejemplo 500 o 500.00.' );
				}
				$b = Bookings::approvePayment(
					$bookingId,
					array(
						'proof_id'      => (int) ( $p['proof_id'] ?? 0 ),
						'amount_cents'  => $amount,
						'received_date' => (string) ( $p['received_date'] ?? '' ),
						'reference'     => (string) ( $p['reference'] ?? '' ),
						'method'        => (string) ( $p['method'] ?? 'transfer' ),
						'note'          => (string) ( $p['note'] ?? '' ),
						'form_key'      => (string) ( $p['form_key'] ?? '' ),
					)
				);
				Ui::flash( 'success', 'Pago registrado. Estado actual: ' . \MOBookingStudio\Domain\BookingStatus::label( $b['status'] ) . '.' );
				return $bookingUrl;

			case 'request_correction':
				Bookings::requestCorrection( $bookingId, ! empty( $p['proof_id'] ) ? (int) $p['proof_id'] : null, sanitize_title( (string) ( $p['reason_key'] ?? '' ) ), (string) ( $p['internal_note'] ?? '' ) );
				Ui::flash( 'success', 'Se pidió la corrección. El horario sigue apartado durante el plazo.' );
				return $bookingUrl;

			case 'add_movement':
				$amount = Money::parse( (string) ( $p['amount'] ?? '' ) ) ?? 0;
				Bookings::addMovement( $bookingId, (string) $p['type'], 'reversal' === $p['type'] ? 1 : $amount, (string) ( $p['note'] ?? '' ), (string) ( $p['form_key'] ?? '' ), ! empty( $p['reverses_id'] ) ? (int) $p['reverses_id'] : null, (string) ( $p['reference'] ?? '' ) );
				Ui::flash( 'success', 'Movimiento registrado.' );
				return $bookingUrl;

			case 'proof_file':
				$proof = Proofs::find( (int) ( $_REQUEST['proof_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification
				if ( ! $proof ) {
					throw new DomainException( 'not_found', 'Comprobante no encontrado.', 404 );
				}
				if ( empty( $_REQUEST['download'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
					Bookings::startReview( (int) $proof['booking_id'] );
				}
				Proofs::stream( $proof, ! empty( $_REQUEST['download'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
				return $redirect;

			case 'confirm':
				Bookings::confirm( $bookingId, (string) ( $p['override'] ?? '' ) );
				Ui::flash( 'success', 'Cita confirmada.' );
				return $bookingUrl;

			case 'reject':
				Bookings::reject( $bookingId, sanitize_title( (string) ( $p['reason_key'] ?? '' ) ), (string) ( $p['internal_note'] ?? '' ) );
				Ui::flash( 'success', 'Solicitud rechazada; el horario quedó libre.' );
				return $bookingUrl;

			case 'cancel':
				Bookings::cancel( $bookingId, 'business' === ( $p['by'] ?? '' ), (string) ( $p['reason'] ?? '' ), (int) $p['version'] );
				Ui::flash( 'success', 'Reserva cancelada. Revisa los movimientos registrados.' );
				return $bookingUrl;

			case 'reschedule':
				Bookings::reschedule( $bookingId, Catalog::parseStart( (string) $p['start'] ), (string) ( $p['reason'] ?? '' ), (int) $p['version'] );
				Ui::flash( 'success', 'Reserva reprogramada.' );
				return $bookingUrl;

			case 'recalculate':
				Bookings::recalculate( $bookingId, (string) $p['category'], (string) ( $p['reason'] ?? '' ), (int) $p['version'] );
				Ui::flash( 'success', 'Precio recalculado.' );
				return $bookingUrl;

			case 'complete':
				Bookings::complete( $bookingId );
				Ui::flash( 'success', 'Sesión marcada como completada.' );
				return $bookingUrl;

			case 'no_show':
				Bookings::markNoShow( $bookingId, (int) $p['version'] );
				Ui::flash( 'success', 'Inasistencia registrada.' );
				return $bookingUrl;

			case 'reopen':
				Bookings::reopen( $bookingId, (string) ( $p['reason'] ?? '' ) );
				Ui::flash( 'success', 'La reserva volvió a "Confirmada".' );
				return $bookingUrl;

			case 'add_note':
				Bookings::addNote( $bookingId, (string) ( $p['note'] ?? '' ) );
				Ui::flash( 'success', 'Nota agregada.' );
				return $bookingUrl;

			case 'rotate_link':
				Bookings::rotateToken( $bookingId );
				Ui::flash( 'success', 'Se generó un enlace nuevo. El anterior ya no funciona.' );
				return $bookingUrl;

			case 'whatsapp_open':
				$b   = Bookings::get( $bookingId );
				$url = Notifications::whatsappUrl( $b, sanitize_key( (string) $p['event'] ) );
				if ( ! $url ) {
					throw new DomainException( 'no_phone', 'La reserva no tiene un teléfono válido.' );
				}
				Notifications::logManualWhatsapp( $b, sanitize_key( (string) $p['event'] ), 'opened_manual' );
				wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- wa.me es externo a propósito.
				exit;

			case 'whatsapp_sent':
				Notifications::markManualSent( (int) $p['notification_id'] );
				Ui::flash( 'success', 'Marcado como enviado manualmente.' );
				return $bookingUrl;

			case 'create_booking':
				$id = Pages\BookingsPage::createFromAdmin( $p );
				Ui::flash( 'success', 'Reserva creada.' );
				return Ui::url( 'mo-booking-bookings', array( 'booking' => $id ) );

			case 'retry_notification':
				Notifications::retry( (int) $p['notification_id'] );
				Ui::flash( 'success', 'Se reintentó el envío.' );
				return $redirect;

			case 'process_queue':
				$e = Bookings::expireDue( 500 );
				$n = Notifications::processQueue( 100 );
				Ui::flash( 'success', sprintf( 'Pendientes procesados: %d vencimientos, %d mensajes.', $e, $n ) );
				return $redirect;
		}
		return $redirect;
	}

	/** URL firmada para ver un comprobante (GET). */
	public static function proofUrl( int $proofId, bool $download = false ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'   => 'mo_booking',
					'do'       => 'proof_file',
					'proof_id' => $proofId,
					'download' => $download ? 1 : 0,
				),
				admin_url( 'admin-post.php' )
			),
			'mo_booking_proof_file',
			'_mo_nonce'
		);
	}
}
