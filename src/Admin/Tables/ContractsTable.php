<?php
namespace MOBookingStudio\Admin\Tables;

use MOBookingStudio\Admin\Ui;
use MOBookingStudio\Application\Contracts;
use MOBookingStudio\Application\Trash;
use MOBookingStudio\Domain\Names;
use MOBookingStudio\Infrastructure\Capabilities as Cap;
use MOBookingStudio\Infrastructure\Db;
use MOBookingStudio\Infrastructure\Schema;

final class ContractsTable extends BaseTable {
	private const TABS = array(
		''         => array( 'Todas', array( 'active' ) ),
		'archived' => array( 'Archivadas', array( 'archived' ) ),
		'trash'    => array( 'Papelera', array( 'trash' ) ),
	);

	public function __construct() {
		parent::__construct( 'contrato', 'contracts' );
	}

	public static function page(): string {
		return 'mo-booking-contracts';
	}

	public static function capability(): string {
		return Cap::SETTINGS;
	}

	public function get_columns() {
		return array(
			'cb'       => '<input type="checkbox">',
			'name'     => 'Plantilla',
			'version'  => 'Vigente',
			'services' => 'Servicios',
			'bookings' => 'Reservas que la aceptaron',
			'updated'  => 'Última modificación',
		);
	}

	protected function get_sortable_columns() {
		return array( 'name' => array( 'name', false ), 'updated' => array( 'updated', true ) );
	}

	protected function get_primary_column_name() {
		return 'name';
	}

	private static function allRows(): array {
		return Db::wpdb()->get_results( 'SELECT * FROM ' . Schema::table( 'contracts' ) . ' ORDER BY name', ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function prepare_items() {
		$view     = isset( self::TABS[ $this->view() ] ) ? $this->view() : '';
		$statuses = self::TABS[ $view ][1];
		$term     = Names::key( $this->search() );
		$rows     = array();
		foreach ( self::allRows() as $c ) {
			if ( ! in_array( $c['status'], $statuses, true ) || ( '' !== $term && false === strpos( Names::key( $c['name'] . ' ' . $c['draft_title'] ), $term ) ) ) {
				continue;
			}
			$c['services'] = array_column( Contracts::servicesUsing( (int) $c['id'] ), 'title' );
			$c['bookings'] = Contracts::bookingsUsing( (int) $c['id'] );
			$rows[]        = $c;
		}
		list( $by, $order ) = $this->sorting( 'name' );
		usort(
			$rows,
			static function ( $a, $b ) use ( $by, $order ) {
				$cmp = 'updated' === $by ? strcmp( $a['updated_at'], $b['updated_at'] ) : strcasecmp( $a['name'], $b['name'] );
				return 'desc' === $order ? -$cmp : $cmp;
			}
		);
		$this->finishItems( array_slice( $rows, $this->offset(), self::PER_PAGE ), count( $rows ) );
	}

	protected function get_views() {
		$counts = array_count_values( array_column( self::allRows(), 'status' ) );
		$views  = array();
		foreach ( self::TABS as $key => $tab ) {
			$views[ $key ] = array( $tab[0], (int) ( $counts[ $tab[1][0] ] ?? 0 ) );
		}
		return $this->viewLinks( $views );
	}

	protected function get_bulk_actions() {
		if ( 'trash' === $this->view() ) {
			return array( 'restore' => 'Restaurar', 'purge' => 'Eliminar permanentemente' );
		}
		return 'archived' === $this->view()
			? array( 'unarchive' => 'Quitar de archivadas', 'trash' => 'Mover a la papelera' )
			: array( 'archive' => 'Archivar', 'trash' => 'Mover a la papelera' );
	}

	protected function actionLabels(): array {
		return array(
			'archive'   => 'Plantillas archivadas',
			'unarchive' => 'Plantillas quitadas de archivadas',
			'trash'     => 'Plantillas enviadas a la papelera',
			'restore'   => 'Plantillas restauradas',
			'purge'     => 'Plantillas eliminadas permanentemente',
		);
	}

	protected function act( string $action, int $id, array $request ): string {
		switch ( $action ) {
			case 'archive':
			case 'unarchive':
				Contracts::setArchived( $id, 'archive' === $action );
				return '';
			case 'trash':
				Trash::trashContract( $id );
				return 'Las reservas conservan su copia exacta del contrato aceptado.';
			case 'restore':
				Trash::restoreContract( $id );
				return '';
			case 'purge':
				$this->requireTypedConfirmation( $request );
				Trash::purgeContract( $id );
				return 'Las reservas conservan su copia exacta del contrato aceptado.';
		}
		return '';
	}

	public function column_name( $c ) {
		$url  = Ui::url( self::page(), array( 'contract' => $c['id'] ) );
		$html = '<strong><a class="row-title" href="' . esc_url( $url ) . '">' . esc_html( $c['name'] ) . '</a></strong>';
		$id   = (int) $c['id'];
		if ( 'trash' === $c['status'] ) {
			$actions = array(
				'restore' => '<a href="' . esc_url( $this->actionUrl( 'restore', $id ) ) . '">Restaurar</a>',
				'delete'  => '<a class="submitdelete" data-typed-confirm="Se borrará la plantilla y sus versiones (las reservas conservan su copia). Escribe ELIMINAR para confirmar." href="' . esc_url( $this->actionUrl( 'purge', $id ) ) . '">Eliminar permanentemente</a>',
			);
		} else {
			$actions = array( 'edit' => '<a href="' . esc_url( $url ) . '">Editar</a>' );
			$actions += 'archived' === $c['status']
				? array( 'unarchive' => '<a href="' . esc_url( $this->actionUrl( 'unarchive', $id ) ) . '">Quitar de archivadas</a>' )
				: array( 'archive' => '<a href="' . esc_url( $this->actionUrl( 'archive', $id ) ) . '">Archivar</a>' );
			$actions['trash'] = '<a class="submitdelete" href="' . esc_url( $this->actionUrl( 'trash', $id ) ) . '">Papelera</a>';
		}
		return $html . $this->row_actions( $actions );
	}

	public function column_default( $c, $column ) {
		switch ( $column ) {
			case 'version':
				return (int) $c['current_version'] ? 'Versión ' . (int) $c['current_version'] : '<em>Sin publicar</em>';
			case 'services':
				return esc_html( $c['services'] ? implode( ', ', $c['services'] ) : '—' );
			case 'bookings':
				return (int) $c['bookings'];
			case 'updated':
				return esc_html( Ui::localDateTime( $c['updated_at'], 'd/m/Y H:i' ) );
		}
		return '';
	}
}
