<?php
/**
 * Theme functions and definitions
 *
 * @package HelloElementorChild
 * @author  Daniel Cambría + Warp
 * @version 1.4.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * ============================================================================
 * THEME SETUP
 * ============================================================================
 */

/**
 * Enqueue parent and child theme styles
 */
add_action('wp_enqueue_scripts', 'hello_elementor_child_enqueue_scripts');
function hello_elementor_child_enqueue_scripts() {
    wp_enqueue_style('hello-elementor-parent', get_template_directory_uri() . '/style.css');
    wp_enqueue_style('hello-elementor-child', get_stylesheet_directory_uri() . '/style.css', array('hello-elementor-parent'));
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
 * ============================================================================
 * JETENGINE CUSTOMIZATIONS
 * ============================================================================
 */
add_filter('jet-engine/maps-listings/data-settings', function($settings) {
    $settings['clustererImg'] = get_stylesheet_directory_uri() . '/markerclusterer-img/m';
    return $settings;
});

/**
 * ============================================================================
 * EVENTS CALENDAR CUSTOMIZATIONS
 * ============================================================================
 */

// Carregar hooks customizados do TEC (localizados em tribe/hooks.php)
$tribe_hooks_file = get_stylesheet_directory() . '/tribe/hooks.php';
if (file_exists($tribe_hooks_file)) {
    require_once $tribe_hooks_file;
}

add_action('tribe_events_single_event_before_the_content', 'bureau_it_custom_event_display');
add_action('tribe_events_list_widget_before_the_event_title', 'bureau_it_custom_event_display');

function bureau_it_custom_event_display($event = null) {
    $event = $event ?? tribe_get_event();
    if (!$event) return;

    $categories = wp_get_post_terms($event->ID, 'tribe_events_cat');
    if (!is_wp_error($categories)) {
        foreach ($categories as $category) {
            if (strtolower($category->slug) === 'edital') {
                // Custom logic here
                break;
            }
        }
    }
}

add_filter('tec_events_get_time_range_separator', function($separator) {
    return (strpos($separator, '#') !== false) ? ' - ' : $separator;
});

add_filter('tec_events_get_date_time_separator', function($separator) {
    return (strpos($separator, '#') !== false) ? ' @ ' : $separator;
});

/**
 * Verifica se um evento é da categoria "edital"
 *
 * @param WP_Post|int $event Objeto do evento ou ID
 * @return bool
 */
function bureau_it_is_edital($event) {
    $event_id = is_object($event) ? $event->ID : $event;
    return has_term('edital', 'tribe_events_cat', $event_id);
}

/**
 * Filtro para modificar a data exibida dos eventos na lista
 * Funciona para eventos da categoria "edital" em qualquer view/shortcode
 *
 * @since 1.4.2
 */
add_filter('tribe_events_event_schedule_details', 'bureau_it_filter_edital_schedule', 10, 2);
function bureau_it_filter_edital_schedule($schedule, $event_id) {
    if (!bureau_it_is_edital($event_id)) {
        return $schedule;
    }

    $event = tribe_get_event($event_id);
    if (!$event || !isset($event->dates->end)) {
        return $schedule;
    }

    $end_date = $event->dates->end;
    $formatted = wp_date('j \d\e F', $end_date->getTimestamp());

    return '<span class="tribe-event-edital-date">Edital disponível até: ' . esc_html($formatted) . '</span>';
}

/**
 * Filtro alternativo para short_schedule_details (usado em widgets)
 *
 * @since 1.4.2
 */
add_filter('tribe_events_event_short_schedule_details', 'bureau_it_filter_edital_short_schedule', 10, 2);
function bureau_it_filter_edital_short_schedule($schedule, $event_id) {
    if (!bureau_it_is_edital($event_id)) {
        return $schedule;
    }

    $event = tribe_get_event($event_id);
    if (!$event || !isset($event->dates->end)) {
        return $schedule;
    }

    $end_date = $event->dates->end;
    $formatted = wp_date('j \d\e F', $end_date->getTimestamp());

    return '<span class="tribe-event-edital-date">Edital disponível até: ' . esc_html($formatted) . '</span>';
}

/**
 * Formata a data de um evento para exibição customizada
 *
 * Para editais: "Edital disponível até: X de Mês"
 * Para eventos: Formato padrão com correções de localização
 *
 * @param WP_Post $event Objeto do evento com propriedades do tribe_get_event()
 * @param bool    $add_prefix Adicionar "De " no início para eventos normais
 * @return string HTML formatado da data
 */
function bureau_it_format_event_date($event, $add_prefix = true) {
    if (bureau_it_is_edital($event)) {
        $end_date = $event->dates->end;
        $formatted = wp_date('j \d\e F', $end_date->getTimestamp());
        return '<span class="tribe-event-edital-date">Edital disponível até: ' . esc_html($formatted) . '</span>';
    }

    // Eventos normais: aplicar correções de formato
    $date_text = $event->schedule_details->value();

    // Correções de localização pt-BR
    $date_text = str_replace('Virtual Evento', 'Evento Virtual', $date_text);
    $date_text = str_replace('-3', 'Horário de São Paulo', $date_text);

    // Corrigir formato da data: "Month DD @ HH:MM" → "DD de Month às HH:MM"
    $date_text = preg_replace_callback(
        '/(\w+)\s(\d+)\s@\s(\d+):(\d+)/i',
        function($matches) {
            return $matches[2] . ' de ' . $matches[1] . ' às ' . $matches[3] . ':' . $matches[4];
        },
        $date_text
    );

    // Substituir separador de intervalo
    $date_text = str_replace('</span> - <span', '</span> até <span', $date_text);

    // Adicionar prefixo "De " se solicitado
    if ($add_prefix && strpos($date_text, '<span class="tribe-event-date-start">') !== false) {
        $date_text = 'De ' . $date_text;
    }

    return $date_text;
}

/**
 * ============================================================================
 * GRAVITY FORMS - FORMULÁRIO DE CONTATO
 * ============================================================================
 */
add_action('wp_head', 'bureau_it_gform_contact_css');
function bureau_it_gform_contact_css() {
    if (!is_page('contato') && !is_page('contact')) return;
    ?>
    <style>
    .gform_wrapper.gravity-theme .gfield_label,
    .gform_wrapper.gravity-theme label.gform-field-label { display:none!important }
    input#input_1_1,input#input_2_1{background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='17' viewBox='0 0 16 17'%3E%3Cpath d='M8 8.5C10.21 8.5 12 6.71 12 4.5C12 2.29 10.21 0.5 8 0.5C5.79 0.5 4 2.29 4 4.5C4 6.71 5.79 8.5 8 8.5ZM8 10.5C5.33 10.5 0 11.84 0 14.5V15.5C0 16.05 0.45 16.5 1 16.5H15C15.55 16.5 16 16.05 16 15.5V14.5C16 11.84 10.67 10.5 8 10.5Z' fill='%23B9BDCB'/%3E%3C/svg%3E") no-repeat 13px center!important;background-size:16px!important;padding-left:40px!important}
    input#input_1_2,input#input_2_2{background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='18' height='19' viewBox='0 0 18 19'%3E%3Cpath d='M14 8.5V2.5C14 1.4 13.1 0.5 12 0.5H6C4.9 0.5 4 1.4 4 2.5V4.5H2C0.9 4.5 0 5.4 0 6.5V16.5C0 17.6 0.9 18.5 2 18.5H7C7.55 18.5 8 18.05 8 17.5V14.5H10V17.5C10 18.05 10.45 18.5 11 18.5H16C17.1 18.5 18 17.6 18 16.5V10.5C18 9.4 17.1 8.5 16 8.5H14ZM4 16.5H2V14.5H4V16.5ZM4 12.5H2V10.5H4V12.5ZM4 8.5H2V6.5H4V8.5ZM8 12.5H6V10.5H8V12.5ZM8 8.5H6V6.5H8V8.5ZM8 4.5H6V2.5H8V4.5ZM12 12.5H10V10.5H12V12.5ZM12 8.5H10V6.5H12V8.5ZM12 4.5H10V2.5H12V4.5ZM16 16.5H14V14.5H16V16.5ZM16 12.5H14V10.5H16V12.5Z' fill='%23B9BDCB'/%3E%3C/svg%3E") no-repeat 13px center!important;background-size:17px!important;padding-left:40px!important}
    input#input_1_3,input#input_2_3{background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='24' height='25' viewBox='0 0 24 25'%3E%3Cpath d='M20 4.5H4C2.9 4.5 2 5.4 2 6.5V18.5C2 19.6 2.9 20.5 4 20.5H20C21.1 20.5 22 19.6 22 18.5V6.5C22 5.4 21.1 4.5 20 4.5ZM19.6 8.75L13.06 12.84C12.41 13.25 11.59 13.25 10.94 12.84L4.4 8.75C4.15 8.59 4 8.32 4 8.03C4 7.36 4.73 6.96 5.3 7.31L12 11.5L18.7 7.31C19.27 6.96 20 7.36 20 8.03C20 8.32 19.85 8.59 19.6 8.75Z' fill='%23B9BDCB'/%3E%3C/svg%3E") no-repeat 11px center!important;background-size:20px!important;padding-left:40px!important}
    input#gform_submit_button_1,input#gform_submit_button_2{width:40%;background:var(--e-global-color-5376d26)!important;border:solid 2px var(--e-global-color-e978a34)!important;border-radius:0;color:var(--e-global-color-e978a34)!important}
    input#gform_submit_button_1:hover,input#gform_submit_button_2:hover{background:var(--e-global-color-e978a34)!important;color:var(--e-global-color-5376d26)!important}
    </style>
    <?php
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

