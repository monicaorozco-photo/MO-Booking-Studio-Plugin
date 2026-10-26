<?php
namespace MOBookingStudio\Elementor;

use MOBookingStudio\Frontend\Frontend;

/**
 * Widget "MO Vista de reserva": la página privada de la clienta (la que abre con su
 * enlace). Va en la página "Mi reserva" en lugar del shortcode [mo_booking_portal].
 * Dentro del editor muestra una reserva de ejemplo para ajustar los estilos.
 */
class PortalWidget extends \Elementor\Widget_Base {
	use StyleControls;

	public function get_name() {
		return 'mo-booking-portal';
	}

	public function get_title() {
		return 'MO Vista de reserva';
	}

	public function get_icon() {
		return 'eicon-lock-user';
	}

	public function get_categories() {
		return array( 'general' );
	}

	public function get_keywords() {
		return array( 'reserva', 'portal', 'mi reserva', 'cliente' );
	}

	public function get_style_depends() {
		Frontend::registerAssetHandles();
		return array( 'mo-booking' );
	}

	public function get_script_depends() {
		Frontend::registerAssetHandles();
		return array( 'mo-booking-common', 'mo-booking-portal' );
	}

	protected function register_controls() {
		$this->start_controls_section( 'content', array( 'label' => 'Vista de reserva' ) );
		$this->add_control( 'help', array( 'type' => \Elementor\Controls_Manager::RAW_HTML, 'raw' => 'Aquí se ve la reserva de la persona que abre su enlace privado. En el editor se muestra una reserva de ejemplo para que ajustes los estilos.' ) );
		$this->end_controls_section();
		$this->registerStyleControls( false );
	}

	protected function render() {
		$preview = class_exists( '\Elementor\Plugin' ) && \Elementor\Plugin::$instance->editor->is_edit_mode();
		echo Frontend::renderPortal( $preview || isset( $_GET['elementor-preview'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput,WordPress.Security.NonceVerification
	}
}
