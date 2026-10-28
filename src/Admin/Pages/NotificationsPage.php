<?php
namespace MOBookingStudio\Admin\Pages;

use MOBookingStudio\Admin\Ui;
use MOBookingStudio\Application\Notifications;
use MOBookingStudio\Infrastructure\Settings;

/**
 * Notificaciones: avisos del sistema, plantillas propias (correo o WhatsApp), guía de
 * códigos junto a cada texto, aviso de códigos desconocidos, vista previa con datos de
 * ejemplo y registro con el texto exacto de cada mensaje.
 */
final class NotificationsPage {
	public static function render(): void {
		$g = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification
		Ui::header( 'Notificaciones', ' <a class="page-title-action" href="' . esc_url( Ui::url( 'mo-booking-notifications', array( 'template' => 'new' ) ) . '#plantilla' ) . '">Nueva plantilla</a>' );
		$mode = 'live' === Settings::get( 'email_mode' ) ? 'Envío real con wp_mail (el transporte del sitio, por ejemplo un plugin SMTP).' : 'Simulación: los mensajes se registran pero NO se envían.';
		echo '<div class="mo-help"><p><strong>Correo:</strong> ' . esc_html( $mode ) . ' Cámbialo en Ajustes. "Aceptado por el transporte" significa que el servidor aceptó el mensaje, no que llegó a la bandeja. <strong>WhatsApp:</strong> ' . esc_html( array( 'off' => 'desactivado', 'manual' => 'manual desde cada reserva', 'automatic' => 'automático pendiente de conectar con un proveedor oficial (los mensajes quedan "sin conexión")' )[ Settings::get( 'whatsapp_mode' ) ] ?? '' ) . '.</p><p>Escribe los códigos con <strong>doble llave</strong>, por ejemplo <code>{{nombre}}</code>. Haz clic en un código de la guía para insertarlo donde está el cursor.</p></div>';
		echo '<script type="application/json" id="mo-sample-vars">' . wp_json_encode( Notifications::sampleVars() ) . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput

		// Plantillas propias.
		$custom = Notifications::customTemplates();
		$events = Notifications::events();
		$rows   = '';
		foreach ( $custom as $id => $t ) {
			$rows .= '<tr><td data-label="Plantilla"><a href="' . esc_url( Ui::url( 'mo-booking-notifications', array( 'template' => $id ) ) . '#plantilla' ) . '"><strong>' . esc_html( $t['name'] ) . '</strong></a>' . ( $t['enabled'] ? '' : ' <span class="mo-pill">Desactivada</span>' ) . '</td>' .
				'<td data-label="Canal">' . esc_html( 'email' === $t['channel'] ? 'Correo' : 'WhatsApp' ) . '</td>' .
				'<td data-label="Cuándo">' . esc_html( 'manual' === $t['trigger'] ? 'A mano, desde la reserva' : 'Con: ' . ( $events[ $t['trigger'] ]['label'] ?? $t['trigger'] ) ) . '</td>' .
				'<td data-label="Acciones"><a href="' . esc_url( Ui::url( 'mo-booking-notifications', array( 'template' => $id ) ) . '#plantilla' ) . '">Editar</a> · ' .
				Ui::formOpen( 'delete_template', array( 'template_id' => $id ), 'mo-inline-form' ) . Ui::button( 'Quitar', 'button-link-delete', '¿Quitar esta plantilla? Los mensajes ya enviados conservan su texto.' ) . '</form></td></tr>';
		}
		$body = $rows
			? '<table class="widefat striped mo-table"><thead><tr><th>Plantilla</th><th>Canal</th><th>Cuándo se usa</th><th>Acciones</th></tr></thead><tbody>' . $rows . '</tbody></table>'
			: '<p>Todavía no tienes plantillas propias. Crea una con "Nueva plantilla": por ejemplo, un correo de bienvenida a la preventa o un mensaje de WhatsApp para recordar el saldo.</p>';
		echo Ui::card( 'Plantillas propias', $body ); // phpcs:ignore WordPress.Security.EscapeOutput

		if ( isset( $g['template'] ) ) {
			$id = sanitize_key( (string) $g['template'] );
			echo '<div id="plantilla">' . self::customForm( 'new' === $id ? null : $id ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}

		// Avisos del sistema.
		$body = Ui::formOpen( 'save_templates' ) . '<p class="description">Se envían solos con cada evento. Puedes cambiar el texto o desactivarlos; no se pueden borrar porque el sistema los necesita.</p>';
		foreach ( $events as $event => $info ) {
			$t     = Notifications::templates()[ $event ];
			$body .= '<details class="mo-details mo-template"><summary>' . esc_html( $info['label'] ) . ( $t['enabled'] ? '' : ' (desactivado)' ) . '</summary>';
			$body .= Ui::checkbox( "tpl[$event][enabled]", (bool) $t['enabled'], 'Enviar este aviso' );
			$body .= Ui::row( 'Asunto', Ui::input( "tpl[$event][subject]", $t['subject'], 'text', array( 'class' => 'large-text', 'data-mo-codes' => '1' ) ) . self::warning( "tpl[$event][subject]" ) );
			if ( in_array( 'client', $info['to'], true ) ) {
				$body .= Ui::row( 'Título del correo', Ui::input( "tpl[$event][title]", (string) $t['title'], 'text', array( 'class' => 'large-text', 'data-mo-codes' => '1' ) ) . self::warning( "tpl[$event][title]" ), 'El texto grande al inicio del correo. Admite emojis. Vacío = sin título.' );
				$body .= self::editorRow( 'Mensaje al cliente (correo)', "tpl[$event][body]", $t['body'], 8, 'Puedes usar negritas, cursivas, encabezados, listas y enlaces.', true );
				$body .= Ui::checkbox( "tpl[$event][card]", ! empty( $t['card'] ), 'Agregar la tarjeta con fecha, hora, servicio y ubicación' );
				$body .= self::designPreview( $event, $t );
				$body .= self::editorRow( 'Texto para WhatsApp (opcional)', "tpl[$event][whatsapp]", (string) ( $t['whatsapp'] ?? '' ), 5, 'Vacío = se usa el texto del correo. En WhatsApp: *negrita*, _cursiva_, ~tachado~. Los saltos de línea y emojis se respetan.' );
			}
			if ( in_array( 'admin', $info['to'], true ) ) {
				$body .= self::editorRow( 'Aviso a la administradora', "tpl[$event][admin]", $t['admin'], 3, 'Vacío = no avisar.' );
			}
			$body .= Ui::checkbox( "tpl[$event][reset]", false, 'Restaurar el texto original al guardar' );
			$body .= '</details>';
		}
		$body .= Ui::button( 'Guardar avisos del sistema' ) . '</form>';
		echo Ui::card( 'Avisos del sistema', $body ); // phpcs:ignore WordPress.Security.EscapeOutput

		// Registro.
		$status = isset( $g['status'] ) ? sanitize_key( $g['status'] ) : null;
		// beta.6: registro paginado (25 por página).
		$perPage = 25;
		$total   = Notifications::countRecent( $status ?: null );
		$pages   = max( 1, (int) ceil( $total / $perPage ) );
		$paged   = min( $pages, max( 1, (int) ( $g['pagina'] ?? 1 ) ) );
		$list    = Notifications::recent( $perPage, $status ?: null, ( $paged - 1 ) * $perPage );
		$body   = '<p><a href="' . esc_url( Ui::url( 'mo-booking-notifications' ) ) . '">Todos</a> · <a href="' . esc_url( Ui::url( 'mo-booking-notifications', array( 'status' => 'failed' ) ) ) . '">Fallidos</a> · <a href="' . esc_url( Ui::url( 'mo-booking-notifications', array( 'status' => 'pending' ) ) ) . '">Pendientes</a></p>';
		if ( $list ) {
			$body .= '<table class="widefat striped mo-table"><thead><tr><th>Fecha</th><th>Mensaje</th><th>Destinatario</th><th>Canal</th><th>Estado</th><th></th></tr></thead><tbody>';
			foreach ( $list as $n ) {
				$body .= '<tr><td data-label="Fecha">' . esc_html( Ui::localDateTime( 'pending' === $n['status'] ? $n['send_after'] : $n['updated_at'], 'd/m H:i' ) ) . '</td><td data-label="Mensaje">' . ( $n['booking_id'] ? '<a href="' . esc_url( Ui::url( 'mo-booking-bookings', array( 'booking' => (int) $n['booking_id'] ) ) ) . '">' : '' ) . esc_html( Notifications::eventLabel( $n['event'] ) ) . ( $n['booking_id'] ? '</a>' : '' ) . self::storedText( $n ) . '</td><td data-label="Destinatario">' . esc_html( $n['recipient'] ) . '</td><td data-label="Canal">' . esc_html( $n['channel'] ) . '</td><td data-label="Estado">' . esc_html( Notifications::STATUS_LABELS[ $n['status'] ] ?? $n['status'] ) . ( $n['last_error'] ? '<br><span class="description">' . esc_html( $n['last_error'] ) . '</span>' : '' ) . '</td><td>' .
					( 'failed' === $n['status'] ? Ui::formOpen( 'retry_notification', array( 'notification_id' => $n['id'] ), 'mo-inline-form' ) . Ui::button( 'Reintentar', 'button-link' ) . '</form>' : '' ) . '</td></tr>';
			}
			$body .= '</tbody></table>';
			if ( $pages > 1 ) {
				$links = paginate_links(
					array(
						'base'      => add_query_arg( 'pagina', '%#%', Ui::url( 'mo-booking-notifications', $status ? array( 'status' => $status ) : array() ) ),
						'format'    => '',
						'current'   => $paged,
						'total'     => $pages,
						'prev_text' => '‹ Anteriores',
						'next_text' => 'Siguientes ›',
					)
				);
				$body .= '<div class="tablenav"><div class="tablenav-pages mo-pagination"><span class="displaying-num">' . esc_html( sprintf( '%d mensajes · página %d de %d', $total, $paged, $pages ) ) . '</span> ' . $links . '</div></div>';
			}
		} else {
			$body .= '<p>Sin mensajes.</p>';
		}
		echo Ui::card( 'Registro de mensajes', $body ); // phpcs:ignore WordPress.Security.EscapeOutput
		Ui::footer();
	}

	/**
	 * beta.6.3: el correo completo con el diseño de la marca y datos de ejemplo (texto
	 * guardado; guarda primero para ver tus cambios).
	 */
	private static function designPreview( string $event, array $t ): string {
		$vars    = Notifications::sampleVars();
		$status  = in_array( $event, array( 'booking_confirmed', 'booking_rescheduled', 'reminder' ), true ) ? \MOBookingStudio\Domain\BookingStatus::CONFIRMED : \MOBookingStudio\Domain\BookingStatus::PENDING_PAYMENT;
		$email   = Notifications::designedEmail( Notifications::renderHtml( (string) $t['body'], $vars ), $t, $vars, array( 'status' => $status ) );
		return '<details class="mo-details"><summary>Ver el diseño del correo (con datos de ejemplo)</summary><p class="description">Muestra el texto guardado: guarda primero para ver tus cambios.</p>' .
			'<iframe title="Vista previa del correo" loading="lazy" sandbox="" style="width:100%;max-width:680px;height:760px;border:1px solid #dcdcde;border-radius:6px;background:#fff;" srcdoc="' . esc_attr( $email ) . '"></iframe></details>';
	}

	/** "Ver texto" del mensaje tal como se usó. */
	public static function storedText( array $row ): string {
		$t = Notifications::storedText( $row );
		if ( ! $t ) {
			return '';
		}
		return '<details class="mo-details mo-details--inline-text"><summary>Ver texto</summary>' . ( '' !== $t['subject'] ? '<p><strong>' . esc_html( $t['subject'] ) . '</strong></p>' : '' ) . '<pre class="mo-message-text">' . esc_html( $t['text'] ) . '</pre></details>';
	}

	private static function customForm( ?string $id ): string {
		$all = Notifications::customTemplates();
		$old = Ui::oldInput();
		$t   = $id && isset( $all[ $id ] ) ? $all[ $id ] : array( 'name' => '', 'channel' => 'email', 'trigger' => 'manual', 'subject' => '', 'body' => '', 'enabled' => true );
		foreach ( array( 'name', 'channel', 'trigger', 'subject', 'body' ) as $k ) {
			if ( isset( $old[ $k ] ) ) {
				$t[ $k ] = $old[ $k ];
			}
		}
		$triggers = array( 'manual' => 'A mano, desde cada reserva' );
		foreach ( Notifications::events() as $event => $info ) {
			if ( in_array( 'client', $info['to'], true ) ) {
				$triggers[ $event ] = 'Junto con: ' . $info['label'];
			}
		}
		$html  = Ui::formOpen( 'save_template', array( 'template_id' => (string) $id ) );
		$html .= Ui::row( 'Nombre (solo lo ves tú)', Ui::input( 'name', $t['name'], 'text', array( 'class' => 'regular-text', 'required' => 'required' ) ), '', 'name' );
		$html .= Ui::row( 'Canal', Ui::select( 'channel', Notifications::CHANNELS, $t['channel'] ), 'WhatsApp abre el chat con el texto listo desde la reserva; tú lo envías.', 'channel' );
		$html .= Ui::row( 'Cuándo se usa (correo)', Ui::select( 'trigger', $triggers, $t['trigger'] ), 'Un correo ligado a un evento se envía además del aviso del sistema.', 'trigger' );
		$html .= Ui::row( 'Asunto (correo)', Ui::input( 'subject', $t['subject'], 'text', array( 'class' => 'large-text', 'data-mo-codes' => '1' ) ) . self::warning( 'subject' ), '', 'subject' );
		$html .= self::editorRow( 'Mensaje', 'body', $t['body'], 8, 'Correo: negritas, cursivas, encabezados, listas y enlaces. WhatsApp: *negrita*, _cursiva_, ~tachado~ (el editor con formato solo aplica a correos).', 'whatsapp' !== $t['channel'] );
		$html .= Ui::checkbox( 'enabled', (bool) $t['enabled'], 'Activa' );
		$html .= '<p>' . Ui::button( $id ? 'Guardar plantilla' : 'Crear plantilla' ) . ' <a href="' . esc_url( Ui::url( 'mo-booking-notifications' ) ) . '">Cancelar</a></p></form>';
		return Ui::card( $id ? 'Editar plantilla: ' . $t['name'] : 'Nueva plantilla', $html );
	}

	/** Área de texto con la guía de códigos al lado, aviso de códigos desconocidos y vista previa. */
	private static function editorRow( string $label, string $name, string $value, int $rows, string $help = '', bool $rich = false ): string {
		$id    = $rich ? str_replace( '-', '_', Ui::id( $name ) ) : Ui::id( $name );
		$guide = '<div class="mo-codes" aria-label="Códigos disponibles"><p class="mo-codes__title">Códigos (clic para insertar)</p><ul>';
		foreach ( Notifications::variables() as $code => $text ) {
			$guide .= '<li><button type="button" class="button-link mo-code" data-insert="{{' . esc_attr( $code ) . '}}" data-target="' . esc_attr( $id ) . '"><code>{{' . esc_html( $code ) . '}}</code></button> <span>' . esc_html( $text ) . '</span></li>';
		}
		$guide .= '</ul></div>';
		if ( $rich ) {
			// Editor de WordPress sencillo (negritas, cursivas, encabezados, listas y enlaces).
			ob_start();
			wp_editor(
				\MOBookingStudio\Application\Notifications::isHtml( $value ) ? $value : wpautop( esc_html( $value ) ),
				$id,
				array(
					'textarea_name' => $name,
					'textarea_rows' => $rows,
					'media_buttons' => false,
					'teeny'         => false,
					'quicktags'     => array( 'buttons' => 'strong,em,link,ul,ol,li' ),
					'tinymce'       => array(
						'toolbar1'      => 'formatselect,bold,italic,underline,bullist,numlist,link,unlink,undo,redo',
						'toolbar2'      => '',
						'block_formats' => 'Párrafo=p;Título=h2;Subtítulo=h3',
					),
					'editor_class'  => 'mo-rich',
				)
			);
			$editor = (string) ob_get_clean();
			$editor = str_replace( '<textarea class="', '<textarea data-mo-codes="1" class="', $editor );
		} else {
			$editor = Ui::textarea( $name, $value, $rows, array( 'data-mo-codes' => '1' ) );
		}
		$control = '<div class="mo-editor"><div class="mo-editor__text">' . $editor . '<p class="mo-code-warning" data-warning-for="' . esc_attr( $id ) . '" role="status" hidden></p>' .
			'<p><button type="button" class="button button-small" data-mo-preview="' . esc_attr( $id ) . '">Vista previa con datos de ejemplo</button></p><div class="mo-preview-text' . ( $rich ? ' mo-preview-text--html' : '' ) . '" data-preview-for="' . esc_attr( $id ) . '" hidden></div>' . ( $help ? '<p class="description">' . esc_html( $help ) . '</p>' : '' ) . '</div>' . $guide . '</div>';
		return '<div class="mo-row"><label class="mo-row__label" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label><div class="mo-row__control">' . $control . '</div></div>';
	}

	private static function warning( string $name ): string {
		return '<p class="mo-code-warning" data-warning-for="' . esc_attr( Ui::id( $name ) ) . '" role="status" hidden></p>';
	}
}
