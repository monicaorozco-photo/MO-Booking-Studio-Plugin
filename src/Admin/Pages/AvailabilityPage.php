<?php
namespace MOBookingStudio\Admin\Pages;

use MOBookingStudio\Admin\Ui;
use MOBookingStudio\Application\Bookings;
use MOBookingStudio\Application\Schedules;
use MOBookingStudio\Application\Services;
use MOBookingStudio\Domain\Availability;
use MOBookingStudio\Domain\BookingStatus;
use MOBookingStudio\Domain\DomainException;
use MOBookingStudio\Infrastructure\Settings;
use MOBookingStudio\Plugin;

final class AvailabilityPage {
	/** "10:00-13:00, 15:00-19:00" -> [[10:00,13:00],[15:00,19:00]] */
	public static function parseWindows( string $text ): array {
		$text = trim( $text );
		if ( '' === $text ) {
			return array();
		}
		$out = array();
		foreach ( preg_split( '/\s*[,;]\s*|\s+y\s+/u', $text ) as $chunk ) {
			if ( ! preg_match( '/^(\d{1,2}:\d{2})\s*(?:-|–|a)\s*(\d{1,2}:\d{2})$/u', trim( $chunk ), $m ) ) {
				throw new DomainException( 'invalid_time', sprintf( 'No se entiende "%s". Usa el formato 10:00-13:00.', trim( $chunk ) ) );
			}
			$out[] = array( str_pad( $m[1], 5, '0', STR_PAD_LEFT ), str_pad( $m[2], 5, '0', STR_PAD_LEFT ) );
		}
		return Availability::validateWindows( $out );
	}

	public static function windowsText( array $windows ): string {
		return implode(
			', ',
			array_map(
				static function ( $w ) {
					return $w[0] . '-' . $w[1];
				},
				$windows
			)
		);
	}

