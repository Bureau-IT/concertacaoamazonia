<?php
/**
 * Configura os formularios que usam a Form Action bit_rdstation:
 *
 *   1. Insere bit_rd_sector_field=field_8aee261 nos 4 footers (ativa o cf_setor)
 *   2. Corrige bit_rd_tags nos 6 formularios (tira o "teste-bit" e soma o
 *      marcador de procedencia bit-website-integration)
 *
 * Idempotente: rodar N vezes produz o mesmo resultado. DRY-RUN por default.
 *
 * Spec: docs/superpowers/specs/2026-08-03-cf-setor-rdstation-design.md
 *
 * ---------------------------------------------------------------------------
 * POR QUE MANIPULA JSON CRU EM VEZ DE json_decode/json_encode
 * ---------------------------------------------------------------------------
 * Um ciclo decode+encode no _elementor_data reescreve escapes unicode, ordem de
 * chaves e precisao de floats — drift de centenas de bytes num campo que nao e
 * nosso. Aqui a operacao e substituicao de string no JSON cru: cirurgica,
 * verificavel por diff de bytes, e nao toca em mais nada.
 *
 * A escrita usa wp_slash(): sem isso o update_post_meta grava NULL no
 * _elementor_data (as barras do JSON sao comidas pelo wpdb).
 *
 * ---------------------------------------------------------------------------
 * USO
 * ---------------------------------------------------------------------------
 *   # DRY-RUN blog 1 (default — nao escreve nada)
 *   std wp eval-file scripts/rd-configure-forms.php
 *
 *   # APLICAR blog 1 (RD_APPLY=1 — mesmo padrao do disperse_duplicate_coords.php)
 *   RD_APPLY=1 std wp eval-file scripts/rd-configure-forms.php
 *
 *   # blog 2 (/cultura/) — o --url e OBRIGATORIO, senao opera no blog 1 calado
 *   RD_APPLY=1 std wp --url="https://cambrasmax.local:8484/cultura/" eval-file scripts/rd-configure-forms.php
 *
 * Em producao, trocar o --url pelo dominio real e rodar via sudo -u www-data.
 *
 * Backup: o _elementor_data original de cada post tocado vai para o postmeta
 * _elementor_data_bkp_rdstation_<timestamp> antes da escrita.
 *
 * @author Daniel Cambría / Bureau de Tecnologia Ltda.
 * @version 1.0.0
 */

// Sem declare(strict_types): o eval-file do WP-CLI roda o arquivo dentro de um
// eval(), e strict_types exige ser a primeira instrucao do script.

defined( 'ABSPATH' ) || exit;

// --- Alvos ------------------------------------------------------------------
// Levantado em 2026-08-03 varrendo _elementor_data por "bit_rdstation" nos dois
// blogs; todos os outros IDs encontrados eram post_type=revision.
//
// sector=true  -> footer, tem o select field_8aee261
// sector=false -> Contato, nao tem campo de setor (so corrige as tags)
const TARGETS = [
    1 => [
        672    => [ 'nome' => 'Contato',   'sector' => false, 'tags' => 'contato,concertacao-amazonia,bit-website-integration' ],
        3626   => [ 'nome' => 'Contact',   'sector' => false, 'tags' => 'contato,concertacao-amazonia,bit-website-integration' ],
        72234  => [ 'nome' => 'Footer',    'sector' => true,  'tags' => 'newsletter,concertacao-amazonia,footer-form,bit-website-integration' ],
        72921  => [ 'nome' => 'Footer EN', 'sector' => true,  'tags' => 'newsletter,concertacao-amazonia,footer-form,bit-website-integration' ],
    ],
    2 => [
        89361  => [ 'nome' => 'Rodapé',    'sector' => true,  'tags' => 'newsletter,concertacao-amazonia,footer-form,bit-website-integration' ],
        89785  => [ 'nome' => 'Rodapé EN', 'sector' => true,  'tags' => 'newsletter,concertacao-amazonia,footer-form,bit-website-integration' ],
    ],
];

const SECTOR_FIELD_ID = 'field_8aee261';

// Ancora da insercao: o controle imediatamente anterior ao bit_rd_sector_field
// na ordem em que o Elementor serializa os settings do widget.
const ANCHOR_UF = '"bit_rd_uf_field":"form_regiao_desk"';

// --- Modo -------------------------------------------------------------------
// Env var em vez de flag: o parser do WP-CLI trata "--apply" como assoc arg
// desconhecido em eval-file. Mesmo padrao do disperse_duplicate_coords.php.
$apply = getenv( 'RD_APPLY' ) === '1';

$blog_id = (int) get_current_blog_id();

if ( ! isset( TARGETS[ $blog_id ] ) ) {
    WP_CLI::error( sprintf(
        'Blog %d nao tem formularios RD Station mapeados. Blogs validos: %s. Use --url= para escolher o blog.',
        $blog_id,
        implode( ', ', array_keys( TARGETS ) )
    ) );
}

$targets   = TARGETS[ $blog_id ];
$timestamp = gmdate( 'YmdHis' );

WP_CLI::log( sprintf(
    "\n=== rd-configure-forms — blog %d — %s ===\n",
    $blog_id,
    $apply ? 'APLICANDO' : 'DRY-RUN (nada sera escrito)'
) );

$touched = 0;
$skipped = 0;
$errors  = 0;

