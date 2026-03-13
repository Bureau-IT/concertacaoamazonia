<?php
/**
 * Plugin Name: BIT Dropdown Button
 * Description: Widget Elementor nativo "BIT Download Button" — dropdown com múltiplos
 *              arquivos, links criptografados via JetElements Download Handler.
 *              Fallback para URL direta quando JetElements indisponível.
 *              Ícone configurável via Icon Picker nativo do Elementor.
 * Version:     3.1.0
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
                'media_types' => [
                    'image',
                    'application/pdf',
                    'application/zip',
                    'application/msword',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                ],
                'description' => 'PDF, DOCX, ZIP ou imagem. Link criptografado via JetElements.',
            ] );

            $repeater->add_control( 'item_fallback_url', [
                'label'         => 'URL alternativa',
                'type'          => \Elementor\Controls_Manager::URL,
                'description'   => 'Usada quando nenhum arquivo for selecionado ou JetElements estiver inativo.',
                'show_external' => true,
                'ai'            => [ 'active' => false ],
            ] );

            $repeater->add_control( 'item_file_size', [
                'label'       => 'Tamanho do arquivo',
                'type'        => \Elementor\Controls_Manager::TEXT,
                'placeholder' => '3.2 MB',
                'ai'          => [ 'active' => false ],
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
                'condition'   => [ 'data_source' => 'static' ],
            ] );

            // Toggle: download direto quando só 1 item
            $this->add_control( 'single_direct_download', [
                'label'        => 'Download direto com 1 item',
                'type'         => \Elementor\Controls_Manager::SWITCHER,
                'label_on'     => 'Sim',
                'label_off'    => 'Não',
                'return_value' => 'yes',
                'default'      => 'yes',
                'description'  => 'Quando ativo e há apenas 1 link configurado, o botão vira um link de download direto (sem dropdown).',
            ] );

            // Separador visual
            $this->add_control( 'divider_source', [
                'type' => \Elementor\Controls_Manager::DIVIDER,
            ] );

            // Fonte dos dados
            $this->add_control( 'data_source', [
                'label'   => 'Fonte dos dados',
                'type'    => \Elementor\Controls_Manager::SELECT,
                'default' => 'static',
                'options' => [
                    'static'     => 'Estático (Elementor)',
                    'meta_field' => 'Campo meta (JetEngine)',
                ],
            ] );

            $this->add_control( 'meta_key', [
                'label'       => 'Nome do campo meta',
                'type'        => \Elementor\Controls_Manager::TEXT,
                'placeholder' => 'arquivos_download',
                'condition'   => [ 'data_source' => 'meta_field' ],
                'ai'          => [ 'active' => false ],
            ] );

            $this->add_control( 'meta_key_label', [
                'label'     => 'Sub-campo: rótulo',
                'type'      => \Elementor\Controls_Manager::TEXT,
                'default'   => 'label',
                'condition' => [ 'data_source' => 'meta_field' ],
                'ai'        => [ 'active' => false ],
            ] );

            $this->add_control( 'meta_key_file_id', [
                'label'     => 'Sub-campo: ID do arquivo',
                'type'      => \Elementor\Controls_Manager::TEXT,
                'default'   => 'file_id',
                'condition' => [ 'data_source' => 'meta_field' ],
                'ai'        => [ 'active' => false ],
            ] );

            $this->add_control( 'meta_key_fallback_url', [
                'label'     => 'Sub-campo: URL alternativa',
                'type'      => \Elementor\Controls_Manager::TEXT,
                'default'   => 'fallback_url',
                'condition' => [ 'data_source' => 'meta_field' ],
                'ai'        => [ 'active' => false ],
            ] );

            $this->add_control( 'meta_key_file_size', [
                'label'     => 'Sub-campo: tamanho do arquivo',
                'type'      => \Elementor\Controls_Manager::TEXT,
                'default'   => 'file_size',
                'condition' => [ 'data_source' => 'meta_field' ],
                'ai'        => [ 'active' => false ],
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

            $this->add_control( 'file_size_color', [
                'label'     => 'Cor do tamanho do arquivo',
                'type'      => \Elementor\Controls_Manager::COLOR,
                'selectors' => [
                    '{{WRAPPER}} .dropdown-btn-file-size' => 'color: {{VALUE}};',
                ],
            ] );

            $this->end_controls_section();
        }

        protected function render() {
            $s     = $this->get_settings_for_display();
            $label = esc_html( ! empty( $s['label'] ) ? $s['label'] : 'Download' );

            // ── Pass 1: Montar $links normalizado a partir da fonte selecionada ───
            $links = [];

            if ( 'meta_field' === ( $s['data_source'] ?? 'static' ) && ! empty( $s['meta_key'] ) ) {
                $raw = [];
                if ( function_exists( 'jet_engine' )
                     && ! empty( jet_engine()->listings )
                     && ! empty( jet_engine()->listings->data ) ) {
                    $raw = jet_engine()->listings->data->get_meta( $s['meta_key'] );
                }
                if ( empty( $raw ) ) {
                    $raw = get_post_meta( get_the_ID(), $s['meta_key'], true );
                }
                if ( is_string( $raw ) ) {
                    $raw = maybe_unserialize( $raw );
                }

                $k_label    = ! empty( $s['meta_key_label'] )        ? $s['meta_key_label']        : 'label';
                $k_file_id  = ! empty( $s['meta_key_file_id'] )      ? $s['meta_key_file_id']      : 'file_id';
                $k_fallback = ! empty( $s['meta_key_fallback_url'] )  ? $s['meta_key_fallback_url'] : 'fallback_url';
                $k_size     = ! empty( $s['meta_key_file_size'] )     ? $s['meta_key_file_size']    : 'file_size';

                foreach ( (array) $raw as $row ) {
                    $links[] = [
                        'item_label'        => $row[ $k_label ]    ?? '',
                        'item_file'         => [ 'id' => (int) ( $row[ $k_file_id ] ?? 0 ), 'url' => '' ],
                        'item_fallback_url' => [ 'url' => $row[ $k_fallback ] ?? '', 'is_external' => false ],
                        'item_file_size'    => $row[ $k_size ] ?? '',
                    ];
                }
            } else {
                $links = $s['links'] ?? [];
            }

            // ── Pass 2: Resolver URLs, auto-detectar tamanho, filtrar itens sem URL ─
            $valid_links    = [];
            $jet_handler_ok = function_exists( 'jet_elements_download_handler' )
                              && jet_elements_download_handler() instanceof Jet_Elements_Download_Handler;

            foreach ( $links as $link ) {
                $attachment_id = (int) ( $link['item_file']['id'] ?? 0 );
                $fallback_url  = $link['item_fallback_url']['url'] ?? '';
                $is_external   = ! empty( $link['item_fallback_url']['is_external'] );

                if ( $attachment_id > 0 && $jet_handler_ok ) {
                    $href        = esc_url( jet_elements_download_handler()->get_download_link( $attachment_id ) );
                    $target_attr = '';
                } elseif ( ! empty( $fallback_url ) ) {
                    $href        = esc_url( $fallback_url );
                    $target_attr = $is_external ? ' target="_blank"' : '';
                } else {
                    continue;
                }

                // Tamanho: manual primeiro; auto-detecta via JetElements quando vazio e attachment presente
                $file_size = esc_html( $link['item_file_size'] ?? '' );
                if ( empty( $file_size ) && $attachment_id > 0 && $jet_handler_ok ) {
                    $auto_size = jet_elements_download_handler()->get_file_size( $attachment_id );
                    $file_size = $auto_size ? esc_html( $auto_size ) : '';
                }

                $valid_links[] = [
                    'label'       => esc_html( $link['item_label'] ?? '' ),
                    'href'        => $href,
                    'target_attr' => $target_attr,
                    'file_size'   => $file_size,
                ];
            }

            // ── Pass 3: Decidir modo e renderizar ─────────────────────────────────
            $single_mode = ( count( $valid_links ) === 1 && 'yes' === ( $s['single_direct_download'] ?? 'yes' ) );

            // Ícone (compartilhado entre modos)
            $icon_settings = $s['icon'] ?? [];
            $icon_html     = '';
            if ( ! empty( $icon_settings['value'] ) ) {
                ob_start();
                \Elementor\Icons_Manager::render_icon( $icon_settings, [ 'aria-hidden' => 'true' ] );
                $icon_html = ob_get_clean();
            }
            $icon_output = ! empty( $icon_html ) ? $icon_html : $this->get_default_icon_svg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

            echo '<div class="dropdown-btn-wrapper">';
            echo '<div class="dropdown-btn-container">';

            if ( $single_mode ) {
                // Modo download direto — âncora sem dropdown
                $item = $valid_links[0];
                echo '<a class="dropdown-btn-toggle dropdown-btn-single"'
                   . ' href="' . $item['href'] . '"'
                   . ' rel="nofollow noopener"'
                   . $item['target_attr'] . '>';
                echo '<div class="dropdown-btn-content">';
                echo '<span class="dropdown-btn-label">' . $label . '</span>';
                if ( ! empty( $item['file_size'] ) ) {
                    echo '<span class="dropdown-btn-file-size">' . $item['file_size'] . '</span>';
                }
                echo '<span class="dropdown-btn-icon">';
                echo $icon_output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                echo '</span>';
                echo '</div>'; // .dropdown-btn-content
                echo '</a>';
            } else {
                // Modo dropdown
                echo '<button class="dropdown-btn-toggle" type="button">';
                echo '<div class="dropdown-btn-content">';
                echo '<span class="dropdown-btn-label">' . $label . '</span>';
                echo '<span class="dropdown-btn-icon">';
                echo $icon_output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                echo '</span>';
                echo '</div>'; // .dropdown-btn-content
                echo '</button>';

                if ( ! empty( $valid_links ) ) {
                    echo '<div class="dropdown-btn-menu">';
                    foreach ( $valid_links as $item ) {
                        echo '<a href="' . $item['href'] . '" rel="nofollow noopener"' . $item['target_attr'] . '>';
                        echo '<span class="dropdown-btn-item-label">' . $item['label'] . '</span>';
                        if ( ! empty( $item['file_size'] ) ) {
                            echo '<span class="dropdown-btn-file-size">' . $item['file_size'] . '</span>';
                        }
                        echo '</a>';
                    }
                    echo '</div>'; // .dropdown-btn-menu
                }
            }

            echo '</div>'; // .dropdown-btn-container
            echo '</div>'; // .dropdown-btn-wrapper
        }
    }

    $widgets_manager->register( new Bit_Dropdown_Btn_Widget() );
} );
