<?php
namespace MOBookingStudio\Domain;

/**
 * Motor de precios. Todo en centavos y en el servidor.
 *
 * Orden de cálculo:
 * 1. Tarifa base: precio del servicio, o la tarifa final ("rate") aplicable con mayor prioridad.
 * 2. Promociones ("discount") sobre la base. Por defecto no se acumulan: se aplica la de
 *    mayor prioridad. Solo si esa promoción y otras están marcadas como acumulables se
 *    aplican en orden de prioridad sobre el importe restante.
 * 3. Se suman personas adicionales, mascotas con costo y extras (sin descuento).
 * 4. Total, anticipo (nunca mayor que el total) y saldo.
 *
 * Dos reglas del mismo tipo que coinciden con la misma prioridad son ambiguas:
 * se rechaza la cotización en lugar de elegir una al azar.
 */
final class Pricing {
	public const CATEGORY_GENERAL  = 'general';
	public const CATEGORY_FREQUENT = 'frequent';

	/** @var \DateTimeZone */
	private $zone;

	public function __construct( \DateTimeZone $zone ) {
		$this->zone = $zone;
	}

	/**
	 * @param array $service  {price_cents, participants_included, participants_max, extra_participant_cents,
	 *                         pets_allowed, pets_max, pet_fee_cents, pets_count_as_participants,
	 *                         extras: array<array{key,label,price_cents,max_qty}>, deposit_type, deposit_value}
	 * @param array $rules    Reglas activas del servicio.
	 * @param array $context  {start: DateTimeImmutable, requested_at: DateTimeImmutable, category: string,
	 *                         participants: int, pets: int, extras: array<string,int>}
	 */
	public function quote( array $service, array $rules, array $context ): array {
		$start       = $context['start'];
		$requestedAt = $context['requested_at'];
		$category    = self::CATEGORY_FREQUENT === ( $context['category'] ?? '' ) ? self::CATEGORY_FREQUENT : self::CATEGORY_GENERAL;

		$participants = (int) ( $context['participants'] ?? 1 );
		$pets         = (int) ( $context['pets'] ?? 0 );
		$included     = max( 1, (int) ( $service['participants_included'] ?? 1 ) );
		$max          = max( $included, (int) ( $service['participants_max'] ?? $included ) );

		if ( $participants < 1 ) {
			throw new DomainException( 'invalid_participants', 'Indica al menos una persona.', 400, array( 'field' => 'participants' ) );
		}
		if ( $pets < 0 ) {
			throw new DomainException( 'invalid_pets', 'La cantidad de mascotas no es válida.', 400, array( 'field' => 'pets' ) );
		}
		if ( $pets > 0 && empty( $service['pets_allowed'] ) ) {
			throw new DomainException( 'pets_not_allowed', 'Este servicio no admite mascotas.', 400, array( 'field' => 'pets' ) );
		}
		$petsMax = (int) ( $service['pets_max'] ?? 0 );
		if ( $petsMax > 0 && $pets > $petsMax ) {
			throw new DomainException( 'invalid_pets', sprintf( 'El máximo de mascotas es %d.', $petsMax ), 400, array( 'field' => 'pets' ) );
		}
		$counted = $participants + ( ! empty( $service['pets_count_as_participants'] ) ? $pets : 0 );
		if ( $counted > $max ) {
			throw new DomainException( 'too_many_participants', sprintf( 'El máximo para este servicio es de %d personas.', $max ), 400, array( 'field' => 'participants' ) );
		}

		$lines = array();

		// 1. Tarifa base.
		$rate = $this->pickRule( $rules, 'rate', $start, $requestedAt, $category );
		if ( $rate ) {
			$base      = max( 0, (int) $rate['value'] );
			$baseLabel = (string) $rate['name'];
		} else {
			$base      = max( 0, (int) ( $service['price_cents'] ?? 0 ) );
			$baseLabel = 'Tarifa del servicio';
		}
		$lines[] = array(
			'kind'   => 'base',
			'label'  => $baseLabel,
			'amount' => $base,
		);

		// 2. Promociones.
		$discountTotal = 0;
		$applied       = array();
		foreach ( $this->pickDiscounts( $rules, $start, $requestedAt, $category ) as $discount ) {
			$remaining = $base - $discountTotal;
			if ( $remaining <= 0 ) {
				break;
			}
			$amount = 'percent' === ( $discount['amount_type'] ?? 'fixed' )
				? Money::percentOf( $remaining, (float) $discount['value'] )
				: (int) $discount['value'];
			$amount = min( max( 0, $amount ), $remaining );
			if ( $amount > 0 ) {
				$discountTotal += $amount;
				$applied[]      = (int) $discount['id'];
				$lines[]        = array(
					'kind'    => 'discount',
					'label'   => (string) $discount['name'],
					'amount'  => -$amount,
					'rule_id' => (int) $discount['id'],
				);
			}
		}

		// 3. Personas adicionales, mascotas y extras.
		$extraPeople      = max( 0, $counted - $included );
		$participantsCost = $extraPeople * max( 0, (int) ( $service['extra_participant_cents'] ?? 0 ) );
		if ( $extraPeople > 0 ) {
			$lines[] = array(
				'kind'     => 'participants',
				'label'    => sprintf( '%d %s adicional%s', $extraPeople, 1 === $extraPeople ? 'persona' : 'personas', 1 === $extraPeople ? '' : 'es' ),
				'quantity' => $extraPeople,
				'unit'     => (int) ( $service['extra_participant_cents'] ?? 0 ),
				'amount'   => $participantsCost,
			);
		}
		$petsCost = 0;
		$petFee   = max( 0, (int) ( $service['pet_fee_cents'] ?? 0 ) );
		if ( $pets > 0 && $petFee > 0 ) {
			$petsCost = $pets * $petFee;
			$lines[]  = array(
				'kind'     => 'pets',
				'label'    => sprintf( '%d %s', $pets, 1 === $pets ? 'mascota' : 'mascotas' ),
				'quantity' => $pets,
				'unit'     => $petFee,
				'amount'   => $petsCost,
			);
		}

		$extrasCost = 0;
		$extrasDef  = array();
		foreach ( (array) ( $service['extras'] ?? array() ) as $extra ) {
			$extrasDef[ (string) $extra['key'] ] = $extra;
		}
		foreach ( (array) ( $context['extras'] ?? array() ) as $key => $qty ) {
			$qty = (int) $qty;
			if ( 0 === $qty ) {
				continue;
			}
			if ( ! isset( $extrasDef[ $key ] ) ) {
				throw new DomainException( 'invalid_extra', 'Uno de los extras elegidos no existe.', 400, array( 'field' => 'extras' ) );
			}
			$def    = $extrasDef[ $key ];
			$maxQty = max( 1, (int) ( $def['max_qty'] ?? 1 ) );
			if ( $qty < 0 || $qty > $maxQty ) {
				throw new DomainException( 'invalid_extra', sprintf( 'La cantidad de "%s" no es válida.', $def['label'] ), 400, array( 'field' => 'extras' ) );
			}
			$amount      = $qty * max( 0, (int) $def['price_cents'] );
			$extrasCost += $amount;
			$lines[]     = array(
				'kind'     => 'extra',
				'key'      => (string) $key,
				'label'    => (string) $def['label'],
				'quantity' => $qty,
				'unit'     => (int) $def['price_cents'],
				'amount'   => $amount,
			);
		}

		// 4. Totales.
		$total   = max( 0, $base - $discountTotal + $participantsCost + $petsCost + $extrasCost );
		$deposit = self::deposit( $service, $total );

		return array(
			'currency'       => 'MXN',
			'category'       => $category,
			'base_cents'     => $base,
			'rate_rule_id'   => $rate ? (int) $rate['id'] : null,
			'discount_ids'   => $applied,
			'discount_cents' => $discountTotal,
			'extras_cents'   => $participantsCost + $petsCost + $extrasCost,
			'total_cents'    => $total,
			'deposit_cents'  => $deposit,
			'balance_cents'  => $total - $deposit,
			'lines'          => $lines,
			'participants'   => $participants,
			'pets'           => $pets,
		);
	}

