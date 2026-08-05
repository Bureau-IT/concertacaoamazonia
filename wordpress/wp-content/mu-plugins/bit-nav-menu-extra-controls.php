<?php
/**
 * Plugin Name: BIT Nav Menu Extra Controls
 * Description: Estende o widget "Menu do WordPress" (Elementor Pro nav-menu) com
 *              controles ausentes no nativo:
 *              - Lista suspensa: padding dimensional (top/right/bottom/left)
 *              - Menu principal: tipografia individual DENTRO de cada tab
 *                (Normal/Hover/Ativo), via detecção dinâmica do último controle
 *                de cada tab + injeção canônica `position` via $options (3º arg)
 * Version:     1.6.0
 * Author:      Bureau IT
 * Network:     true
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Localiza o ID do último controle dentro de uma tab específica.
 *
 * O Elementor marca todo controle declarado entre start_controls_tab/end_controls_tab
 * com `tabs_wrapper` e `inner_tab`. Percorremos os controles e retornamos o último
 * que pertence à tab — alvo seguro para `position` `at => after`.
 */
function bit_nav_menu_last_control_in_tab( $element, $tab_id ) {
	$controls = $element->get_controls();
	$last     = null;
	foreach ( $controls as $id => $control ) {
		if ( isset( $control['inner_tab'] ) && $control['inner_tab'] === $tab_id ) {
			$last = $id;
		}
	}
	return $last;
}

/**
 * Menu principal — tipografia por estado, dentro de cada tab.
 *
 * IMPORTANTE: para `add_group_control`, o `position` DEVE ir no 3º argumento
 * (`$options`), não no 2º (`$args`). O Group_Control_Base::add_controls lê
 * `$options['position']` e chama `start_injection`. Documentação omite isso.
 */
add_action(
	'elementor/element/nav-menu/section_style_main-menu/before_section_end',
	function ( $element, $args ) {
		// Esconde a Tipografia global nativa — redundante com as por estado abaixo.
		// `update_control` preserva valores salvos no banco; usuário não perde nada.
		$element->update_control( 'menu_typography_typography', [
			'type' => \Elementor\Controls_Manager::HIDDEN,
		] );
		foreach ( [ 'font_family', 'font_size', 'font_weight', 'text_transform', 'font_style', 'text_decoration', 'line_height', 'letter_spacing', 'word_spacing' ] as $field ) {
			$element->update_control( 'menu_typography_' . $field, [
				'type' => \Elementor\Controls_Manager::HIDDEN,
			] );
		}

		$last_normal = bit_nav_menu_last_control_in_tab( $element, 'tab_menu_item_normal' );
		$last_hover  = bit_nav_menu_last_control_in_tab( $element, 'tab_menu_item_hover' );
		$last_active = bit_nav_menu_last_control_in_tab( $element, 'tab_menu_item_active' );

		if ( $last_normal ) {
			$element->add_group_control(
				\Elementor\Group_Control_Typography::get_type(),
				[
					'name'     => 'bit_menu_typography_normal',
					'label'    => esc_html__( 'Tipografia', 'bit' ),
					'selector' => '{{WRAPPER}} .elementor-nav-menu--main .elementor-item',
				],
				[
					'position' => [
						'type' => 'control',
						'at'   => 'after',
						'of'   => $last_normal,
					],
				]
			);
		}

		if ( $last_hover ) {
			$element->add_group_control(
				\Elementor\Group_Control_Typography::get_type(),
				[
					'name'     => 'bit_menu_typography_hover',
					'label'    => esc_html__( 'Tipografia', 'bit' ),
					// Inclui :hover/:focus e .highlighted SOMENTE quando também em hover/focus —
					// evita ativar tipografia hover em items com .highlighted stuck (bug do
					// SmartMenus que o Elementor Pro usa). Override 9.5 do header-menu.css
					// neutraliza color/fill nesse mesmo cenário; aqui evitamos contaminar
					// font-family/size/weight/etc na mesma situação.
					'selector' => '{{WRAPPER}} .elementor-nav-menu--main .elementor-item:hover,
						{{WRAPPER}} .elementor-nav-menu--main .elementor-item:focus,
						{{WRAPPER}} .elementor-nav-menu--main .elementor-item.highlighted:hover,
						{{WRAPPER}} .elementor-nav-menu--main .elementor-item.highlighted:focus',
				],
				[
					'position' => [
						'type' => 'control',
						'at'   => 'after',
						'of'   => $last_hover,
					],
				]
			);
		}

		if ( $last_active ) {
			$element->add_group_control(
				\Elementor\Group_Control_Typography::get_type(),
				[
					'name'     => 'bit_menu_typography_active',
					'label'    => esc_html__( 'Tipografia', 'bit' ),
					'selector' => '{{WRAPPER}} .elementor-nav-menu--main .elementor-item.elementor-item-active',
				],
				[
					'position' => [
						'type' => 'control',
						'at'   => 'after',
						'of'   => $last_active,
					],
				]
			);
		}
	},
	10,
	2
);

