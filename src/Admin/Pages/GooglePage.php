<?php
namespace MOBookingStudio\Admin\Pages;

use MOBookingStudio\Admin\Ui;
use MOBookingStudio\Application\CalendarSync;
use MOBookingStudio\Domain\BookingStatus;
use MOBookingStudio\Domain\DomainException;
use MOBookingStudio\Infrastructure\Audit;
use MOBookingStudio\Infrastructure\Db;
use MOBookingStudio\Infrastructure\Schema;
use MOBookingStudio\Infrastructure\Settings;
use MOBookingStudio\Integrations\Google\Api;
use MOBookingStudio\Integrations\Google\Conflicts;
use MOBookingStudio\Integrations\Google\Connection;
use MOBookingStudio\Integrations\Google\GoogleException;
use MOBookingStudio\Integrations\Google\SyncWorker;

/**
 * Conexión con Google Calendar y diagnóstico (CAL-05).
 */
final class GooglePage {
	public static function render(): void {
		Ui::header( 'Google Calendar' );
		$d       = Connection::data();
		$missing = Connection::missingConfig();

		// 1. Configuración en wp-config.php.
		$body = '<p>Dirección de retorno para registrar en Google Cloud (Clientes → tu cliente web → URI de redireccionamiento autorizadas):</p>';
		$body .= '<p><input type="text" readonly class="large-text mo-copy" value="' . esc_attr( Connection::redirectUri() ) . '" onclick="this.select()"></p>';
		if ( $missing ) {
			$body .= '<div class="notice notice-warning inline"><p>Faltan estas líneas en <code>wp-config.php</code> (antes de "That\'s all, stop editing"):</p><pre>';
			foreach ( $missing as $const ) {
				$value = 'MO_BOOKING_SECRET' === $const ? wp_generate_password( 64, false, false ) : ( 'MO_BOOKING_GOOGLE_CLIENT_ID' === $const ? 'pega-aqui-tu-client-id.apps.googleusercontent.com' : 'pega-aqui-tu-client-secret' );
				$body .= esc_html( "define( '$const', '$value' );" ) . "\n";
			}
			$body .= '</pre><p class="description">La clave MO_BOOKING_SECRET que ves arriba se generó al azar para ti; cópiala tal cual. No compartas estas líneas con nadie.</p></div>';
		} else {
			$body .= '<p><span class="dashicons dashicons-yes-alt" style="color:#00a32a" aria-hidden="true"></span> Credenciales y clave de cifrado encontradas en wp-config.php.</p>';
		}
		echo Ui::card( '1. Credenciales', $body ); // phpcs:ignore WordPress.Security.EscapeOutput

		// 2. Conexión.
		$labels = array( 'disconnected' => 'Sin conectar', 'connected' => 'Conectada', 'revoked' => 'Acceso revocado o vencido' );
		$body   = '<p>Estado: <strong>' . esc_html( $labels[ $d['status'] ] ?? $d['status'] ) . '</strong>' . ( $d['account'] ? ' · cuenta ' . esc_html( $d['account'] ) : '' ) . '</p>';
		if ( 'revoked' === $d['status'] ) {
			$body .= '<div class="notice notice-error inline"><p><strong>Google dejó de aceptar la conexión.</strong> Las escrituras en Google están detenidas, pero las reservas y los trabajos pendientes se conservan. Vuelve a conectar para reanudar.</p></div>';
		}
		if ( $d['last_error'] ) {
			$body .= '<p class="description">Último error: ' . esc_html( $d['last_error'] ) . '</p>';
		}
		if ( ! $missing ) {
			$body .= Ui::formOpen( 'google_connect', array(), 'mo-inline-form' ) . Ui::button( 'connected' === $d['status'] ? 'Volver a conectar' : 'Conectar con Google' ) . '</form> ';
		}
		if ( 'disconnected' !== $d['status'] ) {
			$body .= Ui::formOpen( 'google_disconnect', array(), 'mo-inline-form' ) . Ui::button( 'Desconectar', 'button-link-delete', '¿Desconectar Google? No se borran reservas ni eventos ya creados.' ) . '</form>';
		}
		$body .= '<p class="description">Permisos que se piden: ver la lista de calendarios, ver ocupación (sin detalles) y administrar eventos. Al conectar, Google puede decir que la app no está verificada: pulsa "Avanzado" e "Ir a MO Booking Studio".</p>';
		echo Ui::card( '2. Conexión', $body ); // phpcs:ignore WordPress.Security.EscapeOutput

		if ( 'connected' !== $d['status'] && ! $d['destination'] ) {
			Ui::footer();
			return;
		}

		// 3. Calendarios.
		$body = '';
		try {
			$calendars = 'connected' === $d['status'] ? ( new Api() )->calendarList() : array();
		} catch ( GoogleException $e ) {
			$calendars = array();
			$body     .= '<p class="mo-error">' . esc_html( $e->getMessage() ) . '</p>';
		}
		if ( $calendars ) {
			$body .= Ui::formOpen( 'google_calendars' ) . '<table class="widefat striped"><thead><tr><th>Calendario</th><th>Bloquea horarios</th><th>Destino de las reservas</th></tr></thead><tbody>';
			foreach ( $calendars as $c ) {
				$canWrite = in_array( $c['role'], array( 'owner', 'writer' ), true );
				$body    .= '<tr><td>' . esc_html( $c['name'] ) . ( $c['primary'] ? ' <span class="description">(principal)</span>' : '' ) . '</td>' .
					'<td><label><input type="checkbox" name="read[]" value="' . esc_attr( $c['id'] ) . '"' . checked( in_array( $c['id'], (array) $d['read_calendars'], true ), true, false ) . '> Leer ocupación</label></td>' .
					'<td>' . ( $canWrite ? '<label><input type="radio" name="destination" value="' . esc_attr( $c['id'] ) . '"' . checked( $d['destination'], $c['id'], false ) . '> Escribir aquí</label>' : '<span class="description">Sin permiso de escritura</span>' ) . '</td></tr>';
			}
			$body .= '</tbody></table><p class="description">Recomendación: un calendario nuevo y dedicado como destino (por ejemplo "MO Reservas"). Sus eventos propios no bloquean; cualquier otro evento que pongas ahí sí. Prueba primero con calendarios de prueba.</p>' . Ui::button( 'Guardar calendarios' ) . '</form>';
		}
		echo Ui::card( '3. Calendarios', $body ?: '<p>Conecta Google para elegir calendarios.</p>' ); // phpcs:ignore WordPress.Security.EscapeOutput

		// 4. Diagnóstico.
		$jobs  = SyncWorker::jobsSummary();
		$body  = '<ul class="mo-list">';
		$body .= '<li>Calendario destino: <strong>' . esc_html( $d['calendar_names'][ $d['destination'] ] ?? ( $d['destination'] ?: '—' ) ) . '</strong></li>';
		$body .= '<li>Calendarios que bloquean: ' . esc_html( implode( ', ', array_map( static function ( $id ) use ( $d ) { return $d['calendar_names'][ $id ] ?? $id; }, (array) $d['read_calendars'] ) ) ?: '—' ) . '</li>';
		$body .= '<li>Última sincronización: ' . esc_html( $d['last_sync_at'] ? Ui::localDateTime( gmdate( 'Y-m-d H:i:s', strtotime( $d['last_sync_at'] ) ), 'd/m/Y H:i' ) : 'todavía no' ) . '</li>';
		$body .= '<li>Última revisión de empalmes: ' . esc_html( $d['last_scan_at'] ? Ui::localDateTime( gmdate( 'Y-m-d H:i:s', strtotime( $d['last_scan_at'] ) ), 'd/m/Y H:i' ) : 'todavía no' ) . '</li>';
		$body .= '<li>Trabajos pendientes: <strong>' . (int) $jobs['pending'] . '</strong>' . ( $jobs['next'] ? ' · próximo ' . esc_html( Ui::localDateTime( $jobs['next'], 'd/m H:i' ) ) : '' ) . '</li>';
		$body .= '<li>Trabajos fallidos: <strong>' . count( $jobs['failed'] ) . '</strong></li></ul>';
		$migration = SyncWorker::pendingMigration();
		if ( $migration ) {
			// SEG-05: progreso de la migración al nuevo calendario destino.
			$refs  = array_unique( array_column( $migration, 'public_ref' ) );
			$body .= '<div class="notice notice-warning inline"><p><strong>Migración al nuevo calendario destino:</strong> ' . count( $migration ) . ' eventos de ' . count( $refs ) . ' reservas siguen en el calendario anterior (' . esc_html( implode( ', ', array_slice( $refs, 0, 20 ) ) ) . ( count( $refs ) > 20 ? '…' : '' ) . '). Se mueven con la sincronización; los errores aparecen abajo con "Reintentar".</p></div>';
		}
		$body .= Ui::formOpen( 'google_reconcile', array(), 'mo-inline-form' ) . Ui::button( 'Sincronizar y conciliar ahora', 'button-secondary' ) . '</form>';
		if ( $jobs['failed'] ) {
			$body .= '<table class="widefat striped"><thead><tr><th>Reserva</th><th>Error</th><th></th></tr></thead><tbody>';
			foreach ( $jobs['failed'] as $j ) {
				$body .= '<tr><td><a href="' . esc_url( Ui::url( 'mo-booking-bookings', array( 'booking' => (int) $j['booking_id'] ) ) ) . '">' . esc_html( (string) $j['public_ref'] ) . '</a></td><td>' . esc_html( $j['last_error'] ) . ( $j['last_http'] ? ' (' . (int) $j['last_http'] . ')' : '' ) . '</td><td>' . Ui::formOpen( 'google_retry', array( 'job_id' => $j['id'] ), 'mo-inline-form' ) . Ui::button( 'Reintentar', 'button-link' ) . '</form></td></tr>';
			}
			$body .= '</tbody></table>';
		}
		echo Ui::card( '4. Estado de la sincronización', $body ); // phpcs:ignore WordPress.Security.EscapeOutput

		self::conflictsCard();
		self::externalCard();
		Ui::footer();
	}

