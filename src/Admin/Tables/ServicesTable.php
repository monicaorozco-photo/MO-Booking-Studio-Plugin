<?php
namespace MOBookingStudio\Admin\Tables;

use MOBookingStudio\Admin\Ui;
use MOBookingStudio\Application\Services;
use MOBookingStudio\Application\Trash;
use MOBookingStudio\Domain\Names;
use MOBookingStudio\Infrastructure\Capabilities as Cap;
use MOBookingStudio\Infrastructure\Db;
use MOBookingStudio\Infrastructure\Schema;

final class ServicesTable extends BaseTable {
	public function __construct() {
		parent::__construct( 'servicio', 'services' );
	}

	public static function page(): string {
		return 'mo-booking-services';
	}

	public static function capability(): string {
		return Cap::SETTINGS;
	}

	private const TABS = array(
		''          => array( 'Todos', array( 'draft', 'published', 'inactive' ) ),
		'published' => array( 'Activos', array( 'published' ) ),
		'draft'     => array( 'Borradores', array( 'draft' ) ),
		'inactive'  => array( 'Desactivados', array( 'inactive' ) ),
		'archived'  => array( 'Archivados', array( 'archived' ) ),
		'trash'     => array( 'Papelera', array( 'trash' ) ),
	);

	public function get_columns() {
		return array(
			'cb'        => '<input type="checkbox">',
			'title'     => 'Servicio',
			'status'    => 'Estado',
			'price'     => 'Precio base',
			'duration'  => 'Duración',
			'bookings'  => 'Reservas',
			'shortcode' => 'Shortcode',
		);
	}

	protected function get_sortable_columns() {
		return array( 'title' => array( 'title', false ), 'bookings' => array( 'bookings', true ) );
	}

	protected function get_primary_column_name() {
		return 'title';
	}

	public function prepare_items() {
		$view     = isset( self::TABS[ $this->view() ] ) ? $this->view() : '';
		$statuses = self::TABS[ $view ][1];
		$term     = Names::key( $this->search() );
		$rows     = array();
		foreach ( self::allWithTrash() as $s ) {
			if ( ! in_array( $s['status'], $statuses, true ) ) {
				continue;
			}
			if ( '' !== $term && false === strpos( Names::key( $s['title'] . ' ' . $s['brand'] . ' ' . $s['slug'] ), $term ) ) {
				continue;
			}
			$s['bookings'] = Services::usageCount( (int) $s['id'] );
			$rows[]        = $s;
		}
		list( $by, $order ) = $this->sorting( 'title' );
		usort(
			$rows,
			static function ( $a, $b ) use ( $by, $order ) {
				$cmp = 'bookings' === $by ? $a['bookings'] <=> $b['bookings'] : strcasecmp( $a['title'], $b['title'] );
				return 'desc' === $order ? -$cmp : $cmp;
			}
		);
		$this->finishItems( array_slice( $rows, $this->offset(), self::PER_PAGE ), count( $rows ) );
	}

