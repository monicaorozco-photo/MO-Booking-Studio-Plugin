<?php
namespace MOBookingStudio\Infrastructure;

/**
 * Registro mínimo de errores. Nunca registrar tokens, claves, comprobantes ni datos
 * bancarios: solo referencias de reserva y mensajes técnicos.
 */
final class Logger {
	public static function error( string $message, array $context = array() ): void {
		$safe = array();
		foreach ( $context as $key => $value ) {
			if ( preg_match( '/token|secret|password|clabe|pin|key/i', (string) $key ) ) {
				continue;
			}
			$safe[ $key ] = is_scalar( $value ) ? (string) $value : wp_json_encode( $value );
		}
		error_log( '[MO Booking Studio] ' . $message . ( $safe ? ' ' . wp_json_encode( $safe ) : '' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
