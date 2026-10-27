<?php
namespace MOBookingStudio\Elementor;

/**
 * Controles de estilo compartidos por los widgets de reserva y de vista de reserva.
 * Todo se aplica con variables CSS o selectores dentro de .mo-booking: nunca toca el
 * resto del sitio.
 */
trait StyleControls {
	private function color( string $key, string $label, string $selector, string $property ): void {
		$this->add_control( $key, array( 'label' => $label, 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array( '{{WRAPPER}} ' . $selector => $property . ': {{VALUE}};' ) ) );
	}

	protected function registerStyleControls( bool $calendar = true ): void {
		$this->start_controls_section( 'style_colors', array( 'label' => 'Colores generales', 'tab' => \Elementor\Controls_Manager::TAB_STYLE ) );
		$vars = array(
			'primary'          => array( 'Color principal (botones)', '--mo-primary' ),
			'primary_contrast' => array( 'Texto sobre color principal', '--mo-primary-contrast' ),
			'selected'         => array( 'Día u horario seleccionado (activo)', '--mo-selected' ),
			'text'             => array( 'Texto', '--mo-text' ),
			'muted'            => array( 'Texto secundario', '--mo-muted' ),
			'bg'               => array( 'Fondo de tarjetas', '--mo-bg' ),
			'surface'          => array( 'Fondo secundario', '--mo-surface' ),
			'soft'             => array( 'Fondo suave (fecha elegida, total, avisos)', '--mo-soft' ),
			'border'           => array( 'Bordes', '--mo-border' ),
			'notice'           => array( 'Texto de avisos', '--mo-notice' ),
			'notice_bg'        => array( 'Fondo de avisos', '--mo-notice-bg' ),
			'success'          => array( 'Texto de confirmaciones', '--mo-success' ),
			'success_bg'       => array( 'Fondo de confirmaciones', '--mo-success-bg' ),
			'error'            => array( 'Errores', '--mo-error' ),
		);
		foreach ( $vars as $key => $info ) {
			$this->color( 'color_' . $key, $info[0], '.mo-booking', $info[1] );
		}
		$this->end_controls_section();

		if ( $calendar ) {
			$this->start_controls_section( 'style_calendar', array( 'label' => 'Calendario y horarios', 'tab' => \Elementor\Controls_Manager::TAB_STYLE ) );
			$days = array(
				'day_open_bg'      => array( 'Día con horarios: fondo', '--mo-day-open-bg' ),
				'day_open_text'    => array( 'Día con horarios: número', '--mo-day-open-text' ),
				'day_open_border'  => array( 'Día con horarios: borde', '--mo-day-open-border' ),
				'presale'          => array( 'Día con preventa: fondo', '--mo-presale' ),
				'presale_text'     => array( 'Día con preventa: número y borde', '--mo-presale-text' ),
				'day_closed_bg'    => array( 'Día sin horarios: fondo', '--mo-day-closed-bg' ),
				'day_closed_text'  => array( 'Día sin horarios: número', '--mo-day-closed-text' ),
				'soldout_bg'       => array( 'Día lleno: fondo', '--mo-soldout-bg' ),
				'soldout'          => array( 'Día lleno: número', '--mo-soldout' ),
				'special'          => array( 'Ícono de precio especial', '--mo-special' ),
				'slot_bg'          => array( 'Horario libre: fondo', '--mo-slot-bg' ),
				'slot_text'        => array( 'Horario libre: texto y borde', '--mo-slot-text' ),
				'slot_off_bg'      => array( 'Horario ocupado: fondo', '--mo-slot-off-bg' ),
				'slot_off_text'    => array( 'Horario ocupado: texto', '--mo-slot-off-text' ),
			);
			foreach ( $days as $key => $info ) {
				$this->color( 'color_' . $key, $info[0], '.mo-booking', $info[1] );
			}
			$this->add_control( 'calendar_day_height', array( 'label' => 'Alto de los días', 'type' => \Elementor\Controls_Manager::SLIDER, 'size_units' => array( 'px' ), 'range' => array( 'px' => array( 'min' => 44, 'max' => 120 ) ), 'selectors' => array( '{{WRAPPER}} .mo-booking__day' => 'min-height: {{SIZE}}{{UNIT}};' ) ) );
			$this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array( 'name' => 'day_typo', 'label' => 'Números de los días', 'selector' => '{{WRAPPER}} .mo-booking__day-num' ) );
			$this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array( 'name' => 'slot_typo', 'label' => 'Horarios', 'selector' => '{{WRAPPER}} .mo-booking__slot' ) );
			$this->end_controls_section();
		}

		$this->start_controls_section( 'style_buttons', array( 'label' => 'Botones', 'tab' => \Elementor\Controls_Manager::TAB_STYLE ) );
		// Variables: los botones del plugin están blindados contra el tema y usan estos valores.
		$this->color( 'btn_bg', 'Botón principal: fondo', '.mo-booking', '--mo-btn-bg' );
		$this->color( 'btn_text', 'Botón principal: texto', '.mo-booking', '--mo-btn-text' );
		$this->color( 'btn_border', 'Botón principal: borde', '.mo-booking', '--mo-btn-border' );
		$this->color( 'btn2_bg', 'Botón secundario: fondo', '.mo-booking', '--mo-btn2-bg' );
		$this->color( 'btn2_text', 'Botón secundario: texto', '.mo-booking', '--mo-btn2-text' );
		$this->color( 'btn2_border', 'Botón secundario: borde', '.mo-booking', '--mo-btn2-border' );
		$this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array( 'name' => 'button_typo', 'label' => 'Tipografía', 'selector' => '{{WRAPPER}} .mo-booking__button, {{WRAPPER}} .mo-booking__choice' ) );
		$this->add_control( 'button_radius', array( 'label' => 'Esquinas', 'type' => \Elementor\Controls_Manager::SLIDER, 'size_units' => array( 'px' ), 'range' => array( 'px' => array( 'min' => 0, 'max' => 40 ) ), 'selectors' => array( '{{WRAPPER}} .mo-booking__button' => 'border-radius: {{SIZE}}{{UNIT}};' ) ) );
		$this->end_controls_section();

		$this->start_controls_section( 'style_text', array( 'label' => 'Tipografía', 'tab' => \Elementor\Controls_Manager::TAB_STYLE ) );
		$this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array( 'name' => 'body_typo', 'label' => 'Texto', 'selector' => '{{WRAPPER}} .mo-booking' ) );
		$this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array( 'name' => 'title_typo', 'label' => 'Título del servicio', 'selector' => '{{WRAPPER}} .mo-booking__title' ) );
		$this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array( 'name' => 'panel_title_typo', 'label' => 'Títulos de cada paso', 'selector' => '{{WRAPPER}} .mo-booking__panel-title' ) );
		$this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array( 'name' => 'section_title_typo', 'label' => 'Subtítulos de sección', 'selector' => '{{WRAPPER}} .mo-booking h3, {{WRAPPER}} .mo-booking h4, {{WRAPPER}} .mo-booking__subtitle' ) );
		$this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array( 'name' => 'label_typo', 'label' => 'Etiquetas del formulario', 'selector' => '{{WRAPPER}} .mo-booking__label' ) );
		$this->end_controls_section();

		$this->start_controls_section( 'style_shape', array( 'label' => 'Tarjetas y espacios', 'tab' => \Elementor\Controls_Manager::TAB_STYLE ) );
		$this->add_control( 'radius', array( 'label' => 'Radio de esquinas', 'type' => \Elementor\Controls_Manager::SLIDER, 'size_units' => array( 'px' ), 'range' => array( 'px' => array( 'min' => 0, 'max' => 30 ) ), 'selectors' => array( '{{WRAPPER}} .mo-booking' => '--mo-radius: {{SIZE}}{{UNIT}};' ) ) );
		$this->add_control( 'gap', array( 'label' => 'Espaciado', 'type' => \Elementor\Controls_Manager::SLIDER, 'size_units' => array( 'px' ), 'range' => array( 'px' => array( 'min' => 4, 'max' => 40 ) ), 'selectors' => array( '{{WRAPPER}} .mo-booking' => '--mo-gap: {{SIZE}}{{UNIT}};' ) ) );
		$this->add_control( 'max_width', array( 'label' => 'Ancho máximo', 'type' => \Elementor\Controls_Manager::SLIDER, 'size_units' => array( 'px', '%' ), 'range' => array( 'px' => array( 'min' => 320, 'max' => 1400 ) ), 'selectors' => array( '{{WRAPPER}} .mo-booking' => 'max-width: {{SIZE}}{{UNIT}};' ) ) );
		$this->add_group_control( \Elementor\Group_Control_Border::get_type(), array( 'name' => 'panel_border', 'label' => 'Borde de tarjetas', 'selector' => '{{WRAPPER}} .mo-booking__panel' ) );
		$this->add_group_control( \Elementor\Group_Control_Box_Shadow::get_type(), array( 'name' => 'panel_shadow', 'label' => 'Sombra de tarjetas', 'selector' => '{{WRAPPER}} .mo-booking__panel' ) );
		$this->add_responsive_control( 'panel_padding', array( 'label' => 'Relleno de tarjetas', 'type' => \Elementor\Controls_Manager::DIMENSIONS, 'size_units' => array( 'px', 'em' ), 'selectors' => array( '{{WRAPPER}} .mo-booking__panel' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ) ) );
		$this->color( 'box_bg', 'Recuadros (comprobante, datos bancarios): fondo', '.mo-booking .mo-booking__upload-wrap, {{WRAPPER}} .mo-booking .mo-booking__bank', 'background-color' );
		$this->end_controls_section();
	}
}
