<?php
/**
 * The Events Calendar — hooks e funções customizadas
 *
 * @package HelloElementorChild
 * @since   2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Carregar hooks customizados do TEC (localizados em tribe/hooks.php)
$tribe_hooks_file = get_stylesheet_directory() . '/tribe/hooks.php';
if (file_exists($tribe_hooks_file)) {
    require_once $tribe_hooks_file;
}

/**
 * Verifica se a página atual usa The Events Calendar.
 *
 * Detecta: TEC event queries (archives, single events), shortcode [tribe_events],
 * e widgets Elementor nativos do TEC (tec_elementor_widget_events_view).
 *
 * @since 2.1.1
 * @return bool
 */
function bureau_it_page_uses_tec() {
    // TEC event queries: /eventos-calendario/, single events, etc.
    if ( function_exists( 'tribe_is_event_query' ) && tribe_is_event_query() ) {
        return true;
    }

    // Páginas singulares: verificar shortcode e widget Elementor
    if ( is_singular() ) {
        $post = get_post();
        if ( ! $post ) {
            return false;
        }

        // Shortcode clássico [tribe_events]
        if ( has_shortcode( $post->post_content, 'tribe_events' ) ) {
            return true;
        }

        // Widget Elementor nativo do TEC
        $elementor_data = get_post_meta( $post->ID, '_elementor_data', true );
        if ( $elementor_data && str_contains( $elementor_data, 'tec_elementor_widget' ) ) {
            return true;
        }

        return false;
    }

    return false;
}

/**
 * Descarrega assets do TEC em páginas que não usam eventos.
 *
 * O TEC carrega ~275KB de JS + CSS em todas as páginas por padrão via
 * should_enqueue_frontend(). O filter tribe_events_assets_should_enqueue_frontend
 * já cobre tribe_is_event_query() + shortcode [tribe_events], mas não detecta
 * widgets Elementor nativos do TEC (widgetType: tec_elementor_widget_events_view).
 *
 * Este filtro estende a lógica padrão adicionando detecção do widget Elementor.
 *
 * @since 2.1.1
 * @param bool $should_enqueue Valor atual do TEC (já true para event queries/shortcodes)
 * @return bool
 */
add_filter('tribe_events_assets_should_enqueue_frontend', 'bureau_it_tec_assets_should_enqueue_frontend');
function bureau_it_tec_assets_should_enqueue_frontend( $should_enqueue ) {
    // Se o TEC já decidiu carregar (event query, shortcode clássico), respeitar
    if ( $should_enqueue ) {
        return true;
    }
    // Estender com detecção de widget Elementor nativo do TEC
    return bureau_it_page_uses_tec();
}

/**
 * Dequeue explícito dos assets TEC/TEC Pro em páginas sem eventos.
 *
 * O filtro tribe_events_assets_should_enqueue_frontend cobre os assets
 * principais do TEC, mas o TEC Pro registra alguns assets sem conditional
 * (ex: tribe-events-pro-mini-calendar-block-styles). Este dequeue em
 * priority 999 remove os assets residuais após todos os enqueues.
 *
 * Handles documentados: inspecionados via curl em /sobre-a-concertacao/
 * em 2026-03-16 com TEC 6.15.12.2 + TEC Pro 7.7.14.
 *
 * @since 2.1.1
 */
add_action('wp_enqueue_scripts', 'bureau_it_tec_dequeue_on_non_tec_pages', 999);
function bureau_it_tec_dequeue_on_non_tec_pages() {
    if ( bureau_it_page_uses_tec() ) {
        return;
    }

    // CSS handles do TEC core + TEC Pro (sem conditional nativa)
    $css_handles = [
        'tribe-events-pro-mini-calendar-block-styles',
        'tec-variables-skeleton',
        'tec-variables-full',
        'tribe-events-v2-virtual-single-block',
        'tribe-events-v2-single-skeleton',
        'tribe-events-v2-single-skeleton-full',
        'tec-events-elementor-widgets-base-styles',
    ];

    // JS handles do TEC core + TEC Pro
    $js_handles = [
        'tec-user-agent',
    ];

    foreach ( $css_handles as $handle ) {
        wp_dequeue_style( $handle );
        wp_deregister_style( $handle );
    }

    foreach ( $js_handles as $handle ) {
        wp_dequeue_script( $handle );
        wp_deregister_script( $handle );
    }

    // Remove o link de iCal alternate no <head>
    remove_action( 'wp_head', [ 'Tribe__Events__iCal', 'maybe_add_ical_link' ], 2 );
    if ( function_exists( 'tribe' ) && tribe( 'tec.iCal' ) ) {
        remove_action( 'wp_head', [ tribe( 'tec.iCal' ), 'maybe_add_ical_link' ], 2 );
    }
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
