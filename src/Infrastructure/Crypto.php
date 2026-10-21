<?php
namespace MOBookingStudio\Infrastructure;

use MOBookingStudio\Domain\DomainException;

/**
 * Cifrado de secretos recuperables (tokens de Google). La clave vive en wp-config.php
 * (constante MO_BOOKING_SECRET) y nunca en la base de datos (SEC-05, CAL-04).
 * Usa libsodium (incluido en PHP 7.2+).
 */
final class Crypto {
	private const PREFIX = 'v1:';

	public static function available(): bool {
		return function_exists( 'sodium_crypto_secretbox' ) && defined( 'MO_BOOKING_SECRET' ) && strlen( (string) MO_BOOKING_SECRET ) >= 32;
	}

	public static function encrypt( string $plain ): string {
		$key   = self::key();
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return self::PREFIX . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, $key ) );
	}

	public static function decrypt( string $stored ): string {
		if ( 0 !== strpos( $stored, self::PREFIX ) ) {
			throw new DomainException( 'crypto', 'Dato cifrado no válido.', 500 );
		}
		$raw   = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true );
		$nonce = substr( (string) $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$box   = substr( (string) $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain = false === $raw ? false : sodium_crypto_secretbox_open( $box, $nonce, self::key() );
		if ( false === $plain ) {
			// La clave cambió o el dato se alteró: hay que volver a conectar.
			throw new DomainException( 'crypto', 'No se pudo descifrar la conexión guardada. Vuelve a conectar Google.', 500 );
		}
		return $plain;
	}

	private static function key(): string {
		if ( ! self::available() ) {
			throw new DomainException( 'crypto_unavailable', 'Falta la constante MO_BOOKING_SECRET en wp-config.php (al menos 32 caracteres).', 500 );
		}
		return sodium_crypto_generichash( (string) MO_BOOKING_SECRET, 'mo-booking-studio', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}
}
