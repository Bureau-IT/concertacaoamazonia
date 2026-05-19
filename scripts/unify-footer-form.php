<?php
/**
 * unify-footer-form.php — One-shot idempotente.
 *
 * Unifica os 2 widgets Form do template footer 72234 (containers
 * d1e32f6 desktop/tablet e 3e45cefe mobile) em UM único widget device-aware.
 *
 * Uso (via WP-CLI, blog 1 raiz do multisite):
 *   docker exec -u www-data concertacao-dev-wordpress \
 *     wp --url="https://cambrasmax.local:8484/" \
 *     eval-file /var/www/html/wp-content/uploads/_scripts/unify-footer-form.php [APPLY]
 *
 * Sem argumento (dry-run padrão): imprime o diff esperado sem salvar nada.
 * Com argumento "APPLY": aplica e persiste no banco.
 *
 * Idempotente: se o widget mobile (18af5b7) não for encontrado, assume que já
 * foi aplicado e sai com exit 0 ("nada a fazer").
 *
 * O que faz:
 *   1. Carrega _elementor_data do post 72234.
 *   2. Acha o widget Form desktop (e85e505) e o mobile (18af5b7).
 *   3. Copia form_name do mobile → form_name_mobile do desktop.
 *   4. Para cada field do desktop encontra equivalente no mobile por custom_id
 *      (com alias: form_regiao ↔ form_email_regiao) e copia placeholder →
 *      placeholder_mobile e field_options_empty → field_options_empty_mobile.
 *   5. No select Região (form_regiao): detecta se a primeira option é a literal
 *      "Região" ou "Região | Região", remove-a e define field_options_empty="Região"
 *      (placeholder via campo dedicado — corrige bug de "Região" sendo enviado).
 *   6. Remove hide_mobile / hide_tablet / hide_desktop do container desktop
 *      (d1e32f6) para exibir em todos os devices.
 *   7. Remove o container mobile inteiro (3e45cefe).
 *   8. Salva com wp_slash(wp_json_encode()) — obrigatório, ver feedback_elementor_data_wp_slash_required.
 *   9. Flush do cache Elementor + warmup CSS do template 72234 — obrigatório,
 *      ver feedback_elementor_flush_css_warmup.
 *
 * Spec: docs/superpowers/specs/2026-05-19-formulario-rodape-rdstation-design.md
 * Plano: docs/superpowers/plans/2026-05-19-formulario-rodape-unificado-parte1.md (Task 7)
 *
 * Exit codes:
 *   0 — sucesso (dry-run ou apply OK, ou idempotente: nada a fazer)
 *   1 — erro fatal (dados ausentes, JSON inválido, widget não encontrado)
 *   2 — erro de uso (argumentos inválidos)
 */

if ( ! defined( 'ABSPATH' ) ) {
    fwrite( STDERR, "Run via wp eval-file\n" );
    fwrite( STDERR, "Exemplo: docker exec -u www-data concertacao-dev-wordpress wp --url=\"https://cambrasmax.local:8484/\" eval-file /var/www/html/wp-content/uploads/_scripts/unify-footer-form.php [APPLY]\n" );
    exit( 1 );
}

// --help / -h
if ( isset( $args[0] ) && in_array( $args[0], [ '--help', '-h' ], true ) ) {
    echo "Uso: wp eval-file unify-footer-form.php [APPLY]\n";
    echo "\n";
    echo "  (sem argumento)   Dry-run: imprime diff esperado, não altera banco.\n";
    echo "  APPLY             Aplica as mudancas e persiste no banco.\n";
    echo "  --help | -h       Exibe esta ajuda.\n";
    echo "\n";
    echo "Template alvo: post 72234 (footer template, blog 1 raiz do multisite).\n";
    echo "Idempotente: re-rodar apos APPLY reporta 'nada a fazer' e sai 0.\n";
    exit( 0 );
}

if ( isset( $args[0] ) && ! in_array( strtoupper( $args[0] ), [ 'APPLY' ], true ) ) {
    fwrite( STDERR, "Argumento invalido: '" . $args[0] . "'. Use 'APPLY' ou omita para dry-run. Use --help para ajuda.\n" );
    exit( 2 );
}

