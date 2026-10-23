<?php
namespace MOBookingStudio\Application;

use MOBookingStudio\Domain\Availability;
use MOBookingStudio\Domain\DomainException;
use MOBookingStudio\Domain\Policy;
use MOBookingStudio\Domain\Pricing;
use MOBookingStudio\Infrastructure\Audit;
use MOBookingStudio\Infrastructure\Db;
use MOBookingStudio\Infrastructure\Schema;
use MOBookingStudio\Infrastructure\Settings;
use MOBookingStudio\Plugin;

/**
 * Servicios, sus reglas de precio y sus políticas.
 */
final class Services {
	/** Clave reservada del extra de entrega rápida. */
	public const FAST_KEY = 'entrega-rapida';

	/**
	 * Servicio listo para cotizar: agrega la entrega rápida como un extra más (con su
	 * nombre y precio actuales). La cotización congela ambos en la reserva.
	 */
	public static function forPricing( array $service, ?array $frozenQuote = null ): array {
		$service['extras'] = (array) ( $service['extras'] ?? array() );
		$fast              = (array) ( $service['fast_delivery'] ?? array() );
		if ( ! empty( $fast['enabled'] ) ) {
			$service['extras'][] = array(
				'key'         => self::FAST_KEY,
				'label'       => (string) $fast['label'],
				'price_cents' => (int) $fast['price_cents'],
				'max_qty'     => 1,
				'description' => '',
			);
		}
		// Al recotizar una reserva existente, los extras ya contratados conservan su nombre y precio.
		foreach ( (array) ( $frozenQuote['lines'] ?? array() ) as $line ) {
			if ( 'extra' !== ( $line['kind'] ?? '' ) || empty( $line['key'] ) ) {
				continue;
			}
			$frozen = array(
				'key'         => (string) $line['key'],
				'label'       => (string) $line['label'],
				'price_cents' => (int) $line['unit'],
				'max_qty'     => max( 1, (int) $line['quantity'] ),
				'description' => '',
			);
			foreach ( $service['extras'] as $i => $extra ) {
				if ( $extra['key'] === $frozen['key'] ) {
					$frozen['max_qty'] = max( $frozen['max_qty'], (int) $extra['max_qty'] );
					unset( $service['extras'][ $i ] );
				}
			}
			$service['extras'][] = $frozen;
		}
		$service['extras'] = array_values( $service['extras'] );
		return $service;
	}

	public static function defaultConfig(): array {
		return array(
			'description'                => '',
			'location'                   => '',
			'instructions'               => '',
			'delivery_text'              => '',
			'image_id'                   => 0,
			'duration'                   => 60,
			'buffer_before'              => 0,
			'buffer_after'               => 0,
			'interval'                   => 0,
			'min_notice_hours'           => 24,
			'horizon_days'               => 180,
			'max_per_day'                => 0,
			'price_cents'                => 0,
			'participants_included'      => 1,
			'participants_max'           => 1,
			'extra_participant_cents'    => 0,
			'pets_allowed'               => false,
			'pets_max'                   => 0,
			'pet_fee_cents'              => 0,
			'pets_count_as_participants' => false,
			'extras'                     => array(),
			'questions'                  => array(),
			'deposit_type'               => 'fixed',
			'deposit_value'              => 0,
			'hold_hours'                 => 0, // 0 = usar el valor general de Ajustes.
			'confirmation_mode'          => 'manual',
			'sale_from'                  => '',
			'sale_until'                 => '',
			'date_notices'               => array(),
			'terms_text'                 => '',
			'contract_id'                => 0, // 6.5: plantilla de contrato publicada.
			// 6.3 campo 10: extra con precio, siempre al final del formulario.
			'fast_delivery'              => array(
				'enabled'      => false,
				'label'        => 'Entrega rápida',
				'price_cents'  => 0,
				'question'     => '¿Deseas agregar entrega rápida de 2 días hábiles a tu sesión?',
				'regular_text' => 'Entrega regular de 5 a 7 días hábiles.',
			),
			'price_table'                => array( 'presale_until' => '', 'rows' => array() ),
		);
	}

