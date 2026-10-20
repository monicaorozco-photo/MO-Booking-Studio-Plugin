<?php
namespace MOBookingStudio\Domain;

/**
 * Deriva la situación financiera de una reserva a partir de movimientos inmutables.
 *
 * Tipos de movimiento:
 * - payment:      dinero recibido y verificado por la administradora (+).
 * - refund_due:   devolución que se debe a la persona (no mueve dinero).
 * - refund_done:  devolución realizada y registrada manualmente (-).
 * - retention:    parte del dinero pagado que el negocio conserva por política.
 * - penalty:      cargo por política que aún debe pagarse.
 * - credit:       saldo a favor de la persona.
 * - reversal:     anula otro movimiento (reverses_id) sin borrarlo.
 */
final class Ledger {
	public const NONE             = 'none';
	public const IN_REVIEW        = 'in_review';
	public const DEPOSIT_PARTIAL  = 'deposit_partial';
	public const DEPOSIT_COVERED  = 'deposit_covered';
	public const PAID             = 'paid';
	public const REFUND_PENDING   = 'refund_pending';
	public const REFUND_RECORDED  = 'refund_recorded';

	public const LABELS = array(
		self::NONE            => 'Sin pago',
		self::IN_REVIEW       => 'Comprobante en revisión',
		self::DEPOSIT_PARTIAL => 'Anticipo parcial',
		self::DEPOSIT_COVERED => 'Anticipo cubierto',
		self::PAID            => 'Liquidada',
		self::REFUND_PENDING  => 'Devolución pendiente',
		self::REFUND_RECORDED => 'Devolución registrada',
	);

	public const TYPE_LABELS = array(
		'payment'     => 'Pago recibido',
		'refund_due'  => 'Devolución pendiente',
		'refund_done' => 'Devolución realizada',
		'retention'   => 'Anticipo retenido',
		'penalty'     => 'Penalización',
		'credit'      => 'Saldo a favor',
		'reversal'    => 'Reverso',
	);

	/**
	 * @param array $movements Lista de {id, type, amount_cents, reverses_id}.
	 */
	public static function summarize( array $movements, int $totalCents, int $depositCents, bool $proofPending = false ): array {
		$reversed = array();
		foreach ( $movements as $m ) {
			if ( 'reversal' === $m['type'] && ! empty( $m['reverses_id'] ) ) {
				$reversed[ (int) $m['reverses_id'] ] = true;
			}
		}
		$sum = array(
			'payment'     => 0,
			'refund_due'  => 0,
			'refund_done' => 0,
			'retention'   => 0,
			'penalty'     => 0,
			'credit'      => 0,
		);
		foreach ( $movements as $m ) {
			if ( 'reversal' === $m['type'] || isset( $reversed[ (int) $m['id'] ] ) ) {
				continue;
			}
			if ( isset( $sum[ $m['type'] ] ) ) {
				$sum[ $m['type'] ] += (int) $m['amount_cents'];
			}
		}
		$received   = $sum['payment'];
		$net        = $received - $sum['refund_done'];
		$refundOpen = max( 0, $sum['refund_due'] - $sum['refund_done'] );
		// Dinero que cuenta para esta cita: lo recibido menos devoluciones, anticipos
		// retenidos por política y penalizaciones. Así una retención no se suma dos veces.
		$applied = $net - $sum['retention'] - $sum['penalty'];
		$balance = max( 0, $totalCents - $applied );
		$excess  = max( 0, $applied - $totalCents );

		if ( $refundOpen > 0 ) {
			$status = self::REFUND_PENDING;
		} elseif ( $sum['refund_done'] > 0 ) {
			$status = self::REFUND_RECORDED;
		} elseif ( $totalCents > 0 && $applied >= $totalCents ) {
			$status = self::PAID;
		} elseif ( $applied > 0 && $applied >= $depositCents ) {
			$status = self::DEPOSIT_COVERED;
		} elseif ( $applied > 0 ) {
			$status = self::DEPOSIT_PARTIAL;
		} elseif ( $proofPending ) {
			$status = self::IN_REVIEW;
		} elseif ( 0 === $totalCents ) {
			$status = self::PAID;
		} else {
			$status = self::NONE;
		}

		return array(
			'status'           => $status,
			'received_cents'   => $received,
			'net_cents'        => $net,
			'retained_cents'   => $sum['retention'],
			'penalty_cents'    => $sum['penalty'],
			'credit_cents'     => $sum['credit'],
			'refund_due_cents' => $refundOpen,
			'refunded_cents'   => $sum['refund_done'],
			'applied_cents'    => $applied,
			'balance_cents'    => $balance,
			'excess_cents'     => $excess,
			'deposit_covered'  => $applied >= $depositCents,
			'deposit_missing'  => max( 0, $depositCents - $applied ),
		);
	}
}
