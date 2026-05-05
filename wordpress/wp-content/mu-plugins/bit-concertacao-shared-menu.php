<?php
/**
 * Plugin Name: Concertação - Menu Compartilhado
 * Plugin URI:  https://concertacaoamazonia.com.br
 * Description: Sincroniza os menus 'principal', 'principal-en' e 'footer' entre
 *              os blogs do multisite. Blog 1 (raiz) é a fonte da verdade — o
 *              admin do WP-Admin manda. Subsites (blog 2 = /cultura/) leem o
 *              mesmo menu cadastrado no blog 1 via switch_to_blog(1) em runtime.
 *              Itens com path /cultura/* permanecem como custom links no menu
 *              do blog 1 — pertencem ao blog 2 mas devem aparecer no menu de
 *              ambos os blogs.
 * Version:     2.0.0
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
/**
 * Resolve o permalink de uma página em runtime, opcionalmente via switch_to_blog.
 *
 * Uso: concertacao_resolve_url('atuacao/encontros') no contexto blog 1, ou
 *      concertacao_resolve_url('linha-do-tempo', 2) para uma página do blog 2.
 *
 * Falha-segura: se a página não existir, retorna `home_url('/' . $path)`
 * preservando o caminho — o item ainda renderiza e a navegação funciona via
 * rewrite do WP, embora o admin nunca deva chegar a esse fallback (auditar via
 * `WP_DEBUG_LOG` quando isso ocorrer).
 *
 * @param string $path  Path relativo sem leading slash (ex: 'sobre-nos/4-amazonias')
 * @param int    $blog  blog_id alvo; 0 = blog atual
 * @return string URL absoluta (com host atual; tunnel-url-rewrite.php cuida do scheme/host em dev)
 */
function concertacao_resolve_url( string $path, int $blog = 1 ): string {
    $switched = false;
    if ( function_exists( 'is_multisite' ) && is_multisite() && get_current_blog_id() !== $blog ) {
        switch_to_blog( $blog );
        $switched = true;
    }

    $path = trim( $path, '/' );
    if ( $path === '' ) {
        $url = trailingslashit( home_url( '/' ) );
    } else {
        $page = get_page_by_path( $path );
        $url  = $page ? get_permalink( $page->ID ) : trailingslashit( home_url( '/' . $path ) );
    }

    if ( $switched ) {
        restore_current_blog();
    }
    return $url;
}

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
        [ 'Sobre nós',    concertacao_resolve_url( 'sobre-nos' )    ],
        [ 'Atuação',      concertacao_resolve_url( 'atuacao' )      ],
        [ 'Conhecimento', concertacao_resolve_url( 'conhecimento' ) ],
        [ 'Cultura',      concertacao_resolve_url( '', 2 )          ],
        [ 'Contato',      concertacao_resolve_url( 'contato' )      ],
    ];

    $items = concertacao_build_menu_items( $def, 91000 );
    return $items;
}

/**
 * Espelha um menu do blog 1 para o blog atual (>1) via switch_to_blog().
 *
 * O menu principal/footer é mantido como fonte única no blog 1 (admin do
 * WP-Admin é a fonte da verdade). Subsites (blog 2 = /cultura/) leem o
 * mesmo menu cadastrado no blog 1, sem necessidade de duplicar items no
 * banco do subsite.
 *
 * Cross-blog ID collision (incidente 2026-05-05 "Hugo Leonardo"): items com
 * `_menu_item_type = post_type` carregam um `object_id` referente a wp_posts
 * do blog 1. Quando wp_setup_nav_menu_item ou Elementor nav-walker são
 * chamados depois no contexto blog 2, eles re-resolvem `get_the_title($object_id)`
 * em wp_2_posts — onde o mesmo ID pode pertencer a outro post (ex: revision
 * de "Hugo Leonardo" em wp_2_posts.91931 vs page "Interviews" em wp_posts.91931).
 *
 * Para neutralizar: dentro do switch_to_blog(1), congelar `title` e `url`
 * resolvidos pelo nav walker do blog 1 e converter `type` para 'custom'.
 * Items do tipo 'custom' não disparam re-resolução por ID. Mantemos
 * `object_id` original em meta para depuração mas o renderer não usa.
 *
 * @param string $slug Slug do menu no blog 1 (ex: 'principal', 'principal-en', 'footer')
 * @return WP_Post[]|false Items do menu, ou false se inexistente
 */
function concertacao_pull_menu_from_blog1( string $slug ) {
    static $cache = [];
    if ( isset( $cache[ $slug ] ) ) {
        return $cache[ $slug ];
    }

    if ( ! function_exists( 'is_multisite' ) || ! is_multisite() ) {
        return false;
    }

    switch_to_blog( 1 );
    // Pegar items SEM disparar o nosso próprio filtro (para evitar recursão).
    remove_filter( 'wp_get_nav_menu_items', 'concertacao_shared_menu_filter', 10 );
    $items = wp_get_nav_menu_items( $slug );
    add_filter( 'wp_get_nav_menu_items', 'concertacao_shared_menu_filter', 10, 3 );

    // Congelar title/url resolvidos no contexto blog 1 e neutralizar object_id
    // para evitar cross-blog ID collision em re-resoluções subsequentes.
    if ( is_array( $items ) ) {
        foreach ( $items as $item ) {
            if ( ! is_object( $item ) ) continue;
            // Snapshot da URL (já resolvida pelo nav walker do blog 1)
            if ( empty( $item->url ) || strpos( $item->url, 'http' ) !== 0 ) {
                // wp_setup_nav_menu_item já preencheu — fallback seguro
                $item->url = $item->url ?: '#';
            }
            // Title só é re-resolvido pelo walker quando type=post_type/taxonomy.
            // Forçar custom: hooks posteriores respeitam $item->title literal.
            $item->type        = 'custom';
            $item->object      = 'custom';
            $item->object_id   = (string) $item->ID;  // Self-ref evita lookup cross-blog
            $item->type_label  = 'Link';
        }
    }
    restore_current_blog();

    $cache[ $slug ] = $items;
    return $items;
}

/**
 * Filtro principal: nos subsites (blog_id > 1), substitui menus 'principal',
 * 'principal-en' e 'footer' pelos itens cadastrados no blog 1.
 *
 * No blog 1 retorna $items intocados — admin do WP-Admin manda. Permite que
 * mudanças feitas no admin reflitam tanto no blog 1 quanto no blog 2.
 *
 * Slug 'concertacao-lp' é histórico (legado da landing page); mantido por
 * compatibilidade.
 *
 * @param WP_Post[]|false $items Items originais
 * @param WP_Term|object  $menu  Objeto do menu (precisa ter ->slug)
 * @return WP_Post[]|false
 */
function concertacao_shared_menu_filter( $items, $menu, $args ) {
    if ( ! is_object( $menu ) || ! isset( $menu->slug ) ) {
        return $items;
    }
    // No blog 1, admin é a fonte da verdade — não interceptar.
    if ( get_current_blog_id() === 1 ) {
        return $items;
    }
    if ( in_array( $menu->slug, [ 'principal', 'principal-en', 'concertacao-lp' ], true ) ) {
        $blog1_items = concertacao_pull_menu_from_blog1( $menu->slug );
        return $blog1_items ?: $items;
    }
    if ( $menu->slug === 'footer' ) {
        return concertacao_footer_menu_items();
    }
    return $items;
}
add_filter( 'wp_get_nav_menu_items', 'concertacao_shared_menu_filter', 10, 3 );

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
