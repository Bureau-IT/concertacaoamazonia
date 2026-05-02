<?php
/**
 * Plugin Name: Concertação - Menu Compartilhado
 * Plugin URI:  https://concertacaoamazonia.com.br
 * Description: Define menu compartilhado entre todos os subsites da Concertação.
 *              Intercepta o menu "concertacao-lp" em qualquer blog e retorna
 *              a estrutura canônica definida neste arquivo.
 *              Para atualizar o menu, edite concertacao_shared_menu_items().
 * Version:     1.0.0
 * Author:      Bureau IT
 * Author URI:  https://bureaudetecnologia.com.br
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Cria um array de itens de menu fake compatíveis com wp_get_nav_menu_items().
 *
 * @param array $definition  [ [$title, $url, $parent_id?], ... ]
 * @param int   $id_offset   Offset do ID fictício para evitar colisões entre menus
 * @return object[]
 */
function concertacao_build_menu_items( array $definition, int $id_offset = 90000 ): array {
    $items  = [];
    $order  = 0;
    $id_seq = $id_offset;

    $add = function ( string $title, string $url, int $parent_id = 0 ) use ( &$items, &$order, &$id_seq ): int {
        $order++;
        $id = $id_seq++;

        $item = new stdClass();

        $item->ID                = $id;
        $item->post_title        = $title;
        $item->post_status       = 'publish';
        $item->post_type         = 'nav_menu_item';
        $item->post_parent       = 0;
        $item->post_content      = '';
        $item->post_excerpt      = '';
        $item->post_author       = 1;
        $item->comment_status    = 'closed';
        $item->ping_status       = 'closed';
        $item->menu_order        = $order;
        $item->filter            = 'raw';

        $item->db_id             = $id;
        $item->menu_item_parent  = (string) $parent_id;
        $item->object_id         = (string) $id;
        $item->object            = 'custom';
        $item->type              = 'custom';
        $item->type_label        = 'Link';
        $item->url               = $url;
        $item->title             = $title;
        $item->target            = '';
        $item->attr_title        = '';
        $item->description       = '';
        $item->classes           = [ '' ];
        $item->xfn               = '';

        $items[] = $item;
        return $id;
    };

    foreach ( $definition as $entry ) {
        $add( $entry[0], $entry[1], $entry[2] ?? 0 );
    }

    return $items;
}

/**
 * Retorna os itens do menu compartilhado como objetos compatíveis com WP Nav Menu.
 *
 * @return object[]
 */
function concertacao_shared_menu_items(): array {
    static $items = null;
    if ( $items !== null ) {
        return $items;
    }

    // ===================================================================
    // ESTRUTURA DO MENU PRINCIPAL
    // Para atualizar: edite abaixo e salve o arquivo.
    // Mapeamento dev:
    //   :8490 → www-concertacao  (www.concertacaoamazonia.com.br)
    //   :8484 → www2-concertacao (concertacaoamazonia.com.br e /cultura/)
    // ===================================================================

    $def   = [];
    $sobre = 90000;
    $atua  = 90005;
    $conh  = 90011;
    $cult  = 90015;

    // ── Sobre nós (:8490) ──────────────────────────────────────────────
    $def[] = [ 'Sobre nós',             'https://cambrasmax.local:8490/sobre-nos/' ];                                                // ID 90000
    $def[] = [ 'Rede',                  'https://www.concertacaoamazonia.com.br/sobre-nos/#nucleogovernanca', $sobre ];
    $def[] = [ '4 Amazônias',           'https://cambrasmax.local:8490/sobre-nos/4-amazonias/',               $sobre ];
    $def[] = [ '5 Pilares',             'https://cambrasmax.local:8490/sobre-nos/5-pilares/',                 $sobre ];
    $def[] = [ 'Agenda Integradora',    'https://cambrasmax.local:8490/sobre-nos/agenda-integradora/',        $sobre ];

    // ── Atuação (:8490) ────────────────────────────────────────────────
    $def[] = [ 'Atuação',               'https://cambrasmax.local:8490/atuacao/' ];                           // ID 90005
    $def[] = [ 'Encontros',             'https://cambrasmax.local:8484/atuacao/encontros/',                $atua ];
    $def[] = [ 'Grupos de Trabalho',    'https://cambrasmax.local:8490/atuacao/grupos-de-trabalho/',       $atua ];
    $def[] = [ 'Iniciativas Estruturantes', 'https://cambrasmax.local:8490/atuacao/iniciativas-estruturantes/', $atua ];
    $def[] = [ 'Atuação Internacional', 'https://cambrasmax.local:8490/atuacao/atuacao-internacional/',    $atua ];
    $def[] = [ 'Perguntas e Respostas', 'https://cambrasmax.local:8490/atuacao/faq/',                      $atua ];

    // ── Conhecimento (:8484) ───────────────────────────────────────────
    $def[] = [ 'Conhecimento',           'https://cambrasmax.local:8484/conhecimento/' ];                     // ID 90011
    $def[] = [ 'Espiral de Conhecimento','https://cambrasmax.local:8484/conhecimento/espiral-de-conhecimento/', $conh ];
    $def[] = [ 'Mapa de Plataformas',    'https://cambrasmax.local:8484/conhecimento/mapa-das-plataformas/',    $conh ];
    $def[] = [ 'Publicações',            'https://cambrasmax.local:8484/publicacoes/',                          $conh ];

    // ── Cultura (:8484 — blog 2 em /cultura/) ─────────────────────────
    $def[] = [ 'Cultura',                        'https://cambrasmax.local:8484/cultura/' ];                  // ID 90016
    $def[] = [ 'Linha do Tempo',                 'https://cambrasmax.local:8484/cultura/linha-do-tempo/',                $cult ];
    $def[] = [ 'Atlas Cultural das Amazônias',   'https://cambrasmax.local:8484/cultura/atlas-cultural-das-amazonias/',  $cult ];
    $def[] = [ 'Galeria',                        'https://cambrasmax.local:8484/cultura/galeria/',                       $cult ];
    $def[] = [ 'Exposição Porosidades',          'https://cambrasmax.local:8484/cultura/porosidades/',                   $cult ];
    $def[] = [ 'Exposição Cores do Futuro',      'https://cambrasmax.local:8484/cultura/exposicao-cores-do-futuro/',     $cult ];
    $def[] = [ 'Exposição Poéticas do Possível', 'https://cambrasmax.local:8484/cultura/poeticas-do-possivel/',          $cult ];

    // ── Contato (:8490) ────────────────────────────────────────────────
    $def[] = [ 'Contato',                'https://cambrasmax.local:8490/contato/' ];

    $items = concertacao_build_menu_items( $def, 90000 );
    return $items;
}

