<?php
namespace MOBookingStudio\Infrastructure;

/**
 * Integración con las herramientas de privacidad de WordPress (Herramientas > Exportar /
 * Borrar datos personales). El borrado anonimiza contacto y respuestas, pero conserva
 * importes, fechas y movimientos porque son registros contables del negocio.
 */
final class Privacy {
	public static function registerExporter( array $exporters ): array {
		$exporters['mo-booking-studio'] = array(
			'exporter_friendly_name' => 'MO Booking Studio',
			'callback'               => array( self::class, 'export' ),
		);
		return $exporters;
	}

	public static function registerEraser( array $erasers ): array {
		$erasers['mo-booking-studio'] = array(
			'eraser_friendly_name' => 'MO Booking Studio',
			'callback'             => array( self::class, 'erase' ),
		);
		return $erasers;
	}

	private static function clientIds( string $email ): array {
		$wpdb = Db::wpdb();
		$norm = \MOBookingStudio\Domain\Contact::normalizeEmail( $email );
		if ( ! $norm ) {
			return array();
		}
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'clients' ) . ' WHERE email_norm = %s', $norm ) ) ?: array() ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function export( string $email, int $page = 1 ): array {
		$wpdb  = Db::wpdb();
		$items = array();
		foreach ( self::clientIds( $email ) as $clientId ) {
			$client  = $wpdb->get_row( $wpdb->prepare( 'SELECT name, email, phone, created_at FROM ' . Schema::table( 'clients' ) . ' WHERE id = %d', $clientId ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$items[] = array(
				'group_id'    => 'mo-booking-client',
				'group_label' => 'Expediente de cliente',
				'item_id'     => 'mo-client-' . $clientId,
				'data'        => array(
					array( 'name' => 'Nombre', 'value' => $client['name'] ),
					array( 'name' => 'Correo', 'value' => $client['email'] ),
					array( 'name' => 'Teléfono', 'value' => $client['phone'] ),
					array( 'name' => 'Alta', 'value' => $client['created_at'] ),
				),
			);
			$bookings = $wpdb->get_results( $wpdb->prepare( 'SELECT public_ref, status, start_utc, total_cents, answers FROM ' . Schema::table( 'bookings' ) . ' WHERE client_id = %d', $clientId ), ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( $bookings as $b ) {
				$items[] = array(
					'group_id'    => 'mo-booking-bookings',
					'group_label' => 'Reservas',
					'item_id'     => 'mo-booking-' . $b['public_ref'],
					'data'        => array(
						array( 'name' => 'Reserva', 'value' => $b['public_ref'] ),
						array( 'name' => 'Estado', 'value' => \MOBookingStudio\Domain\BookingStatus::label( $b['status'] ) ),
						array( 'name' => 'Fecha (UTC)', 'value' => $b['start_utc'] ),
						array( 'name' => 'Total', 'value' => \MOBookingStudio\Domain\Money::format( (int) $b['total_cents'] ) ),
						array( 'name' => 'Respuestas', 'value' => (string) $b['answers'] ),
					),
				);
			}
		}
		return array( 'data' => $items, 'done' => true );
	}

	public static function erase( string $email, int $page = 1 ): array {
		$wpdb    = Db::wpdb();
		$removed = false;
		foreach ( self::clientIds( $email ) as $clientId ) {
			$anon = 'Anonimizado ' . $clientId;
			$wpdb->update(
				Schema::table( 'clients' ),
				array( 'name' => $anon, 'email' => '', 'email_norm' => null, 'phone' => '', 'phone_e164' => null, 'notes' => '' ),
				array( 'id' => $clientId )
			);
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table( 'bookings' ) . ' SET contact = %s, answers = %s WHERE client_id = %d', Db::json( array( 'name' => $anon ) ), '[]', $clientId ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			Audit::log( 'client', $clientId, 'anonymized' );
			$removed = true;
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => $removed,
			'messages'       => $removed ? array( 'Se conservaron fechas, importes y movimientos de pago como registros del negocio.' ) : array(),
			'done'           => true,
		);
	}
}
