<?php
/**
 * The Events Calendar — hooks e funções customizadas
 *
 * @package HelloElementorChild
 * @since   2.0.0
 * @version 2.4.6
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

add_filter('tec_events_get_time_range_separator', function($separator) {
    return (strpos($separator, '#') !== false) ? ' - ' : $separator;
});

add_filter('tec_events_get_date_time_separator', function($separator) {
    return (strpos($separator, '#') !== false) ? ' @ ' : $separator;
});

/**
 * Resolve um occurrence ID do TEC Custom Tables V1 para o post_id real.
 *
 * O TEC V1 usa IDs provisórios (ex: 10001315) nas templates em vez dos post IDs
 * reais (ex: 89737). O offset interno é 10.000.000 + occurrence_id.
 *
 * @since 2.2.0
 * @param int $event_id ID que pode ser provisório ou real
 * @return int ID real do post (ou o $event_id original se não for possível resolver)
 */
function bureau_it_tec_resolve_post_id( $event_id ) {
    $event_id = (int) $event_id;

    if ( $event_id <= 0 ) {
        return $event_id;
    }

    // Verificar cache antes de qualquer consulta
    $cache_key = 'bureau_it_tec_post_id_' . $event_id;
    $cached = wp_cache_get( $cache_key, 'bureau_it_tec' );
    if ( false !== $cached ) {
        return (int) $cached;
    }

    $resolved = $event_id;

    if ( function_exists( 'tec_get_post_id_from_occurrence_id' ) ) {
        // TEC API oficial (se disponível)
        $real = tec_get_post_id_from_occurrence_id( $event_id );
        if ( $real ) {
            $resolved = (int) $real;
        }
    } else {
        // Fallback: consulta direta em wp_tec_occurrences
        global $wpdb;
        $table = $wpdb->prefix . 'tec_occurrences';

        // Verificar existência da tabela via information_schema (SQL-safe)
        $table_exists = (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s LIMIT 1",
            $table
        ) );

        if ( $table_exists ) {
            // Tentar direto pelo occurrence_id
            $real = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT post_id FROM {$table} WHERE occurrence_id = %d LIMIT 1",
                $event_id
            ) );

            if ( ! $real ) {
                // TEC provisional IDs: offset interno de 10.000.000 + occurrence_id
                // Ex: 10001315 - 10000000 = 1315 (occurrence_id real)
                $candidate = $event_id - 10000000;
                if ( $candidate > 0 ) {
                    $real = (int) $wpdb->get_var( $wpdb->prepare(
                        "SELECT post_id FROM {$table} WHERE occurrence_id = %d LIMIT 1",
                        $candidate
                    ) );
                }
            }

            if ( $real > 0 ) {
                $resolved = $real;
            }
        }
    }

    wp_cache_set( $cache_key, $resolved, 'bureau_it_tec', 3600 );

    return $resolved;
}

/**
 * Verifica se um evento é da categoria "edital"
 *
 * Suporta IDs provisórios do TEC Custom Tables V1, onde $event->ID pode ser
 * um occurrence ID composto (ex: 10001315) em vez do post_id real (ex: 89737).
 *
 * @since 1.0.0
 * @param WP_Post|int $event Objeto do evento ou ID (real ou provisório)
 * @return bool
 */
function bureau_it_is_edital( $event ) {
    $event_id = is_object( $event ) ? (int) $event->ID : (int) $event;

    if ( $event_id <= 0 ) {
        return false;
    }

    $event_id = bureau_it_tec_resolve_post_id( $event_id );

    return has_term( 'edital', 'tribe_events_cat', $event_id );
}

/**
 * Filtro para modificar a data exibida dos eventos na lista.
 * Usado em views legadas / shortcodes V1.
 *
 * Para V2 (list view, widget), o template override date.php já aplica a lógica.
 * Este filtro mantém compatibilidade com shortcodes antigos e V1.
 *
 * @since 1.4.2
 */
add_filter('tribe_events_event_schedule_details', 'bureau_it_filter_edital_schedule', 10, 2);
function bureau_it_filter_edital_schedule( $schedule, $event_id ) {
    if ( ! bureau_it_is_edital( $event_id ) ) {
        return $schedule;
    }

    // Usar ID resolvido para buscar o evento
    $real_id = bureau_it_tec_resolve_post_id( (int) $event_id );
    $event   = tribe_get_event( $real_id );

    if ( ! $event || ! isset( $event->dates->end ) ) {
        return $schedule;
    }

    $formatted = wp_date( 'j \d\e F', $event->dates->end->getTimestamp() );

    return '<span class="tribe-event-edital-date">Edital disponível até: ' . esc_html( $formatted ) . '</span>';
}

