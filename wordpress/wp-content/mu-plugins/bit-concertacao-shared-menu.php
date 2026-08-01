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
 * Version:     2.1.4
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

    // try/finally garante restore_current_blog mesmo se hook em get_page_by_path/
    // get_permalink lançar exception. Sem isso, blog stack fica corrompido
    // pelo resto do request (todas as queries vão para o blog errado).
    try {
        $path = trim( $path, '/' );
        if ( $path === '' ) {
            $url = trailingslashit( home_url( '/' ) );
        } else {
            $page = get_page_by_path( $path );
            $url  = ( $page instanceof WP_Post ) ? get_permalink( $page->ID ) : trailingslashit( home_url( '/' . $path ) );
            if ( $url === false ) {
                $url = trailingslashit( home_url( '/' . $path ) );
            }
        }
    } finally {
        if ( $switched ) {
            restore_current_blog();
        }
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

    // v2.1.2: usar concertacao_t() em vez de __() inerte. WPML intercepta gettext
    // apenas se um .mo file carregar o domain — mu-plugin não chama
    // load_*_textdomain, então __() é no-op puro. concertacao_t() chama o
    // filter wpml_translate_single_string que é o caminho real do WPML String
    // Translation (mesmo que icl_t() interno).
    $def = [
        [ concertacao_t( 'Sobre nós'    ), concertacao_resolve_url( 'sobre-nos' )    ],
        [ concertacao_t( 'Atuação'      ), concertacao_resolve_url( 'atuacao' )      ],
        [ concertacao_t( 'Conhecimento' ), concertacao_resolve_url( 'conhecimento' ) ],
        [ concertacao_t( 'Cultura'      ), concertacao_resolve_url( '', 2 )          ],
        [ concertacao_t( 'Contato'      ), concertacao_resolve_url( 'contato' )      ],
    ];

    $items = concertacao_build_menu_items( $def, 91000 );
    return $items;
}

/**
 * Traduz uma string registrada em WPML String Translation (context "concertacao-footer").
 *
 * v2.1.2: substitui __() inerte. __() só funciona quando há um .mo file carregado
 * para o domain — mu-plugins não chamam load_textdomain, então __() retorna sempre
 * o original PT. WPML expõe wpml_translate_single_string para resolver strings
 * registradas via icl_register_string (caminho que SEMPRE funciona se a string
 * estiver no painel String Translation).
 *
 * Fallback: se WPML inativo ou string não registrada, retorna o original PT — mesmo
 * comportamento do __() anterior.
 *
 * @param string $label String original em PT (também é a key de registro)
 * @return string Texto traduzido no idioma atual, ou original PT
 */
function concertacao_t( string $label ): string {
    if ( ! function_exists( 'apply_filters' ) ) {
        return $label;
    }
    $name = 'footer:' . sanitize_title( $label );
    $tr   = apply_filters( 'wpml_translate_single_string', $label, 'concertacao', $name );
    return ( is_string( $tr ) && $tr !== '' ) ? $tr : $label;
}

/**
 * Registra strings do footer para WPML String Translation, idempotente via transient.
 *
 * v2.1.2: transient guard evita SELECT/UPDATE em wp_icl_strings a CADA request
 * (era custo médio em init priority 20). Re-registra automaticamente 1x por dia
 * ou quando transient é invalidado por edit de option no admin.
 *
 * Hook em after_setup_theme (mais cedo que init priority 20, mas após WPML
 * carregado) evita corrida com outros plugins.
 */
