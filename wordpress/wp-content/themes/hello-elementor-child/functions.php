<?php
/**
 * Theme functions and definitions
 *
 * @package HelloElementorChild
 * @author  Daniel Cambría + Warp
 * @version 2.2.1
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Módulos PHP específicos
require_once get_stylesheet_directory() . '/inc/events-calendar.php';
require_once get_stylesheet_directory() . '/inc/page-contato.php';

/**
 * ============================================================================
 * THEME SETUP
 * ============================================================================
 */

/**
 * Enqueue parent and child theme styles + módulos CSS condicionais
 */
add_action('wp_enqueue_scripts', 'hello_elementor_child_enqueue_scripts');
function hello_elementor_child_enqueue_scripts() {
    $theme_uri = get_stylesheet_directory_uri();
    $ver       = wp_get_theme()->get('Version');

    // 1. Parent theme
    wp_enqueue_style(
        'hello-elementor-parent',
        get_template_directory_uri() . '/style.css'
    );

    // 2. Child theme — vars :root globais
    wp_enqueue_style(
        'hello-elementor-child',
        get_stylesheet_directory_uri() . '/style.css',
        ['hello-elementor-parent'],
        $ver
    );

    // 3. Base global (sempre)
    wp_enqueue_style(
        'conc-base',
        "$theme_uri/css/base.css",
        ['hello-elementor-child'],
        $ver
    );

    // 4. Header & Menu (sempre)
    wp_enqueue_style(
        'conc-header-menu',
        "$theme_uri/css/header-menu.css",
        ['conc-base'],
        $ver
    );

    // 5. Plugin: The Events Calendar — CSS condicional (só em páginas com TEC)
    if (class_exists('Tribe__Events__Main') && bureau_it_page_uses_tec()) {
        wp_enqueue_style(
            'conc-tec',
            "$theme_uri/css/plugins/tec.css",
            ['conc-base'],
            $ver
        );
    }

    // 6. Plugin: JetEngine
    if (class_exists('Jet_Engine')) {
        wp_enqueue_style(
            'conc-jetengine',
            "$theme_uri/css/plugins/jetengine.css",
            ['conc-base'],
            $ver
        );
    }

    // 7. Plugin: Complianz
    if (class_exists('COMPLIANZ')) {
        wp_enqueue_style(
            'conc-complianz',
            "$theme_uri/css/plugins/complianz.css",
            ['conc-base'],
            $ver
        );
    }

    // 8. Home page
    if (is_front_page() || is_home()) {
        wp_enqueue_style(
            'conc-page-home',
            "$theme_uri/css/pages/home.css",
            ['conc-base'],
            $ver
        );
    }

    // 9. Artistas / Linha das Artes (post types individuais)
    if (is_singular(['linha-das-artes', 'artistas', 'artistas-infantis'])) {
        wp_enqueue_style(
            'conc-page-artistas',
            "$theme_uri/css/pages/artistas.css",
            ['conc-base'],
            $ver
        );
    }

    // 10. Estudos (listagem e post type)
    if (is_singular('estudos') || is_post_type_archive('estudos') || is_page('estudos')) {
        wp_enqueue_style(
            'conc-page-estudos',
            "$theme_uri/css/pages/estudos.css",
            ['conc-base'],
            $ver
        );
    }

    // 11. Publicações
    if (is_page('publicacoes') || is_page('publications')) {
        wp_enqueue_style(
            'conc-page-publicacoes',
            "$theme_uri/css/pages/publicacoes.css",
            ['conc-base'],
            $ver
        );
    }

    // 12. Contato
    if (is_page('contato')) {
        wp_enqueue_style(
            'conc-page-contato',
            "$theme_uri/css/pages/contato.css",
            ['conc-base'],
            $ver
        );
    }

    // Slick.js via footer (mantido do original)
    add_action('wp_footer', 'bureau_it_print_slick_js', 1);
}

/**
 * Print slick.js script tag directly to footer (bypasses WP queue drop)
 */
function bureau_it_print_slick_js() {
    if (!wp_script_is('jquery-slick', 'done')) {
        $src = content_url('plugins/jet-engine/assets/lib/slick/slick.min.js');
        echo '<script src="' . esc_url($src) . '?ver=1.8.1" id="jquery-slick-js"></script>' . "\n";
    }
}

/**
 * ============================================================================
 * CUSTOM FONTS: Franie, Just Sans, Roboto (local, no Google Fonts)
 * Roboto: subset Latin, wght 400-700 (36 KB vs 204 KB Variable Font original)
 * ============================================================================
 *
 * @since 1.6.0
 */

/**
 * Register @font-face declarations via inline CSS
 */
