<?php
namespace MOBookingStudio\Domain;

/**
 * Comparación de nombres sin distinguir mayúsculas, acentos ni espacios repetidos, con
 * tolerancia a faltas leves. Solo sirve de apoyo: un nombre parecido nunca basta para
 * identificar a una persona.
 */
final class Names {
	/** "  María  José Núñez" → "maria jose nunez". */
	public static function key( string $name ): string {
		$name = function_exists( 'remove_accents' ) ? remove_accents( $name ) : $name;
		$name = strtolower( $name );
		$name = (string) preg_replace( '/[^a-z0-9 ]+/', ' ', $name );
		return trim( (string) preg_replace( '/\s+/', ' ', $name ) );
	}

	/** Faltas toleradas según la longitud: 0 en palabras cortas, 1 desde 5 letras, 2 desde 8. */
	private static function tolerance( int $length ): int {
		return $length >= 8 ? 2 : ( $length >= 5 ? 1 : 0 );
	}

	/** Mismo nombre completo con faltas leves ("Angelica Falis" / "Angélica Faliz"). */
	public static function similar( string $a, string $b ): bool {
		$a = self::key( $a );
		$b = self::key( $b );
		if ( '' === $a || '' === $b ) {
			return false;
		}
		if ( $a === $b ) {
			return true;
		}
		$len = min( strlen( $a ), strlen( $b ) );
		return levenshtein( $a, $b ) <= self::tolerance( $len );
	}

	/**
	 * Primer nombre para saludar en los avisos: la primera palabra tal como se escribió
	 * ("María José González" → "María"). Si no hay nombre, cadena vacía.
	 */
	public static function first( string $full ): string {
		$parts = preg_split( '/\s+/u', trim( $full ) );
		return (string) ( $parts[0] ?? '' );
	}

	/**
	 * Al menos una palabra de 3 o más letras en común, admitiendo faltas leves en palabras
	 * largas ("Angelic" escrito como "Angelik", "Gonzalez" como "Gonzales").
	 */
	public static function shareWord( string $typed, string $stored ): bool {
		$words = static function ( string $s ): array {
			return array_values(
				array_filter(
					explode( ' ', self::key( $s ) ),
					static function ( $w ) {
						return strlen( $w ) >= 3 && ! ctype_digit( $w );
					}
				)
			);
		};
		foreach ( $words( $typed ) as $t ) {
			foreach ( $words( $stored ) as $s ) {
				if ( $t === $s || levenshtein( $t, $s ) <= self::tolerance( min( strlen( $t ), strlen( $s ) ) ) ) {
					return true;
				}
			}
		}
		return false;
	}
}
