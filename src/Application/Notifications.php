<?php
namespace MOBookingStudio\Application;

use MOBookingStudio\Domain\BookingStatus;
use MOBookingStudio\Domain\Contact;
use MOBookingStudio\Domain\Money;
use MOBookingStudio\Infrastructure\Db;
use MOBookingStudio\Infrastructure\Logger;
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
		'pending'        => 'Pendiente',
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
			'nombre'             => 'Nombre de la persona',
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
			'reminder'              => array( 'label' => 'Recordatorio de la cita', 'to' => array( 'client' ) ),
		);
	}

	public static function defaultTemplates(): array {
		$sign = "\n\n{{negocio}}";
		return array(
			'booking_created'      => array(
				'enabled' => true,
				'subject' => 'Recibimos tu solicitud {{referencia}}',
				'body'    => "Hola {{nombre}}:\n\nRecibimos tu solicitud para {{servicio}} el {{fecha}} a las {{hora}} ({{zona}}).\n\nTotal: {{total}}\nAnticipo para apartar: {{anticipo}}\nSaldo: {{saldo}}\n\nTu horario queda apartado hasta {{vencimiento}}. Para confirmarlo, transfiere el anticipo y sube tu comprobante desde tu enlace privado:\n{{enlace}}\n\n{{instrucciones_pago}}" . $sign,
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
				'body'    => "Hola {{nombre}}:\n\nTu cita para {{servicio}} está confirmada:\n{{fecha}}, {{hora}} ({{zona}})\n{{ubicacion}}\n\nSaldo pendiente: {{saldo}}\n\n{{instrucciones}}\n\nConsulta tu reserva:\n{{enlace}}" . $sign,
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
				'body'    => "Hola {{nombre}}:\n\nTu cita para {{servicio}} ahora es el {{fecha}} a las {{hora}} ({{zona}}).\n\nSaldo pendiente: {{saldo}}\n\nConsulta el detalle:\n{{enlace}}" . $sign,
				'admin'   => '',
			),
			'reminder'             => array(
				'enabled' => true,
				'subject' => 'Recordatorio: tu cita del {{fecha}}',
				'body'    => "Hola {{nombre}}:\n\nTe recordamos tu cita para {{servicio}} el {{fecha}} a las {{hora}} ({{zona}}).\n{{ubicacion}}\n\n{{instrucciones}}\n\nSaldo pendiente: {{saldo}}\n{{enlace}}" . $sign,
				'admin'   => '',
			),
		);
	}

	public static function templates(): array {
		$stored = get_option( self::TEMPLATES_OPTION, array() );
		$out    = self::defaultTemplates();
		foreach ( (array) $stored as $event => $tpl ) {
			if ( isset( $out[ $event ] ) && is_array( $tpl ) ) {
				$out[ $event ] = array_merge( $out[ $event ], array_intersect_key( $tpl, $out[ $event ] ) );
			}
		}
		return $out;
	}

	public static function saveTemplates( array $templates ): void {
		$clean = array();
		foreach ( self::defaultTemplates() as $event => $default ) {
			if ( ! isset( $templates[ $event ] ) ) {
				continue;
			}
			$t               = $templates[ $event ];
			$clean[ $event ] = array(
				'enabled' => ! empty( $t['enabled'] ),
				'subject' => sanitize_text_field( (string) ( $t['subject'] ?? $default['subject'] ) ),
				'body'    => sanitize_textarea_field( (string) ( $t['body'] ?? $default['body'] ) ),
				'admin'   => sanitize_textarea_field( (string) ( $t['admin'] ?? $default['admin'] ) ),
			);
		}
		update_option( self::TEMPLATES_OPTION, $clean, false );
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
			'nombre'             => $booking['contact']['name'] ?? '',
			'negocio'            => $brand,
			'servicio'           => $service ? $service['title'] : ( $booking['quote']['service_title'] ?? '' ),
			'referencia'         => $booking['public_ref'],
			'fecha'              => Catalog::dateText( $local ),
			'hora'               => $local->format( 'H:i' ),
			'zona'               => $booking['timezone'],
			'ubicacion'          => $service ? $service['location'] : '',
			'instrucciones'      => $service ? $service['instructions'] : '',
			'total'              => Money::format( $booking['total_cents'] ),
			'anticipo'           => Money::format( $booking['deposit_cents'] ),
			'saldo'              => Money::format( $summary['balance_cents'] ),
			'vencimiento'        => $booking['expires'] ? Catalog::dateTimeText( $booking['expires'] ) . ' (' . $booking['timezone'] . ')' : '',
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
		if ( ! $tpl || empty( $tpl['enabled'] ) ) {
			return;
		}
		$now = Plugin::clock()->now();
		$ids = array();
		foreach ( $events[ $event ]['to'] as $to ) {
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
		if ( 'automatic' === Settings::get( 'whatsapp_mode' ) && in_array( 'client', $events[ $event ]['to'], true ) && ! empty( $booking['consent']['whatsapp'] ) ) {
			$phone = $booking['contact']['phone_e164'] ?? '';
			if ( $phone ) {
				self::enqueue( $event, 'whatsapp', $booking['id'], $phone, 'client', $now, null, $event . ':' . $booking['id'] . ':' . $booking['version'] . ':wa', $extra );
			}
		}
		if ( 'booking_confirmed' === $event ) {
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
		$tpl      = self::templates()[ $row['event'] ] ?? null;
		if ( ! $tpl ) {
			$set( array( 'status' => 'skipped', 'last_error' => 'Plantilla inexistente.' ) );
			return false;
		}
		$vars    = self::varsFor( $booking, (array) ( $payload['extra'] ?? array() ) );
		$subject = self::render( $tpl['subject'], $vars );
		$body    = self::render( 'admin' === $audience ? $tpl['admin'] : $tpl['body'], $vars );
		if ( 'admin' === $audience ) {
			$body .= "\n\n" . admin_url( 'admin.php?page=mo-booking-bookings&booking=' . (int) $booking['id'] );
			$subject = '[Reservas] ' . $subject;
		}

		return self::deliver( $row, $subject, $body, (bool) $booking['is_test'], $audience, $set, $payload );
	}

	/** Envía (o simula) un correo de la cola y actualiza su estado. */
	private static function deliver( array $row, string $subject, string $body, bool $isTest, string $audience, callable $set, array $payload = array() ): bool {
		$id  = (int) $row['id'];
		$now = Plugin::clock()->now();
		if ( $isTest || 'live' !== Settings::get( 'email_mode' ) ) {
			$set( array( 'status' => 'simulated', 'payload' => Db::json( array_merge( $payload, array( 'subject' => $subject, 'preview' => mb_substr( $body, 0, 400 ) ) ) ) ) );
			return true;
		}
		$error   = '';
		$capture = static function ( $wpError ) use ( &$error ) {
			$error = is_wp_error( $wpError ) ? $wpError->get_error_message() : 'Error de correo.';
		};
		add_action( 'wp_mail_failed', $capture );
		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
		$from    = (string) Settings::get( 'email_from' );
		if ( is_email( $from ) ) {
			$fromName  = (string) Settings::get( 'email_from_name' ) ?: (string) Settings::get( 'business_name' );
			$headers[] = 'From: ' . str_replace( array( "\r", "\n", '<', '>' ), '', $fromName ) . ' <' . $from . '>';
		}
		if ( 'client' === $audience && is_email( (string) Settings::get( 'admin_email' ) ) ) {
			$headers[] = 'Reply-To: ' . Settings::get( 'admin_email' );
		}
		$ok = wp_mail( $row['recipient'], $subject, $body, $headers );
		remove_action( 'wp_mail_failed', $capture );

		if ( $ok ) {
			$set( array( 'status' => 'accepted', 'last_error' => '' ) );
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
		$tpl = self::templates()[ $event ] ?? self::templates()['booking_created'];
		return self::render( $tpl['body'], self::varsFor( $booking ) );
	}

	public static function whatsappUrl( array $booking, string $event ): ?string {
		$digits = Contact::whatsappDigits( $booking['contact']['phone_e164'] ?? null );
		if ( ! $digits ) {
			return null;
		}
		return 'https://wa.me/' . $digits . '?text=' . rawurlencode( self::whatsappText( $booking, $event ) );
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

	public static function recent( int $limit = 100, ?string $status = null ): array {
		$wpdb  = Db::wpdb();
		$table = Schema::table( 'notifications' );
		if ( $status ) {
			return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE status = %s ORDER BY id DESC LIMIT %d", $status, $limit ), ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