add_action('wp_enqueue_scripts', 'bureau_it_custom_fonts_css');
function bureau_it_custom_fonts_css() {
    $fonts_url  = get_stylesheet_directory_uri() . '/fonts';
    $fonts_woff = $fonts_url . '/woff2';

    $css = "
@font-face {
    font-family: 'Franie';
    src: url('{$fonts_woff}/Franie-Regular.woff2') format('woff2'),
         url('{$fonts_url}/Franie-Regular.otf') format('opentype');
    font-weight: 400;
    font-style: normal;
    font-display: swap;
}

@font-face {
    font-family: 'Franie';
    src: url('{$fonts_woff}/Franie-Italic.woff2') format('woff2'),
         url('{$fonts_url}/Franie-Italic.otf') format('opentype');
    font-weight: 400;
    font-style: italic;
    font-display: swap;
}

@font-face {
    font-family: 'Franie';
    src: url('{$fonts_woff}/Franie-Bold.woff2') format('woff2'),
         url('{$fonts_url}/Franie-Bold.otf') format('opentype');
    font-weight: 700;
    font-style: normal;
    font-display: swap;
}

@font-face {
    font-family: 'Franie';
    src: url('{$fonts_woff}/Franie-BoldItalic.woff2') format('woff2'),
         url('{$fonts_url}/Franie-BoldItalic.otf') format('opentype');
    font-weight: 700;
    font-style: italic;
    font-display: swap;
}

@font-face {
    font-family: 'Just Sans';
    src: url('{$fonts_woff}/JustSans-Regular.woff2') format('woff2'),
         url('{$fonts_url}/JustSans-Regular.otf') format('opentype');
    font-weight: 400;
    font-style: normal;
    font-display: swap;
}

@font-face {
    font-family: 'Just Sans';
    src: url('{$fonts_woff}/JustSans-ExBold.woff2') format('woff2'),
         url('{$fonts_url}/JustSans-ExBold.otf') format('opentype');
    font-weight: 800;
    font-style: normal;
    font-display: swap;
}

@font-face {
    font-family: 'Roboto';
    src: url('{$fonts_woff}/Roboto-latin-w400-700.woff2') format('woff2');
    font-weight: 400 700;
    font-style: normal;
    font-display: swap;
    unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+2000-206F, U+2074, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD;
}
";

    wp_register_style('bureau-custom-fonts', false);
    wp_enqueue_style('bureau-custom-fonts');
    wp_add_inline_style('bureau-custom-fonts', $css);
}

/**
 * Preload critical fonts (Franie-Regular + JustSans-Regular + Roboto na espiral)
 *
 * Roboto é usada exclusivamente no SVG da espiral (--spiral2026-foreignobject-fontfamily).
 * Sem preload, o browser só descobre a necessidade da fonte ao renderizar o foreignObject,
 * causando FOUT que desloca os textos e gera CLS. O preload condicional evita carregar
 * Roboto em páginas que não exibem a espiral.
 *
 * @since 2.2.1
 */
add_action('wp_head', 'bureau_it_preload_critical_fonts', 2);
function bureau_it_preload_critical_fonts() {
    $fonts_woff = get_stylesheet_directory_uri() . '/fonts/woff2';
    echo '<link rel="preload" href="' . esc_url( $fonts_woff . '/Franie-Regular.woff2' ) . '" as="font" type="font/woff2" crossorigin="anonymous">' . "\n";
    echo '<link rel="preload" href="' . esc_url( $fonts_woff . '/JustSans-Regular.woff2' ) . '" as="font" type="font/woff2" crossorigin="anonymous">' . "\n";

    // Preload Roboto apenas em páginas que renderizam o widget espiral
    if ( bureau_it_page_has_espiral_widget() ) {
        echo '<link rel="preload" href="' . esc_url( $fonts_woff . '/Roboto-latin-w400-700.woff2' ) . '" as="font" type="font/woff2" crossorigin="anonymous">' . "\n";
    }
}

/**
 * Detecta se a página atual contém o widget bit-elementor-espiral no _elementor_data.
 *
 * Usa $wp_query->get_queried_object_id() — mais confiável que get_queried_object_id()
 * no contexto do wp_head, onde a query pode não estar completamente inicializada.
 *
 * @since 2.2.1
 * @return bool
 */
function bureau_it_page_has_espiral_widget(): bool {
    global $wp_query;
    $post_id = $wp_query ? $wp_query->get_queried_object_id() : 0;
    if ( ! $post_id ) {
        // Fallback: front page usa get_option
        if ( is_front_page() || is_home() ) {
            $post_id = (int) get_option( 'page_on_front' );
        }
    }
    if ( ! $post_id ) {
        return false;
    }
    $data = get_post_meta( $post_id, '_elementor_data', true );
    // Widget type registrado no Elementor como "bureau_espiral"
    return ! empty( $data ) && str_contains( $data, 'bureau_espiral' );
}

/**
 * Preload LCP background-image da homepage
 *
 * O elemento LCP da homepage é um background-image CSS num container Elementor
 * (attachment ID 89988). O browser não descobre background-images até o CSS ser
 * parseado e o layout calculado — causando LCP > 8 s. Preload explícito no <head>
 * (priority 1) instrui o browser a buscar a imagem junto com o HTML, antes do CSS.
 *
 * LIMITAÇÃO: URL hardcoded via wp_get_attachment_url() — se a imagem hero for
 * trocada no Elementor, atualizar o attachment ID abaixo (ou trocar para
 * um img widget com fetchpriority="high" para eliminar essa dependência).
 *
 * @since 2.2.0
 */
add_action( 'wp_head', 'bureau_it_preload_homepage_lcp', 1 );
function bureau_it_preload_homepage_lcp() {
    if ( ! ( is_front_page() || is_home() ) ) {
        return;
    }
    // Attachment ID do container hero (A-floresta-e-seus-misterios...)
    $attachment_id = 89988;
    $url = wp_get_attachment_url( $attachment_id );
    if ( ! $url ) {
        return;
    }
    echo '<link rel="preload" href="' . esc_url( $url ) . '" as="image" fetchpriority="high">' . "\n";
}

