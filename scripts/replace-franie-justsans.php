<?php
/**
 * Substituir Franie→Poppins, Just Sans→Rubik em overrides literais por widget
 * (_elementor_data JSON + _elementor_page_settings PHP-serializado).
 *
 * Modelado em scripts/replace-barlow-with-roboto.php (migração de fonte anterior).
 *
 * Uso (sempre passando --url= correto para o blog):
 *   # DRY-RUN (default):
 *   wp --url='https://cambrasmax.local:8484/' eval-file replace-franie-justsans.php
 *   wp --url='https://cambrasmax.local:8484/cultura/' eval-file replace-franie-justsans.php
 *
 *   # APLICAR (precisa env var):
 *   FONT_SWAP_APPLY=1 wp --url=... eval-file replace-franie-justsans.php
 *
 * Faz:
 *   1. Detecta blog atual (1 = wp_postmeta, 2 = wp_2_postmeta)
 *   2. Busca todos os posts com _elementor_data OU _elementor_page_settings
 *      contendo "Franie" ou "Just Sans" (NÃO toca _elementor_css nem
 *      _elementor_data_backup* — cache/histórico, fora de escopo)
 *   3. Para cada um:
 *        - _elementor_data (JSON): decodifica, substitui recursivamente,
 *          re-encoda com wp_json_encode + wp_slash antes de update_post_meta
 *        - _elementor_page_settings (PHP-array): get/update_post_meta nativos
 *        - Chave terminando em "font_family" com valor EXATO Franie/Just Sans → troca
 *        - Qualquer outra string contendo Franie/Just Sans (ex: fontName do
 *          Google Charts) → regex com word-boundary (\bFranie\b, \bJust Sans\b)
 *   4. Invalida cache CSS individual do post (Elementor\Core\Files\CSS\Post::delete)
 *   5. Conta total de substituições + posts modificados
 *
 * Após aplicar:
 *   - wp elementor flush_css (regenerar CSS Elementor, varredura final)
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$apply  = getenv( 'FONT_SWAP_APPLY' ) === '1';
$mode   = $apply ? '** APLICAR **' : 'DRY-RUN';
$blog   = get_current_blog_id();
$prefix = $GLOBALS['wpdb']->prefix; // wp_ ou wp_2_
$map    = [ 'Franie' => 'Poppins', 'Just Sans' => 'Rubik' ];

WP_CLI::log( '' );
WP_CLI::log( '═══════════════════════════════════════════════════' );
WP_CLI::log( "Modo: {$mode} | Blog ID: {$blog} | Prefix: {$prefix}" );
WP_CLI::log( '═══════════════════════════════════════════════════' );

global $wpdb;

/**
 * Recursivamente substitui font_family (match exato) e strings livres
 * (match word-boundary) em qualquer estrutura de array/objeto.
 */