	private static function conflictsCard(): void {
		$rows = Conflicts::open();
		if ( ! $rows ) {
			echo Ui::card( 'Empalmes con Google', '<p>No hay empalmes detectados.</p>' ); // phpcs:ignore WordPress.Security.EscapeOutput
			return;
		}
		$names = Connection::data()['calendar_names'];
		$body  = '<p class="description">Un evento de Google ocupa total o parcialmente el horario de estas reservas. Nada se canceló ni se movió. Decide qué hacer; "Marcar resuelto" vuelve a consultar Google antes de cerrarlo.</p><table class="widefat striped mo-table"><thead><tr><th>Reserva</th><th>Ocupado en Google</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>';
		foreach ( $rows as $c ) {
			$body .= '<tr><td data-label="Reserva"><a href="' . esc_url( Ui::url( 'mo-booking-bookings', array( 'booking' => (int) $c['booking_id'] ) ) ) . '">' . esc_html( $c['public_ref'] ) . '</a><br>' . esc_html( Ui::localDateTime( $c['start_utc'], 'd/m/Y H:i' ) ) . '</td>' .
				'<td data-label="Ocupado">' . esc_html( $names[ $c['calendar_id'] ] ?? 'Calendario' ) . '<br>' . esc_html( Ui::localDateTime( $c['busy_start'], 'd/m H:i' ) . ' – ' . Ui::localDateTime( $c['busy_end'], 'H:i' ) ) . '</td>' .
				'<td data-label="Estado">' . esc_html( Conflicts::STATUS_LABEL[ $c['status'] ] ?? $c['status'] ) . ( $c['ignore_until'] ? ' hasta ' . esc_html( Ui::localDateTime( $c['ignore_until'], 'd/m/Y' ) ) : '' ) . '</td><td>' .
				Ui::formOpen( 'google_conflict', array( 'conflict_id' => $c['id'], 'conflict_action' => 'keep' ), 'mo-inline-form' ) . Ui::button( 'Mantener reserva', 'button-link' ) . '</form> · ' .
				Ui::formOpen( 'google_conflict', array( 'conflict_id' => $c['id'], 'conflict_action' => 'resolve' ), 'mo-inline-form' ) . Ui::button( 'Marcar resuelto', 'button-link' ) . '</form> · ' .
				Ui::formOpen( 'google_conflict', array( 'conflict_id' => $c['id'], 'conflict_action' => 'ignore' ), 'mo-inline-form' ) . Ui::input( 'until', '', 'date', array( 'aria-label' => 'Ignorar hasta', 'required' => 'required' ) ) . ' ' . Ui::button( 'Ignorar hasta', 'button-link' ) . '</form>' .
				'<br><a href="' . esc_url( Ui::url( 'mo-booking-bookings', array( 'booking' => (int) $c['booking_id'] ) ) ) . '">Proponer nuevo horario o cancelar…</a></td></tr>';
		}
		echo Ui::card( 'Empalmes con Google (' . count( $rows ) . ')', $body . '</tbody></table>' ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	private static function externalCard(): void {
		$rows = Conflicts::externalChanges();
		if ( ! $rows ) {
			return;
		}
		$labels = array( 'moved' => 'Se cambió la fecha u hora en Google', 'deleted' => 'Se borró en Google', 'attendees' => 'Se cambiaron los invitados en Google' );
		$body   = '<p class="description">Estos eventos del plugin se editaron directamente en Google. WordPress manda: no se importó nada. Restaura el evento con los datos de la reserva o, si fue intencional, ignora el aviso.</p><table class="widefat striped"><thead><tr><th>Reserva</th><th>Evento</th><th>Cambio</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$body .= '<tr><td><a href="' . esc_url( Ui::url( 'mo-booking-bookings', array( 'booking' => (int) $r['booking_id'] ) ) ) . '">' . esc_html( $r['public_ref'] ) . '</a></td><td>' . ( 'client' === $r['kind'] ? 'Del cliente' : 'Interno' ) . '</td><td>' . esc_html( $labels[ $r['external_change'] ] ?? $r['external_change'] ) . '</td><td>' .
				Ui::formOpen( 'google_restore', array( 'booking_id' => $r['booking_id'] ), 'mo-inline-form' ) . Ui::button( 'Restaurar desde WordPress', 'button-link' ) . '</form> · ' .
				Ui::formOpen( 'google_dismiss', array( 'row_id' => $r['id'] ), 'mo-inline-form' ) . Ui::button( 'Ignorar', 'button-link' ) . '</form></td></tr>';
		}
		echo Ui::card( 'Cambios hechos directamente en Google', $body . '</tbody></table>' ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public static function saveCalendars( array $p ): void {
		$calendars = ( new Api() )->calendarList();
		$valid     = array();
		$names     = array();
		$writable  = array();
		foreach ( $calendars as $c ) {
			$valid[]           = $c['id'];
			$names[ $c['id'] ] = $c['name'];
			if ( in_array( $c['role'], array( 'owner', 'writer' ), true ) ) {
				$writable[] = $c['id'];
			}
		}
		$read        = array_values( array_intersect( array_map( 'strval', (array) ( $p['read'] ?? array() ) ), $valid ) );
		$destination = (string) ( $p['destination'] ?? '' );
		if ( ! in_array( $destination, $writable, true ) ) {
			throw new DomainException( 'google_destination', 'Elige un calendario destino en el que tengas permiso de escritura.' );
		}
		$before = Connection::data();
		Connection::save(
			array(
				'read_calendars' => $read,
				'destination'    => $destination,
				'calendar_names' => $names,
			)
		);
		Audit::log( 'google', 0, 'calendars_saved', array( 'read' => count( $read ), 'destination_changed' => $before['destination'] !== $destination ) );
		if ( '' === $before['destination'] ) {
			self::enqueueActive();
		} elseif ( $before['destination'] !== $destination ) {
			// SEG-05: migración explícita de los eventos propios al nuevo destino.
			$ids = array_unique( array_map( 'intval', array_column( SyncWorker::pendingMigration(), 'booking_id' ) ) );
			foreach ( $ids as $id ) {
				CalendarSync::enqueue( $id );
			}
			Audit::log( 'google', 0, 'destination_migration_started', array( 'from' => $before['destination'], 'to' => $destination, 'bookings' => count( $ids ) ) );
		}
	}

	/** Al elegir el destino por primera vez se reflejan las reservas vigentes futuras. */
	public static function enqueueActive(): int {
		$wpdb     = Db::wpdb();
		$statuses = "'" . implode( "','", BookingStatus::active() ) . "'";
		$ids      = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'bookings' ) . " WHERE status IN ($statuses) AND start_utc >= %s", Db::toSql( \MOBookingStudio\Plugin::clock()->now() ) ) ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $ids as $id ) {
			CalendarSync::enqueue( (int) $id );
		}
		return count( $ids );
	}
}