/**
 * Filtro para short_schedule_details (usado em widgets legados).
 *
 * @since 1.4.2
 */
add_filter('tribe_events_event_short_schedule_details', 'bureau_it_filter_edital_short_schedule', 10, 2);
function bureau_it_filter_edital_short_schedule( $schedule, $event_id ) {
    if ( ! bureau_it_is_edital( $event_id ) ) {
        return $schedule;
    }

    $real_id = bureau_it_tec_resolve_post_id( (int) $event_id );
    $event   = tribe_get_event( $real_id );

    if ( ! $event || ! isset( $event->dates->end ) ) {
        return $schedule;
    }

    $formatted = wp_date( 'j \d\e F', $event->dates->end->getTimestamp() );

    return '<span class="tribe-event-edital-date">Edital disponível até: ' . esc_html( $formatted ) . '</span>';
}

/**
 * Formata a data de um evento para exibição customizada
 *
 * Para editais: "Edital disponível até: X de Mês"
 * Para eventos: Formato padrão com correções de localização
 *
 * @since 1.0.0
 * @param WP_Post $event      Objeto do evento com propriedades do tribe_get_event()
 * @param bool    $add_prefix Adicionar "De " no início para eventos normais
 * @return string HTML formatado da data
 */
function bureau_it_format_event_date( $event, $add_prefix = true ) {
    if ( bureau_it_is_edital( $event ) ) {
        if ( ! isset( $event->dates->end ) ) {
            return '';
        }
        $formatted = wp_date( 'j \d\e F', $event->dates->end->getTimestamp() );

        return '<span class="tribe-event-edital-date">Edital disponível até: ' . esc_html( $formatted ) . '</span>';
    }

    // Eventos normais: aplicar correções de formato
    $date_text = wp_kses_post( $event->schedule_details->value() );

    // Correções de localização pt-BR
    $date_text = str_replace( 'Virtual Evento', 'Evento Virtual', $date_text );
    $date_text = preg_replace( '/\(UTC-3\)/i', 'Horário de São Paulo', $date_text );

    // Corrigir formato da data: "Month DD @ HH:MM" → "DD de Month às HH:MM"
    $date_text = preg_replace_callback(
        '/(\w+)\s(\d+)\s@\s(\d+):(\d+)/i',
        function ( $matches ) {
            return $matches[2] . ' de ' . $matches[1] . ' às ' . $matches[3] . ':' . $matches[4];
        },
        $date_text
    );

    // Substituir separador de intervalo
    $date_text = str_replace( '</span> - <span', '</span> até <span', $date_text );

    // Adicionar prefixo "De " se solicitado
    if ( $add_prefix && strpos( $date_text, '<span class="tribe-event-date-start">' ) !== false ) {
        $date_text = 'De ' . $date_text;
    }

    return $date_text;
}

// === POSICIONAMENTO DE EDITAIS PELO MÊS DE TÉRMINO (v2.3.0) ===

/**
 * Adiciona controle "Editais: agrupar pelo mês de término" ao painel Content
 * do widget Elementor TEC Events View.
 *
 * Quando ativo, Editais aparecem no grupo do mês em que terminam (EndDate)
 * em vez do mês em que começam (StartDate — padrão TEC).
 * O agrupamento é implementado via template override em
 * tribe/events/v2/list/month-separator.php.
 *
 * @since 2.3.0
 */
add_action(
    'elementor/element/tec_elementor_widget_events_view/content_section/before_section_end',
    'bureau_it_tec_widget_add_edital_group_control'
);
function bureau_it_tec_widget_add_edital_group_control( $widget ) {
    $widget->add_control( 'bit_edital_group_by_end', [
        'label'        => __( 'Editais: agrupar pelo mês de término', 'hello-elementor-child' ),
        'description'  => __( 'Editais aparecem no mês em que terminam, não no mês em que começam.', 'hello-elementor-child' ),
        'type'         => \Elementor\Controls_Manager::SWITCHER,
        'label_on'     => __( 'Sim', 'hello-elementor-child' ),
        'label_off'    => __( 'Não', 'hello-elementor-child' ),
        'return_value' => 'yes',
        'default'      => '',
    ] );
}

