<?php
namespace MOBookingStudio\Application;

use MOBookingStudio\Domain\DomainException;
use MOBookingStudio\Infrastructure\Audit;
use MOBookingStudio\Infrastructure\Db;
use MOBookingStudio\Infrastructure\Schema;
use MOBookingStudio\Plugin;

/**
 * Contratos (6.5): plantillas editables con versiones inmutables.
 *
 * - La plantilla guarda un borrador que se puede previsualizar sin afectar a nadie.
 * - "Publicar" crea una versión nueva (número, título, texto y hash SHA-256). Las
 *   versiones publicadas nunca se modifican.
 * - Cada servicio elige una plantilla; la reserva congela una copia de la versión vigente
 *   al aceptarla (texto completo, versión, hash y fecha), así que editar la plantilla
 *   después no cambia lo que aceptó la persona.
 * - El HTML se limita a una lista corta de etiquetas (sin scripts, estilos ni eventos).
 */
final class Contracts {
	/** Etiquetas permitidas en el texto del contrato. */
	public static function allowedHtml(): array {
		$plain = array();
		return array(
			'p'          => $plain,
			'br'         => $plain,
			'strong'     => $plain,
			'b'          => $plain,
			'em'         => $plain,
			'i'          => $plain,
			'u'          => $plain,
			'ul'         => $plain,
			'ol'         => $plain,
			'li'         => $plain,
			'h2'         => $plain,
			'h3'         => $plain,
			'h4'         => $plain,
			'blockquote' => $plain,
			'hr'         => $plain,
			'a'          => array( 'href' => true ),
		);
	}

	public static function sanitize( string $html ): string {
		$clean = wp_kses( $html, self::allowedHtml(), array( 'http', 'https', 'mailto', 'tel' ) );
		return trim( (string) $clean );
	}

	public static function hash( string $title, string $body ): string {
		return hash( 'sha256', $title . "\n" . $body );
	}

	public static function all( bool $withArchived = false ): array {
		$wpdb  = Db::wpdb();
		$table = Schema::table( 'contracts' );
		$where = $withArchived ? " WHERE status <> 'trash'" : " WHERE status NOT IN ('archived', 'trash')";
		return $wpdb->get_results( "SELECT * FROM $table$where ORDER BY name", ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function find( int $id ): ?array {
		$wpdb = Db::wpdb();
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'contracts' ) . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ?: null;
	}

	public static function version( int $contractId, int $version ): ?array {
		$wpdb = Db::wpdb();
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'contract_versions' ) . ' WHERE contract_id = %d AND version = %d', $contractId, $version ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ?: null;
	}

