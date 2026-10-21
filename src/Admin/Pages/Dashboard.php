<?php
namespace MOBookingStudio\Admin\Pages;

use MOBookingStudio\Admin\Ui;
use MOBookingStudio\Application\Bookings;
use MOBookingStudio\Application\Proofs;
use MOBookingStudio\Application\Services;
use MOBookingStudio\Domain\BookingStatus;
use MOBookingStudio\Infrastructure\Capabilities as Cap;
use MOBookingStudio\Infrastructure\Db;
use MOBookingStudio\Infrastructure\Schema;
use MOBookingStudio\Infrastructure\Settings;
use MOBookingStudio\Plugin;

final class Dashboard {
	public static function render(): void {
		Bookings::expireDue();
		Ui::header( 'Inicio', ' <a class="page-title-action" href="' . esc_url( Ui::url( 'mo-booking-bookings', array( 'new' => 1 ) ) ) . '">Nueva reserva</a>' );
		$now   = Plugin::clock()->now();
		$zone  = Settings::timezone();
		$today = Ui::localToday();

		echo '<div class="mo-grid">';

		// Pendientes de atención.
		if ( current_user_can( Cap::PAYMENTS ) ) {
			$queue = Proofs::queue();
			$body  = '';
			if ( ! $queue ) {
				$body = '<p>No hay comprobantes por revisar.</p>';
			} else {
				$body .= '<ul class="mo-list">';
				foreach ( array_slice( $queue, 0, 10 ) as $p ) {
					$body .= '<li><a href="' . esc_url( Ui::url( 'mo-booking-bookings', array( 'booking' => (int) $p['booking_id'] ) ) ) . '"><strong>' . esc_html( $p['public_ref'] ) . '</strong> ' . esc_html( $p['client_name'] ) . '</a> · ' . esc_html( Ui::age( $p['created_at'] ) ) .
						( $p['received_late'] ? ' · <span class="mo-pill mo-pill--expired">Tardío: conciliar</span>' : '' ) . '</li>';
				}
				$body .= '</ul>';
				if ( count( $queue ) > 10 ) {
					$body .= '<p><a href="' . esc_url( Ui::url( 'mo-booking-payments' ) ) . '">Ver todos (' . count( $queue ) . ')</a></p>';
				}
			}
			echo Ui::card( sprintf( 'Comprobantes por revisar (%d)', count( $queue ) ), $body ); // phpcs:ignore WordPress.Security.EscapeOutput
		}

		$pending = Bookings::list( array( 'status' => BookingStatus::awaitingPayment(), 'limit' => 20 ) );
		$body    = $pending ? '<ul class="mo-list">' : '<p>Nada pendiente.</p>';
		foreach ( $pending as $b ) {
			$deadline = BookingStatus::PENDING_PAYMENT === $b['status'] ? $b['expires_at'] : ( BookingStatus::CORRECTION_REQUESTED === $b['status'] ? $b['correction_until'] : null );
			$body    .= '<li><a href="' . esc_url( Ui::url( 'mo-booking-bookings', array( 'booking' => $b['id'] ) ) ) . '"><strong>' . esc_html( $b['public_ref'] ) . '</strong> ' . esc_html( $b['client_name'] ) . '</a> ' . Ui::statusPill( $b['status'] ) .
				( $deadline ? ' · vence ' . esc_html( Ui::localDateTime( $deadline, 'd/m H:i' ) ) : '' ) . '</li>';
		}
		$body .= $pending ? '</ul>' : '';
		echo Ui::card( 'Esperando pago o aprobación', $body ); // phpcs:ignore WordPress.Security.EscapeOutput

		// Próximas citas.
		$upcoming = Bookings::list( array( 'status' => array( BookingStatus::CONFIRMED, BookingStatus::PAYMENT_REVIEW, BookingStatus::UNDER_REVIEW ), 'from' => $today, 'to' => $now->modify( '+14 days' )->setTimezone( $zone )->format( 'Y-m-d' ), 'limit' => 30 ) );
		$body     = $upcoming ? '<ul class="mo-list">' : '<p>No hay citas en los próximos 14 días.</p>';
		foreach ( $upcoming as $b ) {
			$summary = Bookings::summary( $b );
			$body   .= '<li><span class="mo-when">' . esc_html( Ui::localDateTime( $b['start_utc'], 'D d/m H:i' ) ) . '</span> <a href="' . esc_url( Ui::url( 'mo-booking-bookings', array( 'booking' => $b['id'] ) ) ) . '">' . esc_html( $b['client_name'] ) . '</a> · ' . esc_html( $b['service_title'] ) .
				( $summary['balance_cents'] > 0 ? ' · saldo ' . Ui::money( $summary['balance_cents'] ) : '' ) . '</li>';
		}
		$body .= $upcoming ? '</ul>' : '';
		echo Ui::card( 'Próximas citas (14 días)', $body ); // phpcs:ignore WordPress.Security.EscapeOutput

		// Dinero: cobrado frente a valor reservado, siempre separados.
		if ( current_user_can( Cap::PAYMENTS ) ) {
			$wpdb       = Db::wpdb();
			$monthStart = \DateTimeImmutable::createFromFormat( '!Y-m-d', $now->setTimezone( $zone )->format( 'Y-m-01' ), $zone );
			$monthEnd   = $monthStart->modify( '+1 month' );
			$received   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(p.amount_cents),0) FROM ' . Schema::table( 'payments' ) . ' p JOIN ' . Schema::table( 'bookings' ) . " b ON b.id = p.booking_id WHERE p.type = 'payment' AND b.is_test = 0 AND p.received_at >= %s AND p.received_at < %s AND p.id NOT IN (SELECT reverses_id FROM " . Schema::table( 'payments' ) . " WHERE type = 'reversal' AND reverses_id IS NOT NULL)", Db::toSql( $monthStart ), Db::toSql( $monthEnd ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$refunded   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(p.amount_cents),0) FROM ' . Schema::table( 'payments' ) . ' p JOIN ' . Schema::table( 'bookings' ) . " b ON b.id = p.booking_id WHERE p.type = 'refund_done' AND b.is_test = 0 AND p.received_at >= %s AND p.received_at < %s", Db::toSql( $monthStart ), Db::toSql( $monthEnd ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$statuses   = "'" . implode( "','", array( BookingStatus::PENDING_PAYMENT, BookingStatus::PAYMENT_REVIEW, BookingStatus::CORRECTION_REQUESTED, BookingStatus::PENDING_APPROVAL, BookingStatus::CONFIRMED, BookingStatus::COMPLETED ) ) . "'";
			$booked     = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(total_cents),0) FROM ' . Schema::table( 'bookings' ) . " WHERE is_test = 0 AND status IN ($statuses) AND start_utc >= %s AND start_utc < %s", Db::toSql( $monthStart ), Db::toSql( $monthEnd ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$body       = '<dl class="mo-kpis"><div><dt>Cobrado este mes (pagos verificados, por fecha de recepción)</dt><dd>' . Ui::money( $received - $refunded ) . '</dd></div>' .
				'<div><dt>Valor de las citas de este mes (no es dinero cobrado)</dt><dd>' . Ui::money( $booked ) . '</dd></div></dl>' .
				'<p class="description">Los reportes completos por categoría y los gastos llegan en la siguiente etapa.</p>';
			echo Ui::card( 'Dinero', $body ); // phpcs:ignore WordPress.Security.EscapeOutput
		}