/**
 * Lista suspensa — padding dimensional (top/right/bottom/left).
 *
 * Para `add_responsive_control` (que internamente chama `add_control`), o
 * `position` aceita tanto $args quanto $options. Mantemos em $options por
 * consistência com add_group_control.
 */
add_action(
	'elementor/element/nav-menu/section_style_dropdown/before_section_end',
	function ( $element, $args ) {
		$element->add_responsive_control(
			'bit_dropdown_item_padding',
			[
				'label'       => esc_html__( 'Padding do bloco do submenu', 'bit' ),
				'description' => esc_html__( 'Espaçamento externo do bloco inteiro de itens (acima, abaixo, esquerda, direita do conjunto). Não confundir com "Espaçamento horizontal/vertical" acima, que controlam o padding INTERNO de cada item.', 'bit' ),
				'type'        => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units'  => [ 'px', 'em', 'rem', '%', 'custom' ],
				'selectors'   => [
					'{{WRAPPER}} nav.elementor-nav-menu--dropdown ul.elementor-nav-menu' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			],
			[
				'position' => [
					'type' => 'control',
					'at'   => 'after',
					'of'   => 'padding_vertical_dropdown_item',
				],
			]
		);
	},
	10,
	2
);

/**
 * Submenu Inline (Desktop) — nova seção de estilo.
 *
 * Controla a barra horizontal de submenu (.bit-hover-bar + .bit-subnav-bar)
 * renderizada pelo mu-plugin bit-inline-submenu quando o widget tem a CSS
 * class `menu-submenu-inline`.
 *
 * Todos os controles têm defaults explícitos (HEX/peso/tipografia) — sem
 * dependência de CSS estático ou Global Colors. Os campos aparecem pré-
 * preenchidos no painel, refletindo exatamente o que renderiza.
 *
 * Hook: after_section_end de `style_toggle` (última seção de estilo do widget).
 */
add_action(
	'elementor/element/nav-menu/style_toggle/after_section_end',
	function ( $element, $args ) {
		$element->start_controls_section(
			'bit_section_style_submenu_inline',
			[
				'label' => esc_html__( 'Submenu Inline (Desktop)', 'bit' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			]
		);

		// ── Layout ────────────────────────────────────────────────────────
		$element->add_responsive_control(
			'bit_submenu_height',
			[
				'label'      => esc_html__( 'Altura da barra', 'bit' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => [ 'px', 'em', 'rem' ],
				'range'      => [
					'px' => [ 'min' => 40, 'max' => 160 ],
					'em' => [ 'min' => 2, 'max' => 10 ],
				],
				'default'    => [ 'unit' => 'px', 'size' => 72 ],
				'selectors'  => [
					'{{WRAPPER}}' => '--bit-submenu-height: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$element->add_control(
			'bit_submenu_bg',
			[
				'label'     => esc_html__( 'Cor de fundo da barra', 'bit' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#003A26',
				'selectors' => [
					'{{WRAPPER}}' => '--bis-bg: {{VALUE}}; --bis-diamond: {{VALUE}};',
				],
			]
		);

		$element->add_control(
			'bit_submenu_diamond',
			[
				'label'       => esc_html__( 'Cor do diamante', 'bit' ),
				'description' => esc_html__( 'Indicador triangular acima da barra hover. Por padrão herda da cor de fundo.', 'bit' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				'default'     => '#003A26',
				'selectors'   => [
					'{{WRAPPER}}' => '--bis-diamond: {{VALUE}};',
				],
			]
		);

		// ── Tabs: Normal / Hover / Ativo ──────────────────────────────────
		$element->start_controls_tabs( 'bit_submenu_inline_tabs' );

		// ─── Normal ──────────────────────────────────────────────────────
		$element->start_controls_tab( 'bit_submenu_tab_normal', [
			'label' => esc_html__( 'Normal', 'bit' ),
		] );

		$element->add_control(
			'bit_submenu_text',
			[
				'label'     => esc_html__( 'Cor do texto', 'bit' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#FFFFFF',
				'selectors' => [
					'{{WRAPPER}}' => '--bis-text: {{VALUE}};',
				],
			]
		);

		$element->add_control(
			'bit_submenu_font_family',
			[
				'label'     => esc_html__( 'Família da fonte', 'bit' ),
				'type'      => \Elementor\Controls_Manager::FONT,
				'default'   => 'Roboto',
				'selectors' => [
					'{{WRAPPER}}' => '--bis-font-family: "{{VALUE}}", sans-serif;',
				],
			]
		);

		$element->add_responsive_control(
			'bit_submenu_font_size',
			[
				'label'      => esc_html__( 'Tamanho da fonte', 'bit' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => [ 'px', 'rem', 'em' ],
				'range'      => [
					'px'  => [ 'min' => 10, 'max' => 32 ],
					'rem' => [ 'min' => 0.5, 'max' => 2, 'step' => 0.05 ],
				],
				'default'    => [ 'unit' => 'rem', 'size' => 0.875 ],
				'selectors'  => [
					'{{WRAPPER}}' => '--bis-font-size: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$element->add_control(
			'bit_submenu_font_weight_normal',
			[
				'label'   => esc_html__( 'Peso da fonte', 'bit' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => '400',
				'options' => [
					'300' => '300 — Light',
					'400' => '400 — Normal',
					'500' => '500 — Medium',
					'600' => '600 — Semibold',
					'700' => '700 — Bold',
					'800' => '800 — Extra Bold',
					'900' => '900 — Black',
				],
				'selectors' => [
					'{{WRAPPER}}' => '--bis-font-weight: {{VALUE}};',
				],
			]
		);

		$element->end_controls_tab();

		// ─── Hover ───────────────────────────────────────────────────────
		$element->start_controls_tab( 'bit_submenu_tab_hover', [
			'label' => esc_html__( 'Hover', 'bit' ),
		] );

		$element->add_control(
			'bit_submenu_text_hover',
			[
				'label'     => esc_html__( 'Cor do texto', 'bit' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#B12B79',
				'selectors' => [
					'{{WRAPPER}}' => '--bis-text-hover: {{VALUE}};',
				],
			]
		);

		$element->add_control(
			'bit_submenu_bg_hover',
			[
				'label'     => esc_html__( 'Cor de fundo (item)', 'bit' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#003A26',
				'selectors' => [
					'{{WRAPPER}}' => '--bis-bg-hover: {{VALUE}};',
				],
			]
		);

		$element->add_control(
			'bit_submenu_font_weight_hover',
			[
				'label'   => esc_html__( 'Peso da fonte', 'bit' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => '700',
				'options' => [
					'300' => '300 — Light',
					'400' => '400 — Normal',
					'500' => '500 — Medium',
					'600' => '600 — Semibold',
					'700' => '700 — Bold',
					'800' => '800 — Extra Bold',
					'900' => '900 — Black',
				],
				'selectors' => [
					'{{WRAPPER}}' => '--bis-text-hover-weight: {{VALUE}};',
				],
			]
		);

		$element->end_controls_tab();

		// ─── Ativo ───────────────────────────────────────────────────────
		$element->start_controls_tab( 'bit_submenu_tab_active', [
			'label' => esc_html__( 'Ativo', 'bit' ),
		] );

		$element->add_control(
			'bit_submenu_text_active',
			[
				'label'     => esc_html__( 'Cor do texto', 'bit' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#B12B79',
				'selectors' => [
					'{{WRAPPER}}' => '--bis-text-active: {{VALUE}};',
				],
			]
		);

		$element->add_control(
			'bit_submenu_border_active',
			[
				'label'     => esc_html__( 'Cor da borda inferior', 'bit' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#B12B79',
				'selectors' => [
					'{{WRAPPER}}' => '--bis-border-active: {{VALUE}};',
				],
			]
		);

		$element->add_control(
			'bit_submenu_font_weight_active',
			[
				'label'   => esc_html__( 'Peso da fonte', 'bit' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => '700',
				'options' => [
					'300' => '300 — Light',
					'400' => '400 — Normal',
					'500' => '500 — Medium',
					'600' => '600 — Semibold',
					'700' => '700 — Bold',
					'800' => '800 — Extra Bold',
					'900' => '900 — Black',
				],
				'selectors' => [
					'{{WRAPPER}}' => '--bis-text-active-weight: {{VALUE}};',
				],
			]
		);

		$element->end_controls_tab();

		$element->end_controls_tabs();

		$element->end_controls_section();
	},
	10,
	2
);
