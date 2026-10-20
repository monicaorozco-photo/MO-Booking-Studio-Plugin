<?php
namespace MOBookingStudio\Admin\Pages;

use MOBookingStudio\Admin\Admin;
use MOBookingStudio\Admin\Ui;
use MOBookingStudio\Application\Proofs;
use MOBookingStudio\Domain\Ledger;
use MOBookingStudio\Infrastructure\Db;
use MOBookingStudio\Infrastructure\Schema;

final class PaymentsPage {
	public static function render(): void {
		Ui::header( 'Pagos y recibos' );
		$queue = Proofs::queue();
		$body  = '';
		if ( ! $queue ) {
			$body = '<p>No hay comprobantes por revisar.</p>';
		} else {
			$body .= '<table class="widefat striped mo-table"><thead><tr><th>Recibido</th><th>Reserva</th><th>Cliente</th><th>Cita</th><th>Anticipo</th><th></th></tr></thead><tbody>';
			foreach ( $queue as $p ) {
				$body .= '<tr><td data-label="Recibido">' . esc_html( Ui::age( $p['created_at'] ) ) . ( $p['received_late'] ? '<br><span class="mo-pill mo-pill--expired">Tardío: conciliar</span>' : '' ) . '</td>' .
					'<td data-label="Reserva"><a href="' . esc_url( Ui::url( 'mo-booking-bookings', array( 'booking' => (int) $p['booking_id'] ) ) ) . '"><strong>' . esc_html( $p['public_ref'] ) . '</strong></a><br>' . Ui::statusPill( $p['booking_status'] ) . '</td>' .
					'<td data-label="Cliente">' . esc_html( (string) $p['client_name'] ) . '</td>' .
					'<td data-label="Cita">' . esc_html( Ui::localDateTime( $p['start_utc'], 'd/m/Y H:i' ) ) . '<br>' . esc_html( (string) $p['service_title'] ) . '</td>' .
					'<td data-label="Anticipo">' . Ui::money( (int) $p['deposit_cents'] ) . '</td>' .
					'<td><a class="button" href="' . esc_url( Admin::proofUrl( (int) $p['id'] ) ) . '" target="_blank" rel="noopener noreferrer">Ver</a> <a class="button button-primary" href="' . esc_url( Ui::url( 'mo-booking-bookings', array( 'booking' => (int) $p['booking_id'] ) ) ) . '">Revisar</a></td></tr>';
			}
			$body .= '</tbody></table>';
		}
		echo Ui::card( 'Comprobantes por revisar (los más antiguos primero)', $body ); // phpcs:ignore WordPress.Security.EscapeOutput

		$wpdb = Db::wpdb();
		$rows = $wpdb->get_results( 'SELECT p.*, b.public_ref FROM ' . Schema::table( 'payments' ) . ' p JOIN ' . Schema::table( 'bookings' ) . ' b ON b.id = p.booking_id ORDER BY p.id DESC LIMIT 50', ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$body = $rows ? '<table class="widefat striped mo-table"><thead><tr><th>Fecha</th><th>Reserva</th><th>Tipo</th><th>Importe</th><th>Detalle</th></tr></thead><tbody>' : '<p>Sin movimientos.</p>';
		foreach ( $rows as $m ) {
			$body .= '<tr><td data-label="Fecha">' . esc_html( Ui::localDateTime( $m['received_at'] ?: $m['created_at'], 'd/m/Y' ) ) . '</td><td data-label="Reserva"><a href="' . esc_url( Ui::url( 'mo-booking-bookings', array( 'booking' => (int) $m['booking_id'] ) ) ) . '">' . esc_html( $m['public_ref'] ) . '</a></td><td data-label="Tipo">' . esc_html( Ledger::TYPE_LABELS[ $m['type'] ] ?? $m['type'] ) . '</td><td data-label="Importe">' . Ui::money( (int) $m['amount_cents'] ) . '</td><td data-label="Detalle">' . esc_html( trim( $m['reference'] . ' ' . $m['note'] ) ) . '</td></tr>';
		}
		$body .= $rows ? '</tbody></table>' : '';
		echo Ui::card( 'Últimos movimientos', $body ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo Ui::card( 'Recibos (próxima etapa)', '<p>Todavía no se emiten recibos en PDF. Cada pago aprobado ya queda registrado con fecha, importe y referencia para emitir su recibo con folio cuando el módulo esté listo.</p>' ); // phpcs:ignore WordPress.Security.EscapeOutput
		Ui::footer();
	}
}
