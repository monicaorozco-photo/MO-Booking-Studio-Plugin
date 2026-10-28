<?php
namespace MOBookingStudio\Application;

use MOBookingStudio\Domain\BookingStatus;
use MOBookingStudio\Domain\Contact;
use MOBookingStudio\Domain\Money;
use MOBookingStudio\Infrastructure\Db;
use MOBookingStudio\Infrastructure\Logger;
use MOBookingStudio\Infrastructure\OptionStore;
use MOBookingStudio\Infrastructure\Schema;
use MOBookingStudio\Infrastructure\Settings;
use MOBookingStudio\Plugin;

/**
 * Cola de notificaciones por evento y canal.
 *
 * - Correo: se envía con wp_mail (el transporte autenticado lo configura el sitio, por
 *   ejemplo un plugin SMTP con Gmail). "Aceptado por transporte" no significa entregado.
 * - Modo simulación (predeterminado): se registra el mensaje sin enviarlo.
 * - Las reservas de prueba nunca envían mensajes.
 * - WhatsApp manual: la administradora abre el chat con el texto preparado; se registra
 *   "abierto para envío manual" y ella marca si lo envió. Nunca se afirma entregado.
 * - WhatsApp automático: pendiente de conectar un proveedor oficial; sin credenciales los
 *   mensajes quedan como "sin conexión" y la reserva funciona igual.
 */
final class Notifications {
	public const TEMPLATES_OPTION = 'mo_booking_templates';
	public const MAX_ATTEMPTS     = 5;

	public const STATUS_LABELS = array(
		'pending'        => 'Preparado (en cola)',
		'accepted'       => 'Aceptado por el transporte de correo',
		'simulated'      => 'Simulado (no enviado)',
		'failed'         => 'Fallido',
		'cancelled'      => 'Anulado',
		'skipped'        => 'Omitido (ya no vigente)',
		'opened_manual'  => 'Abierto para envío manual',
		'sent_manual'    => 'Enviado manualmente (marcado por la administradora)',
		'disconnected'   => 'WhatsApp automático sin conexión',
	);

	public static function variables(): array {
		return array(
			'nombre'             => 'Primer nombre de la persona (para saludar)',
			'nombre_completo'    => 'Nombre completo de la persona',
			'negocio'            => 'Nombre comercial del servicio',
			'servicio'           => 'Nombre del servicio',
			'referencia'         => 'Número de reserva',
			'fecha'              => 'Fecha de la cita',
			'hora'               => 'Hora de la cita',
			'zona'               => 'Zona horaria',
			'ubicacion'          => 'Ubicación del servicio',
			'instrucciones'      => 'Instrucciones del servicio',
			'total'              => 'Total',
			'anticipo'           => 'Anticipo',
			'saldo'              => 'Saldo pendiente',
			'vencimiento'        => 'Fecha y hora límite para pagar',
			'instrucciones_pago' => 'Datos para transferencia',
			'enlace'             => 'Enlace privado de la reserva',
			'motivo'             => 'Motivo (corrección, cancelación)',
			'importe'            => 'Importe del pago aprobado',
		);
	}

	public static function events(): array {
		return array(
			'booking_created'       => array( 'label' => 'Solicitud recibida e instrucciones de pago', 'to' => array( 'client', 'admin' ) ),
			'proof_received'        => array( 'label' => 'Comprobante recibido', 'to' => array( 'client', 'admin' ) ),
			'proof_received_late'   => array( 'label' => 'Comprobante recibido después del vencimiento', 'to' => array( 'admin' ) ),
			'correction_requested'  => array( 'label' => 'Corrección solicitada', 'to' => array( 'client' ) ),
			'payment_approved'      => array( 'label' => 'Pago aprobado', 'to' => array( 'client' ) ),
			'booking_confirmed'     => array( 'label' => 'Cita confirmada', 'to' => array( 'client' ) ),
			'booking_rejected'      => array( 'label' => 'Solicitud no aprobada', 'to' => array( 'client' ) ),
			'booking_expired'       => array( 'label' => 'Retención vencida', 'to' => array( 'client' ) ),
			'booking_cancelled'     => array( 'label' => 'Cancelación', 'to' => array( 'client' ) ),
			'booking_rescheduled'   => array( 'label' => 'Cambio confirmado', 'to' => array( 'client' ) ),
			'client_rescheduled'    => array( 'label' => 'La clienta cambió su fecha (aviso para ti)', 'to' => array( 'admin' ) ),
			'reminder'              => array( 'label' => 'Recordatorio de la cita', 'to' => array( 'client' ) ),
		);
	}

