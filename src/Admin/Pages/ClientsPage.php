<?php
namespace MOBookingStudio\Admin\Pages;

use MOBookingStudio\Admin\Tables\ClientsTable;
use MOBookingStudio\Admin\Ui;
use MOBookingStudio\Application\Bookings;
use MOBookingStudio\Application\Clients;
use MOBookingStudio\Infrastructure\Audit;

final class ClientsPage {
	public static function render(): void {
		$g = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! empty( $g['client'] ) ) {
			self::edit( 'new' === $g['client'] ? null : (int) $g['client'] );
			return;
		}
		if ( ! empty( $g['duplicates'] ) ) {
			self::duplicates();
			return;
		}
		if ( ! empty( $g['merge'] ) ) {
			self::merge( (int) $g['merge'], (int) ( $g['with'] ?? 0 ) );
			return;
		}
		Ui::header( 'Clientes', ' <a class="page-title-action" href="' . esc_url( Ui::url( 'mo-booking-clients', array( 'client' => 'new' ) ) ) . '">Agregar cliente</a>' );
		echo '<p class="description">Umbral para cliente frecuente: ' . (int) Clients::frequentThreshold() . ' sesiones (sesiones previas registradas + sesiones completadas en el sistema). Una categoría manual prevalece. Los frecuentes ven su precio al identificarse con su nombre y su correo o teléfono.</p>';
		( new ClientsTable() )->render( 'Buscar clientes' );
		Ui::footer();
	}

	private static function edit( ?int $id ): void {
		$c = $id ? Clients::find( $id ) : null;
		if ( $id && ! $c ) {
			Ui::header( 'Cliente' );
			echo '<div class="notice notice-error"><p>El cliente no existe (quizá se eliminó definitivamente).</p></div><p><a href="' . esc_url( Ui::url( 'mo-booking-clients' ) ) . '">Volver a Clientes</a></p>';
			Ui::footer();
			return;
		}
		$old = Ui::oldInput();
		$c   = $c ?: array( 'id' => 0, 'name' => '', 'email' => '', 'phone' => '', 'phone_country' => 'MX', 'manual_category' => '', 'historic_sessions' => 0, 'completed_sessions' => 0, 'needs_review' => 0, 'review_note' => '', 'notes' => '', 'phone_e164' => '', 'trashed_at' => null, 'archived_at' => null, 'merged_into' => null );
		$v   = static function ( $k ) use ( $old, $c ) {
			return $old[ $k ] ?? $c[ $k ];
		};
		Ui::header( $id ? 'Cliente: ' . $c['name'] : 'Nuevo cliente', ' <a class="page-title-action" href="' . esc_url( Ui::url( 'mo-booking-clients' ) ) . '">Volver</a>' );
		if ( $id && $c['trashed_at'] ) {
			$merged = $c['merged_into'] ? Clients::find( (int) $c['merged_into'] ) : null;
			echo '<div class="notice notice-warning"><p>Este cliente está en la <strong>papelera</strong>' . ( $merged ? ' porque se fusionó con <a href="' . esc_url( Ui::url( 'mo-booking-clients', array( 'client' => $merged['id'] ) ) ) . '">' . esc_html( $merged['name'] ) . '</a> (sus reservas se quedan allá)' : '' ) . '. ' .
				Ui::formOpen( 'restore_client_trash', array( 'client_id' => $id ), 'mo-inline-form' ) . Ui::button( 'Restaurar', 'button-secondary' ) . '</form></p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '<div class="mo-grid mo-grid--2">';
		if ( $id && ! $c['trashed_at'] ) {
			$archivedNow = ! empty( $c['archived_at'] );
			echo '<p>' . ( $archivedNow ? '<span class="mo-pill">Archivado</span> ' : '' ) . Ui::formOpen( $archivedNow ? 'restore_client' : 'archive_client', array( 'client_id' => $id ), 'mo-inline-form' ) . Ui::button( $archivedNow ? 'Quitar de archivados' : 'Archivar', 'button-secondary', $archivedNow ? '' : '¿Archivar? No se borra nada; puedes restaurarlo.' ) . '</form> ' . // phpcs:ignore WordPress.Security.EscapeOutput
				'<a class="button" href="' . esc_url( Ui::url( 'mo-booking-clients', array( 'merge' => $id ) ) ) . '">Fusionar con otro cliente</a> ' .
				Ui::formOpen( 'trash_client', array( 'client_id' => $id ), 'mo-inline-form' ) . Ui::button( 'Mover a la papelera', 'button-link-delete', '¿Mover a la papelera? Puedes restaurarlo después.' ) . '</form></p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		$body  = Ui::formOpen( 'save_client', array( 'client_id' => (int) $c['id'] ) );
		$body .= Ui::row( 'Nombre', Ui::input( 'name', $v( 'name' ), 'text', array( 'required' => 'required', 'class' => 'regular-text' ) ), '', 'name' );
		$body .= Ui::row( 'Correo principal', Ui::input( 'email', $v( 'email' ), 'email', array( 'class' => 'regular-text' ) ), '', 'email' );
		$body .= Ui::row( 'Teléfono principal', Ui::select( 'phone_country', array( 'MX' => 'México +52', 'US' => 'EE. UU. +1' ), $v( 'phone_country' ) ) . ' ' . Ui::input( 'phone', $v( 'phone' ), 'tel' ), $c['phone_e164'] ? 'Normalizado: ' . $c['phone_e164'] : ( $c['phone'] ? 'No se pudo normalizar: revísalo (no se modificó).' : '' ), 'phone' );
		$body .= Ui::row( 'Sesiones previas (antes del sistema)', Ui::input( 'historic_sessions', $v( 'historic_sessions' ), 'number', array( 'min' => 0 ) ), 'Sesiones completadas registradas por el plugin: ' . (int) $c['completed_sessions'] . '.', 'historic_sessions' );
		$body .= Ui::row( 'Categoría', Ui::select( 'manual_category', array( '' => 'Automática según sesiones', 'frequent' => 'Frecuente (manual)', 'general' => 'General (manual)' ), (string) $v( 'manual_category' ) ), 'Cambiar la categoría no recalcula reservas existentes; hazlo desde cada reserva.', 'manual_category' );
		$body .= Ui::row( 'Revisión', Ui::checkbox( 'needs_review', (bool) $v( 'needs_review' ), 'Requiere revisión de identidad o duplicado' ), (string) $c['review_note'] );
		$body .= Ui::row( 'Notas internas', Ui::textarea( 'notes', $v( 'notes' ), 3 ), '', 'notes' );
		$body .= Ui::button( 'Guardar cliente' ) . '</form>';
		echo Ui::card( 'Datos', $body ); // phpcs:ignore WordPress.Security.EscapeOutput
		if ( $id ) {
			echo Ui::card( 'Otros correos y teléfonos', self::contactsCard( $c ) ); // phpcs:ignore WordPress.Security.EscapeOutput
			if ( ! $c['trashed_at'] ) {
				$similar = Clients::duplicateSuggestions( $id, 10 );
				if ( $similar ) {
					$body = '<p>Estos clientes podrían ser la misma persona. Revisa antes de fusionar.</p><ul class="mo-list">';
					foreach ( $similar as $pair ) {
						$other = (int) $pair['a']['id'] === $id ? $pair['b'] : $pair['a'];
						$body .= '<li><a href="' . esc_url( Ui::url( 'mo-booking-clients', array( 'client' => $other['id'] ) ) ) . '">' . esc_html( $other['name'] ) . '</a> · ' . esc_html( trim( $other['email'] . ' ' . $other['phone'] ) ) . ' · <em>' . esc_html( implode( ', ', $pair['reasons'] ) ) . '</em> · <a href="' . esc_url( Ui::url( 'mo-booking-clients', array( 'merge' => $id, 'with' => $other['id'] ) ) ) . '">Fusionar…</a></li>';
					}
					echo Ui::card( 'Posibles duplicados', $body . '</ul>' ); // phpcs:ignore WordPress.Security.EscapeOutput
				}
			}
			$bookings = Bookings::list( array( 'client_id' => $id, 'order' => 'desc', 'limit' => 100 ) );
			$trashed  = Bookings::list( array( 'client_id' => $id, 'order' => 'desc', 'limit' => 100, 'trash' => true ) );
			$body     = $bookings ? '<ul class="mo-list">' : '<p>Sin reservas.</p>';
			foreach ( $bookings as $b ) {
				$body .= '<li><a href="' . esc_url( Ui::url( 'mo-booking-bookings', array( 'booking' => $b['id'] ) ) ) . '">' . esc_html( $b['public_ref'] ) . '</a> · ' . esc_html( Ui::localDateTime( $b['start_utc'], 'd/m/Y H:i' ) ) . ' · ' . esc_html( (string) $b['service_title'] ) . ' ' . Ui::statusPill( $b['status'] ) . ' · ' . Ui::money( $b['total_cents'] ) . '</li>';
			}
			$body .= $bookings ? '</ul>' : '';
			if ( $trashed ) {
				$body .= '<p class="description">Además, ' . count( $trashed ) . ' reserva(s) en la papelera.</p>';
			}
			echo Ui::card( 'Reservas y pagos', $body ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<div id="eliminar">' . Ui::card( 'Eliminar definitivamente', self::purgeCard( $c, count( $bookings ) + count( $trashed ) ), 'mo-card--danger' ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</div>';
		Ui::footer();
	}

	private static function contactsCard( array $c ): string {
		$list = Clients::contacts( (int) $c['id'] );
		$html = '<p class="description">Se usan para encontrar al cliente al reservar y para reconocer su tarifa de cliente frecuente. Al fusionar, los del duplicado llegan aquí.</p>';
		if ( $list ) {
			$html .= '<table class="widefat striped"><tbody>';
			foreach ( $list as $k ) {
				$html .= '<tr><td>' . ( 'email' === $k['kind'] ? 'Correo' : 'Teléfono' ) . '</td><td>' . esc_html( $k['value'] ) . ( $k['label'] ? ' <span class="description">(' . esc_html( $k['label'] ) . ')</span>' : '' ) . '</td><td>' .
					Ui::formOpen( 'remove_contact', array( 'client_id' => $c['id'], 'contact_id' => $k['id'] ), 'mo-inline-form' ) . Ui::button( 'Quitar', 'button-link-delete', '¿Quitar este contacto?' ) . '</form></td></tr>';
			}
			$html .= '</tbody></table>';
		}
		$html .= Ui::formOpen( 'add_contact', array( 'client_id' => $c['id'] ) ) .
			'<p>' . Ui::select( 'contact_kind', array( 'email' => 'Correo', 'phone' => 'Teléfono' ), 'email', array( 'aria-label' => 'Tipo' ) ) . ' ' .
			Ui::select( 'contact_country', array( 'MX' => 'México +52', 'US' => 'EE. UU. +1' ), 'MX', array( 'aria-label' => 'País del teléfono' ) ) . ' ' .
			Ui::input( 'contact_value', '', 'text', array( 'placeholder' => 'correo o teléfono', 'aria-label' => 'Correo o teléfono', 'required' => 'required' ) ) . ' ' .
			Ui::input( 'contact_label', '', 'text', array( 'placeholder' => 'Nota (opcional)', 'aria-label' => 'Nota', 'class' => 'small-text' ) ) . ' ' . Ui::button( 'Agregar', 'button-secondary' ) . '</p></form>';
		return $html;
	}

	private static function purgeCard( array $c, int $bookings ): string {
		return '<p>Borra para siempre al cliente y <strong>todo su historial</strong>: ' . (int) $bookings . ' reserva(s) con sus pagos, comprobantes (archivos incluidos), copias de contrato, enlaces privados, mensajes y eventos propios en Google Calendar. No se puede deshacer y no pasa por la papelera.</p>' .
			'<p>La bitácora conserva solo un registro sin datos personales (cuántas reservas, pagos y comprobantes se borraron).</p>' .
			Ui::formOpen( 'purge_client', array( 'client_id' => $c['id'] ) ) .
			Ui::row( 'Escribe el nombre del cliente para confirmar', Ui::input( 'confirm_name', '', 'text', array( 'class' => 'regular-text', 'autocomplete' => 'off', 'placeholder' => $c['name'] ) ), 'Debe coincidir con: ' . $c['name'], 'confirm_name' ) .
			Ui::button( 'Eliminar definitivamente', 'button-link-delete', '¿Eliminar al cliente y todo su historial? No se puede deshacer.' ) . '</form>';
	}

	/** Pantalla de posibles duplicados. */
	private static function duplicates(): void {
		Ui::header( 'Posibles clientes duplicados', ' <a class="page-title-action" href="' . esc_url( Ui::url( 'mo-booking-clients' ) ) . '">Volver</a>' );
		echo '<p class="description">Se comparan correos y teléfonos (principales y adicionales) y nombres sin distinguir acentos, mayúsculas ni faltas leves. Nada se fusiona solo: elige "Fusionar" o "No son la misma persona".</p>';
		$pairs = Clients::duplicateSuggestions( null, 200 );
		if ( ! $pairs ) {
			echo '<p>No hay posibles duplicados.</p>';
			Ui::footer();
			return;
		}
		$who = static function ( array $c ): string {
			return '<a href="' . esc_url( Ui::url( 'mo-booking-clients', array( 'client' => $c['id'] ) ) ) . '"><strong>' . esc_html( $c['name'] ) . '</strong></a><br><span class="description">' . esc_html( $c['email'] ) . ( $c['email'] && $c['phone'] ? ' · ' : '' ) . esc_html( $c['phone'] ) . ' · ' . (int) Clients::sessions( $c ) . ' sesiones</span>';
		};
		echo '<table class="widefat striped mo-table"><thead><tr><th>Cliente</th><th>Posible duplicado</th><th>Por qué</th><th>Acciones</th></tr></thead><tbody>';
		foreach ( $pairs as $pair ) {
			echo '<tr><td data-label="Cliente">' . $who( $pair['a'] ) . '</td><td data-label="Posible duplicado">' . $who( $pair['b'] ) . '</td><td data-label="Por qué">' . esc_html( implode( ', ', $pair['reasons'] ) ) . '</td><td data-label="Acciones">' . // phpcs:ignore WordPress.Security.EscapeOutput
				'<a class="button button-small" href="' . esc_url( Ui::url( 'mo-booking-clients', array( 'merge' => $pair['a']['id'], 'with' => $pair['b']['id'] ) ) ) . '">Fusionar…</a> ' .
				Ui::formOpen( 'dismiss_duplicate', array( 'a' => $pair['a']['id'], 'b' => $pair['b']['id'] ), 'mo-inline-form' ) . Ui::button( 'No son la misma persona', 'button-link' ) . '</form></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</tbody></table>';
		Ui::footer();
	}

	/** Fusionar: elegir con quién y cuál se queda como principal, con vista previa. */
	private static function merge( int $id, int $with ): void {
		$a = Clients::find( $id );
		Ui::header( 'Fusionar clientes', ' <a class="page-title-action" href="' . esc_url( Ui::url( 'mo-booking-clients', array( 'client' => $id ) ) ) . '">Volver</a>' );
		if ( ! $a ) {
			echo '<p>El cliente no existe.</p>';
			Ui::footer();
			return;
		}
		$b = $with ? Clients::find( $with ) : null;
		if ( ! $b ) {
			$term    = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			$options = array();
			foreach ( Clients::duplicateSuggestions( $id, 20 ) as $pair ) {
				$other = (int) $pair['a']['id'] === $id ? $pair['b'] : $pair['a'];
				$options[ (int) $other['id'] ] = $other;
			}
			if ( '' !== $term ) {
				foreach ( Clients::search( $term, 20 ) as $found ) {
					if ( (int) $found['id'] !== $id ) {
						$options[ (int) $found['id'] ] = $found;
					}
				}
			}
			echo '<p>Elige el otro expediente de <strong>' . esc_html( $a['name'] ) . '</strong>.</p>';
			echo '<form method="get"><input type="hidden" name="page" value="mo-booking-clients"><input type="hidden" name="merge" value="' . (int) $id . '">' . Ui::input( 'q', $term, 'search', array( 'placeholder' => 'Nombre, correo o teléfono', 'aria-label' => 'Buscar' ) ) . ' <button class="button">Buscar</button></form>'; // phpcs:ignore WordPress.Security.EscapeOutput
			if ( $options ) {
				echo '<ul class="mo-list">';
				foreach ( $options as $o ) {
					echo '<li><a href="' . esc_url( Ui::url( 'mo-booking-clients', array( 'merge' => $id, 'with' => $o['id'] ) ) ) . '">' . esc_html( $o['name'] ) . '</a> · ' . esc_html( trim( $o['email'] . ' ' . $o['phone'] ) ) . '</li>';
				}
				echo '</ul>';
			} else {
				echo '<p class="description">Sin sugerencias. Busca por nombre, correo o teléfono.</p>';
			}
			Ui::footer();
			return;
		}
		// El principal es el que tiene más historial (se puede invertir).
		$principal = isset( $_GET['principal'] ) ? (int) $_GET['principal'] : ( Clients::sessions( $b ) > Clients::sessions( $a ) ? (int) $b['id'] : (int) $a['id'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$keep      = $principal === (int) $b['id'] ? $b : $a;
		$drop      = $principal === (int) $b['id'] ? $a : $b;
		try {
			$p = Clients::previewMerge( (int) $keep['id'], (int) $drop['id'] );
		} catch ( \MOBookingStudio\Domain\DomainException $e ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $e->getMessage() ) . '</p></div>';
			Ui::footer();
			return;
		}
		$card = static function ( array $c, string $role ): string {
			return '<div class="mo-card"><h2 class="mo-card__title">' . esc_html( $role ) . '</h2><p><strong>' . esc_html( $c['name'] ) . '</strong></p><p>' . esc_html( $c['email'] ) . '<br>' . esc_html( $c['phone'] ) . '</p><p>' . (int) Clients::sessions( $c ) . ' sesiones · categoría ' . ( 'frequent' === Clients::effectiveCategory( $c ) ? 'frecuente' : 'general' ) . '</p></div>';
		};
		echo '<div class="mo-grid mo-grid--2">' . $card( $keep, 'Se queda (principal)' ) . $card( $drop, 'Se fusiona y va a la papelera' ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<p><a href="' . esc_url( Ui::url( 'mo-booking-clients', array( 'merge' => $id, 'with' => $with, 'principal' => $drop['id'] ) ) ) . '">Invertir: dejar a ' . esc_html( $drop['name'] ) . ' como principal</a></p>';
		echo '<div class="mo-card"><h2 class="mo-card__title">Qué va a pasar</h2><ul class="mo-list">' .
			'<li>' . (int) $p['bookings'] . ' reserva(s) de ' . esc_html( $drop['name'] ) . ' pasan a ' . esc_html( $keep['name'] ) . ' (con sus pagos, comprobantes y contratos).</li>' .
			'<li>' . ( $p['contacts'] ? 'Correos y teléfonos que se agregan como contactos adicionales: ' . esc_html( implode( ', ', $p['contacts'] ) ) . '.' : 'No hay correos ni teléfonos nuevos que agregar.' ) . '</li>' .
			'<li>Sesiones previas: ' . (int) $p['historic'] . ' (se toma el mayor de los dos) · completadas en el sistema: ' . (int) $p['completed'] . ' (se suman).</li>' .
			'<li>El nombre, correo y teléfono principales de ' . esc_html( $keep['name'] ) . ' no cambian. Las notas se unen.</li>' .
			'<li>' . esc_html( $drop['name'] ) . ' queda en la papelera; si lo restauras, sus reservas se quedan con el principal.</li></ul>' .
			Ui::formOpen( 'merge_clients', array( 'principal_id' => $keep['id'], 'duplicate_id' => $drop['id'] ) ) . Ui::button( 'Fusionar', 'button-primary', '¿Fusionar estos clientes?' ) . '</form></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
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