/**
 * Register custom fonts in Elementor font picker
 */
add_filter('elementor/fonts/additional_fonts', 'bureau_it_elementor_additional_fonts');
function bureau_it_elementor_additional_fonts($additional_fonts) {
    $additional_fonts['Franie']    = 'custom';
    $additional_fonts['Just Sans'] = 'custom';
    $additional_fonts['Roboto']    = 'custom';
    return $additional_fonts;
}

/**
 * Remove Google/system/earlyaccess font groups from Elementor — keep only custom
 */
add_filter('elementor/fonts/groups', 'bureau_it_elementor_font_groups');
function bureau_it_elementor_font_groups($groups) {
    return [
        'custom' => esc_html__('Custom', 'elementor'),
    ];
}

/**
 * Disable Google Fonts loading from Elementor
 */
add_filter('elementor/frontend/print_google_fonts', '__return_false');

/**
 * ============================================================================
 * VIEWPORT: Fix zoom restriction
 * ============================================================================
 *
 * @since 1.6.0
 */
add_filter('hello_elementor_viewport_content', 'bureau_it_fix_viewport_zoom');
function bureau_it_fix_viewport_zoom($content) {
    return 'width=device-width, initial-scale=1';
}

/**
 * ============================================================================
 * ADMIN CUSTOMIZATIONS
 * ============================================================================
 */

/**
 * Add custom admin CSS
 */
add_action('admin_enqueue_scripts', 'bureau_it_admin_css');
function bureau_it_admin_css() {
    wp_enqueue_style('bureau-it-admin-css', get_stylesheet_directory_uri() . '/admin-style.css');
}

/**
 * Enqueue admin bar CSS no frontend (somente quando admin bar visível)
 */
add_action('wp_enqueue_scripts', 'bureau_it_enqueue_admin_bar_css', 999);
function bureau_it_enqueue_admin_bar_css() {
    if (!is_admin_bar_showing()) {
        return;
    }
    wp_enqueue_style(
        'conc-admin-bar',
        get_stylesheet_directory_uri() . '/css/admin/admin-bar.css',
        ['hello-elementor-child'],
        wp_get_theme()->get('Version')
    );
}

/**
 * ============================================================================
 * JETENGINE CUSTOMIZATIONS
 * ============================================================================
 */
add_filter('jet-engine/maps-listings/data-settings', function($settings) {
    $settings['clustererImg'] = get_stylesheet_directory_uri() . '/markerclusterer-img/m';
    return $settings;
});

/**
 * Alphabet filter support for CCT queries (JetSmartFilters + JetEngine CCT)
 *
 * JetSmartFilters stores the selected letter in final_query['alphabet'],
 * but CCT_Query::_get_items() ignores it. This hook converts the letter
 * to a LIKE condition on item_title so the alphabet filter works with CCT.
 *
 * @since 1.5.1
 */
add_action('jet-engine/query-builder/query/before-get-items', 'bureau_it_cct_alphabet_filter', 10, 2);
function bureau_it_cct_alphabet_filter($query, $cached) {
    if ($cached) {
        return;
    }

    if ($query->query_type !== 'custom-content-type') {
        return;
    }

    if (empty($query->final_query['alphabet'])) {
        return;
    }

    $letter = $query->final_query['alphabet'];
    if (is_array($letter)) {
        $letter = reset($letter);
    }
    $letter = mb_substr(sanitize_text_field($letter), 0, 1);

    if (empty($letter)) {
        return;
    }

    if (!isset($query->final_query['args']) || !is_array($query->final_query['args'])) {
        $query->final_query['args'] = array();
    }

    $query->final_query['args'][] = array(
        'field'    => 'item_title',
        'operator' => 'LIKE',
        'value'    => $letter . '%',
    );

    unset($query->final_query['alphabet']);
}

/**
 * ============================================================================
 * SVG SHORTCODE - Externalizar SVGs do HTML
 * ============================================================================
 *
 * Carrega SVGs de arquivos externos no diretorio svg/ do tema filho,
 * eliminando SVGs inline do HTML (economia de ~178KB na home).
 *
 * Uso: [bureau_svg name="logo-br"]
 *      [bureau_svg name="logo-br" class="my-class" id="my-id"]
 *      [bureau_svg name="spiral-2026" class="SVGSpiral2026"]
 *
 * @since 1.5.0
 */
add_shortcode('bureau_svg', 'bureau_it_svg_shortcode');
function bureau_it_svg_shortcode($atts) {
    $atts = shortcode_atts(array(
        'name'  => '',
        'class' => '',
        'id'    => '',
    ), $atts, 'bureau_svg');

    if (empty($atts['name'])) {
        return '';
    }

    $file = get_stylesheet_directory() . '/svg/' . sanitize_file_name($atts['name']) . '.svg';
    if (!file_exists($file)) {
        return '';
    }

    $svg = file_get_contents($file);
    if (empty($svg)) {
        return '';
    }

    if (!empty($atts['class'])) {
        if (preg_match('/\bclass="[^"]*"/', $svg)) {
            $svg = preg_replace('/\bclass="([^"]*)"/', 'class="$1 ' . esc_attr($atts['class']) . '"', $svg, 1);
        } else {
            $svg = preg_replace('/<svg\b/', '<svg class="' . esc_attr($atts['class']) . '"', $svg, 1);
        }
    }

    if (!empty($atts['id'])) {
        if (preg_match('/\bid="[^"]*"/', $svg)) {
            $svg = preg_replace('/\bid="[^"]*"/', 'id="' . esc_attr($atts['id']) . '"', $svg, 1);
        } else {
            $svg = preg_replace('/<svg\b/', '<svg id="' . esc_attr($atts['id']) . '"', $svg, 1);
        }
    }

    return $svg;
}


