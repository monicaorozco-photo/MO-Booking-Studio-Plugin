<?php
namespace MOBookingStudio\Domain;

/**
 * Resuelve la zona IANA del negocio. Algunas instalaciones de PHP (por ejemplo
 * PHP 7.4 con su base de zonas incluida) no conocen America/Ciudad_Juarez,
 * creada en 2022. Desde el 30 de noviembre de 2022 Ciudad Juárez sigue las mismas
 * reglas que America/Denver (horario de montaña con cambio estacional de EE. UU.),
 * así que se usa como equivalente y se informa a la administradora.
 */
final class TimeZoneResolver {
	private const EQUIVALENTS = array(
		'America/Ciudad_Juarez' => 'America/Denver',
	);

	/**
	 * @return array{zone:\DateTimeZone,requested:string,effective:string,fallback:bool}
	 */
	public static function resolve( string $requested ): array {
		$requested = trim( $requested );
		if ( self::isKnown( $requested ) ) {
			return array(
				'zone'      => new \DateTimeZone( $requested ),
				'requested' => $requested,
				'effective' => $requested,
				'fallback'  => false,
			);
		}
		if ( isset( self::EQUIVALENTS[ $requested ] ) && self::isKnown( self::EQUIVALENTS[ $requested ] ) ) {
			$effective = self::EQUIVALENTS[ $requested ];
			return array(
				'zone'      => new \DateTimeZone( $effective ),
				'requested' => $requested,
				'effective' => $effective,
				'fallback'  => true,
			);
		}
		throw new DomainException( 'invalid_timezone', sprintf( 'La zona horaria "%s" no existe en este servidor.', $requested ) );
	}

	public static function zone( string $requested ): \DateTimeZone {
		return self::resolve( $requested )['zone'];
	}

	public static function isKnown( string $name ): bool {
		return '' !== $name && in_array( $name, \DateTimeZone::listIdentifiers( \DateTimeZone::ALL_WITH_BC ), true );
	}
}