/**
 * Captura o setting "bit_edital_group_by_end" do widget TEC da página atual.
 *
 * O TEC Widget View executa tribe_events_views_v2_view_list_template_vars antes
 * do elementor/widget/before_render_content (o render do Elementor chama o TEC
 * internamente, então o $GLOBALS precisa estar pronto antes do filtro do TEC).
 *
 * Solução: ler o _elementor_data da página no hook "wp" (antes de qualquer render),
 * encontrar o widget tec_elementor_widget_events_view e extrair o setting.
 * Fallback via before_render_content para cobrir o caso em que o TEC renderiza
 * depois do Elementor (ex: shortcode embeddado num template).
 *
 * @since 2.4.2
 */
add_action( 'wp', 'bureau_it_tec_capture_edital_group_setting_early' );
function bureau_it_tec_capture_edital_group_setting_early() {
    $post_id = 0;

    // 1. Página singular: caminho mais direto
    if ( is_singular() ) {
        $post_id = get_queried_object_id();
    }

    // 2. url_to_postid() — pode falhar quando TEC sobrepõe rewrites
    if ( ! $post_id ) {
        $current_url = ( is_ssl() ? 'https' : 'http' ) . '://' . ( $_SERVER['HTTP_HOST'] ?? '' ) . strtok( $_SERVER['REQUEST_URI'] ?? '', '?' );
        $post_id     = url_to_postid( $current_url );
    }

    // 3. Fallback: buscar post_name via slug extraído da URI
    if ( ! $post_id ) {
        $slug = trim( strtok( $_SERVER['REQUEST_URI'] ?? '', '?' ), '/' );
        // Pegar apenas o último segmento do slug
        $slug = basename( $slug );
        if ( $slug ) {
            $page = get_page_by_path( $slug, OBJECT, 'page' );
            if ( $page ) {
                $post_id = $page->ID;
            }
        }
    }

    if ( ! $post_id ) {
        return;
    }

    $elementor_data = get_post_meta( $post_id, '_elementor_data', true );
    if ( ! $elementor_data ) {
        return;
    }
    $data = json_decode( $elementor_data, true );
    if ( ! is_array( $data ) ) {
        return;
    }

    $found = bureau_it_tec_find_edital_group_setting( $data );
    if ( null !== $found ) {
        $GLOBALS['bit_tec_edital_group_by_end'] = $found;
    }
}

/**
 * Percorre recursivamente o array de elementos Elementor e retorna o valor de
 * bit_edital_group_by_end do primeiro widget tec_elementor_widget_events_view
 * encontrado. Retorna null se nenhum widget for encontrado.
 *
 * @since 2.4.2
 * @param array $elements
 * @return bool|null
 */
function bureau_it_tec_find_edital_group_setting( array $elements ) {
    foreach ( $elements as $el ) {
        if ( ( $el['widgetType'] ?? '' ) === 'tec_elementor_widget_events_view' ) {
            return 'yes' === ( $el['settings']['bit_edital_group_by_end'] ?? '' );
        }
        if ( ! empty( $el['elements'] ) ) {
            $found = bureau_it_tec_find_edital_group_setting( $el['elements'] );
            if ( null !== $found ) {
                return $found;
            }
        }
    }
    return null;
}

// Fallback: cobertura para shortcodes e templates que renderizam fora do contexto singular
add_action( 'elementor/widget/before_render_content', 'bureau_it_tec_capture_edital_group_setting' );
function bureau_it_tec_capture_edital_group_setting( $widget ) {
    if ( 'tec_elementor_widget_events_view' !== $widget->get_name() ) {
        return;
    }
    $settings                               = $widget->get_settings_for_display();
    $GLOBALS['bit_tec_edital_group_by_end'] = ( 'yes' === ( $settings['bit_edital_group_by_end'] ?? '' ) );
}

/**
 * Pré-ordena os eventos da List View por grupo-mês antes do render.
 *
 * O TEC entrega eventos em start_date ASC. Editais de longa duração (ex: início Mar,
 * fim Dez) ficam intercalados entre eventos do mês corrente, quebrando os separadores.
 * Este filtro reordena o array para que todos os eventos de um mesmo grupo-mês
 * apareçam consecutivos — o month-separator.php tracker então funciona corretamente.
 *
 * Grupo-mês: end_display para Editais (quando toggle ativo), start_display para demais.
 * Sort estável (bubble): preserva ordem relativa dentro do mesmo grupo-mês.
 *
 * @since 2.3.0
 */
