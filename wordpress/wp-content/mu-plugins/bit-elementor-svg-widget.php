<?php
/**
 * Plugin Name:  BIT Elementor SVG Widget
 * Description:  Widget Elementor "Bureau SVG" — carrega SVGs inline do tema
 *               com preview no editor. Suporta qualquer subsite da rede.
 *               Substitui [wpml_logo] e resolve bug de path do JetElements.
 * Version:      1.0.0
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
        $svg_dir = get_stylesheet_directory() . '/svg/';
        $options = [ '' => '— selecionar —' ];
        foreach ( glob( $svg_dir . '*.svg' ) ?: [] as $file ) {
            $name             = basename( $file, '.svg' );
            $options[ $name ] = $name;
        }
        return $options;
    }

    protected function register_controls() {
        $this->start_controls_section( 'content_section', [
            'label' => 'SVG',
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ] );

        $this->add_control( 'svg_name', [
            'label'   => 'Arquivo SVG (tema ativo)',
            'type'    => \Elementor\Controls_Manager::SELECT,
            'options' => $this->get_svg_options(),
            'default' => '',
        ] );

        $this->add_control( 'svg_custom', [
            'label'       => 'Ou nome customizado',
            'type'        => \Elementor\Controls_Manager::TEXT,
            'placeholder' => 'nome-do-arquivo (sem .svg)',
            'description' => 'Se preenchido, substitui o dropdown acima. Busca em svg/ do tema ativo.',
            'ai'          => [ 'active' => false ],
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
                '{{WRAPPER}}' => 'color: {{VALUE}};',
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

    protected function render() {
        $s = $this->get_settings_for_display();

        $name = ! empty( $s['svg_custom'] )
            ? sanitize_file_name( trim( $s['svg_custom'] ) )
            : ( ! empty( $s['svg_name'] ) ? $s['svg_name'] : '' );

        if ( empty( $name ) ) return;

        $file = get_stylesheet_directory() . '/svg/' . $name . '.svg';
        if ( ! file_exists( $file ) ) {
            if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
                echo '<p style="color:red;font-size:12px;">SVG não encontrado: ' . esc_html( $name ) . '.svg</p>';
            }
            return;
        }

        $svg = file_get_contents( $file );
        if ( empty( $svg ) ) return;

        // Remover declaração XML (inválida em HTML5 inline)
        $svg = preg_replace( '/<\?xml[^?]*\?>\s*/i', '', $svg );

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
