<?php
/**
 * Plugin Name: BIT JetEngine Map Controls Color
 * Description: Adiciona seção "Controles do Mapa" na aba Estilo do widget JetEngine Maps Listing com cores, divisória ajustável e arredondamentos da cápsula de zoom/reset. Variáveis: --mc-zoom-buttons-bg/color/border/divider/divider-inset. Suporta Global Colors do Elementor.
 * Version: 1.5.0
 * Author: Bureau de Tecnologia
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'elementor/element/jet-engine-maps-listing/section_marker_style/after_section_end', function ( $element ) {
	if ( ! method_exists( $element, 'start_controls_section' ) ) {
		return;
	}

	$element->start_controls_section(
		'bit_zoom_controls_section',
		[
			'label' => esc_html__( 'Controles do Mapa (zoom + reset)', 'bit' ),
			'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
		]
	);

	// === CORES ===
	$element->add_control(
		'bit_zoom_colors_heading',
		[
			'label' => esc_html__( 'Cores', 'bit' ),
			'type'  => \Elementor\Controls_Manager::HEADING,
		]
	);

	$element->add_control(
		'bit_zoom_buttons_bg',
		[
			'label'     => esc_html__( 'Fundo da cápsula', 'bit' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'global'    => [ 'active' => true ],
			'selectors' => [
				'{{WRAPPER}}' => '--mc-zoom-buttons-bg: {{VALUE}};',
			],
		]
	);

	$element->add_control(
		'bit_zoom_buttons_color',
		[
			'label'     => esc_html__( 'Cor do ícone', 'bit' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'global'    => [ 'active' => true ],
			'selectors' => [
				'{{WRAPPER}}' => '--mc-zoom-buttons-color: {{VALUE}};',
			],
		]
	);

	$element->add_control(
		'bit_zoom_buttons_border',
		[
			'label'     => esc_html__( 'Cor da borda', 'bit' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'global'    => [ 'active' => true ],
			'selectors' => [
				'{{WRAPPER}}' => '--mc-zoom-buttons-border: {{VALUE}};',
			],
		]
	);

	// === DIVISÓRIA ===
	$element->add_control(
		'bit_zoom_divider_heading',
		[
			'label'     => esc_html__( 'Divisória entre botões', 'bit' ),
			'type'      => \Elementor\Controls_Manager::HEADING,
			'separator' => 'before',
		]
	);

	$element->add_control(
		'bit_zoom_divider_color',
		[
			'label'     => esc_html__( 'Cor da divisória', 'bit' ),
			'type'      => \Elementor\Controls_Manager::COLOR,
			'global'    => [ 'active' => true ],
			'selectors' => [
				'{{WRAPPER}}' => '--mc-zoom-buttons-divider: {{VALUE}};',
			],
		]
	);

	$element->add_responsive_control(
		'bit_zoom_divider_inset',
		[
			'label'       => esc_html__( 'Recuo lateral', 'bit' ),
			'description' => esc_html__( 'Quanto a divisória recua das bordas da cápsula. 0% = atravessa de ponta a ponta (igual aos filtros).', 'bit' ),
			'type'        => \Elementor\Controls_Manager::SLIDER,
			'size_units'  => [ '%', 'px' ],
			'range'       => [
				'%'  => [ 'min' => 0, 'max' => 40, 'step' => 1 ],
				'px' => [ 'min' => 0, 'max' => 30, 'step' => 1 ],
			],
			'selectors'   => [
				'{{WRAPPER}} .leaflet-control-zoom a + a::before' => 'left: {{SIZE}}{{UNIT}} !important; right: {{SIZE}}{{UNIT}} !important;',
			],
		]
	);

	$element->add_responsive_control(
		'bit_zoom_divider_thickness',
		[
			'label'      => esc_html__( 'Espessura', 'bit' ),
			'type'       => \Elementor\Controls_Manager::SLIDER,
			'size_units' => [ 'px' ],
			'range'      => [
				'px' => [ 'min' => 0, 'max' => 4, 'step' => 1 ],
			],
			'selectors'  => [
				'{{WRAPPER}} .leaflet-control-zoom a + a::before' => 'height: {{SIZE}}{{UNIT}} !important;',
			],
		]
	);

	$element->add_responsive_control(
		'bit_zoom_divider_gap',
		[
			'label'       => esc_html__( 'Distanciamento entre botões', 'bit' ),
			'description' => esc_html__( 'Espaço entre os botões. A divisória se posiciona no meio desse espaço.', 'bit' ),
			'type'        => \Elementor\Controls_Manager::SLIDER,
			'size_units'  => [ 'px' ],
			'range'       => [
				'px' => [ 'min' => 0, 'max' => 24, 'step' => 1 ],
			],
			'selectors'   => [
				'{{WRAPPER}} .leaflet-control-zoom' => 'gap: {{SIZE}}{{UNIT}} !important;',
				'{{WRAPPER}} .leaflet-control-zoom a + a::before' => 'top: calc(-{{SIZE}}{{UNIT}} / 2) !important;',
			],
		]
	);

	// === ARREDONDAMENTO ===
	$element->add_control(
		'bit_zoom_radius_heading',
		[
			'label'     => esc_html__( 'Arredondamento', 'bit' ),
			'type'      => \Elementor\Controls_Manager::HEADING,
			'separator' => 'before',
		]
	);

	$element->add_responsive_control(
		'bit_zoom_capsule_radius',
		[
			'label'      => esc_html__( 'Raio da cápsula', 'bit' ),
			'type'       => \Elementor\Controls_Manager::DIMENSIONS,
			'size_units' => [ 'px', '%' ],
			'allowed_dimensions' => 'all',
			'selectors'  => [
				'{{WRAPPER}} .leaflet-control-zoom' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}} !important;',
			],
		]
	);

	$element->add_responsive_control(
		'bit_zoom_button_radius',
		[
			'label'      => esc_html__( 'Raio dos botões', 'bit' ),
			'type'       => \Elementor\Controls_Manager::DIMENSIONS,
			'size_units' => [ 'px', '%' ],
			'allowed_dimensions' => 'all',
			'selectors'  => [
				'{{WRAPPER}} .leaflet-control-zoom a' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}} !important;',
			],
		]
	);

	// === POSIÇÃO ===
	$element->add_control(
		'bit_zoom_position_heading',
		[
			'label'     => esc_html__( 'Posição', 'bit' ),
			'type'      => \Elementor\Controls_Manager::HEADING,
			'separator' => 'before',
		]
	);

	$element->add_responsive_control(
		'bit_zoom_offset_x',
		[
			'label'       => esc_html__( 'Deslocamento horizontal', 'bit' ),
			'description' => esc_html__( 'Positivo move para a direita; negativo para a esquerda.', 'bit' ),
			'type'        => \Elementor\Controls_Manager::SLIDER,
			'size_units'  => [ 'px' ],
			'range'       => [
				'px' => [ 'min' => -200, 'max' => 200, 'step' => 1 ],
			],
			'selectors'   => [
				'{{WRAPPER}} .leaflet-top.leaflet-left' => '--bit-zoom-offset-x: {{SIZE}}{{UNIT}}; transform: translate(var(--bit-zoom-offset-x, 0), var(--bit-zoom-offset-y, 0));',
			],
		]
	);

	$element->add_responsive_control(
		'bit_zoom_offset_y',
		[
			'label'       => esc_html__( 'Deslocamento vertical', 'bit' ),
			'description' => esc_html__( 'Positivo move para baixo; negativo para cima.', 'bit' ),
			'type'        => \Elementor\Controls_Manager::SLIDER,
			'size_units'  => [ 'px' ],
			'range'       => [
				'px' => [ 'min' => -200, 'max' => 400, 'step' => 1 ],
			],
			'selectors'   => [
				'{{WRAPPER}} .leaflet-top.leaflet-left' => '--bit-zoom-offset-y: {{SIZE}}{{UNIT}}; transform: translate(var(--bit-zoom-offset-x, 0), var(--bit-zoom-offset-y, 0));',
			],
		]
	);

	$element->end_controls_section();
}, 10 );
