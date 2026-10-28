<?php
namespace MOBookingStudio\Application;

use MOBookingStudio\Domain\Availability;
use MOBookingStudio\Integrations\Google\Busy as GoogleBusy;
use MOBookingStudio\Domain\DomainException;
use MOBookingStudio\Domain\Money;
use MOBookingStudio\Domain\Policy;
use MOBookingStudio\Domain\Pricing;
use MOBookingStudio\Infrastructure\Db;
use MOBookingStudio\Infrastructure\Schema;
use MOBookingStudio\Infrastructure\Settings;
use MOBookingStudio\Plugin;

/**
 * Lo que ve el público: servicios publicados, disponibilidad sin nombres y cotizaciones.
 */
final class Catalog {
	public const QUOTE_MINUTES = 20;

	public static function publicService( array $s ): array {
		$image = $s['image_id'] ? wp_get_attachment_image_url( (int) $s['image_id'], 'large' ) : null;
		return array(
			'slug'                  => $s['slug'],
			'title'                 => $s['title'],
			'brand'                 => $s['brand'] ?: (string) Settings::get( 'business_name' ),
			'description'           => $s['description'],
			'location'              => $s['location'],
			'delivery_text'         => $s['delivery_text'],
			'image'                 => $image ?: null,
			'duration'              => (int) $s['duration'],
			'participants_included' => (int) $s['participants_included'],
			'participants_max'      => (int) $s['participants_max'],
			'extra_participant'     => (int) $s['extra_participant_cents'],
			'extra_participant_txt' => Money::format( (int) $s['extra_participant_cents'] ),
			'pets_allowed'          => (bool) $s['pets_allowed'],
			'pets_max'              => (int) $s['pets_max'],
			// beta.6.3: si las mascotas cuentan como asistentes, el formulario las suma al total.
			'pets_count'            => (bool) $s['pets_count_as_participants'],
			'group_note'            => (string) $s['group_note'],
			// 6.3 campo 5: sin la leyenda sobre si cuentan; el texto público es "Pet friendly".
			'pet_friendly'          => (bool) $s['pets_allowed'],
			'fast_delivery'         => ! empty( $s['fast_delivery']['enabled'] ) ? array(
				'label'        => (string) $s['fast_delivery']['label'],
				'price'        => (int) $s['fast_delivery']['price_cents'],
				'price_txt'    => Money::format( (int) $s['fast_delivery']['price_cents'] ),
				'question'     => (string) $s['fast_delivery']['question'],
				'regular_text' => (string) $s['fast_delivery']['regular_text'],
			) : null,
			'extras'                => array_map(
				static function ( $e ) {
					return array(
						'key'         => $e['key'],
						'label'       => $e['label'],
						'price'       => (int) $e['price_cents'],
						'price_txt'   => Money::format( (int) $e['price_cents'] ),
						'max_qty'     => (int) $e['max_qty'],
						'description' => $e['description'],
					);
				},
				$s['extras']
			),
			'questions'             => $s['questions'],
			'deposit_txt'           => self::depositText( $s ),
			'hold_hours'            => Services::holdHours( $s ),
			'timezone'              => Settings::timezoneInfo()['requested'],
			'policies'              => self::policyTexts( (int) $s['id'] ),
			'contract'              => Contracts::publicView( Contracts::forService( $s ) ),
			'privacy_url'           => (string) Settings::get( 'privacy_url' ),
			// beta.4: textos editables del formulario.
			'texts'                 => array(
				'slot_taken'        => (string) Settings::get( 'slot_taken_label' ) ?: 'No disponible',
				'contract_notice'   => (string) Settings::get( 'contract_notice' ),
				'whatsapp_consent'  => (string) Settings::get( 'whatsapp_consent_text' ) ?: 'Acepto recibir por WhatsApp y por correo los avisos de mi reserva',
				'consent_required'  => (bool) Settings::get( 'whatsapp_consent_required' ),
			),
		);
	}

	public static function depositText( array $s ): string {
		switch ( $s['deposit_type'] ) {
			case 'fixed':
				return $s['deposit_value'] > 0 ? 'Anticipo de ' . Money::format( (int) $s['deposit_value'] ) . ' para apartar tu horario.' : '';
			case 'percent':
				return 'Anticipo del ' . $s['deposit_value'] . ' % del total para apartar tu horario.';
			case 'full':
				return 'Se paga el total para apartar tu horario.';
		}
		return '';
	}

