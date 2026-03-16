<?php
/**
 * Theme functions and definitions
 *
 * @package HelloElementorChild
 * @author  Daniel Cambría + Warp
 * @version 2.1.1
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
    src: url('{$fonts_woff}/Roboto-VariableFont_wdth,wght.woff2') format('woff2'),
         url('{$fonts_url}/Roboto-VariableFont_wdth,wght.ttf') format('truetype');
    font-weight: 100 900;
    font-style: normal;
    font-display: swap;
}

@font-face {
    font-family: 'Roboto';
    src: url('{$fonts_woff}/Roboto-Italic-VariableFont_wdth,wght.woff2') format('woff2'),
         url('{$fonts_url}/Roboto-Italic-VariableFont_wdth,wght.ttf') format('truetype');
    font-weight: 100 900;
    font-style: italic;
    font-display: swap;
}
";

    wp_register_style('bureau-custom-fonts', false);
    wp_enqueue_style('bureau-custom-fonts');
    wp_add_inline_style('bureau-custom-fonts', $css);
}

/**
 * Preload critical fonts (Franie-Regular + JustSans-Regular)
 *
 * @since 2.1.0
 */
add_action('wp_head', 'bureau_it_preload_critical_fonts', 2);
function bureau_it_preload_critical_fonts() {
    $fonts_woff = get_stylesheet_directory_uri() . '/fonts/woff2';
    echo '<link rel="preload" href="' . esc_url( $fonts_woff . '/Franie-Regular.woff2' ) . '" as="font" type="font/woff2" crossorigin="anonymous">' . "\n";
    echo '<link rel="preload" href="' . esc_url( $fonts_woff . '/JustSans-Regular.woff2' ) . '" as="font" type="font/woff2" crossorigin="anonymous">' . "\n";
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
// common-skeleton.css injetado no <body> pelo shortcode TEC.
// O problema: common-skeleton.css carrega DENTRO do <body> (linha ~613),
// portanto sua regra de padding chega APÓS o first paint sob CPU throttle.
// Solução: injetar CSS inline no <head> (priority 1) com os valores finais
// de padding, tornando o layout estável desde o primeiro byte renderizado.
add_action( 'wp_head', 'bureau_it_tec_cls_critical_inline_css', 1 );
function bureau_it_tec_cls_critical_inline_css() {
    // Aplica em toda página onde o plugin TEC estiver ativo
    // (inclui shortcodes em páginas comuns, não apenas /eventos/)
    if ( ! class_exists( 'Tribe__Events__Main' ) ) {
        return;
    }
    ?>
    <style id="bureau-it-tec-cls-fix">
    /* CLS fix: TEC skeleton CSS (common-skeleton.css, views-skeleton.css) é
       injetado no <body> pelo shortcode, chegando APÓS o first paint sob
       CPU throttle. Replicamos aqui os valores finais do estado ≥960px
       (breakpoint-full + breakpoint-medium), estabilizando o layout desde
       o primeiro byte renderizado. */

    /* Padding do container — valores finais do estado breakpoint-medium */
    .tribe-common .tribe-common-l-container {
        padding-left: 42px !important;
        padding-right: 42px !important;
    }
    .tribe-events .tribe-events-l-container {
        min-height: 0 !important;
        padding-top: 96px !important;
    }
    .tribe-common--breakpoint-medium.tribe-events .tribe-events-l-container {
        min-height: 0 !important;
        padding-top: 96px !important;
    }

    /* Datepicker toggle — estado breakpoint-full (≥960px):
       views-skeleton.css define display:none/block via .tribe-common--breakpoint-full,
       mas esse CSS só chega no <body>. Como o PHP já pré-adiciona a classe,
       replicamos as regras aqui para que o estado final já esteja ativo no
       first paint, evitando o shift de altura do header TEC (+120px). */
    .tribe-common--breakpoint-full.tribe-events .tribe-events-c-top-bar__datepicker-mobile {
        display: none !important;
        visibility: hidden !important;
    }
    .tribe-common--breakpoint-full.tribe-events .tribe-events-c-top-bar__datepicker-desktop {
        display: block !important;
        visibility: visible !important;
    }
    </style>
    <?php
}