	public static function find( int $id ): ?array {
		$wpdb = Db::wpdb();
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'services' ) . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? self::hydrate( $row ) : null;
	}

	public static function get( int $id ): array {
		$service = self::find( $id );
		if ( ! $service ) {
			throw new DomainException( 'not_found', 'El servicio no existe.', 404 );
		}
		return $service;
	}

	public static function findBySlug( string $slug ): ?array {
		$wpdb = Db::wpdb();
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'services' ) . ' WHERE slug = %s', $slug ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? self::hydrate( $row ) : null;
	}

	public static function findPublished( string $slug ): array {
		$service = self::findBySlug( $slug );
		if ( ! $service || 'published' !== $service['status'] ) {
			throw new DomainException( 'service_unavailable', 'Este servicio no está disponible.', 404 );
		}
		return $service;
	}

	/** @return array[] */
	public const STATUS_LABELS = array(
		'draft'     => 'Borrador',
		'published' => 'Activo (publicado)',
		'inactive'  => 'Desactivado',
		'archived'  => 'Archivado',
		'trash'     => 'En la papelera',
	);

	/** 13: cambia el estado sin tocar su configuración ni las reservas históricas. */
	public static function setStatus( int $id, string $status ): void {
		if ( ! isset( self::STATUS_LABELS[ $status ] ) || Trash::SERVICE_TRASH === $status ) {
			throw new DomainException( 'invalid_service', 'Estado no válido.' );
		}
		$service = self::get( $id );
		if ( 'published' === $status && ! Contracts::forService( $service ) ) {
			throw new DomainException( 'contract_missing', 'Asigna un contrato publicado antes de activar el servicio.' );
		}
		Db::wpdb()->update( Schema::table( 'services' ), array( 'status' => $status, 'updated_at' => Db::toSql( Plugin::clock()->now() ) ), array( 'id' => $id ) );
		Audit::log( 'service', $id, 'status:' . $status, array( 'from' => $service['status'] ) );
	}

	/** Copia la configuración, tarifas y reglas propias en un borrador nuevo. */
	public static function duplicate( int $id ): int {
		$service = self::get( $id );
		$slug    = $service['slug'] . '-copia';
		for ( $n = 2; self::findBySlug( $slug ); $n++ ) {
			$slug = $service['slug'] . '-copia-' . $n;
		}
		$newId = self::save( array_merge( $service, array( 'title' => 'Copia de ' . $service['title'], 'slug' => $slug, 'status' => 'draft' ) ), null, (bool) $service['is_test'] );
		foreach ( self::rules( $id, false ) as $rule ) {
			unset( $rule['id'] );
			self::saveRule( $newId, $rule, null, (bool) $service['is_test'] );
		}
		foreach ( Policy::ACTIONS as $action ) {
			$own = self::ownPolicy( $id, $action );
			if ( $own ) {
				self::savePolicy( $newId, $action, $own['ranges'], (string) $own['summary'], (bool) $service['is_test'] );
			}
		}
		Audit::log( 'service', $newId, 'duplicated', array( 'from' => $id ) );
		return $newId;
	}

	public static function usageCount( int $id ): int {
		$wpdb = Db::wpdb();
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'bookings' ) . ' WHERE service_id = %d', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Solo si nunca se usó: un servicio con historial se desactiva o archiva. */
	public static function delete( int $id ): void {
		$service = self::get( $id );
		$used    = self::usageCount( $id );
		if ( $used > 0 ) {
			throw new DomainException( 'has_dependencies', sprintf( 'No se puede eliminar: tiene %d reserva(s). Desactívalo o archívalo; las reservas históricas lo siguen mostrando.', $used ), 409 );
		}
		$wpdb = Db::wpdb();
		$wpdb->delete( Schema::table( 'price_rules' ), array( 'service_id' => $id ) );
		$wpdb->delete( Schema::table( 'policies' ), array( 'service_id' => $id ) );
		$wpdb->delete( Schema::table( 'quotes' ), array( 'service_id' => $id ) );
		$wpdb->delete( Schema::table( 'services' ), array( 'id' => $id ) );
		Audit::log( 'service', $id, 'deleted', array( 'title' => $service['title'] ) );
	}

	public static function all( ?string $status = null ): array {
		$wpdb  = Db::wpdb();
		$table = Schema::table( 'services' );
		$rows  = $status
			? $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE status = %s ORDER BY title", $status ), ARRAY_A ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			: $wpdb->get_results( "SELECT * FROM $table WHERE status <> 'trash' ORDER BY status = 'published' DESC, title", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( array( self::class, 'hydrate' ), $rows ?: array() );
	}

	public static function hydrate( array $row ): array {
		$config = array_merge( self::defaultConfig(), (array) Db::decode( $row['config'] ) );
		return array_merge(
			$config,
			array(
				'id'          => (int) $row['id'],
				'slug'        => $row['slug'],
				'title'       => $row['title'],
				'brand'       => $row['brand'],
				'status'      => $row['status'],
				'resource_id' => (int) $row['resource_id'],
				'category_id' => $row['category_id'] ? (int) $row['category_id'] : null,
				'version'     => (int) $row['version'],
				'is_test'     => (int) $row['is_test'],
			)
		);
	}

	/**
	 * Crea o actualiza un servicio. Valida antes de guardar.
	 */
	public static function save( array $data, ?int $id = null, bool $isTest = false ): int {
		$title = trim( (string) ( $data['title'] ?? '' ) );
		if ( '' === $title ) {
			throw new DomainException( 'invalid_service', 'El servicio necesita un nombre.' );
		}
		$slug = sanitize_title( (string) ( $data['slug'] ?? '' ) ?: $title );
		if ( '' === $slug ) {
			throw new DomainException( 'invalid_service', 'El identificador (slug) no es válido.' );
		}
		$existing = self::findBySlug( $slug );
		if ( $existing && $existing['id'] !== $id ) {
			throw new DomainException( 'invalid_service', sprintf( 'Ya existe otro servicio con el identificador "%s".', $slug ) );
		}
		$status = (string) ( $data['status'] ?? 'draft' );
		$status = in_array( $status, array( 'draft', 'published', 'inactive', 'archived' ), true ) ? $status : 'draft';

		$config = array_intersect_key( array_merge( self::defaultConfig(), $data ), self::defaultConfig() );
		$config = self::validateConfig( $config );

		$resourceId = (int) ( $data['resource_id'] ?? 0 ) ?: Schedules::defaultResourceId();
		$categoryId = ! empty( $data['category_id'] ) ? (int) $data['category_id'] : null;
		$now        = Db::toSql( Plugin::clock()->now() );
		$wpdb       = Db::wpdb();
		$row        = array(
			'slug'        => $slug,
			'title'       => $title,
			'brand'       => trim( (string) ( $data['brand'] ?? '' ) ),
			'status'      => $status,
			'resource_id' => $resourceId,
			'category_id' => $categoryId,
			'config'      => Db::json( $config ),
			'updated_at'  => $now,
		);
		if ( $id ) {
			$current = self::find( $id );
			if ( ! $current ) {
				throw new DomainException( 'not_found', 'El servicio no existe.', 404 );
			}
			$row['version'] = $current['version'] + 1;
			Db::check( $wpdb->update( Schema::table( 'services' ), $row, array( 'id' => $id ) ), 'service_update' );
			Audit::log( 'service', $id, 'updated', array( 'version' => $row['version'] ) );
			return $id;
		}
		$row['created_at'] = $now;
		$row['version']    = 1;
		$row['is_test']    = $isTest ? 1 : 0;
		Db::check( $wpdb->insert( Schema::table( 'services' ), $row ), 'service_insert' );
		$newId = (int) $wpdb->insert_id;
		Audit::log( 'service', $newId, 'created' );
		return $newId;
	}

	public static function validateConfig( array $c ): array {
		$int = static function ( $v, int $min = 0 ) {
			return max( $min, (int) $v );
		};
		$c['duration']                = $int( $c['duration'], 5 );
		$c['buffer_before']           = $int( $c['buffer_before'] );
		$c['buffer_after']            = $int( $c['buffer_after'] );
		$c['interval']                = $int( $c['interval'] );
		$c['min_notice_hours']        = max( 0, (float) $c['min_notice_hours'] );
		$c['horizon_days']            = $int( $c['horizon_days'] );
		$c['max_per_day']             = $int( $c['max_per_day'] );
		$c['price_cents']             = $int( $c['price_cents'] );
		$c['participants_included']   = $int( $c['participants_included'], 1 );
		$c['participants_max']        = max( $c['participants_included'], $int( $c['participants_max'], 1 ) );
		$c['extra_participant_cents'] = $int( $c['extra_participant_cents'] );
		$c['pets_allowed']            = (bool) $c['pets_allowed'];
		$c['pets_max']                = $int( $c['pets_max'] );
		$c['pet_fee_cents']           = $int( $c['pet_fee_cents'] );
		$c['pets_count_as_participants'] = (bool) $c['pets_count_as_participants'];
		$c['hold_hours']              = max( 0, (float) $c['hold_hours'] );
		$c['image_id']                = $int( $c['image_id'] );
		$c['confirmation_mode']       = 'auto' === $c['confirmation_mode'] ? 'auto' : 'manual';

		if ( ! in_array( $c['deposit_type'], array( 'none', 'fixed', 'percent', 'full' ), true ) ) {
			throw new DomainException( 'invalid_service', 'El tipo de anticipo no es válido.' );
		}
		if ( 'percent' === $c['deposit_type'] ) {
			$c['deposit_value'] = (float) $c['deposit_value'];
			if ( $c['deposit_value'] < 0 || $c['deposit_value'] > 100 ) {
				throw new DomainException( 'invalid_service', 'El anticipo porcentual debe estar entre 0 y 100.' );
			}
		} else {
			$c['deposit_value'] = $int( $c['deposit_value'] );
		}

		foreach ( array( 'sale_from', 'sale_until' ) as $key ) {
			$c[ $key ] = trim( (string) $c[ $key ] );
			if ( '' !== $c[ $key ] && ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $c[ $key ] ) ) {
				throw new DomainException( 'invalid_service', 'Las fechas de venta deben tener el formato AAAA-MM-DD HH:MM.' );
			}
		}

		$extras = array();
		$keys   = array();
		foreach ( (array) $c['extras'] as $extra ) {
			$label = trim( (string) ( $extra['label'] ?? '' ) );
			if ( '' === $label ) {
				continue;
			}
			$key = sanitize_key( (string) ( $extra['key'] ?? '' ) ?: $label );
			if ( isset( $keys[ $key ] ) || self::FAST_KEY === $key ) {
				$key .= '-' . ( count( $keys ) + 1 );
			}
			$keys[ $key ] = true;
			$extras[]     = array(
				'key'         => $key,
				'label'       => $label,
				'price_cents' => $int( $extra['price_cents'] ?? 0 ),
				'max_qty'     => $int( $extra['max_qty'] ?? 1, 1 ),
				'description' => trim( (string) ( $extra['description'] ?? '' ) ),
			);
		}
		$c['extras'] = $extras;

		$fast               = array_merge( self::defaultConfig()['fast_delivery'], (array) $c['fast_delivery'] );
		$c['fast_delivery'] = array(
			'enabled'      => ! empty( $fast['enabled'] ),
			'label'        => trim( sanitize_text_field( (string) $fast['label'] ) ) ?: 'Entrega rápida',
			'price_cents'  => $int( $fast['price_cents'] ),
			'question'     => trim( sanitize_text_field( (string) $fast['question'] ) ) ?: self::defaultConfig()['fast_delivery']['question'],
			'regular_text' => trim( sanitize_text_field( (string) $fast['regular_text'] ) ),
		);
		$c['contract_id']   = $int( $c['contract_id'] );

		$questions = array();
		$qkeys     = array();
		foreach ( (array) $c['questions'] as $q ) {
			$label = trim( (string) ( $q['label'] ?? '' ) );
			if ( '' === $label ) {
				continue;
			}
			$type = (string) ( $q['type'] ?? 'text' );
			$type = in_array( $type, array( 'text', 'textarea', 'select', 'number', 'yesno' ), true ) ? $type : 'text';
			$key  = sanitize_key( (string) ( $q['key'] ?? '' ) ?: 'q' . substr( md5( $label ), 0, 8 ) );
			if ( isset( $qkeys[ $key ] ) ) {
				$key .= '-' . ( count( $qkeys ) + 1 );
			}
			$qkeys[ $key ] = true;
			$options       = array_values( array_filter( array_map( 'trim', is_array( $q['options'] ?? null ) ? $q['options'] : explode( "\n", (string) ( $q['options'] ?? '' ) ) ) ) );
			if ( 'select' === $type && ! $options ) {
				throw new DomainException( 'invalid_service', sprintf( 'La pregunta "%s" necesita opciones.', $label ) );
			}
			$questions[] = array(
				'key'      => $key,
				'label'    => $label,
				'type'     => $type,
				'required' => ! empty( $q['required'] ),
				'options'  => $options,
			);
		}
		$c['questions'] = $questions;

		$notices = array();
		foreach ( (array) $c['date_notices'] as $n ) {
			$text = trim( (string) ( $n['text'] ?? '' ) );
			if ( '' === $text ) {
				continue;
			}
			$from = trim( (string) ( $n['from'] ?? '' ) );
			$to   = trim( (string) ( $n['to'] ?? '' ) ) ?: $from;
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) || $to < $from ) {
				throw new DomainException( 'invalid_service', 'Las fechas de un aviso no son válidas.' );
			}
			$notices[] = array(
				'from' => $from,
				'to'   => $to,
				'text' => $text,
			);
		}
		$c['date_notices'] = $notices;

		foreach ( array( 'description', 'location', 'instructions', 'delivery_text', 'terms_text' ) as $key ) {
			$c[ $key ] = trim( (string) $c[ $key ] );
		}
		$c['price_table'] = Pricing::validateTable( (array) $c['price_table'] );
		// Comprobar que la combinación de duración e intervalo es válida.
		Availability::normalizeParams( self::availabilityParams( $c ) );
		return $c;
	}

	/** Parámetros para el motor de disponibilidad. */
	public static function availabilityParams( array $service ): array {
		$zone = Settings::timezone();
		$toUtc = static function ( $local ) use ( $zone ) {
			if ( ! $local ) {
				return null;
			}
			$d = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $local, $zone );
			return $d ? $d->setTimezone( new \DateTimeZone( 'UTC' ) ) : null;
		};
		return array(
			'duration'           => (int) $service['duration'],
			'buffer_before'      => (int) $service['buffer_before'],
			'buffer_after'       => (int) $service['buffer_after'],
			'interval'           => (int) $service['interval'],
			'min_notice_minutes' => (int) round( (float) $service['min_notice_hours'] * 60 ),
			'horizon_days'       => (int) $service['horizon_days'],
			'max_per_day'        => (int) $service['max_per_day'],
			'sale_from'          => $toUtc( $service['sale_from'] ?? '' ),
			'sale_until'         => $toUtc( $service['sale_until'] ?? '' ),
		);
	}

	public static function holdHours( array $service ): float {
		$hours = (float) ( $service['hold_hours'] ?? 0 );
		return $hours > 0 ? $hours : max( 0.25, (float) Settings::get( 'hold_hours' ) );
	}

	public static function noticesFor( array $service, string $localDate ): array {
		$out = array();
		foreach ( (array) $service['date_notices'] as $n ) {
			if ( $localDate >= $n['from'] && $localDate <= $n['to'] ) {
				$out[] = $n['text'];
			}
		}
		return $out;
	}

	// ----- Reglas de precio -----

	public static function rules( int $serviceId, bool $activeOnly = true ): array {
		$wpdb  = Db::wpdb();
		$table = Schema::table( 'price_rules' );
		$sql   = "SELECT * FROM $table WHERE service_id = %d" . ( $activeOnly ? ' AND active = 1' : '' ) . ' ORDER BY kind, priority DESC, id';
		$rows  = $wpdb->get_results( $wpdb->prepare( $sql, $serviceId ), ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_map( array( self::class, 'hydrateRule' ), $rows );
	}

	public static function hydrateRule( array $row ): array {
		return array(
			'id'          => (int) $row['id'],
			'service_id'  => (int) $row['service_id'],
			'name'        => $row['name'],
			'kind'        => $row['kind'],
			'priority'    => (int) $row['priority'],
			'amount_type' => $row['amount_type'],
			'value'       => 'percent' === $row['amount_type'] && 'discount' === $row['kind'] ? (float) $row['value'] : (int) round( (float) $row['value'] ),
			'stackable'   => (bool) $row['stackable'],
			'conditions'  => (array) Db::decode( $row['conditions'] ),
			'active'      => (bool) $row['active'],
		);
	}

	public static function findRule( int $id ): ?array {
		$wpdb = Db::wpdb();
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'price_rules' ) . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? self::hydrateRule( $row ) : null;
	}

	/**
	 * Guarda una regla y rechaza el cambio si deja reglas ambiguas.
	 */
	public static function saveRule( int $serviceId, array $rule, ?int $id = null, bool $isTest = false ): int {
		$rule['kind']        = 'discount' === ( $rule['kind'] ?? '' ) ? 'discount' : 'rate';
		$rule['amount_type'] = 'discount' === $rule['kind'] && 'percent' === ( $rule['amount_type'] ?? '' ) ? 'percent' : 'fixed';
		$rule['priority']    = (int) ( $rule['priority'] ?? 0 );
		$rule['active']      = ! isset( $rule['active'] ) || (bool) $rule['active'];
		$rule['stackable']   = ! empty( $rule['stackable'] );
		$c                   = (array) ( $rule['conditions'] ?? array() );
		$rule['conditions']  = array(
			'session_from' => trim( (string) ( $c['session_from'] ?? '' ) ),
			'session_to'   => trim( (string) ( $c['session_to'] ?? '' ) ),
			'booked_from'  => trim( (string) ( $c['booked_from'] ?? '' ) ),
			'booked_until' => trim( (string) ( $c['booked_until'] ?? '' ) ),
			'category'     => in_array( $c['category'] ?? 'any', array( 'any', 'general', 'frequent' ), true ) ? ( $c['category'] ?? 'any' ) : 'any',
			'weekdays'     => array_values( array_unique( array_filter( array_map( 'intval', (array) ( $c['weekdays'] ?? array() ) ), static function ( $d ) { return $d >= 1 && $d <= 7; } ) ) ),
			'time_from'    => trim( (string) ( $c['time_from'] ?? '' ) ),
			'time_to'      => trim( (string) ( $c['time_to'] ?? '' ) ),
		);
		Pricing::validateRule( $rule );

		$others = array_values(
			array_filter(
				self::rules( $serviceId ),
				static function ( $r ) use ( $id ) {
					return $r['id'] !== $id;
				}
			)
		);
		if ( $rule['active'] ) {
			$candidate       = $rule;
			$candidate['id'] = $id ?: PHP_INT_MAX;
			$conflicts       = Pricing::findConflicts( array_merge( $others, array( $candidate ) ) );
			if ( $conflicts ) {
				throw new DomainException( 'rule_conflict', implode( ' ', $conflicts ), 409 );
			}
		}

		$wpdb = Db::wpdb();
		$now  = Db::toSql( Plugin::clock()->now() );
		$row  = array(
			'service_id'  => $serviceId,
			'name'        => trim( (string) $rule['name'] ),
			'kind'        => $rule['kind'],
			'priority'    => $rule['priority'],
			'amount_type' => $rule['amount_type'],
			'value'       => $rule['value'],
			'stackable'   => $rule['stackable'] ? 1 : 0,
			'conditions'  => Db::json( $rule['conditions'] ),
			'active'      => $rule['active'] ? 1 : 0,
			'updated_at'  => $now,
		);
		if ( $id ) {
			Db::check( $wpdb->update( Schema::table( 'price_rules' ), $row, array( 'id' => $id ) ), 'rule_update' );
			Audit::log( 'price_rule', $id, 'updated', array( 'service_id' => $serviceId ) );
			return $id;
		}
		$row['created_at'] = $now;
		$row['is_test']    = $isTest ? 1 : 0;
		Db::check( $wpdb->insert( Schema::table( 'price_rules' ), $row ), 'rule_insert' );
		$newId = (int) $wpdb->insert_id;
		Audit::log( 'price_rule', $newId, 'created', array( 'service_id' => $serviceId ) );
		return $newId;
	}

	public static function deleteRule( int $id ): void {
		$wpdb = Db::wpdb();
		$wpdb->delete( Schema::table( 'price_rules' ), array( 'id' => $id ) );
		Audit::log( 'price_rule', $id, 'deleted' );
	}

	/** Huella de la configuración de precios; si cambia, una cotización deja de ser válida. */
	public static function pricingHash( array $service, array $rules ): string {
		$keys = array( 'price_cents', 'participants_included', 'participants_max', 'extra_participant_cents', 'pets_allowed', 'pets_max', 'pet_fee_cents', 'pets_count_as_participants', 'extras', 'fast_delivery', 'deposit_type', 'deposit_value', 'price_table' );
		return hash( 'sha256', Db::json( array( array_intersect_key( $service, array_flip( $keys ) ), $rules ) ) );
	}

	// ----- Políticas -----

	/**
	 * Política vigente para una acción: la del servicio o, si no tiene, la general.
	 */
	public static function currentPolicy( int $serviceId, string $action ): ?array {
		$wpdb  = Db::wpdb();
		$table = Schema::table( 'policies' );
		foreach ( array( $serviceId, 0 ) as $sid ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE service_id = %d AND action = %s AND is_current = 1 ORDER BY id DESC LIMIT 1", $sid, $action ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $row ) {
				return self::hydratePolicy( $row );
			}
		}
		return null;
	}

	public static function ownPolicy( int $serviceId, string $action ): ?array {
		$wpdb = Db::wpdb();
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'policies' ) . ' WHERE service_id = %d AND action = %s AND is_current = 1 ORDER BY id DESC LIMIT 1', $serviceId, $action ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? self::hydratePolicy( $row ) : null;
	}

	public static function hydratePolicy( array $row ): array {
		return array(
			'id'         => (int) $row['id'],
			'service_id' => (int) $row['service_id'],
			'action'     => $row['action'],
			'version'    => (int) $row['version'],
			'ranges'     => (array) Db::decode( $row['ranges'] ),
			'summary'    => (string) $row['summary'],
			'created_at' => $row['created_at'],
		);
	}

	/**
	 * Guarda una nueva versión de la política. Las reservas existentes conservan la suya.
	 */
	public static function savePolicy( int $serviceId, string $action, array $ranges, string $summary = '', bool $isTest = false ): int {
		if ( ! in_array( $action, Policy::ACTIONS, true ) ) {
			throw new DomainException( 'invalid_policy', 'La acción de la política no es válida.' );
		}
		$ranges = Policy::validateRanges( $ranges );
		if ( '' === trim( $summary ) ) {
			$summary = implode( "\n", array_map( array( Policy::class, 'describeRange' ), $ranges ) );
		}
		return Db::transaction(
			static function () use ( $serviceId, $action, $ranges, $summary, $isTest ) {
				$wpdb    = Db::wpdb();
				$table   = Schema::table( 'policies' );
				$version = 1 + (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(version) FROM $table WHERE service_id = %d AND action = %s", $serviceId, $action ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( $wpdb->prepare( "UPDATE $table SET is_current = 0 WHERE service_id = %d AND action = %s", $serviceId, $action ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				Db::check(
					$wpdb->insert(
						$table,
						array(
							'service_id' => $serviceId,
							'action'     => $action,
							'version'    => $version,
							'ranges'     => Db::json( $ranges ),
							'summary'    => trim( $summary ),
							'is_current' => 1,
							'is_test'    => $isTest ? 1 : 0,
							'created_by' => get_current_user_id(),
							'created_at' => Db::toSql( Plugin::clock()->now() ),
						)
					),
					'policy_insert'
				);
				$id = (int) $wpdb->insert_id;
				Audit::log( 'policy', $id, 'created', array( 'service_id' => $serviceId, 'action' => $action, 'version' => $version ) );
				return $id;
			}
		);
	}

	public static function removeOwnPolicy( int $serviceId, string $action ): void {
		$wpdb = Db::wpdb();
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table( 'policies' ) . ' SET is_current = 0 WHERE service_id = %d AND action = %s', $serviceId, $action ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		Audit::log( 'policy', 0, 'service_policy_removed', array( 'service_id' => $serviceId, 'action' => $action ) );
	}

	/** Instantánea de las políticas vigentes que acepta la persona al reservar. */
	public static function policySnapshot( int $serviceId ): array {
		$out = array();
		foreach ( Policy::ACTIONS as $action ) {
			$p              = self::currentPolicy( $serviceId, $action );
			$out[ $action ] = $p ? array(
				'policy_id' => $p['id'],
				'version'   => $p['version'],
				'ranges'    => $p['ranges'],
				'summary'   => $p['summary'],
			) : null;
		}
		return $out;
	}

	// ----- Categorías -----

	public static function categories(): array {
		$wpdb = Db::wpdb();
		return $wpdb->get_results( 'SELECT * FROM ' . Schema::table( 'categories' ) . ' ORDER BY name', ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function saveCategory( string $name, ?int $id = null, bool $isTest = false ): int {
		$name = trim( $name );
		if ( '' === $name ) {
			throw new DomainException( 'invalid_category', 'La categoría necesita un nombre.' );
		}
		$wpdb = Db::wpdb();
		if ( $id ) {
			// Renombrar no cambia el identificador estable ni los vínculos.
			$wpdb->update( Schema::table( 'categories' ), array( 'name' => $name ), array( 'id' => $id ) );
			return $id;
		}
		$slug = sanitize_title( $name );
		$base = $slug;
		$n    = 2;
		while ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Schema::table( 'categories' ) . ' WHERE slug = %s', $slug ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$slug = $base . '-' . $n++;
		}
		Db::check(
			$wpdb->insert(
				Schema::table( 'categories' ),
				array(
					'name'       => $name,
					'slug'       => $slug,
					'is_test'    => $isTest ? 1 : 0,
					'created_at' => Db::toSql( Plugin::clock()->now() ),
				)
			),
			'category_insert'
		);
		return (int) $wpdb->insert_id;
	}
}
