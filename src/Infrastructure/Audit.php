<?php
namespace MOBookingStudio\Infrastructure;

/**
 * Bitácora de acciones: quién, qué, cuándo y con qué datos. No guarda secretos.
 */
final class Audit {
	public static function log( string $entity, int $entityId, string $action, array $data = array(), ?string $actorType = null, ?int $actorId = null ): void {
		$userId = $actorId ?? get_current_user_id();
		Db::wpdb()->insert(
			Schema::table( 'audit' ),
			array(
				'actor_type' => $actorType ?? ( $userId ? 'user' : 'client' ),
				'actor_id'   => $userId,
				'entity'     => $entity,
				'entity_id'  => $entityId,
				'action'     => $action,
				'data'       => Db::json( $data ),
				'created_at' => Db::toSql( \MOBookingStudio\Plugin::clock()->now() ),
			)
		);
	}

	public static function forEntity( string $entity, int $entityId ): array {
		$wpdb = Db::wpdb();
		return $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . Schema::table( 'audit' ) . ' WHERE entity = %s AND entity_id = %d ORDER BY id ASC', $entity, $entityId ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		) ?: array();
	}
}