	public static function policyTexts( int $serviceId ): array {
		$out = array();
		foreach ( Policy::ACTIONS as $action ) {
			$p = Services::currentPolicy( $serviceId, $action );
			if ( $p ) {
				$out[] = array(
					'action'  => $action,
					'title'   => Policy::ACTION_LABELS[ $action ],
					'summary' => $p['summary'],
				);
			}
		}
		return $out;
	}

	/**
	 * Disponibilidad pública por día, con precio orientativo general.
	 */
	/**
	 * @param string $category general o frequent (solo con una verificación de cliente frecuente vigente).
	 */
	public static function availability( array $service, string $from, string $to, string $category = Pricing::CATEGORY_GENERAL ): array {
		$now  = Plugin::clock()->now();
		Bookings::expireDue();
		$zone   = Settings::timezone();
		$engine = new Availability( $zone );
		$params = Services::availabilityParams( $service );

		$fromUtc = \DateTimeImmutable::createFromFormat( '!Y-m-d', $from, $zone );
		$toUtc   = \DateTimeImmutable::createFromFormat( '!Y-m-d', $to, $zone );
		if ( ! $fromUtc || ! $toUtc ) {
			throw new DomainException( 'invalid_date', 'Las fechas no son válidas.' );
		}
		if ( $fromUtc->diff( $toUtc )->days > 62 ) {
			throw new DomainException( 'invalid_range', 'Consulta como máximo dos meses a la vez.' );
		}
		$rangeFrom = $fromUtc->modify( '-1 day' );
		$rangeTo   = $toUtc->modify( '+2 days' );

		$resource = (int) $service['resource_id'];
		$busy     = Schedules::busy( $resource, $rangeFrom, $rangeTo, $now );
		// Ocupación de Google: vista previa con caché (SYNC-07); cotizar y reservar consultan de nuevo.
		$google   = GoogleBusy::between( $rangeFrom, $rangeTo, false );
		foreach ( $google['blocks'] as $b ) {
			if ( $b['end'] > $b['start'] ) {
				$busy[] = new \MOBookingStudio\Domain\Interval( $b['start'], $b['end'] );
			}
		}
		$blocks   = Schedules::blocks( $resource, $rangeFrom, $rangeTo );
		$counts   = Schedules::dayCounts( (int) $service['id'], $rangeFrom, $rangeTo, $now );
		$days     = $engine->days( Schedules::schedule( $resource ), $params, $from, $to, $now, $busy, $blocks, $counts );

		$pricing  = new Pricing( $zone );
		$rules    = Services::rules( (int) $service['id'] );
		$out      = array();
		$anyPresale = false;
		foreach ( $days as $date => $day ) {
			$slots = array();
			foreach ( $day['slots'] as $slot ) {
				$slots[] = array(
					'start'     => $slot['start']->format( 'Y-m-d\TH:i:s\Z' ),
					'time'      => $slot['local'],
					'available' => $slot['available'],
					'label'     => $slot['available'] ? 'Disponible' : self::reasonLabel( $slot['reason'] ),
				);
			}
			$price     = null;
			$reference = null;
			$priceType = null;
			$special   = false;
			if ( $day['slots'] ) {
				try {
					$q     = $pricing->quote(
						$service,
						$rules,
						array(
							'start'        => $day['slots'][0]['start'],
							'requested_at' => $now,
							'category'     => $category,
							'participants' => (int) $service['participants_included'],
						)
					);
					$price     = $q['base_cents'] - $q['discount_cents'];
					$reference = ! empty( $q['breakdown']['reference'] ) && $q['breakdown']['reference'] > $price ? (int) $q['breakdown']['reference'] : null;
					$priceType = $q['price_type'];
					$special   = $q['special'] || $q['discount_cents'] > 0;
				} catch ( DomainException $e ) {
					$price = null;
				}
			}
			$anyPresale   = $anyPresale || 'presale' === $priceType;
			$available    = count( array_filter( $slots, static function ( $s ) { return $s['available']; } ) );
			$out[ $date ] = array(
				'date'      => $date,
				'open'      => $day['open'],
				'available' => $available,
				'total'     => count( $slots ),
				'status'    => ! $day['open'] ? 'closed' : ( $available ? 'available' : 'sold_out' ),
				'price'     => $price,
				'price_txt' => null !== $price ? Money::format( $price ) : null,
				'reference_txt' => null !== $reference ? Money::format( $reference ) : null,
				'price_type' => $priceType,
				'special'   => $special,
				'notices'   => Services::noticesFor( $service, $date ),
				'slots'     => $slots,
			);
		}
		$table = (array) ( $service['price_table'] ?? array() );
		$until = $anyPresale ? ( new Pricing( $zone ) )->presaleActive( $table, $now ) : false;
		return array(
			'timezone'     => Settings::timezoneInfo()['requested'],
			'category'     => $category,
			'presale'      => $anyPresale,
			'presale_txt'  => $until && ! empty( $table['presale_until'] ) ? 'Preventa hasta el ' . self::dateTimeText( \DateTimeImmutable::createFromFormat( strlen( $table['presale_until'] ) === 16 ? '!Y-m-d H:i' : '!Y-m-d H:i:s', $table['presale_until'], $zone )->setTimezone( new \DateTimeZone( 'UTC' ) ) ) : '',
			'days'         => array_values( $out ),
			'stale'        => $google['stale'],
			'note'         => ( $google['stale'] ? 'Vista preliminar: confirmaremos el horario al enviar tu solicitud. ' : '' ) . ( Pricing::CATEGORY_FREQUENT === $category ? 'Precios para clientes frecuentes' : 'Precios para público general' ) . ', para el número de personas incluido. El precio final se confirma en el resumen.',
		);
	}