/**
 * Shortcode para logos como <img> com troca de idioma via WPML/CSS
 *
 * Usa <img src="logo.svg"> em vez de SVG inline, permitindo cache do browser
 * e reduzindo ~46KB por logo no HTML renderizado.
 * Troca BR/EN via CSS baseado no atributo lang do <html> (WPML).
 *
 * Uso: [bureau_logo wrapper_class="site-logo"]
 *      [bureau_logo wrapper_class="footer-logo" height="120"]
 *
 * @since 1.5.0
 */
add_shortcode('bureau_logo', 'bureau_it_logo_shortcode');
function bureau_it_logo_shortcode($atts) {
    $atts = shortcode_atts(array(
        'wrapper_class' => 'site-logo',
        'id_br'         => '',
        'id_en'         => '',
        'height'        => '',
    ), $atts, 'bureau_logo');

    $svg_uri = get_stylesheet_directory_uri() . '/svg/';
    $svg_dir = get_stylesheet_directory() . '/svg/';

    if (!file_exists($svg_dir . 'logo-br.svg')) {
        return '';
    }

    $wrapper = esc_attr($atts['wrapper_class']);
    $height_attr = !empty($atts['height']) ? ' height="' . esc_attr($atts['height']) . '"' : '';
    $id_br = !empty($atts['id_br']) ? ' id="' . esc_attr($atts['id_br']) . '"' : '';
    $id_en = !empty($atts['id_en']) ? ' id="' . esc_attr($atts['id_en']) . '"' : '';

    $output = '<div class="' . $wrapper . '">';
    $output .= '<img class="bureau-logo-br" src="' . esc_url($svg_uri . 'logo-br.svg') . '" alt="Uma Concertação pela Amazônia"' . $id_br . $height_attr . ' />';
    if (file_exists($svg_dir . 'logo-en.svg')) {
        $output .= '<img hidden class="bureau-logo-en" src="' . esc_url($svg_uri . 'logo-en.svg') . '" alt="A Concertation for the Amazon"' . $id_en . $height_attr . ' />';
    }
    $output .= '</div>';

    return $output;
}

/**
 * ============================================================================
 * PERFORMANCE: reCAPTCHA diferido — carrega sob demanda (interação/formulário)
 * ============================================================================
 *
 * O Elementor Pro injeta recaptcha/api.js em TODAS as páginas via wp_enqueue_scripts,
 * mesmo páginas sem formulário. O script pesa 360 KB (60% não utilizado) e bloqueia
 * o TTI e o LCP da home e outras páginas.
 *
 * Estratégia:
 * 1. Remover o script da fila padrão do WP (evita <script src> síncrono no <head>)
 * 2. Salvar a URL original do script
 * 3. Injetar um loader JS no footer que carrega o reCAPTCHA sob demanda:
 *    - Ao primeiro mouseenter/touchstart/keydown (interação geral)
 *    - Ao IntersectionObserver detectar um formulário Gravity Forms no viewport
 *    Ambos garantem que o reCAPTCHA esteja pronto antes do usuário submeter.
 *
 * Compatibilidade: Gravity Forms + Elementor Pro reCAPTCHA v3.
 * Não aplicar em /wp-admin/ ou quando WP_DEBUG está ativo no admin.
 *
 * @since 2.2.1
 */
/**
 * Intercepta o reCAPTCHA no wp_footer (antes de wp_print_footer_scripts).
 *
 * O Elementor Pro enfileira recaptcha/api.js via render_field() durante the_content,
 * portanto wp_enqueue_scripts:999 ainda não tem o handle. O wp_footer:1 é o primeiro
 * hook após the_content onde o handle já existe e pode ser removido da fila.
 *
 * A URL é capturada do registro WP antes do dequeue e passada ao loader inline.
 */
/**
 * Substitui o <script src="recaptcha/api.js"> síncrono por um loader JS inline.
 *
 * O Elementor Pro enfileira o reCAPTCHA durante o render do footer (wp_footer > 20),
 * impossibilitando wp_dequeue antes de wp_print_footer_scripts. Em vez disso,
 * usamos o filtro script_loader_tag — que é aplicado no momento da impressão —
 * para substituir o <script src> padrão pelo loader assíncrono sob demanda.
 *
 * @since 2.2.1
 */