	public static function defaultTemplates(): array {
		$sign = "\n\n{{negocio}}";
		return array(
			'booking_created'      => array(
				'enabled' => true,
				'subject' => 'Recibimos tu solicitud {{referencia}}',
				'body'    => "Hola {{nombre}}:\n\nRecibimos tu solicitud para {{servicio}} el {{fecha}} a las {{hora}}.\n\nTotal: {{total}}\nAnticipo para apartar: {{anticipo}}\nSaldo: {{saldo}}\n\nTu horario queda apartado hasta {{vencimiento}}. Para confirmarlo, transfiere el anticipo y sube tu comprobante desde tu enlace privado:\n{{enlace}}\n\n{{instrucciones_pago}}" . $sign,
				'admin'   => "Nueva solicitud {{referencia}}: {{servicio}}, {{fecha}} {{hora}}. {{nombre}}. Total {{total}}, anticipo {{anticipo}}. Vence: {{vencimiento}}.",
			),
			'proof_received'       => array(
				'enabled' => true,
				'subject' => 'Recibimos tu comprobante ({{referencia}})',
				'body'    => "Hola {{nombre}}:\n\nRecibimos tu comprobante. Tu horario sigue apartado mientras verificamos tu depósito. Te avisaremos en cuanto lo confirmemos.\n\nConsulta tu reserva aquí:\n{{enlace}}" . $sign,
				'admin'   => 'Nuevo comprobante en {{referencia}} ({{nombre}}, {{servicio}} {{fecha}} {{hora}}). Revísalo en el panel.',
			),
			'proof_received_late'  => array(
				'enabled' => true,
				'subject' => 'Comprobante tardío en {{referencia}}',
				'body'    => '',
				'admin'   => 'Llegó un comprobante después del vencimiento en {{referencia}} ({{nombre}}). El horario no se recuperó automáticamente; revisa la conciliación en el panel.',
			),
			'correction_requested' => array(
				'enabled' => true,
				'subject' => 'Necesitamos revisar tu pago ({{referencia}})',
				'body'    => "Hola {{nombre}}:\n\nRevisamos tu pago y necesitamos lo siguiente:\n{{motivo}}\n\nPuedes enviar otro comprobante desde tu enlace privado:\n{{enlace}}" . $sign,
				'admin'   => '',
			),
			'payment_approved'     => array(
				'enabled' => true,
				'subject' => 'Pago verificado ({{referencia}})',
				'body'    => "Hola {{nombre}}:\n\nVerificamos tu pago de {{importe}}. Saldo pendiente: {{saldo}}.\n\nConsulta tu reserva:\n{{enlace}}" . $sign,
				'admin'   => '',
			),
			'booking_confirmed'    => array(
				'enabled' => true,
				'subject' => '¡Tu cita está confirmada! ({{referencia}})',
				'body'    => "Hola {{nombre}}:\n\nTu cita para {{servicio}} está confirmada:\n{{fecha}}, {{hora}}\n{{ubicacion}}\n\nSaldo pendiente: {{saldo}}\n\n{{instrucciones}}\n\nConsulta tu reserva:\n{{enlace}}" . $sign,
				'admin'   => '',
			),
			'booking_rejected'     => array(
				'enabled' => true,
				'subject' => 'Sobre tu solicitud {{referencia}}',
				'body'    => "Hola {{nombre}}:\n\nNo pudimos aprobar tu solicitud para {{servicio}} el {{fecha}} a las {{hora}}.\n{{motivo}}\n\nSi tienes dudas, responde a este mensaje." . $sign,
				'admin'   => '',
			),
			'booking_expired'      => array(
				'enabled' => true,
				'subject' => 'Tu horario apartado venció ({{referencia}})',
				'body'    => "Hola {{nombre}}:\n\nEl plazo para pagar el anticipo de tu solicitud {{referencia}} terminó y el horario se liberó. Si ya transferiste, escríbenos con tu comprobante para revisarlo." . $sign,
				'admin'   => '',
			),
			'booking_cancelled'    => array(
				'enabled' => true,
				'subject' => 'Cancelación de tu cita {{referencia}}',
				'body'    => "Hola {{nombre}}:\n\nTu cita para {{servicio}} del {{fecha}} a las {{hora}} quedó cancelada.\n{{motivo}}\n\nConsulta el detalle:\n{{enlace}}" . $sign,
				'admin'   => '',
			),
			'booking_rescheduled'  => array(
				'enabled' => true,
				'subject' => 'Tu cita cambió de fecha ({{referencia}})',
				'body'    => "Hola {{nombre}}:\n\nTu cita para {{servicio}} ahora es el {{fecha}} a las {{hora}}.\n\nSaldo pendiente: {{saldo}}\n\nConsulta el detalle:\n{{enlace}}" . $sign,
				'admin'   => '',
			),
			'client_rescheduled'   => array(
				'enabled' => true,
				'subject' => 'Cambio de fecha {{referencia}}',
				'body'    => '',
				'admin'   => "{{nombre}} cambió su fecha desde su página de reserva ({{referencia}}).\nAntes: {{motivo}}\nAhora: {{fecha}} a las {{hora}}.\nTotal: {{total}} · Saldo: {{saldo}}",
			),
			'reminder'             => array(
				'enabled' => true,
				'subject' => 'Recordatorio: tu cita del {{fecha}}',
				'body'    => "Hola {{nombre}}:\n\nTe recordamos tu cita para {{servicio}} el {{fecha}} a las {{hora}}.\n{{ubicacion}}\n\n{{instrucciones}}\n\nSaldo pendiente: {{saldo}}\n{{enlace}}" . $sign,
				'admin'   => '',
			),
		);
	}

	// ----- Plantillas propias (beta.3) -----

	public const CUSTOM_OPTION = 'mo_booking_custom_templates';

	public const CHANNELS = array(
		'email'    => 'Correo',
		'whatsapp' => 'WhatsApp (se abre con el texto listo)',
	);

	/**
	 * Plantillas que crea la administradora. Un correo puede enviarse solo con un evento
	 * (además del aviso del sistema) o a mano desde la reserva; una de WhatsApp se usa al
	 * abrir el chat desde la reserva.
	 *
	 * @return array<string,array{name:string,channel:string,trigger:string,subject:string,body:string,enabled:bool}>
	 */
	public static function customTemplates(): array {
		$out = array();
		foreach ( (array) OptionStore::load( self::CUSTOM_OPTION, array() ) as $id => $t ) {
			if ( is_array( $t ) && preg_match( '/^c[a-f0-9]{8}$/', (string) $id ) ) {
				$out[ $id ] = array_merge( array( 'name' => '', 'channel' => 'email', 'trigger' => 'manual', 'subject' => '', 'body' => '', 'enabled' => true ), $t );
			}
		}
		return $out;
	}

