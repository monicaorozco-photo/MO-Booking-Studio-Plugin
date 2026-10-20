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
		$this->add_control( 'show_description', array( 'label' => 'Mostrar descripción del servicio', 'type' => \Elementor\Controls_Manager::SWITCHER, 'default' => 'yes' ) );
		$this->add_control( 'show_policies', array( 'label' => 'Mostrar políticas en el resumen', 'type' => \Elementor\Controls_Manager::SWITCHER, 'default' => 'yes' ) );
		$this->add_control( 'layout', array( 'label' => 'Disposición', 'type' => \Elementor\Controls_Manager::SELECT, 'options' => array( 'stacked' => 'Apilada', 'columns' => 'Columnas en escritorio' ), 'default' => 'stacked' ) );
		$this->end_controls_section();

		$this->start_controls_section( 'style_colors', array( 'label' => 'Colores', 'tab' => \Elementor\Controls_Manager::TAB_STYLE ) );
		$vars = array(
			'primary'          => array( 'Color principal (botones)', '--mo-primary' ),
			'primary_contrast' => array( 'Texto sobre color principal', '--mo-primary-contrast' ),
			'selected'         => array( 'Día u horario seleccionado (activo)', '--mo-selected' ),
			'text'             => array( 'Texto', '--mo-text' ),
			'muted'            => array( 'Texto secundario', '--mo-muted' ),
			'bg'               => array( 'Fondo de tarjetas', '--mo-bg' ),
			'surface'          => array( 'Fondo secundario', '--mo-surface' ),
			'border'           => array( 'Bordes', '--mo-border' ),
			'special'          => array( 'Precio especial', '--mo-special' ),
			'error'            => array( 'Errores', '--mo-error' ),
		);
		foreach ( $vars as $key => $info ) {
			$this->add_control( 'color_' . $key, array( 'label' => $info[0], 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array( '{{WRAPPER}} .mo-booking' => $info[1] . ': {{VALUE}};' ) ) );
		}
		$this->end_controls_section();

		$this->start_controls_section( 'style_text', array( 'label' => 'Tipografía', 'tab' => \Elementor\Controls_Manager::TAB_STYLE ) );
		$this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array( 'name' => 'body_typo', 'label' => 'Texto', 'selector' => '{{WRAPPER}} .mo-booking' ) );
		$this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array( 'name' => 'title_typo', 'label' => 'Títulos', 'selector' => '{{WRAPPER}} .mo-booking__title, {{WRAPPER}} .mo-booking__panel-title' ) );
		$this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array( 'name' => 'button_typo', 'label' => 'Botones', 'selector' => '{{WRAPPER}} .mo-booking__button, {{WRAPPER}} .mo-booking__slot' ) );
		$this->end_controls_section();

		$this->start_controls_section( 'style_shape', array( 'label' => 'Bordes y espacios', 'tab' => \Elementor\Controls_Manager::TAB_STYLE ) );
		$this->add_control( 'radius', array( 'label' => 'Radio de esquinas', 'type' => \Elementor\Controls_Manager::SLIDER, 'size_units' => array( 'px' ), 'range' => array( 'px' => array( 'min' => 0, 'max' => 30 ) ), 'selectors' => array( '{{WRAPPER}} .mo-booking' => '--mo-radius: {{SIZE}}{{UNIT}};' ) ) );
		$this->add_control( 'gap', array( 'label' => 'Espaciado', 'type' => \Elementor\Controls_Manager::SLIDER, 'size_units' => array( 'px' ), 'range' => array( 'px' => array( 'min' => 4, 'max' => 40 ) ), 'selectors' => array( '{{WRAPPER}} .mo-booking' => '--mo-gap: {{SIZE}}{{UNIT}};' ) ) );
		$this->add_control( 'max_width', array( 'label' => 'Ancho máximo', 'type' => \Elementor\Controls_Manager::SLIDER, 'size_units' => array( 'px', '%' ), 'range' => array( 'px' => array( 'min' => 320, 'max' => 1400 ) ), 'selectors' => array( '{{WRAPPER}} .mo-booking' => 'max-width: {{SIZE}}{{UNIT}};' ) ) );
		$this->add_group_control( \Elementor\Group_Control_Border::get_type(), array( 'name' => 'panel_border', 'label' => 'Borde de tarjetas', 'selector' => '{{WRAPPER}} .mo-booking__panel' ) );
		$this->add_control( 'calendar_day_height', array( 'label' => 'Alto de los días del calendario', 'type' => \Elementor\Controls_Manager::SLIDER, 'size_units' => array( 'px' ), 'range' => array( 'px' => array( 'min' => 44, 'max' => 120 ) ), 'selectors' => array( '{{WRAPPER}} .mo-booking__day' => 'min-height: {{SIZE}}{{UNIT}};' ) ) );
		$this->end_controls_section();
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
				'show_policies'    => 'yes' === ( $s['show_policies'] ?? 'yes' ),
				'layout'           => (string) ( $s['layout'] ?? 'stacked' ),
			)
		);
	}
}