add_filter( 'script_loader_tag', 'bureau_it_defer_recaptcha', 10, 2 );
function bureau_it_defer_recaptcha( string $tag, string $handle ): string {
    // O handle real do Elementor Pro é 'elementor-recaptcha_v3-api' (sem sufixo -js)
    if ( 'elementor-recaptcha_v3-api' !== $handle ) {
        return $tag;
    }

    // Extrair a URL do src do tag original
    if ( ! preg_match( '/src=[\'"]([^\'"]+)[\'"]/', $tag, $m ) ) {
        return $tag;
    }

    $url = esc_url( $m[1] );

    return '<script id="bureau-recaptcha-deferred">
(function(){
    var loaded=false;
    function load(){
        if(loaded)return;loaded=true;
        var s=document.createElement("script");
        s.src=' . wp_json_encode( $url ) . ';
        s.async=true;
        document.head.appendChild(s);
    }
    ["mouseenter","touchstart","keydown","scroll"].forEach(function(ev){
        document.addEventListener(ev,load,{once:true,passive:true});
    });
    if("IntersectionObserver" in window){
        var o=new IntersectionObserver(function(es){es.forEach(function(e){if(e.isIntersecting)load();});},{rootMargin:"200px"});
        document.querySelectorAll(".gform_wrapper,.elementor-form").forEach(function(f){o.observe(f);});
    }
})();
</script>' . "\n";
}

/**
 * ============================================================================
 * PERFORMANCE: Dequeue CSS/JS desnecessarios na Home
 * ============================================================================
 *
 * Remove assets nao utilizados na homepage para reduzir requests.
 * Nota: CSS do The Events Calendar mantido (sera usado em proxima publicacao).
 *
 * @since 1.5.0
 */
add_action('wp_enqueue_scripts', 'bureau_it_dequeue_homepage_assets', 999);
function bureau_it_dequeue_homepage_assets() {
    if (!is_front_page()) {
        return;
    }

    // Chosen.js: 3 plugins registram chosen (TEC, GravityForms, JetSearch)
    wp_dequeue_style('tribe-events-chosen-style');
    wp_dequeue_script('tribe-events-chosen-jquery');
    wp_dequeue_style('gform_chosen');
    wp_dequeue_script('gform_chosen');
    wp_dequeue_style('jquery-chosen');
    wp_dequeue_script('jquery-chosen');

    // Slick.js: Elementor ja tem Swiper nativo
    wp_dequeue_script('jet-slick');
    wp_dequeue_script('jquery-slick');

    // TEC Pro Swiper (34 KB, 90% unused): usado apenas na view de mapa de eventos.
    // A home exibe listagem de eventos, não o mapa — Swiper é desnecessário aqui.
    wp_dequeue_script('tribe-swiper');
    wp_deregister_script('tribe-swiper');
}

// Dequeue tambem no wp_print_footer_scripts (JetEngine/JetSearch podem enfileirar tarde)
add_action('wp_print_footer_scripts', 'bureau_it_dequeue_homepage_assets', 1);

/**
 * Conditional dequeue: jet-elements JS and jet-smart-filters on pages that don't use them
 *
 * Checks _elementor_data for JetElements widgets requiring JS.
 * Economy: ~59KB on pages like 4-amazonias.
 *
 * @since 1.6.0
 */
add_action('wp_enqueue_scripts', 'bureau_it_dequeue_unused_jet_assets', 999);
function bureau_it_dequeue_unused_jet_assets() {
    if (is_admin() || !is_singular()) {
        return;
    }

    $post_id = get_the_ID();
    if (!$post_id) {
        return;
    }

    $data = get_post_meta($post_id, '_elementor_data', true);
    if (empty($data)) {
        return;
    }

    // JetElements widgets that require JS (sliders, carousels, etc.)
    $jet_js_widgets = [
        'jet-slider',
        'jet-carousel',
        'jet-posts',
        'jet-animated-text',
        'jet-testimonials',
        'jet-image-comparison',
        'jet-countdown-timer',
        'jet-accordion',
        'jet-tabs',
        'jet-toggle',
    ];

    $needs_jet_js = false;
    foreach ($jet_js_widgets as $widget) {
        if (strpos($data, '"widgetType":"' . $widget . '"') !== false) {
            $needs_jet_js = true;
            break;
        }
    }

    if (!$needs_jet_js) {
        wp_dequeue_script('jet-elements');
        wp_deregister_script('jet-elements');
    }

    // Dequeue JetSmartFilters if page doesn't use filter widgets
    $filter_widgets = ['jet-smart-filters-', 'jsfb-'];
    $needs_filters  = false;
    foreach ($filter_widgets as $widget) {
        if (strpos($data, $widget) !== false) {
            $needs_filters = true;
            break;
        }
    }

    if (!$needs_filters) {
        wp_dequeue_script('jet-smart-filters');
        wp_deregister_script('jet-smart-filters');
    } else {
        // Força CSS no <head> via fila normal — compatível com WP Rocket.
        // O mecanismo padrão do JSF imprime CSS mid-body via wp_print_styles(),
        // que o WP Rocket remove ao combinar CSS. Forçar aqui garante que o
        // <link> seja emitido pelo wp_head() antes do cache ser gerado.
        if (function_exists('jet_smart_filters') && wp_style_is('jet-smart-filters', 'registered')) {
            wp_enqueue_style('jet-smart-filters');
            jet_smart_filters()->filters_not_used = false; // evita print mid-body duplicado
        }
    }
}

/**
 * ============================================================================
 * PERFORMANCE: Lazy loading para imagens
 * ============================================================================
 *
 * Adiciona loading="lazy" para imagens que nao possuem o atributo.
 *
 * @since 1.5.0
 */
