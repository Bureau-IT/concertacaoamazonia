<?php
/**
 * Corrige fontName: "Roboto" -> fontName: "Poppins" em widgets Google Charts
 * que nunca foram Franie/Just Sans (por isso a migração original não os
 * tocou). Escopo estreito e literal: 6 posts conhecidos, string JS solta
 * dentro de settings.html (não é controle de tipografia do Elementor).
 *
 * Uso:
 *   wp --url=... eval-file fix-chart-roboto-poppins.php   (DRY-RUN default)
 *   FIX_ROBOTO_APPLY=1 wp --url=... eval-file fix-chart-roboto-poppins.php
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$apply = getenv( 'FIX_ROBOTO_APPLY' ) === '1';
$mode  = $apply ? '** APLICAR **' : 'DRY-RUN';

WP_CLI::log( "Modo: {$mode}" );

$post_ids = [ 3777, 5824, 26645, 31078, 18503, 29622 ];
$old = 'fontName: "Roboto"';
$new = 'fontName: "Poppins"';

$total = 0;

function bit_fix_roboto_recursive( &$node, &$counter, $old, $new ) {
    if ( ! is_array( $node ) ) { return; }
    foreach ( $node as $key => &$value ) {
        if ( $key === 'html' && is_string( $value ) && str_contains( $value, $old ) ) {
            $c = substr_count( $value, $old );
            $value = str_replace( $old, $new, $value );
            $counter += $c;
        } elseif ( is_array( $value ) ) {
            bit_fix_roboto_recursive( $value, $counter, $old, $new );
        }
    }
    unset( $value );
}

foreach ( $post_ids as $post_id ) {
    $raw = get_post_meta( $post_id, '_elementor_data', true );
    if ( empty( $raw ) ) {
        WP_CLI::warning( "post_id={$post_id}: _elementor_data vazio, pulando" );
        continue;
    }
    $data = json_decode( $raw, true );
    if ( $data === null && json_last_error() !== JSON_ERROR_NONE ) {
        WP_CLI::warning( "post_id={$post_id}: json_decode falhou (" . json_last_error_msg() . '), pulando' );
        continue;
    }

    $counter = 0;
    bit_fix_roboto_recursive( $data, $counter, $old, $new );

    WP_CLI::log( "post_id={$post_id}: {$counter} substituições" );

    if ( $counter === 0 ) {
        continue;
    }
    $total += $counter;

    if ( $apply ) {
        $new_json = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        $ok = update_post_meta( $post_id, '_elementor_data', wp_slash( $new_json ) );
        if ( $ok === false ) {
            WP_CLI::error( "post_id={$post_id}: update_post_meta retornou false" );
        }
        $check_raw = get_post_meta( $post_id, '_elementor_data', true );
        $check = json_decode( $check_raw, true );
        if ( $check === null ) {
            WP_CLI::error( "post_id={$post_id}: FALHA — _elementor_data corrompido após update_post_meta!" );
        }
        // Verifica na estrutura DECODIFICADA (strings PHP reais, sem escape de
        // JSON) — comparar contra o texto JSON bruto reintroduziria o mesmo
        // bug de escaping (\" vs ") que causou o falso-positivo anterior.
        $has_old = false;
        $has_new = false;
        array_walk_recursive( $check, function ( $v ) use ( &$has_old, &$has_new, $old, $new ) {
            if ( ! is_string( $v ) ) { return; }
            if ( str_contains( $v, $old ) ) { $has_old = true; }
            if ( str_contains( $v, $new ) ) { $has_new = true; }
        } );
        if ( $has_old || ! $has_new ) {
            WP_CLI::error( "post_id={$post_id}: FALHA — conteúdo pós-escrita não reflete o fix esperado! (old presente=" . ( $has_old ? 'sim' : 'não' ) . ", new presente=" . ( $has_new ? 'sim' : 'não' ) . ')' );
        }
        if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
            ( new \Elementor\Core\Files\CSS\Post( $post_id ) )->delete();
        }
    }
}

WP_CLI::log( '' );
WP_CLI::log( "Total: {$total} substituições" );

if ( ! $apply ) {
    WP_CLI::log( '⚠ DRY-RUN — para aplicar: FIX_ROBOTO_APPLY=1 wp --url=... eval-file fix-chart-roboto-poppins.php' );
} else {
    WP_CLI::success( 'Aplicado e validado.' );
}
