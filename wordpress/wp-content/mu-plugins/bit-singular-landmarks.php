<?php
/**
 * Plugin Name: BIT Singular Landmarks
 * Description: Adiciona landmarks semânticos <main> e <article> em singles do site
 *              (posts, CPTs, pages) que não os têm. Templates Elementor renderizam
 *              tudo em <div>s genéricas; WCAG SC 1.3.1 e SEO Schema.org exigem main+article.
 *
 * Estratégia: output buffer no template_redirect captura HTML do single, então:
 *   1. Envolve o `.elementor` root da página em <article role="article">
 *   2. Envolve esse article em <main id="primary">
 *
 * Skip: páginas que JÁ têm <main> e <article> (preserva intenção dos templates).
 * Skip: hubs (lista) — só CPTs e posts/pages singulares.
 *
 * Origem: smoke gate 31 detectou 5 singles sem <main>/<article> (2026-05-19).
 *
 * Version: 1.3.0
 * Changelog:
 *   1.3.0 (2026-05-21): cobertura TEC v2 archive (/eventos/lista/) — wrap
 *                       div.tribe-events-view em <main id="primary">. Sem <article>
 *                       (archive não é article — só single é). should_wrap() aceita
 *                       tribe_is_event_query() além de is_singular().
 *   1.2.0 (2026-05-21): cobertura CPTs sem template Elementor (releases) — quando
 *                       <main> já existe (tema parent injeta) mas <article> não,
 *                       wrap o conteúdo dentro do <main> com <article>.
 *   1.1.0 (2026-05-21): cobertura tribe_events (EPTA template) — busca por
 *                       div.epta-content-area ou .single-tribe_events wrapper.
 *   1.0.0 (2026-05-19): primeira versão — só Elementor wp-post/wp-page/single-*
 *
 * Author: Daniel Cambria (Bureau IT)
 */

namespace BIT\SingularLandmarks;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function should_wrap() {
    // Skip admin
    if ( is_admin() ) return false;
    // Skip se for AJAX/REST
    if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) return false;
    // Singles de CPT públicos (não home, não search)
    if ( is_singular() ) return true;
    // Archive de tribe_events (TEC v2 archive sem <main>)
    if ( function_exists( 'tribe_is_event_query' ) && tribe_is_event_query() ) return true;
    return false;
}

function start_ob() {
    if ( should_wrap() ) {
        ob_start( __NAMESPACE__ . '\\inject_landmarks' );
    }
}

/**
 * Envolve o primeiro elementor data wrapper em <main><article>...</article></main>.
 * Idempotente: se HTML já tem <main> e <article>, retorna inalterado.
 */
