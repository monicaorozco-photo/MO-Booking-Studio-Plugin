<?php
namespace MOBookingStudio\Domain;

/**
 * Políticas editables de reprogramación, cancelación e inasistencia.
 *
 * Una política tiene rangos de anticipación disjuntos que cubren de 0 horas a infinito.
 * Cada rango es [min_hours, max_hours): incluye el mínimo y excluye el máximo. Ejemplo:
 * "48 o más", "de 24 inclusive a menos de 48", "menos de 24". Una solicitud exactamente
 * a 24 horas cae en el rango [24, 48). Se compara en segundos, sin redondear horas.
 *
 * Campos de cada rango:
 * - charge_type: none | fixed | percent       Penalización.
 * - charge_value: centavos (fixed) o porcentaje (percent).
 * - charge_base: total | service | balance    Base del porcentaje.
 * - deposit: credit | retain | refund         Qué pasa con el anticipo pagado.
 * - new_deposit_cents: anticipo que exige la nueva fecha cuando el anterior se retiene.
 */
final class Policy {
	public const ACTIONS = array( 'reschedule', 'cancel', 'no_show' );

	public const ACTION_LABELS = array(
		'reschedule' => 'Reprogramación',
		'cancel'     => 'Cancelación',
		'no_show'    => 'Inasistencia',
	);

	/**
	 * Valida y ordena los rangos. Lanza excepción con un mensaje claro si son ambiguos.
	 */
	public static function validateRanges( array $ranges ): array {
		if ( ! $ranges ) {
			throw new DomainException( 'invalid_policy', 'La política necesita al menos un rango.' );
		}
		$clean = array();
		foreach ( $ranges as $r ) {
			$min = $r['min_hours'] ?? null;
			$max = $r['max_hours'] ?? null;
			if ( ! is_numeric( $min ) || $min < 0 ) {
				throw new DomainException( 'invalid_policy', 'Las horas de cada rango deben ser números no negativos.' );
			}
			if ( null !== $max && '' !== $max && ( ! is_numeric( $max ) || $max <= $min ) ) {
				throw new DomainException( 'invalid_policy', sprintf( 'El rango que empieza en %s horas debe terminar después de su inicio.', $min ) );
			}
			$type = (string) ( $r['charge_type'] ?? 'none' );
			if ( ! in_array( $type, array( 'none', 'fixed', 'percent' ), true ) ) {
				throw new DomainException( 'invalid_policy', 'El tipo de cargo no es válido.' );
			}
			$value = $r['charge_value'] ?? 0;
			if ( ! is_numeric( $value ) || $value < 0 ) {
				throw new DomainException( 'invalid_policy', 'El importe del cargo debe ser un número no negativo.' );
			}
			if ( 'percent' === $type && $value > 100 ) {
				throw new DomainException( 'invalid_policy', 'El porcentaje del cargo no puede superar 100 %.' );
			}
			$deposit = (string) ( $r['deposit'] ?? 'credit' );
			if ( ! in_array( $deposit, array( 'credit', 'retain', 'refund' ), true ) ) {
				throw new DomainException( 'invalid_policy', 'El tratamiento del anticipo no es válido.' );
			}
			$base = (string) ( $r['charge_base'] ?? 'total' );
			if ( ! in_array( $base, array( 'total', 'service', 'balance' ), true ) ) {
				throw new DomainException( 'invalid_policy', 'La base del porcentaje no es válida.' );
			}
			$newDeposit = (int) ( $r['new_deposit_cents'] ?? 0 );
			if ( $newDeposit < 0 ) {
				throw new DomainException( 'invalid_policy', 'El nuevo anticipo no puede ser negativo.' );
			}
			$clean[] = array(
				'min_hours'         => (float) $min,
				'max_hours'         => ( null === $max || '' === $max ) ? null : (float) $max,
				'charge_type'       => $type,
				'charge_value'      => 'fixed' === $type ? (int) $value : (float) $value,
				'charge_base'       => $base,
				'deposit'           => $deposit,
				'new_deposit_cents' => $newDeposit,
				'allowed'           => ! isset( $r['allowed'] ) || (bool) $r['allowed'],
				'note'              => (string) ( $r['note'] ?? '' ),
			);
		}
		usort(
			$clean,
			static function ( $a, $b ) {
				return $a['min_hours'] <=> $b['min_hours'];
			}
		);
		if ( 0.0 !== $clean[0]['min_hours'] ) {
			throw new DomainException( 'invalid_policy', 'El primer rango debe empezar en 0 horas para cubrir las solicitudes de último momento.' );
		}
		for ( $i = 0, $n = count( $clean ); $i < $n; $i++ ) {
			$isLast = $i === $n - 1;
			if ( $isLast && null !== $clean[ $i ]['max_hours'] ) {
				throw new DomainException( 'invalid_policy', 'El último rango debe quedar abierto ("o más") para cubrir todos los casos.' );
			}
			if ( ! $isLast ) {
				if ( null === $clean[ $i ]['max_hours'] ) {
					throw new DomainException( 'invalid_policy', 'Solo el último rango puede quedar abierto.' );
				}
				if ( abs( $clean[ $i ]['max_hours'] - $clean[ $i + 1 ]['min_hours'] ) > 0.0001 ) {
					throw new DomainException(
						'invalid_policy',
						sprintf( 'Los rangos deben ser continuos: uno termina en %s horas y el siguiente empieza en %s.', self::hours( $clean[ $i ]['max_hours'] ), self::hours( $clean[ $i + 1 ]['min_hours'] ) )
					);
				}
			}
		}
		return $clean;
	}