	/** Crea o actualiza una plantilla propia. Devuelve su id y los códigos que no se reconocen. */
	public static function saveCustom( ?string $id, array $data ): array {
		$name    = trim( sanitize_text_field( (string) ( $data['name'] ?? '' ) ) );
		$channel = isset( self::CHANNELS[ $data['channel'] ?? '' ] ) ? (string) $data['channel'] : 'email';
		$trigger = (string) ( $data['trigger'] ?? 'manual' );
		$trigger = 'email' === $channel && isset( self::events()[ $trigger ] ) ? $trigger : 'manual';
		$subject = sanitize_text_field( (string) ( $data['subject'] ?? '' ) );
		$raw     = (string) ( $data['body'] ?? '' );
		// WhatsApp es texto: si se escribió en el editor con formato, se convierte (negritas → *texto*).
		$body    = 'email' === $channel ? self::cleanBody( $raw ) : sanitize_textarea_field( self::isHtml( $raw ) ? self::htmlToText( self::cleanBody( $raw ), true ) : $raw );
		if ( '' === $name ) {
			throw new \MOBookingStudio\Domain\DomainException( 'invalid_template', 'Ponle un nombre a la plantilla.', 400, array( 'field' => 'name' ) );
		}
		if ( '' === trim( wp_strip_all_tags( $body ) ) ) {
			throw new \MOBookingStudio\Domain\DomainException( 'invalid_template', 'Escribe el texto del mensaje.', 400, array( 'field' => 'body' ) );
		}
		if ( 'email' === $channel && '' === trim( $subject ) ) {
			throw new \MOBookingStudio\Domain\DomainException( 'invalid_template', 'Un correo necesita asunto.', 400, array( 'field' => 'subject' ) );
		}
		$all = self::customTemplates();
		$id  = $id && isset( $all[ $id ] ) ? $id : 'c' . bin2hex( random_bytes( 4 ) );
		$all[ $id ] = array(
			'name'    => $name,
			'channel' => $channel,
			'trigger' => $trigger,
			'subject' => $subject,
			'body'    => $body,
			'enabled' => ! empty( $data['enabled'] ),
		);
		OptionStore::save( self::CUSTOM_OPTION, $all );
		\MOBookingStudio\Infrastructure\Audit::log( 'template', 0, 'saved', array( 'id' => $id, 'name' => $name ) );
		return array( 'id' => $id, 'unknown' => self::unknownCodes( $subject . "\n" . $body ) );
	}

	/** Quitar una plantilla propia. Los mensajes ya enviados conservan su texto. */
	public static function deleteCustom( string $id ): void {
		$all = self::customTemplates();
		if ( ! isset( $all[ $id ] ) ) {
			throw new \MOBookingStudio\Domain\DomainException( 'not_found', 'La plantilla no existe.', 404 );
		}
		unset( $all[ $id ] );
		OptionStore::save( self::CUSTOM_OPTION, $all );
		\MOBookingStudio\Infrastructure\Audit::log( 'template', 0, 'deleted', array( 'id' => $id ) );
	}

