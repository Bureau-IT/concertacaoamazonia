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