	/**
	 * Elige el rango según los segundos que faltan para la cita.
	 * Si la cita ya empezó (segundos negativos) se usa el rango de 0 horas.
	 */
	public static function rangeFor( array $ranges, int $secondsBefore ): array {
		$seconds = max( 0, $secondsBefore );
		foreach ( $ranges as $range ) {
			$min = (int) round( $range['min_hours'] * 3600 );
			$max = null === $range['max_hours'] ? null : (int) round( $range['max_hours'] * 3600 );
			if ( $seconds >= $min && ( null === $max || $seconds < $max ) ) {
				return $range;
			}
		}
		throw new DomainException( 'invalid_policy', 'La política no cubre este caso.' );
	}

	/**
	 * Calcula las consecuencias económicas. No mueve dinero: produce un resumen para
	 * mostrar antes de aceptar y para registrar movimientos.
	 *
	 * @param array $booking {total_cents, base_cents, deposit_cents, paid_cents}
	 * @param array|null $newQuote Para reprogramación: {total_cents, deposit_cents}.
	 */
	public static function outcome( string $action, array $range, array $booking, ?array $newQuote = null ): array {
		$total      = (int) $booking['total_cents'];
		$paid       = max( 0, (int) $booking['paid_cents'] );
		$depositReq = (int) $booking['deposit_cents'];
		$base       = (int) ( $booking['base_cents'] ?? $total );

		$penalty = 0;
		if ( 'fixed' === $range['charge_type'] ) {
			$penalty = (int) $range['charge_value'];
		} elseif ( 'percent' === $range['charge_type'] ) {
			switch ( $range['charge_base'] ) {
				case 'service':
					$pctBase = $base;
					break;
				case 'balance':
					$pctBase = max( 0, $total - $paid );
					break;
				default:
					$pctBase = $total;
			}
			$penalty = Money::percentOf( $pctBase, (float) $range['charge_value'] );
		}

		// Parte del dinero pagado que corresponde al anticipo.
		$depositPaid = min( $paid, $depositReq );
		$retained    = 'retain' === $range['deposit'] ? $depositPaid : 0;
		$available   = $paid - $retained;            // Dinero pagado que no se retiene.
		$penaltyPaid = min( $penalty, $available );  // La penalización se cubre primero con ese dinero.
		$penaltyDue  = $penalty - $penaltyPaid;      // Lo que faltaría pagar de penalización.
		$remaining   = $available - $penaltyPaid;    // Dinero que sigue siendo de la persona.

		$out = array(
			'action'            => $action,
			'allowed'           => (bool) ( $range['allowed'] ?? true ),
			'range'             => $range,
			'previous_total'    => $total,
			'paid'              => $paid,
			'retained'          => $retained,
			'penalty'           => $penalty,
			'penalty_from_paid' => $penaltyPaid,
			'penalty_due'       => $penaltyDue,
			'credit'            => 0,
			'refund_due'        => 0,
			'amount_due_now'    => $penaltyDue,
			'new_total'         => null,
			'new_deposit'       => null,
			'price_difference'  => null,
			'new_balance'       => null,
		);

		if ( 'reschedule' === $action ) {
			$newTotal   = $newQuote ? (int) $newQuote['total_cents'] : $total;
			$newDeposit = $newQuote ? (int) $newQuote['deposit_cents'] : $depositReq;
			if ( 'retain' === $range['deposit'] && $range['new_deposit_cents'] > 0 ) {
				$newDeposit = min( $newTotal, (int) $range['new_deposit_cents'] );
			}
			$credit                  = $remaining;
			$out['credit']           = $credit;
			$out['new_total']        = $newTotal;
			$out['new_deposit']      = $newDeposit;
			$out['price_difference'] = $newTotal - $total;
			$out['new_balance']      = $newTotal - min( $credit, $newTotal );
			$out['refund_due']       = max( 0, $credit - $newTotal );
			$out['amount_due_now']   = $penaltyDue + max( 0, $newDeposit - $credit );
		} else {
			// Cancelación o inasistencia: lo que no se retiene ni cubre penalización queda
			// como devolución o crédito pendiente, según la regla.
			if ( 'credit' === $range['deposit'] ) {
				$out['credit'] = $remaining;
			} else {
				$out['refund_due'] = $remaining;
			}
		}
		return $out;
	}

