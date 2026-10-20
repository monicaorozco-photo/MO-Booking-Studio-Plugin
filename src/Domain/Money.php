<?php
namespace MOBookingStudio\Domain;

/**
 * Importes en centavos enteros. Redondeo documentado: los porcentajes se
 * redondean al centavo más cercano, con medios hacia arriba (half-up).
 */
final class Money {
	public static function percentOf( int $cents, float $percent ): int {
		if ( $percent < 0 ) {
			throw new DomainException( 'invalid_percent', 'El porcentaje no puede ser negativo.' );
		}
		return (int) floor( ( $cents * $percent / 100 ) + 0.5 );
	}

	/**
	 * Convierte texto de pesos ("1,600.50", "1600") a centavos. Devuelve null si no es válido.
	 */
	public static function parse( $value ): ?int {
		if ( is_int( $value ) ) {
			return $value * 100;
		}
		$value = str_replace( array( ',', '$', ' ', 'MXN' ), '', (string) $value );
		if ( '' === $value || ! preg_match( '/^-?\d+(\.\d{1,2})?$/', $value ) ) {
			return null;
		}
		$negative = '-' === $value[0];
		$value    = ltrim( $value, '-' );
		$parts    = explode( '.', $value );
		$cents    = (int) $parts[0] * 100 + ( isset( $parts[1] ) ? (int) str_pad( $parts[1], 2, '0' ) : 0 );
		return $negative ? -$cents : $cents;
	}

	public static function format( int $cents, string $currency = 'MXN' ): string {
		$sign = $cents < 0 ? '-' : '';
		$abs  = abs( $cents );
		$text = number_format( intdiv( $abs, 100 ), 0, '.', ',' );
		$frac = $abs % 100;
		if ( $frac ) {
			$text .= '.' . str_pad( (string) $frac, 2, '0', STR_PAD_LEFT );
		}
		return $sign . '$' . $text . ( '' !== $currency ? ' ' . $currency : '' );
	}

	/** Formato para campos de formulario: 1600 o 1600.50 */
	public static function toInput( int $cents ): string {
		$text = (string) intdiv( $cents, 100 );
		if ( $cents % 100 ) {
			$text .= '.' . str_pad( (string) abs( $cents % 100 ), 2, '0', STR_PAD_LEFT );
		}
		return $text;
	}
}
