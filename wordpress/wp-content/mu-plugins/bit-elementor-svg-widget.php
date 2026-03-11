<?php
/**
 * Plugin Name:  BIT Elementor SVG Widget
 * Description:  Widget Elementor "Bureau SVG" — carrega SVGs inline do tema
 *               com preview no editor. Suporta qualquer subsite da rede.
 *               Substitui [wpml_logo] e resolve bug de path do JetElements.
 * Version:      1.4.0
 * Author:       Bureau IT
 * Network:      true
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'elementor/widgets/register', function ( $widgets_manager ) {
    if ( ! class_exists( '\Elementor\Widget_Base' ) ) return;

    // Classe definida aqui (dentro do hook) para evitar fatal error:
    // PHP resolve a herança em tempo de execução, não de compilação.
    // Fora do hook, Elementor ainda não carregou → "Class not found".
    class Bureau_SVG_Widget extends \Elementor\Widget_Base {

    public function get_name()        { return 'bureau_svg'; }
    public function get_title()       { return 'Bureau SVG'; }
    public function get_icon()        { return 'eicon-image'; }
    public function get_categories()  { return [ 'general' ]; }
    public function get_keywords()    { return [ 'svg', 'bureau', 'logo', 'icon', 'inline' ]; }

    private function get_svg_options() {
        $svg_dir  = get_stylesheet_directory() . '/svg/';
        $options  = [ '' => '— selecionar —' ];
        $name_map = [
            'logo-concertacao'        => 'Logo Concertação',
            'espiral-concertacao'     => 'Ícone Espiral',
            'espiral-do-conhecimento' => 'Espiral do Conhecimento',
        ];
        foreach ( glob( $svg_dir . '*.svg' ) ?: [] as $file ) {
            $slug             = basename( $file, '.svg' );
            $options[ $slug ] = $name_map[ $slug ] ?? $slug;
        }
        return $options;
    }

    protected function register_controls() {
        $this->start_controls_section( 'content_section', [
            'label' => 'SVG',
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ] );

        $this->add_control( 'svg_name', [
            'label'   => 'Arquivo SVG',
            'type'    => \Elementor\Controls_Manager::SELECT,
            'options' => $this->get_svg_options(),
            'default' => '',
        ] );

        $this->add_control( 'svg_width', [
            'label'      => 'Largura',
            'type'       => \Elementor\Controls_Manager::SLIDER,
            'size_units' => [ 'px', '%', 'vw' ],
            'range'      => [
                'px' => [ 'min' => 50,  'max' => 800, 'step' => 10 ],
                '%'  => [ 'min' => 5,   'max' => 100, 'step' => 5  ],
                'vw' => [ 'min' => 1,   'max' => 50,  'step' => 1  ],
            ],
            'default'    => [ 'unit' => 'px', 'size' => 200 ],
            'selectors'  => [
                '{{WRAPPER}} svg' => 'width: {{SIZE}}{{UNIT}}; height: auto;',
            ],
        ] );

        $this->add_control( 'svg_class', [
            'label'       => 'CSS Class',
            'type'        => \Elementor\Controls_Manager::TEXT,
            'placeholder' => 'minha-classe outra-classe',
            'ai'          => [ 'active' => false ],
        ] );

        $this->add_control( 'svg_id', [
            'label'       => 'CSS ID',
            'type'        => \Elementor\Controls_Manager::TEXT,
            'placeholder' => 'meu-id',
            'ai'          => [ 'active' => false ],
        ] );

        $this->add_control( 'link_enabled', [
            'label'        => 'Adicionar link',
            'type'         => \Elementor\Controls_Manager::SWITCHER,
            'label_on'     => 'Sim',
            'label_off'    => 'Não',
            'return_value' => 'yes',
            'default'      => '',
        ] );

        $this->add_control( 'link_url', [
            'label'       => 'URL do link',
            'type'        => \Elementor\Controls_Manager::TEXT,
            'placeholder' => 'Vazio = URL raiz da rede (auto)',
            'description' => 'Vazio: usa network_home_url() com filtro wpml_home_url automaticamente '
                           . '(retorna /en quando WPML estiver em inglês). '
                           . 'ATENÇÃO: no site www-concertacao.com.br preencher manualmente com '
                           . 'https://concertacaoamazonia.com.br (domínio diferente).',
            'ai'          => [ 'active' => false ],
            'condition'   => [ 'link_enabled' => 'yes' ],
        ] );

        $this->add_control( 'link_target', [
            'label'     => 'Abrir link em',
            'type'      => \Elementor\Controls_Manager::SELECT,
            'options'   => [
                '_self'  => 'Mesma aba',
                '_blank' => 'Nova aba',
            ],
            'default'   => '_self',
            'condition' => [ 'link_enabled' => 'yes' ],
        ] );

        $this->end_controls_section();

        // ── Cor genérica (para SVGs com fill:currentColor) ──
        $this->start_controls_section( 'style_section', [
            'label' => 'Cor',
            'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
        ] );

        $this->add_control( 'svg_color', [
            'label'     => 'Cor do SVG',
            'type'      => \Elementor\Controls_Manager::COLOR,
            'global'    => [
                'default' => \Elementor\Core\Kits\Documents\Tabs\Global_Colors::COLOR_PRIMARY,
            ],
            'selectors' => [
                '{{WRAPPER}}'     => 'color: {{VALUE}};',
                '{{WRAPPER}} a'   => 'color: {{VALUE}};',
                '{{WRAPPER}} svg' => 'color: {{VALUE}};',
            ],
            'description' => 'Funciona em SVGs que usam fill:currentColor (logo-concertacao, espiral-concertacao).',
        ] );

        $this->end_controls_section();

        // ── Espiral do Conhecimento (somente quando selecionada) ──
        $this->start_controls_section( 'spiral_section', [
            'label'     => 'Espiral do Conhecimento',
            'tab'       => \Elementor\Controls_Manager::TAB_STYLE,
            'condition' => [ 'svg_name' => 'espiral-do-conhecimento' ],
        ] );

        $spiral_colors = [
            'spiral_bg_color'       => [ 'Cor de fundo',              '--spiral2026-backgroundcolor' ],
            'spiral_bg_hover_color' => [ 'Cor de fundo (hover)',       '--spiral2026-backgroundcolor-hover' ],
            'spiral_line_color'     => [ 'Cor da linha principal',     '--spiral2026-mainline-color' ],
            'spiral_center_color'   => [ 'Cor centro do gradiente',    '--spiral2026-middle-center-color' ],
            'spiral_edge_color'     => [ 'Cor borda do gradiente',     '--spiral2026-middle-edge-color' ],
            'spiral_flood_color'    => [ 'Cor flood (sombra)',         '--spiral2026-flood-color' ],
            'spiral_rays_color'     => [ 'Cor dos 8 raios',            '--spiral2026-eightrays-color' ],
            'spiral_text_color'     => [ 'Cor do texto',               '--spiral2026-foreignobject-color' ],
        ];

        foreach ( $spiral_colors as $control_id => [ $label, $css_var ] ) {
            $this->add_control( $control_id, [
                'label'     => $label,
                'type'      => \Elementor\Controls_Manager::COLOR,
                'global'    => [ 'active' => true ],
                'selectors' => [
                    '{{WRAPPER}} .SVGSpiral2026' => $css_var . ': {{VALUE}};',
                ],
            ] );
        }

        $this->add_control( 'spiral_fontsize', [
            'label'      => 'Tamanho da fonte',
            'type'       => \Elementor\Controls_Manager::SLIDER,
            'size_units' => [ 'px' ],
            'range'      => [ 'px' => [ 'min' => 8, 'max' => 32, 'step' => 1 ] ],
            'selectors'  => [
                '{{WRAPPER}} .SVGSpiral2026' => '--spiral2026-foreignobject-fontsize: {{SIZE}}{{UNIT}};',
            ],
        ] );

        $this->end_controls_section();
    }

    /**
     * Isola o <style> interno do SVG com um atributo data-scope único,
     * evitando conflito de nomes de classes genéricas (ex: .cls-1) entre
     * múltiplos SVGs inlined na mesma página.
     *
     * Escopo APENAS para SVGs que usam classes genéricas .cls-N.
     * SVGs com classes únicas (ex: .SVGSpiral2026) são retornados sem modificação,
     * preservando seus controles de estilo do Elementor e CSS interno complexo.
     */
    private function scope_svg_styles( string $svg, string $scope_id ): string {
        // Só aplica scoping em SVGs com classes genéricas .cls-N
        if ( ! preg_match( '/\.cls-[\w-]+\s*\{/', $svg ) ) {
            return $svg;
        }

        // Injetar data-scope no elemento <svg>
        $svg = preg_replace( '/<svg\b/', '<svg data-scope="' . esc_attr( $scope_id ) . '"', $svg, 1 );

        // Scopar apenas os seletores .cls-* dentro dos blocos <style>
        $svg = preg_replace_callback(
            '/(<style[^>]*>)(.*?)(<\/style>)/is',
            static function ( $m ) use ( $scope_id ) {
                $scoped = preg_replace_callback(
                    '/(\.cls-[\w-]+(?:\s*,\s*\.cls-[\w-]+)*)\s*\{/',
                    static function ( $r ) use ( $scope_id ) {
                        $selectors = array_map( 'trim', explode( ',', $r[1] ) );
                        $prefixed  = array_map(
                            static fn( $s ) => '[data-scope="' . $scope_id . '"] ' . $s,
                            $selectors
                        );
                        return implode( ', ', $prefixed ) . ' {';
                    },
                    $m[2]
                );
                return $m[1] . $scoped . $m[3];
            },
            $svg
        );

        return $svg;
    }

    protected function render() {
        $s = $this->get_settings_for_display();

        $name = ! empty( $s['svg_name'] ) ? $s['svg_name'] : '';

        if ( empty( $name ) ) return;

        $file = get_stylesheet_directory() . '/svg/' . $name . '.svg';

        if ( ! file_exists( $file ) ) return;

        $svg = file_get_contents( $file );
        if ( empty( $svg ) ) return;

        // Remover declaração XML (inválida em HTML5 inline)
        $svg = preg_replace( '/<\?xml[^?]*\?>\s*/i', '', $svg );

        // Isolar CSS interno com data-scope para evitar conflitos entre SVGs inlined
        $svg = $this->scope_svg_styles( $svg, 'bsvg-' . $this->get_id() );

        // Injetar class no <svg>
        if ( ! empty( $s['svg_class'] ) ) {
            $class = esc_attr( $s['svg_class'] );
            if ( preg_match( '/\bclass="[^"]*"/', $svg ) ) {
                $svg = preg_replace( '/\bclass="([^"]*)"/', 'class="$1 ' . $class . '"', $svg, 1 );
            } else {
                $svg = preg_replace( '/<svg\b/', '<svg class="' . $class . '"', $svg, 1 );
            }
        }

        // Injetar id no <svg>
        if ( ! empty( $s['svg_id'] ) ) {
            $id = esc_attr( $s['svg_id'] );
            if ( preg_match( '/\bid="[^"]*"/', $svg ) ) {
                $svg = preg_replace( '/\bid="[^"]*"/', 'id="' . $id . '"', $svg, 1 );
            } else {
                $svg = preg_replace( '/<svg\b/', '<svg id="' . $id . '"', $svg, 1 );
            }
        }

        // Envolver em <a> se link_enabled = 'yes'
        if ( ! empty( $s['link_enabled'] ) && $s['link_enabled'] === 'yes' ) {
            $href   = ! empty( $s['link_url'] )
                ? esc_url( $s['link_url'] )
                : esc_url( apply_filters( 'wpml_home_url', network_home_url( '/' ) ) );
            $target = ( $s['link_target'] ?? '_self' ) === '_blank'
                ? ' target="_blank" rel="noopener noreferrer"'
                : '';
            $svg    = '<a href="' . $href . '"' . $target . '>' . $svg . '</a>';
        }

        echo $svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
} // end class Bureau_SVG_Widget

    $widgets_manager->register( new Bureau_SVG_Widget() );
} ); // end add_action
