<?php
namespace MOBookingStudio\Application;

use MOBookingStudio\Domain\Availability;
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
			'pets_note'             => $s['pets_allowed'] ? ( $s['pets_count_as_participants'] ? 'Las mascotas cuentan como participantes.' : 'Las mascotas no cuentan como participantes.' ) : '',
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
			'terms_text'            => trim( $s['terms_text'] . "\n" . (string) Settings::get( 'terms_text' ) ),
			'privacy_url'           => (string) Settings::get( 'privacy_url' ),
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
	public static function availability( array $service, string $from, string $to ): array {
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
		$blocks   = Schedules::blocks( $resource, $rangeFrom, $rangeTo );
		$counts   = Schedules::dayCounts( (int) $service['id'], $rangeFrom, $rangeTo, $now );
		$days     = $engine->days( Schedules::schedule( $resource ), $params, $from, $to, $now, $busy, $blocks, $counts );

		$pricing  = new Pricing( $zone );
		$rules    = Services::rules( (int) $service['id'] );
		$out      = array();
		$prices   = array();
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
			$price = null;
			if ( $day['slots'] ) {
				try {
					$q     = $pricing->quote(
						$service,
						$rules,
						array(
							'start'        => $day['slots'][0]['start'],
							'requested_at' => $now,
							'category'     => Pricing::CATEGORY_GENERAL,
							'participants' => (int) $service['participants_included'],
						)
					);
					$price = $q['base_cents'] - $q['discount_cents'];
				} catch ( DomainException $e ) {
					$price = null;
				}
			}
			if ( null !== $price ) {
				$prices[] = $price;
			}
			$available    = count( array_filter( $slots, static function ( $s ) { return $s['available']; } ) );
			$out[ $date ] = array(
				'date'      => $date,
				'open'      => $day['open'],
				'available' => $available,
				'total'     => count( $slots ),
				'status'    => ! $day['open'] ? 'closed' : ( $available ? 'available' : 'sold_out' ),
				'price'     => $price,
				'price_txt' => null !== $price ? Money::format( $price ) : null,
				'notices'   => Services::noticesFor( $service, $date ),
				'slots'     => $slots,
			);
		}
		// Precio especial por fecha: más bajo que la tarifa más alta de las fechas consultadas
		// para la misma categoría (general) y el mismo periodo de reserva (ahora).
		$reference = $prices ? max( $prices ) : null;
		foreach ( $out as &$day ) {
			$day['special'] = null !== $day['price'] && null !== $reference && $day['price'] < $reference;
			$day['saving']  = $day['special'] ? $reference - $day['price'] : 0;
			$day['saving_txt'] = $day['special'] ? Money::format( $reference - $day['price'] ) : null;
		}
		unset( $day );
		return array(
			'timezone' => Settings::timezoneInfo()['requested'],
			'days'     => array_values( $out ),
			'note'     => 'Precio general orientativo por fecha para el número de personas incluido. El precio final se confirma en el resumen.',
		);
	}

	public static function reasonLabel( ?string $reason ): string {
		switch ( $reason ) {
			case Availability::REASON_BOOKED:
			case Availability::REASON_MAX_PER_DAY:
				return 'Agotado';
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

		$slot = self::findAvailableSlot( $service, $start, $now );
		if ( ! $slot ) {
			throw new DomainException( 'slot_unavailable', 'Ese horario ya no está disponible. Elige otro, por favor.', 409, array( 'field' => 'start' ) );
		}
		$context = self::pricingContext( $input, $start, $now );
		$result  = ( new Pricing( Settings::timezone() ) )->quote( $service, Services::rules( (int) $service['id'] ), $context );
		$key     = bin2hex( random_bytes( 16 ) );
		$expires = $now->modify( '+' . self::QUOTE_MINUTES . ' minutes' );
		$payload = array(
			'input'  => array(
				'start'        => $start->format( 'Y-m-d\TH:i:s\Z' ),
				'participants' => $context['participants'],
				'pets'         => $context['pets'],
				'extras'       => $context['extras'],
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

	public static function findAvailableSlot( array $service, \DateTimeImmutable $start, \DateTimeImmutable $now, ?int $excludeBookingId = null ): ?array {
		$zone     = Settings::timezone();
		$engine   = new Availability( $zone );
		$params   = Services::availabilityParams( $service );
		$resource = (int) $service['resource_id'];
		$from     = $start->modify( '-2 days' );
		$to       = $start->modify( '+2 days' );
		$slot     = $engine->findSlot(
			Schedules::schedule( $resource ),
			$params,
			$start,
			$now,
			Schedules::busy( $resource, $from, $to, $now, $excludeBookingId ),
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
		$participants = $input['participants'] ?? 1;
		$pets         = $input['pets'] ?? 0;
		if ( ! is_numeric( $participants ) || ! is_numeric( $pets ) ) {
			throw new DomainException( 'invalid_participants', 'Indica cantidades con números.', 400, array( 'field' => 'participants' ) );
		}
		return array(
			'start'        => $start,
			'requested_at' => $requestedAt,
			'category'     => $category,
			'participants' => (int) $participants,
			'pets'         => (int) $pets,
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