	public static function deposit( array $service, int $total ): int {
		$type  = (string) ( $service['deposit_type'] ?? 'none' );
		$value = $service['deposit_value'] ?? 0;
		switch ( $type ) {
			case 'fixed':
				$deposit = (int) $value;
				break;
			case 'percent':
				$deposit = Money::percentOf( $total, (float) $value );
				break;
			case 'full':
				$deposit = $total;
				break;
			default:
				$deposit = 0;
		}
		return min( max( 0, $deposit ), $total );
	}

	/**
	 * Comprueba si las condiciones de una regla se cumplen.
	 */
	public function matches( array $rule, \DateTimeImmutable $start, \DateTimeImmutable $requestedAt, string $category ): bool {
		if ( isset( $rule['active'] ) && ! $rule['active'] ) {
			return false;
		}
		$c          = (array) ( $rule['conditions'] ?? array() );
		$localStart = $start->setTimezone( $this->zone );
		$date       = $localStart->format( 'Y-m-d' );

		if ( ! empty( $c['session_from'] ) && $date < $c['session_from'] ) {
			return false;
		}
		if ( ! empty( $c['session_to'] ) && $date > $c['session_to'] ) {
			return false;
		}
		if ( ! empty( $c['weekdays'] ) && ! in_array( (int) $localStart->format( 'N' ), array_map( 'intval', (array) $c['weekdays'] ), true ) ) {
			return false;
		}
		$time = $localStart->format( 'H:i' );
		if ( ! empty( $c['time_from'] ) && $time < $c['time_from'] ) {
			return false;
		}
		if ( ! empty( $c['time_to'] ) && $time >= $c['time_to'] ) {
			return false;
		}
		if ( ! empty( $c['booked_from'] ) && $requestedAt < $this->localInstant( $c['booked_from'], false ) ) {
			return false;
		}
		if ( ! empty( $c['booked_until'] ) && $requestedAt > $this->localInstant( $c['booked_until'], true ) ) {
			return false;
		}
		$ruleCategory = (string) ( $c['category'] ?? 'any' );
		if ( 'any' !== $ruleCategory && '' !== $ruleCategory && $ruleCategory !== $category ) {
			return false;
		}
		return true;
	}