function inject_landmarks( $buffer ) {
    // Se já tem <main> e <article>, não mexer (template já cobriu)
    $has_main = (bool) preg_match( '#<main\b#i', $buffer );
    $has_article = (bool) preg_match( '#<article\b#i', $buffer );
    if ( $has_main && $has_article ) {
        return $buffer;
    }

    // CASE 1: tem <main> mas não <article> (caso hello-elementor parent theme em CPTs
    // sem template Elementor — ex: releases CPT). Wrap conteúdo dentro de <main> com
    // <article>. Estratégia: regex substitui o primeiro <main ...> e seu </main> de fechamento.
    if ( $has_main && ! $has_article ) {
        $post_id = get_the_ID();
        // Achar <main ...> abertura
        if ( preg_match( '#<main\b[^>]*>#i', $buffer, $m1, PREG_OFFSET_CAPTURE ) ) {
            $main_open_pos = $m1[0][1];
            $main_open_len = strlen( $m1[0][0] );
            // Achar </main> fechamento correspondente — busca simples (assume 1 main por página)
            $main_close_pos = stripos( $buffer, '</main>', $main_open_pos + $main_open_len );
            if ( $main_close_pos !== false ) {
                $article_open = '<article role="article" id="post-' . (int) $post_id . '">';
                return substr( $buffer, 0, $main_open_pos + $main_open_len )
                     . $article_open
                     . substr( $buffer, $main_open_pos + $main_open_len, $main_close_pos - ( $main_open_pos + $main_open_len ) )
                     . '</article>'
                     . substr( $buffer, $main_close_pos );
            }
        }
        return $buffer;
    }

    // CASE 2: nem main nem article. Tentar achar root Elementor / EPTA / TEC archive pra wrappar.
    // 1. Root Elementor (data-elementor-type="wp-post" ou similar)
    $pattern = '#(<div\b[^>]*data-elementor-type="(?:wp-post|wp-page|single-post|single)"[^>]*>)#i';
    if ( ! preg_match( $pattern, $buffer, $m, PREG_OFFSET_CAPTURE ) ) {
        // 2. Fallback: EPTA template (#epta-template wrapper para tribe_events single)
        $pattern_epta = '#(<div\b[^>]*id="epta-template"[^>]*>)#i';
        if ( preg_match( $pattern_epta, $buffer, $m, PREG_OFFSET_CAPTURE ) ) {
            // Usa div-balanceado a partir do epta wrapper
        } else {
            // 3. Fallback: TEC v2 archive (<div class="tribe-events-view...">)
            $pattern_tec_view = '#(<div\b[^>]*class="[^"]*tribe-events-view[^"]*"[^>]*>)#i';
            if ( preg_match( $pattern_tec_view, $buffer, $m, PREG_OFFSET_CAPTURE ) ) {
                // Wrap só com <main> (sem article — archive não é article)
                $tec_start = $m[0][1];
                $depth = 1;
                $pos = $tec_start + strlen( $m[0][0] );
                $end_pos = false;
                $len = strlen( $buffer );
                while ( $pos < $len && $depth > 0 ) {
                    $open = strpos( $buffer, '<div', $pos );
                    $close = strpos( $buffer, '</div>', $pos );
                    if ( $close === false ) break;
                    if ( $open !== false && $open < $close ) {
                        $depth++;
                        $pos = $open + 4;
                    } else {
                        $depth--;
                        $pos = $close + 6;
                        if ( $depth === 0 ) {
                            $end_pos = $pos;
                            break;
                        }
                    }
                }
                if ( $end_pos !== false ) {
                    return substr( $buffer, 0, $tec_start )
                         . '<main id="primary">'
                         . substr( $buffer, $tec_start, $end_pos - $tec_start )
                         . '</main>'
                         . substr( $buffer, $end_pos );
                }
            }
            return $buffer;  // sem root reconhecido → não mexer
        }
    }

    $start_pos = $m[0][1];
    $open_div = $m[0][0];

    // Achar o </div> que fecha esse data-elementor-type root.
    // Estratégia: contar divs balanceadas a partir do open. Conservadora.
    $depth = 1;
    $pos = $start_pos + strlen( $open_div );
    $end_pos = false;
    $len = strlen( $buffer );
    while ( $pos < $len && $depth > 0 ) {
        $open = strpos( $buffer, '<div', $pos );
        $close = strpos( $buffer, '</div>', $pos );
        if ( $close === false ) break;
        if ( $open !== false && $open < $close ) {
            $depth++;
            $pos = $open + 4;
        } else {
            $depth--;
            $pos = $close + 6;
            if ( $depth === 0 ) {
                $end_pos = $pos;
                break;
            }
        }
    }

    if ( $end_pos === false ) return $buffer;

    // Compor: <main id="primary"><article role="article" id="post-{ID}">[elementor div ...]</article></main>
    $post_id = get_the_ID();
    $article_open = '<article role="article" id="post-' . (int) $post_id . '">';
    $article_close = '</article>';
    $main_open = '<main id="primary">';
    $main_close = '</main>';

    // Decidir o que injetar com base no que já existe:
    $wrap_open = '';
    $wrap_close = '';
    if ( ! $has_main && ! $has_article ) {
        $wrap_open = $main_open . $article_open;
        $wrap_close = $article_close . $main_close;
    } elseif ( ! $has_main ) {
        $wrap_open = $main_open;
        $wrap_close = $main_close;
    } elseif ( ! $has_article ) {
        $wrap_open = $article_open;
        $wrap_close = $article_close;
    }

    return substr( $buffer, 0, $start_pos )
         . $wrap_open
         . substr( $buffer, $start_pos, $end_pos - $start_pos )
         . $wrap_close
         . substr( $buffer, $end_pos );
}

function end_ob() {
    if ( should_wrap() && ob_get_level() > 0 ) {
        @ob_end_flush();
    }
}

add_action( 'template_redirect', __NAMESPACE__ . '\\start_ob', 2 );
add_action( 'shutdown', __NAMESPACE__ . '\\end_ob', 998 );