	public static function describeRange( array $range ): string {
		$from = self::hours( $range['min_hours'] );
		if ( null === $range['max_hours'] ) {
			$when = sprintf( 'Con %s horas o más de anticipación', $from );
		} elseif ( 0.0 === (float) $range['min_hours'] ) {
			$when = sprintf( 'Con menos de %s horas de anticipación', self::hours( $range['max_hours'] ) );
		} else {
			$when = sprintf( 'Desde %s horas y menos de %s horas', $from, self::hours( $range['max_hours'] ) );
		}
		if ( empty( $range['allowed'] ) && isset( $range['allowed'] ) ) {
			return $when . ': no se permite.';
		}
		$parts = array();
		switch ( $range['deposit'] ) {
			case 'credit':
				$parts[] = 'el anticipo se conserva a tu favor';
				break;
			case 'retain':
				$parts[] = 'el anticipo no es reembolsable ni transferible';
				break;
			case 'refund':
				$parts[] = 'el anticipo se devuelve';
				break;
		}
		if ( 'fixed' === $range['charge_type'] && $range['charge_value'] > 0 ) {
			$parts[] = 'cargo de ' . Money::format( (int) $range['charge_value'] );
		} elseif ( 'percent' === $range['charge_type'] && $range['charge_value'] > 0 ) {
			$bases   = array(
				'total'   => 'del total',
				'service' => 'de la tarifa del servicio',
				'balance' => 'del saldo',
			);
			$parts[] = sprintf( 'cargo del %s %% %s', self::hours( $range['charge_value'] ), $bases[ $range['charge_base'] ] ?? '' );
		}
		if ( 'retain' === $range['deposit'] && $range['new_deposit_cents'] > 0 ) {
			$parts[] = 'la nueva fecha requiere un anticipo de ' . Money::format( (int) $range['new_deposit_cents'] );
		}
		return $when . ': ' . implode( '; ', $parts ) . '.';
	}

	private static function hours( $value ): string {
		$value = (float) $value;
		return floor( $value ) === $value ? (string) (int) $value : rtrim( rtrim( number_format( $value, 2, '.', '' ), '0' ), '.' );
	}
}
