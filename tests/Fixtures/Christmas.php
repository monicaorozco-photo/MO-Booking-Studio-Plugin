<?php
namespace MOBookingStudio\Tests\Fixtures;

/**
 * Configuración de las minis navideñas 2026 (sección 22 de la especificación).
 * Solo se usa en pruebas y en la carga opcional de datos de prueba; el plugin
 * se instala vacío.
 */
final class Christmas {
	public const ZONE = 'America/Ciudad_Juarez';

	public static function service(): array {
		return array(
			'duration'                   => 20,
			'buffer_before'              => 0,
			'buffer_after'               => 10,
			'interval'                   => 30,
			'min_notice_minutes'         => 0,
			'horizon_days'               => 0,
			'max_per_day'                => 0,
			'price_cents'                => 180000,
			'participants_included'      => 6,
			'participants_max'           => 10,
			'extra_participant_cents'    => 10000,
			'pets_allowed'               => true,
			'pets_count_as_participants' => false,
			'pet_fee_cents'              => 0,
			'deposit_type'               => 'fixed',
			'deposit_value'              => 50000,
			'extras'                     => array(),
		);
	}

	/** @return array<string,array<array{0:string,1:string}>> */
	public static function dates(): array {
		$a = array( array( '13:00', '15:30' ) );
		$b = array( array( '17:00', '19:00' ) );
		$c = array( array( '10:00', '13:00' ) );
		$d = array( array( '10:00', '13:00' ), array( '15:00', '19:00' ) );
		return array(
			'2026-11-15' => $a,
			'2026-11-18' => $b,
			'2026-11-19' => $b,
			'2026-11-20' => $c,
			'2026-11-22' => $a,
			'2026-11-25' => $b,
			'2026-11-26' => $b,
			'2026-11-27' => $c,
			'2026-11-28' => $c,
			'2026-12-02' => $b,
			'2026-12-03' => $b,
			'2026-12-04' => $c,
			'2026-12-06' => array( array( '12:00', '15:00' ), array( '17:00', '19:00' ) ),
			'2026-12-09' => $b,
			'2026-12-10' => $b,
			'2026-12-11' => $c,
			'2026-12-12' => $d,
			'2026-12-13' => $d,
			'2026-12-16' => $b,
			'2026-12-17' => $b,
			'2026-12-18' => array( array( '10:00', '12:00' ) ),
			'2026-12-19' => $d,
			'2026-12-20' => $d,
		);
	}

	public static function schedule(): array {
		return array(
			'weekly' => array(),
			'dates'  => self::dates(),
			'closed' => array(),
		);
	}

	/**
	 * Matriz de tarifas finales. Las preventas tienen prioridad mayor que las regulares
	 * porque también coinciden con el periodo regular si no se limita su fecha.
	 */
	public static function rules(): array {
		$rows  = array(
			// nombre, desde, hasta, categoría, preventa, precio
			array( 'Público regular (15 nov–4 dic)', '2026-11-15', '2026-12-04', 'general', false, 160000 ),
			array( 'Público preventa (15 nov–4 dic)', '2026-11-15', '2026-12-04', 'general', true, 140000 ),
			array( 'Frecuente regular (15 nov–4 dic)', '2026-11-15', '2026-12-04', 'frequent', false, 140000 ),
			array( 'Frecuente preventa (15 nov–4 dic)', '2026-11-15', '2026-12-04', 'frequent', true, 120000 ),
			array( 'Público regular (5–20 dic)', '2026-12-05', '2026-12-20', 'general', false, 180000 ),
			array( 'Público preventa (5–20 dic)', '2026-12-05', '2026-12-20', 'general', true, 160000 ),
			array( 'Frecuente regular (5–20 dic)', '2026-12-05', '2026-12-20', 'frequent', false, 160000 ),
			array( 'Frecuente preventa (5–20 dic)', '2026-12-05', '2026-12-20', 'frequent', true, 140000 ),
		);
		$rules = array();
		foreach ( $rows as $i => $r ) {
			$rules[] = array(
				'id'         => $i + 1,
				'name'       => $r[0],
				'kind'       => 'rate',
				'priority'   => $r[4] ? 20 : 10,
				'active'     => true,
				'value'      => $r[5],
				'conditions' => array(
					'session_from' => $r[1],
					'session_to'   => $r[2],
					'category'     => $r[3],
					'booked_until' => $r[4] ? '2026-10-31 23:59:59' : '',
				),
			);
		}
		return $rules;
	}

	/** La misma matriz expresada como tabla de precios por temporada (forma simple del panel). */
	public static function priceTable(): array {
		return array(
			'presale_until' => '2026-10-31 23:59',
			'rows'          => array(
				array( 'from' => '2026-11-15', 'to' => '2026-12-04', 'label' => '15 nov – 4 dic', 'general' => 160000, 'frequent' => 140000, 'presale_general' => 140000, 'presale_frequent' => 120000, 'special' => true ),
				array( 'from' => '2026-12-05', 'to' => '2026-12-20', 'label' => '5 – 20 dic', 'general' => 180000, 'frequent' => 160000, 'presale_general' => 160000, 'presale_frequent' => 140000, 'special' => false ),
			),
		);
	}

	public static function reschedulePolicy(): array {
		return array(
			array(
				'min_hours'         => 0,
				'max_hours'         => 24,
				'charge_type'       => 'none',
				'deposit'           => 'retain',
				'new_deposit_cents' => 50000,
			),
			array(
				'min_hours'   => 24,
				'max_hours'   => null,
				'charge_type' => 'none',
				'deposit'     => 'credit',
			),
		);
	}

	public static function cancelPolicy(): array {
		return array(
			array(
				'min_hours'   => 0,
				'max_hours'   => null,
				'charge_type' => 'none',
				'deposit'     => 'retain',
			),
		);
	}
}
