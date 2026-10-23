<?php
namespace MOBookingStudio\Admin\Tables;

use MOBookingStudio\Admin\Ui;
use MOBookingStudio\Application\Bookings;
use MOBookingStudio\Application\FrequentCheck;
use MOBookingStudio\Application\Services;
use MOBookingStudio\Application\Trash;
use MOBookingStudio\Domain\BookingStatus;
use MOBookingStudio\Domain\Ledger;
use MOBookingStudio\Infrastructure\Capabilities as Cap;

final class BookingsTable extends BaseTable {
	private const SYNC = array( 'none' => '—', 'pending' => 'Pendiente', 'retrying' => 'Reintentando', 'synced' => 'Sincronizada', 'error' => 'Error' );

	public function __construct() {
		parent::__construct( 'reserva', 'bookings' );
	}

	public static function page(): string {
		return 'mo-booking-bookings';
	}

	public static function capability(): string {
		return Cap::BOOKINGS;
	}

	protected function filterKeys(): array {
		return array( 'status', 'service_id', 'pay', 'conflict', 'from', 'to', 'client_id', 'frequent' );
	}

	/** Pestañas: clave => [etiqueta, filtros]. */
	private function tabs(): array {
		return array(
			''          => array( 'Vigentes', array( 'status' => BookingStatus::active(), 'archived' => false ) ),
			'payment'   => array( 'Pago por revisar', array( 'status' => BookingStatus::awaitingPayment(), 'archived' => false ) ),
			'confirmed' => array( 'Confirmadas', array( 'status' => array( BookingStatus::CONFIRMED ), 'archived' => false ) ),
			'past'      => array( 'Completadas', array( 'status' => array( BookingStatus::COMPLETED, BookingStatus::NO_SHOW ), 'archived' => false ) ),
			'closed'    => array( 'Canceladas y vencidas', array( 'status' => array( BookingStatus::CANCELLED, BookingStatus::REJECTED, BookingStatus::EXPIRED ), 'archived' => false ) ),
			'all'       => array( 'Todas', array() ),
			'archived'  => array( 'Archivadas', array( 'archived' => true ) ),
			'trash'     => array( 'Papelera', array( 'trash' => true ) ),
		);
	}

	private function filters(): array {
		$r    = $this->request();
		$tabs = $this->tabs();
		$view = isset( $tabs[ $this->view() ] ) ? $this->view() : '';
		$f    = $tabs[ $view ][1];
		// Compatibilidad con enlaces anteriores: ?status=active&archived=1.
		$status = sanitize_key( (string) ( $r['status'] ?? '' ) );
		if ( 'active' === $status ) {
			$f['status'] = BookingStatus::active();
		} elseif ( isset( BookingStatus::LABELS[ $status ] ) ) {
			$f['status'] = array( $status );
		}
		if ( isset( $r['archived'] ) && '' === $this->view() ) {
			$f['archived'] = '1' === (string) $r['archived'];
		}
		foreach ( array( 'service_id', 'client_id' ) as $k ) {
			if ( ! empty( $r[ $k ] ) ) {
				$f[ $k ] = (int) $r[ $k ];
			}
		}
		foreach ( array( 'from', 'to' ) as $k ) {
			if ( ! empty( $r[ $k ] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $r[ $k ] ) ) {
				$f[ $k ] = (string) $r[ $k ];
			}
		}
		if ( ! empty( $r['conflict'] ) ) {
			$f['conflict'] = true;
		}
		if ( ! empty( $r['frequent'] ) ) {
			$f['frequent_auto'] = true;
		}
		if ( '' !== $this->search() ) {
			$f['search'] = $this->search();
		}
		return $f;
	}

	public function get_columns() {
		return array(
			'cb'       => '<input type="checkbox">',
			'ref'      => 'Reserva',
			'client'   => 'Cliente',
			'service'  => 'Servicio',
			'start'    => 'Fecha y hora',
			'status'   => 'Estado',
			'deposit'  => 'Anticipo',
			'total'    => 'Total y saldo',
			'calendar' => 'Calendar',
		);
	}

