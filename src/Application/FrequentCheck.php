<?php
namespace MOBookingStudio\Application;

use MOBookingStudio\Domain\Contact;
use MOBookingStudio\Domain\DomainException;
use MOBookingStudio\Domain\Names;
use MOBookingStudio\Domain\Pricing;
use MOBookingStudio\Infrastructure\Audit;

/**
 * Identificación de cliente frecuente al iniciar la reserva (decisión B de la beta.3).
 *
 * "Ya me he tomado fotos con ustedes" pide nombre, correo y teléfono en campos separados.
 * Se reconoce al cliente si el correo O el teléfono coincide con su expediente (principal o
 * contacto adicional) y el nombre se parece (sin acentos y con faltas leves). Si es
 * frecuente (sesiones previas + completadas >= umbral, o categoría manual), se emite una
 * verificación temporal que muestra los precios de cliente frecuente (y la preventa).
 *
 * Protecciones:
 * - Nunca solo por nombre. Si el correo y el teléfono apuntan a clientes distintos, o un
 *   contacto lo comparten varios expedientes, no hay descuento automático.
 * - La respuesta nunca revela sesiones ni datos del expediente; si no se confirma, el
 *   mensaje es el mismo exista o no el contacto.
 * - La reserva debe usar el mismo correo o teléfono que coincidió.
 * - Límite de intentos por IP en la API.
 * - Toda cita pasa por la aprobación del anticipo; en el panel la reserva lleva la insignia
 *   "Frecuente reconocido por nombre y contacto" y se puede regresar al precio general.
 */
final class FrequentCheck {
	private const TTL = 3 * HOUR_IN_SECONDS;

	public const MATCH_AUTO = 'auto';
	/** beta.4: reconocida por correo aunque su teléfono esté en otro expediente (revisar). */
	public const MATCH_AUTO_REVIEW = 'auto_review';

	public static function isAuto( string $match ): bool {
		return in_array( $match, array( self::MATCH_AUTO, self::MATCH_AUTO_REVIEW ), true );
	}

	public const NOT_CONFIRMED = 'No pudimos confirmar el beneficio de cliente frecuente con esos datos. Verás los precios para público general; si crees que es un error, continúa con tu reserva y lo revisaremos antes de confirmarla.';

	public static function automaticEnabled(): bool {
		return (bool) apply_filters( 'mo_booking_frequent_automatic', true );
	}

