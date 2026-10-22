<?php
namespace MOBookingStudio\Application;

use MOBookingStudio\Domain\Contact;
use MOBookingStudio\Domain\DomainException;
use MOBookingStudio\Domain\Pricing;
use MOBookingStudio\Infrastructure\Audit;
use MOBookingStudio\Infrastructure\Db;
use MOBookingStudio\Infrastructure\Schema;

/**
 * Identificación de cliente frecuente al iniciar la reserva.
 *
 * La persona escribe su nombre y su correo o teléfono. Se busca el expediente por el
 * contacto (nunca solo por nombre) y el nombre se usa como apoyo: al menos una palabra
 * del nombre debe coincidir. Si el expediente tiene la categoría frecuente (sesiones
 * previas + completadas >= umbral, o categoría manual), se emite una verificación
 * temporal que permite cotizar y reservar con la tarifa de cliente frecuente.
 *
 * Protecciones:
 * - La respuesta nunca revela el número de sesiones ni datos del expediente; si no se
 *   confirma el beneficio, el mensaje es el mismo exista o no el contacto.
 * - La reserva debe hacerse con el mismo correo o teléfono verificado; los avisos y el
 *   enlace privado llegan a ese contacto.
 * - Límite de intentos por IP en la API.
 * Pendiente (siguiente etapa): confirmar la posesión del contacto con un código enviado
 * por correo o WhatsApp cuando esos envíos estén activos.
 */
final class FrequentCheck {
	private const TTL = 3 * HOUR_IN_SECONDS;

	public const REVIEW_MESSAGE = 'Verás los precios para público general. Antes de confirmar tu reserva revisaremos tu historial de sesiones; si aplica la tarifa de cliente frecuente, ajustaremos el precio y te avisaremos.';

	/**
	 * SEG-04 / Salida B: el precio frecuente automático está deshabilitado hasta tener una
	 * prueba de posesión del contacto (código o enlace de verificación). Mientras tanto,
	 * "Ya me he tomado fotos con ustedes" es una solicitud de revisión y la administradora
	 * aplica la tarifa con la acción auditada del panel.
	 */
	public static function automaticEnabled(): bool {
		return false;
	}

	public const NOT_CONFIRMED = 'No pudimos confirmar el beneficio de cliente frecuente con esos datos. Verás los precios para público general; si crees que es un error, continúa con tu reserva y lo revisaremos antes de confirmarla.';

	/**
	 * @param array $input {name, contact (correo o teléfono), phone_country}
	 * @return array{frequent:bool,token?:string,message:string,email?:string,phone?:string}
	 */
	public static function identify( array $input ): array {
		if ( ! self::automaticEnabled() ) {
			// Salida B: no se busca a nadie ni se revela si existe; solo se registra la solicitud de revisión.
			return array(
				'frequent' => false,
				'review'   => true,
				'message'  => self::REVIEW_MESSAGE,
			);
		}
		$name    = trim( sanitize_text_field( (string) ( $input['name'] ?? '' ) ) );
		$contact = trim( sanitize_text_field( (string) ( $input['contact'] ?? '' ) ) );
		$country = 'US' === strtoupper( (string) ( $input['phone_country'] ?? 'MX' ) ) ? 'US' : 'MX';
		if ( mb_strlen( $name ) < 2 ) {
			throw new DomainException( 'invalid_name', 'Escribe tu nombre.', 400, array( 'field' => 'name' ) );
		}
		$email = false !== strpos( $contact, '@' ) ? Contact::normalizeEmail( $contact ) : null;
		$phone = $email ? null : Contact::normalizePhone( $contact, $country );
		if ( ! $email && ! $phone ) {
			throw new DomainException( 'invalid_contact', 'Escribe el correo o el teléfono (10 dígitos) con el que reservaste antes.', 400, array( 'field' => 'contact' ) );
		}

		$client = self::findClient( $email, $phone );
		$ok     = $client && self::nameMatches( $name, (string) $client['name'] ) && Pricing::CATEGORY_FREQUENT === Clients::effectiveCategory( $client );
		Audit::log( 'client', $client ? (int) $client['id'] : 0, 'frequent_check', array( 'result' => $ok ? 'verified' : 'not_confirmed', 'by' => $email ? 'email' : 'phone' ), 'client', 0 );
		if ( ! $ok ) {
			return array(
				'frequent' => false,
				'message'  => self::NOT_CONFIRMED,
			);
		}
		$token = bin2hex( random_bytes( 32 ) );
		set_transient(
			self::key( $token ),
			array(
				'client_id' => (int) $client['id'],
				'email'     => $client['email_norm'],
				'phone'     => $client['phone_e164'],
			),
			self::TTL
		);
		return array(
			'frequent' => true,
			'token'    => $token,
			'message'  => '¡Gracias por volver! Verás los precios para clientes frecuentes.',
			// Se devuelve lo que escribió la persona, nunca datos del expediente.
			'email'    => $email ?: '',
			'phone'    => $phone ? $contact : '',
		);
	}

	/** Verificación vigente o null. */
	public static function get( ?string $token ): ?array {
		if ( ! self::automaticEnabled() ) {
			return null; // Ningún token concede precio frecuente (SEG-04).
		}
		$token = (string) $token;
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
			return null;
		}
		$data = get_transient( self::key( $token ) );
		return is_array( $data ) ? $data : null;
	}

	public static function category( ?string $token ): string {
		return self::get( $token ) ? Pricing::CATEGORY_FREQUENT : Pricing::CATEGORY_GENERAL;
	}

	/**
	 * Confirma que la reserva usa el contacto verificado.
	 */
	public static function assertContact( array $verification, array $contact ): void {
		$sameEmail = $verification['email'] && $verification['email'] === ( $contact['email_norm'] ?? null );
		$samePhone = $verification['phone'] && $verification['phone'] === ( $contact['phone_e164'] ?? null );
		if ( ! $sameEmail && ! $samePhone ) {
			throw new DomainException( 'frequent_contact_mismatch', 'Para conservar la tarifa de cliente frecuente usa el mismo correo o teléfono con el que te identificaste.', 400, array( 'field' => 'email' ) );
		}
	}

	private static function findClient( ?string $email, ?string $phone ): ?array {
		$wpdb  = Db::wpdb();
		$table = Schema::table( 'clients' );
		$rows  = $email
			? $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE email_norm = %s", $email ), ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			: $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE phone_e164 = %s", $phone ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// Un contacto compartido por varios expedientes no se resuelve automáticamente.
		return ( $rows && 1 === count( $rows ) ) ? $rows[0] : null;
	}

	/** Al menos una palabra de 3 o más letras en común, sin distinguir acentos ni mayúsculas. */
	public static function nameMatches( string $typed, string $stored ): bool {
		$words = static function ( string $s ): array {
			$s = strtolower( remove_accents( $s ) );
			$s = preg_replace( '/[^a-z ]+/', ' ', $s );
			return array_filter( explode( ' ', $s ), static function ( $w ) {
				return strlen( $w ) >= 3;
			} );
		};
		return (bool) array_intersect( $words( $typed ), $words( $stored ) );
	}

	private static function key( string $token ): string {
		return 'mo_fv_' . substr( hash( 'sha256', $token ), 0, 40 );
	}
}
