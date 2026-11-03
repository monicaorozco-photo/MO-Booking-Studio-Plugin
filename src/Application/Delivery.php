<?php
namespace MOBookingStudio\Application;

use MOBookingStudio\Domain\BookingStatus;
use MOBookingStudio\Domain\BusinessDays;
use MOBookingStudio\Domain\DomainException;
use MOBookingStudio\Domain\Money;
use MOBookingStudio\Infrastructure\Audit;
use MOBookingStudio\Infrastructure\Db;
use MOBookingStudio\Infrastructure\Schema;
use MOBookingStudio\Infrastructure\Settings;
use MOBookingStudio\Plugin;

/**
 * beta.6.6: fechas de entrega, entrega rápida agregada por la clienta y sesión realizada
 * automática.
 *
 * - Cada servicio indica sus días hábiles de entrega (normal y rápida) como rango. Se
 *   cuentan desde el día siguiente a la sesión, sin fines de semana ni días festivos
 *   (BusinessDays). Si la sesión se liquida después, se cuentan desde el día del pago final.
 * - La clienta puede agregar la entrega rápida desde su página hasta que termina el día de
 *   la sesión. Se suma al total y al saldo (el anticipo no cambia); les llega aviso a las dos.
 * - 15 minutos después de que termina la sesión, una cita confirmada se marca como realizada
 *   y la clienta recibe "Gracias por tu sesión" con la fecha de entrega.
 */
final class Delivery {
	/** Minutos después del final de la sesión para marcarla como realizada. */
	public const COMPLETE_AFTER_MINUTES = 15;

	public static function hasFast( array $booking ): bool {
		return ! empty( ( (array) ( $booking['quote']['extras_input'] ?? array() ) )[ Services::FAST_KEY ] );
	}

	/** @return array{0:int,1:int}|null Rango de días hábiles del servicio (null = sin fechas). */
	public static function days( ?array $service, bool $fast ): ?array {
		if ( ! $service ) {
			return null;
		}
		$min = (int) ( $service[ $fast ? 'fast_days_min' : 'delivery_days_min' ] ?? 0 );
		$max = (int) ( $service[ $fast ? 'fast_days_max' : 'delivery_days_max' ] ?? 0 );
		if ( $max <= 0 ) {
			return null;
		}
		return array( max( 1, min( $min ?: $max, $max ) ), $max );
	}

	/** Día del que se cuentan los días: el de la sesión o, si se liquidó después, el del pago final. */
	public static function baseDate( array $booking ): \DateTimeImmutable {
		$zone    = Settings::timezone();
		$session = $booking['start']->setTimezone( $zone )->setTime( 0, 0 );
		$summary = Bookings::summary( $booking );
		if ( $summary['balance_cents'] > 0 ) {
			return $session;
		}
		$last = null;
		foreach ( Bookings::movements( $booking['id'] ) as $m ) {
			if ( 'payment' === $m['type'] ) {
				$at   = Db::fromSql( $m['received_at'] ?: $m['created_at'] );
				$last = $at && ( ! $last || $at > $last ) ? $at : $last;
			}
		}
		$paid = $last ? $last->setTimezone( $zone )->setTime( 0, 0 ) : null;
		return $paid && $paid > $session ? $paid : $session;
	}

	/**
	 * Fechas de entrega: {from, to} o null si el servicio no tiene días configurados.
	 *
	 * @return array{from:\DateTimeImmutable,to:\DateTimeImmutable}|null
	 */
	public static function range( array $booking, ?bool $fast = null, ?\DateTimeImmutable $base = null ): ?array {
		$days = self::days( Services::find( $booking['service_id'] ), null === $fast ? self::hasFast( $booking ) : $fast );
		if ( ! $days ) {
			return null;
		}
		$base = $base ?? self::baseDate( $booking );
		return array( 'from' => BusinessDays::after( $base, $days[0] ), 'to' => BusinessDays::after( $base, $days[1] ) );
	}

	public static function dateText( \DateTimeImmutable $date ): string {
		return Catalog::dateText( $date );
	}

	/** "entre el lunes 23 y el miércoles 25 de noviembre de 2026" (o un solo día). */
	public static function rangeText( array $range ): string {
		if ( $range['from']->format( 'Y-m-d' ) === $range['to']->format( 'Y-m-d' ) ) {
			return 'el ' . self::dateText( $range['to'] );
		}
		$from = self::dateText( $range['from'] );
		if ( $range['from']->format( 'Y' ) === $range['to']->format( 'Y' ) ) {
			// "entre el viernes 27 de noviembre y el martes 1 de diciembre de 2026".
			$from = (string) preg_replace( '/ de \d{4}$/', '', $from );
			if ( $range['from']->format( 'm' ) === $range['to']->format( 'm' ) ) {
				// "entre el martes 24 y el miércoles 25 de noviembre de 2026".
				$from = (string) preg_replace( '/ de [a-záéíóúñ]+$/u', '', $from );
			}
		}
		return 'entre el ' . $from . ' y el ' . self::dateText( $range['to'] );
	}

	// ----- Entrega rápida desde la página de reserva -----

