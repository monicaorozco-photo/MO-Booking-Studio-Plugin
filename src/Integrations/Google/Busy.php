<?php
namespace MOBookingStudio\Integrations\Google;

use MOBookingStudio\Domain\DomainException;
use MOBookingStudio\Domain\Interval;
use MOBookingStudio\Infrastructure\Settings;

/**
 * Ocupación de los calendarios de Google seleccionados.
 *
 * - Calendarios de lectura: consulta FreeBusy (sin ver detalles de los eventos).
 * - Calendario destino: se listan sus eventos y se ignoran los que creó el plugin, para
 *   que una reserva no se bloquee a sí misma (FreeBusy no permite excluir eventos).
 * - Vista del calendario: caché de pocos minutos. Cotizar y reservar exigen una consulta
 *   fresca; si Google no responde, no se promete el horario (DEC-06, SYNC-07).
 */
final class Busy {
	public const CACHE_SECONDS = 180;

	/**
	 * @return array{blocks:array<array{start:\DateTimeImmutable,end:\DateTimeImmutable,calendar:string}>,stale:bool}
	 */
	public static function between( \DateTimeImmutable $from, \DateTimeImmutable $to, bool $fresh ): array {
		// Nunca conectada: solo la disponibilidad interna (DEC-08).
		if ( ! Connection::isConfigured() ) {
			return array( 'blocks' => array(), 'stale' => false );
		}
		$d      = Connection::data();
		$utc    = new \DateTimeZone( 'UTC' );
		$from   = $from->setTimezone( $utc );
		$to     = $to->setTimezone( $utc );
		$key    = self::cacheKey( $from, $to );
		if ( ! $fresh ) {
			$cached = get_transient( $key );
			if ( is_array( $cached ) ) {
				return array( 'blocks' => self::revive( $cached ), 'stale' => false );
			}
		}
		try {
			if ( ! Connection::isActive() ) {
				// Conectada antes pero revocada: no se puede verificar Google.
				throw new GoogleException( 'auth', 401, 'Google no está conectado.' );
			}
			$blocks = self::fetch( $from, $to, $d );
		} catch ( GoogleException $e ) {
			$stale = $fresh ? false : get_transient( $key . '_s' );
			if ( is_array( $stale ) ) {
				return array( 'blocks' => self::revive( $stale ), 'stale' => true );
			}
			throw new DomainException( 'calendar_unavailable', 'No pudimos confirmar la disponibilidad en este momento. Intenta de nuevo en unos minutos.', 503 );
		}
		$plain = array_map(
			static function ( $b ) {
				return array( $b['start']->format( 'c' ), $b['end']->format( 'c' ), $b['calendar'] );
			},
			$blocks
		);
		set_transient( $key, $plain, self::CACHE_SECONDS );
		set_transient( $key . '_s', $plain, DAY_IN_SECONDS );
		return array( 'blocks' => $blocks, 'stale' => false );
	}

	public static function cacheKey( \DateTimeImmutable $from, \DateTimeImmutable $to ): string {
		$d   = Connection::data();
		$utc = new \DateTimeZone( 'UTC' );
		return 'mo_gbusy_' . md5( $from->setTimezone( $utc )->format( 'c' ) . '|' . $to->setTimezone( $utc )->format( 'c' ) . '|' . implode( ',', $d['read_calendars'] ) . '|' . $d['destination'] );
	}

	/** @return Interval[] */
	public static function intervals( \DateTimeImmutable $from, \DateTimeImmutable $to, bool $fresh ): array {
		$out = array();
		foreach ( self::between( $from, $to, $fresh )['blocks'] as $b ) {
			if ( $b['end'] > $b['start'] ) {
				$out[] = new Interval( $b['start'], $b['end'] );
			}
		}
		return $out;
	}

	private static function fetch( \DateTimeImmutable $from, \DateTimeImmutable $to, array $d ): array {
		$api    = new Api();
		$min    = $from->format( 'Y-m-d\TH:i:s\Z' );
		$max    = $to->format( 'Y-m-d\TH:i:s\Z' );
		$read   = array_values( array_diff( (array) $d['read_calendars'], array( $d['destination'] ) ) );
		$blocks = array();
		foreach ( $api->freeBusy( $min, $max, $read ) as $calendar => $busy ) {
			foreach ( $busy as $b ) {
				$blocks[] = array(
					'start'    => new \DateTimeImmutable( $b['start'] ),
					'end'      => new \DateTimeImmutable( $b['end'] ),
					'calendar' => $calendar,
				);
			}
		}
		if ( '' !== $d['destination'] ) {
			$owner = EventBuilder::siteKey();
			$zone  = Settings::timezone();
			foreach ( $api->listEvents( $d['destination'], $min, $max ) as $e ) {
				if ( ( $e['extendedProperties']['private']['mo_owner'] ?? '' ) === $owner ) {
					continue; // Eventos del propio plugin.
				}
				if ( 'transparent' === ( $e['transparency'] ?? 'opaque' ) || 'cancelled' === ( $e['status'] ?? '' ) ) {
					continue;
				}
				if ( isset( $e['start']['dateTime'] ) ) {
					$s = new \DateTimeImmutable( $e['start']['dateTime'] );
					$f = new \DateTimeImmutable( $e['end']['dateTime'] );
				} elseif ( isset( $e['start']['date'] ) ) {
					// Evento de día completo: ocupa el día entero en la zona del negocio.
					$s = new \DateTimeImmutable( $e['start']['date'] . ' 00:00:00', $zone );
					$f = new \DateTimeImmutable( $e['end']['date'] . ' 00:00:00', $zone );
				} else {
					continue;
				}
				$blocks[] = array(
					'start'    => $s,
					'end'      => $f,
					'calendar' => $d['destination'],
				);
			}
		}
		return $blocks;
	}

	private static function revive( array $plain ): array {
		return array_map(
			static function ( $p ) {
				return array(
					'start'    => new \DateTimeImmutable( $p[0] ),
					'end'      => new \DateTimeImmutable( $p[1] ),
					'calendar' => $p[2],
				);
			},
			$plain
		);
	}
}
