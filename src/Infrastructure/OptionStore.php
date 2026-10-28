<?php
namespace MOBookingStudio\Infrastructure;

use MOBookingStudio\Domain\DomainException;

/**
 * Guardado de opciones con textos (avisos, ajustes) a prueba de emojis.
 *
 * Muchos hostings tienen la tabla de opciones en utf8 (sin "mb4"): si un texto lleva un
 * emoji, WordPress rechaza el guardado completo sin avisar y vuelven los textos de antes.
 * Aquí los emojis se guardan como &#x1f384; (lo acepta cualquier base de datos), se
 * recuperan al leer y, si aun así no se guarda, se avisa con un error en lugar de fingir
 * que se guardó.
 */
final class OptionStore {
	public static function load( string $option, $default = array() ) {
		return self::decode( get_option( $option, $default ) );
	}

	public static function save( string $option, $value ): void {
		$encoded = self::encode( $value );
		update_option( $option, $encoded, false );
		if ( get_option( $option ) !== $encoded ) {
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( $option, 'options' );
			if ( get_option( $option ) !== $encoded ) {
				throw new DomainException( 'option_not_saved', 'No se pudieron guardar los cambios en la base de datos. Intenta de nuevo; si sigue pasando, avísanos.', 500 );
			}
		}
	}

	/** Convierte emojis en entidades numéricas (recorre arreglos). */
	public static function encode( $value ) {
		if ( is_array( $value ) ) {
			return array_map( array( self::class, 'encode' ), $value );
		}
		return is_string( $value ) ? wp_encode_emoji( $value ) : $value;
	}

	/** Recupera los emojis guardados como entidades (recorre arreglos). */
	public static function decode( $value ) {
		if ( is_array( $value ) ) {
			return array_map( array( self::class, 'decode' ), $value );
		}
		return is_string( $value ) ? self::decodeText( $value ) : $value;
	}

	/**
	 * Solo entidades numéricas de caracteres no ASCII (emojis y símbolos): nunca toca
	 * &#60; ni otras que puedan formar HTML.
	 */
	public static function decodeText( string $text ): string {
		if ( false === strpos( $text, '&#' ) ) {
			return $text;
		}
		return (string) preg_replace_callback(
			'/&#(x[0-9a-f]+|[0-9]+);/i',
			static function ( $m ) {
				$code = 'x' === strtolower( $m[1][0] ) ? hexdec( substr( $m[1], 1 ) ) : (int) $m[1];
				return $code > 0x7F && $code <= 0x10FFFF ? html_entity_decode( '&#' . $code . ';', ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : $m[0];
			},
			$text
		);
	}
}
