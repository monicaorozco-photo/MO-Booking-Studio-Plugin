<?php
namespace MOBookingStudio\Application;

use MOBookingStudio\Domain\Contact;
use MOBookingStudio\Domain\DomainException;
use MOBookingStudio\Domain\Names;
use MOBookingStudio\Domain\Pricing;
use MOBookingStudio\Infrastructure\Audit;
use MOBookingStudio\Infrastructure\Db;
use MOBookingStudio\Infrastructure\Schema;
use MOBookingStudio\Plugin;

/**
 * Expedientes de clientes. La coincidencia usa correo normalizado o teléfono con país;
 * el nombre nunca basta. Un contacto que apunta a expedientes distintos se envía a
 * revisión sin fusionar.
 */
final class Clients {
	public const FREQUENT_THRESHOLD_OPTION = 'mo_booking_frequent_threshold';

	public static function frequentThreshold(): int {
		return max( 1, (int) get_option( self::FREQUENT_THRESHOLD_OPTION, 3 ) );
	}

	public static function find( int $id ): ?array {
		$wpdb = Db::wpdb();
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'clients' ) . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ?: null;
	}

	/**
	 * Categoría efectiva: la manual prevalece; si no hay, se calcula con el umbral.
	 */
	public static function effectiveCategory( array $client ): string {
		if ( ! empty( $client['manual_category'] ) ) {
			return $client['manual_category'];
		}
		$sessions = (int) $client['historic_sessions'] + (int) $client['completed_sessions'];
		return $sessions >= self::frequentThreshold() ? Pricing::CATEGORY_FREQUENT : Pricing::CATEGORY_GENERAL;
	}

	public static function sessions( array $client ): int {
		return (int) $client['historic_sessions'] + (int) $client['completed_sessions'];
	}

	/**
	 * Busca un expediente para una reserva pública o lo crea. No actualiza datos de un
	 * expediente existente con lo que escribió la persona.
	 *
	 * @return array{id:int,matched:bool,review:bool}
	 */
	public static function matchOrCreate( string $name, ?string $email, ?string $phoneE164, string $phoneRaw, string $country, bool $isTest = false ): array {
		$byMail  = $email ? self::idsByEmail( $email ) : array();
		$byPhone = $phoneE164 ? self::idsByPhone( $phoneE164 ) : array();

		if ( 1 === count( $byMail ) && ( ! $byPhone || in_array( $byMail[0], $byPhone, true ) ) ) {
			return array( 'id' => $byMail[0], 'matched' => true, 'review' => false );
		}
		if ( ! $byMail && 1 === count( $byPhone ) && ! $email ) {
			return array( 'id' => $byPhone[0], 'matched' => true, 'review' => false );
		}
		$review = (bool) ( $byMail || $byPhone );
		$id     = self::create(
			array(
				'name'          => $name,
				'email'         => (string) $email,
				'phone'         => $phoneRaw,
				'phone_country' => $country,
				'needs_review'  => $review,
				'review_note'   => $review ? 'El correo o el teléfono coinciden con otro expediente. Revisar antes de unir.' : '',
			),
			$isTest
		);
		return array( 'id' => $id, 'matched' => false, 'review' => $review );
	}

	/**
	 * Clientes (fuera de la papelera) con ese correo normalizado como principal o adicional.
	 *
	 * @return int[]
	 */
	public static function idsByEmail( string $emailNorm ): array {
		return self::idsByContact( 'email', 'email_norm', $emailNorm );
	}

	/** @return int[] */
	public static function idsByPhone( string $phoneE164 ): array {
		return self::idsByContact( 'phone', 'phone_e164', $phoneE164 );
	}

	private static function idsByContact( string $kind, string $column, string $value ): array {
		if ( '' === $value ) {
			return array();
		}
		$wpdb    = Db::wpdb();
		$clients = Schema::table( 'clients' );
		$sql     = "SELECT id FROM $clients WHERE $column = %s AND trashed_at IS NULL
			UNION SELECT c.id FROM " . Schema::table( 'client_contacts' ) . " k JOIN $clients c ON c.id = k.client_id WHERE k.kind = %s AND k.value_norm = %s AND c.trashed_at IS NULL";
		$ids     = $wpdb->get_col( $wpdb->prepare( $sql, $value, $kind, $value ) ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$ids     = array_values( array_unique( array_map( 'intval', $ids ) ) );
		sort( $ids );
		return $ids;
	}

	// ----- Contactos adicionales -----

	public static function contacts( int $clientId ): array {
		$wpdb = Db::wpdb();
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'client_contacts' ) . ' WHERE client_id = %d ORDER BY kind, id', $clientId ), ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Agrega un correo o teléfono adicional. Si ya es el principal o ya está, no se repite.
	 *
	 * @return int|null Id del contacto agregado o null si ya existía.
	 */
	public static function addContact( int $clientId, string $kind, string $value, string $country = 'MX', string $label = '' ): ?int {
		$client = self::find( $clientId );
		if ( ! $client ) {
			throw new DomainException( 'not_found', 'El cliente no existe.', 404 );
		}
		$value   = trim( $value );
		$country = 'US' === strtoupper( $country ) ? 'US' : 'MX';
		if ( 'email' === $kind ) {
			$norm = Contact::normalizeEmail( $value );
			if ( ! $norm ) {
				throw new DomainException( 'invalid_email', 'Escribe un correo válido.', 400, array( 'field' => 'contact_value' ) );
			}
			$primary = $client['email_norm'];
		} elseif ( 'phone' === $kind ) {
			$norm = Contact::normalizePhone( $value, $country );
			if ( ! $norm ) {
				throw new DomainException( 'invalid_phone', 'Escribe un teléfono válido (10 dígitos).', 400, array( 'field' => 'contact_value' ) );
			}
			$primary = $client['phone_e164'];
		} else {
			throw new DomainException( 'invalid_contact', 'Tipo de contacto no válido.' );
		}
		if ( $primary === $norm ) {
			return null;
		}
		foreach ( self::contacts( $clientId ) as $c ) {
			if ( $c['kind'] === $kind && $c['value_norm'] === $norm ) {
				return null;
			}
		}
		$wpdb = Db::wpdb();
		Db::check(
			$wpdb->insert(
				Schema::table( 'client_contacts' ),
				array(
					'client_id'     => $clientId,
					'kind'          => $kind,
					'value'         => mb_substr( $value, 0, 190 ),
					'value_norm'    => $norm,
					'phone_country' => $country,
					'label'         => mb_substr( sanitize_text_field( $label ), 0, 60 ),
					'created_at'    => Db::toSql( Plugin::clock()->now() ),
				)
			),
			'contact_insert'
		);
		$id = (int) $wpdb->insert_id;
		Audit::log( 'client', $clientId, 'contact_added', array( 'kind' => $kind ) );
		return $id;
	}

	public static function removeContact( int $clientId, int $contactId ): void {
		$deleted = Db::wpdb()->delete( Schema::table( 'client_contacts' ), array( 'id' => $contactId, 'client_id' => $clientId ) );
		if ( ! $deleted ) {
			throw new DomainException( 'not_found', 'Ese contacto no existe.', 404 );
		}
		Audit::log( 'client', $clientId, 'contact_removed' );
	}

	// ----- Duplicados y fusión -----

	public const NOT_DUPLICATES_OPTION = 'mo_booking_not_duplicates';

	private static function pairKey( int $a, int $b ): string {
		return min( $a, $b ) . '-' . max( $a, $b );
	}

	/** "No son la misma persona": la sugerencia deja de mostrarse. */
	public static function dismissDuplicate( int $a, int $b ): void {
		$list = (array) get_option( self::NOT_DUPLICATES_OPTION, array() );
		$list[ self::pairKey( $a, $b ) ] = 1;
		update_option( self::NOT_DUPLICATES_OPTION, $list, false );
		Audit::log( 'client', min( $a, $b ), 'not_duplicate', array( 'other' => max( $a, $b ) ) );
	}

	/**
	 * Posibles duplicados entre clientes fuera de la papelera: mismo correo o teléfono
	 * (principal o adicional) o nombre igual sin acentos / con faltas leves. Solo sugiere;
	 * nunca fusiona por su cuenta.
	 *
	 * @param int|null $clientId Solo los parecidos a este cliente.
	 * @return array<int,array{a:array,b:array,reasons:string[]}>
	 */
	public static function duplicateSuggestions( ?int $clientId = null, int $limit = 100 ): array {
		$wpdb    = Db::wpdb();
		$clients = $wpdb->get_results( 'SELECT id, name, email, email_norm, phone, phone_e164, is_test, historic_sessions, completed_sessions, manual_category, created_at FROM ' . Schema::table( 'clients' ) . ' WHERE trashed_at IS NULL', ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$byId    = array();
		$owners  = array();
		foreach ( $clients as $c ) {
			$id          = (int) $c['id'];
			$byId[ $id ] = $c;
			if ( $c['email_norm'] ) {
				$owners[ 'email:' . $c['email_norm'] ][ $id ] = true;
			}
			if ( $c['phone_e164'] ) {
				$owners[ 'phone:' . $c['phone_e164'] ][ $id ] = true;
			}
		}
		foreach ( $wpdb->get_results( 'SELECT client_id, kind, value_norm FROM ' . Schema::table( 'client_contacts' ), ARRAY_A ) ?: array() as $k ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( isset( $byId[ (int) $k['client_id'] ] ) && $k['value_norm'] ) {
				$owners[ $k['kind'] . ':' . $k['value_norm'] ][ (int) $k['client_id'] ] = true;
			}
		}
		$dismissed = (array) get_option( self::NOT_DUPLICATES_OPTION, array() );
		$pairs     = array();
		$add       = static function ( int $a, int $b, string $reason ) use ( &$pairs, $dismissed, $clientId ) {
			if ( $a === $b || isset( $dismissed[ self::pairKey( $a, $b ) ] ) || ( $clientId && $a !== $clientId && $b !== $clientId ) ) {
				return;
			}
			$pairs[ self::pairKey( $a, $b ) ][ $reason ] = true;
		};
		foreach ( $owners as $key => $ids ) {
			$ids = array_keys( $ids );
			$why = 0 === strpos( $key, 'email:' ) ? 'Mismo correo' : 'Mismo teléfono';
			for ( $i = 0, $n = count( $ids ); $i < $n; $i++ ) {
				for ( $j = $i + 1; $j < $n; $j++ ) {
					$add( $ids[ $i ], $ids[ $j ], $why );
				}
			}
		}
		// Nombres: se comparan dentro de grupos por inicial para no comparar todos contra todos.
		$buckets = array();
		foreach ( $byId as $id => $c ) {
			$key = Names::key( (string) $c['name'] );
			if ( strlen( $key ) >= 4 ) {
				$buckets[ $key[0] ][ $id ] = $key;
			}
		}
		foreach ( $buckets as $bucket ) {
			$ids = array_keys( $bucket );
			for ( $i = 0, $n = count( $ids ); $i < $n; $i++ ) {
				for ( $j = $i + 1; $j < $n; $j++ ) {
					$a = $bucket[ $ids[ $i ] ];
					$b = $bucket[ $ids[ $j ] ];
					if ( $a === $b ) {
						$add( $ids[ $i ], $ids[ $j ], 'Mismo nombre' );
					} elseif ( Names::similar( $a, $b ) ) {
						$add( $ids[ $i ], $ids[ $j ], 'Nombre parecido' );
					}
				}
			}
		}
		$out = array();
		foreach ( $pairs as $key => $reasons ) {
			list( $a, $b ) = array_map( 'intval', explode( '-', $key ) );
			$out[] = array( 'a' => $byId[ $a ], 'b' => $byId[ $b ], 'reasons' => array_keys( $reasons ) );
		}
		// Primero los que comparten contacto (más probables).
		usort(
			$out,
			static function ( $x, $y ) {
				$sx = count( array_diff( $x['reasons'], array( 'Nombre parecido', 'Mismo nombre' ) ) );
				$sy = count( array_diff( $y['reasons'], array( 'Nombre parecido', 'Mismo nombre' ) ) );
				return $sy <=> $sx ?: strcmp( (string) $x['a']['name'], (string) $y['a']['name'] );
			}
		);
		return array_slice( $out, 0, $limit );
	}

	/**
	 * Vista previa de la fusión: qué pasa al cliente principal.
	 *
	 * @return array{bookings:int,contacts:string[],historic:int,completed:int}
	 */
	public static function previewMerge( int $principalId, int $duplicateId ): array {
		list( $principal, $duplicate ) = self::mergePair( $principalId, $duplicateId );
		$wpdb     = Db::wpdb();
		$bookings = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'bookings' ) . ' WHERE client_id = %d', $duplicateId ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array(
			'bookings'  => $bookings,
			'contacts'  => array_column( self::contactsToCarry( $principal, $duplicate ), 'value' ),
			'historic'  => max( (int) $principal['historic_sessions'], (int) $duplicate['historic_sessions'] ),
			'completed' => (int) $principal['completed_sessions'] + (int) $duplicate['completed_sessions'],
		);
	}

	/**
	 * Fusiona dos expedientes: las reservas del duplicado pasan al principal, sus correos y
	 * teléfonos quedan como contactos adicionales y el duplicado va a la papelera (se puede
	 * restaurar, pero sus reservas se quedan en el principal).
	 *
	 * Sesiones: las completadas se suman (vienen de las reservas que se mueven); las previas
	 * al sistema toman el mayor de los dos, porque suelen ser las mismas sesiones registradas
	 * dos veces.
	 */
	public static function merge( int $principalId, int $duplicateId ): array {
		return Db::transaction(
			static function () use ( $principalId, $duplicateId ) {
				list( $principal, $duplicate ) = self::mergePair( $principalId, $duplicateId );
				$wpdb    = Db::wpdb();
				$preview = self::previewMerge( $principalId, $duplicateId );
				foreach ( self::contactsToCarry( $principal, $duplicate ) as $c ) {
					self::addContact( $principalId, $c['kind'], $c['value'], $c['country'], $c['label'] );
				}
				$wpdb->delete( Schema::table( 'client_contacts' ), array( 'client_id' => $duplicateId ) );
				$wpdb->update( Schema::table( 'bookings' ), array( 'client_id' => $principalId ), array( 'client_id' => $duplicateId ) );
				$notes = trim( (string) $principal['notes'] );
				if ( '' !== trim( (string) $duplicate['notes'] ) ) {
					$notes = trim( $notes . "

[De " . $duplicate['name'] . '] ' . trim( (string) $duplicate['notes'] ) );
				}
				$now = Db::toSql( Plugin::clock()->now() );
				$wpdb->update(
					Schema::table( 'clients' ),
					array(
						'historic_sessions'  => $preview['historic'],
						'completed_sessions' => $preview['completed'],
						'manual_category'    => $principal['manual_category'] ?: $duplicate['manual_category'],
						'notes'              => $notes,
						'needs_review'       => 0,
						'updated_at'         => $now,
					),
					array( 'id' => $principalId )
				);
				$wpdb->update(
					Schema::table( 'clients' ),
					array( 'trashed_at' => $now, 'merged_into' => $principalId, 'completed_sessions' => 0, 'updated_at' => $now ),
					array( 'id' => $duplicateId )
				);
				Audit::log( 'client', $principalId, 'merged', array( 'duplicate' => $duplicateId, 'bookings' => $preview['bookings'] ) );
				Audit::log( 'client', $duplicateId, 'merged_into', array( 'principal' => $principalId, 'bookings' => $preview['bookings'] ) );
				return $preview;
			}
		);
	}

	private static function mergePair( int $principalId, int $duplicateId ): array {
		if ( $principalId === $duplicateId ) {
			throw new DomainException( 'invalid_merge', 'Elige dos clientes distintos.' );
		}
		$principal = self::find( $principalId );
		$duplicate = self::find( $duplicateId );
		if ( ! $principal || ! $duplicate ) {
			throw new DomainException( 'not_found', 'El cliente no existe.', 404 );
		}
		if ( $principal['trashed_at'] || $duplicate['trashed_at'] ) {
			throw new DomainException( 'in_trash', 'Uno de los clientes está en la papelera. Restáuralo antes de fusionar.', 409 );
		}
		return array( $principal, $duplicate );
	}

	/** Correos y teléfonos del duplicado que el principal todavía no tiene. */
	private static function contactsToCarry( array $principal, array $duplicate ): array {
		$have = array( 'email' => array( (string) $principal['email_norm'] ), 'phone' => array( (string) $principal['phone_e164'] ) );
		foreach ( self::contacts( (int) $principal['id'] ) as $c ) {
			$have[ $c['kind'] ][] = (string) $c['value_norm'];
		}
		$candidates = array();
		if ( $duplicate['email_norm'] ) {
			$candidates[] = array( 'kind' => 'email', 'value' => $duplicate['email'], 'norm' => $duplicate['email_norm'], 'country' => 'MX', 'label' => 'De ' . $duplicate['name'] );
		}
		if ( $duplicate['phone_e164'] ) {
			$candidates[] = array( 'kind' => 'phone', 'value' => $duplicate['phone'], 'norm' => $duplicate['phone_e164'], 'country' => $duplicate['phone_country'], 'label' => 'De ' . $duplicate['name'] );
		}
		foreach ( self::contacts( (int) $duplicate['id'] ) as $c ) {
			$candidates[] = array( 'kind' => $c['kind'], 'value' => $c['value'], 'norm' => $c['value_norm'], 'country' => $c['phone_country'], 'label' => $c['label'] );
		}
		$out = array();
		foreach ( $candidates as $c ) {
			if ( ! in_array( (string) $c['norm'], $have[ $c['kind'] ], true ) ) {
				$have[ $c['kind'] ][] = (string) $c['norm'];
				$out[]                = $c;
			}
		}
		return $out;
	}

	public static function create( array $data, bool $isTest = false ): int {
		$name = trim( (string) ( $data['name'] ?? '' ) );
		if ( '' === $name ) {
			throw new DomainException( 'invalid_client', 'El cliente necesita un nombre.' );
		}
		$country = strtoupper( (string) ( $data['phone_country'] ?? 'MX' ) );
		$country = in_array( $country, array( 'MX', 'US' ), true ) ? $country : 'MX';
		$now     = Db::toSql( Plugin::clock()->now() );
		$wpdb    = Db::wpdb();
		Db::check(
			$wpdb->insert(
				Schema::table( 'clients' ),
				array(
					'name'              => $name,
					'email'             => trim( (string) ( $data['email'] ?? '' ) ),
					'email_norm'        => Contact::normalizeEmail( $data['email'] ?? '' ),
					'phone'             => trim( (string) ( $data['phone'] ?? '' ) ),
					'phone_country'     => $country,
					'phone_e164'        => Contact::normalizePhone( $data['phone'] ?? '', $country ),
					'category'          => 'general',
					'manual_category'   => self::cleanCategory( $data['manual_category'] ?? null ),
					'historic_sessions' => max( 0, (int) ( $data['historic_sessions'] ?? 0 ) ),
					'needs_review'      => ! empty( $data['needs_review'] ) ? 1 : 0,
					'review_note'       => (string) ( $data['review_note'] ?? '' ),
					'notes'             => (string) ( $data['notes'] ?? '' ),
					'is_test'           => $isTest ? 1 : 0,
					'created_at'        => $now,
					'updated_at'        => $now,
				)
			),
			'client_insert'
		);
		$id = (int) $wpdb->insert_id;
		Audit::log( 'client', $id, 'created' );
		return $id;
	}

	public static function update( int $id, array $data ): void {
		$client = self::find( $id );
		if ( ! $client ) {
			throw new DomainException( 'not_found', 'El cliente no existe.', 404 );
		}
		$name = trim( (string) ( $data['name'] ?? $client['name'] ) );
		if ( '' === $name ) {
			throw new DomainException( 'invalid_client', 'El cliente necesita un nombre.' );
		}
		$country = strtoupper( (string) ( $data['phone_country'] ?? $client['phone_country'] ) );
		$country = in_array( $country, array( 'MX', 'US' ), true ) ? $country : 'MX';
		$phone   = trim( (string) ( $data['phone'] ?? $client['phone'] ) );
		$email   = trim( (string) ( $data['email'] ?? $client['email'] ) );
		$row     = array(
			'name'              => $name,
			'email'             => $email,
			'email_norm'        => Contact::normalizeEmail( $email ),
			'phone'             => $phone,
			'phone_country'     => $country,
			'phone_e164'        => Contact::normalizePhone( $phone, $country ),
			'manual_category'   => array_key_exists( 'manual_category', $data ) ? self::cleanCategory( $data['manual_category'] ) : $client['manual_category'],
			'historic_sessions' => array_key_exists( 'historic_sessions', $data ) ? max( 0, (int) $data['historic_sessions'] ) : (int) $client['historic_sessions'],
			'needs_review'      => array_key_exists( 'needs_review', $data ) ? ( $data['needs_review'] ? 1 : 0 ) : (int) $client['needs_review'],
			'notes'             => (string) ( $data['notes'] ?? $client['notes'] ),
			'updated_at'        => Db::toSql( Plugin::clock()->now() ),
		);
		Db::check( Db::wpdb()->update( Schema::table( 'clients' ), $row, array( 'id' => $id ) ), 'client_update' );
		$changes = array();
		foreach ( array( 'manual_category', 'historic_sessions', 'name', 'email', 'phone' ) as $key ) {
			if ( (string) $client[ $key ] !== (string) $row[ $key ] ) {
				$changes[ $key ] = array( $client[ $key ], $row[ $key ] );
			}
		}
		Audit::log( 'client', $id, 'updated', $changes );
	}

	/** Suma o resta una sesión completada registrada por el plugin. */
	public static function adjustCompleted( int $id, int $delta, string $reason ): void {
		$wpdb = Db::wpdb();
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Schema::table( 'clients' ) . ' SET completed_sessions = GREATEST(0, CAST(completed_sessions AS SIGNED) + %d) WHERE id = %d', $delta, $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		Audit::log( 'client', $id, $delta > 0 ? 'session_completed' : 'session_reverted', array( 'reason' => $reason ) );
	}

	public static function search( string $term = '', int $limit = 50, int $offset = 0, bool $archived = false ): array {
		$wpdb  = Db::wpdb();
		$table = Schema::table( 'clients' );
		$arch  = ( $archived ? 'archived_at IS NOT NULL' : 'archived_at IS NULL' ) . ' AND trashed_at IS NULL';
		if ( '' === trim( $term ) ) {
			return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE $arch ORDER BY needs_review DESC, name LIMIT %d OFFSET %d", $limit, $offset ), ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$like = '%' . $wpdb->esc_like( trim( $term ) ) . '%';
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE $arch AND (name LIKE %s OR email LIKE %s OR phone LIKE %s) ORDER BY name LIMIT %d OFFSET %d", $like, $like, $like, $limit, $offset ), ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** 10.4: archivar no borra; restaurar lo recupera. */
	public static function setArchived( int $id, bool $archived ): void {
		if ( ! self::find( $id ) ) {
			throw new DomainException( 'not_found', 'El cliente no existe.', 404 );
		}
		Db::wpdb()->update( Schema::table( 'clients' ), array( 'archived_at' => $archived ? Db::toSql( \MOBookingStudio\Plugin::clock()->now() ) : null ), array( 'id' => $id ) );
		Audit::log( 'client', $id, $archived ? 'archived' : 'restored' );
	}

	/** Eliminar solo sin reservas (y por tanto sin pagos, comprobantes ni contratos). */
	public static function delete( int $id ): void {
		$wpdb     = Db::wpdb();
		$bookings = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'bookings' ) . ' WHERE client_id = %d', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $bookings ) {
			throw new DomainException( 'has_dependencies', sprintf( 'No se puede eliminar: tiene %d reserva(s) con su historial. Archívalo o anonimízalo.', $bookings ), 409 );
		}
		$wpdb->delete( Schema::table( 'clients' ), array( 'id' => $id ) );
		Audit::log( 'client', $id, 'deleted' );
	}

	private static function cleanCategory( $value ): ?string {
		return in_array( $value, array( Pricing::CATEGORY_GENERAL, Pricing::CATEGORY_FREQUENT ), true ) ? $value : null;
	}
}