	protected function get_sortable_columns() {
		return array(
			'ref'     => array( 'ref', false ),
			'client'  => array( 'client', false ),
			'start'   => array( 'start', false ),
			'total'   => array( 'total', true ),
			'created' => array( 'created', true ),
		);
	}

	protected function get_primary_column_name() {
		return 'ref';
	}

	public function prepare_items() {
		$filters                = $this->filters();
		list( $by, $order )     = $this->sorting( 'start', '' === $this->view() || 'payment' === $this->view() || 'confirmed' === $this->view() ? 'asc' : 'desc' );
		$filters['order_by']    = $by;
		$filters['order']       = $order;
		$pay                    = sanitize_key( (string) ( $this->request()['pay'] ?? '' ) );
		if ( '' !== $pay ) {
			// La situación del pago se calcula de los movimientos: se filtra aquí y se pagina en memoria.
			$rows = array();
			foreach ( Bookings::list( $filters + array( 'limit' => 500 ) ) as $b ) {
				$b['summary'] = Bookings::summary( $b );
				if ( $b['summary']['status'] === $pay ) {
					$rows[] = $b;
				}
			}
			$this->finishItems( array_slice( $rows, $this->offset(), self::PER_PAGE ), count( $rows ) );
			return;
		}
		$rows = Bookings::list( $filters + array( 'limit' => self::PER_PAGE, 'offset' => $this->offset() ) );
		foreach ( $rows as &$b ) {
			$b['summary'] = Bookings::summary( $b );
		}
		unset( $b );
		$this->finishItems( $rows, Bookings::count( $filters ) );
	}

	protected function get_views() {
		$views = array();
		foreach ( $this->tabs() as $key => $tab ) {
			$views[ $key ] = array( $tab[0], Bookings::count( $tab[1] ) );
		}
		return $this->viewLinks( $views );
	}

	protected function get_bulk_actions() {
		if ( 'trash' === $this->view() ) {
			return array( 'restore' => 'Restaurar', 'purge' => 'Eliminar permanentemente' );
		}
		return array( 'archive' => 'Archivar', 'unarchive' => 'Quitar de archivadas', 'trash' => 'Mover a la papelera' );
	}

	protected function actionLabels(): array {
		return array(
			'archive'   => 'Reservas archivadas',
			'unarchive' => 'Reservas quitadas de archivadas',
			'trash'     => 'Reservas enviadas a la papelera',
			'restore'   => 'Reservas restauradas',
			'purge'     => 'Reservas eliminadas permanentemente',
		);
	}

