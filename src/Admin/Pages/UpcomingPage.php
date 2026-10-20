<?php
namespace MOBookingStudio\Admin\Pages;

use MOBookingStudio\Admin\Ui;

/**
 * Módulos de la especificación que todavía no están implementados en esta versión.
 * Se muestran identificados para no aparentar funciones que no existen.
 */
final class UpcomingPage {
	public static function finance(): void {
		Ui::header( 'Finanzas' );
		echo Ui::card( // phpcs:ignore WordPress.Security.EscapeOutput
			'Disponible en la siguiente etapa',
			'<p>Esta sección todavía no está implementada. Incluirá reportes por categoría de servicio, cobros netos, devoluciones, gastos y resultado estimado.</p><p>Mientras tanto, cada reserva ya guarda sus pagos, retenciones y devoluciones con fecha de recepción y categoría, así que los reportes podrán calcular el historial completo.</p>'
		);
		Ui::footer();
	}

	public static function galleries(): void {
		Ui::header( 'Galerías' );
		echo Ui::card( // phpcs:ignore WordPress.Security.EscapeOutput
			'Disponible en la siguiente etapa',
			'<p>Esta sección todavía no está implementada. Permitirá publicar en el portal de cada reserva los enlaces a galerías externas (por ejemplo Pixieset) con su contraseña y PIN de descarga protegidos.</p>'
		);
		Ui::footer();
	}
}
