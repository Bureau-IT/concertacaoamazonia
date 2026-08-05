<?php
/**
 * Plugin Name: BIT TEC h1→h2 on Home
 * Description: The Events Calendar (TEC) injeta <h1 class="screen-reader-text">Eventos</h1>
 *              em views de eventos (incluindo widget listing na home). Em pages que NÃO são
 *              archive de eventos, esse h1 cria duplicação semântica — title da página +
 *              "Eventos" como h1 confunde leitores de tela e SEO.
 *
 * Estratégia: output buffer no template_redirect só na home (page_id 2461). Converte
 * <h1 class="screen-reader-text"> dentro de .tribe-events-header__content-title para <h2>.
 * h1 real da home vem do mu-plugin bit-hub-pages-h1.php (BIT-injected).
 *
 * Origem: smoke gate 31 detectou h1="Eventos" na home (2026-05-19). Decisão: trocar
 * para h2 e injetar h1 real "Uma Concertação pela Amazônia" via bit-hub-pages-h1.
 *
 * Version: 1.0.1
 * Author: Daniel Cambria (Bureau IT)
 * Changelog:
 *   1.0.1 (2026-05-22): adiciona ID 2519 (home EN, WPML translation de 2461) —
 *                       Playwright detectou h1_count=2 em /en/ porque widget TEC
 *                       da home EN tem o mesmo bug que a PT. Backlog P2: migrar
 *                       para template override no child theme.
 */

namespace BIT\TECh1Home;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// IDs de paginas onde o widget TEC injeta <h1>Eventos</h1>/<h1>Events</h1>
// duplicado. Inclui PT (2461) e EN (2519 — WPML translation).
const HOME_POST_IDS = [
    1 => [ 2461, 2519 ],
];

function should_filter() {
    if ( ! is_singular( 'page' ) ) return false;
    $blog_id = is_multisite() ? get_current_blog_id() : 1;
    $post_id = get_queried_object_id();
    if ( ! isset( HOME_POST_IDS[ $blog_id ] ) ) return false;
    return in_array( $post_id, HOME_POST_IDS[ $blog_id ], true );
}

function start_ob() {
    if ( should_filter() ) {
        ob_start( __NAMESPACE__ . '\\rewrite_tec_h1' );
    }
}

function rewrite_tec_h1( $buffer ) {
    // Padrão TEC: <div class="tribe-events-header__content-title"><h1 class="screen-reader-text tec-a11y-title-hidden">Eventos</h1></div>
    return preg_replace_callback(
        '#(<div\s+class="tribe-events-header__content-title">\s*)<h1(\s[^>]*)?>(.*?)</h1>#is',
        function ( $m ) {
            return $m[1] . '<h2' . ( $m[2] ?? '' ) . '>' . $m[3] . '</h2>';
        },
        $buffer
    );
}

function end_ob() {
    if ( should_filter() && ob_get_level() > 0 ) {
        @ob_end_flush();
    }
}

add_action( 'template_redirect', __NAMESPACE__ . '\\start_ob', 2 );
add_action( 'shutdown', __NAMESPACE__ . '\\end_ob', 999 );
