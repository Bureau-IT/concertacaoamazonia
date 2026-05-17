<?php
/**
 * Plugin Name:  BIT Elementor Espiral Widget
 * Description:  Widget "BIT Espiral do Conhecimento" — carrega SVG inline com
 *               controles visuais e persistência via REST API. Suporta qualquer subsite
 *               da rede. Complementa o bit-elementor-svg-widget para a espiral 2026.
 * Version:      2.1.0
 * Author:       Bureau IT
 * Network:      true
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ─── Ícone customizado no painel do Elementor ─────────────────────────────
add_action( 'elementor/editor/after_enqueue_styles', function () {
    $stroke = '%23444';
    $path   = 'M20 12 A8 8 0 0 0 4 12 A6 6 0 0 1 16 12 A4 4 0 0 0 8 12 A2 2 0 0 1 12 12';
    $svg    = 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22'
            . ' viewBox=%220 0 24 24%22 fill=%22none%22'
            . ' stroke=%22' . $stroke . '%22'
            . ' stroke-width=%221.8%22 stroke-linecap=%22round%22%3E'
            . '%3Cpath d=%22' . rawurlencode( $path ) . '%22/%3E'
            . '%3C/svg%3E';
    echo '<style>
.eicon-bit-espiral::before {
    content: "";
    display: block;
    width: 1em;
    height: 1em;
    background: url("' . esc_attr( $svg ) . '") center/contain no-repeat;
}
</style>' . "\n";
} );

// ─── JS do botão "Testar animação" no editor do Elementor ────────────────
// Não usar <script> dentro de RAW_HTML do controle: o Elementor injeta esse
// HTML via Underscore template `{{{ data.raw }}}` (innerHTML), e <script>
// inserido por innerHTML não é executado pelo browser.
//
// Estratégia: imprimir o JS tanto em `elementor/editor/footer` quanto em
// `admin_print_footer_scripts` (com guarda global), e usar MutationObserver
// para anexar `onclick` direto no <button> assim que aparecer no DOM —
// sobrevive à re-renderização do painel pelo Elementor e não depende de
// event delegation (que pode ser interceptada por handlers internos).
function bit_espiral_print_replay_js() {
    static $printed = false;
    if ( $printed ) return;
    $printed = true;
    ?>
    <script id="bit-espiral-replay">
    /* @bit-espiral-replay-marker v5 (revert: animação só em <a>, clone-replace) */
    (function(){
      if (window.__bitEspiralReplayInit) return;
      window.__bitEspiralReplayInit = true;
      console.log('%c[bit-espiral] replay JS v5 inicializado', 'color:#3c6fff;font-weight:bold');

      function getPreviewDoc(){
        try {
          if (!window.elementor) return null;
          if (typeof elementor.getPreviewView === 'function') {
            var v = elementor.getPreviewView();
            if (v && v.$el && v.$el[0]) return v.$el[0].ownerDocument;
          }
          if (elementor.$preview && elementor.$preview[0]) {
            return elementor.$preview[0].contentDocument;
          }
          var iframe = document.querySelector('#elementor-preview-iframe');
          if (iframe) return iframe.contentDocument;
        } catch(e){ console.warn('[bit-espiral] getPreviewDoc erro', e); }
        return null;
      }

      function replay(){
        var doc = getPreviewDoc();
        if (!doc) { console.warn('[bit-espiral] preview iframe não encontrado'); return; }
        var svgs = doc.querySelectorAll('svg.SVGSpiral2026');
        console.log('[bit-espiral] replay: SVGs encontradas =', svgs.length);
        if (!svgs.length) { console.warn('[bit-espiral] SVGSpiral2026 não encontrada no preview'); return; }
        svgs.forEach(function(svg){
          // Clonar e substituir é a forma determinística de re-disparar
          // animações CSS num SVG dentro de iframe — o browser trata o nó
          // novo como elemento totalmente novo, sem estado de animação anterior.
          var parent = svg.parentNode;
          if (!parent) { console.warn('[bit-espiral] svg sem parent'); return; }
          var clone = svg.cloneNode(true);
          clone.classList.remove('Spiral26Animate');
          parent.replaceChild(clone, svg);
          void clone.getBoundingClientRect();
          requestAnimationFrame(function(){
            clone.classList.add('Spiral26Animate');
            console.log('[bit-espiral] replay: animação reiniciada');
          });
        });
      }

      // expõe globalmente para uso via onclick="..." de fallback
      window.bitEspiralReplay = function(ev){
        if (ev && ev.preventDefault) ev.preventDefault();
        replay();
        return false;
      };

      // Anexa onclick direto no botão assim que aparecer no DOM.
      function bindButtons(root){
        var btns = (root || document).querySelectorAll('.bit-espiral-replay-btn:not([data-bit-bound])');
        btns.forEach(function(b){
          b.setAttribute('data-bit-bound', '1');
          b.onclick = window.bitEspiralReplay;
          console.log('[bit-espiral] botão de replay encontrado e vinculado', b);
        });
      }

      // Bind imediato
      bindButtons(document);

      // Bind contínuo via MutationObserver (Elementor recria o controle quando
      // o usuário troca de seção / widget). Roda em todo o body, mas filtra por
      // existência da classe — barato.
      try {
        var mo = new MutationObserver(function(muts){
          for (var i=0; i<muts.length; i++) {
            var m = muts[i];
            if (m.addedNodes && m.addedNodes.length) {
              for (var j=0; j<m.addedNodes.length; j++) {
                var n = m.addedNodes[j];
                if (n.nodeType === 1) bindButtons(n);
              }
            }
          }
        });
        mo.observe(document.body || document.documentElement, { childList: true, subtree: true });
      } catch(e) { console.warn('[bit-espiral] MutationObserver falhou', e); }

      // Event delegation como segundo fallback (capture phase)
      document.addEventListener('click', function(ev){
        var t = ev.target;
        if (!t) return;
        var btn = (t.classList && t.classList.contains('bit-espiral-replay-btn'))
                ? t
                : (t.closest ? t.closest('.bit-espiral-replay-btn') : null);
        if (!btn) return;
        ev.preventDefault();
        ev.stopPropagation();
        replay();
      }, true);
    })();
    </script>
    <?php
}
add_action( 'elementor/editor/footer', 'bit_espiral_print_replay_js' );
add_action( 'admin_print_footer_scripts', 'bit_espiral_print_replay_js' );

// ─── REST API: GET/POST da config da espiral ───────────────────────────────
add_action( 'rest_api_init', function () {
    register_rest_route( 'bit-espiral/v1', '/config', [
        [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => function () {
                return rest_ensure_response( get_option( 'bit_espiral_config', [] ) );
            },
            'permission_callback' => '__return_true',
        ],
        [
            'methods'             => \WP_REST_Server::EDITABLE,
            'callback'            => function ( \WP_REST_Request $request ) {
                $body = $request->get_json_params();
                if ( ! is_array( $body ) ) {
                    return new \WP_Error( 'invalid_body', 'Corpo JSON inválido.', [ 'status' => 400 ] );
                }
                update_option( 'bit_espiral_config', $body );
                return rest_ensure_response( $body );
            },
            'permission_callback' => function () {
                return current_user_can( 'edit_posts' );
            },
        ],
    ] );
} );

