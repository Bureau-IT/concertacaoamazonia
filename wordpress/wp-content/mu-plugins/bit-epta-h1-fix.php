<?php
/**
 * Plugin Name: BIT EPTA H1 Fix
 * Description: Plugin event-page-templates-addon-for-the-events-calendar (EPTA) renderiza
 *              o título do single event como <h2> hardcoded em epta-template-1.php linha 48.
 *              Esse mu-plugin filtra o output do single tribe_events via output buffer,
 *              convertendo <h2> dentro de .epta-title-date para <h1>. Workaround minimamente
 *              invasivo (sem editar plugin de terceiro nem override de template — sobrevive
 *              a updates do EPTA).
 *
 * Origem: smoke gate 31 detectou single tribe_events com 0 h1 (2026-05-19).
 *         WCAG 2.4.6 + SEO exigem h1 único por página.
 *
 * Version: 1.1.0
 * Changelog:
 *   1.1.0 (2026-05-21): também converte <h3 class="tecset-share-title"> em h2 para
 *                       manter hierarquia (h1→h3 era skip de nível em single event).
 *   1.0.1 (2026-05-19): primeira versão funcional — h2→h1 em .epta-title-date.
 *
 * Author: Daniel Cambria (Bureau IT)
 */

namespace BIT\EPTA;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Inicia output buffer no `template_redirect` para singles de tribe_events.
 * O OB captura HTML inteiro até `shutdown` (ou render natural do tema),
 * onde aplicamos regex pra trocar h2 → h1 dentro de .epta-title-date.
 */
function start_ob() {
    if ( is_singular( 'tribe_events' ) ) {
        ob_start( __NAMESPACE__ . '\\rewrite_epta_h2' );
    }
}

/**
 * 2 transformações no HTML do single event:
 *   1. <h2> dentro de .epta-title-date → <h1> (título do evento)
 *   2. <h3 class="tecset-share-title"> → <h2> (manter hierarquia)
 */
function rewrite_epta_h2( $buffer ) {
    // Fix #1: título do evento h2 → h1
    $buffer = preg_replace_callback(
        '#(<div\s+class="epta-title-date(?:\s+no-image)?">\s*)<h2>(.*?)</h2>#is',
        function ( $m ) {
            return $m[1] . '<h1>' . $m[2] . '</h1>';
        },
        $buffer
    );

    // Fix #2: "Share This Event" h3 → h2 (skip h1→h3 no outline)
    $buffer = preg_replace_callback(
        '#<h3(\s+class="tecset-share-title"[^>]*)>(.*?)</h3>#is',
        function ( $m ) {
            return '<h2' . $m[1] . '>' . $m[2] . '</h2>';
        },
        $buffer
    );

    return $buffer;
}

function end_ob() {
    if ( is_singular( 'tribe_events' ) && ob_get_level() > 0 ) {
        @ob_end_flush();
    }
}

add_action( 'template_redirect', __NAMESPACE__ . '\\start_ob', 1 );
add_action( 'shutdown', __NAMESPACE__ . '\\end_ob', 999 );
