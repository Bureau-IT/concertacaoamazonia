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

// Debug: confirmar que o arquivo está sendo carregado
add_action('init', function() {
    file_put_contents('/tmp/tec_debug.log', date('H:i:s') . " - hooks.php LOADED\n", FILE_APPEND);
}, 1);

/**
 * Registra o diretório de templates do tema para o TEC Views V2
 *
 * O TEC busca templates em duas listas:
 * - tribe_template_path_list: para plugins
 * - tribe_template_theme_path_list: para temas
 *
 * Para temas, a estrutura esperada é:
 * {theme}/tribe/events/v2/list/event/date.php
 *
 * @since 1.0.0
 */
add_filter('tribe_template_theme_path_list', 'bureau_it_tec_register_theme_template_path', 5, 3);
function bureau_it_tec_register_theme_template_path($folders, $namespace, $template) {
    // Adicionar o diretório do child theme no início da lista
    $theme_folder = [
        'id'       => 'hello-elementor-child-tribe',
        'priority' => 5, // Prioridade alta (menor número = maior prioridade)
        'path'     => get_stylesheet_directory() . '/tribe',
    ];

    // Inserir no início para ter prioridade sobre o tema parent
    array_unshift($folders, $theme_folder);

    return $folders;
}

/**
 * Modifica o HTML do template de data para eventos da categoria "edital"
 *
 * Intercepta a renderização do template de data e substitui o conteúdo
 * para mostrar "Edital disponível até: [data]" em vez do formato padrão.
 *
 * Hook names no TEC V2:
 * - Shortcode list view: events/v2/list/event/date
 * - Widget events list: events/v2/widgets/widget-events-list/event/date
 *
 * @since 1.0.0
 */
// Hook names corretos identificados via debug:
// - list/event/date (para list view do Elementor widget/shortcode)
// - widgets/widget-events-list/event/date (para widget sidebar)
add_filter('tribe_template_html:list/event/date', 'bureau_it_tec_filter_edital_date_html', 10, 4);

// DEBUG: capturar TODOS os templates de data
add_filter('tribe_template_html', function($html, $file, $name, $template) {
    $name_str = is_array($name) ? implode('/', $name) : $name;
    if (strpos($name_str, 'date') !== false) {
        file_put_contents('/tmp/tec_debug.log', date('H:i:s') . " - TEMPLATE: $name_str\n", FILE_APPEND);
    }
    return $html;
}, 1, 4);

/**
 * Filtro principal para modificar o HTML do shortcode/view V2
 *
 * O filtro `tribe_events_views_v2_bootstrap_html` é aplicado ao HTML final
 * gerado pelo shortcode do TEC V2. Este é o ponto de interceptação correto
 * para modificar a exibição de datas.
 *
 * @since 1.0.0
 * @param string $html     HTML gerado pelo view
 * @param object $context  Contexto do view
 * @param string $view_slug Slug do view (list, month, etc.)
 * @param array  $query    Query de eventos
 * @return string HTML modificado
 */
add_filter('tribe_events_views_v2_bootstrap_html', 'bureau_it_tec_filter_view_html', 10, 4);
function bureau_it_tec_filter_view_html($html, $context, $view_slug, $query) {
    file_put_contents('/tmp/tec_debug.log', date('H:i:s') . " - bootstrap_html CALLED view=$view_slug\n", FILE_APPEND);

    // Só processar se contiver elementos de data do list view
    if (strpos($html, 'tribe-events-calendar-list__event-datetime') === false) {
        file_put_contents('/tmp/tec_debug.log', date('H:i:s') . " - No datetime element found\n", FILE_APPEND);
        return $html;
    }

    file_put_contents('/tmp/tec_debug.log', date('H:i:s') . " - Processing HTML with datetime elements\n", FILE_APPEND);

    // O HTML real tem a estrutura:
    // <article class="... post-10001240 ... cat_edital ...">
    // Usamos cat_edital para identificar eventos da categoria edital
    // E post-NNNN para extrair o ID do evento

    $html = preg_replace_callback(
        '/(<article[^>]*class="[^"]*post-(\d+)[^"]*cat_edital[^"]*"[^>]*>)(.*?)(<\/article>)/s',
        function($matches) {
            $article_open = $matches[1];
            $event_id = $matches[2];
            $article_content = $matches[3];
            $article_close = $matches[4];

            // Obter dados do evento para a data de término
            $event = tribe_get_event($event_id);
            if (!$event || !isset($event->dates->end)) {
                return $matches[0];
            }

            // Formatar a nova data
            $end_date = $event->dates->end;
            $formatted_date = wp_date('j \d\e F', $end_date->getTimestamp());
            $new_date_html = '<span class="tribe-event-edital-date">Edital disponível até: ' . esc_html($formatted_date) . '</span>';

            // Substituir o conteúdo do elemento time dentro deste artigo
            $article_content = preg_replace(
                '/(<time[^>]*class="tribe-events-calendar-list__event-datetime"[^>]*>).*?(<\/time>)/s',
                '$1' . $new_date_html . '$2',
                $article_content
            );

            return $article_open . $article_content . $article_close;
        },
        $html
    );

    return $html;
}

function bureau_it_tec_filter_edital_date_html($html, $file, $name, $template) {
    file_put_contents('/tmp/tec_debug.log', date('H:i:s') . " - bureau_it_tec_filter_edital_date_html CALLED\n", FILE_APPEND);

    // Obter o evento do contexto do template
    $context = $template->get_values();
    $event = isset($context['event']) ? $context['event'] : null;

    if (!$event) {
        file_put_contents('/tmp/tec_debug.log', date('H:i:s') . " - NO EVENT found\n", FILE_APPEND);
        return $html;
    }

    file_put_contents('/tmp/tec_debug.log', date('H:i:s') . " - Event ID: {$event->ID}\n", FILE_APPEND);

    // Verificar se é um edital
    if (!has_term('edital', 'tribe_events_cat', $event->ID)) {
        file_put_contents('/tmp/tec_debug.log', date('H:i:s') . " - NOT edital\n", FILE_APPEND);
        return $html;
    }

    file_put_contents('/tmp/tec_debug.log', date('H:i:s') . " - IS EDITAL! Processing...\n", FILE_APPEND);

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