// Registrar em ambos: genérico (tribe_events_views_v2_view_template_vars dispara sempre)
// e específico da list view (tribe_events_views_v2_view_list_template_vars).
// A função usa uma flag estática para não ordenar duas vezes caso ambos disparem.
add_filter( 'tribe_events_views_v2_view_template_vars', 'bureau_it_tec_sort_events_by_group_month' );
add_filter( 'tribe_events_views_v2_view_list_template_vars', 'bureau_it_tec_sort_events_by_group_month' );

/**
 * Desabilita o HTML cache interno do TEC (HTML_Cache trait) quando o sort
 * de editais por mês de término está ativo.
 *
 * O TEC usa `maybe_get_cached_html()` em `View::get_html()` — se o cache existir,
 * retorna antes de chamar `setup_template_vars()`, bypassando nosso filtro.
 * Este filtro força `null` como retorno do cache, obrigando o TEC a renderizar.
 *
 * @since 2.4.5
 */
add_filter( 'tribe_events_views_v2_view_cached_html', 'bureau_it_tec_disable_view_html_cache', 5, 2 );
function bureau_it_tec_disable_view_html_cache( $cached_html, $view ) {
    if ( ! empty( $GLOBALS['bit_tec_edital_group_by_end'] ) ) {
        return null; // Forçar render completo — nosso filtro de sort precisa executar
    }
    return $cached_html;
}
function bureau_it_tec_sort_events_by_group_month( $template_vars ) {
    // Flag estática: ordenar somente uma vez por request (evita double-sort se ambos os filtros dispararem)
    static $sorted = false;
    if ( $sorted ) {
        return $template_vars;
    }

    if ( empty( $GLOBALS['bit_tec_edital_group_by_end'] ) ) {
        return $template_vars;
    }

    if ( empty( $template_vars['events'] ) || ! is_array( $template_vars['events'] ) ) {
        return $template_vars;
    }

    $request_date = $template_vars['request_date'] ?? null;
    $is_past      = $template_vars['is_past'] ?? false;

    // Pré-calcular grupo-mês de cada evento.
    // Chave = $event->ID original (pode ser occurrence ID provisório do TEC V1).
    // O sort abaixo usa o mesmo ID, então as chaves devem ser consistentes.
    $group_dates = [];
    foreach ( $template_vars['events'] as $event ) {
        $original_id = $event->ID;
        $hydrated    = tribe_get_event( $event );
        if ( ! $hydrated instanceof WP_Post ) {
            $group_dates[ $original_id ] = $event->dates->start_display ?? null;
            continue;
        }

        $use_end = function_exists( 'bureau_it_is_edital' )
                   && bureau_it_is_edital( $hydrated )
                   && isset( $hydrated->dates->end_display );

        if ( $use_end ) {
            $group_dates[ $original_id ] = $hydrated->dates->end_display;
        } elseif ( empty( $is_past ) && ! empty( $request_date ) ) {
            $group_dates[ $original_id ] = max( $hydrated->dates->start_display, $request_date );
        } else {
            $group_dates[ $original_id ] = $hydrated->dates->start_display;
        }
    }

    // Ordenar por grupo-data ASC (primário: Y-m, secundário: Y-m-d).
    // usort não é estável em PHP < 8.0; para garantir estabilidade, adicionamos
    // índice original como terceiro critério de desempate.
    $indexed = array_values( $template_vars['events'] );
    $order   = array_keys( $indexed ); // índices originais para desempate estável

    usort( $indexed, function ( $a, $b ) use ( $group_dates, $order ) {
        $date_a = $group_dates[ $a->ID ] ?? null;
        $date_b = $group_dates[ $b->ID ] ?? null;

        if ( ! $date_a || ! $date_b ) {
            return 0;
        }

        $ts_a = $date_a->format( 'Y-m-d' );
        $ts_b = $date_b->format( 'Y-m-d' );

        return strcmp( $ts_a, $ts_b );
    } );

    $template_vars['events'] = $indexed;
    $sorted                  = true;

    return $template_vars;
}
