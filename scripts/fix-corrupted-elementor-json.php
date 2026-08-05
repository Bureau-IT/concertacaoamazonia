<?php
/**
 * Aplica _elementor_data reparado (JSON corrompido: aspas HTML não escapadas +
 * escapes unicode sem backslash) a partir de arquivo previamente reconstruído
 * e validado (json.loads bem-sucedido em Python, sanity-check semântico feito
 * manualmente). Ver scripts/replace-franie-justsans.php para o contexto de
 * como esses 2 posts (72726, 92313) foram descobertos.
 *
 * Uso:
 *   wp --url=... eval-file fix-corrupted-elementor-json.php   (DRY-RUN default)
 *   FIX_JSON_APPLY=1 wp --url=... eval-file fix-corrupted-elementor-json.php
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$apply = getenv( 'FIX_JSON_APPLY' ) === '1';
$mode  = $apply ? '** APLICAR **' : 'DRY-RUN';

WP_CLI::log( "Modo: {$mode}" );

$map = [
    72726 => '/tmp/post-72726-fixed.json',
    92313 => '/tmp/post-92313-fixed.json',
];

foreach ( $map as $post_id => $file ) {
    if ( ! file_exists( $file ) ) {
        WP_CLI::warning( "post_id={$post_id}: arquivo {$file} não encontrado, pulando" );
        continue;
    }
    $fixed_json = file_get_contents( $file );
    $decoded = json_decode( $fixed_json, true );
    if ( $decoded === null && json_last_error() !== JSON_ERROR_NONE ) {
        WP_CLI::warning( "post_id={$post_id}: arquivo reparado não decodifica (" . json_last_error_msg() . '), pulando' );
        continue;
    }

    $old_raw = get_post_meta( $post_id, '_elementor_data', true );
    $old_len = strlen( $old_raw );
    $new_json = wp_json_encode( $decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
    $new_len = strlen( $new_json );

    WP_CLI::log( "post_id={$post_id}: old_len={$old_len} new_len={$new_len}" );

    if ( $apply ) {
        $ok = update_post_meta( $post_id, '_elementor_data', wp_slash( $new_json ) );
        if ( $ok === false ) {
            WP_CLI::warning( "post_id={$post_id}: update_post_meta retornou false" );
            continue;
        }
        // Verificação pós-escrita (gotcha wp_slash/stripslashes_deep documentado no projeto)
        $check = get_post_meta( $post_id, '_elementor_data', true );
        if ( json_decode( $check, true ) === null ) {
            WP_CLI::error( "post_id={$post_id}: FALHA — _elementor_data corrompido após update_post_meta!" );
        }
        WP_CLI::success( "post_id={$post_id}: aplicado e validado (json_decode OK pós-escrita)." );

        if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
            ( new \Elementor\Core\Files\CSS\Post( $post_id ) )->delete();
        }
    }
}

if ( ! $apply ) {
    WP_CLI::log( '' );
    WP_CLI::log( '⚠ DRY-RUN — para aplicar, rodar:' );
    WP_CLI::log( '  FIX_JSON_APPLY=1 wp --url=... eval-file fix-corrupted-elementor-json.php' );
}