add_filter('wp_get_attachment_image_attributes', 'bureau_it_optimize_image_loading', 10, 3);
function bureau_it_optimize_image_loading($attr, $attachment, $size) {
    // banner-home usa slick carousel com infinite clone — lazy loading quebra imagens em slides clonados
    if (get_post_type() === 'banner-home') {
        $attr['loading'] = 'eager';
        return $attr;
    }
    if (!isset($attr['loading'])) {
        $attr['loading'] = 'lazy';
    }
    return $attr;
}

/**
 * ============================================================================
 * ELEMENTOR PRO BUG FIX: SVG Links
 * ============================================================================
 *
 * Fix para bug do Elementor Pro 3.33.x onde o módulo Off-Canvas falha ao
 * processar links dentro de elementos SVG. O erro ocorre porque links SVG
 * retornam SVGAnimatedString (objeto) ao invés de string para a propriedade
 * .href, e o Elementor tenta chamar .includes() que não existe nesse objeto.
 *
 * Este fix adiciona o método .includes() ao prototype de SVGAnimatedString.
 *
 * @since 1.4.1
 * @see https://github.com/elementor/elementor/issues/XXXXX
 */
add_action('wp_head', 'bureau_it_fix_elementor_svg_links', 1);
function bureau_it_fix_elementor_svg_links() {
    ?>
    <script>
    // Fix: Elementor Pro Off-Canvas + SVG links compatibility
    // SVGAnimatedString doesn't have .includes(), causing TypeError
    if (typeof SVGAnimatedString !== 'undefined' && !SVGAnimatedString.prototype.includes) {
        SVGAnimatedString.prototype.includes = function(searchString) {
            return this.baseVal.includes(searchString);
        };
    }
    </script>
    <?php
}

/**
 * ============================================================================
 * MULTISITE: Shared uploads symlinks (subsite blog_id=2)
 * ============================================================================
 *
 * Subsite /cultura/ generates upload URLs with /sites/2/YYYY/MM/ but actual
 * files are in /wp-content/uploads/YYYY/MM/ (shared from single-site era).
 * Creates symlinks sites/2/YYYY -> ../../YYYY on first request if missing.
 *
 * @since 1.6.1
 */
add_action('init', 'bureau_it_ensure_shared_upload_symlinks', 1);
function bureau_it_ensure_shared_upload_symlinks() {
    if (!is_multisite() || get_current_blog_id() !== 2) {
        return;
    }

    $upload_dir = ABSPATH . 'wp-content/uploads';
    $sites_dir  = "$upload_dir/sites/2";

    if (!is_dir($sites_dir)) {
        @mkdir($sites_dir, 0755, true);
    }

    foreach (glob("$upload_dir/20[0-9][0-9]", GLOB_ONLYDIR) as $year_dir) {
        $year = basename($year_dir);
        $link = "$sites_dir/$year";
        if (!file_exists($link)) {
            @symlink("../../$year", $link);
        }
    }
}

/**
 * Preload LCP image de /cultura/ (blog_id=2)
 *
 * A imagem hero Sapopema (attachment ID 89985 no blog 1) é um image widget
 * Elementor — o Lighthouse a identifica como LCP. Preload explícito no <head>
 * instrui o browser a buscar a imagem antes do CSS ser parseado.
 *
 * O preload usa switch_to_blog(1) porque o attachment pertence ao blog 1
 * (imagens compartilhadas via Network Media Library).
 *
 * @since 2.2.0
 */
add_action( 'wp_head', 'bureau_it_cultura_preload_lcp', 1 );
function bureau_it_cultura_preload_lcp() {
    if ( ! is_multisite() || get_current_blog_id() !== 2 ) {
        return;
    }
    // Só preload na front page do blog 2 (/cultura/)
    if ( ! ( is_front_page() || is_home() ) ) {
        return;
    }
    // Attachment ID 89985 vive no blog 1 (blog 2 compartilha via NML)
    switch_to_blog( 1 );
    $url = wp_get_attachment_url( 89985 );
    restore_current_blog();
    if ( ! $url ) {
        return;
    }
    echo '<link rel="preload" href="' . esc_url( $url ) . '" as="image" fetchpriority="high">' . "\n";
}

/**
 * Fix LCP /cultura/: remove loading="lazy" da imagem hero via output buffer
 *
 * O Elementor injeta loading="lazy" diretamente em image-size.php, contornando
 * o filtro wp_get_attachment_image_attributes. O output buffer é o único mecanismo
 * confiável para alterar o atributo no HTML final.
 *
 * Aplica apenas no blog 2 (/cultura/). blog_id=2 é fixo na criação do subsite —
 * se o banco for reimportado em novo ambiente, verificar que /cultura/ ainda é blog 2.
 *
 * LIMITAÇÃO CONHECIDA: o match usa o nome de arquivo 'Sapopema'. Se a imagem hero
 * for substituída no Elementor por outra com nome diferente, o fix para de funcionar
 * silenciosamente. Alternativa mais robusta: usar Custom Attributes do Elementor Pro
 * (Advanced > Custom Attributes: fetchpriority|high, loading|eager) diretamente no widget.
 *
 * @since 2.1.0
 * @requires PHP 8.0+
 */
