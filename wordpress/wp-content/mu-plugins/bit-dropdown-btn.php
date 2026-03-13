<?php
/**
 * Plugin Name: BIT Dropdown Button
 * Description: Widget Elementor nativo "BIT Download Button" — dropdown com múltiplos
 *              arquivos, links criptografados via JetElements Download Handler.
 *              Fallback para URL direta quando JetElements indisponível.
 *              Ícone configurável via Icon Picker nativo do Elementor.
 * Version:     3.0.0
 * Author:      Bureau IT
 * Network:     true
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'wp_enqueue_scripts', function () {
    wp_enqueue_style(
        'bit-dropdown-btn',
        WPMU_PLUGIN_URL . '/bit-dropdown-btn.css',
        [ 'hello-elementor-child' ],
        filemtime( WPMU_PLUGIN_DIR . '/bit-dropdown-btn.css' )
    );
} );

add_action( 'elementor/widgets/register', function ( $widgets_manager ) {
    if ( ! class_exists( '\Elementor\Widget_Base' ) ) return;

    class Bit_Dropdown_Btn_Widget extends \Elementor\Widget_Base {

        public function get_name()       { return 'bit_dropdown_btn'; }
        public function get_title()      { return 'BIT Download Button'; }
        public function get_icon()       { return 'eicon-button'; }
        public function get_categories() { return [ 'general' ]; }
        public function get_keywords()   { return [ 'download', 'dropdown', 'button', 'bit', 'bureau' ]; }

        /**
         * SVG padrão (círculo com seta apontando para baixo).
         * Usado quando o controle ICONS não tiver seleção.
         * fill-rule="evenodd" garante o interior vazado correto.
         */
        private function get_default_icon_svg(): string {
            return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 22 22" '
                 . 'aria-hidden="true" focusable="false" fill="currentColor">'
                 . '<path fill-rule="evenodd" d="M11,0C4.92,0,0,4.92,0,11s4.92,11,11,11,11-4.92,11-11'
                 . 'S17.08,0,11,0Zm.13,8.42h3.5l-1.75,3.03-1.75,3.03-1.75-3.03-1.75-3.03h3.5Z"/>'
                 . '</svg>';
        }

        protected function register_controls() {

            // ── Tab Content ──────────────────────────────────────────────────
            $this->start_controls_section( 'content_section', [
                'label' => 'Botão',
                'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
            ] );

            $this->add_control( 'label', [
                'label'       => 'Rótulo do botão',
                'type'        => \Elementor\Controls_Manager::TEXT,
                'default'     => 'DOWNLOAD',
                'label_block' => true,
                'ai'          => [ 'active' => false ],
            ] );

            $this->add_control( 'icon', [
                'label'   => 'Ícone',
                'type'    => \Elementor\Controls_Manager::ICONS,
                'default' => [ 'value' => 'eicon-download', 'library' => 'eicons' ],
            ] );

            $repeater = new \Elementor\Repeater();

            $repeater->add_control( 'item_label', [
                'label'       => 'Rótulo',
                'type'        => \Elementor\Controls_Manager::TEXT,
                'default'     => 'Português',
                'label_block' => true,
                'ai'          => [ 'active' => false ],
            ] );

            $repeater->add_control( 'item_file', [
                'label'       => 'Arquivo (biblioteca de mídia)',
                'type'        => \Elementor\Controls_Manager::MEDIA,
                'description' => 'Selecione um arquivo. O link será criptografado automaticamente via JetElements.',
            ] );

            $repeater->add_control( 'item_fallback_url', [
                'label'         => 'URL alternativa',
                'type'          => \Elementor\Controls_Manager::URL,
                'description'   => 'Usada quando nenhum arquivo for selecionado ou JetElements estiver inativo.',
                'show_external' => true,
                'ai'            => [ 'active' => false ],
            ] );

            $this->add_control( 'links', [
                'label'       => 'Links do dropdown',
                'type'        => \Elementor\Controls_Manager::REPEATER,
                'fields'      => $repeater->get_controls(),
                'default'     => [
                    [ 'item_label' => 'Português', 'item_file' => [ 'id' => 0, 'url' => '' ], 'item_fallback_url' => [ 'url' => '' ] ],
                    [ 'item_label' => 'English',   'item_file' => [ 'id' => 0, 'url' => '' ], 'item_fallback_url' => [ 'url' => '' ] ],
                ],
                'title_field' => '{{{ item_label }}}',
            ] );

            $this->end_controls_section();

            // ── Tab Style — Botão Dimensões ──────────────────────────────────
            $this->start_controls_section( 'style_btn_dims', [
                'label' => 'Botão — Dimensões',
                'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
            ] );

            $this->add_control( 'btn_width', [
                'label'      => 'Largura',
                'type'       => \Elementor\Controls_Manager::SLIDER,
                'size_units' => [ 'px', '%' ],
                'range'      => [
                    'px' => [ 'min' => 100, 'max' => 600, 'step' => 5 ],
                    '%'  => [ 'min' => 20,  'max' => 100, 'step' => 5 ],
                ],
                'default'    => [ 'unit' => 'px', 'size' => 220 ],
                'selectors'  => [
                    '{{WRAPPER}} .dropdown-btn-toggle' => 'width: {{SIZE}}{{UNIT}};',
                ],
            ] );

            $this->add_control( 'btn_border_radius', [
                'label'      => 'Border radius',
                'type'       => \Elementor\Controls_Manager::DIMENSIONS,
                'size_units' => [ 'px', '%' ],
                'default'    => [
                    'top'      => 4,
                    'right'    => 4,
                    'bottom'   => 4,
                    'left'     => 4,
                    'unit'     => 'px',
                    'isLinked' => true,
                ],
                'selectors'  => [
                    '{{WRAPPER}} .dropdown-btn-toggle' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ] );

            $this->add_control( 'btn_padding', [
                'label'      => 'Padding',
                'type'       => \Elementor\Controls_Manager::DIMENSIONS,
                'size_units' => [ 'px', 'em' ],
                'default'    => [
                    'top'      => 15,
                    'right'    => 25,
                    'bottom'   => 15,
                    'left'     => 25,
                    'unit'     => 'px',
                    'isLinked' => false,
                ],
                'selectors'  => [
                    '{{WRAPPER}} .dropdown-btn-toggle' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ] );

            $this->add_group_control(
                \Elementor\Group_Control_Box_Shadow::get_type(),
                [
                    'name'     => 'btn_box_shadow',
                    'selector' => '{{WRAPPER}} .dropdown-btn-toggle',
                ]
            );

            $this->end_controls_section();

            // ── Tab Style — Cores Normal ─────────────────────────────────────
            $this->start_controls_section( 'style_colors_normal', [
                'label' => 'Cores — Normal',
                'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
            ] );

            $this->add_control( 'color_bg', [
                'label'     => 'Fundo',
                'type'      => \Elementor\Controls_Manager::COLOR,
                'selectors' => [ '{{WRAPPER}}' => '--btn-normal-bg: {{VALUE}};' ],
            ] );

            $this->add_control( 'color_txt', [
                'label'     => 'Texto',
                'type'      => \Elementor\Controls_Manager::COLOR,
                'selectors' => [ '{{WRAPPER}}' => '--btn-normal-txt: {{VALUE}};' ],
            ] );

            $this->add_control( 'color_bdr', [
                'label'     => 'Borda',
                'type'      => \Elementor\Controls_Manager::COLOR,
                'selectors' => [ '{{WRAPPER}}' => '--btn-normal-bdr: {{VALUE}};' ],
            ] );

            $this->end_controls_section();

            // ── Tab Style — Cores Hover ──────────────────────────────────────
            $this->start_controls_section( 'style_colors_hover', [
                'label' => 'Cores — Hover',
                'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
            ] );

            $this->add_control( 'color_bg_hv', [
                'label'     => 'Fundo (hover)',
                'type'      => \Elementor\Controls_Manager::COLOR,
                'selectors' => [ '{{WRAPPER}}' => '--btn-normal-bg-hv: {{VALUE}};' ],
            ] );

            $this->add_control( 'color_txt_hv', [
                'label'     => 'Texto (hover)',
                'type'      => \Elementor\Controls_Manager::COLOR,
                'selectors' => [ '{{WRAPPER}}' => '--btn-normal-txt-hv: {{VALUE}};' ],
            ] );

            $this->add_control( 'color_bdr_hv', [
                'label'     => 'Borda (hover)',
                'type'      => \Elementor\Controls_Manager::COLOR,
                // Mantém --btn-normal-border-hv (inconsistência herdada do CSS — não alterar)
                'selectors' => [ '{{WRAPPER}}' => '--btn-normal-border-hv: {{VALUE}};' ],
            ] );

            $this->end_controls_section();

            // ── Tab Style — Ícone ────────────────────────────────────────────
            $this->start_controls_section( 'style_icon', [
                'label' => 'Ícone',
                'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
            ] );

            $this->add_control( 'icon_size', [
                'label'      => 'Tamanho',
                'type'       => \Elementor\Controls_Manager::SLIDER,
                'size_units' => [ 'px' ],
                'range'      => [ 'px' => [ 'min' => 12, 'max' => 64, 'step' => 1 ] ],
                'default'    => [ 'unit' => 'px', 'size' => 30 ],
                'selectors'  => [
                    '{{WRAPPER}} .dropdown-btn-icon svg, {{WRAPPER}} .dropdown-btn-icon i' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};',
                ],
            ] );

            $this->add_control( 'color_icn', [
                'label'     => 'Cor (normal)',
                'type'      => \Elementor\Controls_Manager::COLOR,
                'selectors' => [ '{{WRAPPER}}' => '--btn-normal-icn: {{VALUE}};' ],
            ] );

            $this->add_control( 'color_icn_hv', [
                'label'     => 'Cor (hover)',
                'type'      => \Elementor\Controls_Manager::COLOR,
                'selectors' => [ '{{WRAPPER}}' => '--btn-normal-icon-hv: {{VALUE}};' ],
            ] );

            $this->end_controls_section();

            // ── Tab Style — Tipografia ───────────────────────────────────────
            $this->start_controls_section( 'style_typography', [
                'label' => 'Tipografia',
                'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
            ] );

            $this->add_group_control(
                \Elementor\Group_Control_Typography::get_type(),
                [
                    'name'     => 'label_typography',
                    'label'    => 'Label do botão',
                    'selector' => '{{WRAPPER}} .dropdown-btn-label',
                ]
            );

            $this->add_group_control(
                \Elementor\Group_Control_Typography::get_type(),
                [
                    'name'     => 'menu_typography',
                    'label'    => 'Itens do menu',
                    'selector' => '{{WRAPPER}} .dropdown-btn-menu a',
                ]
            );

            $this->end_controls_section();

            // ── Tab Style — Menu Dropdown ────────────────────────────────────
            $this->start_controls_section( 'style_menu', [
                'label' => 'Menu Dropdown',
                'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
            ] );

            $this->add_control( 'menu_min_width', [
                'label'      => 'Largura mínima',
                'type'       => \Elementor\Controls_Manager::SLIDER,
                'size_units' => [ 'px', '%' ],
                'range'      => [
                    'px' => [ 'min' => 100, 'max' => 600, 'step' => 5 ],
                    '%'  => [ 'min' => 50,  'max' => 200, 'step' => 5 ],
                ],
                'selectors'  => [
                    '{{WRAPPER}} .dropdown-btn-menu' => 'min-width: {{SIZE}}{{UNIT}};',
                ],
            ] );

            $this->add_control( 'menu_item_padding', [
                'label'      => 'Padding do item',
                'type'       => \Elementor\Controls_Manager::DIMENSIONS,
                'size_units' => [ 'px', 'em' ],
                'default'    => [
                    'top'      => 12,
                    'right'    => 20,
                    'bottom'   => 12,
                    'left'     => 20,
                    'unit'     => 'px',
                    'isLinked' => false,
                ],
                'selectors'  => [
                    '{{WRAPPER}} .dropdown-btn-menu a' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
                ],
            ] );

            $this->add_control( 'menu_color_bg', [
                'label'     => 'Fundo',
                'type'      => \Elementor\Controls_Manager::COLOR,
                'selectors' => [ '{{WRAPPER}} .dropdown-btn-menu' => 'background-color: {{VALUE}};' ],
            ] );

            $this->add_control( 'menu_color_txt', [
                'label'     => 'Texto',
                'type'      => \Elementor\Controls_Manager::COLOR,
                'selectors' => [ '{{WRAPPER}} .dropdown-btn-menu a' => 'color: {{VALUE}};' ],
            ] );

            $this->add_control( 'menu_color_txt_hv', [
                'label'     => 'Texto (hover)',
                'type'      => \Elementor\Controls_Manager::COLOR,
                'selectors' => [ '{{WRAPPER}} .dropdown-btn-menu a:hover' => 'color: {{VALUE}};' ],
            ] );

            $this->add_control( 'menu_color_bg_hv', [
                'label'     => 'Fundo (hover)',
                'type'      => \Elementor\Controls_Manager::COLOR,
                'selectors' => [ '{{WRAPPER}} .dropdown-btn-menu a:hover' => 'background-color: {{VALUE}};' ],
            ] );

            $this->add_control( 'menu_color_bdr', [
                'label'     => 'Borda',
                'type'      => \Elementor\Controls_Manager::COLOR,
                'selectors' => [ '{{WRAPPER}} .dropdown-btn-menu' => 'border-color: {{VALUE}};' ],
            ] );

            $this->add_control( 'menu_color_divider', [
                'label'     => 'Separador entre itens',
                'type'      => \Elementor\Controls_Manager::COLOR,
                'selectors' => [ '{{WRAPPER}} .dropdown-btn-menu a' => 'border-bottom-color: {{VALUE}};' ],
            ] );

            $this->end_controls_section();
        }

        protected function render() {
            $s     = $this->get_settings_for_display();
            $label = esc_html( ! empty( $s['label'] ) ? $s['label'] : 'Download' );
            $links = ! empty( $s['links'] ) ? $s['links'] : [];

            echo '<div class="dropdown-btn-wrapper">';
            echo '<div class="dropdown-btn-container">';
            echo '<button class="dropdown-btn-toggle" type="button">';
            echo '<div class="dropdown-btn-content">';
            echo '<span class="dropdown-btn-label">' . $label . '</span>';

            // Ícone — tenta Icon Picker, cai para SVG padrão
            echo '<span class="dropdown-btn-icon">';
            $icon_settings = $s['icon'] ?? [];
            $icon_html     = '';
            if ( ! empty( $icon_settings['value'] ) ) {
                ob_start();
                \Elementor\Icons_Manager::render_icon( $icon_settings, [ 'aria-hidden' => 'true' ] );
                $icon_html = ob_get_clean();
            }
            echo ! empty( $icon_html ) ? $icon_html : $this->get_default_icon_svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo '</span>';

            echo '</div>'; // .dropdown-btn-content
            echo '</button>';

            // Menu dropdown
            if ( ! empty( $links ) ) {
                echo '<div class="dropdown-btn-menu">';
                foreach ( $links as $link ) {
                    $item_label    = esc_html( $link['item_label'] ?? '' );
                    $attachment_id = (int) ( $link['item_file']['id'] ?? 0 );
                    $fallback_url  = $link['item_fallback_url']['url'] ?? '';
                    $is_external   = ! empty( $link['item_fallback_url']['is_external'] );

                    if ( $attachment_id > 0
                         && function_exists( 'jet_elements_download_handler' )
                         && jet_elements_download_handler() instanceof Jet_Elements_Download_Handler ) {
                        // Link criptografado via JetElements
                        $href           = esc_url( jet_elements_download_handler()->get_download_link( $attachment_id ) );
                        $target_attr = '';
                    } elseif ( ! empty( $fallback_url ) ) {
                        // URL direta (fallback)
                        $href           = esc_url( $fallback_url );
                        $target_attr = $is_external ? ' target="_blank"' : '';
                    } else {
                        continue; // nenhuma URL válida — não renderiza este item
                    }

                    echo '<a href="' . $href . '" rel="nofollow noopener"' . $target_attr . '>'
                       . $item_label . '</a>';
                }
                echo '</div>'; // .dropdown-btn-menu
            }

            echo '</div>'; // .dropdown-btn-container
            echo '</div>'; // .dropdown-btn-wrapper
        }
    }

    $widgets_manager->register( new Bit_Dropdown_Btn_Widget() );
} );
