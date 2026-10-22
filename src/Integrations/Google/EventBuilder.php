<?php
namespace MOBookingStudio\Integrations\Google;

use MOBookingStudio\Application\Bookings;
use MOBookingStudio\Application\Catalog;
use MOBookingStudio\Application\Services;
use MOBookingStudio\Domain\BookingStatus;
use MOBookingStudio\Domain\Ledger;
use MOBookingStudio\Domain\Money;
use MOBookingStudio\Infrastructure\Settings;

/**
 * Contenido de los dos eventos vinculados (sección "Dos eventos vinculados").
 *
 * Evento interno: ocupado, privado, sin invitados; datos administrativos mínimos y
 * enlace al panel (que exige iniciar sesión).
 * Evento del cliente: transparente, con la persona invitada; solo fecha, hora, ubicación
 * e indicaciones. Nunca teléfono, montos, pagos, notas, enlaces del panel ni tokens.
 */
final class EventBuilder {
	public const SITE_KEY_OPTION = 'mo_booking_site_key';
	private const BASE32HEX      = '0123456789abcdefghijklmnopqrstuv';

	/** Marca de propiedad del plugin en este sitio (no es un secreto). */
	public static function siteKey(): string {
		$key = (string) get_option( self::SITE_KEY_OPTION, '' );
		if ( '' === $key ) {
			$key = bin2hex( random_bytes( 6 ) );
			add_option( self::SITE_KEY_OPTION, $key, '', false );
		}
		return $key;
	}

	/**
	 * Identificador determinista (CAL-06): el mismo para la misma reserva y tipo,
	 * así un reintento tras una falla parcial no crea un segundo evento.
	 * Google acepta letras a-v y dígitos.
	 */
	public static function eventId( int $bookingId, string $kind ): string {
		$bin = hash( 'sha256', self::siteKey() . '|' . $bookingId . '|' . $kind, true );
		$out = '';
		$bits = '';
		foreach ( str_split( $bin ) as $byte ) {
			$bits .= str_pad( decbin( ord( $byte ) ), 8, '0', STR_PAD_LEFT );
		}
		foreach ( str_split( substr( $bits, 0, 130 ), 5 ) as $chunk ) {
			$out .= self::BASE32HEX[ bindec( $chunk ) ];
		}
		return 'mob' . $out;
	}

	public static function ownerProps( int $bookingId, string $kind ): array {
		return array(
			'private' => array(
				'mo_owner'   => self::siteKey(),
				'mo_booking' => (string) $bookingId,
				'mo_kind'    => $kind,
			),
		);
	}

	public static function times( array $booking ): array {
		$zone = Settings::timezone();
		$tz   = Settings::timezoneInfo()['requested'];
		return array(
			'start' => array(
				'dateTime' => $booking['start']->setTimezone( $zone )->format( 'Y-m-d\TH:i:sP' ),
				'timeZone' => $tz,
			),
			'end'   => array(
				'dateTime' => $booking['end']->setTimezone( $zone )->format( 'Y-m-d\TH:i:sP' ),
				'timeZone' => $tz,
			),
		);
	}

	/** Etiqueta del estado en el evento interno (9.4). */
	private static function internalState( array $booking ): string {
		switch ( $booking['status'] ) {
			case BookingStatus::CONFIRMED:
				return 'MO CONFIRMADA';
			case BookingStatus::PAYMENT_REVIEW:
			case BookingStatus::UNDER_REVIEW:
				return 'MO EN REVISIÓN';
			default:
				return 'MO PENDIENTE';
		}
	}

