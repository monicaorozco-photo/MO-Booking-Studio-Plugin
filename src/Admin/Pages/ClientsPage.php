<?php
namespace MOBookingStudio\Admin\Pages;

use MOBookingStudio\Admin\Ui;
use MOBookingStudio\Application\Bookings;
use MOBookingStudio\Application\Clients;

final class ClientsPage {
	public static function render(): void {
		$g = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! empty( $g['client'] ) ) {
			self::edit( 'new' === $g['client'] ? null : (int) $g['client'] );
			return;
		}
		Ui::header( 'Clientes', ' <a class="page-title-action" href="' . esc_url( Ui::url( 'mo-booking-clients', array( 'client' => 'new' ) ) ) . '">Agregar cliente</a>' );
		echo '<form method="get" class="mo-filter"><input type="hidden" name="page" value="mo-booking-clients">' . Ui::input( 's', $g['s'] ?? '', 'search', array( 'placeholder' => 'Nombre, correo o teléfono', 'aria-label' => 'Buscar' ) ) . ' <button class="button">Buscar</button></form>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<p class="description">Umbral para cliente frecuente: ' . (int) Clients::frequentThreshold() . ' sesiones (sesiones previas registradas + sesiones completadas en el sistema). Una categoría manual prevalece. La importación por CSV llega en la siguiente etapa.</p>';
		$list = Clients::search( (string) ( $g['s'] ?? '' ), 200 );
		if ( ! $list ) {
			echo '<p>No hay clientes todavía. Se crean automáticamente con cada reserva o puedes agregarlos a mano.</p>';
			Ui::footer();
			return;
		}
		echo '<table class="widefat striped mo-table"><thead><tr><th>Nombre</th><th>Correo</th><th>Teléfono</th><th>Sesiones</th><th>Categoría</th></tr></thead><tbody>';
		foreach ( $list as $c ) {
			$cat = Clients::effectiveCategory( $c );
			echo '<tr><td data-label="Nombre"><a href="' . esc_url( Ui::url( 'mo-booking-clients', array( 'client' => $c['id'] ) ) ) . '"><strong>' . esc_html( $c['name'] ) . '</strong></a>' . ( $c['needs_review'] ? ' <span class="mo-pill mo-pill--review">Revisar</span>' : '' ) . ( $c['is_test'] ? ' <span class="mo-pill mo-pill--test">PRUEBA</span>' : '' ) . '</td>' .
				'<td data-label="Correo">' . esc_html( $c['email'] ) . '</td><td data-label="Teléfono">' . esc_html( $c['phone'] ) . '</td>' .
				'<td data-label="Sesiones">' . (int) Clients::sessions( $c ) . '</td><td data-label="Categoría">' . ( 'frequent' === $cat ? 'Frecuente' : 'General' ) . ( $c['manual_category'] ? ' (manual)' : '' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		Ui::footer();
	}

	private static function edit( ?int $id ): void {
		$c = $id ? Clients::find( $id ) : null;
		if ( $id && ! $c ) {
			wp_die( 'El cliente no existe.' );
		}
		$old = Ui::oldInput();
		$c   = $c ?: array( 'id' => 0, 'name' => '', 'email' => '', 'phone' => '', 'phone_country' => 'MX', 'manual_category' => '', 'historic_sessions' => 0, 'completed_sessions' => 0, 'needs_review' => 0, 'review_note' => '', 'notes' => '', 'phone_e164' => '' );
		$v   = static function ( $k ) use ( $old, $c ) {
			return $old[ $k ] ?? $c[ $k ];
		};
		Ui::header( $id ? 'Cliente: ' . $c['name'] : 'Nuevo cliente', ' <a class="page-title-action" href="' . esc_url( Ui::url( 'mo-booking-clients' ) ) . '">Volver</a>' );
		echo '<div class="mo-grid mo-grid--2">';
		$body  = Ui::formOpen( 'save_client', array( 'client_id' => (int) $c['id'] ) );
		$body .= Ui::row( 'Nombre', Ui::input( 'name', $v( 'name' ), 'text', array( 'required' => 'required', 'class' => 'regular-text' ) ), '', 'name' );
		$body .= Ui::row( 'Correo', Ui::input( 'email', $v( 'email' ), 'email', array( 'class' => 'regular-text' ) ), '', 'email' );
		$body .= Ui::row( 'Teléfono', Ui::select( 'phone_country', array( 'MX' => 'México +52', 'US' => 'EE. UU. +1' ), $v( 'phone_country' ) ) . ' ' . Ui::input( 'phone', $v( 'phone' ), 'tel' ), $c['phone_e164'] ? 'Normalizado: ' . $c['phone_e164'] : ( $c['phone'] ? 'No se pudo normalizar: revísalo (no se modificó).' : '' ), 'phone' );
		$body .= Ui::row( 'Sesiones previas (antes del sistema)', Ui::input( 'historic_sessions', $v( 'historic_sessions' ), 'number', array( 'min' => 0 ) ), 'Sesiones completadas registradas por el plugin: ' . (int) $c['completed_sessions'] . '.', 'historic_sessions' );
		$body .= Ui::row( 'Categoría', Ui::select( 'manual_category', array( '' => 'Automática según sesiones', 'frequent' => 'Frecuente (manual)', 'general' => 'General (manual)' ), (string) $v( 'manual_category' ) ), 'Cambiar la categoría no recalcula reservas existentes; hazlo desde cada reserva.', 'manual_category' );
		$body .= Ui::row( 'Revisión', Ui::checkbox( 'needs_review', (bool) $v( 'needs_review' ), 'Requiere revisión de identidad o duplicado' ), (string) $c['review_note'] );
		$body .= Ui::row( 'Notas internas', Ui::textarea( 'notes', $v( 'notes' ), 3 ), '', 'notes' );
		$body .= Ui::button( 'Guardar cliente' ) . '</form>';
		echo Ui::card( 'Datos', $body ); // phpcs:ignore WordPress.Security.EscapeOutput
		if ( $id ) {
			$bookings = Bookings::list( array( 'client_id' => $id, 'order' => 'desc', 'limit' => 100 ) );
			$body     = $bookings ? '<ul class="mo-list">' : '<p>Sin reservas.</p>';
			foreach ( $bookings as $b ) {
				$body .= '<li><a href="' . esc_url( Ui::url( 'mo-booking-bookings', array( 'booking' => $b['id'] ) ) ) . '">' . esc_html( $b['public_ref'] ) . '</a> · ' . esc_html( Ui::localDateTime( $b['start_utc'], 'd/m/Y H:i' ) ) . ' · ' . esc_html( (string) $b['service_title'] ) . ' ' . Ui::statusPill( $b['status'] ) . ' · ' . Ui::money( $b['total_cents'] ) . '</li>';
			}
			$body .= $bookings ? '</ul>' : '';
			echo Ui::card( 'Reservas y pagos', $body ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</div>';
		Ui::footer();
	}

	public static function save( array $p ): int {
		$data = array(
			'name'              => sanitize_text_field( (string) ( $p['name'] ?? '' ) ),
			'email'             => sanitize_email( (string) ( $p['email'] ?? '' ) ),
			'phone'             => sanitize_text_field( (string) ( $p['phone'] ?? '' ) ),
			'phone_country'     => sanitize_key( (string) ( $p['phone_country'] ?? 'MX' ) ),
			'historic_sessions' => (int) ( $p['historic_sessions'] ?? 0 ),
			'manual_category'   => sanitize_key( (string) ( $p['manual_category'] ?? '' ) ) ?: null,
			'needs_review'      => ! empty( $p['needs_review'] ),
			'notes'             => sanitize_textarea_field( (string) ( $p['notes'] ?? '' ) ),
		);
		$id = (int) ( $p['client_id'] ?? 0 );
		if ( $id ) {
			Clients::update( $id, $data );
			return $id;
		}
		return Clients::create( $data );
	}
}