function bit_swap_fonts_recursive( &$node, &$counter, $map ) {
    if ( is_array( $node ) || is_object( $node ) ) {
        foreach ( $node as $key => &$value ) {
            if ( is_string( $key ) && str_ends_with( $key, 'font_family' ) && is_string( $value ) && isset( $map[ $value ] ) ) {
                $value = $map[ $value ];
                $counter++;
            } elseif ( is_string( $value ) ) {
                $new_val = $value;
                foreach ( $map as $old => $new ) {
                    $pattern = '/\b' . preg_quote( $old, '/' ) . '\b/';
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

$meta_keys = [ '_elementor_data', '_elementor_page_settings' ];
$total_posts_changed = 0;
$total_replacements  = 0;
$failed_posts         = [];
$changed_post_ids      = [];

foreach ( $meta_keys as $meta_key ) {
    // JOIN com wp_posts + exclusão de post_type='revision': update_post_meta()
    // SEMPRE redireciona escritas em revisões para o post pai (wp_is_post_revision()
    // em wp-includes/post.php) — processar revisões aqui sobrescreveria posts reais
    // com conteúdo de snapshots antigos. Ver scripts/remediate-revision-redirect.php
    // (incidente documentado durante blog 1, corrigido antes de rodar no blog 2).
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = %s
               AND p.post_type != 'revision'
               AND (pm.meta_value LIKE %s OR pm.meta_value LIKE %s)",
            $meta_key,
            '%Franie%',
            '%Just Sans%'
        )
    );

    WP_CLI::log( '' );
    WP_CLI::log( count( $rows ) . " post(s) com meta_key={$meta_key} detectado(s)." );

    foreach ( $rows as $row ) {
        $post_id = (int) $row->post_id;
        $counter = 0;

        if ( $meta_key === '_elementor_data' ) {
            // JSON path — mesmo caminho do script Barlow
            $raw  = $row->meta_value; // RAW (sem stripslashes) — $wpdb->get_results retorna assim
            $data = json_decode( $raw, true );

            if ( $data === null && json_last_error() !== JSON_ERROR_NONE ) {
                $failed_posts[] = "{$post_id}/{$meta_key} (decode: " . json_last_error_msg() . ')';
                continue;
            }

            bit_swap_fonts_recursive( $data, $counter, $map );

            if ( $counter === 0 ) {
                WP_CLI::warning( "post_id={$post_id} meta={$meta_key} — LIKE bateu mas substituição=0" );
                continue;
            }

            WP_CLI::log( sprintf( '  post_id=%6d  meta=%-24s substituições=%d', $post_id, $meta_key, $counter ) );

            if ( $apply ) {
                $new_json = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
                if ( $new_json === false ) {
                    $failed_posts[] = "{$post_id}/{$meta_key} (encode falhou)";
                    continue;
                }
                $ok = update_post_meta( $post_id, $meta_key, wp_slash( $new_json ) );
                if ( ! $ok ) {
                    $failed_posts[] = "{$post_id}/{$meta_key} (update_post_meta retornou false)";
                }
            }
        } else {
            // PHP-serialized path — _elementor_page_settings
            $data = get_post_meta( $post_id, $meta_key, true ); // auto-unserializado

            if ( ! is_array( $data ) ) {
                $failed_posts[] = "{$post_id}/{$meta_key} (não é array: " . gettype( $data ) . ')';
                continue;
            }

            bit_swap_fonts_recursive( $data, $counter, $map );

            if ( $counter === 0 ) {
                WP_CLI::warning( "post_id={$post_id} meta={$meta_key} — LIKE bateu mas substituição=0" );
                continue;
            }

            WP_CLI::log( sprintf( '  post_id=%6d  meta=%-24s substituições=%d', $post_id, $meta_key, $counter ) );

            if ( $apply ) {
                $ok = update_post_meta( $post_id, $meta_key, $data ); // WP re-serializa
                if ( ! $ok ) {
                    $failed_posts[] = "{$post_id}/{$meta_key} (update_post_meta retornou false)";
                }
            }
        }

        $total_posts_changed++;
        $total_replacements += $counter;
        $changed_post_ids[ $post_id ] = true;
    }
}

// Invalida cache CSS individual dos posts alterados
if ( $apply && ! empty( $changed_post_ids ) && class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
    WP_CLI::log( '' );
    WP_CLI::log( 'Invalidando cache CSS individual de ' . count( $changed_post_ids ) . ' post(s)...' );
    foreach ( array_keys( $changed_post_ids ) as $post_id ) {
        ( new \Elementor\Core\Files\CSS\Post( $post_id ) )->delete();
    }
}

WP_CLI::log( '' );
WP_CLI::log( '═══════════════════════════════════════════════════' );
WP_CLI::log( "Resumo: {$total_posts_changed} (post_id,meta_key) modificados, {$total_replacements} substituições" );
if ( ! empty( $failed_posts ) ) {
    WP_CLI::warning( 'Falhas: ' . implode( ', ', $failed_posts ) );
}
WP_CLI::log( '═══════════════════════════════════════════════════' );

if ( ! $apply ) {
    WP_CLI::log( '' );
    WP_CLI::log( '⚠ DRY-RUN — para aplicar, rodar:' );
    WP_CLI::log( '  FONT_SWAP_APPLY=1 wp --url=... eval-file replace-franie-justsans.php' );
}