/**
 * Intercepta wp_get_nav_menu_items para menus com slug 'concertacao-lp'
 * e retorna a estrutura compartilhada definida em concertacao_shared_menu_items().
 *
 * Funciona em qualquer blog dos dois stacks (www-concertacao e www2-concertacao).
 *
 * @param WP_Post[]|false $items Itens originais (ou false se erro)
 * @param WP_Term|object  $menu  Objeto do menu
 * @param object          $args  Argumentos da chamada
 * @return WP_Post[]|false
 */
/**
 * Retorna os itens do menu de footer (apenas os 5 itens principais).
 *
 * @return object[]
 */
function concertacao_footer_menu_items(): array {
    static $items = null;
    if ( $items !== null ) {
        return $items;
    }

    $def = [
        [ 'Sobre nós',    'https://cambrasmax.local:8490/sobre-nos/'    ],
        [ 'Atuação',      'https://cambrasmax.local:8490/atuacao/'      ],
        [ 'Conhecimento', 'https://cambrasmax.local:8484/conhecimento/' ],
        [ 'Cultura',      'https://cambrasmax.local:8484/cultura/'      ],
        [ 'Contato',      'https://cambrasmax.local:8490/contato/'      ],
    ];

    $items = concertacao_build_menu_items( $def, 91000 );
    return $items;
}

/**
 * Slugs de menus a interceptar:
 *
 * Header (menu principal com submenus):
 *   concertacao-lp → www-concertacao blogs 1-4
 *   principal      → www2-concertacao blog 1 (PT e EN)
 *
 * Footer (apenas itens top-level):
 *   footer         → todos os sites
 */
add_filter( 'wp_get_nav_menu_items', function ( $items, $menu, $args ) {
    if ( ! is_object( $menu ) || ! isset( $menu->slug ) ) {
        return $items;
    }

    if ( in_array( $menu->slug, [ 'concertacao-lp', 'principal' ], true ) ) {
        return concertacao_shared_menu_items();
    }

    if ( $menu->slug === 'footer' ) {
        return concertacao_footer_menu_items();
    }

    return $items;
}, 10, 3 );

/**
 * WPML: substitui nome por extenso pelo código de 2 letras no switcher.
 * Ex: "English" → "EN", "Português" → "PT"
 * Cobre todos os shortcodes WPML de language switcher.
 */
add_filter( 'do_shortcode_tag', function ( $output, $tag ) {
    $wpml_tags = [
        'wpml_language_switcher',
        'wpml_language_selector_widget',
        'wpml_language_selector_footer',
    ];
    if ( ! in_array( $tag, $wpml_tags, true ) ) {
        return $output;
    }

    return preg_replace_callback(
        '/<span class="wpml-ls-native"([^>]*)>([^<]+)<\/span>/',
        function ( $m ) {
            preg_match( '/lang="([^"]+)"/i', $m[1], $lang_match );
            $lang  = strtolower( $lang_match[1] ?? '' );
            $codes = [ 'en' => 'EN', 'pt-br' => 'PT', 'pt_br' => 'PT' ];
            $code  = $codes[ $lang ] ?? strtoupper( substr( $lang, 0, 2 ) );
            return '<span class="wpml-ls-native"' . $m[1] . '>' . $code . '</span>';
        },
        $output
    );
}, 10, 2 );