add_action( 'after_setup_theme', function () {
    if ( ! function_exists( 'icl_register_string' ) ) {
        return;
    }
    // Versionar o transient: bump quando adicionar/remover strings do footer.
    if ( get_transient( 'concertacao_wpml_strings_registered_v1' ) ) {
        return;
    }
    foreach ( [ 'Sobre nós', 'Atuação', 'Conhecimento', 'Cultura', 'Contato' ] as $label ) {
        icl_register_string( 'concertacao', 'footer:' . sanitize_title( $label ), $label );
    }
    set_transient( 'concertacao_wpml_strings_registered_v1', 1, DAY_IN_SECONDS );
}, 20 );

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

    // Cache key composto: protege contra contaminação cross-context em workers
    // long-running (WP-CLI multi-blog, cron-control loops) onde o mesmo PHP
    // process serve requests com blog_id/lang diferentes. Sem isso, 1ª chamada
    // poderia poisonar entradas subsequentes. Chave $slug sozinha era frágil.
    $lang = function_exists( 'apply_filters' ) ? apply_filters( 'wpml_current_language', 'pt-br' ) : 'pt-br';
    if ( ! is_string( $lang ) || $lang === '' ) {
        $lang = 'pt-br';
    }
    $key = $slug . '|' . get_current_blog_id() . '|' . $lang;

    if ( isset( $cache[ $key ] ) ) {
        return $cache[ $key ];
    }

    if ( ! function_exists( 'is_multisite' ) || ! is_multisite() ) {
        return false;
    }

    switch_to_blog( 1 );

    // v2.1.2: remover filter ANTES do try; re-adicionar dentro do finally garante
    // que mesmo se wp_get_nav_menu_items() lançar exception, o filter é re-registrado
    // (bug latente v2.1.1: linha add_filter ficava fora do try-finally → request
    // perdia o filter pelo resto do ciclo se exception).
    //
    // try/finally também garante restore_current_blog mesmo se walker terceiro
    // (Mega Menu, JetMenu, theme custom) lançar exception. Sem isso, request
    // inteiro fica preso no blog 1 (todas as queries subsequentes erradas).
    remove_filter( 'wp_get_nav_menu_items', 'concertacao_shared_menu_filter', 10 );
    try {
        $items = wp_get_nav_menu_items( $slug );

        // Congelar title/url resolvidos no contexto blog 1 e neutralizar object_id
        // para evitar cross-blog ID collision em re-resoluções subsequentes.
        if ( is_array( $items ) ) {
            foreach ( $items as $item ) {
                if ( ! is_object( $item ) ) continue;
                // Snapshot da URL (já resolvida pelo nav walker do blog 1)
                if ( empty( $item->url ) || ! is_string( $item->url ) || strpos( $item->url, 'http' ) !== 0 ) {
                    // wp_setup_nav_menu_item já preencheu — fallback seguro
                    $item->url = ! empty( $item->url ) ? (string) $item->url : '#';
                }
                // Title só é re-resolvido pelo walker quando type=post_type/taxonomy.
                // Forçar custom: hooks posteriores respeitam $item->title literal.
                $item->type        = 'custom';
                $item->object      = 'custom';
                $item->object_id   = (string) $item->ID;  // Self-ref evita lookup cross-blog
                $item->type_label  = 'Link';
            }
        }
    } finally {
        add_filter( 'wp_get_nav_menu_items', 'concertacao_shared_menu_filter', 10, 3 );
        restore_current_blog();
    }

    $cache[ $key ] = $items;
    return $items;
}

/**
 * Detecta se a requisição atual é do Customizer (carregamento de customize.php
 * ou AJAX do Customizer como customize_save).
 *
 * Motivo: o Customizer cria um WP_Customize_Nav_Menu_Item_Setting para CADA
 * item retornado por wp_get_nav_menu_items() e valida cada ID contra o blog
 * atual. Os itens injetados por este mu-plugin (reais do blog 1 ou fake, IDs
 * 90000+) não existem como posts no blog 2, disparando
 * "Illegal widget setting ID: nav_menu_item[]" (fatal). No Customizer, então,
 * devolvemos os itens reais do blog atual (sem interceptar).
 *
 * @return bool
 */