$apply = isset( $args[0] ) && strtoupper( $args[0] ) === 'APPLY';

$template_id       = 72234;
$container_desktop = 'd1e32f6';   // container a manter (desktop/tablet)
$container_mobile  = '3e45cefe';  // container a remover após copiar metadados
$widget_id_desktop = 'e85e505';   // Form widget dentro do container desktop
$widget_id_mobile  = '18af5b7';   // Form widget dentro do container mobile

echo "─── unify-footer-form.php " . ( $apply ? '[APPLY MODE]' : '[DRY-RUN]' ) . " ───\n";

// Garantir contexto no blog 1 (onde o template footer 72234 vive)
if ( function_exists( 'switch_to_blog' ) ) {
    switch_to_blog( 1 );
}

$raw = get_post_meta( $template_id, '_elementor_data', true );
if ( empty( $raw ) ) {
    fwrite( STDERR, "ERRO: _elementor_data vazio para template $template_id\n" );
    if ( function_exists( 'restore_current_blog' ) ) { restore_current_blog(); }
    exit( 1 );
}

$bytes_before = strlen( is_string( $raw ) ? $raw : wp_json_encode( $raw ) );

$data = is_array( $raw ) ? $raw : json_decode( wp_unslash( $raw ), true );
if ( ! is_array( $data ) ) {
    fwrite( STDERR, "ERRO: _elementor_data nao e JSON valido (json_last_error=" . json_last_error_msg() . ")\n" );
    if ( function_exists( 'restore_current_blog' ) ) { restore_current_blog(); }
    exit( 1 );
}

echo "OK: _elementor_data carregado ($bytes_before bytes, " . count( $data ) . " elementos raiz)\n";

// ---------------------------------------------------------------------------
// Helpers recursivos
// ---------------------------------------------------------------------------

/**
 * Busca elemento por id na arvore $nodes e retorna referencia mutavel.
 * Retorna null (por referencia) se nao encontrado.
 */
function &bit_find_by_id( array &$nodes, string $id ) {
    $null = null; // fallback para referencia quando nao encontrado
    foreach ( $nodes as &$node ) {
        if ( ( $node['id'] ?? '' ) === $id ) {
            return $node;
        }
        if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
            $sub = &bit_find_by_id( $node['elements'], $id );
            if ( $sub !== null ) {
                return $sub;
            }
        }
    }
    unset( $node );
    return $null;
}

/**
 * Remove elemento por id da arvore $nodes (busca recursiva).
 * Retorna true se removido, false se nao encontrado.
 */
function bit_remove_by_id( array &$nodes, string $id ): bool {
    foreach ( $nodes as $i => &$node ) {
        if ( ( $node['id'] ?? '' ) === $id ) {
            array_splice( $nodes, $i, 1 );
            return true;
        }
        if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
            if ( bit_remove_by_id( $node['elements'], $id ) ) {
                return true;
            }
        }
    }
    unset( $node );
    return false;
}

// ---------------------------------------------------------------------------
// Localizar os 2 widgets
// ---------------------------------------------------------------------------

$desktop_form = &bit_find_by_id( $data, $widget_id_desktop );
$mobile_form  = &bit_find_by_id( $data, $widget_id_mobile );

if ( $desktop_form === null ) {
    fwrite( STDERR, "ERRO: widget desktop $widget_id_desktop nao encontrado no _elementor_data\n" );
    if ( function_exists( 'restore_current_blog' ) ) { restore_current_blog(); }
    exit( 1 );
}

if ( $mobile_form === null ) {
    echo "AVISO: widget mobile $widget_id_mobile nao encontrado — script provavelmente ja rodou.\n";
    echo "Idempotente: nada a fazer.\n";
    if ( function_exists( 'restore_current_blog' ) ) {
        restore_current_blog();
    }
    exit( 0 );
}

echo "OK: widget desktop $widget_id_desktop encontrado\n";
echo "OK: widget mobile $widget_id_mobile encontrado\n";