foreach ( $targets as $post_id => $spec ) {
    $post = get_post( $post_id );

    if ( ! $post ) {
        WP_CLI::warning( sprintf( 'post %d (%s) nao existe neste blog — pulado', $post_id, $spec['nome'] ) );
        $errors++;
        continue;
    }

    $raw = get_post_meta( $post_id, '_elementor_data', true );

    if ( ! is_string( $raw ) || $raw === '' ) {
        WP_CLI::warning( sprintf( 'post %d (%s) sem _elementor_data — pulado', $post_id, $spec['nome'] ) );
        $errors++;
        continue;
    }

    $before  = $raw;
    $changes = [];

    // --- 1. bit_rd_sector_field (so nos footers) ---------------------------
    if ( $spec['sector'] ) {
        $insert = sprintf( '"bit_rd_sector_field":"%s"', SECTOR_FIELD_ID );

        if ( strpos( $raw, $insert ) !== false ) {
            $changes[] = 'sector: ja configurado';
        } elseif ( strpos( $raw, '"bit_rd_sector_field"' ) !== false ) {
            // Existe com outro valor — nao sobrescreve as cegas.
            WP_CLI::warning( sprintf(
                'post %d (%s): bit_rd_sector_field existe com valor diferente de %s — revisar a mao',
                $post_id, $spec['nome'], SECTOR_FIELD_ID
            ) );
            $errors++;
        } elseif ( strpos( $raw, ANCHOR_UF ) === false ) {
            WP_CLI::warning( sprintf(
                'post %d (%s): ancora %s nao encontrada — o form mudou, revisar a mao',
                $post_id, $spec['nome'], ANCHOR_UF
            ) );
            $errors++;
        } else {
            // Insercao adjacente a ancora, sem re-encode.
            $raw       = str_replace( ANCHOR_UF, ANCHOR_UF . ',' . $insert, $raw );
            $changes[] = sprintf( 'sector: + %s', SECTOR_FIELD_ID );
        }
    }

    // --- 2. bit_rd_tags (nos 6) -------------------------------------------
    $tags_want = sprintf( '"bit_rd_tags":"%s"', $spec['tags'] );

    if ( strpos( $raw, $tags_want ) !== false ) {
        $changes[] = 'tags: ja corretas';
    } else {
        // Captura o valor atual para logar a transicao. As tags sao ASCII e sem
        // escapes, entao o padrao simples e suficiente e nao ha risco de casar
        // atravessando o fim da string JSON.
        $found = preg_match( '/"bit_rd_tags":"([^"\\\\]*)"/', $raw, $m );

        if ( ! $found ) {
            WP_CLI::warning( sprintf(
                'post %d (%s): bit_rd_tags nao encontrado — revisar a mao',
                $post_id, $spec['nome']
            ) );
            $errors++;
        } else {
            $raw       = str_replace( $m[0], $tags_want, $raw );
            $changes[] = sprintf( 'tags: "%s" -> "%s"', $m[1], $spec['tags'] );
        }
    }

    // --- 3. Resultado ------------------------------------------------------
    if ( $raw === $before ) {
        WP_CLI::log( sprintf(
            "  = post %-6d %-10s  sem mudanca (%s)",
            $post_id, $spec['nome'], implode( ' | ', $changes )
        ) );
        $skipped++;
        continue;
    }

    WP_CLI::log( sprintf( "  %s post %-6d %-10s", $apply ? '~' : '?', $post_id, $spec['nome'] ) );
    foreach ( $changes as $c ) {
        WP_CLI::log( sprintf( '        %s', $c ) );
    }
    WP_CLI::log( sprintf( '        bytes: %d -> %d', strlen( $before ), strlen( $raw ) ) );

    if ( ! $apply ) {
        $touched++;
        continue;
    }

    // Backup antes de escrever.
    update_post_meta( $post_id, '_elementor_data_bkp_rdstation_' . $timestamp, wp_slash( $before ) );

    // wp_slash OBRIGATORIO — sem ele o _elementor_data grava NULL.
    update_post_meta( $post_id, '_elementor_data', wp_slash( $raw ) );

    // Verificacao pos-escrita: le de volta e compara byte a byte.
    $readback = get_post_meta( $post_id, '_elementor_data', true );

    if ( $readback !== $raw ) {
        WP_CLI::warning( sprintf(
            'post %d (%s): READBACK DIVERGENTE (%d bytes gravados vs %d esperados) — restaurar do backup _elementor_data_bkp_rdstation_%s',
            $post_id, $spec['nome'], strlen( (string) $readback ), strlen( $raw ), $timestamp
        ) );
        $errors++;
        continue;
    }

    WP_CLI::log( '        readback OK' );
    $touched++;
}

// --- Resumo -----------------------------------------------------------------
WP_CLI::log( sprintf(
    "\n--- blog %d: %d alterado(s), %d sem mudanca, %d erro(s) ---",
    $blog_id, $touched, $skipped, $errors
) );

if ( ! $apply && $touched > 0 ) {
    WP_CLI::log( 'DRY-RUN. Repita com RD_APPLY=1 para escrever.' );
}

if ( $apply && $touched > 0 ) {
    WP_CLI::log( sprintf( 'Backups em _elementor_data_bkp_rdstation_%s', $timestamp ) );
    WP_CLI::log( 'Limpar cache do Elementor/Redis nao e necessario: settings de Form Action sao lidos no submit, nao no render.' );
}

if ( $errors > 0 ) {
    WP_CLI::error( sprintf( '%d erro(s) — revisar antes de seguir.', $errors ) );
}
