<?php
namespace MOBookingStudio\Application;

use MOBookingStudio\Domain\BookingStatus;
use MOBookingStudio\Domain\DomainException;
use MOBookingStudio\Domain\Money;
use MOBookingStudio\Infrastructure\Audit;
use MOBookingStudio\Infrastructure\Settings;
use MOBookingStudio\Plugin;

/**
 * beta.6: la clienta cambia la fecha de su sesión desde "Mi reserva".
 *
 * Reglas: se activa en Ajustes (apagado por omisión); una sola vez por reserva; con un
 * mínimo de horas antes de la cita actual (36 por omisión). Usa el mismo motor que la
 * re-agenda del panel: recalcula el precio para la nueva fecha conservando su tipo de
 * cliente y su fecha de solicitud (si reservó en preventa, conserva la preventa), aplica la
 * regla de cambio que aceptó al reservar y actualiza Google Calendar. Si la nueva fecha
 * cuesta más, la clienta debe aceptar la diferencia antes de confirmar. A la administradora
 * le llega un aviso. Los cambios que hace la administradora desde el panel no cuentan.
 */
final class SelfReschedule {
	public const REASON = 'Cambio de fecha hecho por la clienta desde su página de reserva.';

	public static function enabled(): bool {
		return (bool) Settings::get( 'self_reschedule_enabled' );
	}

	public static function used( array $booking ): bool {
		return (int) ( $booking['quote']['self_reschedules'] ?? 0 ) > 0;
	}

	/**
	 * Lo que ve la clienta en "Mi reserva". null = no se muestra nada (apagado o reserva cerrada).
	 *
	 * @return array{can:bool,message:string,policy:string}|null
	 */
	public static function status( array $booking ): ?array {
		if ( ! self::enabled() || ! BookingStatus::isOpen( $booking['status'] ) ) {
			return null;
		}
		if ( self::used( $booking ) ) {
			return array( 'can' => false, 'message' => (string) Settings::get( 'self_reschedule_used_text' ), 'policy' => '' );
		}
		$hours = (float) Settings::get( 'self_reschedule_min_hours' );
		if ( Plugin::clock()->now() > $booking['start']->modify( sprintf( '-%d minutes', (int) round( $hours * 60 ) ) ) ) {
			return array( 'can' => false, 'message' => sprintf( 'Los cambios de fecha se hacen con mínimo %s horas de anticipación. Si necesitas ayuda, escríbenos.', self::hoursText( $hours ) ), 'policy' => '' );
		}
		return array( 'can' => true, 'message' => '', 'policy' => (string) Settings::get( 'self_reschedule_text' ) );
	}

	private static function hoursText( float $hours ): string {
		return floor( $hours ) === $hours ? (string) (int) $hours : rtrim( rtrim( number_format( $hours, 1, '.', '' ), '0' ), '.' );
	}

	private static function assertCan( array $booking ): void {
		$status = self::status( $booking );
		if ( ! $status ) {
			throw new DomainException( 'reschedule_unavailable', 'El cambio de fecha no está disponible para esta reserva.', 409 );
		}
		if ( ! $status['can'] ) {
			throw new DomainException( 'reschedule_unavailable', $status['message'], 409 );
		}
	}

	/**
	 * Fechas y horarios para elegir. Sin precios por día: el precio exacto (que conserva su
	 * preventa) se muestra al elegir el horario.
	 */
	public static function availability( array $booking, string $from, string $to ): array {
		self::assertCan( $booking );
		$service = Services::get( $booking['service_id'] );
		$data    = Catalog::availability( $service, $from, $to, (string) $booking['client_category'] );
		$current = $booking['start']->getTimestamp();
		foreach ( $data['days'] as &$day ) {
			unset( $day['price'], $day['price_txt'], $day['reference_txt'], $day['price_type'], $day['special'], $day['saving_txt'] );
			foreach ( $day['slots'] as &$slot ) {
				if ( '' !== (string) $slot['start'] && strtotime( (string) $slot['start'] ) === $current ) {
					$slot['available'] = false;
					$slot['label']     = 'Tu horario actual';
				}
			}
			unset( $slot );
		}
		unset( $day );
		unset( $data['note'], $data['presale_txt'] );
		return $data;
	}

	/** Lo que pasaría con el cambio: fecha nueva, total nuevo, diferencia y lo que falta pagar. */
	public static function preview( array $booking, \DateTimeImmutable $newStart ): array {
		self::assertCan( $booking );
		$outcome = Bookings::previewReschedule( $booking, $newStart );
		if ( empty( $outcome['allowed'] ) ) {
			throw new DomainException( 'not_allowed', 'Tu regla de cambio no permite mover la cita con esta anticipación. Escríbenos para ayudarte.', 409 );
		}
		$local = $newStart->setTimezone( Settings::timezone() );
		$diff  = (int) $outcome['quote']['total_cents'] - (int) $booking['total_cents'];
		return array(
			'start'           => $newStart->format( 'c' ),
			'date_txt'        => Catalog::dateText( $local ),
			'time_txt'        => $local->format( 'H:i' ),
			'old_total_txt'   => Money::format( $booking['total_cents'] ),
			'new_total_txt'   => Money::format( $outcome['quote']['total_cents'] ),
			'difference'      => $diff,
			'difference_txt'  => ( $diff < 0 ? '−' : '+' ) . Money::format( abs( $diff ) ),
			'due_now'         => (int) $outcome['amount_due_now'],
			'due_now_txt'     => $outcome['amount_due_now'] > 0 ? Money::format( (int) $outcome['amount_due_now'] ) : '',
			'needs_accept'    => $diff > 0,
		);
	}

	public static function apply( int $bookingId, \DateTimeImmutable $newStart, bool $acceptedDifference ): array {
		$booking = Bookings::get( $bookingId );
		$preview = self::preview( $booking, $newStart );
		if ( $preview['needs_accept'] && ! $acceptedDifference ) {
			throw new DomainException( 'difference_not_accepted', 'Para continuar, acepta la diferencia de precio de la nueva fecha.', 400, array( 'field' => 'accept_difference' ) );
		}
		$oldLocal = $booking['start']->setTimezone( Settings::timezone() );
		$updated  = Bookings::reschedule( $bookingId, $newStart, self::REASON, (int) $booking['version'], true );
		Audit::log( 'booking', $bookingId, 'self_rescheduled', array( 'from' => $booking['start_utc'], 'to' => $updated['start_utc'], 'difference' => $preview['difference'] ) );
		Notifications::bookingEvent( 'client_rescheduled', $updated, array( 'reason' => Catalog::dateText( $oldLocal ) . ' ' . $oldLocal->format( 'H:i' ) . ' hrs' ) );
		return $updated;
	}
}
