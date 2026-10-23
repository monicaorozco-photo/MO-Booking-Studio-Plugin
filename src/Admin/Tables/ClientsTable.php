<?php
namespace MOBookingStudio\Admin\Tables;

use MOBookingStudio\Admin\Ui;
use MOBookingStudio\Application\Clients;
use MOBookingStudio\Application\Trash;
use MOBookingStudio\Infrastructure\Capabilities as Cap;
use MOBookingStudio\Infrastructure\Db;
use MOBookingStudio\Infrastructure\Schema;

final class ClientsTable extends BaseTable {
	public function __construct() {
		parent::__construct( 'cliente', 'clients' );
	}

	public static function page(): string {
		return 'mo-booking-clients';
	}

	public static function capability(): string {
		return Cap::CLIENTS;
	}

	protected function filterKeys(): array {
		return array( 'category' );
	}

	public function get_columns() {
		return array(
			'cb'       => '<input type="checkbox">',
			'name'     => 'Nombre',
			'email'    => 'Correo',
			'phone'    => 'Teléfono',
			'sessions' => 'Sesiones',
			'category' => 'Categoría',
			'bookings' => 'Reservas',
		);
	}

	protected function get_sortable_columns() {
		return array(
			'name'     => array( 'name', false ),
			'sessions' => array( 'sessions', true ),
			'bookings' => array( 'bookings', true ),
		);
	}

	protected function get_primary_column_name() {
		return 'name';
	}

	/** Expresión SQL de la categoría efectiva (manual o por umbral). */
	private static function frequentSql(): string {
		return "(c.manual_category = 'frequent' OR (c.manual_category IS NULL AND c.historic_sessions + c.completed_sessions >= " . (int) Clients::frequentThreshold() . '))';
	}

	private function where( string $view ): array {
		$wpdb   = Db::wpdb();
		$where  = array( 'trash' === $view ? 'c.trashed_at IS NOT NULL' : 'c.trashed_at IS NULL' );
		$params = array();
		if ( 'archived' === $view ) {
			$where[] = 'c.archived_at IS NOT NULL';
		} elseif ( 'trash' !== $view ) {
			$where[] = 'c.archived_at IS NULL';
		}
		if ( 'review' === $view ) {
			$where[] = 'c.needs_review = 1';
		}
		$category = sanitize_key( (string) ( $this->request()['category'] ?? '' ) );
		if ( 'frequent' === $category ) {
			$where[] = self::frequentSql();
		} elseif ( 'general' === $category ) {
			$where[] = 'NOT ' . self::frequentSql();
		}
		$term = $this->search();
		if ( '' !== $term ) {
			$like     = '%' . $wpdb->esc_like( $term ) . '%';
			$where[]  = '(c.name LIKE %s OR c.email LIKE %s OR c.phone LIKE %s OR c.id IN (SELECT client_id FROM ' . Schema::table( 'client_contacts' ) . ' WHERE value LIKE %s))';
			$params   = array_merge( $params, array( $like, $like, $like, $like ) );
		}
		return array( implode( ' AND ', $where ), $params );
	}

	private function countRows( string $view ): int {
		$wpdb               = Db::wpdb();
		list( $w, $params ) = $this->where( $view );
		$sql                = 'SELECT COUNT(*) FROM ' . Schema::table( 'clients' ) . " c WHERE $w";
		return (int) $wpdb->get_var( $params ? $wpdb->prepare( $sql, $params ) : $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public function prepare_items() {
		$wpdb                 = Db::wpdb();
		$view                 = $this->view();
		list( $w, $params )   = $this->where( $view );
		list( $by, $order )   = $this->sorting( 'name' );
		$orderSql             = array(
			'name'     => 'c.name',
			'sessions' => '(c.historic_sessions + c.completed_sessions)',
			'bookings' => 'bookings',
		)[ $by ];
		$sql = 'SELECT c.*, (SELECT COUNT(*) FROM ' . Schema::table( 'bookings' ) . ' b WHERE b.client_id = c.id) AS bookings FROM ' . Schema::table( 'clients' ) . " c WHERE $w ORDER BY " . ( '' === $view ? 'c.needs_review DESC, ' : '' ) . "$orderSql " . strtoupper( $order ) . ', c.id LIMIT %d OFFSET %d';
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $params, array( self::PER_PAGE, $this->offset() ) ) ), ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->finishItems( $rows, $this->countRows( $view ) );
	}