	/**
	 * Valida un conjunto de reglas antes de guardarlas: detecta reglas del mismo tipo
	 * con la misma prioridad cuyas condiciones pueden coincidir a la vez.
	 *
	 * @return string[] Mensajes de conflicto (vacío si no hay conflicto).
	 */
	public static function findConflicts( array $rules ): array {
		$conflicts = array();
		$rules     = array_values(
			array_filter(
				$rules,
				static function ( $r ) {
					return ! isset( $r['active'] ) || $r['active'];
				}
			)
		);
		for ( $i = 0, $n = count( $rules ); $i < $n; $i++ ) {
			for ( $j = $i + 1; $j < $n; $j++ ) {
				$a = $rules[ $i ];
				$b = $rules[ $j ];
				if ( $a['kind'] !== $b['kind'] || (int) $a['priority'] !== (int) $b['priority'] ) {
					continue;
				}
				if ( 'discount' === $a['kind'] && ! empty( $a['stackable'] ) && ! empty( $b['stackable'] ) ) {
					continue;
				}
				if ( self::conditionsCanOverlap( (array) $a['conditions'], (array) $b['conditions'] ) ) {
					$conflicts[] = sprintf( '"%s" y "%s" pueden aplicar a la misma cita con la misma prioridad (%d). Cambia la prioridad de una de ellas o ajusta sus condiciones.', $a['name'], $b['name'], (int) $a['priority'] );
				}
			}
		}
		return $conflicts;
	}

	public static function validateRule( array $rule ): void {
		if ( '' === trim( (string) ( $rule['name'] ?? '' ) ) ) {
			throw new DomainException( 'invalid_rule', 'La regla necesita un nombre.' );
		}
		if ( ! in_array( $rule['kind'] ?? '', array( 'rate', 'discount' ), true ) ) {
			throw new DomainException( 'invalid_rule', 'El tipo de regla no es válido.' );
		}
		$value = $rule['value'] ?? null;
		if ( ! is_numeric( $value ) || $value < 0 ) {
			throw new DomainException( 'invalid_rule', 'El importe de la regla debe ser un número no negativo.' );
		}
		if ( 'discount' === $rule['kind'] && 'percent' === ( $rule['amount_type'] ?? '' ) && $value > 100 ) {
			throw new DomainException( 'invalid_rule', 'Un descuento porcentual no puede superar 100 %.' );
		}
		$c = (array) ( $rule['conditions'] ?? array() );
		foreach ( array( 'session_from', 'session_to' ) as $key ) {
			if ( ! empty( $c[ $key ] ) && ! self::isDate( $c[ $key ] ) ) {
				throw new DomainException( 'invalid_rule', 'Una de las fechas de la regla no es válida.' );
			}
		}
		if ( ! empty( $c['session_from'] ) && ! empty( $c['session_to'] ) && $c['session_to'] < $c['session_from'] ) {
			throw new DomainException( 'invalid_rule', 'La fecha final de la regla es anterior a la inicial.' );
		}
		foreach ( array( 'booked_from', 'booked_until' ) as $key ) {
			if ( ! empty( $c[ $key ] ) && ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', (string) $c[ $key ] ) ) {
				throw new DomainException( 'invalid_rule', 'La fecha y hora del periodo de reserva no es válida.' );
			}
		}
		if ( ! empty( $c['booked_from'] ) && ! empty( $c['booked_until'] ) && $c['booked_until'] < $c['booked_from'] ) {
			throw new DomainException( 'invalid_rule', 'El periodo de reserva termina antes de empezar.' );
		}
		foreach ( array( 'time_from', 'time_to' ) as $key ) {
			if ( ! empty( $c[ $key ] ) ) {
				Availability::parseTime( (string) $c[ $key ] );
			}
		}
	}

