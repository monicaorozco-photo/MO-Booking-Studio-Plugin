<?php
namespace MOBookingStudio\Admin\Pages;

use MOBookingStudio\Admin\Ui;
use MOBookingStudio\Application\Bookings;
use MOBookingStudio\Application\Services;
use MOBookingStudio\Domain\BookingStatus;
use MOBookingStudio\Infrastructure\Capabilities as Cap;

/**
 * Agenda en lista por día, usable en celular. Vista día, semana o mes.
 */
final class Agenda {
	public static function render(): void {
		$g     = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification
		$view  = (string) ( $g['view'] ?? 'week' );
		$view  = in_array( $view, array( 'day', 'week', 'month' ), true ) ? $view : 'week';
		$from  = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $g['from'] ?? '' ) ) ? $g['from'] : Ui::localToday();
		$start = new \DateTimeImmutable( $from );
		if ( 'week' === $view ) {
			$start = $start->modify( '-' . ( (int) $start->format( 'N' ) - 1 ) . ' days' );
			$end   = $start->modify( '+6 days' );
		} elseif ( 'month' === $view ) {
			$start = $start->modify( 'first day of this month' );
			$end   = $start->modify( 'last day of this month' );
		} else {
			$end = $start;
		}
		$step = array( 'day' => '1 day', 'week' => '7 days', 'month' => '1 month' )[ $view ];
		Ui::header( 'Agenda' );

		$services = array( '' => 'Todos los servicios' );
		foreach ( Services::all() as $s ) {
			$services[ $s['id'] ] = $s['title'];
		}
		$nav  = '<div class="mo-agenda-nav">';
		$nav .= '<a class="button" href="' . esc_url( Ui::url( 'mo-booking-agenda', array( 'view' => $view, 'from' => $start->modify( '-' . $step )->format( 'Y-m-d' ), 'service_id' => $g['service_id'] ?? '' ) ) ) . '" aria-label="Anterior">‹</a> ';
		$nav .= '<a class="button" href="' . esc_url( Ui::url( 'mo-booking-agenda', array( 'view' => $view, 'service_id' => $g['service_id'] ?? '' ) ) ) . '">Hoy</a> ';
		$nav .= '<a class="button" href="' . esc_url( Ui::url( 'mo-booking-agenda', array( 'view' => $view, 'from' => $start->modify( '+' . $step )->format( 'Y-m-d' ), 'service_id' => $g['service_id'] ?? '' ) ) ) . '" aria-label="Siguiente">›</a> ';
		foreach ( array( 'day' => 'Día', 'week' => 'Semana', 'month' => 'Mes' ) as $k => $label ) {
			$nav .= '<a class="button' . ( $k === $view ? ' button-primary' : '' ) . '" href="' . esc_url( Ui::url( 'mo-booking-agenda', array( 'view' => $k, 'from' => $start->format( 'Y-m-d' ), 'service_id' => $g['service_id'] ?? '' ) ) ) . '">' . esc_html( $label ) . '</a> ';
		}
		$nav .= '<form method="get" class="mo-inline-form"><input type="hidden" name="page" value="mo-booking-agenda"><input type="hidden" name="view" value="' . esc_attr( $view ) . '"><input type="hidden" name="from" value="' . esc_attr( $start->format( 'Y-m-d' ) ) . '">' . Ui::select( 'service_id', $services, $g['service_id'] ?? '', array( 'aria-label' => 'Servicio', 'onchange' => 'this.form.submit()' ) ) . '</form>';
		$nav .= '</div>';
		echo $nav; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<h2>' . esc_html( AvailabilityPage::dayLabel( $start->format( 'Y-m-d' ) ) . ( $end != $start ? ' – ' . AvailabilityPage::dayLabel( $end->format( 'Y-m-d' ) ) : '' ) ) . '</h2>';

		$list = Bookings::list(
			array(
				'status'     => array( BookingStatus::PENDING_PAYMENT, BookingStatus::PAYMENT_REVIEW, BookingStatus::CORRECTION_REQUESTED, BookingStatus::PENDING_APPROVAL, BookingStatus::CONFIRMED, BookingStatus::COMPLETED, BookingStatus::NO_SHOW ),
				'from'       => $start->format( 'Y-m-d' ),
				'to'         => $end->format( 'Y-m-d' ),
				'service_id' => $g['service_id'] ?? '',
				'limit'      => 500,
			)
		);
		if ( ! $list ) {
			echo '<p>No hay citas en este periodo.</p>';
			Ui::footer();
			return;
		}
		$byDay = array();
		foreach ( $list as $b ) {
			$byDay[ Ui::localDateTime( $b['start_utc'], 'Y-m-d' ) ][] = $b;
		}
		$canOpen = current_user_can( Cap::BOOKINGS );
		foreach ( $byDay as $day => $items ) {
			echo '<section class="mo-agenda-day"><h3>' . esc_html( AvailabilityPage::dayLabel( $day ) ) . ' · ' . count( $items ) . '</h3><ul class="mo-agenda-list">';
			foreach ( $items as $b ) {
				$name = esc_html( (string) $b['client_name'] );
				echo '<li><span class="mo-when">' . esc_html( Ui::localDateTime( $b['start_utc'], 'H:i' ) . '–' . Ui::localDateTime( $b['end_utc'], 'H:i' ) ) . '</span> ' .
					( $canOpen ? '<a href="' . esc_url( Ui::url( 'mo-booking-bookings', array( 'booking' => $b['id'] ) ) ) . '">' . $name . '</a>' : $name ) .
					' · ' . esc_html( (string) $b['service_title'] ) . ' · ' . (int) $b['participants'] . ' pers. ' . Ui::statusPill( $b['status'] ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</ul></section>';
		}
		Ui::footer();
	}
}
