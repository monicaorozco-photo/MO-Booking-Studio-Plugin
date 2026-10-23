<?php
namespace MOBookingStudio\Admin\Tables;

use MOBookingStudio\Admin\Ui;
use MOBookingStudio\Domain\DomainException;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Listas con el formato de las entradas de WordPress: pestañas con conteo, casillas,
 * acciones masivas, acciones por fila, filtros, búsqueda, orden y paginación.
 *
 * Todo viaja por GET en el formulario de la lista (como en Entradas). Las acciones se
 * procesan en el gancho "load-" de la página, antes de imprimir nada: validan capacidad
 * y nonce, y redirigen a la misma lista con un aviso.
 */
abstract class BaseTable extends \WP_List_Table {
	public const PER_PAGE = 20;

	/** Slug de la página del panel. */
	abstract public static function page(): string;

	/** Capacidad necesaria para las acciones de la lista. */
	abstract public static function capability(): string;

	/**
	 * Ejecuta una acción sobre un elemento. Devuelve un texto corto para el aviso o lanza
	 * DomainException con el motivo.
	 */
	abstract protected function act( string $action, int $id, array $request ): string;

	/** Etiquetas de las acciones, para los avisos ("3 clientes enviados a la papelera"). */
	abstract protected function actionLabels(): array;

	public function __construct( string $singular, string $plural ) {
		parent::__construct(
			array(
				'singular' => $singular,
				'plural'   => $plural,
				'ajax'     => false,
				'screen'   => 'mo-booking-' . $plural,
			)
		);
	}