	private static function isDate( string $date ): bool {
		$d = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date );
		return $d && $d->format( 'Y-m-d' ) === $date;
	}

	private static function conditionsCanOverlap( array $a, array $b ): bool {
		$rangesOverlap = static function ( $aFrom, $aTo, $bFrom, $bTo ) {
			$aFrom = $aFrom ?: '0000';
			$bFrom = $bFrom ?: '0000';
			$aTo   = $aTo ?: '9999';
			$bTo   = $bTo ?: '9999';
			return $aFrom <= $bTo && $bFrom <= $aTo;
		};
		if ( ! $rangesOverlap( $a['session_from'] ?? '', $a['session_to'] ?? '', $b['session_from'] ?? '', $b['session_to'] ?? '' ) ) {
			return false;
		}
		if ( ! $rangesOverlap( $a['booked_from'] ?? '', $a['booked_until'] ?? '', $b['booked_from'] ?? '', $b['booked_until'] ?? '' ) ) {
			return false;
		}
		$catA = $a['category'] ?? 'any';
		$catB = $b['category'] ?? 'any';
		if ( 'any' !== $catA && 'any' !== $catB && '' !== $catA && '' !== $catB && $catA !== $catB ) {
			return false;
		}
		if ( ! empty( $a['weekdays'] ) && ! empty( $b['weekdays'] ) && ! array_intersect( array_map( 'intval', $a['weekdays'] ), array_map( 'intval', $b['weekdays'] ) ) ) {
			return false;
		}
		$aTimeFrom = $a['time_from'] ?? '';
		$aTimeTo   = $a['time_to'] ?? '';
		$bTimeFrom = $b['time_from'] ?? '';
		$bTimeTo   = $b['time_to'] ?? '';
		if ( ( $aTimeFrom || $aTimeTo ) && ( $bTimeFrom || $bTimeTo ) ) {
			$aTimeFrom = $aTimeFrom ?: '00:00';
			$bTimeFrom = $bTimeFrom ?: '00:00';
			$aTimeTo   = $aTimeTo ?: '24:00';
			$bTimeTo   = $bTimeTo ?: '24:00';
			if ( ! ( $aTimeFrom < $bTimeTo && $bTimeFrom < $aTimeTo ) ) {
				return false;
			}
		}
		return true;
	}

	private function pickRule( array $rules, string $kind, \DateTimeImmutable $start, \DateTimeImmutable $requestedAt, string $category ): ?array {
		$matching = $this->matching( $rules, $kind, $start, $requestedAt, $category );
		if ( ! $matching ) {
			return null;
		}
		if ( count( $matching ) > 1 && (int) $matching[0]['priority'] === (int) $matching[1]['priority'] ) {
			throw new DomainException(
				'ambiguous_rules',
				'Hay dos tarifas con la misma prioridad que aplican a esta fecha. La administradora debe revisar la configuración de tarifas.',
				409,
				array( 'rules' => array( $matching[0]['name'], $matching[1]['name'] ) )
			);
		}
		return $matching[0];
	}

	private function pickDiscounts( array $rules, \DateTimeImmutable $start, \DateTimeImmutable $requestedAt, string $category ): array {
		$matching = $this->matching( $rules, 'discount', $start, $requestedAt, $category );
		if ( ! $matching ) {
			return array();
		}
		$top = $matching[0];
		if ( empty( $top['stackable'] ) ) {
			if ( count( $matching ) > 1 && (int) $matching[1]['priority'] === (int) $top['priority'] ) {
				throw new DomainException( 'ambiguous_rules', 'Hay dos promociones con la misma prioridad que aplican a esta cita. La administradora debe revisar la configuración.', 409 );
			}
			return array( $top );
		}
		return array_values(
			array_filter(
				$matching,
				static function ( $r ) {
					return ! empty( $r['stackable'] );
				}
			)
		);
	}

	private function matching( array $rules, string $kind, \DateTimeImmutable $start, \DateTimeImmutable $requestedAt, string $category ): array {
		$out = array();
		foreach ( $rules as $rule ) {
			if ( ( $rule['kind'] ?? '' ) === $kind && $this->matches( $rule, $start, $requestedAt, $category ) ) {
				$out[] = $rule;
			}
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return ( (int) $b['priority'] <=> (int) $a['priority'] ) ?: ( (int) $a['id'] <=> (int) $b['id'] );
			}
		);
		return $out;
	}

	private function localInstant( string $local, bool $isEnd ): \DateTimeImmutable {
		$format = strlen( $local ) === 16 ? '!Y-m-d H:i' : '!Y-m-d H:i:s';
		$d      = \DateTimeImmutable::createFromFormat( $format, $local, $this->zone );
		if ( ! $d ) {
			throw new DomainException( 'invalid_rule', 'Una regla de tarifa tiene una fecha no válida.' );
		}
		// "Hasta 23:59" incluye todo ese minuto.
		if ( $isEnd && 16 === strlen( $local ) ) {
			$d = $d->modify( '+59 seconds' );
		}
		return $d;
	}
}
