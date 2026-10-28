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
use MOBookingStudio\Application\Trash;
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
		'save_template'       => Cap::SETTINGS,
		'delete_template'     => Cap::SETTINGS,
		'send_template'       => Cap::BOOKINGS,
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
		'upload_proof'        => Cap::PAYMENTS,
		'confirm'             => Cap::BOOKINGS,
		'invite_without_deposit' => Cap::BOOKINGS,
		'courtesy'            => Cap::BOOKINGS,
		'remove_courtesy'     => Cap::BOOKINGS,
		'duplicate_service'   => Cap::SETTINGS,
		'service_status'      => Cap::SETTINGS,
		'delete_service'      => Cap::SETTINGS,
		'archive_client'      => Cap::CLIENTS,
		'restore_client'      => Cap::CLIENTS,
		'delete_client'       => Cap::CLIENTS,
		'trash_client'        => Cap::CLIENTS,
		'restore_client_trash' => Cap::CLIENTS,
		'purge_client'        => Cap::CLIENTS,
		'add_contact'         => Cap::CLIENTS,
		'remove_contact'      => Cap::CLIENTS,
		'merge_clients'       => Cap::CLIENTS,
		'dismiss_duplicate'   => Cap::CLIENTS,
		'delete_movement'     => Cap::PAYMENTS,
		'revert_frequent'     => Cap::BOOKINGS,
		'trash_booking'       => Cap::BOOKINGS,
		'restore_booking_trash' => Cap::BOOKINGS,
		'purge_booking'       => Cap::BOOKINGS,
		'trash_service'       => Cap::SETTINGS,
		'trash_contract'      => Cap::SETTINGS,
		'archive_booking'     => Cap::BOOKINGS,
		'restore_booking'     => Cap::BOOKINGS,
		'delete_booking'      => Cap::BOOKINGS,
		'edit_preview'        => Cap::BOOKINGS,
		'edit_apply'          => Cap::BOOKINGS,
		'save_contract'       => Cap::SETTINGS,
		'archive_contract'    => Cap::SETTINGS,
		'restore_contract'    => Cap::SETTINGS,
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
		'google_connect'      => Cap::GOOGLE,
		'google_disconnect'   => Cap::GOOGLE,
		'google_calendars'    => Cap::GOOGLE,
		'google_reconcile'    => Cap::GOOGLE,
		'google_retry'        => Cap::GOOGLE,
		'google_restore'      => Cap::GOOGLE,
		'google_dismiss'      => Cap::GOOGLE,
		'google_conflict'     => Cap::BOOKINGS,
	);

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_mo_booking', array( $this, 'dispatch' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_notices', array( $this, 'globalNotices' ) );
		add_action( 'admin_notices', array( $this, 'upgradeNotice' ) );
		add_action( 'admin_post_mo_booking_confirm_backup', array( $this, 'confirmBackup' ) );
		add_action( 'admin_post_mo_booking_google_callback', array( $this, 'googleCallback' ) );
		add_action( 'admin_page_access_denied', array( $this, 'redirectDuringUpgrade' ) );
		add_filter( 'submenu_file', array( $this, 'highlightSubmenu' ), 10, 2 );
	}

	/** "Reglas de cancelación y cambios" es una pestaña de Contratos: se resalta Contratos en el menú. */
	public function highlightSubmenu( $submenuFile, $parentFile ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'mo-booking-policies' === $page ) {
			$GLOBALS['parent_file'] = 'mo-booking'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			return 'mo-booking-contracts';
		}
		return $submenuFile;
	}

	/** Mientras se espera el respaldo, cualquier pantalla del plugin lleva al aviso de actualización. */
	public function redirectDuringUpgrade(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 0 === strpos( $page, 'mo-booking-' ) && \MOBookingStudio\Plugin::maintenance() ) {
			wp_safe_redirect( admin_url( 'admin.php?page=mo-booking' ) );
			exit;
		}
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
		$lists   = array();
		$lists[ Tables\BookingsTable::class ] = add_submenu_page( 'mo-booking', 'Reservas', 'Reservas', Cap::BOOKINGS, 'mo-booking-bookings', array( Pages\BookingsPage::class, 'render' ) );
		$lists[ Tables\ServicesTable::class ] = add_submenu_page( 'mo-booking', 'Servicios', 'Servicios', Cap::SETTINGS, 'mo-booking-services', array( Pages\ServicesPage::class, 'render' ) );
		add_submenu_page( 'mo-booking', 'Disponibilidad', 'Disponibilidad', Cap::SETTINGS, 'mo-booking-availability', array( Pages\AvailabilityPage::class, 'render' ) );
		$lists[ Tables\ClientsTable::class ] = add_submenu_page( 'mo-booking', 'Clientes', 'Clientes', Cap::CLIENTS, 'mo-booking-clients', array( Pages\ClientsPage::class, 'render' ) );
		add_submenu_page( 'mo-booking', 'Pagos y recibos', 'Pagos y recibos', Cap::PAYMENTS, 'mo-booking-payments', array( Pages\PaymentsPage::class, 'render' ) );
		add_submenu_page( 'mo-booking', 'Tarifas y descuentos', 'Tarifas y descuentos', Cap::SETTINGS, 'mo-booking-rates', array( Pages\RatesPage::class, 'render' ) );
		// 6.5: "Políticas" se renombra "Contratos"; las reglas económicas quedan como pestaña.
		$lists[ Tables\ContractsTable::class ] = add_submenu_page( 'mo-booking', 'Contratos', 'Contratos', Cap::SETTINGS, 'mo-booking-contracts', array( Pages\ContractsPage::class, 'render' ) );
		$hook = add_submenu_page( '', 'Reglas de cancelación y cambios', 'Reglas de cancelación y cambios', Cap::SETTINGS, 'mo-booking-policies', array( Pages\PoliciesPage::class, 'render' ) );
		// Las páginas ocultas no tienen título en el menú; se fija para la cabecera de WordPress.
		add_action(
			'load-' . $hook,
			static function () {
				$GLOBALS['title'] = 'Reglas de cancelación y cambios'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			}
		);
		add_submenu_page( 'mo-booking', 'Notificaciones', 'Notificaciones', Cap::SETTINGS, 'mo-booking-notifications', array( Pages\NotificationsPage::class, 'render' ) );
		add_submenu_page( 'mo-booking', 'Google Calendar', 'Google Calendar', Cap::GOOGLE, 'mo-booking-google', array( Pages\GooglePage::class, 'render' ) );
		add_submenu_page( 'mo-booking', 'Finanzas (próximamente)', 'Finanzas · próx.', Cap::FINANCE, 'mo-booking-finance', array( Pages\UpcomingPage::class, 'finance' ) );
		add_submenu_page( 'mo-booking', 'Galerías (próximamente)', 'Galerías · próx.', Cap::GALLERIES, 'mo-booking-galleries', array( Pages\UpcomingPage::class, 'galleries' ) );
		add_submenu_page( 'mo-booking', 'Ajustes', 'Ajustes', Cap::SETTINGS, 'mo-booking-settings', array( Pages\SettingsPage::class, 'render' ) );
		// Acciones masivas y por fila de las listas: se procesan antes de imprimir la página.
		foreach ( $lists as $table => $hook ) {
			if ( $hook ) {
				add_action( 'load-' . $hook, array( $table, 'process' ) );
			}
		}
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
		$wpZone = Settings::wordpressZoneMismatch( \MOBookingStudio\Plugin::clock()->now() );
		if ( '' !== $wpZone ) {
			$notices[] = sprintf( 'La zona horaria de WordPress (<strong>%1$s</strong>) no da la misma hora que la del plugin (<strong>%2$s</strong>). Las reservas usan la del plugin y son correctas; para que el resto del sitio coincida, cambia <em>Ajustes → Generales → Zona horaria</em> a una ciudad con la misma hora que %2$s (por ejemplo, Ciudad Juárez o Denver).', esc_html( $wpZone ), esc_html( $tz['requested'] ) );
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

	/** Retorno de Google después de autorizar (CAL-01). */
	public function googleCallback(): void {
		if ( ! current_user_can( Cap::GOOGLE ) ) {
			wp_die( 'No tienes permiso para conectar Google.', '', array( 'response' => 403 ) );
		}
		$g = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification -- Se valida con el parámetro state.
		try {
			if ( ! empty( $g['error'] ) ) {
				throw new DomainException( 'google_denied', 'access_denied' === $g['error'] ? 'Cancelaste la autorización en Google. No se conectó nada.' : 'Google no autorizó la conexión.' );
			}
			\MOBookingStudio\Integrations\Google\Connection::handleCallback( (string) ( $g['code'] ?? '' ), (string) ( $g['state'] ?? '' ) );
			Ui::flash( 'success', 'Google conectado. Ahora elige los calendarios.' );
		} catch ( DomainException $e ) {
			Ui::flash( 'error', $e->getMessage() );
		} catch ( \MOBookingStudio\Integrations\Google\GoogleException $e ) {
			Ui::flash( 'error', $e->getMessage() );
		}
		wp_safe_redirect( Ui::url( 'mo-booking-google' ) );
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
		} catch ( \MOBookingStudio\Integrations\Google\GoogleException $e ) {
			Ui::flash( 'error', 'Google: ' . $e->getMessage() );
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
					'error'  => array( 'warning', 'No se pudo confirmar que la carpeta sea privada. La carga queda deshabilitada.' ),
				);
				Ui::flash( $messages[ $r ][0], $messages[ $r ][1] . ' Detalle: ' . (string) Settings::get( 'uploads_reason' ) );
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
				$unknown = Notifications::saveTemplates( (array) ( $p['tpl'] ?? array() ) );
				Ui::flash( 'success', 'Avisos guardados.' );
				if ( $unknown ) {
					Ui::flash( 'warning', 'Revisa estos códigos, no los reconocemos y saldrían vacíos: ' . implode( ' ', $unknown ) . '. Usa la guía de códigos (doble llave, por ejemplo {{nombre}}).' );
				}
				return $redirect;

			case 'save_template':
				$r = Notifications::saveCustom( (string) ( $p['template_id'] ?? '' ) ?: null, $p );
				Ui::flash( 'success', 'Plantilla guardada.' );
				if ( $r['unknown'] ) {
					Ui::flash( 'warning', 'Revisa estos códigos, no los reconocemos y saldrían vacíos: ' . implode( ' ', $r['unknown'] ) . '. Usa la guía de códigos (doble llave, por ejemplo {{nombre}}).' );
				}
				return Ui::url( 'mo-booking-notifications', array( 'template' => $r['id'] ) ) . '#plantilla';

			case 'delete_template':
				Notifications::deleteCustom( sanitize_key( (string) ( $p['template_id'] ?? '' ) ) );
				Ui::flash( 'success', 'Plantilla quitada. Los mensajes ya enviados conservan su texto.' );
				return Ui::url( 'mo-booking-notifications' );

			case 'send_template':
				Notifications::sendCustomNow( Bookings::get( $bookingId ), sanitize_key( (string) ( $p['template_id'] ?? '' ) ) );
				Ui::flash( 'success', 'Correo procesado. Revisa su estado en el registro de mensajes de la reserva.' );
				return $bookingUrl;

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
				Bookings::requestCorrection( $bookingId, ! empty( $p['proof_id'] ) ? (int) $p['proof_id'] : null, sanitize_title( (string) ( $p['reason_key'] ?? '' ) ), (string) ( $p['internal_note'] ?? '' ), (string) ( $p['other_reason'] ?? '' ), (string) ( $p['exception_reason'] ?? '' ) );
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

			case 'upload_proof':
				$r = Proofs::uploadByAdmin( $bookingId, (array) ( $_FILES['proof_file'] ?? array() ), (string) ( $p['note'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				Ui::flash( 'success', sprintf( 'Comprobante adjuntado (versión %d). Revísalo y aprueba el pago cuando confirmes el depósito.', $r['version'] ) );
				return $bookingUrl;

			case 'confirm':
				Bookings::confirm( $bookingId, (string) ( $p['override'] ?? '' ) );
				Ui::flash( 'success', 'Cita confirmada.' );
				return $bookingUrl;

			case 'save_contract':
				$contractId = \MOBookingStudio\Application\Contracts::save( (int) ( $p['contract_id'] ?? 0 ) ?: null, (string) ( $p['name'] ?? '' ), (string) ( $p['title'] ?? '' ), (string) ( $p['body'] ?? '' ), ! empty( $p['publish'] ) );
				Ui::flash( 'success', ! empty( $p['publish'] ) ? 'Contrato publicado como versión nueva. Las reservas anteriores conservan la versión que aceptaron.' : 'Borrador guardado. Aún no lo ve nadie.' );
				return Ui::url( 'mo-booking-contracts', array( 'contract' => $contractId ) );

			case 'archive_contract':
			case 'restore_contract':
				\MOBookingStudio\Application\Contracts::setArchived( (int) $p['contract_id'], 'archive_contract' === $do );
				Ui::flash( 'success', 'archive_contract' === $do ? 'Plantilla archivada.' : 'Plantilla restaurada.' );
				return Ui::url( 'mo-booking-contracts' );

			case 'courtesy':
				Bookings::markCourtesy( $bookingId, (string) ( $p['reason'] ?? '' ), (int) ( $p['version'] ?? 0 ) );
				Ui::flash( 'success', 'Reserva marcada como cortesía: confirmada sin costo y sin avisos a la clienta.' );
				return $bookingUrl;

			case 'remove_courtesy':
				Bookings::removeCourtesy( $bookingId, (int) ( $p['version'] ?? 0 ) );
				Ui::flash( 'success', 'Se quitó la cortesía; volvió el precio original.' );
				return $bookingUrl;

			case 'invite_without_deposit':
				Bookings::inviteWithoutDeposit( $bookingId, (string) ( $p['reason'] ?? '' ) );
				Ui::flash( 'success', 'Se registró la invitación sin anticipo; se enviará al sincronizar con Google.' );
				return $bookingUrl;

			case 'reject':
				Bookings::reject( $bookingId, sanitize_title( (string) ( $p['reason_key'] ?? '' ) ), (string) ( $p['internal_note'] ?? '' ) );
				Ui::flash( 'success', 'Solicitud rechazada; el horario quedó libre.' );
				return $bookingUrl;

			case 'duplicate_service':
				$newId = Services::duplicate( (int) $p['service_id'] );
				Ui::flash( 'success', 'Servicio duplicado como borrador.' );
				return Ui::url( 'mo-booking-services', array( 'service' => $newId ) );

			case 'service_status':
				Services::setStatus( (int) $p['service_id'], sanitize_key( (string) ( $p['status'] ?? '' ) ) );
				Ui::flash( 'success', 'Estado del servicio actualizado. Las reservas existentes no cambian.' );
				return Ui::url( 'mo-booking-services' );

			case 'delete_service':
				Services::delete( (int) $p['service_id'] );
				Ui::flash( 'success', 'Servicio eliminado.' );
				return Ui::url( 'mo-booking-services' );

			case 'archive_client':
			case 'restore_client':
				\MOBookingStudio\Application\Clients::setArchived( (int) $p['client_id'], 'archive_client' === $do );
				Ui::flash( 'success', 'archive_client' === $do ? 'Cliente archivado.' : 'Cliente restaurado.' );
				return Ui::url( 'mo-booking-clients', array( 'client' => (int) $p['client_id'] ) );

			case 'delete_client':
				\MOBookingStudio\Application\Clients::delete( (int) $p['client_id'] );
				Ui::flash( 'success', 'Cliente eliminado.' );
				return Ui::url( 'mo-booking-clients' );

			case 'trash_client':
				Trash::trashClient( (int) $p['client_id'] );
				Ui::flash( 'success', 'Cliente enviado a la papelera. Puedes restaurarlo desde Clientes → Papelera.' );
				return Ui::url( 'mo-booking-clients' );

			case 'restore_client_trash':
				Trash::restoreClient( (int) $p['client_id'] );
				Ui::flash( 'success', 'Cliente restaurado.' );
				return Ui::url( 'mo-booking-clients', array( 'client' => (int) $p['client_id'] ) );

			case 'purge_client':
				$r = Trash::purgeClient( (int) $p['client_id'], (string) ( $p['confirm_name'] ?? '' ) );
				Ui::flash( 'success', sprintf( 'Cliente eliminado definitivamente junto con %d reserva(s), %d movimiento(s) y %d comprobante(s).', $r['bookings'], $r['payments'], $r['proofs'] ) );
				return Ui::url( 'mo-booking-clients' );

			case 'add_contact':
				$added = Clients::addContact( (int) $p['client_id'], sanitize_key( (string) ( $p['contact_kind'] ?? '' ) ), sanitize_text_field( (string) ( $p['contact_value'] ?? '' ) ), sanitize_key( (string) ( $p['contact_country'] ?? 'MX' ) ), (string) ( $p['contact_label'] ?? '' ) );
				Ui::flash( 'success', $added ? 'Contacto agregado.' : 'Ese contacto ya estaba registrado para este cliente.' );
				return Ui::url( 'mo-booking-clients', array( 'client' => (int) $p['client_id'] ) );

			case 'remove_contact':
				Clients::removeContact( (int) $p['client_id'], (int) $p['contact_id'] );
				Ui::flash( 'success', 'Contacto quitado.' );
				return Ui::url( 'mo-booking-clients', array( 'client' => (int) $p['client_id'] ) );

			case 'merge_clients':
				$r = Clients::merge( (int) $p['principal_id'], (int) $p['duplicate_id'] );
				Ui::flash( 'success', sprintf( 'Clientes fusionados: %d reserva(s) movidas. El duplicado quedó en la papelera.', $r['bookings'] ) );
				return Ui::url( 'mo-booking-clients', array( 'client' => (int) $p['principal_id'] ) );

			case 'dismiss_duplicate':
				Clients::dismissDuplicate( (int) $p['a'], (int) $p['b'] );
				Ui::flash( 'success', 'Listo: ya no se sugerirán como duplicados.' );
				return $redirect;

			case 'delete_movement':
				$r = Trash::deleteMovement( $bookingId, (int) $p['payment_id'], (string) ( $p['reason'] ?? '' ) );
				Ui::flash( 'success', sprintf( 'Movimiento eliminado (%s). Saldo antes: %s · ahora: %s.', Money::format( $r['amount'] ), Money::format( $r['before']['balance_cents'] ), Money::format( $r['after']['balance_cents'] ) ) );
				if ( $r['warning'] ) {
					Ui::flash( 'warning', $r['warning'] );
				}
				return $bookingUrl;

			case 'revert_frequent':
				Bookings::revertFrequent( $bookingId, (int) $p['version'] );
				Ui::flash( 'success', 'Se regresó al precio para público general. Revisa el anticipo y el saldo.' );
				return $bookingUrl;

			case 'trash_booking':
				Trash::trashBooking( $bookingId );
				Ui::flash( 'success', 'Reserva enviada a la papelera. Puedes restaurarla desde Reservas → Papelera.' );
				return Ui::url( 'mo-booking-bookings' );

			case 'restore_booking_trash':
				Trash::restoreBooking( $bookingId );
				Ui::flash( 'success', 'Reserva restaurada.' );
				return $bookingUrl;

			case 'purge_booking':
				if ( 'ELIMINAR' !== strtoupper( trim( (string) ( $p['confirm_text'] ?? '' ) ) ) ) {
					throw new DomainException( 'confirm_required', 'Para eliminar definitivamente escribe ELIMINAR.' );
				}
				Trash::purgeBooking( $bookingId );
				Ui::flash( 'success', 'Reserva eliminada definitivamente con sus pagos y comprobantes.' );
				return Ui::url( 'mo-booking-bookings', array( 'view' => 'trash' ) );

			case 'trash_service':
				Trash::trashService( (int) $p['service_id'] );
				Ui::flash( 'success', 'Servicio enviado a la papelera: ya no se ofrece. Las reservas existentes no cambian.' );
				return Ui::url( 'mo-booking-services' );

			case 'trash_contract':
				Trash::trashContract( (int) $p['contract_id'] );
				Ui::flash( 'success', 'Contrato enviado a la papelera. Las reservas conservan su copia aceptada.' );
				return Ui::url( 'mo-booking-contracts' );

			case 'archive_booking':
			case 'restore_booking':
				Bookings::setArchived( $bookingId, 'archive_booking' === $do );
				Ui::flash( 'success', 'archive_booking' === $do ? 'Reserva archivada. No se modificó su estado ni sus pagos.' : 'Reserva restaurada.' );
				return $bookingUrl;

			case 'delete_booking':
				Bookings::deleteTest( $bookingId, (string) ( $p['confirm'] ?? '' ) );
				Ui::flash( 'success', 'Reserva de prueba eliminada.' );
				return Ui::url( 'mo-booking-bookings' );

			case 'edit_preview':
				$data = array_intersect_key( $p, array_flip( array( 'children', 'adults', 'pets', 'extras', 'fast_delivery', 'name', 'email', 'phone', 'phone_country', 'important_info' ) ) );
				Bookings::previewEdit( Bookings::get( $bookingId ), $data ); // Valida antes de mostrar.
				$key = bin2hex( random_bytes( 8 ) );
				set_transient( 'mo_edit_' . get_current_user_id() . '_' . $bookingId, array( 'key' => $key, 'data' => $data ), 30 * MINUTE_IN_SECONDS );
				return add_query_arg( 'edit_preview', $key, $bookingUrl ) . '#editar';

			case 'edit_apply':
				$pending = get_transient( 'mo_edit_' . get_current_user_id() . '_' . $bookingId );
				if ( ! is_array( $pending ) || ! hash_equals( $pending['key'], sanitize_key( (string) ( $p['edit_key'] ?? '' ) ) ) ) {
					throw new DomainException( 'edit_expired', 'La vista previa venció. Vuelve a revisar el cambio.' );
				}
				Bookings::applyEdit( $bookingId, array_merge( $pending['data'], array( 'reason' => (string) ( $p['reason'] ?? '' ) ) ), (int) $p['version'] );
				delete_transient( 'mo_edit_' . get_current_user_id() . '_' . $bookingId );
				Ui::flash( 'success', 'Cambios guardados. Se registraron los valores anteriores y nuevos.' );
				return $bookingUrl;

			case 'cancel':
				Bookings::cancel( $bookingId, 'business' === ( $p['by'] ?? '' ), (string) ( $p['reason'] ?? '' ), (int) $p['version'], sanitize_key( (string) ( $p['deposit_outcome'] ?? '' ) ) );
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
				if ( ! Notifications::whatsappEventAllowed( sanitize_key( (string) $p['event'] ) ) ) {
					throw new DomainException( 'not_found', 'Esa plantilla de WhatsApp ya no existe.' );
				}
				if ( ! $url ) {
					throw new DomainException( 'no_phone', 'La reserva no tiene un teléfono válido.' );
				}
				Notifications::logManualWhatsapp( $b, sanitize_key( (string) $p['event'] ), 'opened_manual' );
				self::redirectToWhatsapp( $url );

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

			case 'google_connect':
				wp_redirect( \MOBookingStudio\Integrations\Google\Connection::authUrl() ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Google es externo a propósito.
				exit;

			case 'google_disconnect':
				\MOBookingStudio\Integrations\Google\Connection::disconnect();
				Ui::flash( 'success', 'Google desconectado. Las reservas y los eventos existentes se conservan.' );
				return $redirect;

			case 'google_calendars':
				Pages\GooglePage::saveCalendars( $p );
				Ui::flash( 'success', 'Calendarios guardados.' );
				return $redirect;

			case 'google_reconcile':
				$sent = \MOBookingStudio\Integrations\Google\SyncWorker::processQueue( 100 );
				$scan = \MOBookingStudio\Integrations\Google\Conflicts::scan();
				Ui::flash( 'success', sprintf( 'Sincronización: %d trabajos procesados. Empalmes abiertos: %d (nuevos: %d). Cambios externos: %d.', $sent, $scan['open'], $scan['new'], $scan['external'] ) );
				return $redirect;

			case 'google_retry':
				\MOBookingStudio\Integrations\Google\SyncWorker::retry( (int) $p['job_id'] );
				\MOBookingStudio\Integrations\Google\SyncWorker::processQueue( 10 );
				Ui::flash( 'success', 'Se reintentó la sincronización.' );
				return $redirect;

			case 'google_restore':
				\MOBookingStudio\Application\CalendarSync::enqueue( $bookingId, 'sync_booking', true );
				\MOBookingStudio\Integrations\Google\SyncWorker::processQueue( 10 );
				Ui::flash( 'success', 'El evento se restauró con los datos de la reserva.' );
				return $redirect;

			case 'google_dismiss':
				\MOBookingStudio\Integrations\Google\Conflicts::dismissExternal( (int) $p['row_id'] );
				Ui::flash( 'success', 'Aviso ignorado.' );
				return $redirect;

			case 'google_conflict':
				\MOBookingStudio\Integrations\Google\Conflicts::act( (int) $p['conflict_id'], (string) $p['conflict_action'], (string) ( $p['note'] ?? '' ), (string) ( $p['until'] ?? '' ) );
				Ui::flash( 'success', 'Empalme actualizado.' );
				return $redirect;

			case 'process_queue':
				$e = Bookings::expireDue( 500 );
				$n = Notifications::processQueue( 100 );
				Ui::flash( 'success', sprintf( 'Pendientes procesados: %d vencimientos, %d mensajes.', $e, $n ) );
				return $redirect;
		}
		return $redirect;
	}

	/**
	 * beta.4: wp_redirect() quita los saltos de línea codificados (%0A) de la dirección por
	 * seguridad, y el mensaje llegaba amontonado. La dirección ya viene codificada (sin
	 * saltos reales) y solo puede ser de WhatsApp, así que se envía tal cual.
	 */
	private static function redirectToWhatsapp( string $url ): void {
		if ( ! preg_match( '#^https://(api|web)\.whatsapp\.com/send\?#', $url ) || preg_match( '/[\r\n]/', $url ) ) {
			wp_die( 'Dirección de WhatsApp no válida.', '', array( 'response' => 400 ) );
		}
		nocache_headers();
		header( 'Location: ' . $url, true, 302 );
		exit;
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
