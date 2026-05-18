<?php
/**
 * BIT_4Amazonias_Widget — Elementor widget renderer.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class BIT_4Amazonias_Widget extends \Elementor\Widget_Base {

    public function get_name() { return 'bit-4amazonias'; }
    public function get_title() { return 'BIT 4 Amazonias'; }
    public function get_icon() { return 'eicon-bit-4amazonias'; }
    public function get_categories() { return [ 'general' ]; }
    public function get_keywords() { return [ 'amazonia', '4 amazonias', 'concertacao', 'tabela', 'bit' ]; }

    /**
     * Conteudo (PT/EN). Selecao por ICL_LANGUAGE_CODE no render.
     * Estrutura: 4 amazonias x 4 linhas (Premissa, 6 Especificas, Estruturantes, Transversais).
     */
    private function get_content( $lang = 'pt' ) {
        require __DIR__ . '/content-pt.php';
        require __DIR__ . '/content-en.php';
        return ( $lang === 'en' ) ? $en_content : $pt_content;
    }

    /**
     * SVG icons (markup original; fill='white' sera sobrescrito por CSS).
     */
    private function get_icons() {
        return require __DIR__ . '/icons.php';
    }

    protected function register_controls() {
        // --- Section: Comportamento ---
        $this->start_controls_section( 'behavior_section', [
            'label' => 'Comportamento',
            'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
        ] );

        $this->add_control( 'show_toggle', [
            'label'        => 'Iniciar recolhido (show/hide)',
            'type'         => \Elementor\Controls_Manager::SWITCHER,
            'label_on'     => 'Sim',
            'label_off'    => 'Nao',
            'return_value' => 'yes',
            'default'      => 'yes',
        ] );

        $this->add_control( 'btn_text_expand_pt', [
            'label'     => 'Texto botao expandir (PT)',
            'type'      => \Elementor\Controls_Manager::TEXT,
            'default'   => 'Conheça as quatro Amazônias',
            'condition' => [ 'show_toggle' => 'yes' ],
        ] );
        $this->add_control( 'btn_text_collapse_pt', [
            'label'     => 'Texto botao recolher (PT)',
            'type'      => \Elementor\Controls_Manager::TEXT,
            'default'   => 'Fechar framework das quatro Amazônias',
            'condition' => [ 'show_toggle' => 'yes' ],
        ] );
        $this->add_control( 'btn_text_expand_en', [
            'label'     => 'Texto botao expandir (EN)',
            'type'      => \Elementor\Controls_Manager::TEXT,
            'default'   => 'Learn about the four Amazonias',
            'condition' => [ 'show_toggle' => 'yes' ],
        ] );
        $this->add_control( 'btn_text_collapse_en', [
            'label'     => 'Texto botao recolher (EN)',
            'type'      => \Elementor\Controls_Manager::TEXT,
            'default'   => 'Close the four Amazonias framework',
            'condition' => [ 'show_toggle' => 'yes' ],
        ] );

        $this->end_controls_section();

        // --- Section: Cores ---
        $this->start_controls_section( 'colors_section', [
            'label' => 'Cores',
            'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
        ] );

        // Header (col-headers)
        $this->add_control( 'header_bg', [
            'label'     => 'Header — fundo',
            'type'      => \Elementor\Controls_Manager::COLOR,
                        'selectors' => [ '{{WRAPPER}} .bit4a-col-header' => 'background-color: {{VALUE}};' ],
            'default'   => '',
        ] );
        $this->add_control( 'header_text', [
            'label'     => 'Header — texto',
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .bit4a-col-header' => 'color: {{VALUE}};' ],
            'default'   => '',
        ] );

        // Row label (1a coluna)
        $this->add_control( 'rowlabel_bg', [
            'label'     => 'Rotulo de linha — fundo',
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .bit4a-col-rowlabel' => 'background-color: {{VALUE}};' ],
            'default'   => '',
        ] );
        $this->add_control( 'rowlabel_text', [
            'label'     => 'Rotulo de linha — texto',
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .bit4a-col-rowlabel' => 'color: {{VALUE}};' ],
            'default'   => '',
        ] );

        // Cells
        $this->add_control( 'cell_bg', [
            'label'     => 'Celulas — fundo',
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .bit4a-col-cell' => 'background-color: {{VALUE}};' ],
            'default'   => '',
        ] );
        $this->add_control( 'cell_text', [
            'label'     => 'Celulas — texto',
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .bit4a-col-cell' => 'color: {{VALUE}};' ],
            'default'   => '',
        ] );

        // Fullrow (Estruturantes / Transversais)
        $this->add_control( 'fullrow_bg', [
            'label'     => 'Linhas full-width — fundo',
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .bit4a-fullrow' => 'background-color: {{VALUE}};' ],
            'default'   => '',
        ] );
        $this->add_control( 'fullrow_text', [
            'label'     => 'Linhas full-width — texto',
            'type'      => \Elementor\Controls_Manager::COLOR,
            'selectors' => [ '{{WRAPPER}} .bit4a-fullrow' => 'color: {{VALUE}};' ],
            'default'   => '',
        ] );

        $this->end_controls_section();
    }

    protected function render() {
        $lang = ( defined( 'ICL_LANGUAGE_CODE' ) && ICL_LANGUAGE_CODE === 'en' ) ? 'en' : 'pt';
        $C = $this->get_content( $lang );
        $icons = $this->get_icons();
        $widget_id = $this->get_id();

        // Helpers
        $cols = $C['cols'];

        $svg = function( $key ) use ( $icons ) {
            return isset( $icons[ $key ] ) ? $icons[ $key ] : '';
        };

        $list_items = function( $arr ) {
            $out = '';
            foreach ( $arr as $it ) {
                $out .= '<li>' . esc_html( $it ) . '</li>';
            }
            return '<ul>' . $out . '</ul>';
        };

        $list_or_div = function( $arr ) {
            $out = '';
            foreach ( $arr as $it ) {
                $out .= '<div>' . esc_html( $it ) . '</div>';
            }
            return $out;
        };

        // Inline CSS (escopado pelo widget id para nao colidir)
        $w = '.elementor-element-' . esc_attr( $widget_id ) . ' ';
        ?>
        <style>
        <?php echo $w; ?>.bit4a-wrap { font-family: inherit; }
        <?php echo $w; ?>.bit4a-svg-fix svg, <?php echo $w; ?>.bit4a-svg-fix svg path { fill: currentColor !important; }

        /* Force widget to respect viewport even when parent e-con-full has a
           larger declared width (some Elementor full-width containers do this).
           min() ensures we never exceed viewport. */
        .elementor-element.elementor-element-<?php echo esc_attr( $widget_id ); ?> {
            width: 100% !important;
            max-width: min(100vw, 1140px) !important;
            margin-inline: auto !important;
        }

        /* Container query: switch layout based on widget container width,
           not viewport. Ensures table-desktop only renders when it fits. */
        <?php echo $w; ?>.bit4a-collapsible {
            container-type: inline-size;
            container-name: bit4a;
        }

        /* Default = mobile (works without container-query support) */
        <?php echo $w; ?>.bit4a-mobile { display: block; padding: 16px; }
        <?php echo $w; ?>.bit4a-desktop { display: none; }

        /* Desktop layout (when container >= 1140px) */
        @container bit4a (min-width: 1140px) {
            <?php echo $w; ?>.bit4a-mobile { display: none; }
            <?php echo $w; ?>.bit4a-desktop { display: block; }
            <?php echo $w; ?>.bit4a-col-headers {
                display: grid; grid-template-columns: 200px repeat(4, 1fr); gap: 12px;
                margin-bottom: 12px; padding: 8px 0;
                background: transparent;
                position: sticky; top: 0; z-index: 10;
            }
            <?php echo $w; ?>.bit4a-col-headers::before {
                content: ''; position: absolute; left: 0; right: 0;
                bottom: 100%; height: 40px; z-index: 1; pointer-events: none;
                background: linear-gradient(to bottom, rgba(246,239,234,0) 0%, var(--e-global-color-e03d05f, #F6EFEA) 100%);
            }
            <?php echo $w; ?>.bit4a-col-spacer { background: transparent; }
            <?php echo $w; ?>.bit4a-col-header {
                background: var(--e-global-color-text, #005A42);
                color: var(--e-global-color-e03d05f, #F6EFEA);
                padding: 14px; border-radius: 8px; text-align: center;
                font-weight: 700; font-style: italic; letter-spacing: 0.04em; font-size: 0.85rem;
            }
            <?php echo $w; ?>.bit4a-col-header svg { width: 52px; height: 52px; fill: currentColor; display: block; margin: 0 auto 6px; }
            <?php echo $w; ?>.bit4a-col-grid {
                display: grid; grid-template-columns: 200px repeat(4, 1fr); gap: 12px;
                align-items: stretch; margin-bottom: 8px;
            }
            <?php echo $w; ?>.bit4a-col-rowlabel {
                background: var(--e-global-color-96a86ed, #003A26);
                color: var(--e-global-color-e03d05f, #F6EFEA);
                padding: 14px; border-radius: 8px;
                display: flex; align-items: center; justify-content: center;
                text-align: center; font-weight: 700; font-style: italic;
                font-size: 0.85rem; letter-spacing: 0.04em;
            }
            <?php echo $w; ?>.bit4a-col-cell {
                background: #fff;
                color: var(--e-global-color-text, #005A42);
                padding: 14px; border-radius: 8px;
                font-size: 0.85rem; line-height: 1.45;
                display: flex; flex-direction: column; justify-content: center;
            }
            <?php echo $w; ?>.bit4a-col-cell ul { margin: 0; padding-left: 16px; }
            <?php echo $w; ?>.bit4a-col-cell li { margin-bottom: 4px; }
            <?php echo $w; ?>.bit4a-block-label {
                font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.1em;
                opacity: 0.65; font-weight: 700; margin: 18px 0 8px;
                color: var(--e-global-color-text, #005A42);
            }
            <?php echo $w; ?>.bit4a-section-label {
                text-transform: uppercase; letter-spacing: 0.1em; font-size: 0.7rem; font-weight: 700;
                color: var(--e-global-color-text, #005A42); opacity: 0.65; margin: 22px 0 10px; text-align: center;
            }
            <?php echo $w; ?>.bit4a-fullrow {
                background: var(--e-global-color-96a86ed, #003A26);
                color: var(--e-global-color-e03d05f, #F6EFEA);
                border-radius: 8px; padding: 16px 20px; margin-bottom: 8px;
            }
            <?php echo $w; ?>.bit4a-fullrow-header { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; font-weight: 700; font-style: italic; letter-spacing: 0.04em; }
            <?php echo $w; ?>.bit4a-fullrow-amazonias { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin-bottom: 14px; background: rgba(255,255,255,0.06); padding: 10px 14px; border-radius: 6px; }
            <?php echo $w; ?>.bit4a-amz { display: flex; flex-direction: column; align-items: center; font-size: 0.65rem; opacity: 0.85; text-align: center; gap: 4px; }
            <?php echo $w; ?>.bit4a-amz svg { width: 32px; height: 32px; fill: currentColor; }
            <?php echo $w; ?>.bit4a-fullrow ul { columns: 2; column-gap: 32px; margin: 0; padding-left: 18px; font-size: 0.9rem; line-height: 1.45; }
            <?php echo $w; ?>.bit4a-fullrow li { break-inside: avoid; margin-bottom: 4px; }
        }

        /* Mobile layout (<= 1410px) */
        @media (max-width: 1139px) {
            <?php echo $w; ?>.bit4a-desktop { display: none; }
            <?php echo $w; ?>.bit4a-mobile { display: block; padding: 16px; }
            <?php echo $w; ?>.bit4a-section-label {
                text-transform: uppercase; letter-spacing: 0.08em; font-size: 0.65rem;
                font-weight: 700; color: var(--e-global-color-text, #005A42); opacity: 0.55;
                margin: 18px 0 8px; display: flex; align-items: center; gap: 8px;
            }
            <?php echo $w; ?>.bit4a-section-label::before, <?php echo $w; ?>.bit4a-section-label::after {
                content: ''; flex: 1; height: 1px; background: currentColor; opacity: 0.3;
            }
            <?php echo $w; ?>.bit4a-row-card {
                background: #fff; margin-bottom: 14px; overflow: hidden; border-radius: 12px;
                box-shadow: 0 1px 3px rgba(0,90,66,0.08);
            }
            <?php echo $w; ?>.bit4a-row-header {
                background: var(--e-global-color-96a86ed, #003A26);
                color: var(--e-global-color-e03d05f, #F6EFEA);
                padding: 10px 16px; font-weight: 700; font-size: 0.85rem;
                font-style: italic; letter-spacing: 0.04em;
            }
            <?php echo $w; ?>.bit4a-row-header .bit4a-badge {
                font-size: 0.6rem; background: rgba(255,255,255,0.15); padding: 2px 8px;
                border-radius: 999px; margin-left: 8px; text-transform: uppercase;
                letter-spacing: 0.06em; font-style: normal; font-weight: 600; vertical-align: middle;
            }
            <?php echo $w; ?>.bit4a-sub-tabs-wrap {
                display: flex; align-items: center; gap: 4px; padding: 8px 8px;
                background: #faf6f1; border-bottom: 1px solid #ece4dd;
            }
            <?php echo $w; ?>.bit4a-sub-nav-btn {
                background: #fff; border: 1px solid #ece4dd;
                color: var(--e-global-color-text, #005A42);
                width: 28px; height: 28px; border-radius: 50%;
                display: flex; align-items: center; justify-content: center;
                cursor: pointer; flex-shrink: 0; font-family: inherit; font-size: 14px;
                line-height: 1; padding: 0;
            }
            <?php echo $w; ?>.bit4a-sub-nav-btn:hover {
                background: var(--e-global-color-text, #005A42);
                color: var(--e-global-color-e03d05f, #F6EFEA);
                border-color: var(--e-global-color-text, #005A42);
            }
            <?php echo $w; ?>.bit4a-sub-nav-btn:disabled { opacity: 0.3; cursor: not-allowed; }
            <?php echo $w; ?>.bit4a-sub-tabs {
                display: flex; gap: 6px; overflow-x: auto; flex: 1; min-width: 0;
                padding: 2px 0; align-items: stretch;
            }
            <?php echo $w; ?>.bit4a-sub-tabs::-webkit-scrollbar { display: none; }
            <?php echo $w; ?>.bit4a-sub-tab {
                font-size: 0.7rem; padding: 6px 12px; border-radius: 16px;
                background: #fff; color: var(--e-global-color-text, #005A42);
                border: 1px solid #ece4dd; cursor: pointer; font-family: inherit;
                line-height: 1.15; text-align: center; flex-shrink: 0; max-width: 200px;
                white-space: normal; display: flex; align-items: center; justify-content: center;
            }
            <?php echo $w; ?>.bit4a-sub-tab.is-active {
                background: var(--e-global-color-text, #005A42);
                color: var(--e-global-color-e03d05f, #F6EFEA);
                border-color: var(--e-global-color-text, #005A42); font-weight: 700;
            }
            <?php echo $w; ?>.bit4a-amazonia-tabs {
                display: grid; grid-template-columns: repeat(4, 1fr);
                background: #fff; border-bottom: 2px solid var(--e-global-color-e03d05f, #F6EFEA);
            }
            <?php echo $w; ?>.bit4a-amazonia-tab {
                padding: 10px 4px; text-align: center; border: 0;
                border-bottom: 3px solid transparent; cursor: pointer;
                font-size: 0.6rem; color: var(--e-global-color-text, #005A42);
                opacity: 0.6; background: #fff; font-family: inherit; line-height: 1.15;
            }
            <?php echo $w; ?>.bit4a-amazonia-tab svg { width: 26px; height: 26px; display: block; margin: 0 auto 4px; fill: currentColor; }
            <?php echo $w; ?>.bit4a-amazonia-tab.is-active {
                opacity: 1; border-bottom-color: var(--e-global-color-text, #005A42); font-weight: 700;
            }
            <?php echo $w; ?>.bit4a-row-body {
                padding: 12px 16px; font-size: 0.83rem; line-height: 1.4;
                color: var(--e-global-color-text, #005A42);
            }
            <?php echo $w; ?>.bit4a-row-body ul { margin: 0; padding-left: 18px; }
            <?php echo $w; ?>.bit4a-row-body li { margin-bottom: 4px; }
            <?php echo $w; ?>.bit4a-fullwidth-card {
                background: var(--e-global-color-96a86ed, #003A26);
                color: var(--e-global-color-e03d05f, #F6EFEA);
                margin-bottom: 12px; overflow: hidden; border-radius: 12px;
            }
            <?php echo $w; ?>.bit4a-fullwidth-card .bit4a-fw-header {
                padding: 12px 16px; font-weight: 700; font-style: italic;
                letter-spacing: 0.04em; font-size: 0.9rem;
            }
            <?php echo $w; ?>.bit4a-fullwidth-card .bit4a-fw-amazonias-label {
                font-size: 0.6rem; text-transform: uppercase; letter-spacing: 0.1em;
                opacity: 0.65; padding: 10px 16px 0; font-weight: 600;
            }
            <?php echo $w; ?>.bit4a-fullwidth-card .bit4a-fw-amazonias {
                display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px;
                padding: 10px 16px 4px; background: rgba(255,255,255,0.06);
                border-bottom: 1px solid rgba(255,255,255,0.12);
            }
            <?php echo $w; ?>.bit4a-fullwidth-card .bit4a-fw-amz {
                display: flex; flex-direction: column; align-items: center; gap: 4px;
                font-size: 0.55rem; text-align: center; line-height: 1.15; opacity: 0.85;
            }
            <?php echo $w; ?>.bit4a-fullwidth-card .bit4a-fw-amz svg { width: 26px; height: 26px; fill: currentColor; }
            <?php echo $w; ?>.bit4a-fullwidth-card ul { margin: 0; padding: 8px 16px 14px 32px; font-size: 0.85rem; line-height: 1.5; }
            <?php echo $w; ?>.bit4a-fullwidth-card li { margin-bottom: 6px; }
        }

        /* Toggle button + collapsible wrapper (matches old CONHECA/FECHAR behavior) */
        <?php echo $w; ?>.bit4a-toggle-wrap {
            text-align: center;
            margin: 0 0 1rem 0;
        }
        <?php echo $w; ?>.bit4a-toggle-btn {
            background-color: var(--e-global-color-bfeecce, #F6EFEA);
            color: var(--e-global-color-195c11b, #005A42);
            border: 2px solid var(--e-global-color-e978a34, #005A42);
            padding: 12px 28px;
            font-family: inherit;
            font-weight: 700;
            font-size: 0.9rem;
            letter-spacing: 0.02em;
            border-radius: 4px;
            cursor: pointer;
            transition: background-color 0.2s, color 0.2s;
        }
        <?php echo $w; ?>.bit4a-toggle-btn:hover,
        <?php echo $w; ?>.bit4a-toggle-btn:focus-visible {
            background-color: var(--e-global-color-1a29b29, #005A42);
            color: var(--e-global-color-d06d81a, #FFFFFF);
            outline: 0;
        }
        <?php echo $w; ?>.bit4a-collapsible {
            max-height: 0;
            overflow: hidden;
            transition: max-height 600ms linear;
        }
        <?php echo $w; ?>.bit4a-collapsible.is-open {
            max-height: 12000px;
        }
        /* After expand animation finishes, drop overflow so sticky-header works */
        <?php echo $w; ?>.bit4a-collapsible.is-open.is-animated {
            overflow: visible;
        }
        </style>
        <?php
        // === RENDER ===
        $settings = $this->get_settings_for_display();
        $show_toggle = ( ( $settings['show_toggle'] ?? 'yes' ) === 'yes' );
        $btn_expand   = $lang === 'en'
            ? ( $settings['btn_text_expand_en']   ?? 'Learn about the four Amazonias' )
            : ( $settings['btn_text_expand_pt']   ?? 'Conheça as quatro Amazônias' );
        $btn_collapse = $lang === 'en'
            ? ( $settings['btn_text_collapse_en'] ?? 'Close the four Amazonias framework' )
            : ( $settings['btn_text_collapse_pt'] ?? 'Fechar framework das quatro Amazônias' );

        if ( $show_toggle ) :
        ?>
        <div class="bit4a-toggle-wrap">
            <button class="bit4a-toggle-btn" type="button"
                    data-bit4a-toggle="<?php echo esc_attr( $widget_id ); ?>"
                    data-text-expand="<?php echo esc_attr( $btn_expand ); ?>"
                    data-text-collapse="<?php echo esc_attr( $btn_collapse ); ?>"
                    aria-expanded="false"
                    aria-controls="bit4a-content-<?php echo esc_attr( $widget_id ); ?>">
                <?php echo esc_html( $btn_expand ); ?>
            </button>
        </div>
        <?php endif; ?>

        <div class="bit4a-collapsible<?php echo $show_toggle ? '' : ' is-open'; ?>"
             id="bit4a-content-<?php echo esc_attr( $widget_id ); ?>">
        <div class="bit4a-wrap bit4a-svg-fix">

        <!-- DESKTOP -->
        <div class="bit4a-desktop">
            <!-- Sticky scope: header lives ONLY within this wrapper, so it
                 stops sticking when user scrolls past the table rows. -->
            <div class="bit4a-table">
                <div class="bit4a-col-headers">
                    <div class="bit4a-col-spacer"></div>
                    <?php foreach ( $cols as $c ) : ?>
                        <div class="bit4a-col-header"><?php echo $svg( $c['id'] ); ?><div><?php echo esc_html( $c['label'] ); ?></div></div>
                    <?php endforeach; ?>
                </div>

                <div class="bit4a-block-label"><?php echo esc_html( $C['label_premissa'] ); ?></div>
                <div class="bit4a-col-grid">
                    <div class="bit4a-col-rowlabel"><?php echo esc_html( $C['label_premissa'] ); ?></div>
                    <?php foreach ( $cols as $ci => $c ) : ?>
                        <div class="bit4a-col-cell"><?php echo $list_or_div( $C['premissa'][ $ci ] ); ?></div>
                    <?php endforeach; ?>
                </div>

                <div class="bit4a-block-label"><?php echo esc_html( $C['label_especificas'] ); ?></div>
                <?php foreach ( $C['especificas'] as $f ) : ?>
                    <div class="bit4a-col-grid">
                        <div class="bit4a-col-rowlabel"><?php echo esc_html( $f['title'] ); ?></div>
                        <?php foreach ( $cols as $ci => $c ) : ?>
                            <div class="bit4a-col-cell"><?php echo $list_items( $f['cells'][ $ci ] ); ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div><!-- /.bit4a-table (sticky scope ends here) -->

            <div class="bit4a-section-label"><?php echo esc_html( $C['label_para_todas'] ); ?></div>
            <?php foreach ( [ [ 'label_estruturantes', 'estruturantes' ], [ 'label_transversais', 'transversais' ] ] as $pair ) :
                $lbl  = $C[ $pair[0] ];
                $items = $C[ $pair[1] ];
                ?>
                <div class="bit4a-fullrow">
                    <div class="bit4a-fullrow-header"><?php echo esc_html( $lbl ); ?></div>
                    <div class="bit4a-fullrow-amazonias">
                        <?php foreach ( $cols as $c ) : ?>
                            <div class="bit4a-amz"><?php echo $svg( $c['id'] ); ?><div><?php echo esc_html( $c['label'] ); ?></div></div>
                        <?php endforeach; ?>
                    </div>
                    <?php echo $list_items( $items ); ?>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- MOBILE -->
        <div class="bit4a-mobile" data-bit4a-mobile="<?php echo esc_attr( $widget_id ); ?>">
            <div class="bit4a-section-label"><?php echo esc_html( $C['label_premissa'] ); ?></div>
            <div class="bit4a-row-card">
                <div class="bit4a-row-header"><?php echo esc_html( $C['label_premissa'] ); ?></div>
                <div class="bit4a-amazonia-tabs" data-bit4a-tabs="premissa">
                    <?php foreach ( $cols as $ci => $c ) : ?>
                        <button class="bit4a-amazonia-tab<?php echo $ci === 0 ? ' is-active' : ''; ?>" type="button" data-ci="<?php echo (int) $ci; ?>">
                            <?php echo $svg( $c['id'] ); ?>
                            <div><?php echo esc_html( $c['label'] ); ?></div>
                        </button>
                    <?php endforeach; ?>
                </div>
                <div class="bit4a-row-body" data-bit4a-body="premissa">
                    <?php echo $list_items( $C['premissa'][0] ); ?>
                </div>
                <script type="application/json" data-bit4a-data="premissa"><?php echo wp_json_encode( $C['premissa'] ); ?></script>
            </div>

            <div class="bit4a-section-label"><?php echo esc_html( $C['label_especificas'] ); ?></div>
            <div class="bit4a-row-card" data-bit4a-esp-card>
                <div class="bit4a-row-header">
                    <?php echo esc_html( $C['label_especificas'] ); ?>
                </div>
                <div class="bit4a-sub-tabs-wrap">
                    <button class="bit4a-sub-nav-btn" type="button" data-bit4a-esp-prev aria-label="Anterior" disabled>&lsaquo;</button>
                    <div class="bit4a-sub-tabs" data-bit4a-sub-tabs>
                        <?php foreach ( $C['especificas'] as $si => $f ) : ?>
                            <button class="bit4a-sub-tab<?php echo $si === 0 ? ' is-active' : ''; ?>" type="button" data-si="<?php echo (int) $si; ?>"><?php echo esc_html( $f['title'] ); ?></button>
                        <?php endforeach; ?>
                    </div>
                    <button class="bit4a-sub-nav-btn" type="button" data-bit4a-esp-next aria-label="Proximo">&rsaquo;</button>
                </div>
                <div class="bit4a-amazonia-tabs" data-bit4a-esp-tabs>
                    <?php foreach ( $cols as $ci => $c ) : ?>
                        <button class="bit4a-amazonia-tab<?php echo $ci === 0 ? ' is-active' : ''; ?>" type="button" data-ci="<?php echo (int) $ci; ?>">
                            <?php echo $svg( $c['id'] ); ?>
                            <div><?php echo esc_html( $c['label'] ); ?></div>
                        </button>
                    <?php endforeach; ?>
                </div>
                <div class="bit4a-row-body" data-bit4a-esp-body>
                    <?php echo $list_items( $C['especificas'][0]['cells'][0] ); ?>
                </div>
                <script type="application/json" data-bit4a-esp-data><?php echo wp_json_encode( array_map( function( $f ) { return $f['cells']; }, $C['especificas'] ) ); ?></script>
            </div>

            <div class="bit4a-section-label"><?php echo esc_html( $C['label_para_todas'] ); ?></div>
            <?php foreach ( [ [ 'label_estruturantes', 'estruturantes' ], [ 'label_transversais', 'transversais' ] ] as $pair ) :
                $lbl  = $C[ $pair[0] ];
                $items = $C[ $pair[1] ];
                ?>
                <div class="bit4a-fullwidth-card">
                    <div class="bit4a-fw-header"><?php echo esc_html( $lbl ); ?></div>
                    <div class="bit4a-fw-amazonias-label"><?php echo esc_html( $C['atua_em_todas'] ); ?></div>
                    <div class="bit4a-fw-amazonias">
                        <?php foreach ( $cols as $c ) : ?>
                            <div class="bit4a-fw-amz"><?php echo $svg( $c['id'] ); ?><div><?php echo esc_html( $c['label'] ); ?></div></div>
                        <?php endforeach; ?>
                    </div>
                    <?php echo $list_items( $items ); ?>
                </div>
            <?php endforeach; ?>
        </div>

        </div><!-- .bit4a-wrap -->
        <?php if ( $show_toggle ) : ?>
        <div class="bit4a-toggle-wrap bit4a-toggle-bottom">
            <button class="bit4a-toggle-btn" type="button"
                    data-bit4a-toggle="<?php echo esc_attr( $widget_id ); ?>"
                    data-text-expand="<?php echo esc_attr( $btn_expand ); ?>"
                    data-text-collapse="<?php echo esc_attr( $btn_collapse ); ?>"
                    aria-expanded="true"
                    aria-controls="bit4a-content-<?php echo esc_attr( $widget_id ); ?>">
                <?php echo esc_html( $btn_collapse ); ?>
            </button>
        </div>
        <?php endif; ?>
        </div><!-- .bit4a-collapsible -->

        <script>
        (function(){
            var root = document.querySelector('.elementor-element-<?php echo esc_js( $widget_id ); ?>');
            if (!root || root.__bit4aInit) return;
            root.__bit4aInit = true;

            // Toggle show/hide (multiple buttons: top + bottom)
            var toggleBtns = root.querySelectorAll('[data-bit4a-toggle]');
            var collapsible = root.querySelector('.bit4a-collapsible');
            if (toggleBtns.length && collapsible) {
                function setState(isOpen) {
                    if (isOpen) {
                        collapsible.classList.add('is-open');
                        // remove is-animated DURING animation so overflow stays hidden,
                        // then add it AFTER transitionend so sticky-header works
                        collapsible.classList.remove('is-animated');
                    } else {
                        collapsible.classList.remove('is-open');
                        collapsible.classList.remove('is-animated');
                    }
                    toggleBtns.forEach(function(b) {
                        b.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                        b.textContent = isOpen
                            ? b.getAttribute('data-text-collapse')
                            : b.getAttribute('data-text-expand');
                    });
                    if (!isOpen) {
                        // scroll back to top toggle button
                        var topBtn = root.querySelector('[data-bit4a-toggle]:not(.bit4a-toggle-bottom [data-bit4a-toggle])');
                        if (topBtn) topBtn.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                }
                collapsible.addEventListener('transitionend', function(e) {
                    if (e.propertyName === 'max-height' && collapsible.classList.contains('is-open')) {
                        collapsible.classList.add('is-animated');
                    }
                });
                toggleBtns.forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        var willOpen = !collapsible.classList.contains('is-open');
                        setState(willOpen);
                    });
                });
            }

            // Premissa amazonia tabs
            var premissaTabs = root.querySelector('[data-bit4a-tabs="premissa"]');
            var premissaBody = root.querySelector('[data-bit4a-body="premissa"]');
            var premissaData = root.querySelector('[data-bit4a-data="premissa"]');
            if (premissaTabs && premissaBody && premissaData) {
                var pData;
                try { pData = JSON.parse(premissaData.textContent); } catch(e) { pData = []; }
                premissaTabs.addEventListener('click', function(e) {
                    var btn = e.target.closest('.bit4a-amazonia-tab');
                    if (!btn) return;
                    var ci = +btn.dataset.ci;
                    premissaTabs.querySelectorAll('.bit4a-amazonia-tab').forEach(function(b){ b.classList.remove('is-active'); });
                    btn.classList.add('is-active');
                    var items = pData[ci] || [];
                    premissaBody.innerHTML = '<ul>' + items.map(function(it){
                        var d = document.createElement('div'); d.textContent = it; return '<li>' + d.innerHTML + '</li>';
                    }).join('') + '</ul>';
                });
            }

            // Especificas: sub-tabs (1-6) + inner amazonia tabs + prev/next
            var espCard = root.querySelector('[data-bit4a-esp-card]');
            if (espCard) {
                var subTabs = espCard.querySelector('[data-bit4a-sub-tabs]');
                var espTabs = espCard.querySelector('[data-bit4a-esp-tabs]');
                var espBody = espCard.querySelector('[data-bit4a-esp-body]');
                var espPrev = espCard.querySelector('[data-bit4a-esp-prev]');
                var espNext = espCard.querySelector('[data-bit4a-esp-next]');
                var espDataEl = espCard.querySelector('[data-bit4a-esp-data]');
                var espData;
                try { espData = JSON.parse(espDataEl.textContent); } catch(e) { espData = []; }
                var curSub = 0, curAmz = 0;

                function renderBody() {
                    var items = (espData[curSub] && espData[curSub][curAmz]) || [];
                    espBody.innerHTML = '<ul>' + items.map(function(it){
                        var d = document.createElement('div'); d.textContent = it; return '<li>' + d.innerHTML + '</li>';
                    }).join('') + '</ul>';
                }
                function setSub(i) {
                    curSub = i;
                    subTabs.querySelectorAll('.bit4a-sub-tab').forEach(function(b){ b.classList.remove('is-active'); });
                    var t = subTabs.querySelectorAll('.bit4a-sub-tab')[i];
                    if (t) { t.classList.add('is-active'); t.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' }); }
                    if (espPrev) espPrev.disabled = (curSub === 0);
                    if (espNext) espNext.disabled = (curSub === espData.length - 1);
                    renderBody();
                }
                subTabs.addEventListener('click', function(e) {
                    var btn = e.target.closest('.bit4a-sub-tab');
                    if (!btn) return;
                    setSub(+btn.dataset.si);
                });
                espTabs.addEventListener('click', function(e) {
                    var btn = e.target.closest('.bit4a-amazonia-tab');
                    if (!btn) return;
                    curAmz = +btn.dataset.ci;
                    espTabs.querySelectorAll('.bit4a-amazonia-tab').forEach(function(b){ b.classList.remove('is-active'); });
                    btn.classList.add('is-active');
                    renderBody();
                });
                if (espPrev) espPrev.addEventListener('click', function(){ if (curSub > 0) setSub(curSub - 1); });
                if (espNext) espNext.addEventListener('click', function(){ if (curSub < espData.length - 1) setSub(curSub + 1); });
            }
        })();
        </script>
        <?php
    }
}
