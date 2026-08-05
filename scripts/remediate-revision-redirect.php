<?php
/**
 * Remediação do bug de redirect de update_post_meta() em revisões.
 *
 * update_post_meta($revision_id, ...) SEMPRE redireciona a escrita para o post
 * pai (wp_is_post_revision()), nunca escreve na própria revisão. O script
 * replace-franie-justsans.php (Fase 4) processou revisões antigas (que ainda
 * continham Franie/Just Sans) e, ao chamar update_post_meta() nelas, acabou
 * SOBRESCREVENDO os posts reais (pais) com conteúdo de revisões antigas
 * (apenas com o nome da fonte trocado) — revertendo edições legítimas feitas
 * depois daquela revisão.
 *
 * Este script corrige isso: para cada post real afetado, pega o conteúdo do
 * BACKUP pré-migração (fonte de verdade, salvo em exports/databases/ antes da
 * Fase 3), aplica a MESMA transformação de fonte que deveria ter sido aplicada,
 * e escreve isso diretamente no post real (nunca numa revisão, sem risco de
 * redirect). Idempotente-seguro: mesmo posts que não foram corrompidos ficam
 * exatamente iguais ao reaplicar.
 *
 * Uso:
 *   wp --url=... eval-file remediate-revision-redirect.php   (DRY-RUN default)
 *   REMEDIATE_APPLY=1 wp --url=... eval-file remediate-revision-redirect.php
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$apply = getenv( 'REMEDIATE_APPLY' ) === '1';
$mode  = $apply ? '** APLICAR **' : 'DRY-RUN';

WP_CLI::log( '' );
WP_CLI::log( '═══════════════════════════════════════════════════' );
WP_CLI::log( "Modo: {$mode}" );
WP_CLI::log( '═══════════════════════════════════════════════════' );

// IDs reais afetados via redirect de revisão em _elementor_data
$affected_data_posts = [
    90088, 44311, 80093, 80119, 72751, 8464, 76198, 4499, 4493, 91132, 91222,
    40498, 5684, 31342, 31078, 82230, 10336, 25851, 56769, 91599, 80130, 69756,
    65139, 5679, 71775, 32912, 92701, 44298, 72921, 52767, 53834, 16505, 51393,
    79123, 28187, 3626, 93085, 60306, 93077, 93493, 74762, 79178, 5824, 75718,
    72926, 26827, 1240, 71726, 72684, 10998, 2, 8442, 34870, 57418, 57102,
    47611, 47614, 70232, 72727, 672, 94330, 97, 14763, 72234, 2519, 2461,
    26826, 70697, 26666, 3777, 49045, 92840,
];

// Kit afetado via redirect de revisão em _elementor_page_settings
$affected_kit_posts = [ 2553 ];

$map = [ 'Franie' => 'Poppins', 'Just Sans' => 'Rubik' ];

function bit_swap_fonts_recursive( &$node, &$counter, $map ) {
    if ( is_array( $node ) || is_object( $node ) ) {
        foreach ( $node as $key => &$value ) {
            if ( is_string( $key ) && str_ends_with( $key, 'font_family' ) && is_string( $value ) && isset( $map[ $value ] ) ) {
                $value = $map[ $value ];
                $counter++;
            } elseif ( is_string( $value ) ) {
                $new_val = $value;
                foreach ( $map as $old => $new ) {
                    $pattern  = '/\b' . preg_quote( $old, '/' ) . '\b/';
                    $replaced = preg_replace( $pattern, $new, $new_val );
                    if ( $replaced !== $new_val ) {
                        $new_val = $replaced;
                    }
                }
                if ( $new_val !== $value ) {
                    $value = $new_val;
                    $counter++;
                }
            } else {
                bit_swap_fonts_recursive( $value, $counter, $map );
            }
        }
        unset( $value );
    }
}

// Conexão com o backup pré-migração restaurado em bit_scratch_restore
$backup_db = mysqli_connect( DB_HOST, 'root', getenv( 'BIT_SCRATCH_ROOT_PW' ), 'bit_scratch_restore' );
if ( ! $backup_db ) {
    WP_CLI::error( 'Não foi possível conectar ao banco de backup (bit_scratch_restore): ' . mysqli_connect_error() );
}

$total_fixed = 0;
$changed_post_ids = [];

// --- _elementor_data (JSON) ---
foreach ( $affected_data_posts as $post_id ) {
    $post_id = (int) $post_id;
    $res = mysqli_query( $backup_db, "SELECT meta_value FROM wp_postmeta WHERE post_id={$post_id} AND meta_key='_elementor_data'" );
    if ( ! $res || mysqli_num_rows( $res ) === 0 ) {
        WP_CLI::warning( "post_id={$post_id}: não encontrado no backup, pulando" );
        continue;
    }
    $row = mysqli_fetch_row( $res );
    $backup_raw = $row[0];

    $data = json_decode( $backup_raw, true );
    if ( $data === null && json_last_error() !== JSON_ERROR_NONE ) {
        WP_CLI::warning( "post_id={$post_id}: backup não decodifica como JSON (" . json_last_error_msg() . '), pulando' );
        continue;
    }

    $counter = 0;
    bit_swap_fonts_recursive( $data, $counter, $map );

    WP_CLI::log( sprintf( '  post_id=%6d  meta=_elementor_data           substituições=%d', $post_id, $counter ) );

    if ( $apply ) {
        $new_json = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        $ok = update_post_meta( $post_id, '_elementor_data', wp_slash( $new_json ) );
        if ( $ok === false ) {
            WP_CLI::warning( "post_id={$post_id}: update_post_meta retornou false" );
        }
    }
    $total_fixed++;
    $changed_post_ids[ $post_id ] = true;
}

// --- _elementor_page_settings (PHP-array) — Kit ---
foreach ( $affected_kit_posts as $post_id ) {
    $post_id = (int) $post_id;
    $res = mysqli_query( $backup_db, "SELECT meta_value FROM wp_postmeta WHERE post_id={$post_id} AND meta_key='_elementor_page_settings'" );
    if ( ! $res || mysqli_num_rows( $res ) === 0 ) {
        WP_CLI::warning( "kit post_id={$post_id}: não encontrado no backup, pulando" );
        continue;
    }
    $row = mysqli_fetch_row( $res );
    $data = unserialize( $row[0] );
    if ( ! is_array( $data ) ) {
        WP_CLI::warning( "kit post_id={$post_id}: backup não desserializa como array, pulando" );
        continue;
    }

    $counter = 0;
    bit_swap_fonts_recursive( $data, $counter, $map );

    // Replica a renomeação de título feita na Fase 3 (update-kit-typography.php)
    if ( isset( $data['custom_typography'] ) && is_array( $data['custom_typography'] ) ) {
        foreach ( $data['custom_typography'] as &$style ) {
            if ( isset( $style['title'] ) && $style['title'] === 'Just Sans' ) {
                $style['title'] = 'Rubik';
            }
        }
        unset( $style );
    }

    WP_CLI::log( sprintf( '  kit post_id=%6d  meta=_elementor_page_settings  substituições=%d', $post_id, $counter ) );

    if ( $apply ) {
        $ok = update_post_meta( $post_id, '_elementor_page_settings', $data );
        if ( $ok === false ) {
            WP_CLI::warning( "kit post_id={$post_id}: update_post_meta retornou false" );
        }
    }
    $total_fixed++;
    $changed_post_ids[ $post_id ] = true;
}

if ( $apply && ! empty( $changed_post_ids ) && class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
    WP_CLI::log( '' );
    WP_CLI::log( 'Invalidando cache CSS individual de ' . count( $changed_post_ids ) . ' post(s)...' );
    foreach ( array_keys( $changed_post_ids ) as $post_id ) {
        ( new \Elementor\Core\Files\CSS\Post( $post_id ) )->delete();
    }
}

WP_CLI::log( '' );
WP_CLI::log( '═══════════════════════════════════════════════════' );
WP_CLI::log( "Total remediado: {$total_fixed} posts (de " . ( count( $affected_data_posts ) + count( $affected_kit_posts ) ) . ' esperados)' );
WP_CLI::log( '═══════════════════════════════════════════════════' );

if ( ! $apply ) {
    WP_CLI::log( '' );
    WP_CLI::log( '⚠ DRY-RUN — para aplicar, rodar:' );
    WP_CLI::log( '  REMEDIATE_APPLY=1 wp --url=... eval-file remediate-revision-redirect.php' );
}
