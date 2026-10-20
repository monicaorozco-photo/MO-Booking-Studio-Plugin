<?php
namespace MOBookingStudio\Admin\Pages;

use MOBookingStudio\Admin\Ui;
use MOBookingStudio\Application\Services;
use MOBookingStudio\Domain\DomainException;
use MOBookingStudio\Domain\Money;
use MOBookingStudio\Domain\Pricing;
use MOBookingStudio\Infrastructure\Settings;

/**
 * Tarifas finales y promociones por servicio, con simulador.
 */
final class RatesPage {
	public static function render(): void {
		$services = Services::all();
		Ui::header( 'Tarifas y descuentos' );
		if ( ! $services ) {
			echo '<p>Primero crea un servicio.</p>';
			Ui::footer();
			return;
		}
		$serviceId = isset( $_GET['service'] ) ? (int) $_GET['service'] : $services[0]['id']; // phpcs:ignore WordPress.Security.NonceVerification
		$service   = Services::find( $serviceId ) ?: $services[0];
		$editId    = isset( $_GET['rule'] ) ? sanitize_text_field( wp_unslash( $_GET['rule'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification

		$opts = array();
		foreach ( $services as $s ) {
			$opts[ $s['id'] ] = $s['title'];
		}
		echo '<form method="get" class="mo-filter"><input type="hidden" name="page" value="mo-booking-rates"><label>Servicio ' . Ui::select( 'service', $opts, (string) $service['id'] ) . '</label> <button class="button">Ver</button></form>'; // phpcs:ignore WordPress.Security.EscapeOutput

		echo '<div class="mo-help"><p><strong>Para preventa, precio regular y cliente frecuente usa la tabla "Precios por temporada" dentro del servicio</strong>: es más sencilla y la preventa termina sola. Esta página es para reglas avanzadas (por ejemplo, un descuento por día de la semana o un cupón); una tarifa final avanzada tiene prioridad sobre la tabla.</p><p><strong>Cómo funcionan:</strong> el precio base del servicio es ' . Ui::money( (int) $service['price_cents'] ) . '. Una <em>tarifa final</em> reemplaza ese precio cuando se cumplen sus condiciones (por ejemplo, fechas de diciembre, preventa o cliente frecuente). Si varias tarifas aplican, gana la de mayor prioridad; dos con la misma prioridad que puedan coincidir no se permiten. Las <em>promociones</em> restan dinero de la tarifa y por defecto no se acumulan. Los extras y personas adicionales no reciben descuento.</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput

		$rules = Services::rules( $service['id'], false );
		if ( $rules ) {
			echo '<table class="widefat striped mo-table"><thead><tr><th>Nombre</th><th>Tipo</th><th>Importe</th><th>Prioridad</th><th>Condiciones</th><th>Activa</th><th></th></tr></thead><tbody>';
			foreach ( $rules as $r ) {
				$value = 'rate' === $r['kind'] ? Money::format( (int) $r['value'] ) : ( 'percent' === $r['amount_type'] ? '-' . $r['value'] . ' %' : '-' . Money::format( (int) $r['value'] ) );
				echo '<tr><td data-label="Nombre">' . esc_html( $r['name'] ) . '</td><td data-label="Tipo">' . ( 'rate' === $r['kind'] ? 'Tarifa final' : 'Promoción' ) . '</td><td data-label="Importe">' . esc_html( $value ) . '</td><td data-label="Prioridad">' . (int) $r['priority'] . '</td><td data-label="Condiciones">' . esc_html( self::describe( $r['conditions'] ) ) . '</td><td data-label="Activa">' . ( $r['active'] ? 'Sí' : 'No' ) . '</td><td>' .
					'<a class="button" href="' . esc_url( Ui::url( 'mo-booking-rates', array( 'service' => $service['id'], 'rule' => $r['id'] ) ) ) . '">Editar</a> ' .
					Ui::formOpen( 'delete_rule', array( 'rule_id' => $r['id'] ), 'mo-inline-form' ) . Ui::button( 'Eliminar', 'button-link-delete', '¿Eliminar esta regla? Las reservas existentes conservan su precio.' ) . '</form></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</tbody></table>';
		} else {
			echo '<p>Este servicio no tiene tarifas especiales: siempre usa el precio base.</p>';
		}

		echo '<div class="mo-grid mo-grid--2">';
		$rule = null;
		if ( $editId && 'new' !== $editId ) {
			$rule = Services::findRule( (int) $editId );
		}
		self::form( $service, $rule );
		self::simulator( $service );
		echo '</div>';
		Ui::footer();
	}

	public static function describe( array $c ): string {
		$parts = array();
		if ( ! empty( $c['session_from'] ) || ! empty( $c['session_to'] ) ) {
			$parts[] = 'Citas ' . ( $c['session_from'] ? 'desde ' . $c['session_from'] : '' ) . ( $c['session_to'] ? ' hasta ' . $c['session_to'] : '' );
		}
		if ( ! empty( $c['booked_from'] ) || ! empty( $c['booked_until'] ) ) {
			$parts[] = 'Reservadas ' . ( $c['booked_from'] ? 'desde ' . $c['booked_from'] : '' ) . ( $c['booked_until'] ? ' hasta ' . $c['booked_until'] : '' );
		}
		$cats = array( 'general' => 'Público general', 'frequent' => 'Clientes frecuentes' );
		if ( ! empty( $c['category'] ) && 'any' !== $c['category'] ) {
			$parts[] = $cats[ $c['category'] ] ?? $c['category'];
		}
		if ( ! empty( $c['weekdays'] ) ) {
			$names   = Ui::weekdays();
			$parts[] = implode( ', ', array_map( static function ( $d ) use ( $names ) { return $names[ $d ] ?? $d; }, $c['weekdays'] ) );
		}
		if ( ! empty( $c['time_from'] ) || ! empty( $c['time_to'] ) ) {
			$parts[] = 'Horario ' . ( $c['time_from'] ?: '00:00' ) . '–' . ( $c['time_to'] ?: '24:00' );
		}
		return $parts ? implode( '; ', $parts ) : 'Siempre';
	}

	private static function form( array $service, ?array $rule ): void {
		$old = Ui::oldInput();
		$r   = $rule ?: array(
			'id'          => 0,
			'name'        => '',
			'kind'        => 'rate',
			'priority'    => 10,
			'amount_type' => 'fixed',
			'value'       => 0,
			'stackable'   => false,
			'active'      => true,
			'conditions'  => array(),
		);
		$c   = $old['conditions'] ?? $r['conditions'];
		$val = $old['value'] ?? ( 'discount' === $r['kind'] && 'percent' === $r['amount_type'] ? (string) $r['value'] : Money::toInput( (int) $r['value'] ) );

		$body  = Ui::formOpen( 'save_rule', array( 'service_id' => $service['id'], 'rule_id' => (int) $r['id'] ) );
		$body .= Ui::row( 'Nombre', Ui::input( 'name', $old['name'] ?? $r['name'], 'text', array( 'class' => 'regular-text', 'required' => 'required', 'placeholder' => 'Ej.: Preventa diciembre' ) ), 'Se muestra en el desglose del precio.', 'name' );
		$body .= Ui::row( 'Tipo', Ui::select( 'kind', array( 'rate' => 'Tarifa final (reemplaza el precio)', 'discount' => 'Promoción (resta al precio)' ), $old['kind'] ?? $r['kind'] ), '', 'kind' );
		$body .= Ui::row( 'Importe', Ui::input( 'value', $val, 'text', array( 'inputmode' => 'decimal', 'class' => 'small-text', 'required' => 'required' ) ) . ' ' . Ui::select( 'amount_type', array( 'fixed' => 'pesos', 'percent' => '% (solo promociones)' ), $old['amount_type'] ?? $r['amount_type'] ), 'Tarifa final: precio en pesos. Promoción: pesos o porcentaje a restar.', 'value' );
		$body .= Ui::row( 'Prioridad', Ui::input( 'priority', $old['priority'] ?? $r['priority'], 'number', array( 'class' => 'small-text' ) ), 'Si varias reglas del mismo tipo aplican, gana la de número mayor. Ej.: preventa 20, regular 10.', 'priority' );
		$body .= '<fieldset class="mo-fieldset"><legend>Condiciones (deja vacío lo que no aplique)</legend>';
		$body .= Ui::row( 'Fecha de la cita desde', Ui::input( 'conditions[session_from]', $c['session_from'] ?? '', 'date' ), '', 'conditions[session_from]' );
		$body .= Ui::row( 'Fecha de la cita hasta', Ui::input( 'conditions[session_to]', $c['session_to'] ?? '', 'date' ), 'Incluye ese día.', 'conditions[session_to]' );
		$body .= Ui::row( 'Reservada desde', Ui::input( 'conditions[booked_from]', Ui::sqlToLocalInput( (string) ( $c['booked_from'] ?? '' ) ), 'datetime-local' ), 'Momento en que se envía la solicitud.', 'conditions[booked_from]' );
		$body .= Ui::row( 'Reservada hasta', Ui::input( 'conditions[booked_until]', Ui::sqlToLocalInput( (string) ( $c['booked_until'] ?? '' ) ), 'datetime-local' ), 'Incluye todo ese minuto. Ej.: 2026-10-31 23:59 cierra la preventa a las 23:59:59.', 'conditions[booked_until]' );
		$body .= Ui::row( 'Tipo de cliente', Ui::select( 'conditions[category]', array( 'any' => 'Cualquiera', 'general' => 'Público general', 'frequent' => 'Cliente frecuente validado' ), $c['category'] ?? 'any' ), '', 'conditions[category]' );
		$days  = '';
		foreach ( Ui::weekdays() as $d => $label ) {
			$days .= '<label class="mo-check mo-check--inline"><input type="checkbox" name="conditions[weekdays][]" value="' . $d . '"' . checked( in_array( $d, array_map( 'intval', (array) ( $c['weekdays'] ?? array() ) ), true ), true, false ) . '> ' . esc_html( $label ) . '</label> ';
		}
		$body .= Ui::row( 'Días de la semana', $days, 'Ninguno marcado = todos.' );
		$body .= Ui::row( 'Hora de inicio desde / hasta', Ui::input( 'conditions[time_from]', $c['time_from'] ?? '', 'time' ) . ' – ' . Ui::input( 'conditions[time_to]', $c['time_to'] ?? '', 'time' ), '' );
		$body .= '</fieldset>';
		$body .= Ui::row( 'Opciones', Ui::checkbox( 'active', (bool) ( $old['active'] ?? $r['active'] ), 'Activa' ) . '<br>' . Ui::checkbox( 'stackable', (bool) ( $old['stackable'] ?? $r['stackable'] ), 'Promoción acumulable con otras acumulables' ) );
		$body .= Ui::button( $rule ? 'Guardar cambios' : 'Agregar regla' ) . ( $rule ? ' <a class="button-link" href="' . esc_url( Ui::url( 'mo-booking-rates', array( 'service' => $service['id'] ) ) ) . '">Cancelar</a>' : '' ) . '</form>';
		echo Ui::card( $rule ? 'Editar regla' : 'Nueva regla', $body ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	private static function simulator( array $service ): void {
		$g    = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification
		$body = '<form method="get"><input type="hidden" name="page" value="mo-booking-rates"><input type="hidden" name="service" value="' . esc_attr( (string) $service['id'] ) . '">';
		$body .= Ui::row( 'Fecha y hora de la cita', Ui::input( 'sim_start', $g['sim_start'] ?? '', 'datetime-local', array( 'required' => 'required' ) ), '', 'sim_start' );
		$body .= Ui::row( 'Fecha y hora de la solicitud', Ui::input( 'sim_request', $g['sim_request'] ?? Ui::sqlToLocalInput( \MOBookingStudio\Plugin::clock()->now()->setTimezone( Settings::timezone() )->format( 'Y-m-d H:i' ) ), 'datetime-local', array( 'required' => 'required' ) ), '', 'sim_request' );
		$body .= Ui::row( 'Tipo de cliente', Ui::select( 'sim_category', array( 'general' => 'Público general', 'frequent' => 'Cliente frecuente' ), $g['sim_category'] ?? 'general' ), '', 'sim_category' );
		$body .= Ui::row( 'Personas', Ui::input( 'sim_people', $g['sim_people'] ?? $service['participants_included'], 'number', array( 'min' => 1 ) ), '', 'sim_people' );
		$body .= '<button class="button">Simular</button></form>';
		if ( ! empty( $g['sim_start'] ) && ! empty( $g['sim_request'] ) ) {
			try {
				$zone  = Settings::timezone();
				$start = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i', Ui::localInputToSql( (string) $g['sim_start'] ), $zone );
				$req   = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i', Ui::localInputToSql( (string) $g['sim_request'] ), $zone );
				if ( ! $start || ! $req ) {
					throw new DomainException( 'invalid_date', 'Fechas no válidas.' );
				}
				$q = ( new Pricing( $zone ) )->quote(
					$service,
					Services::rules( $service['id'] ),
					array(
						'start'        => $start->setTimezone( new \DateTimeZone( 'UTC' ) ),
						'requested_at' => $req->setTimezone( new \DateTimeZone( 'UTC' ) ),
						'category'     => (string) ( $g['sim_category'] ?? 'general' ),
						'participants' => (int) ( $g['sim_people'] ?? 1 ),
					)
				);
				$body .= '<table class="widefat mo-sim"><tbody>';
				foreach ( $q['lines'] as $l ) {
					$body .= '<tr><td>' . esc_html( $l['label'] ) . '</td><td>' . Ui::money( (int) $l['amount'] ) . '</td></tr>';
				}
				$body .= '<tr><th>Total</th><th>' . Ui::money( $q['total_cents'] ) . '</th></tr><tr><td>Anticipo</td><td>' . Ui::money( $q['deposit_cents'] ) . '</td></tr><tr><td>Saldo</td><td>' . Ui::money( $q['balance_cents'] ) . '</td></tr></tbody></table>';
				$body .= '<p class="description">El simulador no revisa disponibilidad; solo calcula el precio.</p>';
			} catch ( DomainException $e ) {
				$body .= '<p class="mo-error">' . esc_html( $e->getMessage() ) . '</p>';
			}
		}
		echo Ui::card( 'Simulador de precio', $body ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public static function save( array $p ): int {
		$kind  = 'discount' === ( $p['kind'] ?? '' ) ? 'discount' : 'rate';
		$type  = 'discount' === $kind && 'percent' === ( $p['amount_type'] ?? '' ) ? 'percent' : 'fixed';
		$value = 'percent' === $type ? (float) str_replace( ',', '.', (string) ( $p['value'] ?? '' ) ) : Money::parse( (string) ( $p['value'] ?? '' ) );
		if ( null === $value ) {
			throw new DomainException( 'invalid_amount', 'El importe no es válido.' );
		}
		$c = (array) ( $p['conditions'] ?? array() );
		$c['booked_from']  = Ui::localInputToSql( (string) ( $c['booked_from'] ?? '' ) );
		$c['booked_until'] = Ui::localInputToSql( (string) ( $c['booked_until'] ?? '' ) );
		$c                 = array_map(
			static function ( $v ) {
				return is_array( $v ) ? array_map( 'sanitize_text_field', $v ) : sanitize_text_field( (string) $v );
			},
			$c
		);
		return Services::saveRule(
			(int) $p['service_id'],
			array(
				'name'        => sanitize_text_field( (string) ( $p['name'] ?? '' ) ),
				'kind'        => $kind,
				'amount_type' => $type,
				'value'       => $value,
				'priority'    => (int) ( $p['priority'] ?? 0 ),
				'active'      => ! empty( $p['active'] ),
				'stackable'   => ! empty( $p['stackable'] ),
				'conditions'  => $c,
			),
			(int) ( $p['rule_id'] ?? 0 ) ?: null
		);
	}
}