	/**
	 * @param array $input {name, email, phone, phone_country}
	 * @return array{frequent:bool,token?:string,message:string,email?:string,phone?:string}
	 */
	public static function identify( array $input ): array {
		$name    = trim( (string) preg_replace( '/\s+/u', ' ', sanitize_text_field( (string) ( $input['name'] ?? '' ) ) ) );
		$rawMail = trim( sanitize_text_field( (string) ( $input['email'] ?? '' ) ) );
		$rawTel  = trim( sanitize_text_field( (string) ( $input['phone'] ?? '' ) ) );
		$country = 'US' === strtoupper( (string) ( $input['phone_country'] ?? 'MX' ) ) ? 'US' : 'MX';
		// Compatibilidad con la versión anterior del formulario (un solo campo "contacto").
		if ( '' === $rawMail && '' === $rawTel && ! empty( $input['contact'] ) ) {
			$contact = trim( sanitize_text_field( (string) $input['contact'] ) );
			if ( false !== strpos( $contact, '@' ) ) {
				$rawMail = $contact;
			} else {
				$rawTel = $contact;
			}
		}
		if ( mb_strlen( $name ) < 2 ) {
			throw new DomainException( 'invalid_name', 'Escribe tu nombre y apellido.', 400, array( 'field' => 'name' ) );
		}
		$email = '' !== $rawMail ? Contact::normalizeEmail( $rawMail ) : null;
		$phone = '' !== $rawTel ? Contact::normalizePhone( $rawTel, $country ) : null;
		if ( '' !== $rawMail && ! $email ) {
			throw new DomainException( 'invalid_email', 'Revisa tu correo.', 400, array( 'field' => 'email' ) );
		}
		if ( '' !== $rawTel && ! $phone ) {
			throw new DomainException( 'invalid_phone', 'Revisa tu teléfono (10 dígitos).', 400, array( 'field' => 'phone' ) );
		}
		if ( ! $email && ! $phone ) {
			throw new DomainException( 'invalid_contact', 'Escribe el correo o el teléfono con el que reservaste antes.', 400, array( 'field' => 'email' ) );
		}

		$found  = self::findClient( $email, $phone );
		$client = $found['client'];
		$ok     = self::automaticEnabled() && $client && Names::shareWord( $name, (string) $client['name'] ) && Pricing::CATEGORY_FREQUENT === Clients::effectiveCategory( $client );
		Audit::log(
			'client',
			$client ? (int) $client['id'] : 0,
			'frequent_check',
			array(
				'result' => $ok ? ( empty( $found['review'] ) ? 'verified' : 'verified_email_phone_elsewhere' ) : ( $found['reason'] ?: ( $client ? 'name_or_category' : 'not_found' ) ),
				'by'     => $found['by'],
			),
			'client',
			0
		);
		if ( ! $ok ) {
			return array(
				'frequent' => false,
				'message'  => self::NOT_CONFIRMED,
				// Se devuelve lo que escribió la persona para no pedirlo dos veces.
				'email'    => $rawMail,
				'phone'    => $rawTel,
			);
		}
		$token = bin2hex( random_bytes( 32 ) );
		set_transient(
			self::key( $token ),
			array(
				'client_id' => (int) $client['id'],
				// Solo los contactos que coincidieron: la reserva debe usar uno de ellos.
				'email'     => in_array( 'email', $found['by'], true ) ? $email : null,
				'phone'     => in_array( 'phone', $found['by'], true ) ? $phone : null,
				'review'    => ! empty( $found['review'] ),
			),
			self::TTL
		);
		return array(
			'frequent' => true,
			'token'    => $token,
			'message'  => '¡Gracias por volver! Verás los precios para clientes frecuentes.',
			'email'    => $rawMail,
			'phone'    => $rawTel,
			'locked'   => array( 'email' => in_array( 'email', $found['by'], true ), 'phone' => in_array( 'phone', $found['by'], true ) ),
		);
	}

	/** Verificación vigente o null. */
	public static function get( ?string $token ): ?array {
		if ( ! self::automaticEnabled() ) {
			return null;
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

	/**
	 * Un solo expediente al que apunten el correo y/o el teléfono escritos.
	 *
	 * @return array{client:?array,by:string[],reason:string}
	 */
	private static function findClient( ?string $email, ?string $phone ): array {
		$byMail  = $email ? Clients::idsByEmail( $email ) : array();
		$byPhone = $phone ? Clients::idsByPhone( $phone ) : array();
		if ( count( $byMail ) > 1 ) {
			return array( 'client' => null, 'by' => array(), 'reason' => 'shared_contact' );
		}
		// beta.4 (decisión de la administradora): si el correo es de una sola clienta, manda el
		// correo aunque el teléfono sea de otro expediente o lo compartan varios (familias).
		// La reserva queda marcada para revisar.
		if ( 1 === count( $byMail ) && $byPhone && array( $byMail[0] ) !== $byPhone ) {
			return array( 'client' => Clients::find( $byMail[0] ), 'by' => array( 'email' ), 'reason' => '', 'review' => true );
		}
		if ( count( $byPhone ) > 1 ) {
			return array( 'client' => null, 'by' => array(), 'reason' => 'shared_contact' );
		}
		$id = $byMail ? $byMail[0] : ( $byPhone ? $byPhone[0] : 0 );
		$by = array();
		if ( $byMail ) {
			$by[] = 'email';
		}
		if ( $byPhone ) {
			$by[] = 'phone';
		}
		return array( 'client' => $id ? Clients::find( $id ) : null, 'by' => $by, 'reason' => '' );
	}

	/** Compatibilidad: al menos una palabra en común (sin acentos, faltas leves). */
	public static function nameMatches( string $typed, string $stored ): bool {
		return Names::shareWord( $typed, $stored );
	}

	private static function key( string $token ): string {
		return 'mo_fv_' . substr( hash( 'sha256', $token ), 0, 40 );
	}
}