	/**
	 * Códigos escritos que no existen: {{desconocido}} o {nombre} con una sola llave.
	 *
	 * @return string[] Texto tal como se escribió.
	 */
	public static function unknownCodes( string $text ): array {
		$known = self::variables();
		$out   = array();
		if ( preg_match_all( '/\{\{\s*([^{}\s]+)\s*\}\}/u', $text, $m ) ) {
			foreach ( $m[1] as $i => $code ) {
				if ( ! isset( $known[ $code ] ) ) {
					$out[] = $m[0][ $i ];
				}
			}
		}
		// Una sola llave ({nombre}) no se reemplaza en estas plantillas.
		if ( preg_match_all( '/(?<!\{)\{([a-z_]+)\}(?!\})/u', $text, $m ) ) {
			foreach ( $m[0] as $single ) {
				$out[] = $single;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** Datos de ejemplo para la vista previa. */
	public static function sampleVars(): array {
		return array(
			'nombre'             => 'Ana',
			'nombre_completo'    => 'Ana López',
			'negocio'            => (string) Settings::get( 'business_name' ),
			'servicio'           => 'Minis navideñas',
			'referencia'         => 'MO-AB12CD',
			'fecha'              => 'sábado 21 de noviembre de 2026',
			'hora'               => '10:30 hrs',
			'zona'               => 'America/Ciudad_Juarez',
			'ubicacion'          => 'Estudio MO, Av. Ejemplo 123',
			'instrucciones'      => 'Llega 10 minutos antes.',
			'total'              => '$1,400.00 MXN',
			'anticipo'           => '$500.00 MXN',
			'saldo'              => '$900.00 MXN',
			'vencimiento'        => 'viernes 20 de noviembre de 2026, 22:30 hrs',
			'instrucciones_pago' => trim( (string) Settings::get( 'bank_instructions' ) ) ?: 'Banco Ejemplo · CLABE 000000000000000000',
			'enlace'             => home_url( '/mi-reserva/#acceso=…' ),
			'motivo'             => 'El comprobante no se lee completo.',
			'importe'            => '$500.00 MXN',
		);
	}

	/** Plantilla propia por id de evento de la cola ("custom_c1a2b3c4"), o null. */
	private static function customFor( string $event ): ?array {
		if ( 0 !== strpos( $event, 'custom_' ) ) {
			return null;
		}
		$all = self::customTemplates();
		$id  = substr( $event, 7 );
		return isset( $all[ $id ] ) ? array( 'enabled' => $all[ $id ]['enabled'], 'subject' => $all[ $id ]['subject'], 'body' => $all[ $id ]['body'], 'admin' => '', 'name' => $all[ $id ]['name'] ) : null;
	}

	/** Etiqueta legible de un evento de la cola, incluidas las plantillas propias. */
	public static function eventLabel( string $event ): string {
		$events = self::events();
		if ( isset( $events[ $event ] ) ) {
			return $events[ $event ]['label'];
		}
		$custom = self::customFor( $event );
		if ( $custom ) {
			return $custom['name'];
		}
		return 0 === strpos( $event, 'custom_' ) ? 'Plantilla propia (ya eliminada)' : $event;
	}

	/** Envía ahora, a mano, un correo con una plantilla propia. */
	public static function sendCustomNow( array $booking, string $templateId ): void {
		$all = self::customTemplates();
		if ( ! isset( $all[ $templateId ] ) || 'email' !== $all[ $templateId ]['channel'] ) {
			throw new \MOBookingStudio\Domain\DomainException( 'not_found', 'Elige una plantilla de correo.', 404 );
		}
		$to = (string) ( $booking['contact']['email'] ?? '' );
		if ( ! is_email( $to ) ) {
			throw new \MOBookingStudio\Domain\DomainException( 'no_email', 'La reserva no tiene un correo válido.' );
		}
		$id = self::enqueue( 'custom_' . $templateId, 'email', $booking['id'], $to, 'client', Plugin::clock()->now(), null, 'manual:' . $templateId . ':' . $booking['id'] . ':' . bin2hex( random_bytes( 4 ) ) );
		if ( $id ) {
			self::processOne( $id );
		}
	}

	public static function templates(): array {
		$stored = OptionStore::load( self::TEMPLATES_OPTION, array() );
		$out    = self::defaultTemplates();
		foreach ( $out as $event => $tpl ) {
			// beta.4: texto propio para WhatsApp (vacío = se usa el del correo, convertido).
			$out[ $event ] += array( 'whatsapp' => '' );
		}
		foreach ( (array) $stored as $event => $tpl ) {
			if ( isset( $out[ $event ] ) && is_array( $tpl ) ) {
				$out[ $event ] = array_merge( $out[ $event ], array_intersect_key( $tpl, $out[ $event ] ) );
			}
		}
		return $out;
	}

	/**
	 * Guarda los avisos del sistema. Devuelve los códigos que no se reconocen para avisar
	 * (se guarda igual: el código desconocido simplemente queda vacío al enviar).
	 *
	 * @return string[]
	 */
	public static function saveTemplates( array $templates ): array {
		$clean   = array();
		$unknown = array();
		foreach ( self::defaultTemplates() as $event => $default ) {
			if ( ! isset( $templates[ $event ] ) ) {
				continue;
			}
			$t = $templates[ $event ];
			if ( ! empty( $t['reset'] ) ) {
				$clean[ $event ] = array_merge( $default, array( 'enabled' => ! empty( $t['enabled'] ), 'whatsapp' => '' ) );
				continue;
			}
			$clean[ $event ] = array(
				'enabled'  => ! empty( $t['enabled'] ),
				'subject'  => sanitize_text_field( (string) ( $t['subject'] ?? $default['subject'] ) ),
				'body'     => self::cleanBody( (string) ( $t['body'] ?? $default['body'] ) ),
				'admin'    => sanitize_textarea_field( (string) ( $t['admin'] ?? $default['admin'] ) ),
				'whatsapp' => sanitize_textarea_field( (string) ( $t['whatsapp'] ?? '' ) ),
			);
			$unknown = array_merge( $unknown, self::unknownCodes( implode( "\n", array( $clean[ $event ]['subject'], $clean[ $event ]['body'], $clean[ $event ]['admin'], $clean[ $event ]['whatsapp'] ) ) ) );
		}
		OptionStore::save( self::TEMPLATES_OPTION, $clean );
		return array_values( array_unique( $unknown ) );
	}

	// ----- Correos con formato (beta.4) -----

	/** Etiquetas permitidas en el texto de los correos (negritas, encabezados, listas, enlaces). */
	public static function allowedHtml(): array {
		return array(
			'p'          => array( 'style' => true ),
			'br'         => array(),
			'strong'     => array(),
			'b'          => array(),
			'em'         => array(),
			'i'          => array(),
			'u'          => array(),
			'h1'         => array( 'style' => true ),
			'h2'         => array( 'style' => true ),
			'h3'         => array( 'style' => true ),
			'h4'         => array( 'style' => true ),
			'ul'         => array(),
			'ol'         => array(),
			'li'         => array(),
			'blockquote' => array(),
			'hr'         => array(),
			'span'       => array( 'style' => true ),
			'a'          => array( 'href' => true, 'target' => true, 'rel' => true ),
		);
	}

	public static function cleanBody( string $html ): string {
		return trim( wp_kses( $html, self::allowedHtml() ) );
	}

	public static function isHtml( string $text ): bool {
		return $text !== wp_strip_all_tags( $text );
	}

	/**
	 * Texto del correo con formato. Los datos de la reserva se escapan; las plantillas sin
	 * etiquetas (texto simple) conservan sus saltos de línea.
	 */
	public static function renderHtml( string $template, array $vars ): string {
		if ( ! self::isHtml( $template ) ) {
			return make_clickable( nl2br( esc_html( self::render( $template, $vars ) ) ) );
		}
		$html = (string) preg_replace_callback(
			'/\{\{\s*([a-z_]+)\s*\}\}/',
			static function ( $m ) use ( $vars ) {
				if ( ! isset( $vars[ $m[1] ] ) ) {
					return '';
				}
				$value = esc_html( wp_strip_all_tags( (string) $vars[ $m[1] ] ) );
				return 'enlace' === $m[1] ? '<a href="' . esc_url( (string) $vars[ $m[1] ] ) . '">' . $value . '</a>' : nl2br( $value );
			},
			self::cleanBody( $template )
		);
		return $html;
	}

	/** Versión de texto simple (para clientes de correo sin formato y para WhatsApp). */
	public static function htmlToText( string $html, bool $whatsapp = false ): string {
		$text = $html;
		if ( $whatsapp ) {
			$text = (string) preg_replace( '#<(strong|b|h[1-4])[^>]*>(.*?)</\1>#is', '*$2*', $text );
			$text = (string) preg_replace( '#<(em|i)[^>]*>(.*?)</\1>#is', '_$2_', $text );
		}
		$text = (string) preg_replace( '#<a [^>]*href="([^"]*)"[^>]*>(.*?)</a>#is', '$2 ($1)', $text );
		$text = (string) preg_replace( '#\s*<br\s*/?>\s*#i', "\n", $text );
		$text = (string) preg_replace( '#</(p|h[1-6]|li|blockquote|ul|ol)>#i', "\n\n", $text );
		$text = (string) preg_replace( '#<li[^>]*>#i', '• ', $text );
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' );
		$text = (string) preg_replace( "/[ \t]+\n/", "\n", $text );
		$text = (string) preg_replace( "/\n{3,}/", "\n\n", $text );
		// "texto (texto)" cuando el enlace se escribió como la propia dirección.
		$text = (string) preg_replace( '#(https?://\S+) \(\1\)#', '$1', $text );
		return trim( $text );
	}

	/** Correo completo: el texto dentro de un diseño sencillo y legible en el teléfono. */
	private static function emailLayout( string $inner ): string {
		return '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head><body style="margin:0;padding:0;background:#f6f4f1;"><div style="max-width:600px;margin:0 auto;padding:24px 16px;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.55;color:#1f2523;"><div style="background:#ffffff;border-radius:10px;padding:24px;">' . $inner . '</div></div></body></html>';
	}

	/**
	 * Reemplaza {{variable}} con valores ya convertidos a texto plano. Las plantillas no
	 * pueden ejecutar código; los correos se envían como texto plano.
	 */
	public static function render( string $template, array $vars ): string {
		return (string) preg_replace_callback(
			'/\{\{\s*([a-z_]+)\s*\}\}/',
			static function ( $m ) use ( $vars ) {
				return isset( $vars[ $m[1] ] ) ? wp_strip_all_tags( (string) $vars[ $m[1] ] ) : '';
			},
			$template
		);
	}

	public static function varsFor( array $booking, array $extra = array() ): array {
		$service = Services::find( $booking['service_id'] );
		$zone    = Settings::timezone();
		$local   = $booking['start']->setTimezone( $zone );
		$summary = Bookings::summary( $booking );
		$brand   = ( $service && $service['brand'] ) ? $service['brand'] : (string) Settings::get( 'business_name' );
		return array(
			'nombre'             => \MOBookingStudio\Domain\Names::first( (string) ( $booking['contact']['name'] ?? '' ) ),
			'nombre_completo'    => (string) ( $booking['contact']['name'] ?? '' ),
			'negocio'            => $brand,
			'servicio'           => $service ? $service['title'] : ( $booking['quote']['service_title'] ?? '' ),
			'referencia'         => $booking['public_ref'],
			'fecha'              => Catalog::dateText( $local ),
			// beta.6.1: avisos más cortos, "10:30 hrs" (sin zona horaria).
			'hora'               => $local->format( 'H:i' ) . ' hrs',
			'zona'               => $booking['timezone'],
			'ubicacion'          => $service ? $service['location'] : '',
			'instrucciones'      => $service ? $service['instructions'] : '',
			'total'              => Money::format( $booking['total_cents'] ),
			'anticipo'           => Money::format( $booking['deposit_cents'] ),
			'saldo'              => Money::format( $summary['balance_cents'] ),
			'vencimiento'        => $booking['expires'] ? Catalog::dateText( $booking['expires']->setTimezone( $zone ) ) . ', ' . $booking['expires']->setTimezone( $zone )->format( 'H:i' ) . ' hrs' : '',
			'instrucciones_pago' => trim( (string) Settings::get( 'bank_instructions' ) . "\n" . (string) Settings::get( 'payment_reference' ) . ' Referencia: ' . $booking['public_ref'] ),
			'enlace'             => Bookings::portalUrl( $booking ),
			'motivo'             => (string) ( $extra['reason'] ?? '' ),
			'importe'            => isset( $extra['amount_cents'] ) ? Money::format( (int) $extra['amount_cents'] ) : '',
		);
	}

	/**
	 * Encola los mensajes de un evento y procesa de inmediato los que ya deben enviarse.
	 */
	public static function bookingEvent( string $event, array $booking, array $extra = array() ): void {
		$events = self::events();
		if ( ! isset( $events[ $event ] ) ) {
			return;
		}
		$tpl = self::templates()[ $event ] ?? null;
		$now = Plugin::clock()->now();
		$ids = array();
		foreach ( $tpl && ! empty( $tpl['enabled'] ) ? $events[ $event ]['to'] : array() as $to ) {
			if ( 'admin' === $to && '' === trim( (string) $tpl['admin'] ) ) {
				continue;
			}
			if ( 'client' === $to && '' === trim( (string) $tpl['body'] ) ) {
				continue;
			}
			$recipient = 'admin' === $to ? (string) Settings::get( 'admin_email' ) : (string) ( $booking['contact']['email'] ?? '' );
			if ( ! is_email( $recipient ) ) {
				continue;
			}
			$ids[] = self::enqueue( $event, 'email', $booking['id'], $recipient, $to, $now, null, $event . ':' . $booking['id'] . ':' . $booking['version'] . ':' . $to, $extra );
		}
		if ( $tpl && ! empty( $tpl['enabled'] ) && 'automatic' === Settings::get( 'whatsapp_mode' ) && in_array( 'client', $events[ $event ]['to'], true ) && ! empty( $booking['consent']['whatsapp'] ) ) {
			$phone = $booking['contact']['phone_e164'] ?? '';
			if ( $phone ) {
				self::enqueue( $event, 'whatsapp', $booking['id'], $phone, 'client', $now, null, $event . ':' . $booking['id'] . ':' . $booking['version'] . ':wa', $extra );
			}
		}
		// Plantillas propias de correo ligadas a este evento.
		foreach ( self::customTemplates() as $cid => $custom ) {
			if ( $custom['enabled'] && 'email' === $custom['channel'] && $event === $custom['trigger'] && is_email( (string) ( $booking['contact']['email'] ?? '' ) ) ) {
				$ids[] = self::enqueue( 'custom_' . $cid, 'email', $booking['id'], (string) $booking['contact']['email'], 'client', $now, null, 'custom_' . $cid . ':' . $booking['id'] . ':' . $booking['version'], $extra );
			}
		}
		if ( 'booking_confirmed' === $event && $tpl && ! empty( $tpl['enabled'] ) ) {
			self::scheduleReminder( $booking );
		}
		foreach ( array_filter( $ids ) as $id ) {
			self::processOne( $id );
		}
	}

	/**
	 * Aviso directo a la administradora (por ejemplo, empalmes agrupados). La clave evita
	 * duplicados si la tarea se repite.
	 */
	public static function adminMessage( string $dedupe, string $subject, string $body ): void {
		$to = (string) Settings::get( 'admin_email' );
		if ( ! is_email( $to ) ) {
			return;
		}
		$id = self::enqueue( 'admin_message', 'email', 0, $to, 'admin', Plugin::clock()->now(), null, $dedupe, array( 'subject' => $subject, 'body' => $body ) );
		if ( $id ) {
			self::processOne( $id );
		}
	}

	/** Programa el recordatorio; si la fecha cambia, la clave cambia y el anterior se anula. */
	public static function scheduleReminder( array $booking ): void {
		$hours = (float) Settings::get( 'reminder_hours' );
		if ( $hours <= 0 || ! in_array( $booking['status'], array( BookingStatus::CONFIRMED ), true ) ) {
			return;
		}
		$tpl = self::templates()['reminder'];
		if ( empty( $tpl['enabled'] ) || ! is_email( $booking['contact']['email'] ?? '' ) ) {
			return;
		}
		$sendAt = $booking['start']->modify( sprintf( '-%d minutes', (int) round( $hours * 60 ) ) );
		$now    = Plugin::clock()->now();
		if ( $booking['start'] <= $now ) {
			return;
		}
		self::enqueue( 'reminder', 'email', $booking['id'], (string) $booking['contact']['email'], 'client', $sendAt > $now ? $sendAt : $now, $booking['start'], 'reminder:' . $booking['id'] . ':' . $booking['start_utc'] . ':' . $booking['version'] );
	}

	/** Anula mensajes pendientes de la reserva (por ejemplo, recordatorios tras cancelar). */
	public static function cancelPending( int $bookingId ): void {
		$wpdb = Db::wpdb();
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table( 'notifications' ) . " SET status = 'cancelled', updated_at = %s WHERE booking_id = %d AND status = 'pending' AND event = 'reminder'", Db::toSql( Plugin::clock()->now() ), $bookingId ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private static function enqueue( string $event, string $channel, int $bookingId, string $recipient, string $audience, \DateTimeImmutable $sendAfter, ?\DateTimeImmutable $validUntil, string $dedupe, array $extra = array() ): ?int {
		$wpdb = Db::wpdb();
		$now  = Db::toSql( Plugin::clock()->now() );
		$done = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO ' . Schema::table( 'notifications' ) . ' (event, channel, booking_id, recipient, status, attempts, send_after, valid_until, dedupe_key, payload, created_at, updated_at) VALUES (%s, %s, %d, %s, %s, 0, %s, %s, %s, %s, %s, %s)', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$event,
				$channel,
				$bookingId,
				$recipient,
				'pending',
				Db::toSql( $sendAfter ),
				$validUntil ? Db::toSql( $validUntil ) : '9999-12-31 00:00:00',
				mb_substr( $dedupe, 0, 120 ),
				Db::json( array( 'audience' => $audience, 'extra' => $extra ) ),
				$now,
				$now
			)
		);
		return $done ? (int) $wpdb->insert_id : null;
	}

	/**
	 * Procesa la cola: mensajes pendientes cuyo momento ya llegó.
	 */
	public static function processQueue( int $limit = 20 ): int {
		$wpdb = Db::wpdb();
		$now  = Db::toSql( Plugin::clock()->now() );
		$ids  = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'notifications' ) . " WHERE status = 'pending' AND send_after <= %s AND (locked_until IS NULL OR locked_until < %s) ORDER BY send_after LIMIT %d", $now, $now, $limit ) ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n    = 0;
		foreach ( $ids as $id ) {
			$n += self::processOne( (int) $id ) ? 1 : 0;
		}
		update_option( 'mo_booking_queue_last_run', Plugin::clock()->now()->format( 'c' ), false );
		return $n;
	}

