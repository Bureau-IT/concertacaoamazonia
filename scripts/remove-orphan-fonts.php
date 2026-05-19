<?php
/**
 * Remove fontes órfãs do projeto: Merriweather + Tan Pearl
 *
 * Limpeza em 4 passos:
 *   1. _elementor_data — substituir typography_font_family Merriweather/Tan Pearl → Roboto
 *   2. CPT elementor_font — deletar (78477, 78483 Merriweather; 36516 Tan Pearl)
 *   3. Attachments dos arquivos de fonte (.ttf/.woff2/.otf em uploads/)
 *   4. Revisar wp_options.elementor_fonts_manager_fonts (se aplicável)
 *
 * Uso:
 *   wp --url='https://cambrasmax.local:8484/' eval-file remove-orphan-fonts.php
 *   FONTS_APPLY=1 wp --url=... eval-file remove-orphan-fonts.php
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$apply  = getenv( 'FONTS_APPLY' ) === '1';
$mode   = $apply ? '** APLICAR **' : 'DRY-RUN';
$blog   = get_current_blog_id();
$prefix = $GLOBALS['wpdb']->prefix;

// Famílias órfãs (não oficiais pelo Manual de Marca §3.9)
$orphan_fonts = [ 'Merriweather', 'Merriweather Italic', 'Tan Pearl' ];

WP_CLI::log( '' );
WP_CLI::log( '═══════════════════════════════════════════════════' );
WP_CLI::log( "Modo: {$mode} | Blog ID: {$blog} | Prefix: {$prefix}" );
WP_CLI::log( "Fontes órfãs alvo: " . implode( ', ', $orphan_fonts ) );
WP_CLI::log( '═══════════════════════════════════════════════════' );

global $wpdb;

// ── 1. Substituir em _elementor_data ─────────────────────────────────────
$like_clauses = array_map( fn( $f ) => "meta_value LIKE '%{$f}%'", $orphan_fonts );
$where        = implode( ' OR ', $like_clauses );
$posts        = $wpdb->get_results(
    "SELECT post_id, meta_value FROM {$wpdb->postmeta}
     WHERE meta_key = '_elementor_data' AND ({$where})"
);

WP_CLI::log( '' );
WP_CLI::log( '── Passo 1: _elementor_data ──' );
WP_CLI::log( count( $posts ) . ' post(s) com fonte órfã detectado(s).' );

function bit_replace_orphan_recursive( &$node, &$counter, $orphans ) {
    $is_orphan = function( $value ) use ( $orphans ) {
        return is_string( $value ) && in_array( $value, $orphans, true );
    };
    if ( is_array( $node ) ) {
        foreach ( $node as $key => &$value ) {
            if ( is_string( $key ) && str_ends_with( $key, 'font_family' ) && $is_orphan( $value ) ) {
                $value = 'Roboto';
                $counter++;
            } elseif ( is_string( $value ) ) {
                foreach ( $orphans as $orphan ) {
                    if ( str_contains( $value, $orphan ) ) {
                        $value = str_replace( $orphan, 'Roboto', $value );
                        $counter++;
                    }
                }
            } else {
                bit_replace_orphan_recursive( $value, $counter, $orphans );
            }
        }
        unset( $value );
    } elseif ( is_object( $node ) ) {
        foreach ( $node as $key => &$value ) {
            if ( is_string( $key ) && str_ends_with( $key, 'font_family' ) && $is_orphan( $value ) ) {
                $value = 'Roboto';
                $counter++;
            } elseif ( is_string( $value ) ) {
                foreach ( $orphans as $orphan ) {
                    if ( str_contains( $value, $orphan ) ) {
                        $value = str_replace( $orphan, 'Roboto', $value );
                        $counter++;
                    }
                }
            } else {
                bit_replace_orphan_recursive( $value, $counter, $orphans );
            }
        }
        unset( $value );
    }
}

$total_posts_changed = 0;
$total_replacements  = 0;
foreach ( $posts as $row ) {
    $post_id = (int) $row->post_id;
    $data    = json_decode( $row->meta_value, true );
    if ( $data === null ) { continue; }
    $counter = 0;
    bit_replace_orphan_recursive( $data, $counter, $orphan_fonts );
    if ( $counter === 0 ) { continue; }
    $total_posts_changed++;
    $total_replacements += $counter;
    WP_CLI::log( sprintf( '  post_id=%6d  substituições=%d', $post_id, $counter ) );
    if ( $apply ) {
        $new_json = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        update_post_meta( $post_id, '_elementor_data', wp_slash( $new_json ) );
    }
}
WP_CLI::log( "Resumo passo 1: {$total_posts_changed} posts, {$total_replacements} substituições" );

// ── 2. Deletar CPT elementor_font ─────────────────────────────────────────
WP_CLI::log( '' );
WP_CLI::log( '── Passo 2: CPT elementor_font ──' );
$font_titles_like = array_map( fn( $f ) => "post_title LIKE '%{$f}%'", $orphan_fonts );
$where_fonts      = implode( ' OR ', $font_titles_like );
$font_cpts        = $wpdb->get_results(
    "SELECT ID, post_title FROM {$wpdb->posts}
     WHERE post_type = 'elementor_font' AND ({$where_fonts})"
);

foreach ( $font_cpts as $cpt ) {
    WP_CLI::log( "  ID={$cpt->ID}  title=\"{$cpt->post_title}\"" );
    if ( $apply ) {
        wp_delete_post( (int) $cpt->ID, true );  // true = force (sem trash)
    }
}
WP_CLI::log( 'Resumo passo 2: ' . count( $font_cpts ) . ' CPT(s) elementor_font alvo' );

// ── 3. Limpar wp_options.elementor_fonts_manager_fonts ───────────────────
WP_CLI::log( '' );
WP_CLI::log( '── Passo 3: wp_options.elementor_fonts_manager_fonts ──' );
$manager_opt = get_option( 'elementor_fonts_manager_fonts' );
if ( is_array( $manager_opt ) && ! empty( $manager_opt ) ) {
    $original_count = count( $manager_opt );
    $filtered       = array_filter( $manager_opt, function( $key ) use ( $orphan_fonts ) {
        foreach ( $orphan_fonts as $orphan ) {
            if ( str_contains( strtolower( $key ), strtolower( $orphan ) ) ) return false;
        }
        return true;
    }, ARRAY_FILTER_USE_KEY );
    $removed = $original_count - count( $filtered );
    WP_CLI::log( "  Fonts no manager option: {$original_count}, removeríamos {$removed}." );
    if ( $apply && $removed > 0 ) {
        update_option( 'elementor_fonts_manager_fonts', $filtered );
        WP_CLI::log( "  ✓ Manager option atualizada." );
    }
} else {
    WP_CLI::log( '  (option vazia ou ausente neste blog)' );
}

WP_CLI::log( '' );
WP_CLI::log( '═══════════════════════════════════════════════════' );
if ( ! $apply ) {
    WP_CLI::log( '⚠ DRY-RUN — para aplicar:' );
    WP_CLI::log( '  FONTS_APPLY=1 wp --url=... eval-file remove-orphan-fonts.php' );
}
WP_CLI::log( '═══════════════════════════════════════════════════' );