	protected function request(): array {
		return wp_unslash( $_REQUEST ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	protected function view(): string {
		return sanitize_key( (string) ( $this->request()['view'] ?? '' ) );
	}

	protected function search(): string {
		return trim( sanitize_text_field( (string) ( $this->request()['s'] ?? '' ) ) );
	}

	/** @return array{0:string,1:string} columna y dirección permitidas. */
	protected function sorting( string $default, string $defaultOrder = 'asc' ): array {
		$r       = $this->request();
		$allowed = array_keys( $this->get_sortable_columns() );
		$by      = sanitize_key( (string) ( $r['orderby'] ?? '' ) );
		$order   = strtolower( (string) ( $r['order'] ?? $defaultOrder ) ) === 'desc' ? 'desc' : 'asc';
		return array( in_array( $by, $allowed, true ) ? $by : $default, $order );
	}

	/** Enlace de acción por fila (lleva el mismo nonce que las acciones masivas). */
	protected function actionUrl( string $action, int $id ): string {
		return wp_nonce_url(
			add_query_arg( array( 'mo_action' => $action, 'ids' => array( $id ) ), $this->baseUrl() ),
			'bulk-' . $this->_args['plural']
		);
	}

	/** URL de la lista conservando la pestaña, búsqueda, filtros, orden y página. */
	protected function baseUrl(): string {
		$keep = array();
		foreach ( array_merge( array( 'view', 's', 'orderby', 'order', 'paged' ), $this->filterKeys() ) as $k ) {
			$v = $this->request()[ $k ] ?? '';
			if ( '' !== $v && null !== $v ) {
				$keep[ $k ] = sanitize_text_field( (string) $v );
			}
		}
		return Ui::url( static::page(), $keep );
	}

	/** Parámetros de filtro propios de cada lista (se conservan al actuar). */
	protected function filterKeys(): array {
		return array();
	}

	/** Pestañas tipo "Todos (12) | Papelera (2)". */
	protected function viewLinks( array $views ): array {
		$current = $this->view();
		$links   = array();
		foreach ( $views as $key => $v ) {
			$url           = Ui::url( static::page(), '' === $key ? array() : array( 'view' => $key ) );
			$links[ $key ] = '<a href="' . esc_url( $url ) . '"' . ( $current === $key ? ' class="current" aria-current="page"' : '' ) . '>' . esc_html( $v[0] ) . ' <span class="count">(' . (int) $v[1] . ')</span></a>';
		}
		return $links;
	}

	public function column_cb( $item ) {
		return '<label class="screen-reader-text" for="cb-' . (int) $item['id'] . '">Seleccionar</label><input type="checkbox" id="cb-' . (int) $item['id'] . '" name="ids[]" value="' . (int) $item['id'] . '">';
	}

	protected function finishItems( array $items, int $total ): void {
		$this->items           = $items;
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), $this->get_primary_column_name() );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => self::PER_PAGE,
				'total_pages' => (int) ceil( $total / self::PER_PAGE ),
			)
		);
	}

	protected function offset(): int {
		return ( max( 1, $this->get_pagenum() ) - 1 ) * self::PER_PAGE;
	}

	/** Lista completa: pestañas, búsqueda, tabla y el formulario GET que las une. */
	public function render( string $searchLabel ): void {
		$this->prepare_items();
		$this->views();
		echo '<form method="get" class="mo-list-form">';
		echo '<input type="hidden" name="page" value="' . esc_attr( static::page() ) . '">';
		if ( '' !== $this->view() ) {
			echo '<input type="hidden" name="view" value="' . esc_attr( $this->view() ) . '">';
		}
		$this->search_box( $searchLabel, $this->_args['plural'] );
		$this->display();
		echo '</form>';
	}

	/**
	 * Procesa acciones masivas o de fila en el gancho "load-" de la página. Cada elemento se
	 * intenta por separado: un error no detiene a los demás y se informa al final.
	 */
	public static function process(): void {
		$r      = wp_unslash( $_REQUEST ); // phpcs:ignore WordPress.Security.NonceVerification
		$table  = new static();
		$action = ! empty( $r['mo_action'] ) ? sanitize_key( (string) $r['mo_action'] ) : (string) $table->current_action();
		if ( '' === $action || '-1' === $action ) {
			return;
		}
		$ids = array_values( array_filter( array_map( 'intval', (array) ( $r['ids'] ?? array() ) ) ) );
		if ( ! $ids ) {
			return;
		}
		if ( ! current_user_can( static::capability() ) ) {
			wp_die( 'No tienes permiso para esta acción.', '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'bulk-' . $table->_args['plural'] );
		$labels = $table->actionLabels();
		if ( ! isset( $labels[ $action ] ) ) {
			wp_die( 'Acción no válida.', '', array( 'response' => 400 ) );
		}
		$done   = 0;
		$errors = array();
		$notes  = array();
		foreach ( $ids as $id ) {
			try {
				$note = $table->act( $action, $id, $r );
				++$done;
				if ( '' !== $note ) {
					$notes[] = $note;
				}
			} catch ( DomainException $e ) {
				$errors[] = $e->getMessage();
			}
		}
		if ( $done ) {
			Ui::flash( 'success', sprintf( '%s: %d.', $labels[ $action ], $done ) . ( $notes ? ' ' . implode( ' ', array_unique( $notes ) ) : '' ) );
		}
		foreach ( array_unique( $errors ) as $message ) {
			Ui::flash( 'error', $message );
		}
		wp_safe_redirect( remove_query_arg( array( 'mo_action', 'ids', 'action', 'action2', '_wpnonce', '_wp_http_referer', 'confirm_text' ), $table->baseUrl() ) );
		exit;
	}

	/** Confirmación escrita para acciones masivas sin vuelta atrás. */
	protected function requireTypedConfirmation( array $request ): void {
		if ( 'ELIMINAR' !== strtoupper( trim( (string) ( $request['confirm_text'] ?? '' ) ) ) ) {
			throw new DomainException( 'confirm_required', 'Para eliminar definitivamente escribe ELIMINAR en la confirmación.' );
		}
	}

	public function no_items() {
		echo 'trash' === $this->view() ? 'La papelera está vacía.' : 'No hay resultados con estos filtros.';
	}
}