// ─── Elementor widget ─────────────────────────────────────────────────────
add_action( 'elementor/widgets/register', function ( $widgets_manager ) {
    if ( ! class_exists( '\Elementor\Widget_Base' ) ) return;

    class Bureau_Espiral_Widget extends \Elementor\Widget_Base {

        public function get_name()        { return 'bureau_espiral'; }
        public function get_title()       { return 'BIT Espiral do Conhecimento'; }
        public function get_icon()        { return 'eicon-bit-espiral'; }
        public function get_categories()  { return [ 'general' ]; }
        public function get_keywords()    { return [ 'espiral', 'svg', 'bureau', 'knowledge', 'spiral', 'bit' ]; }

        /**
         * CSS vars padrão — espelham os defaults internos do SVG.
         * Usado para validar quais chaves de cssVars são permitidas.
         */
        private function get_default_css_vars() {
            return [
                '--spiral2026-backgroundcolor'             => 'rgba(10,38,102,0.6)',
                '--spiral2026-backgroundcolor-hover'       => '#0E4A5C',
                '--spiral2026-mainline-color'              => 'rgba(189,248,57,0.878)',
                '--spiral2026-middle-center-color'         => 'rgba(214,243,149,0.902)',
                '--spiral2026-middle-edge-color'           => 'rgba(100,212,233,0.431)',
                '--spiral2026-flood-color'                 => '#060f0a',
                '--spiral2026-animation-delay'             => '160ms',
                '--spiral2026-animation-duration'          => '2s',
                '--spiral2026-eightrays-color'             => 'rgba(189,248,57,0.4)',
                '--spiral2026-eightrays-strokewidth'       => '0.6px',
                '--spiral2026-foreignobject-color'         => '#ffffff',
                '--spiral2026-foreignobject-width'         => '130px',
                '--spiral2026-foreignobject-height'        => '88px',
                '--spiral2026-foreignobject-fontsize'      => '15px',
                '--spiral2026-foreignobject-fontfamily'    => '"Just Sans", sans-serif',
                '--spiral2026-foreignobject-fontweight'    => '500',
                '--spiral2026-foreignobject-lineheight'    => '1.2',
                '--spiral2026-foreignobject-letterspacing' => '0',
            ];
        }

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

        /**
         * Termos filhos do termo "Espiral" (term_id 1148) na taxonomia "eixos".
         * Retorna [ term_id => label_sem_prefixo ] para popular o dropdown do Repeater.
         */
        private function get_espiral_terms_options(): array {
            $terms = get_terms( [
                'taxonomy'   => 'eixos',
                'parent'     => 1148,
                'hide_empty' => false,
                'orderby'    => 'name',
                'order'      => 'ASC',
            ] );
            if ( is_wp_error( $terms ) || empty( $terms ) ) {
                return [ '' => '— Sem eixos —' ];
            }
            $opts = [ '' => '— Selecione um eixo —' ];
            foreach ( $terms as $t ) {
                $label = preg_replace( '/^Espiral:\s*/u', '', $t->name );
                $opts[ (string) $t->term_id ] = $label;
            }
            return $opts;
        }

        /**
         * Default do Repeater "axes_repeater": 21 entradas com aria-label e term_id
         * que correspondem ao mapeamento atual do SVG. Permite que o widget renderize
         * exatamente como antes mesmo se o desenvolvedor não tocar no Repeater.
         */
        private function get_default_axes_repeater(): array {
            $defaults = [
                [ 'Governança',                                                'Espiral: Governança',                                              172  ],
                [ 'Instrumentos de financiamento',                             'Espiral: Instrumentos de financiamento',                            174  ],
                [ 'Planos e políticas públicas',                               'Espiral: Planos e políticas públicas',                              175  ],
                [ 'Negócios',                                                  'Espiral: Negócios',                                                176  ],
                [ 'Sociedade civil',                                           'Espiral: Sociedade civil',                                          177  ],
                [ 'Ciência, tecnologia e inovação',                            'Espiral: Ciência, Tecnologia e Inovação',                           187  ],
                [ 'Cultura',                                                   'Espiral: Cultura',                                                  178  ],
                [ 'Mudança do uso do solo',                                    'Espiral: Mudança do Uso do Solo',                                   180  ],
                [ 'Ordenamento territorial e regularização fundiária',         'Espiral: Ordenamento Territorial e Regularização Fundiária',         2013 ],
                [ 'Infraestrutura',                                            'Espiral: Infraestrutura',                                           182  ],
                [ 'Comunicação e mídia',                                       'Espiral: Comunicação e mídia',                                      183  ],
                [ 'Mudanças Climáticas',                                       'Espiral: Mudanças Climáticas',                                      184  ],
                [ 'Agenda Internacional',                                      'Espiral: Agenda Internacional',                                     185  ],
                [ 'Educação',                                                  'Espiral: Educação',                                                 1819 ],
                [ 'Bioeconomia',                                               'Espiral: Bioeconomia',                                              604  ],
                [ 'Segurança',                                                 'Espiral: Segurança',                                                598  ],
                [ 'Saúde',                                                     'Espiral: Saúde',                                                    2479 ],
                [ 'Cidades',                                                   'Espiral: Cidades',                                                  2360 ],
                [ 'Biodiversidade',                                            'Espiral: Biodiversidade',                                           2463 ],
                [ 'Povos indígenas, quilombolas e comunidades tradicionais',   'Espiral: PIQCTs',                                                   2401 ],
                [ 'Direitos humanos',                                          'Espiral: Direitos humanos',                                         2464 ],
            ];
            $rows = [];
            foreach ( $defaults as [ $label, $_term_name, $term_id ] ) {
                $rows[] = [
                    'segment_label'   => $label,
                    'segment_term_id' => (string) $term_id,
                ];
            }
            return $rows;
        }

        /**
         * Default do Repeater "typo_repeater": 21 linhas com apenas o label
         * (mesmos aria-labels do mapeamento padrão). Todos os campos numéricos
         * iniciam vazios — sem override, mantém os valores globais.
         */
        private function get_default_typo_repeater(): array {
            $rows = [];
            foreach ( $this->get_default_axes_repeater() as $r ) {
                $rows[] = [ 'seg_label' => $r['segment_label'] ];
            }
            return $rows;
        }


        // (O JS do botão "Testar animação" é registrado via
        // `elementor/editor/footer` no topo deste arquivo — RAW_HTML não
        // executa <script> porque o Elementor injeta o markup via innerHTML.)

        /**
         * Aplica segLinks e segTypo do JSON config diretamente no markup SVG.
         *
         * segLinks: { "1": "/url/...", "2": "/url/...", ... }
         *   → atualiza href de cada <a id="Spiral26Text-N">
         *
         * segTypo: { "20": { "fs": "14px", "w": "135px", "ls": "-0.05em" }, ... }
         *   → atualiza/injeta CSS vars no style="" do <a id="Spiral26Text-N">
         *   → fs = --spiral2026-foreignobject-fontsize
         *   → w  = --spiral2026-foreignobject-width
         *   → ls = --spiral2026-foreignobject-letterspacing
         */
        private function apply_config_to_svg( string $svg, array $config ): string {
            // Mapear modificações por segmento: [ n => [ 'href' => ..., 'style_vars' => [...] ] ]
            $seg_mods = [];

            // segLinks ────────────────────────────────────────────────────
            if ( ! empty( $config['segLinks'] ) && is_array( $config['segLinks'] ) ) {
                foreach ( $config['segLinks'] as $n => $href ) {
                    $n    = (int) $n;
                    $href = trim( (string) $href );
                    if ( $n < 1 || $n > 50 || '' === $href || '#' === $href ) continue;
                    $seg_mods[ $n ]['href'] = $href;
                }
            }

            // segTypo ─────────────────────────────────────────────────────
            if ( ! empty( $config['segTypo'] ) && is_array( $config['segTypo'] ) ) {
                $prop_map = [
                    'fs' => '--spiral2026-foreignobject-fontsize',
                    'w'  => '--spiral2026-foreignobject-width',
                    'ls' => '--spiral2026-foreignobject-letterspacing',
                    'lh' => '--spiral2026-foreignobject-lineheight', // suporte futuro
                ];
                foreach ( $config['segTypo'] as $n => $vals ) {
                    $n = (int) $n;
                    if ( $n < 1 || $n > 50 || ! is_array( $vals ) ) continue;
                    foreach ( $prop_map as $key => $css_var ) {
                        if ( isset( $vals[ $key ] ) && '' !== trim( (string) $vals[ $key ] ) ) {
                            $seg_mods[ $n ]['style_vars'][ $css_var ] = trim( (string) $vals[ $key ] );
                        }
                    }
                }
            }

            if ( empty( $seg_mods ) ) return $svg;

            // Aplicar modificações por segmento via regex callback
            foreach ( $seg_mods as $n => $mods ) {
                $svg = preg_replace_callback(
                    '/<a\b([^>]*\bid="Spiral26Text-' . (int) $n . '"[^>]*)>/s',
                    static function ( $matches ) use ( $mods ) {
                        $attrs = $matches[1];

                        // ── href ─────────────────────────────────────────
                        if ( ! empty( $mods['href'] ) ) {
                            $escaped = esc_attr( $mods['href'] );
                            if ( preg_match( '/\bhref="/', $attrs ) ) {
                                $attrs = preg_replace( '/\bhref="[^"]*"/', 'href="' . $escaped . '"', $attrs );
                            } else {
                                $attrs = ' href="' . $escaped . '"' . $attrs;
                            }
                        }

                        // ── style CSS vars ────────────────────────────────
                        if ( ! empty( $mods['style_vars'] ) ) {
                            // Parsear declarações existentes no style=""
                            $existing = '';
                            if ( preg_match( '/\bstyle="([^"]*)"/', $attrs, $sm ) ) {
                                $existing = $sm[1];
                            }
                            // Montar mapa var → valor a partir do estilo atual
                            $style_map = [];
                            foreach ( explode( ';', $existing ) as $decl ) {
                                $decl = trim( $decl );
                                if ( '' === $decl ) continue;
                                $colon = strpos( $decl, ':' );
                                if ( false === $colon ) continue;
                                $key               = trim( substr( $decl, 0, $colon ) );
                                $val               = trim( substr( $decl, $colon + 1 ) );
                                $style_map[ $key ] = $val;
                            }
                            // Sobrescrever / adicionar vars do JSON
                            foreach ( $mods['style_vars'] as $var => $val ) {
                                $style_map[ $var ] = $val;
                            }
                            // Reconstruir string de estilo
                            $parts = [];
                            foreach ( $style_map as $k => $v ) {
                                $parts[] = $k . ': ' . $v;
                            }
                            $new_style = implode( '; ', $parts );
                            if ( preg_match( '/\bstyle="[^"]*"/', $attrs ) ) {
                                $attrs = preg_replace( '/\bstyle="[^"]*"/', 'style="' . esc_attr( $new_style ) . '"', $attrs );
                            } else {
                                $attrs .= ' style="' . esc_attr( $new_style ) . '"';
                            }
                        }

                        return '<a' . $attrs . '>';
                    },
                    $svg,
                    1
                );
            }

            return $svg;
        }

        protected function register_controls() {

            // ── Conteúdo ──────────────────────────────────────────────────
            $this->start_controls_section( 'content_section', [
                'label' => 'Espiral',
                'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
            ] );

            $this->add_control( 'plugin_info', [
                'type'            => \Elementor\Controls_Manager::RAW_HTML,
                'raw'             => '<small style="color:#888">BIT Espiral do Conhecimento v1.7.0<br>Bureau de Tecnologia</small>',
                'content_classes' => 'elementor-descriptor',
            ] );

            $this->add_control( 'svg_name', [
                'label'   => 'Arquivo SVG',
                'type'    => \Elementor\Controls_Manager::SELECT,
                'options' => $this->get_svg_options(),
                'default' => 'espiral-do-conhecimento',
            ] );

            $this->add_responsive_control( 'svg_width', [
                'label'      => 'Largura',
                'type'       => \Elementor\Controls_Manager::SLIDER,
                'size_units' => [ 'px', '%', 'vw' ],
                'range'      => [
                    'px' => [ 'min' => 100, 'max' => 1200, 'step' => 10 ],
                    '%'  => [ 'min' => 5,   'max' => 100,  'step' => 5  ],
                    'vw' => [ 'min' => 1,   'max' => 100,  'step' => 1  ],
                ],
                'default'    => [ 'unit' => '%', 'size' => 100 ],
                'selectors'  => [
                    // !important para vencer eventual segunda declaração CSS
                    // do Elementor com mesma specificity (root cause confirmada:
                    // Safari aplicava a 2ª regra width:100% sobre a 1ª width:700px,
                    // expandindo a SVG para largura total do container).
                    '{{WRAPPER}} .SVGSpiral2026' => 'width: {{SIZE}}{{UNIT}} !important; height: auto !important;',
                ],
            ] );

            $this->end_controls_section();

            // ── Eixos da Espiral (mapeamento dos 21 segmentos) ────────────
            $this->start_controls_section( 'axes_section', [
                'label' => 'Eixos da Espiral',
                'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
            ] );

            $this->add_control( 'axes_help', [
                'type'            => \Elementor\Controls_Manager::RAW_HTML,
                'raw'             => '<small style="color:#888;display:block;line-height:1.4">Cada linha do Repeater corresponde a um dos 21 segmentos da espiral (na ordem). Selecione o eixo (taxonomia <code>eixos</code>) — o link com <code>#estudos</code> é montado automaticamente.<br>Segmentos com <em>— Selecione um eixo —</em> mantêm o link estático do SVG.</small>',
                'content_classes' => 'elementor-descriptor',
            ] );

            $axes_repeater = new \Elementor\Repeater();

            $axes_repeater->add_control( 'segment_label', [
                'label'       => 'Segmento (referência)',
                'type'        => \Elementor\Controls_Manager::TEXT,
                'placeholder' => 'Governança',
                'description' => 'Apenas para identificar o segmento no painel.',
            ] );

            $axes_repeater->add_control( 'segment_term_id', [
                'label'   => 'Eixo (taxonomia)',
                'type'    => \Elementor\Controls_Manager::SELECT,
                'options' => $this->get_espiral_terms_options(),
                'default' => '',
            ] );

            $this->add_control( 'axes_repeater', [
                'label'        => 'Mapeamento dos 21 segmentos',
                'type'         => \Elementor\Controls_Manager::REPEATER,
                'fields'       => $axes_repeater->get_controls(),
                'default'      => $this->get_default_axes_repeater(),
                'title_field'  => '{{{ segment_label }}}',
                'prevent_empty'=> false,
            ] );

            $this->add_control( 'estudos_anchor_offset', [
                'label'       => 'Offset da âncora #estudos (px)',
                'type'        => \Elementor\Controls_Manager::SLIDER,
                'size_units'  => [ 'px' ],
                'range'       => [ 'px' => [ 'min' => 0, 'max' => 800, 'step' => 10 ] ],
                'default'     => [ 'unit' => 'px', 'size' => 420 ],
                'description' => 'Espaço acima do bloco de resultados quando a página rola até #estudos. Valores maiores mostram os filtros e o título acima dos cards. Aplicado via scroll-margin-top no #estudos.',
            ] );

            $this->end_controls_section();

            // ── Importar / Exportar JSON ──────────────────────────────────
            $this->start_controls_section( 'json_section', [
                'label' => 'Importar JSON',
                'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
            ] );

            $this->add_control( 'json_import_ui', [
                'type' => \Elementor\Controls_Manager::RAW_HTML,
                // Envia o JSON completo (cssVars + segTypo + segLinks) para a REST API.
                // A chave cssVars controla as CSS vars globais.
                // segTypo aplica overrides de tipografia por segmento (href e style vars).
                // segLinks atualiza o href de cada <a id="Spiral26Text-N">.
                'raw'  => '<script>
function bitEspiralApplyJson(){
  var ta=document.getElementById("bit-espiral-import-json");
  if(!ta)return;
  try{
    var cfg=JSON.parse(ta.value.trim());
    // Envia o objeto completo — o plugin usa cssVars, segTypo e segLinks
    var data=cfg;
    var root=(window.wpApiSettings&&window.wpApiSettings.root)||"/wp-json/";
    var nonce=(window.wpApiSettings&&window.wpApiSettings.nonce)||"";
    fetch(root+"bit-espiral/v1/config",{
      method:"POST",
      headers:{"Content-Type":"application/json","X-WP-Nonce":nonce},
      body:JSON.stringify(data)
    }).then(function(r){
      ta.style.borderColor=r.ok?"#4caf50":"#f44336";
      if(r.ok&&window.elementor&&window.elementor.reloadPreview)
        window.elementor.reloadPreview();
    }).catch(function(){ta.style.borderColor="#f44336";});
  }catch(e){
    ta.style.borderColor="#f44336";
    alert("JSON inválido: "+e.message);
  }
}
</script>
<small style="color:#888;display:block;margin-bottom:6px">
Cole o JSON exportado do <strong>espiral-2025-editor.html</strong> e clique em Aplicar.<br>
<span style="color:#aaa">Suporta: cssVars · segTypo (fs/w/ls) · segLinks (hrefs)</span>
</small>
<textarea id="bit-espiral-import-json" rows="6"
  placeholder="{&quot;cssVars&quot;:{...},&quot;segTypo&quot;:{...},&quot;segLinks&quot;:{...}}"
  style="width:100%;font-family:monospace;font-size:10px;border:1px solid #d5d8dc;padding:6px;resize:vertical;box-sizing:border-box;background:#f9f9f9;border-radius:3px;outline:none;"></textarea>
<button type="button" onclick="bitEspiralApplyJson()"
  style="width:100%;margin-top:6px;padding:8px;background:#3c6fff;color:#fff;border:none;border-radius:3px;cursor:pointer;font-size:12px;font-weight:600;letter-spacing:.3px;">
  Aplicar JSON
</button>',
            ] );

            $this->end_controls_section();

            // ── Cores Principais ─────────────────────────────────────────
            $this->start_controls_section( 'colors_section', [
                'label' => 'Cores Principais',
                'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
            ] );

            $spiral_colors = [
                'spiral_bg_color'       => [ 'Cor de fundo',           '--spiral2026-backgroundcolor' ],
                'spiral_bg_hover_color' => [ 'Cor de fundo (hover)',    '--spiral2026-backgroundcolor-hover' ],
                'spiral_line_color'     => [ 'Linha principal',         '--spiral2026-mainline-color' ],
                'spiral_center_color'   => [ 'Centro — cor central',    '--spiral2026-middle-center-color' ],
                'spiral_edge_color'     => [ 'Centro — cor borda',      '--spiral2026-middle-edge-color' ],
                'spiral_flood_color'    => [ 'Sombra (flood)',           '--spiral2026-flood-color' ],
                'spiral_rays_color'     => [ 'Cor dos 8 raios',         '--spiral2026-eightrays-color' ],
                'spiral_text_color'     => [ 'Cor do texto',            '--spiral2026-foreignobject-color' ],
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

            $this->end_controls_section();

            // ── 8 Raios ───────────────────────────────────────────────────
            $this->start_controls_section( 'rays_section', [
                'label' => '8 Raios',
                'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
            ] );

            $this->add_control( 'spiral_rays_strokewidth', [
                'label'      => 'Espessura',
                'type'       => \Elementor\Controls_Manager::SLIDER,
                'size_units' => [ 'px' ],
                'range'      => [ 'px' => [ 'min' => 0.1, 'max' => 10, 'step' => 0.1 ] ],
                'default'    => [ 'unit' => 'px', 'size' => 0.6 ],
                'selectors'  => [
                    '{{WRAPPER}} .SVGSpiral2026' => '--spiral2026-eightrays-strokewidth: {{SIZE}}{{UNIT}};',
                ],
            ] );

            $this->add_control( 'spiral_rays_linecap', [
                'label'     => 'Estilo da ponta',
                'type'      => \Elementor\Controls_Manager::SELECT,
                'options'   => [
                    'round'  => 'Round (arredondado)',
                    'square' => 'Square (quadrado)',
                    'butt'   => 'Butt (reto)',
                ],
                'default'   => 'round',
                'selectors' => [
                    '{{WRAPPER}} .spiral26EightRays line' => 'stroke-linecap: {{VALUE}};',
                ],
            ] );

            $this->add_control( 'spiral_rays_dasharray', [
                'label'     => 'Estilo da linha',
                'type'      => \Elementor\Controls_Manager::SELECT,
                'options'   => [
                    'none' => 'Sólida',
                    '4 4'  => 'Tracejada',
                    '2 8'  => 'Pontilhada',
                    '8 4'  => 'Traço longo',
                ],
                'default'   => 'none',
                'selectors' => [
                    '{{WRAPPER}} .spiral26EightRays line' => 'stroke-dasharray: {{VALUE}};',
                ],
            ] );

            $this->end_controls_section();

            // ── Tipografia ────────────────────────────────────────────────
            $this->start_controls_section( 'typo_section', [
                'label' => 'Tipografia',
                'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
            ] );

            $this->add_control( 'spiral_font_family', [
                'label'     => 'Família da fonte',
                'type'      => \Elementor\Controls_Manager::FONT,
                'default'   => 'Just Sans',
                'selectors' => [
                    '{{WRAPPER}} .SVGSpiral2026' => "--spiral2026-foreignobject-fontfamily: '{{VALUE}}', sans-serif;",
                ],
            ] );

            $this->add_control( 'spiral_font_weight', [
                'label'     => 'Peso',
                'type'      => \Elementor\Controls_Manager::SELECT,
                'options'   => [
                    '300' => '300 — Light',
                    '400' => '400 — Regular',
                    '500' => '500 — Medium',
                    '600' => '600 — Semi-bold',
                    '700' => '700 — Bold',
                    '800' => '800 — Extra Bold',
                ],
                'default'   => '500',
                'selectors' => [
                    '{{WRAPPER}} .SVGSpiral2026' => '--spiral2026-foreignobject-fontweight: {{VALUE}};',
                ],
            ] );

            $this->add_control( 'spiral_font_size', [
                'label'      => 'Tamanho (px)',
                'type'       => \Elementor\Controls_Manager::SLIDER,
                'size_units' => [ 'px' ],
                'range'      => [ 'px' => [ 'min' => 6, 'max' => 32, 'step' => 1 ] ],
                'default'    => [ 'unit' => 'px', 'size' => 15 ],
                'selectors'  => [
                    '{{WRAPPER}} .SVGSpiral2026' => '--spiral2026-foreignobject-fontsize: {{SIZE}}{{UNIT}};',
                ],
            ] );

            $this->add_control( 'spiral_line_height', [
                'label'      => 'Altura de linha',
                'type'       => \Elementor\Controls_Manager::SLIDER,
                'size_units' => [ 'em' ],
                'range'      => [ 'em' => [ 'min' => 0.8, 'max' => 2.5, 'step' => 0.05 ] ],
                'default'    => [ 'unit' => 'em', 'size' => 1.2 ],
                'selectors'  => [
                    '{{WRAPPER}} .SVGSpiral2026' => '--spiral2026-foreignobject-lineheight: {{SIZE}};',
                ],
            ] );

            $this->add_control( 'spiral_letter_spacing', [
                'label'      => 'Espaçamento entre letras',
                'type'       => \Elementor\Controls_Manager::SLIDER,
                'size_units' => [ 'em' ],
                'range'      => [ 'em' => [ 'min' => -0.1, 'max' => 0.3, 'step' => 0.01 ] ],
                'default'    => [ 'unit' => 'em', 'size' => 0 ],
                'selectors'  => [
                    '{{WRAPPER}} .SVGSpiral2026' => '--spiral2026-foreignobject-letterspacing: {{SIZE}}{{UNIT}};',
                ],
            ] );

            $this->add_control( 'spiral_text_width', [
                'label'      => 'Largura da caixa de texto (px)',
                'type'       => \Elementor\Controls_Manager::SLIDER,
                'size_units' => [ 'px' ],
                'range'      => [ 'px' => [ 'min' => 60, 'max' => 200, 'step' => 2 ] ],
                'default'    => [ 'unit' => 'px', 'size' => 130 ],
                'description' => 'Segmentos com largura individual no SVG não são afetados.',
                'selectors'  => [
                    '{{WRAPPER}} .SVGSpiral2026' => '--spiral2026-foreignobject-width: {{SIZE}}{{UNIT}};',
                ],
            ] );

            $this->add_control( 'spiral_text_height', [
                'label'      => 'Altura da caixa de texto (px)',
                'type'       => \Elementor\Controls_Manager::SLIDER,
                'size_units' => [ 'px' ],
                'range'      => [ 'px' => [ 'min' => 40, 'max' => 200, 'step' => 2 ] ],
                'default'    => [ 'unit' => 'px', 'size' => 88 ],
                'selectors'  => [
                    '{{WRAPPER}} .SVGSpiral2026' => '--spiral2026-foreignobject-height: {{SIZE}}{{UNIT}};',
                ],
            ] );

            $this->add_control( 'spiral_text_y_offset', [
                'label'      => 'Posição Y (px)',
                'type'       => \Elementor\Controls_Manager::SLIDER,
                'size_units' => [ 'px' ],
                'range'      => [ 'px' => [ 'min' => -30, 'max' => 30, 'step' => 1 ] ],
                'default'    => [ 'unit' => 'px', 'size' => 0 ],
                'description' => 'Ajuste fino de centralização vertical do texto (aplicado a todos os segmentos).',
                'selectors'  => [
                    '{{WRAPPER}} .SVGSpiral2026' => '--spiral2026-foreignobject-y-offset: {{SIZE}}{{UNIT}};',
                ],
            ] );

            $this->end_controls_section();

            // ── Tipografia por eixo ───────────────────────────────────────
            $this->start_controls_section( 'typo_per_axis_section', [
                'label' => 'Tipografia por eixo',
                'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
            ] );

            $this->add_control( 'typo_per_axis_help', [
                'type'            => \Elementor\Controls_Manager::RAW_HTML,
                'raw'             => '<small style="color:#888;display:block;line-height:1.4">Override por segmento. Cada linha do Repeater corresponde a um dos 21 eixos (na ordem). Campos vazios mantêm os valores globais de Tipografia. <code>Posição Y</code> ajusta a centralização vertical do texto sem mudar a caixa.</small>',
                'content_classes' => 'elementor-descriptor',
            ] );

            $typo_repeater = new \Elementor\Repeater();

            $typo_repeater->add_control( 'seg_label', [
                'label'       => 'Segmento (referência)',
                'type'        => \Elementor\Controls_Manager::TEXT,
                'description' => 'Apenas para identificar a linha.',
            ] );

            $typo_repeater->add_control( 'seg_font_size', [
                'label'      => 'Tamanho (px)',
                'type'       => \Elementor\Controls_Manager::SLIDER,
                'size_units' => [ 'px' ],
                'range'      => [ 'px' => [ 'min' => 6, 'max' => 32, 'step' => 1 ] ],
                'default'    => [ 'unit' => 'px', 'size' => '' ],
            ] );

            $typo_repeater->add_control( 'seg_line_height', [
                'label'      => 'Altura de linha',
                'type'       => \Elementor\Controls_Manager::SLIDER,
                'size_units' => [ 'em' ],
                'range'      => [ 'em' => [ 'min' => 0.8, 'max' => 2.5, 'step' => 0.05 ] ],
                'default'    => [ 'unit' => 'em', 'size' => '' ],
            ] );

            $typo_repeater->add_control( 'seg_letter_spacing', [
                'label'      => 'Espaçamento entre letras',
                'type'       => \Elementor\Controls_Manager::SLIDER,
                'size_units' => [ 'em' ],
                'range'      => [ 'em' => [ 'min' => -0.1, 'max' => 0.3, 'step' => 0.01 ] ],
                'default'    => [ 'unit' => 'em', 'size' => '' ],
            ] );

            $typo_repeater->add_control( 'seg_text_width', [
                'label'      => 'Largura da caixa de texto (px)',
                'type'       => \Elementor\Controls_Manager::SLIDER,
                'size_units' => [ 'px' ],
                'range'      => [ 'px' => [ 'min' => 60, 'max' => 200, 'step' => 2 ] ],
                'default'    => [ 'unit' => 'px', 'size' => '' ],
            ] );

            $typo_repeater->add_control( 'seg_text_height', [
                'label'      => 'Altura da caixa de texto (px)',
                'type'       => \Elementor\Controls_Manager::SLIDER,
                'size_units' => [ 'px' ],
                'range'      => [ 'px' => [ 'min' => 40, 'max' => 200, 'step' => 2 ] ],
                'default'    => [ 'unit' => 'px', 'size' => '' ],
            ] );

            $typo_repeater->add_control( 'seg_y_offset', [
                'label'      => 'Posição Y (px)',
                'type'       => \Elementor\Controls_Manager::SLIDER,
                'size_units' => [ 'px' ],
                'range'      => [ 'px' => [ 'min' => -30, 'max' => 30, 'step' => 1 ] ],
                'default'    => [ 'unit' => 'px', 'size' => '' ],
                'description' => 'Ajuste fino de centralização vertical do texto.',
            ] );

            $this->add_control( 'typo_repeater', [
                'label'         => 'Override por segmento',
                'type'          => \Elementor\Controls_Manager::REPEATER,
                'fields'        => $typo_repeater->get_controls(),
                'default'       => $this->get_default_typo_repeater(),
                'title_field'   => '{{{ seg_label }}}',
                'prevent_empty' => false,
            ] );

            $this->end_controls_section();

            // ── Animação ──────────────────────────────────────────────────
            $this->start_controls_section( 'anim_section', [
                'label' => 'Animação',
                'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
            ] );

            $this->add_control( 'spiral_anim_duration', [
                'label'      => 'Duração (s)',
                'type'       => \Elementor\Controls_Manager::SLIDER,
                'size_units' => [ 's' ],
                'range'      => [ 's' => [ 'min' => 0.5, 'max' => 10, 'step' => 0.1 ] ],
                'default'    => [ 'unit' => 's', 'size' => 2 ],
                'selectors'  => [
                    '{{WRAPPER}} .SVGSpiral2026' => '--spiral2026-animation-duration: {{SIZE}}{{UNIT}};',
                ],
            ] );

            $this->add_control( 'spiral_anim_delay', [
                'label'      => 'Delay entre segmentos (ms)',
                'type'       => \Elementor\Controls_Manager::SLIDER,
                'size_units' => [ 'ms' ],
                'range'      => [ 'ms' => [ 'min' => 0, 'max' => 1000, 'step' => 10 ] ],
                'default'    => [ 'unit' => 'ms', 'size' => 160 ],
                'selectors'  => [
                    '{{WRAPPER}} .SVGSpiral2026' => '--spiral2026-animation-delay: {{SIZE}}{{UNIT}};',
                ],
            ] );

            $this->add_control( 'anim_bg_heading', [
                'type'  => \Elementor\Controls_Manager::HEADING,
                'label' => 'Fundo dos segmentos',
                'separator' => 'before',
            ] );

            $this->add_control( 'anim_fill_color', [
                'label'       => 'Cor de fundo durante animação',
                'type'        => \Elementor\Controls_Manager::COLOR,
                'description' => 'Cor do fundo dos segmentos enquanto a animação roda. Vazio = mantém a cor de "Cores Principais → Cor de fundo".',
                'alpha'       => true,
                'selectors'   => [
                    '{{WRAPPER}} .SVGSpiral2026' => '--spiral2026-anim-fill: {{VALUE}};',
                ],
            ] );

            $this->add_control( 'anim_pulse_intensity', [
                'label'       => 'Intensidade do pulso',
                'type'        => \Elementor\Controls_Manager::SLIDER,
                'description' => 'Brilho/saturação aplicados durante o pulso. 1 = sem efeito (default), 2-3 = pulso luminoso.',
                'range'       => [ 'px' => [ 'min' => 1, 'max' => 3, 'step' => 0.1 ] ],
                'default'     => [ 'unit' => 'px', 'size' => 1 ],
                'selectors'   => [
                    '{{WRAPPER}} .SVGSpiral2026' => '--spiral2026-anim-filter: brightness({{SIZE}}) saturate({{SIZE}});',
                ],
            ] );

            $this->add_control( 'anim_text_heading', [
                'type'  => \Elementor\Controls_Manager::HEADING,
                'label' => 'Texto dos segmentos',
                'separator' => 'before',
            ] );

            $this->add_control( 'anim_text_color', [
                'label'       => 'Cor do texto durante animação',
                'type'        => \Elementor\Controls_Manager::COLOR,
                'description' => 'Cor do texto dos segmentos enquanto a animação roda. Vazio = mantém a cor de "Tipografia → Cor do texto".',
                'alpha'       => true,
                'selectors'   => [
                    '{{WRAPPER}} .SVGSpiral2026' => '--spiral2026-anim-text-color: {{VALUE}};',
                ],
            ] );

            $this->add_control( 'anim_text_keep_visible', [
                'label'        => 'Manter texto visível (não pisca)',
                'type'         => \Elementor\Controls_Manager::SWITCHER,
                'description'  => 'Ao ligar, só o fundo do segmento pisca; o texto fica visível durante toda a animação.',
                'label_on'     => 'Sim',
                'label_off'    => 'Não',
                'return_value' => '1',
                'default'      => '',
                'selectors'    => [
                    '{{WRAPPER}} .SVGSpiral2026' => '--spiral2026-anim-text-opacity: 1;',
                ],
            ] );

            $this->add_control( 'anim_text_scale', [
                'label'       => 'Escala (zoom) do texto durante animação',
                'type'        => \Elementor\Controls_Manager::SLIDER,
                'description' => 'Zoom aplicado ao texto no meio do pulso. 1 = sem efeito (default).',
                'range'       => [ 'px' => [ 'min' => 0.8, 'max' => 1.4, 'step' => 0.05 ] ],
                'default'     => [ 'unit' => 'px', 'size' => 1 ],
                'selectors'   => [
                    '{{WRAPPER}} .SVGSpiral2026' => '--spiral2026-anim-text-scale: {{SIZE}};',
                ],
            ] );

            $this->add_control( 'anim_replay_btn', [
                'type' => \Elementor\Controls_Manager::RAW_HTML,
                // onclick inline garante que o botão funcione mesmo se o <script>
                // do bit_espiral_print_replay_js não estiver disponível no momento
                // (Underscore template do Elementor pode reordenar o DOM).
                'raw'  => '<button type="button" class="bit-espiral-replay-btn" '
                        . 'onclick="if(window.bitEspiralReplay){bitEspiralReplay(event)}else{console.warn(\'[bit-espiral] handler não carregado\')}" '
                        . 'style="width:100%;margin-top:6px;padding:8px;background:#3c6fff;color:#fff;border:none;'
                        . 'border-radius:3px;cursor:pointer;font-size:12px;font-weight:600;letter-spacing:.3px">'
                        . '↻ Testar animação</button>'
                        . '<small style="color:#888;display:block;margin-top:4px">Re-dispara o fade dos segmentos no preview, sem reload.</small>',
            ] );

            $this->end_controls_section();

            // ─── SEÇÃO: Efeito de Clique nos Eixos ────────────────────
            $this->start_controls_section( 'click_fx_section', [
                'label' => 'Clique nos eixos',
                'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
            ] );

            $this->add_control( 'click_fx_enabled', [
                'label'        => 'Ativar efeito de clique',
                'type'         => \Elementor\Controls_Manager::SWITCHER,
                'description'  => 'Glow rosa no eixo clicado + opcional estado de carregamento até a próxima página renderizar.',
                'label_on'     => 'Sim',
                'label_off'    => 'Não',
                'return_value' => '1',
                'default'      => '1',
            ] );

            $this->add_control( 'click_fx_glow_color', [
                'label'       => 'Cor do glow',
                'type'        => \Elementor\Controls_Manager::COLOR,
                'description' => 'Cor do drop-shadow ao clicar num eixo.',
                'default'     => '#ec4899',
                'condition'   => [ 'click_fx_enabled' => '1' ],
            ] );

            $this->add_control( 'click_fx_glow_duration', [
                'label'       => 'Duração do glow (ms)',
                'type'        => \Elementor\Controls_Manager::SLIDER,
                'description' => 'Duração do flash inicial ao clicar.',
                'size_units'  => [ 'ms' ],
                'range'       => [ 'ms' => [ 'min' => 150, 'max' => 1200, 'step' => 50 ] ],
                'default'     => [ 'unit' => 'ms', 'size' => 400 ],
                'condition'   => [ 'click_fx_enabled' => '1' ],
            ] );

            $this->add_control( 'click_fx_glow_dim', [
                'label'       => 'Opacidade dos outros eixos (glow)',
                'type'        => \Elementor\Controls_Manager::SLIDER,
                'description' => 'Quanto os demais eixos esmaecem durante o flash inicial. 1 = sem dim.',
                'range'       => [ 'px' => [ 'min' => 0.3, 'max' => 1, 'step' => 0.05 ] ],
                'default'     => [ 'unit' => 'px', 'size' => 0.75 ],
                'condition'   => [ 'click_fx_enabled' => '1' ],
            ] );

            $this->add_control( 'click_fx_loading_heading', [
                'type'      => \Elementor\Controls_Manager::HEADING,
                'label'     => 'Estado de carregamento',
                'separator' => 'before',
                'condition' => [ 'click_fx_enabled' => '1' ],
            ] );

            $this->add_control( 'click_fx_loading_enabled', [
                'label'        => 'Ativar pulso de loading',
                'type'         => \Elementor\Controls_Manager::SWITCHER,
                'description'  => 'Após o glow, o eixo pulsa em loop até a próxima página carregar. Útil quando a navegação demora alguns segundos.',
                'label_on'     => 'Sim',
                'label_off'    => 'Não',
                'return_value' => '1',
                'default'      => '1',
                'condition'    => [ 'click_fx_enabled' => '1' ],
            ] );

            $this->add_control( 'click_fx_loading_speed', [
                'label'       => 'Velocidade do pulso (ms por ciclo)',
                'type'        => \Elementor\Controls_Manager::SLIDER,
                'description' => 'Quanto menor, mais rápido o pulso. 600ms = ritmo natural; 1200ms = contemplativo.',
                'size_units'  => [ 'ms' ],
                'range'       => [ 'ms' => [ 'min' => 300, 'max' => 1500, 'step' => 50 ] ],
                'default'     => [ 'unit' => 'ms', 'size' => 600 ],
                'condition'   => [
                    'click_fx_enabled'         => '1',
                    'click_fx_loading_enabled' => '1',
                ],
            ] );

            $this->add_control( 'click_fx_loading_dim', [
                'label'       => 'Opacidade dos outros eixos (loading)',
                'type'        => \Elementor\Controls_Manager::SLIDER,
                'description' => 'Quanto os demais eixos esmaecem enquanto o carregamento acontece.',
                'range'       => [ 'px' => [ 'min' => 0.2, 'max' => 1, 'step' => 0.05 ] ],
                'default'     => [ 'unit' => 'px', 'size' => 0.5 ],
                'condition'   => [
                    'click_fx_enabled'         => '1',
                    'click_fx_loading_enabled' => '1',
                ],
            ] );

            $this->end_controls_section();
        }

        protected function render() {
            $s    = $this->get_settings_for_display();
            $name = ! empty( $s['svg_name'] ) ? sanitize_file_name( $s['svg_name'] ) : 'espiral-do-conhecimento';
            $file = get_stylesheet_directory() . '/svg/' . $name . '.svg';

            if ( ! file_exists( $file ) ) {
                if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
                    echo '<p style="color:#e07070;font-size:12px;padding:12px;">SVG não encontrado: ' . esc_html( $name ) . '.svg</p>';
                }
                return;
            }

            $svg = file_get_contents( $file );
            if ( empty( $svg ) ) return;

            // Remover declaração XML (inválida em HTML5 inline)
            $svg = preg_replace( '/<\?xml[^?]*\?>\s*/i', '', $svg );

            // ── Safari/WebKit fix: adicionar preserveAspectRatio explícito.
            //
            // Sem preserveAspectRatio, Safari/WebKit aplica o default implícito
            // "xMidYMid meet" mas trata foreignObject filhos com cálculos
            // sub-pixel divergentes do Chromium/Blink (validado via Playwright
            // 2026-05-17 com MutationObserver: zero JS modifica DOM, mas
            // <g>/foreignObject internos renderizam em posições diferentes).
            //
            // Forçar preserveAspectRatio explícito reduz ambiguidade entre engines.
            $svg = preg_replace_callback(
                '#(<svg\b)([^>]*)>#i',
                static function ( $m ) {
                    $attrs = $m[2];
                    // Se já tem preserveAspectRatio, não duplica
                    if ( preg_match( '#\bpreserveAspectRatio\s*=#i', $attrs ) ) {
                        return $m[0];
                    }
                    return $m[1] . $attrs . ' preserveAspectRatio="xMidYMid meet">';
                },
                $svg,
                1 // só o <svg> root, não svgs filhos
            );

            // Envelopar conteúdo de cada <foreignObject> em
            // <div xmlns="http://www.w3.org/1999/xhtml" class="bit-espiral-text-inner">
            // para permitir aplicar `position: relative; top:` (Y offset).
            //
            // O xmlns XHTML é OBRIGATÓRIO: sem ele, Safari iOS (strict SVG/XHTML
            // parser) não renderiza o conteúdo HTML dentro do foreignObject —
            // o texto desaparece no iPhone (Chrome/Firefox são tolerantes).
            $svg = preg_replace_callback(
                '#(<foreignObject\b[^>]*>)(.*?)(</foreignObject>)#s',
                static function ( $m ) {
                    $inner = trim( $m[2] );
                    if ( strpos( $inner, 'bit-espiral-text-inner' ) !== false ) {
                        // Já envelopado em versões anteriores (sem xmlns) — re-aplica
                        // o xmlns no wrapper existente para corrigir páginas cacheadas
                        // de outras versões do mu-plugin.
                        return preg_replace(
                            '#<div(\s+(?!xmlns=)[^>]*)?class="bit-espiral-text-inner"#',
                            '<div xmlns="http://www.w3.org/1999/xhtml"$1 class="bit-espiral-text-inner"',
                            $m[0],
                            1
                        );
                    }
                    return $m[1]
                        . '<div xmlns="http://www.w3.org/1999/xhtml" class="bit-espiral-text-inner">'
                        . $inner
                        . '</div>'
                        . $m[3];
                },
                $svg
            );

            $stored_config = get_option( 'bit_espiral_config', [] );

            // ── 0. Sintetizar segLinks a partir do Repeater "axes_repeater" ─
            // O Repeater (UI nova) tem precedência sobre segLinks salvos no
            // banco. Cada item gera URL no padrão:
            //   /conhecimento/espiral-de-conhecimento/?eixo=eixo{N}
            //   &jsf=jet-engine:estudos&tax=eixos:{term_id}#estudos
            //
            // {N} é a POSIÇÃO do segmento (1..21), não o slug do termo. Isso
            // mantém compatibilidade com o JetSmartFilters que espera
            // `eixo1..eixo21` como query var (o filtro real é via tax=eixos:ID).
            //
            // Itens sem term_id (vazio) deixam o segmento inalterado — fallback
            // para segLinks do banco e, se ausente, href estático do SVG.
            $repeater_items = $s['axes_repeater'] ?? [];
            if ( ! empty( $repeater_items ) && is_array( $repeater_items ) ) {
                $synth_links = [];
                foreach ( $repeater_items as $i => $row ) {
                    $n = $i + 1;
                    if ( $n > 21 ) break;
                    $term_id = isset( $row['segment_term_id'] ) ? (int) $row['segment_term_id'] : 0;
                    if ( $term_id <= 0 ) continue;
                    // v1.7.0: anexar _label legivel a partir do segment_label do Repeater.
                    // Idioma da pagina (PT no PT, EN no EN). Atualiza automaticamente
                    // quando o segment_label e renomeado no painel Elementor.
                    $label_raw  = isset( $row['segment_label'] ) ? (string) $row['segment_label'] : '';
                    $label_slug = sanitize_title( $label_raw );
                    $synth_links[ $n ] = sprintf(
                        '/conhecimento/espiral-de-conhecimento/?eixo=eixo%d%s&jsf=jet-engine:estudos&tax=eixos:%d#estudos',
                        $n,
                        $label_slug !== '' ? '&_label=' . $label_slug : '',
                        $term_id
                    );
                }
                if ( ! empty( $synth_links ) ) {
                    if ( ! is_array( $stored_config ) ) $stored_config = [];
                    $existing = isset( $stored_config['segLinks'] ) && is_array( $stored_config['segLinks'] )
                              ? $stored_config['segLinks'] : [];
                    // Repeater preenchido vence; segmentos vazios mantêm o que vier do banco.
                    $stored_config['segLinks'] = $synth_links + $existing;
                }

                // ── 0.1. Substituir o texto PT do segmento (aria-label, <title>
                // e conteúdo do <foreignObject data-language="pt">) com o valor
                // do campo "Segmento (referência)" do Repeater.
                // Edição do label no painel reflete imediatamente no segmento.
                foreach ( $repeater_items as $i => $row ) {
                    $n = $i + 1;
                    if ( $n > 21 ) break;
                    $label = isset( $row['segment_label'] ) ? trim( (string) $row['segment_label'] ) : '';
                    if ( '' === $label ) continue;
                    $label_attr = esc_attr( $label );
                    $label_text = esc_html( $label );

                    // Match do <a id="Spiral26Text-N" ...> incluindo seu fechamento </a>
                    $svg = preg_replace_callback(
                        '#(<a\b[^>]*\bid="Spiral26Text-' . (int) $n . '"[^>]*>)(.*?)(</a>)#s',
                        static function ( $m ) use ( $label_attr, $label_text ) {
                            $open  = $m[1];
                            $inner = $m[2];
                            $close = $m[3];

                            // 1) aria-label="..." na tag <a>
                            if ( preg_match( '/\baria-label="[^"]*"/', $open ) ) {
                                $open = preg_replace( '/\baria-label="[^"]*"/', 'aria-label="' . $label_attr . '"', $open );
                            } else {
                                $open = preg_replace( '/<a\b/', '<a aria-label="' . $label_attr . '"', $open, 1 );
                            }

                            // 2) <title>...</title>
                            if ( preg_match( '#<title>.*?</title>#s', $inner ) ) {
                                $inner = preg_replace( '#<title>.*?</title>#s', '<title>' . $label_text . '</title>', $inner, 1 );
                            }

                            // 3) <foreignObject data-language="pt">…texto…</foreignObject>
                            // xmlns="http://www.w3.org/1999/xhtml" OBRIGATÓRIO no <div>
                            // para Safari iOS renderizar o conteúdo do foreignObject.
                            $inner = preg_replace_callback(
                                '#(<foreignObject\b[^>]*\bdata-language="pt"[^>]*>)(.*?)(</foreignObject>)#s',
                                static function ( $fm ) use ( $label_text ) {
                                    return $fm[1]
                                        . '<div xmlns="http://www.w3.org/1999/xhtml" class="bit-espiral-text-inner">'
                                        . $label_text
                                        . '</div>'
                                        . $fm[3];
                                },
                                $inner,
                                1
                            );

                            return $open . $inner . $close;
                        },
                        $svg,
                        1
                    );
                }
            }

            // ── 1. Aplicar segLinks e segTypo no markup SVG ────────────────
            // (modificações estruturais: href e style vars por segmento)
            if ( ! empty( $stored_config ) ) {
                $svg = $this->apply_config_to_svg( $svg, $stored_config );
            }

            // ── 1.1. Cross-browser fix: converter <foreignObject> em <text> SVG
            //
            // <foreignObject> + HTML interno tem múltiplos bugs em Safari/WebKit
            // (clip-rect quebrado com position:relative, scaling diferente do
            // Blink, cálculo de viewBox + foreignObject divergente). <text> SVG
            // nativo renderiza identicamente cross-browser.
            //
            // Para cada <foreignObject data-language="pt"> com .bit-espiral-text-inner:
            //  - extrair x, y, w, h, texto
            //  - calcular fontSize por eixo (typo_repeater overrides ou default 15px)
            //  - aplicar word-wrap manual em N linhas baseado em width
            //  - emitir <text x=centro y=baseline> com <tspan> por linha
            //  - esconder os 2 foreignObjects (PT + EN) com display:none
            //
            // Per-axis font-size: lê typo_repeater (controles Elementor) para
            // gerar mapa eixo→fontSize. Default = $s['spiral_font_size']['size']
            // (controle global).
            $axis_font_size = [];
            $axis_y_offset  = [];
            $typo_items_pre = $s['typo_repeater'] ?? [];
            if ( is_array( $typo_items_pre ) ) {
                foreach ( $typo_items_pre as $i => $row ) {
                    $n = $i + 1;
                    if ( $n > 21 ) break;
                    if ( isset( $row['seg_font_size']['size'] ) && '' !== $row['seg_font_size']['size'] && null !== $row['seg_font_size']['size'] ) {
                        $axis_font_size[ $n ] = (float) $row['seg_font_size']['size'];
                    }
                    if ( isset( $row['seg_y_offset']['size'] ) && '' !== $row['seg_y_offset']['size'] && null !== $row['seg_y_offset']['size'] ) {
                        $axis_y_offset[ $n ] = (int) $row['seg_y_offset']['size'];
                    }
                }
            }
            $default_font_size = isset( $s['spiral_font_size']['size'] ) ? (float) $s['spiral_font_size']['size'] : 15.0;
            $default_y_offset  = isset( $s['spiral_text_y_offset']['size'] ) ? (int) $s['spiral_text_y_offset']['size'] : 0;

            // Word-wrap helper: quebra texto em linhas com até maxChars chars,
            // sem quebrar palavras. maxChars derivado de (width_px / fontSize / 0.55).
            $wrap_text = static function( $text, $max_chars ) {
                $text  = trim( preg_replace( '/\s+/u', ' ', $text ) );
                if ( $text === '' ) return [];
                $words = explode( ' ', $text );
                $lines = [];
                $cur   = '';
                foreach ( $words as $w ) {
                    $test = $cur === '' ? $w : ( $cur . ' ' . $w );
                    if ( mb_strlen( $test ) > $max_chars && $cur !== '' ) {
                        $lines[] = $cur;
                        $cur     = $w;
                    } else {
                        $cur = $test;
                    }
                }
                if ( $cur !== '' ) $lines[] = $cur;
                return $lines;
            };

            // Substitui foreignObject PT por <text>; remove foreignObject EN
            $axis_counter = 0;
            $svg = preg_replace_callback(
                '#<foreignObject\b([^>]*)>(.*?)</foreignObject>#s',
                function ( $m ) use ( &$axis_counter, $axis_font_size, $axis_y_offset, $default_font_size, $default_y_offset, $wrap_text ) {
                    $attrs = $m[1];
                    $inner = $m[2];

                    // Pula EN — remove (esconde com display:none preservando layout)
                    if ( preg_match( '#data-language=["\']en["\']#i', $attrs ) ) {
                        return '';
                    }

                    // PT: aumenta contador (1..21)
                    $axis_counter++;
                    $n = $axis_counter;

                    // Extrai x, y, w, h
                    preg_match( '#\bx\s*=\s*["\']([^"\']+)["\']#i', $attrs, $mx );
                    preg_match( '#\by\s*=\s*["\']([^"\']+)["\']#i', $attrs, $my );
                    preg_match( '#\bwidth\s*=\s*["\']([^"\']+)["\']#i', $attrs, $mw );
                    preg_match( '#\bheight\s*=\s*["\']([^"\']+)["\']#i', $attrs, $mh );
                    $x = isset( $mx[1] ) ? (float) $mx[1] : 0;
                    $y = isset( $my[1] ) ? (float) $my[1] : 0;
                    $w = isset( $mw[1] ) ? (float) $mw[1] : 130;
                    $h = isset( $mh[1] ) ? (float) $mh[1] : 88;

                    // Extrai texto (entre <div>...</div> ou direto)
                    $text = '';
                    if ( preg_match( '#<div[^>]*class=["\'][^"\']*bit-espiral-text-inner[^"\']*["\'][^>]*>(.*?)</div>#s', $inner, $md ) ) {
                        $text = $md[1];
                    } else {
                        $text = $inner;
                    }
                    // Strip de qualquer tag interna; decodifica entities
                    $text = trim( html_entity_decode( strip_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
                    if ( $text === '' ) {
                        return '';
                    }

                    // Font-size por eixo ou default
                    $fs = $axis_font_size[ $n ] ?? $default_font_size;
                    $yo = $axis_y_offset[ $n ] ?? $default_y_offset;

                    // Word-wrap: aprox 0.5 = razão char/fontSize média (font Roboto/Just Sans)
                    $max_chars  = max( 8, (int) floor( $w / ( $fs * 0.5 ) ) );
                    $lines      = $wrap_text( $text, $max_chars );
                    $line_count = count( $lines );
                    $line_h     = $fs * 1.2;
                    $cx         = $x + $w / 2;
                    // Posicionamento: replica comportamento do foreignObject original
                    // que usava text-align:center + HTML flow (texto começa no TOPO do
                    // bbox, não centralizado verticalmente). As coordenadas y do
                    // foreignObject no SVG fonte foram calibradas para esse comportamento.
                    //
                    // Primeira linha: baseline = y_topo + fontSize*0.85 (cap-height média)
                    //                          + y_offset opcional do typo_repeater
                    // Linhas seguintes: dy = line_h (1.2em)
                    $first_y    = $y + $fs * 0.85 + $yo;

                    $tspans = '';
                    foreach ( $lines as $i => $line ) {
                        $dy_attr = $i === 0 ? '' : ' dy="' . $line_h . '"';
                        $tspans .= sprintf(
                            '<tspan x="%s"%s>%s</tspan>',
                            $cx,
                            $dy_attr,
                            htmlspecialchars( $line, ENT_QUOTES, 'UTF-8' )
                        );
                    }

                    // text-anchor=middle centraliza horizontalmente.
                    // Sem dominant-baseline (que diverge entre engines):
                    // baseline natural (alphabetic) + offset calculado explicitamente.
                    return sprintf(
                        '<text class="bit-espiral-text-svg" x="%s" y="%s" text-anchor="middle" font-size="%s" data-axis="%d">%s</text>',
                        $cx,
                        $first_y,
                        $fs,
                        $n,
                        $tspans
                    );
                },
                $svg
            );

            // ── 2. Injetar CSS vars globais via <style> (esp. 0,1,0) ───────
            //
            // NÃO usar style="" inline no <svg>: inline styles têm especificidade
            // máxima (1,0,0) e bloqueiam os controles do Elementor (esp. 0,2,0).
            // <style> com .SVGSpiral2026 (esp. 0,1,0) pode ser sobrescrito pelo
            // Elementor (esp. 0,2,0) ✓
            //
            $api_rules = [];
            if ( ! empty( $stored_config['cssVars'] ) && is_array( $stored_config['cssVars'] ) ) {
                $allowed = array_keys( $this->get_default_css_vars() );
                foreach ( $stored_config['cssVars'] as $k => $v ) {
                    if ( in_array( $k, $allowed, true ) && '' !== trim( (string) $v ) ) {
                        $api_rules[] = esc_attr( $k ) . ':' . esc_attr( $v );
                    }
                }
            }

            $api_style = $api_rules
                ? '<style data-bit-espiral-api>.SVGSpiral2026{' . implode( ';', $api_rules ) . '}</style>'
                : '';

            // ── 2.1. Tipografia por eixo (Repeater "typo_repeater") ────────
            // Para cada linha do Repeater com pelo menos um campo preenchido,
            // gera regra CSS escopada ao foreignObject do segmento via
            // #Spiral26Text-N. Regras vão num <style> escopado dentro do <svg>
            // — especificidade alta o suficiente (#id) para sobrescrever as
            // CSS vars globais.
            $typo_rules     = [];
            $typo_y_rules   = []; // posição Y aplicada via translateY no foreignObject
            $typo_items     = $s['typo_repeater'] ?? [];
            if ( is_array( $typo_items ) ) {
                foreach ( $typo_items as $i => $row ) {
                    $n = $i + 1;
                    if ( $n > 21 ) break;
                    $rules = [];
                    if ( isset( $row['seg_font_size']['size'] ) && '' !== $row['seg_font_size']['size'] && null !== $row['seg_font_size']['size'] ) {
                        $rules[] = 'font-size:' . (float) $row['seg_font_size']['size'] . 'px';
                    }
                    if ( isset( $row['seg_line_height']['size'] ) && '' !== $row['seg_line_height']['size'] && null !== $row['seg_line_height']['size'] ) {
                        $rules[] = 'line-height:' . (float) $row['seg_line_height']['size'];
                    }
                    if ( isset( $row['seg_letter_spacing']['size'] ) && '' !== $row['seg_letter_spacing']['size'] && null !== $row['seg_letter_spacing']['size'] ) {
                        $rules[] = 'letter-spacing:' . (float) $row['seg_letter_spacing']['size'] . 'em';
                    }
                    if ( isset( $row['seg_text_width']['size'] ) && '' !== $row['seg_text_width']['size'] && null !== $row['seg_text_width']['size'] ) {
                        $rules[] = 'width:' . (int) $row['seg_text_width']['size'] . 'px';
                    }
                    if ( isset( $row['seg_text_height']['size'] ) && '' !== $row['seg_text_height']['size'] && null !== $row['seg_text_height']['size'] ) {
                        $rules[] = 'height:' . (int) $row['seg_text_height']['size'] . 'px';
                    }
                    if ( ! empty( $rules ) ) {
                        $typo_rules[] = sprintf(
                            '.SVGSpiral2026 #Spiral26Text-%d foreignObject{%s}',
                            $n,
                            implode( ';', $rules )
                        );
                    }
                    // Posição Y: aplicada via `transform: translateY()` no
                    // wrapper .bit-espiral-text-inner.
                    // ATENÇÃO: usar `position:relative; top:` causa bug em
                    // Safari/WebKit — conteúdo HTML vaza fora do clip-rect do
                    // foreignObject pai (validado com SVG minimal em maio/2026).
                    // `transform: translateY()` mantém o elemento no flow e
                    // funciona consistentemente em Chrome/Firefox/Safari.
                    if ( isset( $row['seg_y_offset']['size'] ) && '' !== $row['seg_y_offset']['size'] && null !== $row['seg_y_offset']['size'] && 0 != $row['seg_y_offset']['size'] ) {
                        $typo_y_rules[] = sprintf(
                            '.SVGSpiral2026 #Spiral26Text-%d foreignObject .bit-espiral-text-inner{transform:translateY(%dpx)}',
                            $n,
                            (int) $row['seg_y_offset']['size']
                        );
                    }
                }
            }
            $typo_style = '';
            if ( ! empty( $typo_rules ) || ! empty( $typo_y_rules ) ) {
                $typo_style = '<style data-bit-espiral-typo-per-axis>'
                            . implode( '', $typo_rules )
                            . implode( '', $typo_y_rules )
                            . '</style>';
            }

            // ── 2.2. scroll-margin-top no #estudos ────────────────────────
            // Quando o usuário clica num eixo da espiral, o link tem #estudos
            // como âncora. Sem esse offset, a página rola até o topo do grid
            // de cards e esconde os filtros logo acima. Esse <style> escopa
            // a regra para o widget Listing Grid com id=estudos.
            $anchor_style = '';
            if ( isset( $s['estudos_anchor_offset']['size'] ) && $s['estudos_anchor_offset']['size'] > 0 ) {
                $anchor_offset = (int) $s['estudos_anchor_offset']['size'];
                $anchor_style = sprintf(
                    '<style data-bit-espiral-anchor>#estudos{scroll-margin-top:%dpx}</style>',
                    $anchor_offset
                );
            }

            // ── 3. Fix fill nos <use> shadow clones + CSS de clique/loading
            // Lê settings do widget (com defaults) e sanitiza para evitar
            // injeção via valores arbitrários no <style> inline.
            $fx_enabled  = ! isset( $s['click_fx_enabled'] ) || '1' === $s['click_fx_enabled'];
            $glow_color  = isset( $s['click_fx_glow_color'] ) && '' !== $s['click_fx_glow_color']
                ? $s['click_fx_glow_color']
                : '#ec4899';
            // Sanitiza cor: aceita #RRGGBB, #RGB, rgb(a)(...) — fallback se inválido.
            if ( ! preg_match( '/^(#[0-9a-fA-F]{3,8}|rgba?\([^()]+\))$/', trim( $glow_color ) ) ) {
                $glow_color = '#ec4899';
            }
            $glow_duration = isset( $s['click_fx_glow_duration']['size'] )
                ? max( 150, min( 1200, (int) $s['click_fx_glow_duration']['size'] ) )
                : 400;
            $glow_dim = isset( $s['click_fx_glow_dim']['size'] )
                ? max( 0.3, min( 1.0, (float) $s['click_fx_glow_dim']['size'] ) )
                : 0.75;
            $loading_enabled = $fx_enabled && ( ! isset( $s['click_fx_loading_enabled'] ) || '1' === $s['click_fx_loading_enabled'] );
            $loading_speed   = isset( $s['click_fx_loading_speed']['size'] )
                ? max( 300, min( 1500, (int) $s['click_fx_loading_speed']['size'] ) )
                : 600;
            $loading_dim = isset( $s['click_fx_loading_dim']['size'] )
                ? max( 0.2, min( 1.0, (float) $s['click_fx_loading_dim']['size'] ) )
                : 0.5;

            // Atributo data-* no <svg root> para o JS detectar se loading está ativo
            // (evita disparar o estado loading se o usuário desligar via Elementor).
            $svg = preg_replace_callback(
                '/(<svg\b)([^>]*)(>)/s',
                static function ( $m ) use ( $fx_enabled, $loading_enabled ) {
                    $attrs = ' data-bit-fx-click="' . ( $fx_enabled ? '1' : '0' ) . '"'
                           . ' data-bit-fx-loading="' . ( $loading_enabled ? '1' : '0' ) . '"';
                    return $m[1] . $m[2] . $attrs . $m[3];
                },
                $svg,
                1
            );

            $svg = preg_replace_callback(
                '/(<svg\b[^>]*>)/s',
                static function ( $m ) use (
                    $api_style, $typo_style, $anchor_style,
                    $fx_enabled, $glow_color, $glow_duration, $glow_dim,
                    $loading_enabled, $loading_speed, $loading_dim
                ) {
                    $base = $m[1]
                        . $api_style
                        . $typo_style
                        . $anchor_style
                        // ── iOS Safari fix: regras CSS dentro de <defs> (no SVG
                        // fonte) NÃO se aplicam ao conteúdo de <foreignObject>
                        // em Safari iOS strict. Replicamos as regras críticas
                        // (color/font/width/position) AQUI, fora de <defs>, no
                        // <style> injetado dentro de <svg> root. Sem isso, os
                        // labels somem ou ficam invisíveis em iPhone.
                        . '<style data-bit-espiral-ios-fix>'
                        // CSS custom properties replicadas (fora de <defs>) para
                        // que Safari iOS resolva os var(...) corretamente.
                        // Defaults baixa specificity: Elementor post-{ID}.css
                        // sobrescreve via controles do widget (fonte da verdade).
                        . '.SVGSpiral2026{'
                        . '--spiral2026-foreignobject-color:#ffffff;'
                        . '--spiral2026-foreignobject-fontfamily:"Just Sans",Sans-serif;'
                        . '--spiral2026-foreignobject-fontsize:15px;'
                        . '--spiral2026-foreignobject-fontweight:500;'
                        . '--spiral2026-foreignobject-lineheight:1.2;'
                        . '--spiral2026-foreignobject-letterspacing:0;'
                        . '--spiral2026-foreignobject-width:130px;'
                        . '--spiral2026-foreignobject-height:88px;'
                        . '--spiral2026-backgroundcolor:rgba(10,38,102,0.6);'
                        . '--spiral2026-backgroundcolor-hover:#0E4A5C;}'
                        // Labels da espiral convertidos de <foreignObject> em
                        // <text> SVG nativo (cross-browser idêntico). Herdam
                        // font-family/color das CSS vars do mu-plugin.
                        . '.SVGSpiral2026 text.bit-espiral-text-svg{'
                        . 'fill:var(--spiral2026-foreignobject-color,#ffffff);'
                        . 'font-family:var(--spiral2026-foreignobject-fontfamily,"Just Sans",Sans-serif);'
                        . 'font-weight:var(--spiral2026-foreignobject-fontweight,500);'
                        . 'pointer-events:none;'
                        . 'paint-order:stroke;'
                        . 'stroke:rgba(0,0,0,0.35);'
                        . 'stroke-width:0.6;}'
                        . '.SVGSpiral2026 foreignObject{'
                        . 'font-family:var(--spiral2026-foreignobject-fontfamily,"Just Sans",Sans-serif);'
                        . 'font-size:var(--spiral2026-foreignobject-fontsize,15px);'
                        . 'font-weight:var(--spiral2026-foreignobject-fontweight,500);'
                        . 'line-height:var(--spiral2026-foreignobject-lineheight,1.2);'
                        . 'color:var(--spiral2026-foreignobject-color,#ffffff);'
                        . 'text-align:center;'
                        . 'width:var(--spiral2026-foreignobject-width,130px);'
                        . 'height:var(--spiral2026-foreignobject-height,88px);'
                        . 'letter-spacing:var(--spiral2026-foreignobject-letterspacing,0);'
                        . 'overflow:visible;pointer-events:none;}'
                        . '.SVGSpiral2026 foreignObject .bit-espiral-text-inner{'
                        // transform: translateY() em vez de position:relative+top
                        // — evita bug WebKit/Safari onde conteúdo HTML vaza
                        // fora do clip-rect do foreignObject pai.
                        // !important sobrescreve a regra com position:relative
                        // que existe dentro de <defs> no SVG fonte (legado).
                        . 'position:static!important;top:auto!important;'
                        . 'transform:translateY(var(--spiral2026-foreignobject-y-offset,0px));'
                        . 'color:inherit;font-family:inherit;font-size:inherit;'
                        . 'font-weight:inherit;line-height:inherit;text-align:inherit;'
                        . 'letter-spacing:inherit;display:block;width:100%;}'
                        . '</style>'
                        . '<style data-bit-espiral-use-fix>'
                        . '.SVGSpiral2026 .spiral26AxisLinksGroup a{'
                        . 'fill:var(--spiral2026-backgroundcolor);'
                        . 'outline:none;-webkit-tap-highlight-color:transparent;}'
                        . '.SVGSpiral2026 .spiral26AxisLinksGroup a:hover,'
                        . '.SVGSpiral2026 .spiral26AxisLinksGroup a:focus,'
                        . '.SVGSpiral2026 .spiral26AxisLinksGroup a:active{'
                        . 'fill:var(--spiral2026-backgroundcolor-hover);}'
                        . '.SVGSpiral2026 .spiral26AxisLinksGroup a:focus-visible{'
                        . 'outline:2px dashed var(--spiral2026-backgroundcolor-hover);'
                        . 'outline-offset:2px;}';

                    if ( ! $fx_enabled ) {
                        return $base . '</style>';
                    }

                    $glow_dim_str    = rtrim( rtrim( number_format( $glow_dim, 2, '.', '' ), '0' ), '.' );
                    $loading_dim_str = rtrim( rtrim( number_format( $loading_dim, 2, '.', '' ), '0' ), '.' );

                    // Glow via FILL color cross-browser:
                    // <a> tem 2 camadas que pintam o setor:
                    //   1. <use href="#eixo-N"> dentro de <a> (.spiral26AxisLinksGroup)
                    //   2. <path id="eixo-N"> em .spiral26AxisBackground (camada base)
                    //
                    // Para a animação ser visível em TODOS engines (especialmente
                    // Safari/WebKit que pode descartar fill no <use> shadow), JS
                    // adiciona classe `bit-clicked-N` no <svg> que dispara CSS
                    // mudando AMBAS camadas via path[id="eixo-N"] + use[href="#eixo-N"].
                    // (lógica JS — ver #bit-espiral-click-glow script abaixo)
                    $base .= '@keyframes bit-axis-glow-fill{'
                          . '0%,100%{fill:var(--spiral2026-backgroundcolor);}'
                          . '50%{fill:' . $glow_color . ';}}'
                          . '.SVGSpiral2026 .spiral26AxisLinksGroup a.bit-clicked use,'
                          . '.SVGSpiral2026.bit-clicked-svg .spiral26AxisBackground path.bit-glow-target{'
                          . 'animation:bit-axis-glow-fill ' . $glow_duration . 'ms ease-out !important;}'
                          . '.SVGSpiral2026 .spiral26AxisLinksGroup.bit-has-clicked a:not(.bit-clicked){'
                          . 'opacity:' . $glow_dim_str . ';transition:opacity 160ms ease-out;}';

                    if ( $loading_enabled ) {
                        $low_glow = preg_match( '/^#[0-9a-fA-F]{6}$/', $glow_color )
                            ? 'rgba(' . hexdec( substr( $glow_color, 1, 2 ) ) . ',' . hexdec( substr( $glow_color, 3, 2 ) ) . ',' . hexdec( substr( $glow_color, 5, 2 ) ) . ',0.6)'
                            : 'rgba(236,72,153,0.6)';
                        $base .= '@keyframes bit-axis-pulse-fill{'
                              . '0%,100%{fill:var(--spiral2026-backgroundcolor);}'
                              . '50%{fill:' . $low_glow . ';}}'
                              . '.SVGSpiral2026 .spiral26AxisLinksGroup a.bit-loading use,'
                              . '.SVGSpiral2026.bit-loading-svg .spiral26AxisBackground path.bit-loading-target{'
                              . 'animation:bit-axis-pulse-fill ' . $loading_speed . 'ms ease-in-out infinite !important;}'
                              . '.SVGSpiral2026 .spiral26AxisLinksGroup.bit-loading a:not(.bit-clicked){'
                              . 'opacity:' . $loading_dim_str . ';transition:opacity 200ms ease-out;}';
                    }

                    $base .= '@media (prefers-reduced-motion:reduce){'
                          . '.SVGSpiral2026 .spiral26AxisLinksGroup a.bit-clicked use,'
                          . '.SVGSpiral2026 .spiral26AxisLinksGroup a.bit-loading use,'
                          . '.SVGSpiral2026 .spiral26AxisBackground path.bit-glow-target,'
                          . '.SVGSpiral2026 .spiral26AxisBackground path.bit-loading-target{animation:none !important;}'
                          . '.SVGSpiral2026 .spiral26AxisLinksGroup.bit-has-clicked a:not(.bit-clicked),'
                          . '.SVGSpiral2026 .spiral26AxisLinksGroup.bit-loading a:not(.bit-clicked){opacity:1;}}';

                    return $base . '</style>';
                },
                $svg,
                1
            );

            // ── 4. IntersectionObserver: dispara animação só quando a SVG
            // entra na viewport. Sem isso, a espiral termina o ciclo de fade
            // antes do usuário rolar até ela e o usuário só vê o estado final.
            // Remover `Spiral26Animate` do <svg root> para que o JS abaixo
            // adicione no momento certo.
            $svg = preg_replace_callback(
                '/(<svg\b[^>]*\bclass=")([^"]*)(")/',
                static function ( $m ) {
                    $cls = preg_replace( '/\bSpiral26Animate\b/', '', $m[2] );
                    $cls = preg_replace( '/\s+/', ' ', trim( $cls ) );
                    return $m[1] . $cls . $m[3];
                },
                $svg,
                1
            );

            echo $svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

            // JS: observa a entrada do .SVGSpiral2026 na viewport e adiciona
            // a classe `Spiral26Animate` para começar a animação. Re-execução
            // segura: já adicionada → no-op. Carregado uma vez por página.
            static $observer_printed = false;
            if ( ! $observer_printed ) {
                $observer_printed = true;
                ?>
                <script id="bit-espiral-viewport-observer">
                (function(){
                  if (window.__bitEspiralViewportObserver) return;
                  window.__bitEspiralViewportObserver = true;

                  function start(){
                    var svgs = document.querySelectorAll('svg.SVGSpiral2026:not(.Spiral26Animate)');
                    if (!svgs.length) return;
                    if (!('IntersectionObserver' in window)) {
                      // Fallback: dispara imediatamente se IO não suportado
                      svgs.forEach(function(s){ s.classList.add('Spiral26Animate'); });
                      return;
                    }
                    var io = new IntersectionObserver(function(entries){
                      entries.forEach(function(entry){
                        if (entry.isIntersecting && entry.intersectionRatio >= 0.2) {
                          entry.target.classList.add('Spiral26Animate');
                          io.unobserve(entry.target);
                        }
                      });
                    }, { threshold: [0, 0.2, 0.5] });
                    svgs.forEach(function(s){ io.observe(s); });
                  }

                  if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', start);
                  } else {
                    start();
                  }
                })();
                </script>
                <script id="bit-espiral-click-glow">
                (function(){
                  if (window.__bitEspiralClickGlow) return;
                  window.__bitEspiralClickGlow = true;

                  var FX_DURATION       = 400;
                  var NAV_DELAY         = 50;
                  var LOADING_SAFETY_MS = 8000; // safety net se navegação for cancelada
                  var prefersReduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

                  document.addEventListener('click', function(ev){
                    var link = ev.target && ev.target.closest
                      ? ev.target.closest('.SVGSpiral2026 .spiral26AxisLinksGroup a')
                      : null;
                    if (!link) return;

                    var svgRoot = link.closest('svg.SVGSpiral2026');
                    if (svgRoot && svgRoot.getAttribute('data-bit-fx-click') === '0') {
                      return; // Efeito desligado neste widget via Elementor
                    }
                    var loadingEnabled = !svgRoot || svgRoot.getAttribute('data-bit-fx-loading') !== '0';

                    var group = link.parentNode;
                    if (!group) return;

                    // Reset estado anterior (cliques rápidos não acumulam)
                    var prev = group.querySelectorAll('a.bit-clicked, a.bit-loading');
                    for (var i = 0; i < prev.length; i++) {
                      prev[i].classList.remove('bit-clicked', 'bit-loading');
                    }
                    group.classList.remove('bit-loading');

                    // Limpa marcadores dos paths originais (.spiral26AxisBackground)
                    var svgRoot = link.closest('svg.SVGSpiral2026');
                    if (svgRoot) {
                      svgRoot.classList.remove('bit-clicked-svg', 'bit-loading-svg');
                      var oldTargets = svgRoot.querySelectorAll('.bit-glow-target, .bit-loading-target');
                      for (var k = 0; k < oldTargets.length; k++) {
                        oldTargets[k].classList.remove('bit-glow-target', 'bit-loading-target');
                      }
                    }

                    link.classList.add('bit-clicked');
                    group.classList.add('bit-has-clicked');

                    // Identifica o path original (.spiral26AxisBackground) correspondente
                    // ao eixo clicado via <use href="#eixo-N">.
                    //
                    // Estratégia GLOW cross-browser: SMIL <animate> nativo +
                    // overlay <path> clone no TOPO da DOM (não sobreposto por <use>).
                    //
                    // Por que: o <a><use> dentro de .spiral26AxisLinksGroup cobre
                    // visualmente o path original .spiral26AxisBackground. Animar
                    // o path original (que está embaixo) não tem efeito visual.
                    // Animar o <use> tem problema em WebKit (shadow DOM).
                    // Solução: clonar o path com mesmo `d` para uma camada acima.
                    var useEl = link.querySelector('use');
                    var pathId = useEl && (useEl.getAttribute('href') || useEl.getAttribute('xlink:href'));
                    var overlayPath = null;
                    if (pathId && pathId.charAt(0) === '#' && svgRoot) {
                      var origPath = svgRoot.querySelector('path' + pathId);
                      if (origPath) {
                        // Remove overlay anterior se houver
                        var oldOverlays = svgRoot.querySelectorAll('path.bit-fx-overlay');
                        for (var o = 0; o < oldOverlays.length; o++) oldOverlays[o].remove();
                        // Clona o path para overlay (mesmo d, mesma transformação)
                        overlayPath = origPath.cloneNode(false);
                        overlayPath.removeAttribute('id');
                        overlayPath.setAttribute('class', 'bit-fx-overlay');
                        overlayPath.setAttribute('pointer-events', 'none');
                        overlayPath.setAttribute('fill', 'transparent');
                        // Injeta SMIL <animate> para fill
                        var anim = document.createElementNS('http://www.w3.org/2000/svg', 'animate');
                        anim.setAttribute('class', 'bit-fx-anim');
                        anim.setAttribute('attributeName', 'fill');
                        anim.setAttribute('values', 'transparent;#ec4899;transparent');
                        anim.setAttribute('dur', '400ms');
                        anim.setAttribute('repeatCount', '1');
                        anim.setAttribute('fill', 'remove');
                        overlayPath.appendChild(anim);
                        // Adiciona como ÚLTIMO filho do SVG (renderiza por cima)
                        svgRoot.appendChild(overlayPath);
                        if (typeof anim.beginElement === 'function') {
                          try { anim.beginElement(); } catch(e) {}
                        }
                      }
                    }

                    // Reflow para reiniciar a animação caso mesmo eixo seja clicado de novo
                    void link.getBoundingClientRect();

                    // Se reduced-motion, apenas navega imediatamente
                    if (prefersReduce) return;

                    // Adia navegação para o usuário enxergar o glow
                    var href = link.getAttribute('href');
                    var target = link.getAttribute('target');
                    if (!href || target === '_blank' || ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.button !== 0) {
                      // deixa o browser tratar (nova aba, modificadores, etc.)
                      cleanup();
                      return;
                    }

                    ev.preventDefault();

                    // Após o glow one-shot terminar, entra em estado loading (pulso contínuo + dim outros)
                    if (loadingEnabled) {
                      setTimeout(function(){
                        link.classList.add('bit-loading');
                        group.classList.add('bit-loading');
                        if (overlayPath) {
                          // Remove animação anterior; injeta pulse contínuo
                          var glowAnims = overlayPath.querySelectorAll('animate.bit-fx-anim');
                          for (var a = 0; a < glowAnims.length; a++) glowAnims[a].remove();
                          var pulseAnim = document.createElementNS('http://www.w3.org/2000/svg', 'animate');
                          pulseAnim.setAttribute('class', 'bit-fx-anim');
                          pulseAnim.setAttribute('attributeName', 'fill');
                          pulseAnim.setAttribute('values', 'transparent;rgba(236,72,153,0.5);transparent');
                          pulseAnim.setAttribute('dur', '600ms');
                          pulseAnim.setAttribute('repeatCount', 'indefinite');
                          overlayPath.appendChild(pulseAnim);
                          if (typeof pulseAnim.beginElement === 'function') {
                            try { pulseAnim.beginElement(); } catch(e) {}
                          }
                        }
                      }, FX_DURATION);
                    }

                    setTimeout(function(){
                      try {
                        if (href.charAt(0) === '#') {
                          var el = document.querySelector(href);
                          if (el && el.scrollIntoView) {
                            el.scrollIntoView({ behavior: 'smooth', block: 'start' });
                            if (window.history && window.history.replaceState) {
                              window.history.replaceState(null, '', href);
                            }
                          } else {
                            window.location.href = href;
                          }
                        } else {
                          window.location.href = href;
                        }
                      } catch (e) {
                        window.location.href = href;
                      }
                    }, NAV_DELAY);

                    // Cleanup do glow one-shot (não toca em .bit-loading)
                    setTimeout(function(){
                      link.classList.remove('bit-clicked');
                      group.classList.remove('bit-has-clicked');
                      if (svgRoot) svgRoot.classList.remove('bit-clicked-svg');
                    }, FX_DURATION + 50);

                    // Safety net: se navegação for cancelada (back, mesma página, etc.),
                    // remove o estado loading depois de N segundos
                    setTimeout(cleanup, LOADING_SAFETY_MS);

                    function cleanup(){
                      link.classList.remove('bit-clicked', 'bit-loading');
                      group.classList.remove('bit-has-clicked', 'bit-loading');
                      if (svgRoot) {
                        svgRoot.classList.remove('bit-clicked-svg', 'bit-loading-svg');
                        var t = svgRoot.querySelectorAll('.bit-glow-target, .bit-loading-target');
                        for (var j = 0; j < t.length; j++) t[j].classList.remove('bit-glow-target', 'bit-loading-target');
                        // Remove overlay paths + SMIL <animate> nodes
                        var overlays = svgRoot.querySelectorAll('path.bit-fx-overlay');
                        for (var oi = 0; oi < overlays.length; oi++) overlays[oi].remove();
                        var anims = svgRoot.querySelectorAll('animate.bit-fx-anim');
                        for (var k = 0; k < anims.length; k++) anims[k].remove();
                      }
                    }
                  }, true);

                  // Limpa estado loading ao restaurar via bfcache (back/forward cache)
                  window.addEventListener('pageshow', function(ev){
                    if (ev.persisted) {
                      var groups = document.querySelectorAll('.SVGSpiral2026 .spiral26AxisLinksGroup');
                      for (var i = 0; i < groups.length; i++) {
                        groups[i].classList.remove('bit-loading', 'bit-has-clicked');
                        var links = groups[i].querySelectorAll('a.bit-clicked, a.bit-loading');
                        for (var j = 0; j < links.length; j++) {
                          links[j].classList.remove('bit-clicked', 'bit-loading');
                        }
                      }
                    }
                  });
                })();
                </script>
                <?php
            }
        }

    } // end class Bureau_Espiral_Widget

    $widgets_manager->register( new Bureau_Espiral_Widget() );
} ); // end add_action