	protected function act( string $action, int $id, array $request ): string {
		switch ( $action ) {
			case 'archive':
			case 'unarchive':
				Bookings::setArchived( $id, 'archive' === $action );
				return '';
			case 'trash':
				Trash::trashBooking( $id );
				return 'Puedes restaurarlas desde la pestaña Papelera.';
			case 'restore':
				Trash::restoreBooking( $id );
				return '';
			case 'purge':
				$this->requireTypedConfirmation( $request );
				Trash::purgeBooking( $id );
				return '';
		}
		return '';
	}

	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		$r        = $this->request();
		$services = array( '' => 'Todos los servicios' );
		foreach ( Services::all() as $s ) {
			$services[ $s['id'] ] = $s['title'];
		}
		$status = array( '' => 'Todos los estados' ) + BookingStatus::LABELS;
		echo '<div class="alignleft actions mo-list-filters">' . // phpcs:ignore WordPress.Security.EscapeOutput
			Ui::select( 'status', $status, sanitize_key( (string) ( $r['status'] ?? '' ) ), array( 'aria-label' => 'Estado' ) ) . ' ' .
			Ui::select( 'service_id', $services, (string) ( $r['service_id'] ?? '' ), array( 'aria-label' => 'Servicio' ) ) . ' ' .
			Ui::select( 'pay', array( '' => 'Todos los pagos' ) + Ledger::LABELS, sanitize_key( (string) ( $r['pay'] ?? '' ) ), array( 'aria-label' => 'Pago' ) ) . ' ' .
			'<label>Desde ' . Ui::input( 'from', (string) ( $r['from'] ?? '' ), 'date' ) . '</label> <label>Hasta ' . Ui::input( 'to', (string) ( $r['to'] ?? '' ), 'date' ) . '</label> ' .
			Ui::checkbox( 'conflict', ! empty( $r['conflict'] ), 'Con empalme' ) . ' ' .
			Ui::checkbox( 'frequent', ! empty( $r['frequent'] ), 'Frecuente automático' ) .
			( ! empty( $r['client_id'] ) ? '<input type="hidden" name="client_id" value="' . (int) $r['client_id'] . '">' : '' ) .
			' <button class="button" name="filter_action" value="1">Filtrar</button></div>';
	}

	public function column_ref( $b ) {
		$url  = Ui::url( self::page(), array( 'booking' => $b['id'] ) );
		$html = '<strong><a class="row-title" href="' . esc_url( $url ) . '">' . esc_html( $b['public_ref'] ) . '</a></strong>' .
			( $b['is_test'] ? ' <span class="mo-pill mo-pill--test">PRUEBA</span>' : '' ) . ( $b['archived'] ? ' <span class="mo-pill">Archivada</span>' : '' );
		$id = (int) $b['id'];
		if ( 'trash' === $this->view() ) {
			$actions = array(
				'restore' => '<a href="' . esc_url( $this->actionUrl( 'restore', $id ) ) . '">Restaurar</a>',
				'delete'  => '<a class="submitdelete" data-typed-confirm="Se borrará la reserva con sus pagos y comprobantes. Escribe ELIMINAR para confirmar." href="' . esc_url( $this->actionUrl( 'purge', $id ) ) . '">Eliminar permanentemente</a>',
			);
		} else {
			$actions = array(
				'view' => '<a href="' . esc_url( $url ) . '">Ver</a>',
				'edit' => '<a href="' . esc_url( add_query_arg( 'edit', '1', $url ) . '#editar' ) . '">Editar</a>',
			);
			$actions += $b['archived']
				? array( 'unarchive' => '<a href="' . esc_url( $this->actionUrl( 'unarchive', $id ) ) . '">Quitar de archivadas</a>' )
				: array( 'archive' => '<a href="' . esc_url( $this->actionUrl( 'archive', $id ) ) . '">Archivar</a>' );
			if ( ! BookingStatus::isOpen( $b['status'] ) ) {
				$actions['trash'] = '<a class="submitdelete" href="' . esc_url( $this->actionUrl( 'trash', $id ) ) . '">Papelera</a>';
			}
		}
		return $html . $this->row_actions( $actions );
	}

	public function column_default( $b, $column ) {
		$summary = $b['summary'];
		switch ( $column ) {
			case 'client':
				$html = esc_html( (string) $b['client_name'] );
				if ( FrequentCheck::MATCH_AUTO === $b['frequent_match'] && 'frequent' === $b['client_category'] ) {
					$html .= ' <span class="mo-pill mo-pill--auto">Frecuente reconocido por nombre y contacto</span>';
				} elseif ( $b['frequent_claim'] && 'frequent' !== $b['client_category'] ) {
					$html .= ' <span class="mo-pill mo-pill--review">Frecuente por revisar</span>';
				}
				return $html;
			case 'service':
				return esc_html( (string) $b['service_title'] );
			case 'start':
				return esc_html( Ui::localDateTime( $b['start_utc'], 'D d/m/Y H:i' ) );
			case 'status':
				return Ui::statusPill( $b['status'] ) . ( 'open' === $b['conflict_state'] ? ' <span class="mo-pill mo-pill--review">Empalme</span>' : '' );
			case 'deposit':
				return Ui::financialPill( $summary['status'] );
			case 'total':
				return Ui::money( $b['total_cents'] ) . '<br><span class="description">Saldo ' . Ui::money( $summary['balance_cents'] ) . '</span>';
			case 'calendar':
				return esc_html( self::SYNC[ $b['sync_state'] ] ?? $b['sync_state'] );
		}
		return '';
	}
}
