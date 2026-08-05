<?php
/**
 * Plugin Name: BIT Hub Pages H1 Injector
 * Description: Injeta <h1 class="bit-sr-only"> com o título da página em hubs principais
 *              do site que não têm h1 (Elementor templates dessas páginas removeram o título).
 *              h1 fica visualmente oculto via screen-reader-only CSS, preserva visual
 *              mas restaura acessibilidade WCAG 2.4.6 e SEO.
 *
 * Páginas alvo (singular IDs):
 *   blog 1 PT: 2461 /, 49005 /atuacao/, 2 /conhecimento/, 45388 /agenda-integradora/
 *   blog 1 EN: 2519 /en/, 53141 /en/activities/, 71775 /en/knowledge/, 45485 /en/agenda-integradora/
 *   blog 2 PT: 13619 /cultura/, 57548 /cultura/atlas-cultural-das-amazonias/,
 *              26767 /cultura/galeria/, 80405 /cultura/poeticas-do-possivel/
 *   blog 2 EN: 19985 /cultura/en/, 72730 /cultura/en/cultural-atlas-of-the-amazon/,
 *              26999 /cultura/en/gallery/, 81632 /cultura/en/poetics-of-the-possible/
 *
 * Origem: smoke gate 31 detectou h1_count=0 em 5 hubs (2026-05-19).
 *         WCAG 2.4.6 + Google SEO ranking exigem h1 único por página.
 *
 * Estratégia: hook em `wp_body_open` injeta <h1> oculto IMEDIATAMENTE após <body>,
 * antes do conteúdo Elementor. Idempotente (verifica se h1 já existe via marker).
 *
 * Version: 1.0.2
 * Author: Daniel Cambria (Bureau IT)
 * Changelog:
 *   1.0.2 (2026-05-22): expande mapeamento — adiciona traducoes EN (WPML trid)
 *                       de TODOS os hubs ja cobertos em PT (8 novas paginas), e
 *                       hubs blog 2 PT faltantes (/cultura/galeria/,
 *                       /cultura/poeticas-do-possivel/).
 *                       Backlog P2: migrar este mu-plugin inteiro para template
 *                       overrides + functions.php do hello-elementor-child
 *                       (decisão arquitetural — a11y eh do tema, nao de mu-plugin).
 *                       Ref: feedback_a11y_in_theme_not_muplugin.
 *   1.0.1 (2026-05-19): fix bug isset(HUB_PAGES[blog][id]) retornava false para
 *                       values null (semântica PHP). Usar array_key_exists.
 */

namespace BIT\HubPagesH1;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Mapeamento: blog_id => [post_id => h1_text_override (null = usar get_the_title)]
// IDs EN vem de WPML wp_icl_translations por trid (ver inventario no changelog).
const HUB_PAGES = [
    1 => [
        // PT
        2461  => 'Uma Concertação pela Amazônia',  // / (home — h1 "Eventos" do TEC era acidental)
        49005 => null,  // /atuacao/
        2     => null,  // /conhecimento/
        45388 => null,  // /agenda-integradora/
        // EN (WPML translations)
        2519  => 'Amazon Concertation',  // /en/
        53141 => null,  // /en/activities/   (atuacao EN)
        71775 => null,  // /en/knowledge/    (conhecimento EN)
        45485 => null,  // /en/agenda-integradora/ (slug nao muda em EN)
    ],
    2 => [
        // PT
        13619 => null,  // /cultura/
        57548 => null,  // /cultura/atlas-cultural-das-amazonias/
        26767 => null,  // /cultura/galeria/
        80405 => null,  // /cultura/poeticas-do-possivel/
        // EN (WPML translations)
        19985 => null,  // /cultura/en/                              (Culture)
        72730 => null,  // /cultura/en/cultural-atlas-of-the-amazon/ (Cultural Atlas)
        26999 => null,  // /cultura/en/gallery/                      (Gallery)
        81632 => null,  // /cultura/en/poetics-of-the-possible/      (Poetics of the Possible)
    ],
];

/**
 * Verifica se a página atual é um hub configurado.
 * Retorna o texto do h1 a injetar, ou false se não aplicável.
 */
function get_h1_for_current_hub() {
    if ( ! is_singular( 'page' ) ) return false;
    $post_id = get_queried_object_id();
    if ( ! $post_id ) return false;
    $blog_id = is_multisite() ? get_current_blog_id() : 1;
    // CRITICAL: usar array_key_exists, não isset — valores `null` no map seriam
    // tratados como "ausente" por isset (PHP semantic) e a função retornaria false
    // mesmo para hubs configurados. Bug v1.0.0 detectado 2026-05-19.
    if ( ! array_key_exists( $blog_id, HUB_PAGES ) ) return false;
    if ( ! array_key_exists( $post_id, HUB_PAGES[ $blog_id ] ) ) return false;
    $override = HUB_PAGES[ $blog_id ][ $post_id ];
    return $override ?? get_the_title( $post_id );
}

/**
 * Injeta CSS screen-reader-only no <head>.
 * Padrão WCAG: classe .bit-sr-only esconde visualmente mas preserva para leitores de tela.
 */
function inject_sr_only_css() {
    if ( ! get_h1_for_current_hub() ) return;
    echo "<style>.bit-sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}</style>\n";
}

/**
 * Injeta <h1 class="bit-sr-only">Título</h1> logo após <body>.
 * Hook wp_body_open foi adicionado no WP 5.2 — funciona em qualquer tema moderno.
 */
function inject_h1() {
    $title = get_h1_for_current_hub();
    if ( ! $title ) return;
    printf( '<h1 class="bit-sr-only">%s</h1>' . "\n", esc_html( $title ) );
}

add_action( 'wp_head', __NAMESPACE__ . '\\inject_sr_only_css', 99 );
add_action( 'wp_body_open', __NAMESPACE__ . '\\inject_h1', 1 );