	protected function get_views() {
		$wpdb = Db::wpdb();
		$t    = Schema::table( 'clients' );
		$dups = count( Clients::duplicateSuggestions( null, 500 ) );
		$links = $this->viewLinks(
			array(
				''         => array( 'Todos', (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE trashed_at IS NULL AND archived_at IS NULL" ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'review'   => array( 'Por revisar', (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE trashed_at IS NULL AND archived_at IS NULL AND needs_review = 1" ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'archived' => array( 'Archivados', (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE trashed_at IS NULL AND archived_at IS NOT NULL" ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'trash'    => array( 'Papelera', (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE trashed_at IS NOT NULL" ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			)
		);
		$links['duplicates'] = '<a href="' . esc_url( Ui::url( self::page(), array( 'duplicates' => 1 ) ) ) . '">Posibles duplicados <span class="count">(' . (int) $dups . ')</span></a>';
		return $links;
	}

	protected function get_bulk_actions() {
		if ( 'trash' === $this->view() ) {
			return array( 'restore' => 'Restaurar', 'purge' => 'Eliminar permanentemente' );
		}
		if ( 'archived' === $this->view() ) {
			return array( 'unarchive' => 'Quitar de archivados', 'trash' => 'Mover a la papelera' );
		}
		return array( 'archive' => 'Archivar', 'trash' => 'Mover a la papelera' );
	}

	protected function actionLabels(): array {
		return array(
			'archive'   => 'Clientes archivados',
			'unarchive' => 'Clientes quitados de archivados',
			'trash'     => 'Clientes enviados a la papelera',
			'restore'   => 'Clientes restaurados',
			'purge'     => 'Clientes eliminados permanentemente',
		);
	}

	protected function act( string $action, int $id, array $request ): string {
		switch ( $action ) {
			case 'archive':
			case 'unarchive':
				Clients::setArchived( $id, 'archive' === $action );
				return '';
			case 'trash':
				Trash::trashClient( $id );
				return 'Puedes restaurarlos desde la pestaña Papelera.';
			case 'restore':
				Trash::restoreClient( $id );
				return '';
			case 'purge':
				$this->requireTypedConfirmation( $request );
				$client = Clients::find( $id );
				Trash::purgeClient( $id, $client ? (string) $client['name'] : '' );
				return 'Se borraron también sus reservas, pagos, comprobantes y copias de contrato.';
		}
		return '';
	}

	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		echo '<div class="alignleft actions">' . Ui::select( 'category', array( '' => 'Todas las categorías', 'frequent' => 'Frecuentes', 'general' => 'Público general' ), sanitize_key( (string) ( $this->request()['category'] ?? '' ) ), array( 'aria-label' => 'Categoría' ) ) . ' <button class="button" name="filter_action" value="1">Filtrar</button></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public function column_name( $c ) {
		$url  = Ui::url( self::page(), array( 'client' => $c['id'] ) );
		$html = '<strong><a class="row-title" href="' . esc_url( $url ) . '">' . esc_html( $c['name'] ) . '</a></strong>';
		if ( $c['needs_review'] ) {
			$html .= ' <span class="mo-pill mo-pill--review">Revisar</span>';
		}
		if ( $c['is_test'] ) {
			$html .= ' <span class="mo-pill mo-pill--test">PRUEBA</span>';
		}
		if ( ! empty( $c['merged_into'] ) ) {
			$html .= ' <span class="mo-pill">Fusionado</span>';
		}
		$id = (int) $c['id'];
		if ( 'trash' === $this->view() ) {
			$actions = array(
				'restore' => '<a href="' . esc_url( $this->actionUrl( 'restore', $id ) ) . '">Restaurar</a>',
				'delete'  => '<a class="submitdelete" href="' . esc_url( $url . '#eliminar' ) . '">Eliminar permanentemente…</a>',
			);
		} else {
			$actions = array(
				'edit'  => '<a href="' . esc_url( $url ) . '">Editar</a>',
				'merge' => '<a href="' . esc_url( Ui::url( self::page(), array( 'merge' => $id ) ) ) . '">Fusionar</a>',
			);
			$actions += empty( $c['archived_at'] )
				? array( 'archive' => '<a href="' . esc_url( $this->actionUrl( 'archive', $id ) ) . '">Archivar</a>' )
				: array( 'unarchive' => '<a href="' . esc_url( $this->actionUrl( 'unarchive', $id ) ) . '">Quitar de archivados</a>' );
			$actions['trash'] = '<a class="submitdelete" href="' . esc_url( $this->actionUrl( 'trash', $id ) ) . '">Papelera</a>';
		}
		return $html . $this->row_actions( $actions );
	}

	public function column_default( $c, $column ) {
		switch ( $column ) {
			case 'email':
				return esc_html( $c['email'] );
			case 'phone':
				return esc_html( $c['phone'] );
			case 'sessions':
				return (int) Clients::sessions( $c );
			case 'category':
				return ( 'frequent' === Clients::effectiveCategory( $c ) ? 'Frecuente' : 'General' ) . ( $c['manual_category'] ? ' <span class="description">(manual)</span>' : '' );
			case 'bookings':
				return (int) $c['bookings'] ? '<a href="' . esc_url( Ui::url( 'mo-booking-bookings', array( 'client_id' => $c['id'], 'view' => 'all' ) ) ) . '">' . (int) $c['bookings'] . '</a>' : '0';
		}
		return '';
	}
}
