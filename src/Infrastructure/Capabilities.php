<?php
namespace MOBookingStudio\Infrastructure;

/**
 * Capacidades propias. Cada acción del panel y cada endpoint las valida;
 * ocultar un botón no es control de acceso.
 */
final class Capabilities {
	public const SETTINGS  = 'mo_manage_settings';
	public const BOOKINGS  = 'mo_manage_bookings';
	public const PAYMENTS  = 'mo_review_payments';
	public const CLIENTS   = 'mo_manage_clients';
	public const EXPORT    = 'mo_export_data';
	public const AGENDA    = 'mo_view_agenda';
	public const RECEIPTS  = 'mo_issue_receipts';
	public const FINANCE   = 'mo_view_finance';
	public const EXPENSES  = 'mo_manage_expenses';
	public const GALLERIES = 'mo_manage_galleries';

	public const COLLABORATOR_ROLE = 'mo_collaborator';

	public static function all(): array {
		return array( self::SETTINGS, self::BOOKINGS, self::PAYMENTS, self::CLIENTS, self::EXPORT, self::AGENDA, self::RECEIPTS, self::FINANCE, self::EXPENSES, self::GALLERIES );
	}

	public static function install(): void {
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( self::all() as $cap ) {
				$admin->add_cap( $cap );
			}
		}
		if ( ! get_role( self::COLLABORATOR_ROLE ) ) {
			add_role(
				self::COLLABORATOR_ROLE,
				'Colaborador de estudio',
				array(
					'read'       => true,
					self::AGENDA => true,
				)
			);
		}
	}

	public static function uninstall(): void {
		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( self::all() as $cap ) {
				$role->remove_cap( $cap );
			}
		}
		remove_role( self::COLLABORATOR_ROLE );
	}

	public static function can( string $cap ): bool {
		return current_user_can( $cap );
	}
}