add_action('wp', 'bureau_it_cultura_lcp_ob_start');
function bureau_it_cultura_lcp_ob_start() {
    if ( ! is_multisite() || get_current_blog_id() !== 2 ) {
        return;
    }
    ob_start( 'bureau_it_cultura_lcp_ob_callback' );
}

function bureau_it_cultura_lcp_ob_callback( $html ) {
    // Early-return para evitar regex em strings vazias ou sem imagens
    if ( empty( $html ) || ! str_contains( $html, 'Sapopema' ) ) {
        return $html;
    }
    return preg_replace(
        '/(<img\s[^>]*Sapopema[^>]*)\sloading="lazy"([^>]*>)/i',
        '$1 loading="eager" fetchpriority="high"$2',
        $html,
        1 // apenas a primeira ocorrência
    );
}

/**
 * Fix CLS 0.304 em /eventos-calendario/ — The Events Calendar (TEC)
 *
 * Causa raiz (2 fases):
 *
 * Fase 1 — TEC breakpoints JS adiciona `tribe-common--breakpoint-medium` ao
 * container após o render do PHP, ativando o seletor CSS do plugin que muda
 * min-height de 600px → 700px (views-skeleton.css). Correção: adicionar a
 * classe já no server-render via filtro PHP para eliminar o shift CSS.
 *
 * Fase 2 — `live_refresh: true` no view-data faz o TEC JS disparar uma
 * chamada REST que re-renderiza o HTML interno do container na carga inicial.
 * O PHP já renderizou o conteúdo correto — o re-fetch é desnecessário e
 * causa shift de layout ao substituir o DOM. Correção: desativar live_refresh.
 *
 * @since 2.1.0
 */

// Fix Fase 1: pré-adicionar classe de breakpoint no server-render
add_filter( 'tribe_events_views_v2_view_container_classes', 'bureau_it_tec_cls_breakpoint_classes' );
function bureau_it_tec_cls_breakpoint_classes( $classes ) {
    // Assume desktop (≥ 768px) — elimina shift da classe adicionada pelo JS
    if ( ! in_array( 'tribe-common--breakpoint-medium', $classes, true ) ) {
        $classes[] = 'tribe-common--breakpoint-xsmall';
        $classes[] = 'tribe-common--breakpoint-medium';
        $classes[] = 'tribe-common--breakpoint-full';
    }
    return $classes;
}

// Fix Fase 2: desabilitar live_refresh na carga inicial (PHP já renderizou o conteúdo correto)
add_filter( 'tribe_events_views_v2_view_data', 'bureau_it_tec_cls_disable_live_refresh' );
function bureau_it_tec_cls_disable_live_refresh( $data ) {
    $data['live_refresh'] = false;
    return $data;
}

