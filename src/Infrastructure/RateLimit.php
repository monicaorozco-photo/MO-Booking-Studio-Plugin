<?php
namespace MOBookingStudio\Infrastructure;

use MOBookingStudio\Domain\DomainException;

/**
 * Límite de solicitudes por IP (REMOTE_ADDR; no se confía en cabeceras reenviadas).
 * Evita enumeración y bloqueo abusivo de horarios.
 */
final class RateLimit {
	public static function hit( string $bucket, int $limit, int $windowSeconds ): void {
		if ( ! apply_filters( 'mo_booking_rate_limit_enabled', true, $bucket ) ) {
			return;
		}
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : 'cli'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$key = 'mo_rl_' . substr( hash( 'sha256', $bucket . '|' . $ip . '|' . wp_salt( 'nonce' ) ), 0, 32 );
		$now = time();
		$hit = get_transient( $key );
		if ( ! is_array( $hit ) || $hit['until'] <= $now ) {
			$hit = array( 'count' => 0, 'until' => $now + $windowSeconds );
		}
		++$hit['count'];
		set_transient( $key, $hit, max( 1, $hit['until'] - $now ) );
		if ( $hit['count'] > $limit ) {
			throw new DomainException( 'rate_limited', 'Hiciste muchas solicitudes en poco tiempo. Espera unos minutos e intenta de nuevo.', 429 );
		}
	}
}
