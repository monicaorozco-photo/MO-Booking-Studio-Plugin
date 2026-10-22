<?php
namespace MOBookingStudio\Application;

use MOBookingStudio\Domain\Contact;
use MOBookingStudio\Domain\DomainException;
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
		$wpdb   = Db::wpdb();
		$table  = Schema::table( 'clients' );
		$byMail = $email ? $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $table WHERE email_norm = %s", $email ) ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$byPhone = $phoneE164 ? $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $table WHERE phone_e164 = %s", $phoneE164 ) ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$byMail  = array_map( 'intval', $byMail ?: array() );
		$byPhone = array_map( 'intval', $byPhone ?: array() );

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
		$arch  = $archived ? 'archived_at IS NOT NULL' : 'archived_at IS NULL';
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
