<?php
namespace MOBookingStudio\Admin\Pages;

use MOBookingStudio\Admin\Ui;
use MOBookingStudio\Application\Clients;
use MOBookingStudio\Application\TestData;
use MOBookingStudio\Domain\DomainException;
use MOBookingStudio\Domain\TimeZoneResolver;
use MOBookingStudio\Infrastructure\PrivateStorage;
use MOBookingStudio\Infrastructure\Settings;

final class SettingsPage {
	public static function render(): void {
		$s = array_merge( Settings::all(), Ui::oldInput() );
		Ui::header( 'Ajustes' );
		echo Ui::formOpen( 'save_settings' ); // phpcs:ignore WordPress.Security.EscapeOutput

		$tz    = Settings::timezoneInfo();
		$zones = array();
		foreach ( array( 'America/Ciudad_Juarez', 'America/Chihuahua', 'America/Mexico_City', 'America/Tijuana', 'America/Hermosillo', 'America/Mazatlan', 'America/Monterrey', 'America/Cancun', 'America/Denver', 'America/Los_Angeles', 'America/Chicago' ) as $z ) {
			$zones[ $z ] = $z;
		}
		$body  = Ui::row( 'Nombre del negocio', Ui::input( 'business_name', $s['business_name'], 'text', array( 'class' => 'regular-text' ) ), 'Se usa cuando un servicio no tiene nombre comercial propio.', 'business_name' );
		$body .= Ui::row( 'Zona horaria del negocio', Ui::select( 'timezone', $zones, $s['timezone'] ), $tz['fallback'] ? sprintf( 'Este servidor no conoce %s; se usa %s, con las mismas reglas de horario.', $tz['requested'], $tz['effective'] ) : 'Independiente de la zona general de WordPress (que no se modifica).', 'timezone' );
		$body .= Ui::row( 'Correo de la administradora', Ui::input( 'admin_email', $s['admin_email'], 'email', array( 'class' => 'regular-text' ) ), 'Recibe avisos de nuevas reservas y comprobantes.', 'admin_email' );
		echo Ui::card( 'Negocio', $body ); // phpcs:ignore WordPress.Security.EscapeOutput

		$body  = Ui::row( 'Datos para transferencia', Ui::textarea( 'bank_instructions', $s['bank_instructions'], 5, array( 'placeholder' => "Banco:\nTitular:\nCLABE:\nTarjeta:" ) ), 'Solo se muestran a la persona en su confirmación y en su portal mientras tenga pagos pendientes.', 'bank_instructions' );
		$body .= Ui::row( 'Instrucción de referencia', Ui::input( 'payment_reference', $s['payment_reference'], 'text', array( 'class' => 'large-text' ) ), '', 'payment_reference' );
		$body .= Ui::row( 'Horas para pagar el anticipo', Ui::input( 'hold_hours', $s['hold_hours'], 'number', array( 'min' => '0.25', 'step' => '0.25' ) ), 'Valor general; cada servicio puede tener el suyo.', 'hold_hours' );
		$body .= Ui::row( 'Horas para corregir un comprobante', Ui::input( 'correction_hours', $s['correction_hours'], 'number', array( 'min' => '0.25', 'step' => '0.25' ) ), 'Solo se permite una corrección por reserva. Decisión: 2 horas.', 'correction_hours' );
		$body .= Ui::row( 'Motivos de corrección o rechazo', Ui::textarea( 'review_reasons', $s['review_reasons'], 6 ), 'Uno por línea. La persona ve el motivo que elijas; tus notas internas nunca se le envían.', 'review_reasons' );
		$body .= Ui::row( 'Tamaño máximo de comprobante (MB)', Ui::input( 'proof_max_mb', $s['proof_max_mb'], 'number', array( 'min' => 1, 'max' => 20 ) ), 'Ten en cuenta el límite de subida de tu hosting.', 'proof_max_mb' );
		$body .= Ui::row( 'Máximo de comprobantes por reserva', Ui::input( 'proof_max_files', $s['proof_max_files'], 'number', array( 'min' => 1, 'max' => 20 ) ), '', 'proof_max_files' );
		$body .= Ui::row( 'Sesiones para cliente frecuente', Ui::input( 'frequent_threshold', Clients::frequentThreshold(), 'number', array( 'min' => 1 ) ), '', 'frequent_threshold' );
		echo Ui::card( 'Pagos y reservas', $body ); // phpcs:ignore WordPress.Security.EscapeOutput

		$body  = Ui::row( 'Correos', Ui::select( 'email_mode', array( 'simulate' => 'Simulación (no se envía nada)', 'live' => 'Envío real' ), $s['email_mode'] ), 'Activa el envío real solo cuando el correo autenticado (SMTP o Gmail) esté configurado y probado. Las reservas de prueba nunca envían.', 'email_mode' );
		$body .= Ui::row( 'Remitente (nombre)', Ui::input( 'email_from_name', $s['email_from_name'], 'text', array( 'class' => 'regular-text' ) ), '', 'email_from_name' );
		$body .= Ui::row( 'Remitente (correo)', Ui::input( 'email_from', $s['email_from'], 'email', array( 'class' => 'regular-text' ) ), 'Debe estar autorizado en tu transporte de correo. Vacío = el del sitio.', 'email_from' );
		$body .= Ui::row( 'Recordatorio (horas antes)', Ui::input( 'reminder_hours', $s['reminder_hours'], 'number', array( 'min' => 0 ) ), '0 = sin recordatorio. Propuesta: 48.', 'reminder_hours' );
		$body .= Ui::row( 'WhatsApp', Ui::select( 'whatsapp_mode', array( 'off' => 'Desactivado', 'manual' => 'Manual (abrir chat con el texto listo)', 'automatic' => 'Automático (pendiente de conectar proveedor oficial)' ), $s['whatsapp_mode'] ), 'El modo automático aún no envía: requiere un proveedor oficial de WhatsApp Business, plantillas aprobadas y credenciales.', 'whatsapp_mode' );
		$body .= Ui::row( 'Número de WhatsApp del negocio', Ui::input( 'whatsapp_number', $s['whatsapp_number'], 'tel' ), 'Tu número (no el de la clienta). Lo usan los botones de WhatsApp del enlace privado: enviar comprobante, preguntas y cambios.', 'whatsapp_number' );
		$body .= Ui::row( 'Botón para enviar el comprobante por WhatsApp', Ui::input( 'whatsapp_proof_button', $s['whatsapp_proof_button'], 'text', array( 'class' => 'large-text' ) ), 'Aparece en el enlace privado y al terminar la reserva cuando no se pueden subir archivos. Abre WhatsApp con el número del negocio.', 'whatsapp_proof_button' );
		$body .= Ui::row( 'Mensaje predeterminado del comprobante', Ui::textarea( 'whatsapp_proof_message', $s['whatsapp_proof_message'], 2 ), 'Texto que se escribe solo en WhatsApp. Códigos: {nombre} = nombre de la persona, {referencia} = número de reserva.', 'whatsapp_proof_message' );
		$body .= Ui::row( 'Texto junto al comprobante', Ui::textarea( 'portal_proof_text', $s['portal_proof_text'], 2 ), 'Se muestra en el enlace privado sobre la carga del comprobante. {whatsapp} se reemplaza por el número de arriba.', 'portal_proof_text' );
		$body .= Ui::row( 'Contacto público para Google Calendar', Ui::input( 'public_contact', $s['public_contact'], 'text', array( 'class' => 'regular-text', 'placeholder' => 'WhatsApp 656 000 0000 · hola@tuestudio.com' ) ), 'Aparece en el evento que recibe la clienta. No pongas datos privados.', 'public_contact' );
		$body .= Ui::row( 'Enlace privado vence (días después de la cita)', Ui::input( 'token_days_after', $s['token_days_after'], 'number', array( 'min' => 1 ) ), 'Propuesta: 90 días.', 'token_days_after' );
		echo Ui::card( 'Comunicaciones', $body ); // phpcs:ignore WordPress.Security.EscapeOutput

		// 6.5: las condiciones viven ahora en Contratos; el texto anterior se conserva sin mostrarse.
		$body  = Ui::row( 'Condiciones y contrato', '<a href="' . esc_url( Ui::url( 'mo-booking-contracts' ) ) . '">Editar en Contratos</a>' . Ui::input( 'terms_text', (string) $s['terms_text'], 'hidden' ), 'Las condiciones generales anteriores se copiaron a la versión 1 del contrato de cada servicio al actualizar.' );
		$body .= Ui::row( 'URL del aviso de privacidad', Ui::input( 'privacy_url', $s['privacy_url'], 'url', array( 'class' => 'regular-text' ) ), '', 'privacy_url' );
		$pages = array( 0 => '— Sin seleccionar —' );
		foreach ( get_pages( array( 'post_status' => array( 'publish', 'draft', 'private' ) ) ) as $page ) {
			$pages[ $page->ID ] = $page->post_title . ( 'publish' !== $page->post_status ? ' (' . $page->post_status . ')' : '' );
		}
		$body .= Ui::row( 'Página de reservas', Ui::select( 'booking_page_id', $pages, (string) $s['booking_page_id'] ), 'Página con el shortcode o el widget de Elementor.', 'booking_page_id' );
		$body .= Ui::row( 'Página "Mi reserva" (portal)', Ui::select( 'portal_page_id', $pages, (string) $s['portal_page_id'] ), 'Debe contener [mo_booking_portal]. Sin página se usa ' . home_url( '/?mo_portal=1' ) . '.', 'portal_page_id' );
		// SEG-02: la ruta exacta se muestra antes de activar el borrado y se confirma por separado.
		$purgeDir  = PrivateStorage::canonical( PrivateStorage::dir() );
		$confirmed = (bool) $s['delete_on_uninstall'] && $s['delete_confirm_path'] === $purgeDir;
		$body     .= Ui::row(
			'Al desinstalar',
			Ui::checkbox( 'delete_on_uninstall', $confirmed, 'Borrar todos los datos del plugin al eliminarlo (reservas, clientes, pagos, contratos y comprobantes). No se puede deshacer.' ) .
			'<p>Comprobantes que se borrarían (solo los archivos registrados por el plugin) en: <code>' . esc_html( $purgeDir ) . '</code></p>' .
			'<p><label>Confirmación: escribe <strong>BORRAR</strong> ' . Ui::input( 'delete_confirm', '', 'text', array( 'autocomplete' => 'off', 'size' => 10 ) ) . '</label></p>',
			$confirmed ? 'Activado y confirmado para esta ruta exacta. Si la ruta cambia, la confirmación deja de valer.' : 'Desactivado: al eliminar el plugin se conservan todos los datos.'
		);
		echo Ui::card( 'Páginas, condiciones y datos', $body ); // phpcs:ignore WordPress.Security.EscapeOutput

		echo '<p class="mo-sticky-actions">' . Ui::button( 'Guardar ajustes' ) . '</p></form>'; // phpcs:ignore WordPress.Security.EscapeOutput

		echo '<div class="mo-grid mo-grid--2">';
		$labels = array(
			''       => 'Sin verificar',
			'ok'     => 'Verificado: privado',
			'public' => 'PÚBLICO: los archivos se pueden descargar por URL',
			'error'  => 'No se pudo verificar',
		);
		$where  = array( 'outside' => 'fuera de WordPress y del document root', 'uploads' => 'dentro de uploads: requiere comprobar que el servidor niega el acceso por URL', 'site' => 'dentro del sitio: no se puede verificar; usa una carpeta fuera de public_html' );
		$body   = '<p>Carpeta: <code>' . esc_html( PrivateStorage::canonical( PrivateStorage::dir() ) ) . '</code> (' . esc_html( $where[ PrivateStorage::location() ] ) . ')</p>';
		$problem = PrivateStorage::pathProblem( PrivateStorage::dir() );
		if ( '' !== $problem ) {
			$body .= '<div class="notice notice-error inline"><p>' . esc_html( $problem ) . '</p></div>';
		}
		if ( Settings::get( 'uploads_reason' ) ) {
			$body .= '<p class="description">Resultado de la última verificación: ' . esc_html( (string) Settings::get( 'uploads_reason' ) ) . '</p>';
		}
		if ( 'ok' === Settings::get( 'uploads_verified' ) && ! PrivateStorage::uploadsEnabled() ) {
			$body .= '<p><strong>La carpeta cambió o se verificó con una versión anterior: vuelve a verificar.</strong></p>';
		}
		$body  .= '<p>Estado: <strong>' . esc_html( $labels[ (string) Settings::get( 'uploads_verified' ) ] ?? '' ) . '</strong>' . ( Settings::get( 'uploads_checked_at' ) ? ' · ' . esc_html( (string) Settings::get( 'uploads_checked_at' ) ) : '' ) . '</p>';
		$body  .= '<p class="description">La carga de comprobantes solo se habilita si el servidor no deja descargar los archivos con una URL directa. Lo más seguro es definir <code>MO_BOOKING_PRIVATE_DIR</code> en wp-config.php con una carpeta fuera de public_html.</p>';
		$body  .= Ui::formOpen( 'verify_storage' ) . Ui::button( 'Verificar almacenamiento privado', 'button-secondary' ) . '</form>';
		echo Ui::card( 'Almacenamiento privado de comprobantes', $body ); // phpcs:ignore WordPress.Security.EscapeOutput

		$body  = '<p>Crea dos páginas en borrador: "Reservas" (con el formulario de un servicio) y "Mi reserva" (portal).</p>' . Ui::formOpen( 'create_pages' ) . Ui::button( 'Crear páginas', 'button-secondary' ) . '</form>';
		echo Ui::card( 'Páginas', $body ); // phpcs:ignore WordPress.Security.EscapeOutput

		$body  = '<p>Crea un servicio, una agenda, tarifas, políticas y seis clientes ficticios, todo marcado como <strong>PRUEBA</strong> y en una agenda separada para no ocupar tus horarios reales. Las reservas de prueba nunca envían mensajes. Puedes borrarlo después sin tocar tus datos reales.</p>';
		$body .= '<p>Los datos de prueba reproducen las minis navideñas 2026 de la especificación.</p>';
		$body .= Ui::formOpen( 'load_test_data', array(), 'mo-inline-form' ) . Ui::button( TestData::exists() ? 'Volver a crear datos de prueba' : 'Crear datos de prueba', 'button-secondary' ) . '</form> ';
		if ( TestData::exists() ) {
			$body .= Ui::formOpen( 'clear_test_data', array(), 'mo-inline-form' ) . Ui::button( 'Borrar datos de prueba', 'button-link-delete', '¿Borrar todos los datos marcados como PRUEBA?' ) . '</form>';
		}
		echo Ui::card( 'Datos de prueba', $body ); // phpcs:ignore WordPress.Security.EscapeOutput

		$cron  = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'WP-Cron interno está desactivado: configura una tarea del servidor.' : 'Se usa WP-Cron, que depende de las visitas al sitio.';
		$body  = '<p>' . esc_html( $cron ) . ' Para mayor puntualidad, programa en el hosting cada 1 a 5 minutos:</p><p><code>wget -q -O - ' . esc_html( site_url( 'wp-cron.php?doing_wp_cron' ) ) . ' &gt;/dev/null 2&gt;&amp;1</code></p>';
		$body .= '<p class="description">Aunque la tarea se atrase, las retenciones vencidas no bloquean horarios: se concilian en cada consulta y reserva.</p>';
		$body .= '<p>Versión del plugin: ' . esc_html( MO_BOOKING_VERSION ) . ' · PHP ' . esc_html( PHP_VERSION ) . ' · zona efectiva ' . esc_html( $tz['effective'] ) . '</p>';
		echo Ui::card( 'Tareas programadas y sistema', $body ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</div>';
		Ui::footer();
	}

	public static function save( array $p ): void {
		$wantsDelete = ! empty( $p['delete_on_uninstall'] );
		$current     = Settings::all();
		$keepCurrent = $wantsDelete && $current['delete_on_uninstall'] && $current['delete_confirm_path'] === PrivateStorage::canonical( PrivateStorage::dir() );
		$deleteOk    = $wantsDelete && ( $keepCurrent || 'BORRAR' === trim( (string) ( $p['delete_confirm'] ?? '' ) ) );
		if ( $wantsDelete && ! $deleteOk ) {
			throw new DomainException( 'confirm_required', 'Para activar el borrado al desinstalar escribe BORRAR en la confirmación. No se guardaron los ajustes.' );
		}
		$tz = sanitize_text_field( (string) ( $p['timezone'] ?? 'America/Ciudad_Juarez' ) );
		TimeZoneResolver::resolve( $tz );
		$num = static function ( $key, float $min, float $max ) use ( $p ) {
			$v = (float) str_replace( ',', '.', (string) ( $p[ $key ] ?? 0 ) );
			if ( $v < $min || $v > $max ) {
				throw new DomainException( 'invalid_setting', sprintf( 'El valor de "%s" debe estar entre %s y %s.', $key, $min, $max ) );
			}
			return $v;
		};
		$adminEmail = sanitize_email( (string) ( $p['admin_email'] ?? '' ) );
		$from       = sanitize_email( (string) ( $p['email_from'] ?? '' ) );
		Settings::update(
			array(
				'business_name'       => sanitize_text_field( (string) ( $p['business_name'] ?? '' ) ),
				'timezone'            => $tz,
				'admin_email'         => $adminEmail,
				'bank_instructions'   => sanitize_textarea_field( (string) ( $p['bank_instructions'] ?? '' ) ),
				'payment_reference'   => sanitize_text_field( (string) ( $p['payment_reference'] ?? '' ) ),
				'hold_hours'          => $num( 'hold_hours', 0.25, 720 ),
				'correction_hours'    => $num( 'correction_hours', 0.25, 720 ),
				'review_reasons'      => sanitize_textarea_field( (string) ( $p['review_reasons'] ?? '' ) ) ?: Settings::defaults()['review_reasons'],
				'proof_max_mb'        => $num( 'proof_max_mb', 1, 20 ),
				'proof_max_files'     => (int) $num( 'proof_max_files', 1, 20 ),
				'email_mode'          => 'live' === ( $p['email_mode'] ?? '' ) ? 'live' : 'simulate',
				'email_from_name'     => sanitize_text_field( (string) ( $p['email_from_name'] ?? '' ) ),
				'email_from'          => $from,
				'reminder_hours'      => $num( 'reminder_hours', 0, 720 ),
				'whatsapp_mode'       => in_array( $p['whatsapp_mode'] ?? '', array( 'off', 'manual', 'automatic' ), true ) ? $p['whatsapp_mode'] : 'manual',
				'whatsapp_number'     => sanitize_text_field( (string) ( $p['whatsapp_number'] ?? '' ) ),
				'portal_proof_text'   => sanitize_textarea_field( (string) ( $p['portal_proof_text'] ?? '' ) ),
				'whatsapp_proof_button'  => sanitize_text_field( (string) ( $p['whatsapp_proof_button'] ?? '' ) ),
				'whatsapp_proof_message' => sanitize_textarea_field( (string) ( $p['whatsapp_proof_message'] ?? '' ) ),
				'public_contact'      => sanitize_text_field( (string) ( $p['public_contact'] ?? '' ) ),
				'token_days_after'    => (int) $num( 'token_days_after', 1, 3650 ),
				'terms_text'          => sanitize_textarea_field( (string) ( $p['terms_text'] ?? '' ) ),
				'privacy_url'         => esc_url_raw( (string) ( $p['privacy_url'] ?? '' ) ),
				'booking_page_id'     => (int) ( $p['booking_page_id'] ?? 0 ),
				'portal_page_id'      => (int) ( $p['portal_page_id'] ?? 0 ),
				'delete_on_uninstall' => $deleteOk,
				'delete_confirm_path' => $deleteOk ? PrivateStorage::canonical( PrivateStorage::dir() ) : '',
			)
		);
		update_option( Clients::FREQUENT_THRESHOLD_OPTION, max( 1, (int) ( $p['frequent_threshold'] ?? 3 ) ), false );
	}

	public static function createPages(): void {
		$services = \MOBookingStudio\Application\Services::all( 'published' );
		$slug     = $services ? $services[0]['slug'] : 'tu-servicio';
		$booking  = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Reservas',
				'post_name'    => 'reservas',
				'post_content' => '[mo_booking service="' . $slug . '"]',
			)
		);
		$portal   = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Mi reserva',
				'post_name'    => 'mi-reserva',
				'post_content' => '[mo_booking_portal]',
			)
		);
		Settings::update(
			array(
				'booking_page_id' => (int) $booking,
				'portal_page_id'  => (int) $portal,
			)
		);
	}
}
