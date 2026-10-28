<?php
namespace MOBookingStudio\Domain;

/**
 * Normalización de contactos.
 *
 * Correo: se recorta y se pasa a minúsculas.
 * Teléfono (formato E.164, solo dígitos con "+"):
 * - Si empieza con "+", se respeta el código de país escrito (8 a 15 dígitos).
 * - México (MX): 10 dígitos -> +52 y los 10 dígitos. "52" + 10 dígitos -> +52...
 *   El prefijo antiguo de celulares "521" + 10 dígitos se convierte a +52 + 10 dígitos.
 * - Estados Unidos (US): 10 dígitos -> +1...; "1" + 10 dígitos -> +1...
 * - Cualquier otro caso se considera dudoso: se devuelve null y se conserva el texto
 *   original sin modificarlo para revisión manual.
 */
final class Contact {
	public static function normalizeEmail( ?string $email ): ?string {
		$email = strtolower( trim( (string) $email ) );
		if ( '' === $email || ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			return null;
		}
		return $email;
	}

	public static function normalizePhone( ?string $phone, string $country = 'MX' ): ?string {
		$raw = trim( (string) $phone );
		if ( '' === $raw ) {
			return null;
		}
		$digits = preg_replace( '/\D+/', '', $raw );
		if ( 0 === strpos( $raw, '+' ) || 0 === strpos( $raw, '00' ) ) {
			if ( 0 === strpos( $raw, '00' ) ) {
				$digits = substr( $digits, 2 );
			}
			if ( 0 === strpos( $digits, '521' ) && 13 === strlen( $digits ) ) {
				$digits = '52' . substr( $digits, 3 );
			}
			return ( strlen( $digits ) >= 8 && strlen( $digits ) <= 15 ) ? '+' . $digits : null;
		}
		$country = strtoupper( $country );
		if ( 'MX' === $country ) {
			if ( 10 === strlen( $digits ) ) {
				return '+52' . $digits;
			}
			if ( 12 === strlen( $digits ) && 0 === strpos( $digits, '52' ) ) {
				return '+' . $digits;
			}
			if ( 13 === strlen( $digits ) && 0 === strpos( $digits, '521' ) ) {
				return '+52' . substr( $digits, 3 );
			}
			return null;
		}
		if ( 'US' === $country ) {
			if ( 10 === strlen( $digits ) ) {
				return '+1' . $digits;
			}
			if ( 11 === strlen( $digits ) && '1' === $digits[0] ) {
				return '+' . $digits;
			}
			return null;
		}
		return null;
	}

	/**
	 * Enlace de WhatsApp con mensaje listo. Se usa api.whatsapp.com/send y no wa.me: al
	 * pasar por wa.me en computadora los emojis llegaban como "�". Con $web abre WhatsApp
	 * Web, con la cuenta que esté abierta en el navegador (por ejemplo, WhatsApp Business).
	 */
	public static function whatsappLink( string $digits, string $text, bool $web = false ): string {
		return ( $web ? 'https://web.whatsapp.com/send' : 'https://api.whatsapp.com/send' ) . '?phone=' . rawurlencode( ltrim( $digits, '+' ) ) . '&text=' . rawurlencode( self::decodeEmoji( $text ) );
	}

	/**
	 * Emojis guardados como &#x1f384; (bases de datos sin utf8mb4) vuelven a ser emojis.
	 * Solo toca entidades numéricas; el resto del texto queda igual.
	 */
	public static function decodeEmoji( string $text ): string {
		return \MOBookingStudio\Infrastructure\OptionStore::decodeText( $text );
	}

	/** Número para enlaces de WhatsApp: solo dígitos. */
	public static function whatsappDigits( ?string $e164 ): ?string {
		if ( ! $e164 ) {
			return null;
		}
		return ltrim( $e164, '+' );
	}
}