	/** Incluye la papelera (Services::all() la oculta). */
	private static function allWithTrash(): array {
		$rows = Db::wpdb()->get_results( 'SELECT * FROM ' . Schema::table( 'services' ) . ' ORDER BY title', ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( array( Services::class, 'hydrate' ), $rows );
	}

	protected function get_views() {
		$counts = array_count_values( array_column( self::allWithTrash(), 'status' ) );
		$views  = array();
		foreach ( self::TABS as $key => $tab ) {
			$n = 0;
			foreach ( $tab[1] as $status ) {
				$n += $counts[ $status ] ?? 0;
			}
			$views[ $key ] = array( $tab[0], $n );
		}
		return $this->viewLinks( $views );
	}

	protected function get_bulk_actions() {
		if ( 'trash' === $this->view() ) {
			return array( 'restore' => 'Restaurar', 'purge' => 'Eliminar permanentemente' );
		}
		return array( 'publish' => 'Activar', 'inactive' => 'Desactivar', 'archive' => 'Archivar', 'trash' => 'Mover a la papelera' );
	}

	protected function actionLabels(): array {
		return array(
			'publish'  => 'Servicios activados',
			'duplicate' => 'Servicios duplicados como borrador',
			'inactive' => 'Servicios desactivados',
			'archive'  => 'Servicios archivados',
			'unarchive' => 'Servicios restaurados de archivados',
			'trash'    => 'Servicios enviados a la papelera',
			'restore'  => 'Servicios restaurados',
			'purge'    => 'Servicios eliminados permanentemente',
		);
	}

	protected function act( string $action, int $id, array $request ): string {
		switch ( $action ) {
			case 'publish':
				Services::setStatus( $id, 'published' );
				return '';
			case 'duplicate':
				Services::duplicate( $id );
				return 'La copia aparece en Borradores.';
			case 'inactive':
			case 'unarchive':
				Services::setStatus( $id, 'inactive' );
				return '';
			case 'archive':
				Services::setStatus( $id, 'archived' );
				return '';
			case 'trash':
				Trash::trashService( $id );
				return 'Ya no se ofrecen; las reservas existentes no cambian.';
			case 'restore':
				Trash::restoreService( $id );
				return 'Regresan desactivados (o en borrador) para que los revises antes de activarlos.';
			case 'purge':
				$this->requireTypedConfirmation( $request );
				Trash::purgeService( $id );
				return '';
		}
		return '';
	}

	public function column_title( $s ) {
		$url  = Ui::url( self::page(), array( 'service' => $s['id'] ) );
		$html = '<strong><a class="row-title" href="' . esc_url( $url ) . '">' . esc_html( $s['title'] ) . '</a></strong>' . ( $s['is_test'] ? ' <span class="mo-pill mo-pill--test">PRUEBA</span>' : '' ) .
			( $s['brand'] ? '<br><span class="description">' . esc_html( $s['brand'] ) . '</span>' : '' ) . ( empty( $s['contract_id'] ) ? '<br><span class="mo-pill mo-pill--review">Sin contrato</span>' : '' );
		$id = (int) $s['id'];
		if ( 'trash' === $s['status'] ) {
			$actions = array( 'restore' => '<a href="' . esc_url( $this->actionUrl( 'restore', $id ) ) . '">Restaurar</a>' );
			if ( ! $s['bookings'] ) {
				$actions['delete'] = '<a class="submitdelete" data-typed-confirm="Se borrará el servicio con sus tarifas y reglas. Escribe ELIMINAR para confirmar." href="' . esc_url( $this->actionUrl( 'purge', $id ) ) . '">Eliminar permanentemente</a>';
			}
			return $html . $this->row_actions( $actions );
		}
		$actions = array(
			'edit'      => '<a href="' . esc_url( $url ) . '">Editar</a>',
			'duplicate' => '<a href="' . esc_url( $this->actionUrl( 'duplicate', $id ) ) . '">Duplicar</a>',
			'rates'     => '<a href="' . esc_url( Ui::url( 'mo-booking-rates', array( 'service' => $id ) ) ) . '">Tarifas</a>',
		);
		if ( 'published' === $s['status'] ) {
			$actions['inactive'] = '<a href="' . esc_url( $this->actionUrl( 'inactive', $id ) ) . '" data-confirm="¿Desactivar? Deja de aceptar reservas nuevas; las existentes no cambian.">Desactivar</a>';
		} elseif ( 'archived' === $s['status'] ) {
			$actions['unarchive'] = '<a href="' . esc_url( $this->actionUrl( 'unarchive', $id ) ) . '">Quitar de archivados</a>';
		} else {
			$actions['publish'] = '<a href="' . esc_url( $this->actionUrl( 'publish', $id ) ) . '">Activar</a>';
		}
		$actions['trash'] = '<a class="submitdelete" href="' . esc_url( $this->actionUrl( 'trash', $id ) ) . '">Papelera</a>';
		return $html . $this->row_actions( $actions );
	}

	public function column_default( $s, $column ) {
		switch ( $column ) {
			case 'status':
				return esc_html( Services::STATUS_LABELS[ $s['status'] ] ?? $s['status'] );
			case 'price':
				return Ui::money( (int) $s['price_cents'] );
			case 'duration':
				return (int) $s['duration'] . ' min';
			case 'bookings':
				return $s['bookings'] ? '<a href="' . esc_url( Ui::url( 'mo-booking-bookings', array( 'service_id' => $s['id'], 'view' => 'all' ) ) ) . '">' . (int) $s['bookings'] . '</a>' : '0';
			case 'shortcode':
				return '<code>[mo_booking service="' . esc_html( $s['slug'] ) . '"]</code>';
		}
		return '';
	}
}