// Guard: form_fields precisa estar presente e ser array
if ( empty( $desktop_form['settings']['form_fields'] ) || ! is_array( $desktop_form['settings']['form_fields'] ) ) {
    fwrite( STDERR, "ERRO: widget desktop nao tem form_fields — template corrompido?\n" );
    if ( function_exists( 'restore_current_blog' ) ) { restore_current_blog(); }
    exit( 1 );
}

// ---------------------------------------------------------------------------
// 1. Copiar form_name do mobile para form_name_mobile no desktop
// ---------------------------------------------------------------------------

$desktop_settings = &$desktop_form['settings'];
$mobile_settings  =  $mobile_form['settings']; // copia, nao referencia (so leitura)

$desktop_settings['form_name_mobile'] = $mobile_settings['form_name'] ?? '';
echo "+ form_name_mobile = " . json_encode( $desktop_settings['form_name_mobile'] ) . "\n";

// ---------------------------------------------------------------------------
// 2. Para cada field do desktop, localizar equivalente no mobile e copiar
//    placeholder e field_options_empty para os sufixos _mobile
// ---------------------------------------------------------------------------

if ( ! empty( $desktop_settings['form_fields'] ) && is_array( $desktop_settings['form_fields'] ) ) {
    foreach ( $desktop_settings['form_fields'] as &$d_field ) {
        $cid = $d_field['custom_id'] ?? '';
        if ( ! $cid ) {
            continue;
        }

        // Alias: mobile usa "form_email_regiao" para o campo que no desktop e "form_regiao"
        $mobile_aliases = ( $cid === 'form_regiao' ) ? [ 'form_regiao', 'form_email_regiao' ] : [ $cid ];

        $m_match = null;
        foreach ( $mobile_settings['form_fields'] ?? [] as $m_field ) {
            if ( in_array( $m_field['custom_id'] ?? '', $mobile_aliases, true ) ) {
                $m_match = $m_field;
                break;
            }
        }

        if ( ! $m_match ) {
            echo "  AVISO: field[$cid] sem equivalente no mobile — ignorado\n";
            continue;
        }

        // Copiar placeholder somente se for diferente do desktop (evita ruido no dry-run)
        $ph_mobile = $m_match['placeholder'] ?? '';
        if ( $ph_mobile !== '' && ( $d_field['placeholder'] ?? '' ) !== $ph_mobile ) {
            $d_field['placeholder_mobile'] = $ph_mobile;
            echo "+ field[$cid].placeholder_mobile = " . json_encode( $ph_mobile ) . "\n";
        }

        // Copiar field_options_empty do mobile
        $foe_mobile = $m_match['field_options_empty'] ?? '';
        if ( $foe_mobile !== '' ) {
            $d_field['field_options_empty_mobile'] = $foe_mobile;
            echo "+ field[$cid].field_options_empty_mobile = " . json_encode( $foe_mobile ) . "\n";
        }

        // -----------------------------------------------------------------------
        // 3. Bug "Regiao como primeira option valida": corrigir no widget desktop.
        //    Detecta "Região" ou "Região | Região" como primeira linha de field_options,
        //    remove-a, e seta field_options_empty para usar o campo dedicado de placeholder.
        // -----------------------------------------------------------------------
        if ( $cid === 'form_regiao' && ! empty( $d_field['field_options'] ) ) {
            $lines = explode( "\n", $d_field['field_options'] );
            $first = trim( $lines[0] );
            // Aceita: "Região", "Região | Região", e variações com acento normalizado
            $is_regiao_placeholder = ( $first === 'Região' || $first === 'Região | Região' );
            if ( $is_regiao_placeholder ) {
                array_shift( $lines );
                $d_field['field_options']       = implode( "\n", $lines );
                $d_field['field_options_empty']  = 'Região';
                echo "+ field[$cid].field_options: removida primeira linha '$first' (era placeholder invalido)\n";
                echo "+ field[$cid].field_options_empty = \"Região\" (placeholder via campo dedicado — corrige bug de valor enviado)\n";
            } else {
                echo "  INFO: field[$cid] primeira opcao e '$first' — nao e placeholder Regiao, ignorando\n";
            }
        }
    }
    unset( $d_field );
}

// ---------------------------------------------------------------------------
// 4. Remover restricoes hide_* do container desktop para exibir em todos os devices
// ---------------------------------------------------------------------------