		// Salud del sistema.
		$wpdb    = Db::wpdb();
		$failed  = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table( 'notifications' ) . " WHERE status = 'failed'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$lastRun = get_option( 'mo_booking_queue_last_run' );
		$body    = '<ul class="mo-list">';
		$body   .= '<li>Mensajes fallidos: <strong>' . $failed . '</strong>' . ( $failed ? ' · <a href="' . esc_url( Ui::url( 'mo-booking-notifications', array( 'status' => 'failed' ) ) ) . '">revisar</a>' : '' ) . '</li>';
		$body   .= '<li>Última ejecución de tareas: ' . ( $lastRun ? esc_html( Ui::localDateTime( gmdate( 'Y-m-d H:i:s', strtotime( $lastRun ) ), 'd/m/Y H:i' ) ) : 'todavía no' ) . '</li>';
		$body   .= '<li>Correo: ' . ( 'live' === Settings::get( 'email_mode' ) ? 'envío real' : '<strong>simulación</strong>' ) . '</li>';
		$body   .= '<li>Servicios publicados: ' . count( Services::all( 'published' ) ) . '</li>';
		$body   .= '</ul>' . Ui::formOpen( 'process_queue' ) . Ui::button( 'Procesar pendientes ahora', 'button-secondary' ) . '</form>';
		echo Ui::card( 'Estado del sistema', $body ); // phpcs:ignore WordPress.Security.EscapeOutput

		echo '</div>';
		Ui::footer();
	}
}
