<?php
namespace MOBookingStudio\Application;

use MOBookingStudio\Domain\BookingStatus;
use MOBookingStudio\Domain\Contact;
use MOBookingStudio\Domain\Ledger;
use MOBookingStudio\Domain\Money;
use MOBookingStudio\Infrastructure\Audit;
use MOBookingStudio\Infrastructure\PrivateStorage;
use MOBookingStudio\Infrastructure\Settings;
use MOBookingStudio\Infrastructure\Tokens;
use MOBookingStudio\Plugin;

/**
 * Vista privada de una reserva para su cliente. Nunca incluye notas internas,
 * datos de otras reservas ni información financiera del negocio.
 */
final class Portal {
	private const STATUS_HELP = array(
		BookingStatus::PENDING_PAYMENT      => 'Tu horario está apartado. Transfiere el anticipo y sube tu comprobante antes de la hora límite.',
		BookingStatus::PAYMENT_REVIEW       => 'Recibimos tu comprobante. Tu horario sigue apartado mientras verificamos tu depósito.',
		BookingStatus::UNDER_REVIEW         => 'Estamos revisando tu comprobante. Tu horario sigue apartado.',
		BookingStatus::CORRECTION_REQUESTED => 'Necesitamos que revises tu pago. Lee el motivo y envía lo que falta antes del plazo.',
		BookingStatus::PENDING_APPROVAL     => 'Tu pago está verificado. Estamos por confirmar tu cita.',
		BookingStatus::CONFIRMED            => 'Tu cita está confirmada. ¡Te esperamos!',
		BookingStatus::COMPLETED            => 'Tu sesión se realizó. ¡Gracias!',
		BookingStatus::EXPIRED              => 'Pasaste el tiempo límite para enviar tu anticipo y el horario se liberó. Realiza de nuevo tu reserva.',
		BookingStatus::REJECTED             => 'No pudimos aprobar esta solicitud.',
		BookingStatus::CANCELLED            => 'Esta cita fue cancelada.',
		BookingStatus::NO_SHOW              => 'Registramos que no fue posible realizar la sesión.',
	);

	private const PROOF_STATUS = array(
		'pending'    => 'En revisión',
		'approved'   => 'Aprobado',
		'rejected'   => 'No válido',
		'correction' => 'Requiere corrección',
		'replaced'   => 'Sustituido por una versión nueva',
	);