	/**
	 * 9.4: evento administrativo, nunca compartido ni con invitados. Incluye lo necesario
	 * para atender la sesión: contacto, asistentes, extras, pagos, información importante
	 * y el enlace al panel (que exige iniciar sesión).
	 */
	public static function internal( array $booking ): array {
		$service = Services::find( $booking['service_id'] );
		$summary = Bookings::summary( $booking );
		$lines   = array(
			'Reserva ' . $booking['public_ref'] . ' · ' . BookingStatus::label( $booking['status'] ),
			'Servicio: ' . ( $service ? $service['title'] : (string) ( $booking['quote']['service_title'] ?? '' ) ),
			'Nombre: ' . (string) ( $booking['contact']['name'] ?? '' ),
			'Teléfono: ' . (string) ( $booking['contact']['phone'] ?? '' ),
			'Correo: ' . (string) ( $booking['contact']['email'] ?? '' ),
		);
		if ( null !== $booking['children'] || null !== $booking['adults'] ) {
			$lines[] = sprintf( 'Asistentes: %d (niños %d, adultos %d)', (int) $booking['participants'], (int) $booking['children'], (int) $booking['adults'] );
		} else {
			$lines[] = 'Personas: ' . (int) $booking['participants'];
		}
		$lines[] = 'Mascotas: ' . (int) $booking['pets'];
		$extras  = array();
		foreach ( (array) ( $booking['quote']['lines'] ?? array() ) as $l ) {
			if ( 'extra' === ( $l['kind'] ?? '' ) ) {
				$extras[] = (string) $l['label'] . ( (int) $l['quantity'] > 1 ? ' ×' . (int) $l['quantity'] : '' );
			}
		}
		$lines[] = 'Extras: ' . ( $extras ? implode( ', ', $extras ) : 'ninguno' );
		$lines[] = sprintf( 'Pago: %s · total %s · pagado %s · saldo %s', Ledger::LABELS[ $summary['status'] ] ?? '', Money::format( (int) $booking['total_cents'] ), Money::format( (int) $summary['received_cents'] ), Money::format( (int) $summary['balance_cents'] ) );
		if ( BookingStatus::CONFIRMED !== $booking['status'] && $booking['expires'] ) {
			$lines[] = 'Vence: ' . Catalog::dateTimeText( $booking['expires'] );
		}
		if ( '' !== (string) $booking['important_info'] ) {
			$lines[] = '';
			$lines[] = 'Información importante: ' . $booking['important_info'];
		}
		$lines[] = '';
		$lines[] = 'Panel (requiere iniciar sesión): ' . admin_url( 'admin.php?page=mo-booking-bookings&booking=' . (int) $booking['id'] );
		return array_merge(
			self::times( $booking ),
			array(
				'summary'            => ( $booking['is_test'] ? 'PRUEBA · ' : '' ) . self::internalState( $booking ) . ' · ' . $booking['public_ref'],
				'description'        => implode( "\n", $lines ),
				'visibility'         => 'private',
				'transparency'       => 'opaque',
				'attendees'          => array(),
				'guestsCanSeeOtherGuests' => false,
				'extendedProperties' => self::ownerProps( (int) $booking['id'], 'internal' ),
			)
		);
	}

	/**
	 * 9.5: evento limpio para la persona invitada. Solo: nombre público del estudio,
	 * servicio, fecha, hora, duración, ubicación, indicaciones públicas y contacto público.
	 * Nunca teléfono, pagos, comprobantes, notas, información privada, panel ni tokens.
	 */
	public static function client( array $booking ): array {
		$service = Services::find( $booking['service_id'] );
		$brand   = ( $service && $service['brand'] ) ? $service['brand'] : (string) Settings::get( 'business_name' );
		$title   = $service ? $service['title'] : (string) ( $booking['quote']['service_title'] ?? '' );
		$minutes = (int) round( ( $booking['end']->getTimestamp() - $booking['start']->getTimestamp() ) / 60 );
		$parts   = array( 'Servicio: ' . $title, 'Duración: ' . $minutes . ' minutos' );
		if ( $service && $service['instructions'] ) {
			$parts[] = $service['instructions'];
		}
		$contact = trim( (string) Settings::get( 'public_contact' ) );
		if ( '' !== $contact ) {
			$parts[] = 'Contacto: ' . $contact;
		}
		$email = (string) $booking['contact']['email'];
		$event = array_merge(
			self::times( $booking ),
			array(
				'summary'                 => 'Sesión con ' . $brand,
				'description'             => implode( "\n\n", $parts ),
				'transparency'            => 'transparent',
				'attendees'               => self::canInvite( $email ) ? array( array( 'email' => $email ) ) : array(),
				'guestsCanInviteOthers'   => false,
				'guestsCanSeeOtherGuests' => false,
				'guestsCanModify'         => false,
				'extendedProperties'      => self::ownerProps( (int) $booking['id'], 'client' ),
			)
		);
		if ( $service && $service['location'] ) {
			$event['location'] = $service['location'];
		}
		return $event;
	}

	/**
	 * Los dominios reservados para ejemplos (como los de los datos de prueba) no reciben
	 * correo: el evento se crea, pero sin invitar a nadie.
	 */
	public static function canInvite( string $email ): bool {
		$domain = strtolower( (string) substr( strrchr( $email, '@' ), 1 ) );
		if ( '' === $domain || in_array( $domain, array( 'example.com', 'example.net', 'example.org' ), true ) ) {
			return false;
		}
		return ! preg_match( '/\.(test|example|invalid|localhost)$/', $domain );
	}

	public static function fingerprint( array $event ): string {
		return sha1( (string) wp_json_encode( $event ) );
	}
}
