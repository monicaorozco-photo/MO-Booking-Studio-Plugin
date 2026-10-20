<?php
namespace MOBookingStudio\Admin\Pages;

use MOBookingStudio\Admin\Admin;
use MOBookingStudio\Admin\Ui;
use MOBookingStudio\Application\Bookings;
use MOBookingStudio\Application\Catalog;
use MOBookingStudio\Application\Clients;
use MOBookingStudio\Application\Notifications;
use MOBookingStudio\Application\Services;
use MOBookingStudio\Domain\BookingStatus;
use MOBookingStudio\Domain\DomainException;
use MOBookingStudio\Domain\Ledger;
use MOBookingStudio\Domain\Money;
use MOBookingStudio\Domain\Pricing;
use MOBookingStudio\Infrastructure\Audit;
use MOBookingStudio\Infrastructure\Capabilities as Cap;
use MOBookingStudio\Infrastructure\Settings;
use MOBookingStudio\Plugin;

final class BookingsPage {
	public static function render(): void {
		$g = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification
		Bookings::expireDue();
		if ( ! empty( $g['booking'] ) ) {
			self::detail( (int) $g['booking'], $g );
			return;
		}
		if ( ! empty( $g['new'] ) ) {
			self::newForm( $g );
			return;
		}
		self::listView( $g );
	}

