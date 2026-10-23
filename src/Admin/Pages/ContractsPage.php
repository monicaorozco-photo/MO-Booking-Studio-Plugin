<?php
namespace MOBookingStudio\Admin\Pages;

use MOBookingStudio\Admin\Ui;
use MOBookingStudio\Application\Contracts;

/**
 * Contratos (6.5): plantillas con borrador, vista previa y versiones publicadas
 * inmutables. Las reglas económicas de cancelación y cambios siguen en su propia
 * pestaña ("Reglas de cancelación y cambios").
 */
final class ContractsPage {
	public static function tabs( string $current ): string {
		$tabs = array(
			'mo-booking-contracts' => 'Plantillas de contrato',
			'mo-booking-policies'  => 'Reglas de cancelación y cambios',
		);
		$html = '<nav class="nav-tab-wrapper mo-tabs" aria-label="Contratos">';
		foreach ( $tabs as $page => $label ) {
			$html .= '<a class="nav-tab' . ( $page === $current ? ' nav-tab-active' : '' ) . '" href="' . esc_url( Ui::url( $page ) ) . '"' . ( $page === $current ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}
		return $html . '</nav>';
	}

	public static function render(): void {
		$id = isset( $_GET['contract'] ) ? sanitize_key( wp_unslash( $_GET['contract'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		Ui::header( 'Contratos', '<a class="page-title-action" href="' . esc_url( Ui::url( 'mo-booking-contracts', array( 'contract' => 'new' ) ) ) . '">Nueva plantilla</a>' );
		echo self::tabs( 'mo-booking-contracts' ); // phpcs:ignore WordPress.Security.EscapeOutput
		if ( '' !== $id ) {
			self::edit( 'new' === $id ? null : (int) $id );
		} else {
			self::index();
		}
		Ui::footer();
	}

	private static function index(): void {
		echo '<div class="mo-help"><p>Cada servicio elige una plantilla publicada. La persona la lee en un acordeón debajo del formulario y debe aceptarla para reservar. Cada reserva guarda una copia exacta de la versión que aceptó; publicar cambios crea una versión nueva y no altera reservas anteriores.</p></div>';
		( new \MOBookingStudio\Admin\Tables\ContractsTable() )->render( 'Buscar plantillas' );
	}

	private static function edit( ?int $id ): void {
		$c = $id ? Contracts::find( $id ) : null;
		if ( $id && ! $c ) {
			echo '<div class="notice notice-error"><p>La plantilla no existe.</p></div>';
			return;
		}
		$current = $c ? Contracts::current( (int) $c['id'] ) : null;
		$title   = $c ? ( '' !== (string) $c['draft_title'] ? $c['draft_title'] : ( $current['title'] ?? '' ) ) : '';
		$body    = $c ? ( '' !== (string) $c['draft_body'] ? $c['draft_body'] : ( $current['body'] ?? '' ) ) : '';
		$draftDiffers = $current && ( Contracts::hash( (string) $title, (string) $body ) !== $current['body_hash'] );

		echo '<p><a href="' . esc_url( Ui::url( 'mo-booking-contracts' ) ) . '">← Todas las plantillas</a></p>';
		if ( $current ) {
			echo '<p>Versión publicada vigente: <strong>' . (int) $current['version'] . '</strong> (' . esc_html( Ui::localDateTime( $current['created_at'], 'd/m/Y H:i' ) ) . ').' . ( $draftDiffers ? ' <strong>Hay cambios en borrador sin publicar.</strong>' : '' ) . '</p>';
		}
		echo Ui::formOpen( 'save_contract', array( 'contract_id' => $c ? (int) $c['id'] : 0 ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		$form  = Ui::row( 'Nombre interno', Ui::input( 'name', $c['name'] ?? '', 'text', array( 'class' => 'regular-text', 'required' => 'required' ) ), 'Solo lo ves tú.', 'name' );
		$form .= Ui::row( 'Título visible', Ui::input( 'title', $title, 'text', array( 'class' => 'large-text' ) ), 'Aparece en el acordeón y en la casilla de aceptación.', 'title' );
		echo Ui::card( $c ? 'Editar plantilla' : 'Nueva plantilla', $form ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<div class="mo-card"><h2>Texto del contrato</h2>';
		wp_editor(
			(string) $body,
			'mo_contract_body',
			array(
				'textarea_name' => 'body',
				'media_buttons' => false,
				'teeny'         => true,
				'textarea_rows' => 18,
				'tinymce'       => array( 'toolbar1' => 'formatselect,bold,italic,underline,bullist,numlist,blockquote,link,unlink,undo,redo' ),
				'quicktags'     => array( 'buttons' => 'strong,em,ul,ol,li,link,close' ),
			)
		);
		echo '<p class="description">Se permiten párrafos, títulos, negritas, cursivas, listas, citas y enlaces. Se quitan scripts, estilos e imágenes.</p></div>';
		echo '<p class="mo-sticky-actions">' . Ui::button( 'Guardar borrador', 'button-secondary' ) . ' <button type="submit" name="publish" value="1" class="button button-primary" data-confirm="¿Publicar como versión nueva? Las reservas nuevas aceptarán esta versión; las anteriores conservan la suya.">Publicar nueva versión</button></p></form>'; // phpcs:ignore WordPress.Security.EscapeOutput

		if ( $c && '' !== trim( wp_strip_all_tags( (string) $body ) ) ) {
			echo Ui::card( 'Vista previa del borrador (así lo verá la persona)', '<details class="mo-contract-preview" open><summary>' . esc_html( (string) $title ) . '</summary>' . Contracts::sanitize( (string) $body ) . '</details>' ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		if ( $c ) {
			$rows = '';
			foreach ( Contracts::versions( (int) $c['id'] ) as $v ) {
				$rows .= '<tr><td>' . (int) $v['version'] . '</td><td>' . esc_html( Ui::localDateTime( $v['created_at'], 'd/m/Y H:i' ) ) . '</td><td><code>' . esc_html( substr( $v['body_hash'], 0, 16 ) ) . '…</code></td><td><details><summary>' . esc_html( $v['title'] ) . '</summary>' . Contracts::sanitize( (string) $v['body'] ) . '</details></td></tr>';
			}
			echo Ui::card( 'Versiones publicadas (no se modifican)', $rows ? '<table class="widefat striped"><thead><tr><th>Versión</th><th>Publicada</th><th>Hash SHA-256</th><th>Texto</th></tr></thead><tbody>' . $rows . '</tbody></table>' : '<p>Aún no se ha publicado ninguna versión. Los servicios solo pueden usar plantillas publicadas.</p>' ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}
}