function concertacao_is_customizer_request(): bool {
    // 1. Iframe de preview do Customizer.
    if ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) {
        return true;
    }
    // 2. Carregamento da tela customize.php (admin).
    if ( isset( $GLOBALS['pagenow'] ) && 'customize.php' === $GLOBALS['pagenow'] ) {
        return true;
    }
    // 3. AJAX do Customizer (customize_save, customize_*).
    if ( ( wp_doing_ajax() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) )
        && isset( $_REQUEST['action'] )
        && is_string( $_REQUEST['action'] )
        && 0 === strpos( $_REQUEST['action'], 'customize' ) ) {
        return true;
    }
    // 4. Objeto global do Customizer já instanciado neste request.
    if ( isset( $GLOBALS['wp_customize'] ) && $GLOBALS['wp_customize'] instanceof WP_Customize_Manager ) {
        return true;
    }
    return false;
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
    // No Customizer, devolver os itens REAIS do blog atual — itens cross-blog
    // ou fake quebram WP_Customize_Nav_Menu_Item_Setting (fatal).
    if ( concertacao_is_customizer_request() ) {
        return $items;
    }
    if ( in_array( $menu->slug, [ 'principal', 'principal-en', 'concertacao-lp' ], true ) ) {
        // WPML não troca o slug do menu automaticamente em subsites (blog > 1)
        // quando o widget Elementor pede 'principal' num contexto EN.
        // Mapear manualmente: se idioma EN e slug é 'principal', usar 'principal-en'.
        $slug = $menu->slug;
        if ( $slug === 'principal' && function_exists( 'apply_filters' ) ) {
            $current_lang = apply_filters( 'wpml_current_language', null );
            if ( $current_lang && $current_lang !== 'pt-br' ) {
                $candidate = 'principal-' . $current_lang;
                if ( wp_get_nav_menu_object( $candidate ) ) {
                    $slug = $candidate;
                }
            }
        }
        $blog1_items = concertacao_pull_menu_from_blog1( $slug );
        return $blog1_items ?: $items;
    }
    if ( $menu->slug === 'footer' ) {
        return concertacao_footer_menu_items();
    }
    return $items;
}
add_filter( 'wp_get_nav_menu_items', 'concertacao_shared_menu_filter', 10, 3 );

/**
 * Sanitizador universal para o contexto do Customizer (prioridade máxima).
 *
 * O Customizer (WP_Customize_Nav_Menus::customize_register) cria um
 * WP_Customize_Nav_Menu_Item_Setting por item retornado por
 * wp_get_nav_menu_items() e valida cada ID contra o blog atual. QUALQUER item
 * injetado por mu-plugins cross-blog (itens reais do blog 1 ou fake, IDs
 * 90000/91000+) que não exista como post 'nav_menu_item' no blog corrente
 * dispara "Illegal widget setting ID: nav_menu_item[]" (fatal).
 *
 * A guarda dentro de concertacao_shared_menu_filter() resolve apenas a própria
 * injeção deste plugin; outros injetores (bit-crossblog-*) podem persistir.
 * Este filtro roda DEPOIS de todos (PHP_INT_MAX) e, somente no Customizer,
 * descarta itens fantasma — devolvendo ao Customizer apenas itens reais do blog
 * atual. Frontend e wp-admin não são afetados (early-return).
 *
 * @param mixed           $items Lista de itens (array de objetos) ou false.
 * @param WP_Term|object  $menu  Objeto do menu.
 * @return mixed
 */
function concertacao_customizer_sanitize_menu_items( $items, $menu, $args ) {
    if ( ! concertacao_is_customizer_request() || ! is_array( $items ) ) {
        return $items;
    }
    $clean = [];
    foreach ( $items as $item ) {
        if ( ! is_object( $item ) || empty( $item->ID ) || ! is_numeric( $item->ID ) ) {
            continue; // item sem ID válido (fake/cross-blog)
        }
        $post = get_post( (int) $item->ID );
        if ( $post instanceof WP_Post && 'nav_menu_item' === $post->post_type ) {
            $clean[] = $item; // item real persistido no blog atual
        }
        // itens cujo post não existe no blog atual são descartados
    }
    return $clean;
}
add_filter( 'wp_get_nav_menu_items', 'concertacao_customizer_sanitize_menu_items', PHP_INT_MAX, 3 );

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