	private static function listView( array $g ): void {
		Ui::header( 'Reservas', ' <a class="page-title-action" href="' . esc_url( Ui::url( 'mo-booking-bookings', array( 'new' => 1 ) ) ) . '">Nueva reserva</a>' );
		$statusOpts = array( '' => 'Todos los estados', 'active' => 'Activas' ) + BookingStatus::LABELS;
		$services   = array( '' => 'Todos los servicios' );
		foreach ( Services::all() as $s ) {
			$services[ $s['id'] ] = $s['title'];
		}
		echo '<form method="get" class="mo-filter"><input type="hidden" name="page" value="mo-booking-bookings">' .
			Ui::select( 'status', $statusOpts, $g['status'] ?? 'active', array( 'aria-label' => 'Estado' ) ) . ' ' .
			Ui::select( 'service_id', $services, $g['service_id'] ?? '', array( 'aria-label' => 'Servicio' ) ) . ' ' .
			'<label>Desde ' . Ui::input( 'from', $g['from'] ?? '', 'date' ) . '</label> <label>Hasta ' . Ui::input( 'to', $g['to'] ?? '', 'date' ) . '</label> ' .
			Ui::input( 'search', $g['search'] ?? '', 'search', array( 'placeholder' => 'Referencia, nombre, correo o teléfono', 'aria-label' => 'Buscar' ) ) . ' <button class="button">Filtrar</button></form>'; // phpcs:ignore WordPress.Security.EscapeOutput

		$status  = $g['status'] ?? 'active';
		$filters = array(
			'from'       => $g['from'] ?? '',
			'to'         => $g['to'] ?? '',
			'service_id' => $g['service_id'] ?? '',
			'search'     => $g['search'] ?? '',
			'limit'      => 200,
		);
		if ( 'active' === $status ) {
			$filters['status'] = array( BookingStatus::PENDING_PAYMENT, BookingStatus::PAYMENT_REVIEW, BookingStatus::CORRECTION_REQUESTED, BookingStatus::PENDING_APPROVAL, BookingStatus::CONFIRMED );
		} elseif ( $status ) {
			$filters['status'] = array( $status );
		}
		$list = Bookings::list( $filters );
		if ( ! $list ) {
			echo '<p>No hay reservas con estos filtros.</p>';
			Ui::footer();
			return;
		}
		echo '<table class="widefat striped mo-table"><thead><tr><th>Reserva</th><th>Fecha</th><th>Servicio</th><th>Cliente</th><th>Estado</th><th>Pago</th><th>Total</th></tr></thead><tbody>';
		foreach ( $list as $b ) {
			$summary = Bookings::summary( $b );
			echo '<tr><td data-label="Reserva"><a href="' . esc_url( Ui::url( 'mo-booking-bookings', array( 'booking' => $b['id'] ) ) ) . '"><strong>' . esc_html( $b['public_ref'] ) . '</strong></a>' . ( $b['is_test'] ? ' <span class="mo-pill mo-pill--test">PRUEBA</span>' : '' ) . '</td>' .
				'<td data-label="Fecha">' . esc_html( Ui::localDateTime( $b['start_utc'], 'D d/m/Y H:i' ) ) . '</td>' .
				'<td data-label="Servicio">' . esc_html( (string) $b['service_title'] ) . '</td>' .
				'<td data-label="Cliente">' . esc_html( (string) $b['client_name'] ) . ( $b['frequent_claim'] && 'frequent' !== $b['client_category'] ? ' <span class="mo-pill mo-pill--review">Frecuente por revisar</span>' : '' ) . '</td>' .
				'<td data-label="Estado">' . Ui::statusPill( $b['status'] ) . '</td>' .
				'<td data-label="Pago">' . Ui::financialPill( $summary['status'] ) . '</td>' .
				'<td data-label="Total">' . Ui::money( $b['total_cents'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</tbody></table>';
		Ui::footer();
	}

	private static function detail( int $id, array $g ): void {
		$b = Bookings::find( $id );
		if ( ! $b ) {
			wp_die( 'La reserva no existe.' );
		}
		$service = Services::find( $b['service_id'] );
		$client  = Clients::find( $b['client_id'] );
		$summary = Bookings::summary( $b );
		$hidden  = array( 'booking_id' => $b['id'] );
		$canPay  = current_user_can( Cap::PAYMENTS );

		Ui::header( 'Reserva ' . $b['public_ref'], ' <a class="page-title-action" href="' . esc_url( Ui::url( 'mo-booking-bookings' ) ) . '">Volver a reservas</a>' );
		echo '<p class="mo-detail-status">' . Ui::statusPill( $b['status'] ) . ' ' . Ui::financialPill( $summary['status'] ) . ( $b['is_test'] ? ' <span class="mo-pill mo-pill--test">PRUEBA</span>' : '' ) . ' · versión ' . (int) $b['version'] . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput

		echo '<div class="mo-grid mo-grid--2">';

		// Cita.
		$local = $b['start']->setTimezone( Settings::timezone() );
		$body  = '<dl class="mo-dl">';
		$body .= '<dt>Servicio</dt><dd>' . esc_html( $service ? $service['title'] : (string) ( $b['quote']['service_title'] ?? '' ) ) . '</dd>';
		$body .= '<dt>Fecha</dt><dd>' . esc_html( Catalog::dateText( $local ) ) . ', ' . esc_html( $local->format( 'H:i' ) ) . ' h <span class="description">(' . esc_html( $b['timezone'] ) . ')</span></dd>';
		$body .= '<dt>Personas</dt><dd>' . (int) $b['participants'] . ( $b['pets'] ? ' y ' . (int) $b['pets'] . ' mascota(s)' : '' ) . '</dd>';
		if ( BookingStatus::PENDING_PAYMENT === $b['status'] && $b['expires_at'] ) {
			$body .= '<dt>Retención vence</dt><dd><strong>' . esc_html( Ui::localDateTime( $b['expires_at'], 'd/m/Y H:i' ) ) . '</strong></dd>';
		}
		if ( BookingStatus::CORRECTION_REQUESTED === $b['status'] && $b['correction_until'] ) {
			$body .= '<dt>Plazo de corrección</dt><dd><strong>' . esc_html( Ui::localDateTime( $b['correction_until'], 'd/m/Y H:i' ) ) . '</strong></dd>';
		}
		$body .= '<dt>Solicitada</dt><dd>' . esc_html( Ui::localDateTime( $b['created_at'], 'd/m/Y H:i' ) ) . ' · ' . ( 'admin' === $b['source'] ? 'registrada en el panel' : 'en línea' ) . '</dd>';
		foreach ( (array) $b['answers'] as $a ) {
			$body .= '<dt>' . esc_html( $a['label'] ) . '</dt><dd>' . nl2br( esc_html( $a['value'] ) ) . '</dd>';
		}
		$body .= '</dl>';
		echo Ui::card( 'Cita', $body ); // phpcs:ignore WordPress.Security.EscapeOutput

		// Cliente.
		$body  = '<dl class="mo-dl">';
		$body .= '<dt>Nombre</dt><dd>' . esc_html( $b['contact']['name'] ?? '' ) . '</dd>';
		$body .= '<dt>Correo</dt><dd>' . esc_html( $b['contact']['email'] ?? '' ) . '</dd>';
		$body .= '<dt>Teléfono</dt><dd>' . esc_html( $b['contact']['phone'] ?? '' ) . ' <span class="description">' . esc_html( $b['contact']['phone_e164'] ?? '' ) . '</span></dd>';
		$body .= '<dt>WhatsApp</dt><dd>' . ( ! empty( $b['consent']['whatsapp'] ) ? 'Aceptó avisos' : 'No aceptó avisos automáticos' ) . '</dd>';
		$body .= '<dt>Categoría aplicada</dt><dd>' . ( 'frequent' === $b['client_category'] ? 'Cliente frecuente' . ( ! empty( $b['quote']['frequent_check'] ) ? ' (verificado en línea por nombre y contacto)' : '' ) : 'Público general' ) . ( $b['frequent_claim'] && 'frequent' !== $b['client_category'] ? ' · <span class="mo-pill mo-pill--review">Dice que ya tuvo sesión: validar</span>' : '' ) . '</dd>';
		if ( $client ) {
			$body .= '<dt>Expediente</dt><dd><a href="' . esc_url( Ui::url( 'mo-booking-clients', array( 'client' => $client['id'] ) ) ) . '">' . esc_html( $client['name'] ) . '</a> · ' . Clients::sessions( $client ) . ' sesiones · categoría ' . ( 'frequent' === Clients::effectiveCategory( $client ) ? 'frecuente' : 'general' ) . ( $client['needs_review'] ? ' · <span class="mo-pill mo-pill--review">Revisar identidad</span>' : '' ) . '</dd>';
		}
		$body .= '</dl>';
		echo Ui::card( 'Cliente', $body ); // phpcs:ignore WordPress.Security.EscapeOutput

		// Precio y dinero.
		$body = '<table class="widefat mo-sim"><tbody>';
		foreach ( (array) ( $b['quote']['lines'] ?? array() ) as $l ) {
			$body .= '<tr><td>' . esc_html( $l['label'] ) . '</td><td>' . Ui::money( (int) $l['amount'] ) . '</td></tr>';
		}
		$body .= '<tr><th>Total pactado</th><th>' . Ui::money( $b['total_cents'] ) . '</th></tr><tr><td>Anticipo requerido</td><td>' . Ui::money( $b['deposit_cents'] ) . '</td></tr>';
		$body .= '<tr><td>Pagos verificados</td><td>' . Ui::money( $summary['received_cents'] ) . '</td></tr>';
		if ( $summary['retained_cents'] ) {
			$body .= '<tr><td>Anticipo retenido por política</td><td>' . Ui::money( $summary['retained_cents'] ) . '</td></tr>';
		}
		if ( $summary['penalty_cents'] ) {
			$body .= '<tr><td>Penalizaciones</td><td>' . Ui::money( $summary['penalty_cents'] ) . '</td></tr>';
		}
		$body .= '<tr><th>Saldo por cobrar</th><th>' . Ui::money( $summary['balance_cents'] ) . '</th></tr>';
		if ( $summary['excess_cents'] ) {
			$body .= '<tr><td><strong>Excedente pagado</strong> (registra devolución o crédito)</td><td>' . Ui::money( $summary['excess_cents'] ) . '</td></tr>';
		}
		if ( $summary['refund_due_cents'] ) {
			$body .= '<tr><td>Devolución pendiente</td><td>' . Ui::money( $summary['refund_due_cents'] ) . '</td></tr>';
		}
		if ( $summary['refunded_cents'] ) {
			$body .= '<tr><td>Devoluciones realizadas</td><td>' . Ui::money( $summary['refunded_cents'] ) . '</td></tr>';
		}
		$body .= '</tbody></table>';
		$prev  = $b['quote']['previous'] ?? null;
		if ( $prev ) {
			$body .= '<p class="description">Precio anterior: ' . Ui::money( (int) ( $prev['total'] ?? 0 ) ) . '.</p>';
		}
		echo Ui::card( 'Precio y saldo', $body ); // phpcs:ignore WordPress.Security.EscapeOutput

		// Comprobantes y pagos.
		if ( $canPay ) {
			echo Ui::card( 'Comprobantes y pagos', self::paymentsBlock( $b, $summary, $hidden ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		}

		// Acciones de estado.
		echo Ui::card( 'Acciones', self::actionsBlock( $b, $summary, $hidden, $g ) ); // phpcs:ignore WordPress.Security.EscapeOutput

		// Mensajes.
		echo Ui::card( 'Mensajes y enlace privado', self::messagesBlock( $b, $hidden ) ); // phpcs:ignore WordPress.Security.EscapeOutput

		// Notas e historial.
		$body  = $b['internal_notes'] ? '<div class="mo-notes">' . nl2br( esc_html( $b['internal_notes'] ) ) . '</div>' : '<p>Sin notas.</p>';
		$body .= Ui::formOpen( 'add_note', $hidden ) . Ui::textarea( 'note', '', 2, array( 'placeholder' => 'Nota interna (no la ve el cliente)' ) ) . Ui::button( 'Agregar nota', 'button-secondary' ) . '</form>';
		$body .= '<details class="mo-details"><summary>Historial</summary><ul class="mo-history">';
		foreach ( Audit::forEntity( 'booking', $b['id'] ) as $a ) {
			$user  = $a['actor_id'] ? get_userdata( (int) $a['actor_id'] ) : null;
			$who   = $user ? $user->display_name : ( 'system' === $a['actor_type'] ? 'Sistema' : 'Cliente' );
			$data  = json_decode( (string) $a['data'], true ) ?: array();
			$label = 0 === strpos( $a['action'], 'status:' ) ? 'Estado: ' . BookingStatus::label( substr( $a['action'], 7 ) ) : $a['action'];
			$body .= '<li><span class="mo-when">' . esc_html( Ui::localDateTime( $a['created_at'], 'd/m/Y H:i' ) ) . '</span> ' . esc_html( $who ) . ' · ' . esc_html( $label ) . ( ! empty( $data['reason'] ) ? ' · ' . esc_html( $data['reason'] ) : '' ) . '</li>';
		}
		$body .= '</ul></details>';
		echo Ui::card( 'Notas internas e historial', $body ); // phpcs:ignore WordPress.Security.EscapeOutput

		echo '</div>';
		Ui::footer();
	}

	private static function paymentsBlock( array $b, array $summary, array $hidden ): string {
		$proofs = Bookings::proofs( $b['id'] );
		$today  = Ui::localToday();
		$suggest = $summary['deposit_missing'] > 0 ? $summary['deposit_missing'] : $summary['balance_cents'];
		$html   = '';
		$labels = array( 'pending' => 'Por revisar', 'approved' => 'Aprobado', 'rejected' => 'No válido', 'correction' => 'Corrección pedida' );
		if ( $proofs ) {
			foreach ( $proofs as $p ) {
				$html .= '<div class="mo-proof"><p><strong>' . esc_html( $labels[ $p['status'] ] ?? $p['status'] ) . '</strong> · subido ' . esc_html( Ui::localDateTime( $p['created_at'], 'd/m/Y H:i' ) ) . ' · ' . esc_html( strtoupper( pathinfo( $p['stored_name'], PATHINFO_EXTENSION ) ) ) . ' ' . esc_html( size_format( (int) $p['size_bytes'] ) ) .
					( $p['received_late'] ? ' · <span class="mo-pill mo-pill--expired">Llegó tarde: conciliación manual</span>' : '' ) . '</p>';
				if ( $p['review_note'] ) {
					$html .= '<p class="description">' . esc_html( $p['review_note'] ) . '</p>';
				}
				$html .= '<p><a class="button" href="' . esc_url( Admin::proofUrl( (int) $p['id'] ) ) . '" target="_blank" rel="noopener noreferrer">Ver archivo</a> <a class="button-link" href="' . esc_url( Admin::proofUrl( (int) $p['id'], true ) ) . '">Descargar</a></p>';
				if ( 'pending' === $p['status'] ) {
					$html .= '<p class="description">El comprobante es una evidencia: verifica en tu banca que el dinero llegó antes de aprobar.</p>';
					$html .= Ui::formOpen( 'approve_payment', array_merge( $hidden, array( 'proof_id' => $p['id'] ) ), 'mo-inline-form' ) .
						'<label>Importe recibido ' . Ui::input( 'amount', Money::toInput( max( 0, $suggest ) ), 'text', array( 'class' => 'small-text', 'inputmode' => 'decimal', 'required' => 'required' ) ) . '</label> ' .
						'<label>Fecha de recepción ' . Ui::input( 'received_date', $today, 'date', array( 'required' => 'required' ) ) . '</label> ' .
						'<label>Referencia ' . Ui::input( 'reference', '', 'text' ) . '</label> ' .
						Ui::checkbox( 'confirm', true, 'Confirmar la cita si cubre el anticipo' ) . ' ' . Ui::button( 'Aprobar pago' ) . '</form>';
					$html .= '<details class="mo-details"><summary>Pedir corrección o rechazar</summary>' .
						Ui::formOpen( 'request_correction', array_merge( $hidden, array( 'proof_id' => $p['id'] ) ) ) . Ui::textarea( 'reason', '', 2, array( 'placeholder' => 'Qué debe corregir (lo verá la persona)', 'required' => 'required' ) ) . Ui::button( 'Pedir corrección', 'button-secondary' ) . '</form>' .
						Ui::formOpen( 'reject_proof', array_merge( $hidden, array( 'proof_id' => $p['id'] ) ) ) . Ui::input( 'reason', '', 'text', array( 'placeholder' => 'Motivo' ) ) . ' ' . Ui::button( 'Marcar como no válido', 'button-link-delete' ) . '</form></details>';
				}
				$html .= '</div>';
			}
		} else {
			$html .= '<p>Aún no hay comprobantes.</p>';
		}

		$html .= '<details class="mo-details"><summary>Registrar pago sin comprobante (efectivo, saldo el día de la sesión…)</summary>' .
			Ui::formOpen( 'approve_payment', $hidden ) .
			Ui::row( 'Importe', Ui::input( 'amount', Money::toInput( max( 0, $summary['balance_cents'] ) ), 'text', array( 'class' => 'small-text', 'inputmode' => 'decimal', 'required' => 'required' ) ) ) .
			Ui::row( 'Fecha de recepción', Ui::input( 'received_date', $today, 'date', array( 'required' => 'required' ) ) ) .
			Ui::row( 'Medio', Ui::select( 'method', array( 'transfer' => 'Transferencia', 'cash' => 'Efectivo', 'other' => 'Otro' ), 'cash' ) ) .
			Ui::row( 'Referencia o nota', Ui::input( 'reference', '', 'text' ) ) .
			Ui::checkbox( 'confirm', true, 'Confirmar la cita si cubre el anticipo' ) . '<br>' . Ui::button( 'Registrar pago' ) . '</form></details>';

		$movements = Bookings::movements( $b['id'] );
		if ( $movements ) {
			$reversed = array();
			foreach ( $movements as $m ) {
				if ( 'reversal' === $m['type'] ) {
					$reversed[ (int) $m['reverses_id'] ] = true;
				}
			}
			$html .= '<h3>Movimientos</h3><table class="widefat striped"><thead><tr><th>Fecha</th><th>Tipo</th><th>Importe</th><th>Detalle</th></tr></thead><tbody>';
			foreach ( $movements as $m ) {
				$html .= '<tr' . ( isset( $reversed[ (int) $m['id'] ] ) ? ' class="mo-reversed"' : '' ) . '><td>' . esc_html( Ui::localDateTime( $m['received_at'] ?: $m['created_at'], 'd/m/Y' ) ) . '</td><td>' . esc_html( Ledger::TYPE_LABELS[ $m['type'] ] ?? $m['type'] ) . ( isset( $reversed[ (int) $m['id'] ] ) ? ' (revertido)' : '' ) . '</td><td>' . Ui::money( (int) $m['amount_cents'] ) . '</td><td>' . esc_html( trim( $m['method'] . ' ' . $m['reference'] . ' ' . $m['note'] ) ) . '</td></tr>';
			}
			$html .= '</tbody></table>';
			$options = array();
			foreach ( $movements as $m ) {
				if ( 'reversal' !== $m['type'] && ! isset( $reversed[ (int) $m['id'] ] ) ) {
					$options[ $m['id'] ] = '#' . $m['id'] . ' ' . ( Ledger::TYPE_LABELS[ $m['type'] ] ?? $m['type'] ) . ' ' . Money::format( (int) $m['amount_cents'] );
				}
			}
			$html .= '<details class="mo-details"><summary>Registrar devolución, crédito o reverso</summary>' . Ui::formOpen( 'add_movement', $hidden ) .
				Ui::row( 'Tipo', Ui::select( 'type', array( 'refund_due' => 'Devolución pendiente (se debe devolver)', 'refund_done' => 'Devolución realizada (ya transferí)', 'credit' => 'Saldo a favor', 'reversal' => 'Revertir un movimiento registrado por error' ), 'refund_due' ) ) .
				Ui::row( 'Importe', Ui::input( 'amount', '', 'text', array( 'class' => 'small-text', 'inputmode' => 'decimal' ) ), 'No aplica para reversos: se revierte el importe completo.' ) .
				( $options ? Ui::row( 'Movimiento a revertir', Ui::select( 'reverses_id', array( '' => '—' ) + $options, '' ) ) : '' ) .
				Ui::row( 'Referencia', Ui::input( 'reference', '', 'text' ) ) .
				Ui::row( 'Motivo', Ui::input( 'note', '', 'text', array( 'required' => 'required', 'class' => 'regular-text' ) ) ) .
				Ui::button( 'Registrar movimiento', 'button-secondary' ) . '</form><p class="description">Los movimientos no se borran ni se editan: se corrigen con un reverso. Una devolución solo cuenta como realizada cuando la registras aquí.</p></details>';
		}
		return $html;
	}

	private static function actionsBlock( array $b, array $summary, array $hidden, array $g ): string {
		$html   = '';
		$s      = $b['status'];
		$hiddenV = array_merge( $hidden, array( 'version' => $b['version'] ) );
		$base   = Ui::url( 'mo-booking-bookings', array( 'booking' => $b['id'] ) );

		if ( in_array( $s, array( BookingStatus::PENDING_PAYMENT, BookingStatus::PAYMENT_REVIEW, BookingStatus::CORRECTION_REQUESTED, BookingStatus::PENDING_APPROVAL ), true ) ) {
			$html .= Ui::formOpen( 'confirm', $hidden, 'mo-action' ) . ( $summary['deposit_covered'] ? '' : Ui::input( 'override', '', 'text', array( 'placeholder' => 'Motivo de la excepción (falta ' . Money::format( $summary['deposit_missing'] ) . ')', 'class' => 'regular-text' ) ) . ' ' ) . Ui::button( 'Confirmar cita' ) . '</form>';
			$html .= '<details class="mo-details"><summary>Rechazar solicitud</summary>' . Ui::formOpen( 'reject', $hidden ) . Ui::textarea( 'reason', '', 2, array( 'placeholder' => 'Motivo (se enviará a la persona)' ) ) . Ui::button( 'Rechazar y liberar horario', 'button-link-delete', '¿Rechazar la solicitud?' ) . '</form></details>';
		}
		if ( BookingStatus::CONFIRMED === $s ) {
			$html .= Ui::formOpen( 'complete', $hidden, 'mo-action mo-inline-form' ) . Ui::button( 'Marcar sesión completada' ) . '</form> ';
			$preview = Bookings::previewPolicy( $b, 'no_show' );
			$html   .= '<details class="mo-details"><summary>Marcar inasistencia</summary><p>' . esc_html( $preview['range_text'] ) . '</p>' . PoliciesPage::outcomeTable( $preview ) . Ui::formOpen( 'no_show', $hiddenV ) . Ui::button( 'Registrar inasistencia', 'button-secondary', '¿Registrar inasistencia con estas consecuencias?' ) . '</form></details>';
		}
		if ( in_array( $s, array( BookingStatus::COMPLETED, BookingStatus::NO_SHOW ), true ) ) {
			$html .= '<details class="mo-details"><summary>Reabrir (deshacer completada o inasistencia)</summary>' . Ui::formOpen( 'reopen', $hidden ) . Ui::input( 'reason', '', 'text', array( 'placeholder' => 'Motivo', 'required' => 'required' ) ) . ' ' . Ui::button( 'Reabrir', 'button-secondary' ) . '</form></details>';
		}

		if ( BookingStatus::isOpen( $s ) ) {
			// Recalcular con otra categoría.
			$other = 'frequent' === $b['client_category'] ? Pricing::CATEGORY_GENERAL : Pricing::CATEGORY_FREQUENT;
			$label = Pricing::CATEGORY_FREQUENT === $other ? 'Aplicar tarifa de cliente frecuente' : 'Volver a tarifa de público general';
			if ( ( $g['recalc'] ?? '' ) === $other ) {
				try {
					$q     = Bookings::previewRecalculate( $b, $other );
					$html .= '<div class="mo-preview"><p><strong>' . esc_html( $label ) . '</strong>: total ' . Ui::money( $b['total_cents'] ) . ' → <strong>' . Ui::money( $q['total_cents'] ) . '</strong> (diferencia ' . Ui::money( $q['total_cents'] - $b['total_cents'] ) . '). Anticipo ' . Ui::money( $q['deposit_cents'] ) . '. Se conserva la fecha de solicitud original (' . esc_html( Ui::localDateTime( $b['created_at'], 'd/m/Y H:i' ) ) . ').</p>' .
						Ui::formOpen( 'recalculate', array_merge( $hiddenV, array( 'category' => $other ) ) ) . Ui::input( 'reason', '', 'text', array( 'placeholder' => 'Motivo, por ejemplo: validé 3 sesiones previas', 'required' => 'required', 'class' => 'regular-text' ) ) . ' ' . Ui::button( 'Confirmar ajuste' ) . ' <a href="' . esc_url( $base ) . '">Cancelar</a></form></div>';
				} catch ( DomainException $e ) {
					$html .= '<p class="mo-error">' . esc_html( $e->getMessage() ) . '</p>';
				}
			} else {
				$html .= '<p><a class="button" href="' . esc_url( add_query_arg( 'recalc', $other, $base ) ) . '">' . esc_html( $label ) . '…</a></p>';
			}

			// Reprogramar.
			$html .= self::rescheduleBlock( $b, $hiddenV, $g, $base );

			// Cancelar.
			$by = $g['cancel'] ?? '';
			if ( in_array( $by, array( 'client', 'business' ), true ) ) {
				$o     = Bookings::previewPolicy( $b, 'cancel', 'business' === $by );
				$html .= '<div class="mo-preview"><p><strong>' . ( 'business' === $by ? 'Cancelación por parte del negocio' : 'Cancelación solicitada por el cliente' ) . '</strong></p><p>' . esc_html( $o['range_text'] ) . '</p>' . PoliciesPage::outcomeTable( $o ) .
					'<p class="description">No se ejecuta ninguna transferencia. Las devoluciones quedan pendientes hasta que registres que las hiciste.</p>' .
					Ui::formOpen( 'cancel', array_merge( $hiddenV, array( 'by' => $by ) ) ) . Ui::input( 'reason', '', 'text', array( 'placeholder' => 'Motivo (lo verá la persona)', 'required' => 'required', 'class' => 'regular-text' ) ) . ' ' . Ui::button( 'Confirmar cancelación', 'button-link-delete', '¿Cancelar la reserva con estas consecuencias?' ) . ' <a href="' . esc_url( $base ) . '">No cancelar</a></form></div>';
			} else {
				$html .= '<p>Cancelar: <a href="' . esc_url( add_query_arg( 'cancel', 'client', $base ) ) . '">a petición del cliente…</a> · <a href="' . esc_url( add_query_arg( 'cancel', 'business', $base ) ) . '">por parte del negocio…</a></p>';
			}
		}
		return $html ?: '<p>No hay acciones disponibles en este estado.</p>';
	}

	private static function rescheduleBlock( array $b, array $hiddenV, array $g, string $base ): string {
		$service = Services::find( $b['service_id'] );
		if ( ! $service ) {
			return '';
		}
		$html = '<details class="mo-details"' . ( ! empty( $g['rs_date'] ) || ! empty( $g['rs_start'] ) ? ' open' : '' ) . '><summary>Reprogramar</summary>';
		if ( ! empty( $g['rs_start'] ) ) {
			try {
				$start = Catalog::parseStart( (string) $g['rs_start'] );
				$o     = Bookings::previewReschedule( $b, $start );
				$html .= '<p>Nuevo horario: <strong>' . esc_html( Catalog::dateTimeText( $start ) ) . '</strong></p><p>' . esc_html( $o['range_text'] ) . '</p>' . PoliciesPage::outcomeTable( $o );
				if ( $o['amount_due_now'] > 0 ) {
					$html .= '<p class="description">La reserva quedará "Pendiente de pago" en el nuevo horario hasta recibir ' . Ui::money( $o['amount_due_now'] ) . '. El horario anterior se libera al confirmar este cambio.</p>';
				}
				if ( ! empty( $o['allowed'] ) ) {
					$html .= Ui::formOpen( 'reschedule', array_merge( $hiddenV, array( 'start' => $start->format( 'Y-m-d\TH:i:s\Z' ) ) ) ) . Ui::input( 'reason', '', 'text', array( 'placeholder' => 'Motivo', 'required' => 'required', 'class' => 'regular-text' ) ) . ' ' . Ui::button( 'Confirmar cambio' ) . ' <a href="' . esc_url( $base ) . '">Cancelar</a></form>';
				}
			} catch ( DomainException $e ) {
				$html .= '<p class="mo-error">' . esc_html( $e->getMessage() ) . '</p>';
			}
		} else {
			$date  = (string) ( $g['rs_date'] ?? '' );
			$html .= '<form method="get" class="mo-inline-form"><input type="hidden" name="page" value="mo-booking-bookings"><input type="hidden" name="booking" value="' . (int) $b['id'] . '"><label>Nueva fecha ' . Ui::input( 'rs_date', $date, 'date', array( 'required' => 'required' ) ) . '</label> <button class="button">Ver horarios</button></form>';
			if ( $date ) {
				$html .= self::slotLinks( $service, $date, $b['id'], static function ( $iso ) use ( $base ) {
					return add_query_arg( 'rs_start', $iso, $base );
				} );
			}
		}
		return $html . '</details>';
	}

	/** Botones de horarios disponibles para una fecha. */
	private static function slotLinks( array $service, string $date, ?int $excludeBooking, callable $link ): string {
		try {
			$days = Catalog::availability( $service, $date, $date );
		} catch ( DomainException $e ) {
			return '<p class="mo-error">' . esc_html( $e->getMessage() ) . '</p>';
		}
		$day  = $days['days'][0] ?? null;
		if ( ! $day || ! $day['slots'] ) {
			return '<p>No hay horarios ese día.</p>';
		}
		$html = '<div class="mo-slot-links">';
		foreach ( $day['slots'] as $slot ) {
			$html .= $slot['available']
				? '<a class="button" href="' . esc_url( $link( $slot['start'] ) ) . '">' . esc_html( $slot['time'] ) . '</a> '
				: '<span class="button disabled" aria-disabled="true">' . esc_html( $slot['time'] ) . ' · ' . esc_html( $slot['label'] ) . '</span> ';
		}
		return $html . '</div>';
	}

	private static function messagesBlock( array $b, array $hidden ): string {
		$html  = '<p>Enlace privado del cliente (no lo publiques):</p><p><input type="text" readonly class="large-text mo-copy" value="' . esc_attr( Bookings::portalUrl( $b ) ) . '" onclick="this.select()"></p>';
		$html .= Ui::formOpen( 'rotate_link', $hidden, 'mo-inline-form' ) . Ui::button( 'Generar enlace nuevo (anula el anterior)', 'button-link', '¿Anular el enlace actual y crear uno nuevo?' ) . '</form>';
		$events = array(
			'booking_created'      => 'Instrucciones de pago',
			'correction_requested' => 'Corrección solicitada',
			'booking_confirmed'    => 'Cita confirmada',
			'reminder'             => 'Recordatorio',
			'booking_rescheduled'  => 'Cambio de fecha',
			'booking_cancelled'    => 'Cancelación',
		);
		if ( 'off' !== Settings::get( 'whatsapp_mode' ) && ! empty( $b['contact']['phone_e164'] ) ) {
			$html .= '<h3>WhatsApp manual</h3><p class="description">Abre el chat con el texto listo. Abrir el chat no significa que se envió: márcalo después de enviarlo.</p>' . Ui::formOpen( 'whatsapp_open', $hidden, 'mo-inline-form' ) . Ui::select( 'event', $events, 'booking_created' ) . ' ' . Ui::button( 'Abrir WhatsApp', 'button-secondary' ) . '</form>';
		}
		$notes = Notifications::forBooking( $b['id'] );
		if ( $notes ) {
			$labels = Notifications::events();
			$html  .= '<h3>Registro de mensajes</h3><table class="widefat striped"><thead><tr><th>Fecha</th><th>Mensaje</th><th>Canal</th><th>Estado</th><th></th></tr></thead><tbody>';
			foreach ( $notes as $n ) {
				$action = '';
				if ( 'opened_manual' === $n['status'] ) {
					$action = Ui::formOpen( 'whatsapp_sent', array_merge( $hidden, array( 'notification_id' => $n['id'] ) ), 'mo-inline-form' ) . Ui::button( 'Marcar enviado', 'button-link' ) . '</form>';
				} elseif ( 'failed' === $n['status'] ) {
					$action = Ui::formOpen( 'retry_notification', array( 'notification_id' => $n['id'] ), 'mo-inline-form' ) . Ui::button( 'Reintentar', 'button-link' ) . '</form>';
				}
				$channel = array( 'email' => 'Correo', 'whatsapp' => 'WhatsApp automático', 'whatsapp_manual' => 'WhatsApp manual' );
				$html   .= '<tr><td>' . esc_html( Ui::localDateTime( 'pending' === $n['status'] ? $n['send_after'] : $n['updated_at'], 'd/m H:i' ) ) . '</td><td>' . esc_html( $labels[ $n['event'] ]['label'] ?? $n['event'] ) . '<br><span class="description">' . esc_html( $n['recipient'] ) . '</span></td><td>' . esc_html( $channel[ $n['channel'] ] ?? $n['channel'] ) . '</td><td>' . esc_html( Notifications::STATUS_LABELS[ $n['status'] ] ?? $n['status'] ) . ( $n['last_error'] && 'failed' === $n['status'] ? '<br><span class="description">' . esc_html( $n['last_error'] ) . '</span>' : '' ) . '</td><td>' . $action . '</td></tr>';
			}
			$html .= '</tbody></table>';
		}
		return $html;
	}

	// ----- Alta manual -----

	private static function newForm( array $g ): void {
		Ui::header( 'Nueva reserva', ' <a class="page-title-action" href="' . esc_url( Ui::url( 'mo-booking-bookings' ) ) . '">Volver</a>' );
		$services = array_values(
			array_filter(
				Services::all(),
				static function ( $s ) {
					return 'inactive' !== $s['status'];
				}
			)
		);
		if ( ! $services ) {
			echo '<p>Primero crea un servicio.</p>';
			Ui::footer();
			return;
		}
		$opts = array();
		foreach ( $services as $s ) {
			$opts[ $s['id'] ] = $s['title'] . ( 'published' !== $s['status'] ? ' (borrador)' : '' );
		}
		$serviceId = (int) ( $g['service_id'] ?? $services[0]['id'] );
		$service   = Services::find( $serviceId ) ?: $services[0];
		$date      = (string) ( $g['date'] ?? '' );
		echo '<div class="mo-help"><p>Usa esta opción para registrar reservas que recibiste por WhatsApp o en persona. Pasa por las mismas validaciones que una reserva en línea: el horario debe estar libre. Si la persona apartó durante una preventa, indica cuándo lo solicitó para respetar esa tarifa.</p></div>';
		echo '<form method="get" class="mo-filter"><input type="hidden" name="page" value="mo-booking-bookings"><input type="hidden" name="new" value="1"><label>Servicio ' . Ui::select( 'service_id', $opts, (string) $service['id'] ) . '</label> <label>Fecha ' . Ui::input( 'date', $date, 'date', array( 'required' => 'required' ) ) . '</label> <button class="button">Ver horarios</button></form>'; // phpcs:ignore WordPress.Security.EscapeOutput
		if ( $date && empty( $g['start'] ) ) {
			echo self::slotLinks( $service, $date, null, static function ( $iso ) use ( $service, $date ) { // phpcs:ignore WordPress.Security.EscapeOutput
				return Ui::url( 'mo-booking-bookings', array( 'new' => 1, 'service_id' => $service['id'], 'date' => $date, 'start' => $iso ) );
			} );
		}
		if ( ! empty( $g['start'] ) ) {
			$old   = Ui::oldInput();
			$start = Catalog::parseStart( (string) $g['start'] );
			$body  = Ui::formOpen( 'create_booking', array( 'service_id' => $service['id'], 'start' => $start->format( 'Y-m-d\TH:i:s\Z' ) ) );
			$body .= '<p>Horario: <strong>' . esc_html( Catalog::dateTimeText( $start ) ) . '</strong></p>';
			$body .= Ui::row( 'Nombre', Ui::input( 'name', $old['name'] ?? '', 'text', array( 'required' => 'required', 'class' => 'regular-text' ) ), '', 'name' );
			$body .= Ui::row( 'Correo', Ui::input( 'email', $old['email'] ?? '', 'email', array( 'required' => 'required', 'class' => 'regular-text' ) ), 'Se le enviará el enlace privado (si el correo está en envío real).', 'email' );
			$body .= Ui::row( 'Teléfono', Ui::select( 'phone_country', array( 'MX' => 'México +52', 'US' => 'EE. UU. +1' ), $old['phone_country'] ?? 'MX' ) . ' ' . Ui::input( 'phone', $old['phone'] ?? '', 'tel', array( 'required' => 'required' ) ), '', 'phone' );
			$body .= Ui::row( 'Personas', Ui::input( 'participants', $old['participants'] ?? $service['participants_included'], 'number', array( 'min' => 1, 'max' => $service['participants_max'] ) ), '', 'participants' );
			if ( $service['pets_allowed'] ) {
				$body .= Ui::row( 'Mascotas', Ui::input( 'pets', $old['pets'] ?? 0, 'number', array( 'min' => 0 ) ), '', 'pets' );
			}
			foreach ( $service['extras'] as $ex ) {
				$body .= Ui::row( $ex['label'] . ' (' . Money::format( (int) $ex['price_cents'] ) . ')', Ui::input( 'extras[' . $ex['key'] . ']', $old['extras'][ $ex['key'] ] ?? 0, 'number', array( 'min' => 0, 'max' => $ex['max_qty'] ) ) );
			}
			foreach ( $service['questions'] as $q ) {
				$body .= Ui::row( $q['label'], Ui::input( 'answers[' . $q['key'] . ']', $old['answers'][ $q['key'] ] ?? '', 'text', array( 'class' => 'regular-text' ) ), $q['required'] ? 'Obligatoria.' : '' );
			}
			$body .= Ui::row( 'Tarifa', Ui::select( 'category', array( 'general' => 'Público general', 'frequent' => 'Cliente frecuente (ya validado)' ), $old['category'] ?? 'general' ), '', 'category' );
			$body .= Ui::row( 'Fecha y hora en que lo solicitó', Ui::input( 'requested_at', $old['requested_at'] ?? Ui::sqlToLocalInput( Plugin::clock()->now()->setTimezone( Settings::timezone() )->format( 'Y-m-d H:i' ) ), 'datetime-local' ), 'Determina si aplica una preventa. No puede ser futura.', 'requested_at' );
			$body .= Ui::row( 'Anticipo ya recibido ($)', Ui::input( 'paid', $old['paid'] ?? '', 'text', array( 'inputmode' => 'decimal', 'class' => 'small-text' ) ), 'Opcional. Si ya verificaste el depósito, se registra como pago y la cita se confirma si cubre el anticipo.', 'paid' );
			$body .= Ui::row( 'Fecha de recepción del pago', Ui::input( 'paid_date', $old['paid_date'] ?? Ui::localToday(), 'date' ), '', 'paid_date' );
			$body .= Ui::button( 'Crear reserva' ) . '</form>';
			echo Ui::card( 'Datos de la reserva', $body ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		Ui::footer();
	}

	public static function createFromAdmin( array $p ): int {
		$service = Services::get( (int) $p['service_id'] );
		$zone    = Settings::timezone();
		$reqText = Ui::localInputToSql( (string) ( $p['requested_at'] ?? '' ) );
		$req     = $reqText ? \DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $reqText, $zone ) : null;
		$result  = Bookings::create(
			$service,
			array(
				'idempotency_key' => (string) $p['form_key'],
				'start'           => (string) $p['start'],
				'name'            => (string) ( $p['name'] ?? '' ),
				'email'           => (string) ( $p['email'] ?? '' ),
				'phone'           => (string) ( $p['phone'] ?? '' ),
				'phone_country'   => (string) ( $p['phone_country'] ?? 'MX' ),
				'participants'    => (int) ( $p['participants'] ?? 1 ),
				'pets'            => (int) ( $p['pets'] ?? 0 ),
				'extras'          => array_map( 'intval', (array) ( $p['extras'] ?? array() ) ),
				'answers'         => array_map( 'sanitize_text_field', (array) ( $p['answers'] ?? array() ) ),
			),
			array(
				'source'       => 'admin',
				'category'     => (string) ( $p['category'] ?? 'general' ),
				'requested_at' => $req ? $req->setTimezone( new \DateTimeZone( 'UTC' ) ) : null,
			)
		);
		$booking = $result['booking'];
		$paid    = Money::parse( (string) ( $p['paid'] ?? '' ) );
		if ( $paid && $paid > 0 && ! $result['replayed'] ) {
			Bookings::approvePayment(
				$booking['id'],
				array(
					'amount_cents'  => $paid,
					'received_date' => (string) ( $p['paid_date'] ?? '' ),
					'reference'     => 'Registrado al crear la reserva',
					'confirm'       => true,
					'form_key'      => 'create-' . (string) $p['form_key'],
				)
			);
		}
		return (int) $booking['id'];
	}
}