	public static function processOne( int $id ): bool {
		$wpdb  = Db::wpdb();
		$table = Schema::table( 'notifications' );
		$now   = Plugin::clock()->now();
		// Bloqueo de la tarea: solo un proceso puede tomar el mensaje.
		$taken = $wpdb->query( $wpdb->prepare( "UPDATE $table SET locked_until = %s, attempts = attempts + 1, updated_at = %s WHERE id = %d AND status = 'pending' AND send_after <= %s AND (locked_until IS NULL OR locked_until < %s)", Db::toSql( $now->modify( '+5 minutes' ) ), Db::toSql( $now ), $id, Db::toSql( $now ), Db::toSql( $now ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( 1 !== $taken ) {
			return false;
		}
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$set = static function ( array $fields ) use ( $wpdb, $table, $id, $now ) {
			$wpdb->update( $table, array_merge( $fields, array( 'locked_until' => null, 'updated_at' => Db::toSql( $now ) ) ), array( 'id' => $id ) );
		};
		if ( Db::fromSql( $row['valid_until'] ) <= $now ) {
			$set( array( 'status' => 'skipped', 'last_error' => 'El aviso ya no era vigente cuando se procesó la cola.' ) );
			return false;
		}
		if ( 'admin_message' === $row['event'] ) {
			$extra = (array) ( ( (array) Db::decode( $row['payload'] ) )['extra'] ?? array() );
			return self::deliver( $row, '[Reservas] ' . (string) ( $extra['subject'] ?? '' ), (string) ( $extra['body'] ?? '' ), false, 'admin', $set );
		}
		$booking = $row['booking_id'] ? Bookings::find( (int) $row['booking_id'] ) : null;
		if ( ! $booking ) {
			$set( array( 'status' => 'skipped', 'last_error' => 'La reserva no existe.' ) );
			return false;
		}
		if ( 'reminder' === $row['event'] && ! in_array( $booking['status'], array( BookingStatus::CONFIRMED ), true ) ) {
			$set( array( 'status' => 'cancelled', 'last_error' => 'La cita ya no está activa.' ) );
			return false;
		}
		if ( 'whatsapp' === $row['channel'] ) {
			$set( array( 'status' => 'disconnected', 'last_error' => 'WhatsApp automático pendiente de conectar con un proveedor oficial.' ) );
			return false;
		}

		$payload  = (array) Db::decode( $row['payload'] );
		$audience = $payload['audience'] ?? 'client';
		$tpl      = self::templates()[ $row['event'] ] ?? self::customFor( $row['event'] );
		if ( ! $tpl ) {
			$set( array( 'status' => 'skipped', 'last_error' => 'La plantilla ya no existe.' ) );
			return false;
		}
		$vars = self::varsFor( $booking, (array) ( $payload['extra'] ?? array() ) );
		if ( 'admin' === $audience ) {
			// En tus avisos internos, {{nombre}} es el nombre completo para identificarla.
			$vars['nombre'] = $vars['nombre_completo'];
		}
		$subject = self::render( $tpl['subject'], $vars );
		$html    = '';
		if ( 'admin' === $audience ) {
			$body    = self::render( $tpl['admin'], $vars ) . "\n\n" . admin_url( 'admin.php?page=mo-booking-bookings&booking=' . (int) $booking['id'] );
			$subject = '[Reservas] ' . $subject;
		} else {
			$html = self::renderHtml( $tpl['body'], $vars );
			$body = self::htmlToText( $html );
		}

		return self::deliver( $row, $subject, $body, (bool) $booking['is_test'], $audience, $set, $payload, $html );
	}

	/** Envía (o simula) un correo de la cola y actualiza su estado. */
	private static function deliver( array $row, string $subject, string $body, bool $isTest, string $audience, callable $set, array $payload = array(), string $html = '' ): bool {
		$id  = (int) $row['id'];
		$now = Plugin::clock()->now();
		// El registro conserva el texto exacto que se usó, aunque la plantilla cambie después.
		$keep = Db::json( array_merge( $payload, array( 'subject' => $subject, 'text' => $body, 'html' => $html, 'preview' => mb_substr( $body, 0, 400 ) ) ) );
		if ( $isTest || 'live' !== Settings::get( 'email_mode' ) ) {
			$set( array( 'status' => 'simulated', 'payload' => $keep ) );
			return true;
		}
		$error   = '';
		$capture = static function ( $wpError ) use ( &$error ) {
			$error = is_wp_error( $wpError ) ? $wpError->get_error_message() : 'Error de correo.';
		};
		add_action( 'wp_mail_failed', $capture );
		$headers = array( '' !== $html ? 'Content-Type: text/html; charset=UTF-8' : 'Content-Type: text/plain; charset=UTF-8' );
		// Con formato: el mismo mensaje en texto simple para los programas de correo que no lo muestran.
		$alt = static function ( $mailer ) use ( $body ) {
			$mailer->AltBody = $body;
		};
		if ( '' !== $html ) {
			add_action( 'phpmailer_init', $alt );
		}
		$from    = (string) Settings::get( 'email_from' );
		if ( is_email( $from ) ) {
			$fromName  = (string) Settings::get( 'email_from_name' ) ?: (string) Settings::get( 'business_name' );
			$headers[] = 'From: ' . str_replace( array( "\r", "\n", '<', '>' ), '', $fromName ) . ' <' . $from . '>';
		}
		if ( 'client' === $audience && is_email( (string) Settings::get( 'admin_email' ) ) ) {
			$headers[] = 'Reply-To: ' . Settings::get( 'admin_email' );
		}
		$ok = wp_mail( $row['recipient'], $subject, '' !== $html ? self::emailLayout( $html ) : $body, $headers );
		remove_action( 'wp_mail_failed', $capture );
		remove_action( 'phpmailer_init', $alt );

		if ( $ok ) {
			$set( array( 'status' => 'accepted', 'last_error' => '', 'payload' => $keep ) );
			return true;
		}
		$attempts = (int) $row['attempts'];
		if ( $attempts >= self::MAX_ATTEMPTS ) {
			$set( array( 'status' => 'failed', 'last_error' => mb_substr( $error ?: 'El transporte de correo rechazó el mensaje.', 0, 250 ) ) );
			Logger::error( 'Notificación fallida', array( 'notification' => $id ) );
		} else {
			// Espera progresiva: 2, 4, 8, 16 minutos.
			$set( array( 'status' => 'pending', 'send_after' => Db::toSql( $now->modify( sprintf( '+%d minutes', 2 ** $attempts ) ) ), 'last_error' => mb_substr( $error ?: 'Reintento programado.', 0, 250 ) ) );
		}
		return false;
	}

	/** Reintento manual de un mensaje fallido. */
	public static function retry( int $id ): void {
		$wpdb = Db::wpdb();
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table( 'notifications' ) . " SET status = 'pending', attempts = 0, send_after = %s, locked_until = NULL WHERE id = %d AND status = 'failed'", Db::toSql( Plugin::clock()->now() ), $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		self::processOne( $id );
	}

	// ----- WhatsApp manual -----

	public static function whatsappText( array $booking, string $event ): string {
		$tpl  = self::templates()[ $event ] ?? self::customFor( $event ) ?? self::templates()['booking_created'];
		$vars = self::varsFor( $booking );
		if ( '' !== trim( (string) ( $tpl['whatsapp'] ?? '' ) ) ) {
			return self::render( (string) $tpl['whatsapp'], $vars );
		}
		// Sin texto propio: el del correo, con negritas y cursivas al estilo de WhatsApp.
		return self::isHtml( (string) $tpl['body'] ) ? self::htmlToText( self::renderHtml( (string) $tpl['body'], $vars ), true ) : self::render( (string) $tpl['body'], $vars );
	}

	/** Mensajes que se pueden abrir por WhatsApp desde la reserva: avisos del sistema y plantillas propias de WhatsApp. */
	public static function whatsappEventAllowed( string $event ): bool {
		if ( isset( self::events()[ $event ] ) ) {
			return true;
		}
		$custom = self::customTemplates()[ substr( $event, 7 ) ] ?? null;
		return 0 === strpos( $event, 'custom_' ) && $custom && 'whatsapp' === $custom['channel'] && $custom['enabled'];
	}

	/** Texto guardado de un mensaje del registro (o null si se envió antes de guardarse). */
	public static function storedText( array $row ): ?array {
		$p = (array) Db::decode( $row['payload'] ?? '' );
		if ( isset( $p['text'] ) ) {
			return array( 'subject' => (string) ( $p['subject'] ?? '' ), 'text' => (string) $p['text'] );
		}
		return isset( $p['preview'] ) ? array( 'subject' => (string) ( $p['subject'] ?? '' ), 'text' => (string) $p['preview'] ) : null;
	}

	public static function whatsappUrl( array $booking, string $event ): ?string {
		$digits = Contact::whatsappDigits( $booking['contact']['phone_e164'] ?? null );
		if ( ! $digits ) {
			return null;
		}
		return Contact::whatsappLink( $digits, self::whatsappText( $booking, $event ), 'web' === Settings::get( 'whatsapp_open' ) );
	}

	public static function logManualWhatsapp( array $booking, string $event, string $status ): void {
		$wpdb = Db::wpdb();
		$now  = Plugin::clock()->now();
		$wpdb->insert(
			Schema::table( 'notifications' ),
			array(
				'event'      => $event,
				'channel'    => 'whatsapp_manual',
				'booking_id' => $booking['id'],
				'recipient'  => (string) ( $booking['contact']['phone_e164'] ?? '' ),
				'status'     => $status,
				'attempts'   => 0,
				'send_after' => Db::toSql( $now ),
				'dedupe_key' => 'wam:' . $booking['id'] . ':' . $event . ':' . bin2hex( random_bytes( 6 ) ),
				'payload'    => Db::json( array( 'audience' => 'client', 'text' => self::whatsappText( $booking, $event ) ) ),
				'created_at' => Db::toSql( $now ),
				'updated_at' => Db::toSql( $now ),
			)
		);
	}

	public static function markManualSent( int $id ): void {
		$wpdb = Db::wpdb();
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table( 'notifications' ) . " SET status = 'sent_manual', updated_at = %s WHERE id = %d AND channel = 'whatsapp_manual'", Db::toSql( Plugin::clock()->now() ), $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function forBooking( int $bookingId ): array {
		$wpdb = Db::wpdb();
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'notifications' ) . ' WHERE booking_id = %d ORDER BY id', $bookingId ), ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function recent( int $limit = 100, ?string $status = null, int $offset = 0 ): array {
		$wpdb  = Db::wpdb();
		$table = Schema::table( 'notifications' );
		if ( $status ) {
			return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE status = %s ORDER BY id DESC LIMIT %d OFFSET %d", $status, $limit, max( 0, $offset ) ), ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table ORDER BY id DESC LIMIT %d OFFSET %d", $limit, max( 0, $offset ) ), ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** beta.6: total de mensajes del registro (para paginar). */
	public static function countRecent( ?string $status = null ): int {
		$wpdb  = Db::wpdb();
		$table = Schema::table( 'notifications' );
		return $status
			? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE status = %s", $status ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			: (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
