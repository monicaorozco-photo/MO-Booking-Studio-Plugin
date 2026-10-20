<?php
namespace MOBookingStudio\Application;

use MOBookingStudio\Infrastructure\Db;
use MOBookingStudio\Infrastructure\PrivateStorage;
use MOBookingStudio\Infrastructure\Schema;

/**
 * Datos de prueba opcionales: el ejemplo de minis navideñas 2026 de la especificación.
 * Todo queda marcado como prueba (is_test) y en una agenda propia, de modo que no ocupa
 * horarios reales ni envía mensajes. Nunca se cargan al activar el plugin.
 */
final class TestData {
	public const RESOURCE_NAME = 'Agenda de PRUEBA';

	public static function exists(): bool {
		$wpdb = Db::wpdb();
		return (bool) $wpdb->get_var( 'SELECT id FROM ' . Schema::table( 'services' ) . ' WHERE is_test = 1 LIMIT 1' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** @return int Espacios generados. */
	public static function load(): int {
		self::clear();
		$wpdb = Db::wpdb();
		$wpdb->insert( Schema::table( 'resources' ), array( 'name' => self::RESOURCE_NAME, 'active' => 1, 'is_test' => 1, 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
		$resource = (int) $wpdb->insert_id;
		$category = Services::saveCategory( 'Minis (PRUEBA)', null, true );

		$serviceId = Services::save(
			array(
				'title'                      => 'PRUEBA – Minis navideñas 2026',
				'slug'                       => 'prueba-minis-navidenas-2026',
				'brand'                      => 'Clics con Amor Fotografía',
				'status'                     => 'published',
				'resource_id'                => $resource,
				'category_id'                => $category,
				'description'                => 'Incluye aproximadamente 30 fotografías o más, previamente seleccionadas y editadas con ajustes de color, iluminación y encuadre, según el desarrollo de la sesión.',
				'delivery_text'              => 'Entrega de 5 a 7 días hábiles.',
				'duration'                   => 20,
				'buffer_after'               => 10,
				'interval'                   => 30,
				'min_notice_hours'           => 0,
				'horizon_days'               => 0,
				'price_cents'                => 180000,
				'participants_included'      => 6,
				'participants_max'           => 10,
				'extra_participant_cents'    => 10000,
				'pets_allowed'               => true,
				'pets_count_as_participants' => false,
				'deposit_type'               => 'fixed',
				'deposit_value'              => 50000,
				'confirmation_mode'          => 'manual',
				'price_table'                => array(
					'presale_until' => '2026-10-31 23:59',
					'rows'          => array(
						array( 'from' => '2026-11-15', 'to' => '2026-12-04', 'label' => '15 nov – 4 dic', 'presale_general' => 140000, 'presale_frequent' => 120000, 'general' => 160000, 'frequent' => 140000, 'special' => true ),
						array( 'from' => '2026-12-05', 'to' => '2026-12-20', 'label' => '5 – 20 dic', 'presale_general' => 160000, 'presale_frequent' => 140000, 'general' => 180000, 'frequent' => 160000, 'special' => false ),
					),
				),
				'date_notices'               => array(
					array(
						'from' => '2026-12-19',
						'to'   => '2026-12-20',
						'text' => 'Las sesiones del 19 y 20 de diciembre se entregan después de Navidad.',
					),
				),
			),
			null,
			true
		);

		$a     = array( array( '13:00', '15:30' ) );
		$b     = array( array( '17:00', '19:00' ) );
		$c     = array( array( '10:00', '13:00' ) );
		$d     = array( array( '10:00', '13:00' ), array( '15:00', '19:00' ) );
		$dates = array(
			'2026-11-15' => $a, '2026-11-18' => $b, '2026-11-19' => $b, '2026-11-20' => $c, '2026-11-22' => $a,
			'2026-11-25' => $b, '2026-11-26' => $b, '2026-11-27' => $c, '2026-11-28' => $c,
			'2026-12-02' => $b, '2026-12-03' => $b, '2026-12-04' => $c, '2026-12-06' => array( array( '12:00', '15:00' ), array( '17:00', '19:00' ) ),
			'2026-12-09' => $b, '2026-12-10' => $b, '2026-12-11' => $c, '2026-12-12' => $d, '2026-12-13' => $d,
			'2026-12-16' => $b, '2026-12-17' => $b, '2026-12-18' => array( array( '10:00', '12:00' ) ), '2026-12-19' => $d, '2026-12-20' => $d,
		);
		foreach ( $dates as $date => $windows ) {
			Schedules::saveDate( $resource, $date, $windows, true );
		}

		Services::savePolicy( $serviceId, 'reschedule', array(
			array( 'min_hours' => 0, 'max_hours' => 24, 'deposit' => 'retain', 'new_deposit_cents' => 50000 ),
			array( 'min_hours' => 24, 'max_hours' => null, 'deposit' => 'credit' ),
		), "Con al menos 24 horas de anticipación puedes reagendar conservando tu anticipo, sujeto a disponibilidad.\nCon menos de 24 horas, el anticipo no se transfiere y la nueva fecha requiere otro anticipo de $500.", true );
		Services::savePolicy( $serviceId, 'cancel', array( array( 'min_hours' => 0, 'max_hours' => null, 'deposit' => 'retain' ) ), 'El anticipo no es reembolsable en caso de cancelación definitiva.', true );
		Services::savePolicy( $serviceId, 'no_show', array( array( 'min_hours' => 0, 'max_hours' => null, 'deposit' => 'retain' ) ), 'Si no asistes, el anticipo no se transfiere a otra fecha.', true );

		$clients = array(
			array( 'name' => 'PRUEBA Cliente nuevo', 'email' => 'nuevo@example.com', 'phone' => '656 555 0101' ),
			array( 'name' => 'PRUEBA Frecuente tres sesiones', 'email' => 'frecuente3@example.com', 'phone' => '656 555 0102', 'historic_sessions' => 3 ),
			array( 'name' => 'PRUEBA Frecuente cinco sesiones', 'email' => 'frecuente5@example.com', 'phone' => '656 555 0103', 'historic_sessions' => 5 ),
			array( 'name' => 'PRUEBA Categoría manual', 'email' => 'manual@example.com', 'phone' => '656 555 0104', 'manual_category' => 'frequent', 'notes' => 'Frecuente por decisión de la administradora.' ),
			array( 'name' => 'PRUEBA Contacto compartido', 'email' => 'familia@example.com', 'phone' => '656 555 0105', 'needs_review' => true, 'review_note' => 'Comparte teléfono con un familiar.' ),
			array( 'name' => 'PRUEBA Beneficio pendiente', 'email' => 'pendiente@example.com', 'phone' => '915 555 0106', 'phone_country' => 'US', 'historic_sessions' => 2, 'notes' => 'Dice tener 3 sesiones; validar.' ),
		);
		foreach ( $clients as $c ) {
			Clients::create( $c, true );
		}
		return 150;
	}

	/** Borra únicamente lo marcado como prueba. */
	public static function clear(): void {
		$wpdb     = Db::wpdb();
		$bookings = array_map( 'intval', $wpdb->get_col( 'SELECT id FROM ' . Schema::table( 'bookings' ) . ' WHERE is_test = 1' ) ?: array() ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $bookings ) {
			$in = implode( ',', $bookings );
			foreach ( $wpdb->get_col( 'SELECT stored_name FROM ' . Schema::table( 'proofs' ) . " WHERE booking_id IN ($in)" ) ?: array() as $file ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				try {
					@unlink( PrivateStorage::path( $file ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				}
			}
			foreach ( array( 'occupancies', 'payments', 'proofs', 'access_tokens', 'portal_sessions', 'notifications' ) as $t ) {
				$wpdb->query( 'DELETE FROM ' . Schema::table( $t ) . " WHERE booking_id IN ($in)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			$wpdb->query( 'DELETE FROM ' . Schema::table( 'audit' ) . " WHERE entity = 'booking' AND entity_id IN ($in)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( 'DELETE FROM ' . Schema::table( 'bookings' ) . " WHERE id IN ($in)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$services = array_map( 'intval', $wpdb->get_col( 'SELECT id FROM ' . Schema::table( 'services' ) . ' WHERE is_test = 1' ) ?: array() ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $services ) {
			$in = implode( ',', $services );
			$wpdb->query( 'DELETE FROM ' . Schema::table( 'price_rules' ) . " WHERE service_id IN ($in)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( 'DELETE FROM ' . Schema::table( 'policies' ) . " WHERE service_id IN ($in)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		foreach ( array( 'services', 'clients', 'availability', 'categories', 'resources' ) as $t ) {
			$wpdb->query( 'DELETE FROM ' . Schema::table( $t ) . ' WHERE is_test = 1' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$wpdb->query( 'DELETE FROM ' . Schema::table( 'calendar_locks' ) . ' WHERE resource_id NOT IN (SELECT id FROM ' . Schema::table( 'resources' ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
