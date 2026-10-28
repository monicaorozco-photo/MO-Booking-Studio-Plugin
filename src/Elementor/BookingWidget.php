<?php
namespace MOBookingStudio\Elementor;

use MOBookingStudio\Application\Services;
use MOBookingStudio\Frontend\Frontend;

/**
 * Widget "MO Booking Studio" para Elementor (no requiere Elementor Pro). Elementor solo
 * controla la presentación: precios, permisos y validación siguen en el servidor.
 * Dentro del editor muestra una vista previa ficticia que no crea reservas.
 */
class BookingWidget extends \Elementor\Widget_Base {
	use StyleControls;

	public function get_name() {
		return 'mo-booking-studio';
	}

	public function get_title() {
		return 'MO Booking Studio';
	}

	public function get_icon() {
		return 'eicon-calendar';
	}

	public function get_categories() {
		return array( 'general' );
	}

	public function get_keywords() {
		return array( 'reservas', 'booking', 'citas', 'calendario' );
	}

	public function get_style_depends() {
		Frontend::registerAssetHandles();
		return array( 'mo-booking' );
	}

	public function get_script_depends() {
		Frontend::registerAssetHandles();
		return array( 'mo-booking-common', 'mo-booking' );
	}

	protected function register_controls() {
		$services = array( '' => '— Elige un servicio —' );
		foreach ( Services::all() as $s ) {
			if ( 'inactive' !== $s['status'] ) {
				$services[ $s['slug'] ] = $s['title'] . ( 'published' !== $s['status'] ? ' (borrador)' : '' );
			}
		}

		$this->start_controls_section( 'content', array( 'label' => 'Contenido' ) );
		$this->add_control( 'service', array( 'label' => 'Servicio', 'type' => \Elementor\Controls_Manager::SELECT, 'options' => $services, 'default' => '' ) );
		$this->add_control( 'intro', array( 'label' => 'Texto de introducción', 'type' => \Elementor\Controls_Manager::TEXTAREA, 'default' => '' ) );
		$this->add_control( 'show_brand', array( 'label' => 'Mostrar nombre de la marca', 'type' => \Elementor\Controls_Manager::SWITCHER, 'default' => 'yes' ) );
		$this->add_control( 'show_title', array( 'label' => 'Mostrar nombre del servicio', 'type' => \Elementor\Controls_Manager::SWITCHER, 'default' => 'yes', 'description' => 'Apágalos si pones tu propio título arriba del formulario.' ) );
		$this->add_control( 'show_description', array( 'label' => 'Mostrar descripción del servicio', 'type' => \Elementor\Controls_Manager::SWITCHER, 'default' => 'yes' ) );
		$this->add_control( 'show_policies', array( 'label' => 'Mostrar reglas de cancelación en el resumen', 'type' => \Elementor\Controls_Manager::SWITCHER, 'default' => '', 'description' => 'Normalmente apagado: la clienta ve y acepta tu contrato.' ) );
		$this->add_control( 'layout', array( 'label' => 'Disposición', 'type' => \Elementor\Controls_Manager::SELECT, 'options' => array( 'columns' => 'Dos columnas en computadora', 'stacked' => 'Una columna' ), 'default' => 'columns' ) );
		$this->end_controls_section();

		// DEC-10: textos de la bienvenida. Deja un subtítulo vacío para ocultarlo.
		$labels = array(
			'welcome_title'           => 'Título',
			'welcome_question'        => 'Pregunta',
			'welcome_new_title'       => 'Botón cliente nuevo',
			'welcome_new_text'        => 'Subtítulo cliente nuevo (vacío = ocultar)',
			'welcome_returning_title' => 'Botón cliente frecuente',
			'welcome_returning_text'  => 'Subtítulo cliente frecuente (vacío = ocultar)',
			'welcome_identify_title'  => 'Título al identificarse',
			'welcome_identify_text'   => 'Instrucción al identificarse',
		);
		$this->start_controls_section( 'welcome', array( 'label' => 'Bienvenida' ) );
		foreach ( Frontend::WELCOME_TEXTS as $key => $default ) {
			$this->add_control( $key, array( 'label' => $labels[ $key ], 'type' => \Elementor\Controls_Manager::TEXT, 'default' => $default, 'label_block' => true ) );
		}
		$this->end_controls_section();

		$this->registerStyleControls();
	}

	protected function render() {
		$s       = $this->get_settings_for_display();
		$preview = class_exists( '\Elementor\Plugin' ) && \Elementor\Plugin::$instance->editor->is_edit_mode();
		echo Frontend::renderBooking( // phpcs:ignore WordPress.Security.EscapeOutput
			(string) ( $s['service'] ?? '' ),
			array(
				'intro'            => (string) ( $s['intro'] ?? '' ),
				'preview'          => $preview || ( isset( $_GET['elementor-preview'] ) ), // phpcs:ignore WordPress.Security.NonceVerification
				'show_description' => 'yes' === ( $s['show_description'] ?? 'yes' ),
				'show_brand'       => 'yes' === ( $s['show_brand'] ?? 'yes' ),
				'show_title'       => 'yes' === ( $s['show_title'] ?? 'yes' ),
				'show_policies'    => 'yes' === ( $s['show_policies'] ?? '' ),
				'layout'           => (string) ( $s['layout'] ?? 'columns' ),
				'texts'            => array_intersect_key( $s, Frontend::WELCOME_TEXTS ),
			)
		);
	}
}
