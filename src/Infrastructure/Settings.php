<?php
namespace MOBookingStudio\Infrastructure;

use MOBookingStudio\Domain\TimeZoneResolver;

/**
 * Ajustes generales del negocio. Todos son editables desde el panel.
 * Los valores marcados como "propuesta" vienen de la sección 26 de la especificación.
 */
final class Settings {
	public const OPTION = 'mo_booking_settings';

	public static function defaults(): array {
		return array(
			'business_name'        => get_bloginfo( 'name' ),
			'timezone'             => 'America/Ciudad_Juarez',
			'bank_instructions'    => '',
			'payment_reference'    => 'Usa tu número de reserva como concepto o referencia.',
			'hold_hours'           => 12,   // Retención de horario mientras se paga.
			'correction_hours'     => 12,   // Propuesta: plazo para corregir un comprobante.
			'proof_max_mb'         => 10,   // Propuesta: tamaño máximo de comprobante.
			'proof_max_files'      => 5,
			'token_days_after'     => 90,   // Propuesta: el enlace privado vence 90 días después de la cita.
			'booking_page_id'      => 0,
			'portal_page_id'       => 0,
			'admin_email'          => get_option( 'admin_email' ),
			'email_mode'           => 'simulate', // simulate | live. En simulación no se envía nada.
			'email_from_name'      => '',
			'email_from'           => '',
			'whatsapp_mode'        => 'manual', // off | manual | automatic
			'whatsapp_number'      => '',
			'reminder_hours'       => 48,   // Propuesta: recordatorio 48 horas antes.
			'terms_text'           => '',
			'privacy_url'          => '',
			'delete_on_uninstall'  => false,
			'uploads_verified'     => '',   // '' | ok | public | error
			'uploads_checked_at'   => '',
		);
	}

	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
	}

	public static function get( string $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	public static function update( array $values ): void {
		$current = self::all();
		foreach ( $values as $key => $value ) {
			if ( array_key_exists( $key, $current ) ) {
				$current[ $key ] = $value;
			}
		}
		update_option( self::OPTION, $current, false );
	}

	/** Zona efectiva del negocio (con equivalente si el servidor no conoce la zona pedida). */
	public static function timezone(): \DateTimeZone {
		return self::timezoneInfo()['zone'];
	}

	public static function timezoneInfo(): array {
		try {
			return TimeZoneResolver::resolve( (string) self::get( 'timezone' ) );
		} catch ( \Throwable $e ) {
			return TimeZoneResolver::resolve( 'America/Ciudad_Juarez' );
		}
	}
}