	public static function render(): void {
		$resources  = Schedules::resources();
		$resourceId = isset( $_GET['resource'] ) ? (int) $_GET['resource'] : Schedules::defaultResourceId(); // phpcs:ignore WordPress.Security.NonceVerification
		$old        = Ui::oldInput();
		Ui::header( 'Disponibilidad' );

		if ( count( $resources ) > 1 ) {
			echo '<form method="get" class="mo-filter"><input type="hidden" name="page" value="mo-booking-availability"><label>Agenda ';
			$opts = array();
			foreach ( $resources as $r ) {
				$opts[ $r['id'] ] = $r['name'] . ( $r['is_test'] ? ' (PRUEBA)' : '' );
			}
			echo Ui::select( 'resource', $opts, (string) $resourceId ) . '</label> <button class="button">Ver</button></form>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		$tz = Settings::timezoneInfo();
		echo '<p class="description">Todas las horas están en ' . esc_html( $tz['requested'] ) . '. Las fechas específicas reemplazan el horario semanal de ese día; un cierre o un bloqueo siempre prevalecen. Cambiar horarios no mueve ni borra citas existentes.</p>';

		$schedule = Schedules::schedule( $resourceId );
		echo '<div class="mo-grid mo-grid--2">';

		// Horario semanal.
		$rows = '';
		foreach ( Ui::weekdays() as $d => $label ) {
			$rows .= Ui::row( $label, Ui::input( "weekly[$d]", self::windowsText( $schedule['weekly'][ $d ] ?? array() ), 'text', array( 'class' => 'regular-text', 'placeholder' => 'Cerrado' ) ), '', "weekly[$d]" );
		}
		echo Ui::card( 'Horario semanal', Ui::formOpen( 'save_weekly', array( 'resource_id' => $resourceId ) ) . '<p class="description">Escribe los horarios de cada día, por ejemplo <code>10:00-14:00, 16:00-19:00</code>. Vacío = cerrado.</p>' . $rows . Ui::button( 'Guardar horario semanal' ) . '</form>' ); // phpcs:ignore WordPress.Security.EscapeOutput

		// Fechas específicas.
		$body  = Ui::formOpen( 'save_date', array( 'resource_id' => $resourceId ), 'mo-inline-form' );
		$body .= '<label>Fecha ' . Ui::input( 'date', $old['date'] ?? '', 'date', array( 'required' => 'required' ) ) . '</label> <label>Horarios ' . Ui::input( 'windows', $old['windows'] ?? '', 'text', array( 'placeholder' => '13:00-15:30', 'required' => 'required' ) ) . '</label> ' . Ui::button( 'Agregar o reemplazar', 'button-secondary' ) . '</form>';
		$body .= '<details class="mo-details"' . ( isset( $old['lines'] ) ? ' open' : '' ) . '><summary>Cargar varias fechas a la vez</summary>' . Ui::formOpen( 'bulk_dates', array( 'resource_id' => $resourceId ) ) .
			'<p class="description">Una fecha por línea: <code>2026-11-15 13:00-15:30</code> o <code>2026-12-06 12:00-15:00, 17:00-19:00</code>. Si una línea tiene error no se guarda nada.</p>' .
			Ui::textarea( 'lines', $old['lines'] ?? '', 8 ) . Ui::button( 'Guardar fechas' ) . '</form></details>';
		$dates = $schedule['dates'];
		if ( $dates ) {
			$body .= '<table class="widefat striped mo-table"><thead><tr><th>Fecha</th><th>Horarios</th><th></th></tr></thead><tbody>';
			foreach ( $dates as $date => $windows ) {
				$body .= '<tr><td data-label="Fecha">' . esc_html( self::dayLabel( $date ) ) . '</td><td data-label="Horarios">' . esc_html( self::windowsText( $windows ) ?: 'Sin horario' ) . '</td><td>' .
					Ui::formOpen( 'remove_date', array( 'resource_id' => $resourceId, 'date' => $date ), 'mo-inline-form' ) . Ui::button( 'Quitar', 'button-link-delete', '¿Quitar el horario especial de esta fecha?' ) . '</form></td></tr>';
			}
			$body .= '</tbody></table>';
		} else {
			$body .= '<p>No hay fechas específicas.</p>';
		}
		echo Ui::card( 'Fechas específicas', $body ); // phpcs:ignore WordPress.Security.EscapeOutput

		// Cierres.
		$closed = Schedules::rows( $resourceId, 'closed' );
		$body   = Ui::formOpen( 'close_date', array( 'resource_id' => $resourceId ), 'mo-inline-form' ) . '<label>Fecha ' . Ui::input( 'date', '', 'date', array( 'required' => 'required' ) ) . '</label> <label>Nota ' . Ui::input( 'note', '', 'text', array( 'placeholder' => 'Opcional' ) ) . '</label> ' . Ui::button( 'Cerrar fecha', 'button-secondary' ) . '</form>';
		if ( $closed ) {
			$body .= '<ul class="mo-list">';
			foreach ( $closed as $c ) {
				$body .= '<li>' . esc_html( self::dayLabel( $c['date'] ) ) . ( $c['note'] ? ' · ' . esc_html( $c['note'] ) : '' ) . ' ' . Ui::formOpen( 'reopen_date', array( 'resource_id' => $resourceId, 'date' => $c['date'] ), 'mo-inline-form' ) . Ui::button( 'Reabrir', 'button-link' ) . '</form></li>';
			}
			$body .= '</ul>';
		}
		echo Ui::card( 'Fechas cerradas', $body ); // phpcs:ignore WordPress.Security.EscapeOutput

		// Bloqueos.
		$blocks = Schedules::rows( $resourceId, 'block' );
		$body   = Ui::formOpen( 'add_block', array( 'resource_id' => $resourceId ), 'mo-inline-form' ) . '<label>Desde ' . Ui::input( 'start', '', 'datetime-local', array( 'required' => 'required' ) ) . '</label> <label>Hasta ' . Ui::input( 'end', '', 'datetime-local', array( 'required' => 'required' ) ) . '</label> <label>Nota ' . Ui::input( 'note', '', 'text' ) . '</label> ' . Ui::button( 'Bloquear', 'button-secondary' ) . '</form>';
		if ( $blocks ) {
			$body .= '<ul class="mo-list">';
			foreach ( $blocks as $b ) {
				$body .= '<li>' . esc_html( Ui::localDateTime( $b['start_utc'], 'd/m/Y H:i' ) . ' – ' . Ui::localDateTime( $b['end_utc'], 'd/m/Y H:i' ) ) . ( $b['note'] ? ' · ' . esc_html( $b['note'] ) : '' ) . ' ' . Ui::formOpen( 'remove_row', array( 'resource_id' => $resourceId, 'row_id' => $b['id'] ), 'mo-inline-form' ) . Ui::button( 'Quitar', 'button-link-delete' ) . '</form></li>';
			}
			$body .= '</ul>';
		}
		echo Ui::card( 'Bloqueos manuales', $body ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</div>';

		self::preview( $resourceId, $schedule );
		Ui::footer();
	}

	/** Vista previa: espacios por día para un servicio, y citas que quedaron fuera del horario. */
	private static function preview( int $resourceId, array $schedule ): void {
		$services = array_values(
			array_filter(
				Services::all(),
				static function ( $s ) use ( $resourceId ) {
					return $s['resource_id'] === $resourceId && 'inactive' !== $s['status'];
				}
			)
		);
		if ( ! $services ) {
			echo Ui::card( 'Vista previa de espacios', '<p>Crea un servicio para ver cuántos espacios genera este horario.</p>' ); // phpcs:ignore WordPress.Security.EscapeOutput
			return;
		}
		$serviceId = isset( $_GET['preview_service'] ) ? (int) $_GET['preview_service'] : $services[0]['id']; // phpcs:ignore WordPress.Security.NonceVerification
		$service   = Services::find( $serviceId ) ?: $services[0];
		$zone      = Settings::timezone();
		$today     = Ui::localToday();
		$from      = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : $today; // phpcs:ignore WordPress.Security.NonceVerification
		$to        = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : ( new \DateTimeImmutable( $from ) )->modify( '+60 days' )->format( 'Y-m-d' ); // phpcs:ignore WordPress.Security.NonceVerification

		$opts = array();
		foreach ( $services as $s ) {
			$opts[ $s['id'] ] = $s['title'];
		}
		$body = '<form method="get" class="mo-inline-form"><input type="hidden" name="page" value="mo-booking-availability"><input type="hidden" name="resource" value="' . esc_attr( (string) $resourceId ) . '"><label>Servicio ' . Ui::select( 'preview_service', $opts, (string) $service['id'] ) . '</label> <label>Desde ' . Ui::input( 'from', $from, 'date' ) . '</label> <label>Hasta ' . Ui::input( 'to', $to, 'date' ) . '</label> <button class="button">Ver</button></form>';
		try {
			$engine = new Availability( $zone );
			$now    = Plugin::clock()->now();
			$params = Services::availabilityParams( $service );
			$params['min_notice_minutes'] = 0;
			$params['horizon_days']       = 0;
			$params['sale_from']          = null;
			$params['sale_until']         = null;
			$days   = $engine->days( $schedule, $params, $from, $to, $now->modify( '-1 year' ) );
			$total  = 0;
			$byMonth = array();
			$rows    = '';
			foreach ( $days as $date => $d ) {
				$n = count( $d['slots'] );
				if ( ! $n ) {
					continue;
				}
				$total += $n;
				$month  = substr( $date, 0, 7 );
				$byMonth[ $month ] = ( $byMonth[ $month ] ?? 0 ) + $n;
				$rows  .= '<tr><td>' . esc_html( self::dayLabel( $date ) ) . '</td><td>' . esc_html( implode( ' ', array_column( $d['slots'], 'local' ) ) ) . '</td><td>' . $n . '</td></tr>';
			}
			$body .= '<p><strong>' . $total . ' espacios</strong> en el rango' . ( $byMonth ? ' (' . esc_html( implode( ', ', array_map( static function ( $m, $n ) { return $m . ': ' . $n; }, array_keys( $byMonth ), $byMonth ) ) ) . ')' : '' ) . '. Son espacios del horario, no reservas.</p>';
			$body .= $rows ? '<table class="widefat striped"><thead><tr><th>Fecha</th><th>Inicios</th><th>Espacios</th></tr></thead><tbody>' . $rows . '</tbody></table>' : '<p>No hay espacios en este rango.</p>';
		} catch ( DomainException $e ) {
			$body .= '<p class="mo-error">' . esc_html( $e->getMessage() ) . '</p>';
		}

		// Citas activas fuera del horario actual (por cambios de horario): se muestran, no se mueven.
		$conflicts = array();
		foreach ( Bookings::list( array( 'status' => BookingStatus::active(), 'from' => $today, 'limit' => 500 ) ) as $b ) {
			if ( $b['resource_id'] !== $resourceId ) {
				continue;
			}
			$bs = Services::find( $b['service_id'] );
			if ( ! $bs ) {
				continue;
			}
			$p = Services::availabilityParams( $bs );
			$p['min_notice_minutes'] = 0;
			$p['horizon_days']       = 0;
			$p['sale_from']          = null;
			$p['sale_until']         = null;
			if ( ! ( new Availability( $zone ) )->findSlot( $schedule, $p, $b['start'], Plugin::clock()->now()->modify( '-1 year' ) ) ) {
				$conflicts[] = $b;
			}
		}
		if ( $conflicts ) {
			$body .= '<div class="notice notice-warning inline"><p><strong>Citas que quedaron fuera del horario actual</strong> (no se movieron; resuélvelas manualmente):</p><ul>';
			foreach ( $conflicts as $b ) {
				$body .= '<li><a href="' . esc_url( Ui::url( 'mo-booking-bookings', array( 'booking' => $b['id'] ) ) ) . '">' . esc_html( $b['public_ref'] . ' · ' . Ui::localDateTime( $b['start_utc'], 'd/m/Y H:i' ) . ' · ' . $b['client_name'] ) . '</a></li>';
			}
			$body .= '</ul></div>';
		}
		echo Ui::card( 'Vista previa de espacios', $body ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public static function dayLabel( string $date ): string {
		$d    = new \DateTimeImmutable( $date );
		$days = array( 1 => 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb', 'dom' );
		return $days[ (int) $d->format( 'N' ) ] . ' ' . $d->format( 'd/m/Y' );
	}
}
