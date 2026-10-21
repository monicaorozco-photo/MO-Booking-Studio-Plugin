<?php
namespace MOBookingStudio\Integrations\Google;

use MOBookingStudio\Application\Bookings;
use MOBookingStudio\Application\Catalog;
use MOBookingStudio\Application\Services;
use MOBookingStudio\Domain\BookingStatus;
use MOBookingStudio\Domain\Ledger;
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

	public static function internal( array $booking ): array {
		$service   = Services::find( $booking['service_id'] );
		$confirmed = BookingStatus::CONFIRMED === $booking['status'];
		$summary   = Bookings::summary( $booking );
		$lines     = array(
			'Reserva ' . $booking['public_ref'] . ' · ' . BookingStatus::label( $booking['status'] ),
			'Servicio: ' . ( $service ? $service['title'] : (string) ( $booking['quote']['service_title'] ?? '' ) ),
			'Nombre: ' . (string) ( $booking['contact']['name'] ?? '' ),
			'Teléfono: ' . (string) ( $booking['contact']['phone'] ?? '' ),
			'Personas: ' . (int) $booking['participants'] . ( $booking['pets'] ? ' · Mascotas: ' . (int) $booking['pets'] : '' ),
			'Pago: ' . ( Ledger::LABELS[ $summary['status'] ] ?? '' ),
		);
		if ( ! $confirmed && $booking['expires'] ) {
			$lines[] = 'Vence: ' . Catalog::dateTimeText( $booking['expires'] );
		}
		$lines[] = '';
		$lines[] = 'Panel (requiere iniciar sesión): ' . admin_url( 'admin.php?page=mo-booking-bookings&booking=' . (int) $booking['id'] );
		return array_merge(
			self::times( $booking ),
			array(
				'summary'            => ( $booking['is_test'] ? 'PRUEBA · ' : '' ) . ( $confirmed ? 'MO CONFIRMADA' : 'MO PENDIENTE' ) . ' · ' . $booking['public_ref'],
				'description'        => implode( "\n", $lines ),
				'visibility'         => 'private',
				'transparency'       => 'opaque',
				'attendees'          => array(),
				'guestsCanSeeOtherGuests' => false,
				'extendedProperties' => self::ownerProps( (int) $booking['id'], 'internal' ),
			)
		);
	}

	public static function client( array $booking ): array {
		$service = Services::find( $booking['service_id'] );
		$brand   = ( $service && $service['brand'] ) ? $service['brand'] : (string) Settings::get( 'business_name' );
		$parts   = array();
		if ( $service && $service['instructions'] ) {
			$parts[] = $service['instructions'];
		}
		if ( $service && $service['delivery_text'] ) {
			$parts[] = $service['delivery_text'];
		}
		$parts[] = 'Referencia: ' . $booking['public_ref'];
		$email   = (string) $booking['contact']['email'];
		$event   = array_merge(
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
