<?php
namespace MOBookingStudio\Infrastructure;

/**
 * Utilidades de base de datos: transacciones, fechas UTC y JSON.
 */
final class Db {
	public static function wpdb(): \wpdb {
		global $wpdb;
		return $wpdb;
	}

	/**
	 * Ejecuta una operación dentro de una transacción InnoDB. Si la operación lanza
	 * una excepción se revierte todo y la excepción se propaga.
	 *
	 * @template T
	 * @param callable():T $operation
	 * @return T
	 */
	public static function transaction( callable $operation ) {
		$wpdb = self::wpdb();
		$wpdb->query( 'START TRANSACTION' );
		try {
			$result = $operation();
			$wpdb->query( 'COMMIT' );
			return $result;
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		}
	}

	public static function toSql( ?\DateTimeImmutable $dt ): ?string {
		return $dt ? $dt->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ) : null;
	}

	public static function fromSql( ?string $value ): ?\DateTimeImmutable {
		if ( null === $value || '' === $value || '0000-00-00 00:00:00' === $value ) {
			return null;
		}
		return new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) );
	}

	public static function json( $value ): string {
		return (string) wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	public static function decode( ?string $json, $default = array() ) {
		if ( null === $json || '' === $json ) {
			return $default;
		}
		$value = json_decode( $json, true );
		return null === $value ? $default : $value;
	}

	/**
	 * Lanza una excepción si la última consulta falló, sin exponer el SQL a la persona.
	 */
	public static function check( $result, string $context ) {
		$wpdb = self::wpdb();
		if ( false === $result || '' !== $wpdb->last_error ) {
			Logger::error( 'Error de base de datos en ' . $context, array( 'error' => $wpdb->last_error ) );
			throw new \MOBookingStudio\Domain\DomainException( 'db_error', 'No pudimos guardar la información. Intenta de nuevo en unos minutos.', 500 );
		}
		return $result;
	}
}
