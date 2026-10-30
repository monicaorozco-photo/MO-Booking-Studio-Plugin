<?php
namespace MOBookingStudio\Application;

use MOBookingStudio\Domain\BookingStatus;
use MOBookingStudio\Domain\DomainException;
use MOBookingStudio\Domain\Money;
use MOBookingStudio\Infrastructure\Audit;
use MOBookingStudio\Infrastructure\Db;
use MOBookingStudio\Plugin;

/**
 * beta.6.5: descuentos y cargos extra manuales de una reserva (varios, con nombre visible
 * para la clienta y nota interna). Cambian el total y el saldo; el anticipo solo baja si el
 * total queda por debajo de él. Sin avisos: la clienta lo ve en su página de reserva.
 *
 * Se guardan en la cotización (quote.adjustments) y el total se recalcula desde el precio
 * del servicio (quote.total_cents), así que se conservan al editar, reprogramar o recalcular.
 * Un descuento en % se calcula sobre el precio del servicio más los cargos extra.
 */
final class Adjustments {
	public const KINDS = array(
		'discount' => 'Descuento',
		'charge'   => 'Cargo extra',
	);

	/** @return array<int,array> Ajustes guardados, cada uno con su importe calculado (negativo = descuento). */
	public static function rows( array $quote ): array {
		$list    = array_values( array_filter( (array) ( $quote['adjustments'] ?? array() ), 'is_array' ) );
		$base    = (int) ( $quote['total_cents'] ?? 0 );
		$charges = 0;
		foreach ( $list as $a ) {
			if ( 'charge' === $a['kind'] ) {
				$charges += (int) $a['value'];
			}
		}
		$out = array();
		foreach ( $list as $a ) {
			if ( 'charge' === $a['kind'] ) {
				$amount = (int) $a['value'];
			} else {
				$amount = -( 'percent' === $a['mode'] ? Money::percentOf( $base + $charges, (float) $a['value'] ) : (int) $a['value'] );
			}
			$out[] = $a + array( 'amount' => $amount );
		}
		return $out;
	}

	/** Total a cobrar: precio del servicio más cargos menos descuentos (una cortesía es $0). */
	public static function total( array $quote ): int {
		if ( ! empty( $quote['courtesy'] ) ) {
			return 0;
		}
		$total = (int) ( $quote['total_cents'] ?? 0 );
		foreach ( self::rows( $quote ) as $a ) {
			$total += (int) $a['amount'];
		}
		return max( 0, $total );
	}

	/** El anticipo nunca supera el total. */
	public static function deposit( int $deposit, int $total ): int {
		return max( 0, min( $deposit, $total ) );
	}

	public static function add( int $bookingId, array $data, int $expectedVersion ): array {
		$kind  = isset( self::KINDS[ $data['kind'] ?? '' ] ) ? (string) $data['kind'] : '';
		$label = trim( sanitize_text_field( (string) ( $data['label'] ?? '' ) ) );
		$note  = trim( sanitize_text_field( (string) ( $data['note'] ?? '' ) ) );
		$mode  = 'discount' === $kind && 'percent' === ( $data['mode'] ?? '' ) ? 'percent' : 'fixed';
		if ( '' === $kind ) {
			throw new DomainException( 'invalid_adjustment', 'Elige si es descuento o cargo extra.' );
		}
		if ( '' === $label ) {
			throw new DomainException( 'invalid_adjustment', 'Escribe el nombre que verá la clienta.', 400, array( 'field' => 'label' ) );
		}
		if ( 'percent' === $mode ) {
			$value = (float) str_replace( ',', '.', (string) ( $data['value'] ?? '' ) );
			if ( $value <= 0 || $value > 100 ) {
				throw new DomainException( 'invalid_adjustment', 'El porcentaje debe ser mayor que 0 y hasta 100.', 400, array( 'field' => 'value' ) );
			}
		} else {
			$value = Money::parse( (string) ( $data['value'] ?? '' ) );
			if ( null === $value || $value <= 0 ) {
				throw new DomainException( 'invalid_adjustment', 'Escribe un importe mayor que $0.', 400, array( 'field' => 'value' ) );
			}
		}
		return self::change(
			$bookingId,
			$expectedVersion,
			static function ( array $list ) use ( $kind, $label, $note, $mode, $value ): array {
				$list[] = array(
					'id'    => bin2hex( random_bytes( 4 ) ),
					'kind'  => $kind,
					'label' => mb_substr( $label, 0, 120 ),
					'note'  => mb_substr( $note, 0, 250 ),
					'mode'  => $mode,
					'value' => $value,
					'at'    => Db::toSql( Plugin::clock()->now() ),
					'by'    => get_current_user_id(),
				);
				return $list;
			},
			'adjustment_added',
			array( 'kind' => $kind, 'label' => $label, 'mode' => $mode, 'value' => $value )
		);
	}

	public static function remove( int $bookingId, string $id, int $expectedVersion ): array {
		return self::change(
			$bookingId,
			$expectedVersion,
			static function ( array $list ) use ( $id ): array {
				$out = array_values(
					array_filter(
						$list,
						static function ( $a ) use ( $id ) {
							return ( $a['id'] ?? '' ) !== $id;
						}
					)
				);
				if ( count( $out ) === count( $list ) ) {
					throw new DomainException( 'not_found', 'Ese ajuste ya no existe.', 404 );
				}
				return $out;
			},
			'adjustment_removed',
			array( 'id' => $id )
		);
	}

	private static function change( int $bookingId, int $expectedVersion, callable $edit, string $auditAction, array $auditData ): array {
		Db::transaction(
			static function () use ( $bookingId, $expectedVersion, $edit, $auditAction, $auditData ) {
				$booking = Bookings::get( $bookingId, true );
				Bookings::assertVersion( $booking, $expectedVersion );
				if ( Bookings::isCourtesy( $booking ) ) {
					throw new DomainException( 'courtesy', 'Esta reserva es cortesía ($0). Quita la cortesía para agregar descuentos o cargos.', 409 );
				}
				if ( ! BookingStatus::isOpen( $booking['status'] ) && BookingStatus::COMPLETED !== $booking['status'] ) {
					throw new DomainException( 'invalid_transition', 'Solo se ajusta el precio de reservas vigentes o realizadas.', 409 );
				}
				$quote                = $booking['quote'];
				$quote['adjustments'] = $edit( (array) ( $quote['adjustments'] ?? array() ) );
				$total                = self::total( $quote );
				$paid                 = (int) Bookings::summary( $booking )['applied_cents'];
				if ( $total < $paid ) {
					throw new DomainException( 'below_paid', sprintf( 'El total quedaría en %s y la clienta ya pagó %s. Reduce el descuento.', Money::format( $total ), Money::format( $paid ) ), 409 );
				}
				Bookings::update(
					$booking,
					array(
						'total_cents'   => $total,
						'deposit_cents' => self::deposit( (int) ( $quote['deposit_cents'] ?? $booking['deposit_cents'] ), $total ),
						'quote'         => Db::json( $quote ),
					)
				);
				Audit::log( 'booking', $bookingId, $auditAction, $auditData + array( 'total_before' => (int) $booking['total_cents'], 'total' => $total ) );
			}
		);
		CalendarSync::enqueue( $bookingId );
		return Bookings::get( $bookingId );
	}
}
