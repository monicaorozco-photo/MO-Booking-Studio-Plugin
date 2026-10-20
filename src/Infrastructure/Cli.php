<?php
namespace MOBookingStudio\Infrastructure;

/**
 * Comandos WP-CLI.
 *
 * ## EJEMPLOS
 *
 *     # Procesar vencimientos y cola de mensajes (para una tarea cron del servidor cada minuto)
 *     wp mo-booking process
 */
final class Cli {
	/**
	 * Procesa vencimientos y mensajes pendientes.
	 */
	public function process(): void {
		$expired = \MOBookingStudio\Application\Bookings::expireDue( 500 );
		$sent    = \MOBookingStudio\Application\Notifications::processQueue( 100 );
		\WP_CLI::success( sprintf( 'Vencidas: %d. Mensajes procesados: %d.', $expired, $sent ) );
	}

	/**
	 * Verifica que los comprobantes no se puedan descargar por URL directa.
	 */
	public function verify_storage(): void {
		$result = PrivateStorage::verify();
		\WP_CLI::log( 'Resultado: ' . $result );
	}
}
