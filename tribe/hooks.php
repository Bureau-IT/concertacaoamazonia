<?php
/**
 * The Events Calendar - Hooks customizados
 *
 * Este arquivo é carregado automaticamente pelo TEC quando existe no diretório tribe/ do tema.
 * Contém filtros e ações para customizar o comportamento do calendário.
 *
 * @package HelloElementorChild
 * @subpackage TEC
 * @author Daniel Cambría + Claude Code
 * @version 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registra o diretório de templates do tema para o TEC Views V2
 *
 * Isso garante que os templates customizados em tribe/events/v2/ sejam carregados
 * em todos os contextos (shortcodes, páginas de arquivo, widgets, etc.)
 */
add_filter('tribe_template_path_list', 'bureau_it_tec_register_template_path', 15, 2);
function bureau_it_tec_register_template_path($folders, $template) {
    $theme_folder = [
        'id'       => 'hello-elementor-child-tribe',
        'priority' => 5, // Prioridade alta para carregar antes do plugin
        'path'     => get_stylesheet_directory() . '/tribe',
    ];

    // Adicionar no início para ter prioridade
    array_unshift($folders, $theme_folder);

    return $folders;
}

/**
 * Modifica o HTML do template de data para eventos da categoria "edital"
 *
 * Intercepta a renderização do template de data e substitui o conteúdo
 * para mostrar "Edital disponível até: [data]" em vez do formato padrão.
 */
add_filter('tribe_template_html:events/v2/list/event/date', 'bureau_it_tec_filter_edital_date_html', 10, 4);
add_filter('tribe_template_html:events/v2/widgets/widget-events-list/event/date', 'bureau_it_tec_filter_edital_date_html', 10, 4);
function bureau_it_tec_filter_edital_date_html($html, $file, $name, $template) {
    // Obter o evento do contexto do template
    $context = $template->get_values();
    $event = isset($context['event']) ? $context['event'] : null;

    if (!$event) {
        return $html;
    }

    // Verificar se é um edital
    if (!has_term('edital', 'tribe_events_cat', $event->ID)) {
        return $html;
    }

    // Obter a data de término do evento
    $event_obj = tribe_get_event($event->ID);
    if (!$event_obj || !isset($event_obj->dates->end)) {
        return $html;
    }

    // Formatar a data
    $end_date = $event_obj->dates->end;
    $formatted_date = wp_date('j \d\e F', $end_date->getTimestamp());

    // Substituir o conteúdo do elemento time mantendo a estrutura HTML
    $new_date_html = '<span class="tribe-event-edital-date">Edital disponível até: ' . esc_html($formatted_date) . '</span>';

    // Usar regex para substituir o conteúdo do elemento time
    $html = preg_replace(
        '/(<time[^>]*>).*?(<\/time>)/s',
        '$1' . $new_date_html . '$2',
        $html
    );

    return $html;
}

/**
 * Filtro alternativo para modificar as variáveis do template antes da renderização
 *
 * Este filtro modifica o objeto $event diretamente antes de ser passado ao template.
 */
add_filter('tribe_events_views_v2_view_template_vars', 'bureau_it_tec_modify_edital_event_vars', 10, 2);
function bureau_it_tec_modify_edital_event_vars($template_vars, $view) {
    if (!isset($template_vars['events']) || !is_array($template_vars['events'])) {
        return $template_vars;
    }

    foreach ($template_vars['events'] as $key => $event) {
        if (!is_object($event) || !isset($event->ID)) {
            continue;
        }

        // Verificar se é edital
        if (!has_term('edital', 'tribe_events_cat', $event->ID)) {
            continue;
        }

        // Marcar o evento como edital para uso nos templates
        $template_vars['events'][$key]->is_edital = true;

        // Se o evento tem datas, adicionar a data formatada
        if (isset($event->dates->end)) {
            $formatted_date = wp_date('j \d\e F', $event->dates->end->getTimestamp());
            $template_vars['events'][$key]->edital_date_text = 'Edital disponível até: ' . $formatted_date;
        }
    }

    return $template_vars;
}
