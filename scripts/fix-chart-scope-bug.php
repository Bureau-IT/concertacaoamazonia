<?php
/**
 * Corrige bug de escopo JS no widget Google Charts do post 26645
 * ("Participantes"): drawChartExpertBottom(data, options) referenciava
 * `chart` como se fosse acessível — mas `chart` é var local de drawChart(),
 * função irmã, não visível dali. Causava "ReferenceError: chart is not
 * defined" no console, quebrando os event listeners de tooltip hover
 * (google.visualization.events.addListener) e o segundo chart.draw().
 *
 * Fix: passar `chart` como parâmetro explícito em vez de depender de escopo
 * compartilhado inexistente.
 *
 * Uso:
 *   wp --url=... eval-file fix-chart-scope-bug.php   (DRY-RUN default)
 *   FIX_CHART_APPLY=1 wp --url=... eval-file fix-chart-scope-bug.php
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$apply = getenv( 'FIX_CHART_APPLY' ) === '1';
$mode  = $apply ? '** APLICAR **' : 'DRY-RUN';
$post_id = 26645;

WP_CLI::log( "Modo: {$mode} | post_id={$post_id}" );

$raw = get_post_meta( $post_id, '_elementor_data', true );
$data = json_decode( $raw, true );
if ( $data === null && json_last_error() !== JSON_ERROR_NONE ) {
    WP_CLI::error( 'json_decode falhou: ' . json_last_error_msg() );
}

$old_call = 'drawChartExpertBottom(data, options);';
$new_call = 'drawChartExpertBottom(data, options, chart);';
$old_sig  = 'function drawChartExpertBottom(data, options) {';
$new_sig  = 'function drawChartExpertBottom(data, options, chart) {';

$found_call = 0;
$found_sig  = 0;

function bit_fix_chart_scope( &$node, &$found_call, &$found_sig, $old_call, $new_call, $old_sig, $new_sig ) {
    if ( ! is_array( $node ) ) { return; }
    foreach ( $node as $key => &$value ) {
        if ( $key === 'html' && is_string( $value ) && str_contains( $value, 'drawChartExpertBottom' ) ) {
            $c1 = substr_count( $value, $old_call );
            $c2 = substr_count( $value, $old_sig );
            if ( $c1 > 0 ) {
                $value = str_replace( $old_call, $new_call, $value );
                $found_call += $c1;
            }
            if ( $c2 > 0 ) {
                $value = str_replace( $old_sig, $new_sig, $value );
                $found_sig += $c2;
            }
        } elseif ( is_array( $value ) ) {
            bit_fix_chart_scope( $value, $found_call, $found_sig, $old_call, $new_call, $old_sig, $new_sig );
        }
    }
    unset( $value );
}

bit_fix_chart_scope( $data, $found_call, $found_sig, $old_call, $new_call, $old_sig, $new_sig );

WP_CLI::log( "Ocorrências corrigidas: chamada={$found_call} assinatura={$found_sig}" );

if ( $found_call === 0 || $found_sig === 0 ) {
    WP_CLI::error( 'Padrão esperado não encontrado — abortando sem aplicar (conteúdo pode ter mudado).' );
}

if ( $apply ) {
    $new_json = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
    $ok = update_post_meta( $post_id, '_elementor_data', wp_slash( $new_json ) );
    if ( $ok === false ) {
        WP_CLI::error( 'update_post_meta retornou false' );
    }
    $check = get_post_meta( $post_id, '_elementor_data', true );
    if ( json_decode( $check, true ) === null ) {
        WP_CLI::error( 'FALHA — _elementor_data corrompido após update_post_meta!' );
    }
    if ( ! str_contains( $check, $new_call ) || ! str_contains( $check, $new_sig ) ) {
        WP_CLI::error( 'FALHA — conteúdo pós-escrita não reflete o fix esperado!' );
    }
    WP_CLI::success( 'Aplicado e validado.' );

    if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
        ( new \Elementor\Core\Files\CSS\Post( $post_id ) )->delete();
    }
} else {
    WP_CLI::log( '⚠ DRY-RUN — para aplicar: FIX_CHART_APPLY=1 wp --url=... eval-file fix-chart-scope-bug.php' );
}
