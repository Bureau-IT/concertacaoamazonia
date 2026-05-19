<?php
/**
 * Substituir "typography_font_family":"Barlow" → "Roboto" em _elementor_data
 *
 * Uso (sempre passando --url= correto para o blog):
 *   # DRY-RUN (default):
 *   wp --url='https://cambrasmax.local:8484/' eval-file replace-barlow-with-roboto.php
 *   wp --url='https://cambrasmax.local:8484/cultura/' eval-file replace-barlow-with-roboto.php
 *
 *   # APLICAR (precisa env var):
 *   BARLOW_APPLY=1 wp --url=... eval-file replace-barlow-with-roboto.php
 *
 * Faz:
 *   1. Detecta blog atual (1 = wp_postmeta, 2 = wp_2_postmeta)
 *   2. Busca todos os posts com `_elementor_data` contendo "Barlow"
 *   3. Para cada um:
 *        - Decodifica JSON, substitui RECURSIVAMENTE typography_font_family Barlow→Roboto
 *        - Re-encoda com wp_json_encode (preserva escape unicode)
 *        - update_post_meta com wp_slash (sem dobrar backslashes — wpdb faz para nós)
 *   4. Conta total de substituições + posts modificados
 *
 * Após aplicar:
 *   - wp elementor flush-css (regenerar CSS Elementor)
 *   - Warm-up explícito por post: (new \Elementor\Core\Files\CSS\Post($id))->update()
 *   - wp rocket clean cirúrgico por post_id
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$apply  = getenv( 'BARLOW_APPLY' ) === '1';
$mode   = $apply ? '** APLICAR **' : 'DRY-RUN';
$blog   = get_current_blog_id();
$prefix = $GLOBALS['wpdb']->prefix; // wp_ ou wp_2_

WP_CLI::log( "" );
WP_CLI::log( "═══════════════════════════════════════════════════" );
WP_CLI::log( "Modo: {$mode} | Blog ID: {$blog} | Prefix: {$prefix}" );
WP_CLI::log( "═══════════════════════════════════════════════════" );

global $wpdb;
$posts = $wpdb->get_results(
    "SELECT post_id, meta_value FROM {$wpdb->postmeta}
     WHERE meta_key = '_elementor_data'
       AND meta_value LIKE '%Barlow%'"
);

if ( empty( $posts ) ) {
    WP_CLI::success( 'Zero posts com typography_font_family Barlow neste blog.' );
    return;
}

WP_CLI::log( count( $posts ) . ' post(s) com Barlow detectado(s).' );
WP_CLI::log( '' );

$total_posts_changed  = 0;
$total_replacements   = 0;
$failed_posts         = [];

/**
 * Recursivamente substitui typography_font_family Barlow → Roboto em qualquer estrutura.
 * Conta substituições via referência.
 */
function bit_replace_barlow_recursive( &$node, &$counter ) {
    // Match qualquer chave que termine em "font_family" com valor começando por "Barlow".
    // Cobre: "Barlow", "Barlow Condensed", "Barlow Semi Condensed" → todos viram "Roboto".
    // Cobre: typography_font_family + qualquer prefixo (name_, title_, subitem_, cta_,
    //        hover_label_, normal_label_, pagination_items_, content_provider_, toggle_label_, etc.)
    $is_barlow = function( $value ) {
        return is_string( $value ) && ( $value === 'Barlow' || str_starts_with( $value, 'Barlow ' ) );
    };
    if ( is_array( $node ) ) {
        foreach ( $node as $key => &$value ) {
            if ( is_string( $key ) && str_ends_with( $key, 'font_family' ) && $is_barlow( $value ) ) {
                $value = 'Roboto';
                $counter++;
            } elseif ( is_string( $value ) && str_contains( $value, 'Barlow' ) ) {
                // String literal contendo Barlow em qualquer outro contexto.
                // Cobre HTML widgets com `fontName: "Barlow"` (Google Charts) e
                // CSS personalizado inline com `font-family: Barlow`.
                $new = preg_replace(
                    '/\bBarlow(?:\s+(?:Condensed|Semi\s+Condensed))?\b/',
                    'Roboto',
                    $value
                );
                if ( $new !== $value ) {
                    $value = $new;
                    $counter++;
                }
            } else {
                bit_replace_barlow_recursive( $value, $counter );
            }
        }
        unset( $value );
    } elseif ( is_object( $node ) ) {
        foreach ( $node as $key => &$value ) {
            if ( is_string( $key ) && str_ends_with( $key, 'font_family' ) && $is_barlow( $value ) ) {
                $value = 'Roboto';
                $counter++;
            } elseif ( is_string( $value ) && str_contains( $value, 'Barlow' ) ) {
                $new = preg_replace(
                    '/\bBarlow(?:\s+(?:Condensed|Semi\s+Condensed))?\b/',
                    'Roboto',
                    $value
                );
                if ( $new !== $value ) {
                    $value = $new;
                    $counter++;
                }
            } else {
                bit_replace_barlow_recursive( $value, $counter );
            }
        }
        unset( $value );
    }
}

foreach ( $posts as $row ) {
    $post_id = (int) $row->post_id;

    // $wpdb->get_results retorna RAW (sem stripslashes) — usar como veio
    $raw  = $row->meta_value;
    $data = json_decode( $raw, true );

    if ( $data === null && json_last_error() !== JSON_ERROR_NONE ) {
        $failed_posts[] = "{$post_id} (decode: " . json_last_error_msg() . ')';
        continue;
    }

    $counter = 0;
    bit_replace_barlow_recursive( $data, $counter );

    if ( $counter === 0 ) {
        // Não deveria acontecer (LIKE bateu mas estrutura não tem typography_font_family Barlow puro)
        WP_CLI::warning( "post_id={$post_id} — LIKE bateu mas substituição=0 (talvez Barlow em outra chave)" );
        continue;
    }

    $total_posts_changed++;
    $total_replacements += $counter;

    WP_CLI::log( sprintf( '  post_id=%6d  substituições=%d', $post_id, $counter ) );

    if ( $apply ) {
        $new_json = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        if ( $new_json === false ) {
            $failed_posts[] = "{$post_id} (encode falhou)";
            continue;
        }
        // update_post_meta faz stripslashes_deep no value antes de salvar; precisa wp_slash p/ neutralizar.
        $ok = update_post_meta( $post_id, '_elementor_data', wp_slash( $new_json ) );
        if ( ! $ok ) {
            $failed_posts[] = "{$post_id} (update_post_meta retornou false)";
        }
    }
}

WP_CLI::log( '' );
WP_CLI::log( '═══════════════════════════════════════════════════' );
WP_CLI::log( "Resumo: {$total_posts_changed} posts modificados, {$total_replacements} substituições" );
if ( ! empty( $failed_posts ) ) {
    WP_CLI::warning( 'Falhas: ' . implode( ', ', $failed_posts ) );
}
WP_CLI::log( '═══════════════════════════════════════════════════' );

if ( ! $apply ) {
    WP_CLI::log( '' );
    WP_CLI::log( '⚠ DRY-RUN — para aplicar, rodar:' );
    WP_CLI::log( '  BARLOW_APPLY=1 wp --url=... eval-file replace-barlow-with-roboto.php' );
}
