<?php
/**
 * Substituir Franie→Poppins, Just Sans→Rubik no Kit global do Elementor
 * (_elementor_page_settings, gravado como array PHP serializado — não JSON).
 *
 * Uso (sempre passando --url= correto para o blog):
 *   # DRY-RUN (default):
 *   wp --url='https://cambrasmax.local:8484/' eval-file scripts/update-kit-typography.php
 *   wp --url='https://cambrasmax.local:8484/cultura/' eval-file scripts/update-kit-typography.php
 *
 *   # APLICAR (precisa env var):
 *   KIT_APPLY=1 wp --url=... eval-file scripts/update-kit-typography.php
 *
 * Faz:
 *   1. Detecta blog atual (1 = kit 2553, 2 = kit 5)
 *   2. get_post_meta (array PHP nativo, sem json_decode)
 *   3. Substitui RECURSIVAMENTE qualquer chave terminando em "font_family" cujo
 *      valor seja exatamente "Franie" ou "Just Sans"
 *   4. update_post_meta (WP re-serializa automaticamente)
 *
 * Após aplicar:
 *   - wp elementor flush_css
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$apply = getenv( 'KIT_APPLY' ) === '1';
$mode  = $apply ? '** APLICAR **' : 'DRY-RUN';
$blog  = get_current_blog_id();
$kit_id = ( $blog === 2 ) ? 5 : 2553;
$map = [ 'Franie' => 'Poppins', 'Just Sans' => 'Rubik' ];

WP_CLI::log( '' );
WP_CLI::log( '═══════════════════════════════════════════════════' );
WP_CLI::log( "Modo: {$mode} | Blog ID: {$blog} | Kit ID: {$kit_id}" );
WP_CLI::log( '═══════════════════════════════════════════════════' );

$settings = get_post_meta( $kit_id, '_elementor_page_settings', true );

if ( ! is_array( $settings ) ) {
    WP_CLI::error( "Kit {$kit_id}: _elementor_page_settings não é um array (encontrado: " . gettype( $settings ) . ')' );
}

/**
 * Recursivamente substitui qualquer chave terminando em "font_family" cujo
 * valor seja exatamente Franie/Just Sans. Conta substituições via referência.
 */
function bit_replace_kit_font_recursive( &$node, &$counter, $map ) {
    if ( ! is_array( $node ) ) {
        return;
    }
    foreach ( $node as $key => &$value ) {
        if ( is_array( $value ) ) {
            bit_replace_kit_font_recursive( $value, $counter, $map );
        } elseif ( is_string( $key ) && str_ends_with( $key, 'font_family' ) && is_string( $value ) && isset( $map[ $value ] ) ) {
            $value = $map[ $value ];
            $counter++;
        }
    }
    unset( $value );
}

$counter = 0;
bit_replace_kit_font_recursive( $settings, $counter, $map );

WP_CLI::log( "Kit {$kit_id}: {$counter} substituições de font_family encontradas" );

// Aviso específico para o estilo custom_typography "Just Sans" (renomear título p/ clareza)
if ( isset( $settings['custom_typography'] ) && is_array( $settings['custom_typography'] ) ) {
    foreach ( $settings['custom_typography'] as &$style ) {
        if ( isset( $style['title'] ) && $style['title'] === 'Just Sans' ) {
            WP_CLI::log( "  → renomeando título do custom_typography '{$style['_id']}' de 'Just Sans' para 'Rubik'" );
            $style['title'] = 'Rubik';
        }
    }
    unset( $style );
}

if ( $counter === 0 ) {
    WP_CLI::success( "Kit {$kit_id}: nada a substituir." );
    return;
}

if ( $apply ) {
    $ok = update_post_meta( $kit_id, '_elementor_page_settings', $settings );
    if ( $ok === false ) {
        WP_CLI::warning( "Kit {$kit_id}: update_post_meta retornou false (pode já ter o mesmo valor)" );
    } else {
        WP_CLI::success( "Kit {$kit_id}: aplicado." );
    }
}

WP_CLI::log( '' );
if ( ! $apply ) {
    WP_CLI::log( '⚠ DRY-RUN — para aplicar, rodar:' );
    WP_CLI::log( '  KIT_APPLY=1 wp --url=... eval-file scripts/update-kit-typography.php' );
}