	/** ¿Puede la clienta agregar la entrega rápida ahora? Hasta que termina el día de la sesión. */
	public static function canAddFast( array $booking ): bool {
		$service = Services::find( $booking['service_id'] );
		if ( ! $service || empty( $service['fast_delivery']['enabled'] ) || self::hasFast( $booking ) || Bookings::isCourtesy( $booking ) ) {
			return false;
		}
		if ( ! in_array( $booking['status'], array_merge( BookingStatus::awaitingPayment(), array( BookingStatus::CONFIRMED, BookingStatus::COMPLETED ) ), true ) ) {
			return false;
		}
		$endOfDay = $booking['start']->setTimezone( Settings::timezone() )->setTime( 23, 59, 59 );
		return Plugin::clock()->now() <= $endOfDay;
	}

	/**
	 * La clienta agrega la entrega rápida: se suma como un extra más con el precio vigente
	 * del servicio, sin volver a calcular el resto del precio. Avisa a la clienta y a ti.
	 */
	public static function addFast( int $bookingId ): array {
		Db::transaction(
			static function () use ( $bookingId ) {
				$booking = Bookings::get( $bookingId, true );
				if ( ! self::canAddFast( $booking ) ) {
					throw new DomainException( 'fast_unavailable', 'La entrega rápida ya no se puede agregar a esta reserva. Escríbenos si necesitas ayuda.', 409 );
				}
				$fast  = Services::get( $booking['service_id'] )['fast_delivery'];
				$price = max( 0, (int) $fast['price_cents'] );
				$quote = $booking['quote'];
				$quote['lines'][]                              = array(
					'kind'     => 'extra',
					'key'      => Services::FAST_KEY,
					'label'    => (string) $fast['label'],
					'quantity' => 1,
					'unit'     => $price,
					'amount'   => $price,
				);
				$quote['extras_input']                         = (array) ( $quote['extras_input'] ?? array() );
				$quote['extras_input'][ Services::FAST_KEY ]   = 1;
				$quote['total_cents']                          = (int) ( $quote['total_cents'] ?? 0 ) + $price;
				$quote['extras_cents']                         = (int) ( $quote['extras_cents'] ?? 0 ) + $price;
				$quote['fast_added_by_client']                 = Db::toSql( Plugin::clock()->now() );
				$total                                         = Adjustments::total( $quote );
				Bookings::update(
					$booking,
					array(
						'total_cents' => $total,
						'quote'       => Db::json( $quote ),
					)
				);
				Audit::log( 'booking', $bookingId, 'fast_delivery_added', array( 'price' => $price, 'total' => $total ), 'client' );
			}
		);
		$booking = Bookings::get( $bookingId );
		Notifications::bookingEvent( 'fast_delivery_added', $booking );
		CalendarSync::enqueue( $bookingId );
		return $booking;
	}

	// ----- Sesión realizada automática -----

	/**
	 * Marca como realizadas las citas confirmadas que terminaron hace 15 minutos o más y
	 * envía "Gracias por tu sesión". Las que terminaron hace más de 3 días (por ejemplo,
	 * si las tareas programadas estuvieron detenidas) se marcan sin enviar el correo.
	 */
	public static function completeDue( int $limit = 50 ): int {
		$wpdb  = Db::wpdb();
		$now   = Plugin::clock()->now();
		$limit = max( 1, $limit );
		$ids   = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'bookings' ) . " WHERE status = %s AND end_utc <= %s AND trashed_at IS NULL ORDER BY end_utc LIMIT %d", BookingStatus::CONFIRMED, Db::toSql( $now->modify( '-' . self::COMPLETE_AFTER_MINUTES . ' minutes' ) ), $limit ) ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$done  = 0;
		foreach ( $ids as $id ) {
			try {
				$booking = Bookings::complete( (int) $id, 'Sesión realizada (automático, 15 minutos después de terminar).' );
			} catch ( DomainException $e ) {
				continue;
			}
			++$done;
			if ( $booking['end'] > $now->modify( '-3 days' ) ) {
				Notifications::bookingEvent( 'session_completed', $booking );
			}
		}
		return $done;
	}

	/** Al liquidar una sesión ya realizada: aviso con la fecha de entrega contada desde el pago. */
	public static function afterPayment( array $booking ): void {
		if ( BookingStatus::COMPLETED === $booking['status'] && Bookings::summary( $booking )['balance_cents'] <= 0 && self::range( $booking ) ) {
			Notifications::bookingEvent( 'session_paid_delivery', $booking );
		}
	}

	/** Datos de entrega para los avisos ({{entrega}}, {{entrega_rango}}, {{aviso_saldo}}). */
	public static function vars( array $booking ): array {
		$range   = self::range( $booking );
		$balance = (int) Bookings::summary( $booking )['balance_cents'];
		return array(
			'entrega'       => $range ? 'a más tardar el ' . self::dateText( $range['to'] ) : '',
			'entrega_rango' => $range ? self::rangeText( $range ) : '',
			'aviso_saldo'   => $balance > 0 ? sprintf( 'Recuerda que los días de entrega empiezan a correr cuando tu sesión esté liquidada. Tu saldo pendiente es de %s.', Money::format( $balance ) ) : '',
		);
	}
}