	public static function view( array $booking ): array {
		$service = Services::find( $booking['service_id'] );
		$summary = Bookings::summary( $booking );
		$local   = $booking['start']->setTimezone( Settings::timezone() );
		$status  = $booking['status'];
		$paying  = in_array( $status, BookingStatus::active(), true );
		$window  = Proofs::uploadWindow( $booking, Plugin::clock()->now() );

		$correction = null;
		if ( BookingStatus::CORRECTION_REQUESTED === $status ) {
			// Solo el motivo elegido de la lista; nunca notas internas.
			$reason = '';
			foreach ( array_reverse( Bookings::proofs( $booking['id'] ) ) as $p ) {
				if ( 'correction' === $p['status'] ) {
					$reason = (string) $p['client_reason'];
					break;
				}
			}
			$until      = $booking['correction_until'] ? new \DateTimeImmutable( $booking['correction_until'], new \DateTimeZone( 'UTC' ) ) : null;
			$correction = array(
				'reason'    => $reason,
				'until'     => $until ? $until->format( 'c' ) : null,
				'until_txt' => $until ? Catalog::dateTimeText( $until ) : null,
			);
		}

		$proofs = array();
		foreach ( Bookings::proofs( $booking['id'] ) as $p ) {
			$proofs[] = array(
				'date'   => Catalog::dateTimeText( new \DateTimeImmutable( $p['created_at'], new \DateTimeZone( 'UTC' ) ) ),
				'status' => self::PROOF_STATUS[ $p['status'] ] ?? $p['status'],
				'note'   => in_array( $p['status'], array( 'correction', 'rejected' ), true ) ? $p['client_reason'] : '',
			);
		}

		$policies = array();
		foreach ( (array) $booking['policy'] as $action => $p ) {
			if ( $p ) {
				$policies[] = array(
					'title'   => \MOBookingStudio\Domain\Policy::ACTION_LABELS[ $action ] ?? $action,
					'summary' => $p['summary'],
				);
			}
		}

		$canUpload = PrivateStorage::uploadsEnabled() && $window['open'];
		$waNumber  = Contact::normalizePhone( (string) Settings::get( 'whatsapp_number' ), 'MX' );

		return array(
			'reference'       => $booking['public_ref'],
			'status'          => $status,
			'status_label'    => BookingStatus::label( $status ),
			'status_help'     => self::STATUS_HELP[ $status ] ?? '',
			'service'         => $service ? $service['title'] : (string) ( $booking['quote']['service_title'] ?? '' ),
			'brand'           => ( $service && $service['brand'] ) ? $service['brand'] : (string) Settings::get( 'business_name' ),
			'name'            => $booking['contact']['name'] ?? '',
			'date_txt'        => Catalog::dateText( $local ),
			'time_txt'        => $local->format( 'H:i' ),
			'timezone'        => $booking['timezone'],
			'start'           => $booking['start']->format( 'c' ),
			'location'        => $service ? $service['location'] : '',
			'instructions'    => $service ? $service['instructions'] : '',
			'delivery_text'   => $service ? $service['delivery_text'] : '',
			'notices'         => $service ? Services::noticesFor( $service, $local->format( 'Y-m-d' ) ) : array(),
			'participants'    => $booking['participants'],
			'pets'            => $booking['pets'],
			'frequent_review' => (bool) $booking['frequent_claim'] && 'frequent' !== $booking['client_category'],
			'lines'           => array_map(
				static function ( $l ) {
					return array(
						'label'      => $l['label'],
						'amount_txt' => Money::format( (int) $l['amount'] ),
					);
				},
				(array) ( $booking['quote']['lines'] ?? array() )
			),
			'total_txt'       => Money::format( $booking['total_cents'] ),
			'deposit_txt'     => Money::format( $booking['deposit_cents'] ),
			'paid_txt'        => Money::format( max( 0, $summary['applied_cents'] ) ),
			'balance_txt'     => Money::format( $summary['balance_cents'] ),
			'deposit_missing_txt' => $summary['deposit_missing'] > 0 ? Money::format( $summary['deposit_missing'] ) : null,
			'refund_due_txt'  => $summary['refund_due_cents'] > 0 ? Money::format( $summary['refund_due_cents'] ) : null,
			'credit_txt'      => $summary['credit_cents'] > 0 ? Money::format( $summary['credit_cents'] ) : null,
			'financial_label' => Ledger::LABELS[ $summary['status'] ] ?? '',
			'expires_at'      => $window['open'] && $window['until'] && BookingStatus::PENDING_PAYMENT === $status ? $window['until']->format( 'c' ) : null,
			'expires_txt'     => $window['open'] && $window['until'] && BookingStatus::PENDING_PAYMENT === $status ? Catalog::dateTimeText( $window['until'] ) : null,
			'correction'      => $correction,
			'bank'            => $paying && $summary['balance_cents'] > 0 ? (string) Settings::get( 'bank_instructions' ) : '',
			'payment_ref'     => $paying ? (string) Settings::get( 'payment_reference' ) : '',
			'proofs'          => $proofs,
			'can_upload'      => $canUpload,
			'uploads_note'    => ! PrivateStorage::uploadsEnabled() ? 'Por ahora no podemos recibir archivos aquí. Envíanos tu comprobante por WhatsApp o correo con tu número de reserva.' : ( 'late' === $window['reason'] ? $window['message'] : '' ),
			'max_mb'          => (float) Settings::get( 'proof_max_mb' ),
			'policies'        => $policies,
			'whatsapp_url'    => $waNumber ? 'https://wa.me/' . ltrim( $waNumber, '+' ) . '?text=' . rawurlencode( 'Hola, tengo una pregunta sobre mi reserva ' . $booking['public_ref'] ) : null,
			// 8.1: texto editable junto a la carga del comprobante.
			'proof_text'      => str_replace( '{whatsapp}', (string) Settings::get( 'whatsapp_number' ), (string) Settings::get( 'portal_proof_text' ) ),
			// 16.1: durante la preventa, cambios y cancelaciones se piden por WhatsApp.
			'change_url'      => $waNumber && BookingStatus::isOpen( $status ) ? 'https://wa.me/' . ltrim( $waNumber, '+' ) . '?text=' . rawurlencode( 'Hola, quiero hacer un cambio o cancelar mi reserva ' . $booking['public_ref'] ) : null,
			'children'        => $booking['children'],
			'adults'          => $booking['adults'],
		);
	}

	public static function cookie(): ?string {
		$value = $_COOKIE[ Tokens::COOKIE ] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return is_string( $value ) ? $value : null;
	}

	public static function setCookie( array $session ): void {
		$_COOKIE[ Tokens::COOKIE ] = $session['session'];
		if ( headers_sent() ) {
			return;
		}
		setcookie(
			Tokens::COOKIE,
			$session['session'],
			array(
				'expires'  => $session['expires']->getTimestamp(),
				'path'     => COOKIEPATH ?: '/',
				'domain'   => COOKIE_DOMAIN ?: '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Strict',
			)
		);
	}

	public static function clearCookie(): void {
		unset( $_COOKIE[ Tokens::COOKIE ] );
		if ( ! headers_sent() ) {
			setcookie( Tokens::COOKIE, '', array( 'expires' => time() - 3600, 'path' => COOKIEPATH ?: '/', 'domain' => COOKIE_DOMAIN ?: '', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Strict' ) );
		}
	}
}
