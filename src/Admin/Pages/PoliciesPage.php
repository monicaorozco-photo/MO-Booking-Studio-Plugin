<?php
namespace MOBookingStudio\Admin\Pages;

use MOBookingStudio\Admin\Ui;
use MOBookingStudio\Application\Services;
use MOBookingStudio\Domain\DomainException;
use MOBookingStudio\Domain\Money;
use MOBookingStudio\Domain\Policy;
use MOBookingStudio\Infrastructure\Settings;

/**
 * Editor de políticas de reprogramación, cancelación e inasistencia con simulador.
 * Las horas y los importes son libres. Cada guardado crea una versión nueva.
 */
final class PoliciesPage {
	public static function render(): void {
		$services  = Services::all();
		$serviceId = isset( $_GET['service'] ) ? (int) $_GET['service'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		Ui::header( 'Políticas' );
		$opts = array( 0 => 'Generales (se heredan en todos los servicios)' );
		foreach ( $services as $s ) {
			$opts[ $s['id'] ] = 'Servicio: ' . $s['title'];
		}
		echo '<form method="get" class="mo-filter"><input type="hidden" name="page" value="mo-booking-policies"><label>Aplicar a ' . Ui::select( 'service', $opts, (string) $serviceId ) . '</label> <button class="button">Ver</button></form>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<div class="mo-help"><p>Cada política tiene rangos de anticipación que deben cubrir de 0 horas en adelante sin huecos. Cada rango incluye su mínimo y excluye su máximo: con rangos 0–24 y 24 o más, una solicitud a exactamente 24 horas cae en "24 o más". Los cambios no afectan reservas ya hechas: cada reserva conserva la versión que aceptó. Estas reglas son la política comercial del negocio, no una validación legal.</p></div>';

		$old = Ui::oldInput();
		foreach ( Policy::ACTIONS as $action ) {
			$own     = Services::ownPolicy( $serviceId, $action );
			$current = $serviceId ? Services::currentPolicy( $serviceId, $action ) : $own;
			$ranges  = ( isset( $old['policy_action'] ) && $old['policy_action'] === $action ) ? (array) $old['ranges'] : ( $current ? $current['ranges'] : array() );
			$body    = '';
			if ( $serviceId && ! $own ) {
				$body .= '<p class="description">' . ( $current ? 'Este servicio usa la política general (versión ' . (int) $current['version'] . '). Guarda aquí para crear una propia.' : 'No hay política general ni propia. Sin política no se aplican cargos automáticos.' ) . '</p>';
			} elseif ( $own ) {
				$body .= '<p class="description">Versión vigente: ' . (int) $own['version'] . ' (' . esc_html( Ui::localDateTime( $own['created_at'], 'd/m/Y H:i' ) ) . ').</p>';
				if ( $serviceId ) {
					$body .= Ui::formOpen( 'remove_policy', array( 'service_id' => $serviceId, 'policy_action' => $action ), 'mo-inline-form' ) . Ui::button( 'Volver a la política general', 'button-link', '¿Quitar la política propia del servicio?' ) . '</form>';
				}
			}
			if ( $current ) {
				$body .= '<div class="mo-policy-summary">' . nl2br( esc_html( $current['summary'] ) ) . '</div>';
			}
			$body .= self::form( $serviceId, $action, $ranges );
			echo Ui::card( Policy::ACTION_LABELS[ $action ], $body ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		self::simulator( $serviceId );
		Ui::footer();
	}

	private static function form( int $serviceId, string $action, array $ranges ): string {
		if ( ! $ranges ) {
			$ranges = array( array( 'min_hours' => 0, 'max_hours' => null, 'charge_type' => 'none', 'deposit' => 'credit' ) );
		}
		$ranges[] = array( 'blank' => true );
		$html     = Ui::formOpen( 'save_policy', array( 'service_id' => $serviceId, 'policy_action' => $action ) );
		$html    .= '<table class="widefat mo-repeat mo-policy-table"><thead><tr><th>Desde (horas antes)</th><th>Hasta (horas antes)</th><th>¿Se permite?</th><th>Anticipo pagado</th><th>Cargo</th><th>Base del %</th><th>Nuevo anticipo</th></tr></thead><tbody>';
		foreach ( $ranges as $i => $r ) {
			$blank       = ! empty( $r['blank'] );
			$chargeValue = '';
			if ( ! $blank ) {
				$chargeValue = 'fixed' === ( $r['charge_type'] ?? 'none' ) ? Money::toInput( (int) ( $r['charge_value'] ?? 0 ) ) : (string) ( $r['charge_value'] ?? '' );
			}
			$html .= '<tr>';
			$html .= '<td data-label="Desde">' . Ui::input( "ranges[$i][min_hours]", $blank ? '' : $r['min_hours'], 'number', array( 'min' => 0, 'step' => 'any', 'class' => 'small-text', 'aria-label' => 'Desde horas' ) ) . '</td>';
			$html .= '<td data-label="Hasta">' . Ui::input( "ranges[$i][max_hours]", $blank ? '' : ( $r['max_hours'] ?? '' ), 'number', array( 'min' => 0, 'step' => 'any', 'class' => 'small-text', 'placeholder' => 'o más', 'aria-label' => 'Hasta horas' ) ) . '</td>';
			$html .= '<td data-label="Permitido">' . Ui::select( "ranges[$i][allowed]", array( '1' => 'Sí', '0' => 'No' ), $blank ? '1' : ( ( ! isset( $r['allowed'] ) || $r['allowed'] ) ? '1' : '0' ) ) . '</td>';
			$html .= '<td data-label="Anticipo">' . Ui::select( "ranges[$i][deposit]", array( 'credit' => 'Se conserva a su favor', 'retain' => 'Se retiene (no se devuelve ni transfiere)', 'refund' => 'Se devuelve' ), $blank ? 'credit' : ( $r['deposit'] ?? 'credit' ) ) . '</td>';
			$html .= '<td data-label="Cargo">' . Ui::select( "ranges[$i][charge_type]", array( 'none' => 'Sin cargo', 'fixed' => 'Pesos', 'percent' => 'Porcentaje' ), $blank ? 'none' : ( $r['charge_type'] ?? 'none' ) ) . ' ' . Ui::input( "ranges[$i][charge_value]", $chargeValue, 'text', array( 'class' => 'small-text', 'inputmode' => 'decimal', 'aria-label' => 'Importe del cargo' ) ) . '</td>';
			$html .= '<td data-label="Base">' . Ui::select( "ranges[$i][charge_base]", array( 'total' => 'Total pactado', 'service' => 'Tarifa del servicio', 'balance' => 'Saldo' ), $blank ? 'total' : ( $r['charge_base'] ?? 'total' ) ) . '</td>';
			$html .= '<td data-label="Nuevo anticipo">' . Ui::input( "ranges[$i][new_deposit]", $blank ? '' : ( ! empty( $r['new_deposit_cents'] ) ? Money::toInput( (int) $r['new_deposit_cents'] ) : '' ), 'text', array( 'class' => 'small-text', 'inputmode' => 'decimal', 'placeholder' => '$', 'aria-label' => 'Nuevo anticipo' ) ) . '</td>';
			$html .= '</tr>';
		}
		$html .= '</tbody></table><p class="description">"Nuevo anticipo" aplica a reprogramaciones cuando el anticipo se retiene: es el dinero que exige la nueva fecha, distinto de una penalización. Deja "Desde" vacío para quitar una fila. Un cargo se cubre primero con el dinero ya pagado que no se retiene; lo que falte se muestra como importe a pagar.</p>';
		$html .= Ui::row( 'Texto para clientes (opcional)', Ui::textarea( 'summary', '', 2, array( 'placeholder' => 'Si lo dejas vacío se genera a partir de los rangos.' ) ), '', 'summary' );
		$html .= Ui::button( 'Guardar nueva versión' ) . '</form>';
		return $html;
	}

	private static function simulator( int $serviceId ): void {
		$g     = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification
		$body  = '<form method="get"><input type="hidden" name="page" value="mo-booking-policies"><input type="hidden" name="service" value="' . esc_attr( (string) $serviceId ) . '">';
		$body .= Ui::row( 'Acción', Ui::select( 'sim_action', Policy::ACTION_LABELS, $g['sim_action'] ?? 'reschedule' ), '', 'sim_action' );
		$body .= Ui::row( 'Fecha y hora de la cita', Ui::input( 'sim_start', $g['sim_start'] ?? '', 'datetime-local', array( 'required' => 'required' ) ), '', 'sim_start' );
		$body .= Ui::row( 'Fecha y hora de la solicitud', Ui::input( 'sim_request', $g['sim_request'] ?? '', 'datetime-local', array( 'required' => 'required' ) ), '', 'sim_request' );
		$body .= Ui::row( 'Total pactado ($)', Ui::input( 'sim_total', $g['sim_total'] ?? '1600', 'text', array( 'inputmode' => 'decimal' ) ), '', 'sim_total' );
		$body .= Ui::row( 'Anticipo requerido ($)', Ui::input( 'sim_deposit', $g['sim_deposit'] ?? '500', 'text', array( 'inputmode' => 'decimal' ) ), '', 'sim_deposit' );
		$body .= Ui::row( 'Dinero ya pagado ($)', Ui::input( 'sim_paid', $g['sim_paid'] ?? '500', 'text', array( 'inputmode' => 'decimal' ) ), '', 'sim_paid' );
		$body .= '<button class="button">Simular</button></form>';
		if ( ! empty( $g['sim_start'] ) && ! empty( $g['sim_request'] ) ) {
			try {
				$action = in_array( $g['sim_action'] ?? '', Policy::ACTIONS, true ) ? $g['sim_action'] : 'reschedule';
				$policy = $serviceId ? Services::currentPolicy( $serviceId, $action ) : Services::ownPolicy( 0, $action );
				if ( ! $policy ) {
					throw new DomainException( 'no_policy', 'No hay política para esta acción.' );
				}
				$zone  = Settings::timezone();
				$start = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i', Ui::localInputToSql( (string) $g['sim_start'] ), $zone );
				$req   = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i', Ui::localInputToSql( (string) $g['sim_request'] ), $zone );
				if ( ! $start || ! $req ) {
					throw new DomainException( 'invalid_date', 'Fechas no válidas.' );
				}
				$seconds = $start->getTimestamp() - $req->getTimestamp();
				$range   = Policy::rangeFor( $policy['ranges'], $seconds );
				$o       = Policy::outcome( $action, $range, array( 'total_cents' => (int) Money::parse( (string) $g['sim_total'] ), 'deposit_cents' => (int) Money::parse( (string) $g['sim_deposit'] ), 'paid_cents' => (int) Money::parse( (string) $g['sim_paid'] ) ) );
				$body   .= '<p><strong>Anticipación:</strong> ' . esc_html( number_format( $seconds / 3600, 2 ) ) . ' horas. <strong>Regla:</strong> ' . esc_html( Policy::describeRange( $range ) ) . '</p>';
				$body   .= self::outcomeTable( $o );
			} catch ( DomainException $e ) {
				$body .= '<p class="mo-error">' . esc_html( $e->getMessage() ) . '</p>';
			}
		}
		echo Ui::card( 'Simulador de política', $body ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/** Tabla de consecuencias económicas (también la usa el detalle de reserva). */
	public static function outcomeTable( array $o ): string {
		$rows = array(
			'Precio anterior'                => $o['previous_total'],
			'Dinero recibido'                => $o['paid'],
			'Anticipo retenido'              => $o['retained'],
			'Penalización'                   => $o['penalty'],
			'Penalización cubierta con lo pagado' => $o['penalty_from_paid'],
			'Saldo a favor que se conserva'  => $o['credit'],
			'Devolución pendiente'           => $o['refund_due'],
			'Importe a pagar ahora'          => $o['amount_due_now'],
		);
		if ( null !== $o['new_total'] ) {
			$rows['Nuevo precio']          = $o['new_total'];
			$rows['Diferencia de tarifa']  = $o['price_difference'];
			$rows['Anticipo de la nueva fecha'] = $o['new_deposit'];
			$rows['Saldo de la nueva fecha']    = $o['new_balance'];
		}
		$html = '<table class="widefat mo-sim"><tbody>';
		foreach ( $rows as $label => $value ) {
			$html .= '<tr><td>' . esc_html( $label ) . '</td><td>' . Ui::money( (int) $value ) . '</td></tr>';
		}
		$html .= '</tbody></table>';
		if ( empty( $o['allowed'] ) ) {
			$html .= '<p class="mo-error">La política no permite esta acción con esta anticipación.</p>';
		}
		return $html;
	}

	public static function save( array $p ): void {
		$ranges = array();
		foreach ( (array) ( $p['ranges'] ?? array() ) as $r ) {
			if ( '' === trim( (string) ( $r['min_hours'] ?? '' ) ) ) {
				continue;
			}
			$type  = sanitize_key( (string) ( $r['charge_type'] ?? 'none' ) );
			$value = 'fixed' === $type ? Money::parse( (string) ( $r['charge_value'] ?? '' ) ) : (float) str_replace( ',', '.', (string) ( $r['charge_value'] ?? 0 ) );
			if ( 'fixed' === $type && null === $value ) {
				throw new DomainException( 'invalid_policy', 'El cargo en pesos no es válido.' );
			}
			$ranges[] = array(
				'min_hours'         => str_replace( ',', '.', (string) $r['min_hours'] ),
				'max_hours'         => '' === trim( (string) ( $r['max_hours'] ?? '' ) ) ? null : str_replace( ',', '.', (string) $r['max_hours'] ),
				'allowed'           => '1' === (string) ( $r['allowed'] ?? '1' ),
				'deposit'           => sanitize_key( (string) ( $r['deposit'] ?? 'credit' ) ),
				'charge_type'       => $type,
				'charge_value'      => 'none' === $type ? 0 : $value,
				'charge_base'       => sanitize_key( (string) ( $r['charge_base'] ?? 'total' ) ),
				'new_deposit_cents' => (int) ( Money::parse( (string) ( $r['new_deposit'] ?? '' ) ) ?? 0 ),
			);
		}
		Services::savePolicy( (int) $p['service_id'], sanitize_key( (string) $p['policy_action'] ), $ranges, sanitize_textarea_field( (string) ( $p['summary'] ?? '' ) ) );
	}
}
