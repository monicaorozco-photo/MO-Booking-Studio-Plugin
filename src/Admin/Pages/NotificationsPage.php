<?php
namespace MOBookingStudio\Admin\Pages;

use MOBookingStudio\Admin\Ui;
use MOBookingStudio\Application\Notifications;
use MOBookingStudio\Infrastructure\Settings;

final class NotificationsPage {
	public static function render(): void {
		$g = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification
		Ui::header( 'Notificaciones' );
		$mode = 'live' === Settings::get( 'email_mode' ) ? 'Envío real con wp_mail (el transporte del sitio, por ejemplo un plugin SMTP).' : 'Simulación: los mensajes se registran pero NO se envían.';
		echo '<div class="mo-help"><p><strong>Correo:</strong> ' . esc_html( $mode ) . ' Cámbialo en Ajustes. "Aceptado por el transporte" significa que el servidor aceptó el mensaje, no que llegó a la bandeja. <strong>WhatsApp:</strong> ' . esc_html( array( 'off' => 'desactivado', 'manual' => 'manual desde cada reserva', 'automatic' => 'automático pendiente de conectar con un proveedor oficial (los mensajes quedan "sin conexión")' )[ Settings::get( 'whatsapp_mode' ) ] ?? '' ) . '.</p></div>';

		$vars = '';
		foreach ( Notifications::variables() as $k => $label ) {
			$vars .= '<code>{{' . esc_html( $k ) . '}}</code> ' . esc_html( $label ) . '<br>';
		}
		echo '<details class="mo-details"><summary>Variables disponibles</summary><p>' . $vars . '</p></details>'; // phpcs:ignore WordPress.Security.EscapeOutput

		$body = Ui::formOpen( 'save_templates' );
		foreach ( Notifications::events() as $event => $info ) {
			$t     = Notifications::templates()[ $event ];
			$body .= '<details class="mo-details mo-template"><summary>' . esc_html( $info['label'] ) . ( $t['enabled'] ? '' : ' (desactivado)' ) . '</summary>';
			$body .= Ui::checkbox( "tpl[$event][enabled]", (bool) $t['enabled'], 'Enviar este aviso' );
			$body .= Ui::row( 'Asunto', Ui::input( "tpl[$event][subject]", $t['subject'], 'text', array( 'class' => 'large-text' ) ) );
			if ( in_array( 'client', $info['to'], true ) ) {
				$body .= Ui::row( 'Mensaje al cliente', Ui::textarea( "tpl[$event][body]", $t['body'], 8 ) );
			}
			if ( in_array( 'admin', $info['to'], true ) ) {
				$body .= Ui::row( 'Aviso a la administradora', Ui::textarea( "tpl[$event][admin]", $t['admin'], 3 ), 'Vacío = no avisar.' );
			}
			$body .= '</details>';
		}
		$body .= Ui::button( 'Guardar plantillas' ) . '</form>';
		echo Ui::card( 'Plantillas', $body ); // phpcs:ignore WordPress.Security.EscapeOutput

		$status = isset( $g['status'] ) ? sanitize_key( $g['status'] ) : null;
		$list   = Notifications::recent( 100, $status ?: null );
		$body   = '<p><a href="' . esc_url( Ui::url( 'mo-booking-notifications' ) ) . '">Todos</a> · <a href="' . esc_url( Ui::url( 'mo-booking-notifications', array( 'status' => 'failed' ) ) ) . '">Fallidos</a> · <a href="' . esc_url( Ui::url( 'mo-booking-notifications', array( 'status' => 'pending' ) ) ) . '">Pendientes</a></p>';
		if ( $list ) {
			$events = Notifications::events();
			$body  .= '<table class="widefat striped mo-table"><thead><tr><th>Fecha</th><th>Evento</th><th>Destinatario</th><th>Canal</th><th>Estado</th><th></th></tr></thead><tbody>';
			foreach ( $list as $n ) {
				$body .= '<tr><td data-label="Fecha">' . esc_html( Ui::localDateTime( 'pending' === $n['status'] ? $n['send_after'] : $n['updated_at'], 'd/m H:i' ) ) . '</td><td data-label="Evento">' . ( $n['booking_id'] ? '<a href="' . esc_url( Ui::url( 'mo-booking-bookings', array( 'booking' => (int) $n['booking_id'] ) ) ) . '">' : '' ) . esc_html( $events[ $n['event'] ]['label'] ?? $n['event'] ) . ( $n['booking_id'] ? '</a>' : '' ) . '</td><td data-label="Destinatario">' . esc_html( $n['recipient'] ) . '</td><td data-label="Canal">' . esc_html( $n['channel'] ) . '</td><td data-label="Estado">' . esc_html( Notifications::STATUS_LABELS[ $n['status'] ] ?? $n['status'] ) . ( $n['last_error'] ? '<br><span class="description">' . esc_html( $n['last_error'] ) . '</span>' : '' ) . '</td><td>' .
					( 'failed' === $n['status'] ? Ui::formOpen( 'retry_notification', array( 'notification_id' => $n['id'] ), 'mo-inline-form' ) . Ui::button( 'Reintentar', 'button-link' ) . '</form>' : '' ) . '</td></tr>';
			}
			$body .= '</tbody></table>';
		} else {
			$body .= '<p>Sin mensajes.</p>';
		}
		echo Ui::card( 'Registro de mensajes', $body ); // phpcs:ignore WordPress.Security.EscapeOutput
		Ui::footer();
	}
}