	public static function reasonLabel( ?string $reason ): string {
		switch ( $reason ) {
			case Availability::REASON_BOOKED:
			case Availability::REASON_MAX_PER_DAY:
				return (string) Settings::get( 'slot_taken_label' ) ?: 'No disponible';
			case Availability::REASON_NOTICE:
				return 'Ya no se puede reservar';
			case Availability::REASON_HORIZON:
			case Availability::REASON_SALE_WINDOW:
				return 'Aún no disponible';
			default:
				return 'No disponible';
		}
	}

	/**
	 * Cotiza en el servidor y guarda la cotización para aceptarla al reservar.
	 */
	public static function quote( array $service, array $input ): array {
		$now   = Plugin::clock()->now();
		$start = self::parseStart( (string) ( $input['start'] ?? '' ) );
		Bookings::expireDue();

		$slot = self::findAvailableSlot( $service, $start, $now, null, false, self::freshGoogleBusy( $service, $start ) );
		if ( ! $slot ) {
			throw new DomainException( 'slot_unavailable', 'Ese horario ya no está disponible. Elige otro, por favor.', 409, array( 'field' => 'start' ) );
		}
		$category = FrequentCheck::category( isset( $input['frequent_token'] ) ? (string) $input['frequent_token'] : null );
		$context  = self::pricingContext( $input, $start, $now, $category );
		$result   = ( new Pricing( Settings::timezone() ) )->quote( Services::forPricing( $service ), Services::rules( (int) $service['id'] ), $context );
		$key     = bin2hex( random_bytes( 16 ) );
		$expires = $now->modify( '+' . self::QUOTE_MINUTES . ' minutes' );
		$payload = array(
			'input'  => array(
				'start'        => $start->format( 'Y-m-d\TH:i:s\Z' ),
				'participants' => $context['participants'],
				'children'     => $context['children'],
				'adults'       => $context['adults'],
				'pets'         => $context['pets'],
				'extras'       => $context['extras'],
				'category'     => $category,
			),
			'result' => $result,
		);
		$wpdb = Db::wpdb();
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Schema::table( 'quotes' ) . ' WHERE expires_at < %s', Db::toSql( $now->modify( '-1 day' ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		Db::check(
			$wpdb->insert(
				Schema::table( 'quotes' ),
				array(
					'quote_key'    => $key,
					'service_id'   => (int) $service['id'],
					'start_utc'    => Db::toSql( $start ),
					'payload'      => Db::json( $payload ),
					'pricing_hash' => Services::pricingHash( $service, Services::rules( (int) $service['id'] ) ),
					'expires_at'   => Db::toSql( $expires ),
					'created_at'   => Db::toSql( $now ),
				)
			),
			'quote_insert'
		);
		return self::presentQuote( $service, $key, $expires, $start, $result );
	}

	/**
	 * Renglones del precio para la clienta. Con tabla por temporada: precio de referencia
	 * (tachado), descuentos y "precio con descuento"; luego personas, mascotas y extras.
	 * Sin desglose (tarifas avanzadas o reservas anteriores): los renglones de siempre.
	 *
	 * @return array<array{kind:string,label:string,amount:int,amount_txt:string}>
	 */
	public static function priceRows( array $result ): array {
		$row = static function ( string $kind, string $label, int $amount ): array {
			return array( 'kind' => $kind, 'label' => $label, 'amount' => $amount, 'amount_txt' => ( $amount < 0 ? '−' : '' ) . Money::format( abs( $amount ) ) );
		};
		$lines = (array) ( $result['lines'] ?? array() );
		$b     = $result['breakdown'] ?? null;
		if ( ! is_array( $b ) || empty( $b['steps'] ) ) {
			return array_map(
				static function ( $l ) use ( $row ) {
					return $row( (string) ( $l['kind'] ?? 'line' ), (string) $l['label'], (int) $l['amount'] );
				},
				$lines
			);
		}
		$labels = array(
			'date'     => (string) Settings::get( 'label_discount_date' ),
			'frequent' => (string) Settings::get( 'label_discount_frequent' ),
			'presale'  => (string) Settings::get( 'label_discount_presale' ),
		);
		$out = array( $row( 'reference', (string) Settings::get( 'label_price_reference' ), (int) $b['reference'] ) );
		foreach ( $b['steps'] as $step ) {
			$out[] = $row( 'discount', $labels[ $step['kind'] ] ?? 'Descuento', (int) $step['amount'] );
		}
		$after = array();
		foreach ( $lines as $l ) {
			if ( 'base' === ( $l['kind'] ?? '' ) ) {
				continue;
			}
			if ( 'discount' === ( $l['kind'] ?? '' ) ) {
				$out[] = $row( 'discount', (string) $l['label'], (int) $l['amount'] );
				continue;
			}
			$after[] = $row( (string) ( $l['kind'] ?? 'line' ), (string) $l['label'], (int) $l['amount'] );
		}
		$out[] = $row( 'discounted', (string) Settings::get( 'label_price_discounted' ), (int) $result['base_cents'] - (int) ( $result['discount_cents'] ?? 0 ) );
		return array_merge( $out, $after );
	}

	public static function presentQuote( array $service, string $key, \DateTimeImmutable $expires, \DateTimeImmutable $start, array $result ): array {
		$zone  = Settings::timezone();
		$local = $start->setTimezone( $zone );
		return array(
			'quote_id'      => $key,
			'expires_at'    => $expires->format( 'c' ),
			'service'       => $service['title'],
			'start'         => $start->format( 'Y-m-d\TH:i:s\Z' ),
			'date_txt'      => self::dateText( $local ),
			'time_txt'      => $local->format( 'H:i' ),
			'timezone'      => Settings::timezoneInfo()['requested'],
			'duration'      => (int) $service['duration'],
			'lines'         => array_map(
				static function ( $l ) {
					$l['amount_txt'] = Money::format( (int) $l['amount'] );
					return $l;
				},
				$result['lines']
			),
			'price_rows'    => self::priceRows( $result ),
			'total'         => $result['total_cents'],
			'total_txt'     => Money::format( $result['total_cents'] ),
			'deposit'       => $result['deposit_cents'],
			'deposit_txt'   => Money::format( $result['deposit_cents'] ),
			'balance'       => $result['balance_cents'],
			'balance_txt'   => Money::format( $result['balance_cents'] ),
			'category'      => $result['category'],
			'notices'       => Services::noticesFor( $service, $local->format( 'Y-m-d' ) ),
			'hold_hours'    => Services::holdHours( $service ),
			'participants'  => $result['participants'],
			'pets'          => $result['pets'],
		);
	}

	public static function loadQuote( string $key ): array {
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $key ) ) {
			throw new DomainException( 'invalid_quote', 'La cotización no es válida. Vuelve a revisar tu resumen.', 400 );
		}
		$wpdb = Db::wpdb();
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'quotes' ) . ' WHERE quote_key = %s', $key ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $row ) {
			throw new DomainException( 'invalid_quote', 'La cotización no es válida. Vuelve a revisar tu resumen.', 404 );
		}
		$row['payload'] = Db::decode( $row['payload'] );
		return $row;
	}

	/**
	 * @param bool $relaxed Para la administradora: ignora anticipación mínima, horizonte y
	 *                      fechas de venta, pero nunca solapamientos, cierres ni bloqueos.
	 */
	/**
	 * Consulta fresca a Google para el horario pedido (DEC-06). Si nunca se conectó
	 * devuelve una lista vacía; si Google no responde lanza un error 503.
	 *
	 * @return \MOBookingStudio\Domain\Interval[]
	 */
	public static function freshGoogleBusy( array $service, \DateTimeImmutable $start ): array {
		if ( ! \MOBookingStudio\Integrations\Google\Connection::isConfigured() ) {
			return array();
		}
		$occupy = Availability::occupancyFor( $start, Services::availabilityParams( $service ) );
		return GoogleBusy::intervals( $occupy->start, $occupy->end, true );
	}

	/**
	 * @param \MOBookingStudio\Domain\Interval[] $externalBusy Ocupación de Google ya consultada.
	 */
	public static function findAvailableSlot( array $service, \DateTimeImmutable $start, \DateTimeImmutable $now, ?int $excludeBookingId = null, bool $relaxed = false, array $externalBusy = array() ): ?array {
		$zone     = Settings::timezone();
		$engine   = new Availability( $zone );
		$params   = Services::availabilityParams( $service );
		if ( $relaxed ) {
			$params['min_notice_minutes'] = 0;
			$params['horizon_days']       = 0;
			$params['sale_from']          = null;
			$params['sale_until']         = null;
			if ( $start <= $now ) {
				return null;
			}
		}
		$resource = (int) $service['resource_id'];
		$from     = $start->modify( '-2 days' );
		$to       = $start->modify( '+2 days' );
		$slot     = $engine->findSlot(
			Schedules::schedule( $resource ),
			$params,
			$start,
			$now,
			array_merge( Schedules::busy( $resource, $from, $to, $now, $excludeBookingId ), $externalBusy ),
			Schedules::blocks( $resource, $from, $to ),
			Schedules::dayCounts( (int) $service['id'], $from, $to, $now, $excludeBookingId )
		);
		return ( $slot && $slot['available'] ) ? $slot : null;
	}

	public static function pricingContext( array $input, \DateTimeImmutable $start, \DateTimeImmutable $requestedAt, string $category = Pricing::CATEGORY_GENERAL ): array {
		$extras = array();
		foreach ( (array) ( $input['extras'] ?? array() ) as $key => $qty ) {
			$key = sanitize_key( (string) $key );
			if ( '' !== $key && (int) $qty !== 0 ) {
				$extras[ $key ] = (int) $qty;
			}
		}
		if ( ! empty( $input['fast_delivery'] ) ) {
			$extras[ Services::FAST_KEY ] = 1;
		} elseif ( array_key_exists( 'fast_delivery', $input ) ) {
			unset( $extras[ Services::FAST_KEY ] );
		}
		// 6.3: niños y adultos son enteros >= 0, sin decimales ni texto; asistentes = niños + adultos.
		$whole = static function ( $value, string $field, string $label ): int {
			if ( null === $value || '' === $value ) {
				return 0;
			}
			if ( is_int( $value ) ) {
				$ok = $value >= 0;
			} else {
				$ok = is_string( $value ) && 1 === preg_match( '/^\d{1,3}$/', trim( $value ) );
			}
			if ( ! $ok ) {
				throw new DomainException( 'invalid_' . $field, sprintf( '%s: escribe un número entero, 0 o mayor.', $label ), 400, array( 'field' => $field ) );
			}
			return (int) $value;
		};
		$children = null;
		$adults   = null;
		if ( isset( $input['children'] ) || isset( $input['adults'] ) ) {
			$children     = $whole( $input['children'] ?? null, 'children', 'Niños' );
			$adults       = $whole( $input['adults'] ?? null, 'adults', 'Adultos' );
			$participants = $children + $adults;
			if ( $participants < 1 ) {
				throw new DomainException( 'invalid_participants', 'Indica al menos un asistente entre niños y adultos.', 400, array( 'field' => 'adults' ) );
			}
		} else {
			$participants = $input['participants'] ?? 1;
			if ( ! is_numeric( $participants ) ) {
				throw new DomainException( 'invalid_participants', 'Indica cantidades con números.', 400, array( 'field' => 'participants' ) );
			}
			$participants = (int) $participants;
		}
		$pets = $whole( $input['pets'] ?? 0, 'pets', 'Mascotas' );
		return array(
			'start'        => $start,
			'requested_at' => $requestedAt,
			'category'     => $category,
			'participants' => $participants,
			'children'     => $children,
			'adults'       => $adults,
			'pets'         => $pets,
			'extras'       => $extras,
		);
	}

	public static function parseStart( string $value ): \DateTimeImmutable {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value ) ) {
			throw new DomainException( 'invalid_start', 'Elige un horario.', 400, array( 'field' => 'start' ) );
		}
		return new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) );
	}

	public static function dateText( \DateTimeImmutable $local ): string {
		$days   = array( 1 => 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo' );
		$months = array( 1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre' );
		return sprintf( '%s %d de %s de %d', $days[ (int) $local->format( 'N' ) ], (int) $local->format( 'j' ), $months[ (int) $local->format( 'n' ) ], (int) $local->format( 'Y' ) );
	}

	public static function dateTimeText( \DateTimeImmutable $utc ): string {
		$local = $utc->setTimezone( Settings::timezone() );
		return self::dateText( $local ) . ', ' . $local->format( 'H:i' ) . ' h';
	}
}