// Fix Fase 3: CSS crítico inline no <head> para eliminar CLS causado por
// common-skeleton.css e views-skeleton.css injetados no <body> pelo shortcode TEC.
// O problema: ambos os skeleton CSS carregam DENTRO do <body> (linhas ~660-666),
// portanto suas regras chegam APÓS o first paint sob CPU throttle, causando:
//   - Shift do container (padding 19.5px→42px, min-height 0→700px)
//   - Shift do header TEC (128px→156px): negative margin no mobile, margin:0 no breakpoint-medium
//   - Shift da nav: padding-top spacer-4→spacer-6
//   - Datepicker mobile/desktop toggle
//   - Loader, top-bar, events-bar margin e flex layout changes
// Solução: replicar TODOS os valores finais do estado breakpoint-full+medium no <head>
// (priority 1), tornando o layout estável desde o primeiro byte renderizado.
// Como o PHP (Fase 1) já pré-adiciona as classes breakpoint-*, os seletores
// com .tribe-common--breakpoint-medium e .tribe-common--breakpoint-full já
// se aplicam imediatamente, sem esperar pelo skeleton CSS do body.
add_action( 'wp_head', 'bureau_it_tec_cls_critical_inline_css', 1 );
function bureau_it_tec_cls_critical_inline_css() {
    // Aplica em toda página onde o plugin TEC estiver ativo
    // (inclui shortcodes em páginas comuns, não apenas /eventos/)
    if ( ! class_exists( 'Tribe__Events__Main' ) ) {
        return;
    }
    ?>
    <style id="bureau-it-tec-cls-fix">
    /* ================================================================
       TEC CLS Fix — Bureau IT
       Replica os valores finais do estado breakpoint-full + breakpoint-medium
       no <head> para eliminar layout shifts causados por skeleton CSS
       injetado no <body> pelo shortcode TEC (Assets.php print: true).
       PHP (Fase 1) já pré-adiciona as classes ao container no server-render.
       ================================================================ */

    /* --- common-skeleton.css: l-container padding ---
       Base: --tec-grid-gutter-page-small = 19.5px
       Final (breakpoint-medium): --tec-grid-gutter-page = 42px */
    .tribe-common .tribe-common-l-container {
        padding-left: 42px !important;
        padding-right: 42px !important;
    }
    .tribe-common--breakpoint-medium.tribe-common .tribe-common-l-container {
        padding-left: 42px !important;
        padding-right: 42px !important;
    }

    /* --- views-skeleton.css: l-container min-height e padding ---
       Base: min-height 600px, padding-top 64px, padding-bottom 80px
       Final: min-height 0 (override), padding-top 96px, padding-bottom 160px */
    .tribe-events .tribe-events-l-container {
        min-height: 0 !important;
        padding-top: 96px !important;
        padding-bottom: 160px !important;
    }
    .tribe-common--breakpoint-medium.tribe-events .tribe-events-l-container {
        min-height: 0 !important;
        padding-top: 96px !important;
        padding-bottom: 160px !important;
    }

    /* --- views-skeleton.css: tribe-events-header layout ---
       Base (mobile): margin: 0 calc(-19.5px); padding: 0 19.5px spacer-3
       Final (breakpoint-medium): margin: 0; padding: 0
       Esta mudança é responsável pelo shift de altura 128px→156px do header. */
    .tribe-events .tribe-events-header {
        align-items: center;
        display: flex;
        flex-direction: row-reverse;
        flex-wrap: wrap;
        justify-content: space-between;
        margin: 0;
        padding: 0;
        position: relative;
    }
    .tribe-common--breakpoint-medium.tribe-events .tribe-events-header {
        margin: 0;
        padding: 0;
    }
    .tribe-common--breakpoint-medium.tribe-events .tribe-events-header--has-event-search {
        flex-direction: row;
    }
    .tribe-common--breakpoint-medium.tribe-events .tribe-events-header--has-event-search .tribe-events-header__events-bar {
        margin-left: 0;
        width: 100%;
    }
    .tribe-common--breakpoint-medium.tribe-events .tribe-events-header--has-event-search .tribe-events-header__top-bar {
        width: 100%;
    }

    /* --- views-skeleton.css: top-bar flex layout ---
       Final (breakpoint-medium): flex row, wrap */
    .tribe-common--breakpoint-medium.tribe-events .tribe-events-c-top-bar {
        align-items: center;
        display: flex;
        flex-direction: row;
        flex-wrap: wrap;
    }

    /* --- views-skeleton.css: events-bar margin ---
       Base: flex none, no margin
       Final (breakpoint-medium): margin-bottom spacer-7, margin-left spacer-3 */
    .tribe-common--breakpoint-medium.tribe-events .tribe-events-header__events-bar {
        margin-bottom: var(--tec-spacer-7);
        margin-left: var(--tec-spacer-3);
    }
    .tribe-common--breakpoint-medium.tribe-events .tribe-events-header__top-bar {
        margin-bottom: var(--tec-spacer-7);
    }

    /* --- views-skeleton.css: nav padding-top ---
       Base: --tec-spacer-4 = 16px
       Final (breakpoint-medium): --tec-spacer-6 = 24px */
    .tribe-events .tribe-events-c-nav {
        padding-top: var(--tec-spacer-6);
    }
    .tribe-common--breakpoint-medium.tribe-events .tribe-events-c-nav {
        padding-top: var(--tec-spacer-6);
    }

    /* --- views-skeleton.css: list-nav padding-top ---
       Base: --tec-spacer-5 = 20px
       Final (breakpoint-medium): --tec-spacer-7 = 32px */
    .tribe-events .tribe-events-calendar-list-nav {
        padding-top: var(--tec-spacer-7);
    }
    .tribe-common--breakpoint-medium.tribe-events .tribe-events-calendar-list-nav {
        padding-top: var(--tec-spacer-7);
    }

    /* --- views-skeleton.css: datepicker toggle (breakpoint-full ≥960px) ---
       Mobile element oculto, desktop visível — evita altura extra do datepicker */
    .tribe-common--breakpoint-full.tribe-events .tribe-events-c-top-bar__datepicker-mobile {
        display: none !important;
        visibility: hidden !important;
    }
    .tribe-common--breakpoint-full.tribe-events .tribe-events-c-top-bar__datepicker-desktop {
        display: block !important;
        visibility: visible !important;
    }

    /* --- views-skeleton.css: top-bar nav/today/actions visibility ---
       Ocultos sem breakpoint-medium; visíveis com. Afeta altura do header. */
    .tribe-common--breakpoint-medium.tribe-events .tribe-events-c-top-bar__nav {
        display: block !important;
        flex: none;
        visibility: visible;
    }
    .tribe-common--breakpoint-medium.tribe-events .tribe-events-c-top-bar__today-button {
        display: block !important;
        flex: none;
        margin-right: 15px;
        visibility: visible;
    }
    .tribe-common--breakpoint-medium.tribe-events .tribe-events-c-top-bar__actions {
        display: block !important;
        flex: none;
        margin-left: auto;
        visibility: visible;
    }

    /* --- views-skeleton.css: events-bar search-container ---
       Base: display none (mobile, absolute)
       Final (breakpoint-medium): display flex (inline) */
    .tribe-common--breakpoint-medium.tribe-events .tribe-events-c-events-bar__search-container {
        align-items: center;
        display: flex;
        flex: auto;
        padding: 0;
        position: static;
        z-index: auto;
    }
    .tribe-common--breakpoint-medium.tribe-events .tribe-events-c-events-bar__search {
        display: flex;
        flex: auto;
    }

    /* --- views-skeleton.css: subscribe dropdown float ---
       Final (breakpoint-medium): float right */
    .tribe-common--breakpoint-medium.tribe-events .tribe-events-c-subscribe-dropdown {
        float: right;
        margin-left: auto;
    }
    </style>
    <?php
}

