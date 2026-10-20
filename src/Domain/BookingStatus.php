<?php
namespace MOBookingStudio\Domain;

/**
 * Estados de reserva y transiciones válidas. La situación financiera se deriva
 * aparte, de los movimientos aprobados (ver Ledger).
 */
final class BookingStatus {
	public const PENDING_PAYMENT      = 'pending_payment';
	public const PAYMENT_REVIEW       = 'payment_review';
	public const CORRECTION_REQUESTED = 'correction_requested';
	public const PENDING_APPROVAL     = 'pending_approval';
	public const CONFIRMED            = 'confirmed';
	public const COMPLETED            = 'completed';
	public const EXPIRED              = 'expired';
	public const REJECTED             = 'rejected';
	public const CANCELLED            = 'cancelled';
	public const NO_SHOW              = 'no_show';

	public const LABELS = array(
		self::PENDING_PAYMENT      => 'Pendiente de pago',
		self::PAYMENT_REVIEW       => 'Pago en revisión',
		self::CORRECTION_REQUESTED => 'Corrección solicitada',
		self::PENDING_APPROVAL     => 'Pendiente de aprobación',
		self::CONFIRMED            => 'Confirmada',
		self::COMPLETED            => 'Completada',
		self::EXPIRED              => 'Vencida',
		self::REJECTED             => 'Rechazada',
		self::CANCELLED            => 'Cancelada',
		self::NO_SHOW              => 'Inasistencia',
	);

	private const TRANSITIONS = array(
		self::PENDING_PAYMENT      => array( self::PAYMENT_REVIEW, self::PENDING_APPROVAL, self::CONFIRMED, self::EXPIRED, self::REJECTED, self::CANCELLED ),
		self::PAYMENT_REVIEW       => array( self::CORRECTION_REQUESTED, self::PENDING_PAYMENT, self::PENDING_APPROVAL, self::CONFIRMED, self::REJECTED, self::CANCELLED ),
		self::CORRECTION_REQUESTED => array( self::PAYMENT_REVIEW, self::PENDING_PAYMENT, self::PENDING_APPROVAL, self::CONFIRMED, self::EXPIRED, self::REJECTED, self::CANCELLED ),
		self::PENDING_APPROVAL     => array( self::CONFIRMED, self::REJECTED, self::CANCELLED, self::PAYMENT_REVIEW, self::PENDING_PAYMENT ),
		self::CONFIRMED            => array( self::COMPLETED, self::NO_SHOW, self::CANCELLED, self::PAYMENT_REVIEW, self::PENDING_PAYMENT ),
		self::COMPLETED            => array( self::CONFIRMED ),
		self::NO_SHOW              => array( self::CONFIRMED ),
		self::EXPIRED              => array(),
		self::REJECTED             => array(),
		self::CANCELLED            => array(),
	);

	/** Estados cuya ocupación bloquea el calendario. */
	private const OCCUPYING = array(
		self::PENDING_PAYMENT,
		self::PAYMENT_REVIEW,
		self::CORRECTION_REQUESTED,
		self::PENDING_APPROVAL,
		self::CONFIRMED,
		self::COMPLETED,
		self::NO_SHOW,
	);

	public static function canTransition( string $from, string $to ): bool {
		return in_array( $to, self::TRANSITIONS[ $from ] ?? array(), true );
	}

	public static function assertTransition( string $from, string $to ): void {
		if ( $from === $to ) {
			throw new DomainException( 'repeated_transition', sprintf( 'La reserva ya está en estado "%s".', self::label( $to ) ), 409 );
		}
		if ( ! self::canTransition( $from, $to ) ) {
			throw new DomainException( 'invalid_transition', sprintf( 'No se puede pasar de "%s" a "%s".', self::label( $from ), self::label( $to ) ), 409 );
		}
	}

	public static function occupies( string $status ): bool {
		return in_array( $status, self::OCCUPYING, true );
	}

	public static function isOpen( string $status ): bool {
		return in_array( $status, array( self::PENDING_PAYMENT, self::PAYMENT_REVIEW, self::CORRECTION_REQUESTED, self::PENDING_APPROVAL, self::CONFIRMED ), true );
	}

	public static function label( string $status ): string {
		return self::LABELS[ $status ] ?? $status;
	}
}