$desktop_container = &bit_find_by_id( $data, $container_desktop );
if ( $desktop_container !== null && ! empty( $desktop_container['settings'] ) ) {
    foreach ( [ 'hide_mobile', 'hide_tablet', 'hide_desktop' ] as $k ) {
        if ( isset( $desktop_container['settings'][ $k ] ) && $desktop_container['settings'][ $k ] !== '' ) {
            $val_before = $desktop_container['settings'][ $k ];
            $desktop_container['settings'][ $k ] = '';
            echo "+ container $container_desktop: $k '$val_before' → '' (mostrar em todos os devices)\n";
        }
    }
} else {
    echo "  INFO: container $container_desktop nao encontrado ou sem settings de visibilidade\n";
}

// ---------------------------------------------------------------------------
// 5. Remover o container mobile inteiro
// ---------------------------------------------------------------------------

if ( bit_remove_by_id( $data, $container_mobile ) ) {
    echo "+ container $container_mobile (mobile) REMOVIDO\n";
} else {
    echo "  AVISO: container $container_mobile nao encontrado para remocao\n";
}

// ---------------------------------------------------------------------------
// Dry-run: parar aqui
// ---------------------------------------------------------------------------

if ( ! $apply ) {
    echo "\n[DRY-RUN] Nada salvo. Rerun com 'APPLY' como argumento para aplicar.\n";
    if ( function_exists( 'restore_current_blog' ) ) {
        restore_current_blog();
    }
    exit( 0 );
}

// ---------------------------------------------------------------------------
// APPLY: salvar com wp_slash + wp_json_encode (feedback_elementor_data_wp_slash_required)
// ---------------------------------------------------------------------------

$encoded      = wp_json_encode( $data );
$bytes_after  = strlen( $encoded );
echo "\nTamanho _elementor_data: antes=$bytes_before bytes, apos=$bytes_after bytes\n";

if ( $bytes_after < ( $bytes_before * 0.5 ) ) {
    fwrite( STDERR, "ABORTAR: JSON apos edicao e menos de 50% do original ($bytes_after vs $bytes_before bytes) — possivel perda de dados\n" );
    if ( function_exists( 'restore_current_blog' ) ) {
        restore_current_blog();
    }
    exit( 1 );
}

$ok = update_post_meta( $template_id, '_elementor_data', wp_slash( $encoded ) );
echo "[APPLY] update_post_meta retornou: " . var_export( $ok, true ) . "\n";

if ( $ok === false ) {
    fwrite( STDERR, "ERRO: update_post_meta retornou false — sem alteracoes salvas\n" );
    if ( function_exists( 'restore_current_blog' ) ) {
        restore_current_blog();
    }
    exit( 1 );
}

// ---------------------------------------------------------------------------
// Flush Elementor cache + warmup CSS (feedback_elementor_flush_css_warmup)
// ---------------------------------------------------------------------------

if ( class_exists( '\Elementor\Plugin' ) ) {
    \Elementor\Plugin::$instance->files_manager->clear_cache();
    ( new \Elementor\Core\Files\CSS\Post( $template_id ) )->update();
    echo "+ Elementor: cache limpo + CSS regenerado para template $template_id\n";
} else {
    echo "  AVISO: classe Elementor\\Plugin nao encontrada — flush manual necessario\n";
}

if ( function_exists( 'restore_current_blog' ) ) {
    restore_current_blog();
}

echo "\nDone. Post-deploy checklist:\n";
echo "  - FPM reload: docker exec concertacao-dev-wordpress sh -c 'kill -USR2 \$(pgrep -of \"php-fpm: master\" | head -1)'\n";
echo "\nValidar:\n";
echo "  - Desktop (>=1025px): https://cambrasmax.local:8484/ — heading 'Cadastre-se...', inputs retangulo\n";
echo "  - Mobile (<=767px): heading 'Inscreva-se...', inputs pill branco\n";
echo "  - Select Regiao: primeira <option> e placeholder disabled, nao opcao valida\n";
echo "  - Idempotencia: rerun com APPLY deve reportar 'nada a fazer'\n";
