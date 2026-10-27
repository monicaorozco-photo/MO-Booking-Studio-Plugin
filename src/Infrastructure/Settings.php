<?php
namespace MOBookingStudio\Infrastructure;

use MOBookingStudio\Domain\TimeZoneResolver;

/**
 * Ajustes generales del negocio. Todos son editables desde el panel.
 * Los valores marcados como "propuesta" vienen de la sección 26 de la especificación.
 */
final class Settings {
	public const OPTION = 'mo_booking_settings';

	public static function defaults(): array {
		return array(
			'business_name'        => get_bloginfo( 'name' ),
			'timezone'             => 'America/Ciudad_Juarez',
			'bank_instructions'    => '',
			'payment_reference'    => 'Usa tu número de reserva como concepto o referencia.',
			'hold_hours'           => 12,   // Retención de horario mientras se paga.
			'correction_hours'     => 2,    // DEC-01: una sola sustitución en 2 horas.
			'review_reasons'       => "Archivo ilegible\nNo se identifica el monto\nReferencia incorrecta\nArchivo equivocado\nMonto incompleto\nNo recibimos el depósito", // P5: motivos que ve la persona.
			'proof_max_mb'         => 10,   // Propuesta: tamaño máximo de comprobante.
			'proof_max_files'      => 5,
			'token_days_after'     => 90,   // Propuesta: el enlace privado vence 90 días después de la cita.
			'booking_page_id'      => 0,
			'portal_page_id'       => 0,
			'admin_email'          => get_option( 'admin_email' ),
			'email_mode'           => 'simulate', // simulate | live. En simulación no se envía nada.
			'email_from_name'      => '',
			'email_from'           => '',
			'whatsapp_mode'        => 'manual', // off | manual | automatic
			'whatsapp_number'      => '',
			'reminder_hours'       => 48,   // Propuesta: recordatorio 48 horas antes.
			'terms_text'           => '',
			'privacy_url'          => '',
			'delete_on_uninstall'  => false,
			'uploads_verified'     => '',   // '' | ok | public | error
			'uploads_checked_at'   => '',
			'uploads_verified_path' => '',  // SEG-01: ruta canónica que se verificó.
			'uploads_reason'       => '',
			'delete_confirm_path'  => '',   // SEG-02: ruta exacta confirmada para borrar al desinstalar.
			// 9.5: medio público de contacto en el evento del cliente (nunca el teléfono de la clienta).
			'public_contact'       => '',
			// 8.1: texto del portal junto a la carga del comprobante. {whatsapp} = número de WhatsApp del negocio.
			'portal_proof_text'    => 'Sube aquí tu comprobante de anticipo o envíalo por WhatsApp con tu nombre o número de reserva al {whatsapp}.',
			// Rediseño: etiquetas del desglose de precio (solo presentación).
			'label_price_reference'    => 'Precio',
			'label_discount_date'      => 'Descuento por fecha anticipada',
			'label_discount_frequent'  => 'Descuento cliente frecuente',
			'label_discount_presale'   => 'Descuento por preventa',
			'label_price_discounted'   => 'Precio con descuento',
			// beta.4: textos del formulario público.
			'slot_taken_label'          => 'No disponible',
			'contract_notice'           => 'Antes de continuar, lee con calma el contrato completo. Ahí explicamos qué incluye tu sesión y cómo funcionan el anticipo, los cambios de fecha y las cancelaciones. Al aceptarlo confirmas que estás de acuerdo con todo lo que dice.',
			'whatsapp_consent_text'     => 'Acepto recibir por WhatsApp y por correo los avisos de mi reserva',
			'whatsapp_consent_required' => true,
			// beta.3: si no se pueden subir archivos, botón a WhatsApp del negocio con mensaje listo.
			'whatsapp_proof_button'  => 'Da clic en este botón para enviar tu comprobante por WhatsApp',
			'whatsapp_proof_message' => 'Hola! Hago el envío de mi comprobante de anticipo a nombre de {nombre} con número de reserva {referencia}',
		);
	}

	/**
	 * Enlace wa.me al WhatsApp del negocio con el mensaje del comprobante ({nombre} y
	 * {referencia} se reemplazan). Null si no hay número válido.
	 */
	public static function proofWhatsappUrl( string $name, string $reference ): ?string {
		$number = \MOBookingStudio\Domain\Contact::normalizePhone( (string) self::get( 'whatsapp_number' ), 'MX' );
		if ( ! $number ) {
			return null;
		}
		$text = strtr( (string) self::get( 'whatsapp_proof_message' ), array( '{nombre}' => $name, '{referencia}' => $reference ) );
		return 'https://wa.me/' . ltrim( $number, '+' ) . '?text=' . rawurlencode( $text );
	}

	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
	}

	public static function get( string $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	public static function update( array $values ): void {
		$current = self::all();
		foreach ( $values as $key => $value ) {
			if ( array_key_exists( $key, $current ) ) {
				$current[ $key ] = $value;
			}
		}
		update_option( self::OPTION, $current, false );
	}

	/**
	 * Motivos de revisión que puede ver la persona (uno por línea, editables).
	 *
	 * @return array<string,string> clave => texto
	 */
	public static function reviewReasons(): array {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) self::get( 'review_reasons' ) ) as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$out[ sanitize_title( $line ) ] = $line;
			}
		}
		return $out;
	}

	public static function correctionReason( string $key ): ?string {
		$reasons = self::reviewReasons();
		return $reasons[ $key ] ?? null;
	}

	/** Zona efectiva del negocio (con equivalente si el servidor no conoce la zona pedida). */
	public static function timezone(): \DateTimeZone {
		return self::timezoneInfo()['zone'];
	}

	/**
	 * DEC-07: el plugin usa su propia zona. Si la zona general de WordPress da otra hora
	 * (en cualquier momento del año, por el horario de verano), se avisa en el panel: las
	 * fechas de entradas, correos de otros plugins o tareas de WordPress no coincidirían.
	 *
	 * @return string Zona de WordPress cuando difiere; vacío si coinciden.
	 */
	public static function wordpressZoneMismatch( \DateTimeImmutable $now ): string {
		$wp   = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
		$mine = self::timezone();
		foreach ( array( 0, 3, 6, 9 ) as $months ) {
			$at = $now->modify( '+' . $months . ' months' );
			if ( $wp->getOffset( $at ) !== $mine->getOffset( $at ) ) {
				return function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : $wp->getName();
			}
		}
		return '';
	}

	public static function timezoneInfo(): array {
		try {
			return TimeZoneResolver::resolve( (string) self::get( 'timezone' ) );
		} catch ( \Throwable $e ) {
			return TimeZoneResolver::resolve( 'America/Ciudad_Juarez' );
		}
	}
}