	public static function versions( int $contractId ): array {
		$wpdb = Db::wpdb();
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'contract_versions' ) . ' WHERE contract_id = %d ORDER BY version DESC', $contractId ), ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Versión publicada vigente de una plantilla activa, o null. */
	public static function current( int $contractId ): ?array {
		$contract = $contractId ? self::find( $contractId ) : null;
		if ( ! $contract || in_array( $contract['status'], array( 'archived', 'trash' ), true ) || (int) $contract['current_version'] < 1 ) {
			return null;
		}
		return self::version( $contractId, (int) $contract['current_version'] );
	}

	/** Contrato vigente del servicio, o null si no tiene uno publicado. */
	public static function forService( array $service ): ?array {
		return self::current( (int) ( $service['contract_id'] ?? 0 ) );
	}

	/**
	 * Crea o actualiza una plantilla. Con $publish, el texto se publica como versión nueva;
	 * sin él, solo se guarda el borrador.
	 *
	 * @return int Id de la plantilla.
	 */
	public static function save( ?int $id, string $name, string $title, string $body, bool $publish, bool $isTest = false ): int {
		$name  = trim( sanitize_text_field( $name ) );
		$title = trim( sanitize_text_field( $title ) );
		$body  = self::sanitize( $body );
		if ( '' === $name ) {
			throw new DomainException( 'invalid_contract', 'Escribe un nombre para la plantilla.' );
		}
		if ( $publish && ( '' === $title || '' === wp_strip_all_tags( $body ) ) ) {
			throw new DomainException( 'invalid_contract', 'Para publicar, el contrato necesita título y texto.' );
		}
		$wpdb = Db::wpdb();
		$now  = Db::toSql( Plugin::clock()->now() );
		return (int) Db::transaction(
			static function () use ( $id, $name, $title, $body, $publish, $isTest, $wpdb, $now ) {
				$table = Schema::table( 'contracts' );
				if ( $id ) {
					$current = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d FOR UPDATE", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					if ( ! $current ) {
						throw new DomainException( 'not_found', 'La plantilla no existe.', 404 );
					}
					$wpdb->update( $table, array( 'name' => $name, 'draft_title' => $title, 'draft_body' => $body, 'updated_at' => $now ), array( 'id' => $id ) );
				} else {
					Db::check(
						$wpdb->insert(
							$table,
							array(
								'name'            => $name,
								'status'          => 'active',
								'current_version' => 0,
								'draft_title'     => $title,
								'draft_body'      => $body,
								'is_test'         => $isTest ? 1 : 0,
								'created_at'      => $now,
								'updated_at'      => $now,
							)
						),
						'contract_insert'
					);
					$id      = (int) $wpdb->insert_id;
					$current = array( 'current_version' => 0 );
				}
				if ( $publish ) {
					$latest = self::version( $id, (int) $current['current_version'] );
					if ( $latest && $latest['body_hash'] === self::hash( $title, $body ) ) {
						return $id; // Sin cambios: no se crea una versión duplicada.
					}
					$version = 1 + (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(version) FROM ' . Schema::table( 'contract_versions' ) . ' WHERE contract_id = %d', $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					Db::check(
						$wpdb->insert(
							Schema::table( 'contract_versions' ),
							array(
								'contract_id' => $id,
								'version'     => $version,
								'title'       => $title,
								'body'        => $body,
								'body_hash'   => self::hash( $title, $body ),
								'created_by'  => get_current_user_id(),
								'created_at'  => $now,
							)
						),
						'contract_version_insert'
					);
					$wpdb->update( $table, array( 'current_version' => $version, 'updated_at' => $now ), array( 'id' => $id ) );
					Audit::log( 'contract', $id, 'published', array( 'version' => $version, 'hash' => self::hash( $title, $body ) ) );
				}
				return $id;
			}
		);
	}

	/** Servicios que usan la plantilla. */
	public static function servicesUsing( int $contractId ): array {
		return array_values(
			array_filter(
				Services::all(),
				static function ( $s ) use ( $contractId ) {
					return (int) ( $s['contract_id'] ?? 0 ) === $contractId;
				}
			)
		);
	}

	public static function bookingsUsing( int $contractId ): int {
		$wpdb = Db::wpdb();
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Schema::table( 'bookings' ) . ' WHERE contract_id = %d', $contractId ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Archivar no borra versiones ni copias aceptadas. No se puede si un servicio la usa. */
	public static function setArchived( int $id, bool $archived ): void {
		if ( ! self::find( $id ) ) {
			throw new DomainException( 'not_found', 'La plantilla no existe.', 404 );
		}
		if ( $archived && self::servicesUsing( $id ) ) {
			throw new DomainException( 'contract_in_use', 'Esta plantilla está asignada a un servicio. Asigna otra antes de archivarla.', 409 );
		}
		Db::wpdb()->update( Schema::table( 'contracts' ), array( 'status' => $archived ? 'archived' : 'active', 'updated_at' => Db::toSql( Plugin::clock()->now() ) ), array( 'id' => $id ) );
		Audit::log( 'contract', $id, $archived ? 'archived' : 'restored' );
	}

	/**
	 * Copia inmutable para la reserva. Si la persona vio otra versión, se pide revisar.
	 *
	 * @return array{contract_id:int,contract_version:int,contract_title:string,contract_body:string,contract_hash:string}
	 */
	public static function snapshotFor( array $service, $seenVersion ): array {
		$current = self::forService( $service );
		if ( ! $current ) {
			throw new DomainException( 'contract_missing', 'Este servicio todavía no tiene un contrato publicado. Escríbenos para reservar.', 503 );
		}
		if ( null !== $seenVersion && '' !== $seenVersion && (int) $seenVersion !== (int) $current['version'] ) {
			throw new DomainException( 'contract_changed', 'El contrato se actualizó mientras reservabas. Léelo de nuevo y acéptalo para continuar.', 409, array( 'contract' => self::publicView( $current ) ) );
		}
		return array(
			'contract_id'      => (int) $current['contract_id'],
			'contract_version' => (int) $current['version'],
			'contract_title'   => (string) $current['title'],
			'contract_body'    => (string) $current['body'],
			'contract_hash'    => (string) $current['body_hash'],
		);
	}

	public static function publicView( ?array $version ): ?array {
		if ( ! $version ) {
			return null;
		}
		return array(
			'id'      => (int) $version['contract_id'],
			'version' => (int) $version['version'],
			'title'   => (string) $version['title'],
			// Se vuelve a filtrar al mostrar, por si el contenido se alteró en la base de datos.
			'body'    => self::sanitize( (string) $version['body'] ),
		);
	}

	/**
	 * Migración del esquema 3: las condiciones de cada servicio (más las generales de
	 * Ajustes, al final) se convierten en la versión 1 de una plantilla por servicio. Los
	 * campos anteriores no se borran.
	 */
	public static function migrateTerms(): int {
		$general = trim( (string) \MOBookingStudio\Infrastructure\Settings::get( 'terms_text' ) );
		$count   = 0;
		$wpdb    = Db::wpdb();
		foreach ( Services::all() as $service ) {
			if ( ! empty( $service['contract_id'] ) ) {
				continue;
			}
			$text = trim( trim( (string) $service['terms_text'] ) . "\n\n" . $general );
			if ( '' === $text ) {
				continue;
			}
			$body = wpautop( esc_html( $text ) );
			$id   = self::save( null, 'Contrato – ' . $service['title'], 'Contrato de servicio: ' . $service['title'], $body, true, (bool) $service['is_test'] );
			$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT config FROM ' . Schema::table( 'services' ) . ' WHERE id = %d', $service['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$cfg  = (array) Db::decode( $row['config'] ?? '' );
			$cfg['contract_id'] = $id;
			$wpdb->update( Schema::table( 'services' ), array( 'config' => Db::json( $cfg ) ), array( 'id' => $service['id'] ) );
			++$count;
		}
		return $count;
	}
}
