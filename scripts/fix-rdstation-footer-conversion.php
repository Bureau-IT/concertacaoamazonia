<?php
/**
 * fix-rdstation-footer-conversion.php — troca o conversion_identifier de TESTE
 *                                        pelo definitivo nos 4 footers RD Station
 *
 * Autor : Daniel Cambría — Bureau de Tecnologia
 * Data  : 2026-08-03
 * Versão: 1.0.0
 *
 * CONTEXTO
 *   Os 4 formulários de rodapé conectados à Form Action `bit_rdstation` estavam
 *   com `bit_rd_conversion_identifier = "teste-bit-footer-site"` — um
 *   identificador de TESTE. Em produção isso faria os leads reais do rodapé
 *   caírem numa conversão de teste no RD Station. O valor correto é
 *   `newsletter-footer-concertacao` (o mesmo que o Gate 55 do /smoke espera).
 *
 *   Detectado em 03/08/2026 pelo Gate 55 durante a validação pré-cutover
 *   blue-green. Dev, green e prod estavam todos com o id de teste.
 *
 *   Escopo por blog (o script detecta pelo get_current_blog_id):
 *     blog 1 : 72234 (footer PT), 72921 (footer EN)
 *     blog 2 : 89361 (footer PT), 89785 (footer EN)
 *
 * SEGURANÇA
 *   - Dry-run por DEFAULT. Só grava com FIX_RDCONV_APPLY=1 (ou --apply).
 *   - Só altera o widget cujo `submit_actions` contém `bit_rdstation`.
 *   - Só troca quando o valor atual é EXATAMENTE o de teste (idempotente: rodar
 *     duas vezes não faz nada na segunda).
 *   - `_elementor_data` é gravado com wp_slash() — sem isso o update_post_meta
 *     grava NULL e a página fica em branco.
 *   - A gravação é feita por substituição do TOKEN EXATO no JSON cru, nunca por
 *     json_decode + re-encode: o round-trip converteria os ~50 escapes \uXXXX do
 *     original em UTF-8 literal, encurtando o meta em 206 bytes e reescrevendo
 *     partes alheias ao fix. O script aborta se o delta de bytes não for
 *     exatamente o esperado (+8 por ocorrência).
 *   - Backup JSON do `_elementor_data` original em
 *     uploads/rdstation-conv-backups/ antes de gravar.
 *   - Verificação pós-gravação relê do banco e confere o valor.
 *
 * USO
 *   Dry-run, blog 1:
 *     ./common/bin/docker-dev.sh wp --url="https://cambrasmax.local:8484" \
 *         eval-file scripts/fix-rdstation-footer-conversion.php
 *   Aplicar, blog 2:
 *     ./common/bin/docker-dev.sh wp --url="https://cambrasmax.local:8484/cultura/" \
 *         eval-file scripts/fix-rdstation-footer-conversion.php --apply
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Este script roda via `wp eval-file`.\n" );
	exit( 1 );
}

global $wpdb;

const FIX_RDCONV_FROM = 'teste-bit-footer-site';
const FIX_RDCONV_TO   = 'newsletter-footer-concertacao';

$by_blog = array(
	1 => array( 72234, 72921 ),
	2 => array( 89361, 89785 ),
);

$blog  = get_current_blog_id();
$posts = $by_blog[ $blog ] ?? array();
$apply = getenv( 'FIX_RDCONV_APPLY' ) === '1' || in_array( '--apply', (array) ( $GLOBALS['argv'] ?? array() ), true );

printf( "\n=== fix-rdstation-footer-conversion — blog %d — modo %s ===\n", $blog, $apply ? 'APPLY' : 'DRY-RUN' );
printf( "    '%s' -> '%s'\n\n", FIX_RDCONV_FROM, FIX_RDCONV_TO );

if ( ! $posts ) {
	printf( "  nenhum footer mapeado para o blog %d — nada a fazer\n", $blog );
	exit( 0 );
}

/**
 * Confere, via árvore decodificada, que o widget alvo é de fato um form com a
 * action bit_rdstation e o id de teste. NÃO altera nada — a gravação é feita por
 * substituição no JSON CRU (ver nota abaixo).
 */
function fix_rdconv_inspect( array $nodes, array &$found ) {
	foreach ( $nodes as $node ) {
		if ( ( $node['widgetType'] ?? '' ) === 'form'
			&& in_array( 'bit_rdstation', (array) ( $node['settings']['submit_actions'] ?? array() ), true ) ) {
			$found[] = array(
				'id'  => $node['id'] ?? '?',
				'cur' => $node['settings']['bit_rd_conversion_identifier'] ?? '',
			);
		}
		if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
			fix_rdconv_inspect( $node['elements'], $found );
		}
	}
}

$total   = 0;
$errors  = 0;
$backups = array();

foreach ( $posts as $pid ) {
	printf( "  --- post %d (%s) ---\n", $pid, get_post_field( 'post_name', $pid ) ?: '?' );

	// Leitura RAW (sem wp_slash na leitura).
	$raw = $wpdb->get_var( $wpdb->prepare(
		"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_elementor_data'",
		$pid
	) );
	if ( $raw === null || $raw === '' ) {
		printf( "    [ERRO] _elementor_data vazio\n" );
		$errors++;
		continue;
	}

	$data = json_decode( $raw, true );
	if ( ! is_array( $data ) ) {
		printf( "    [ERRO] _elementor_data não é JSON válido\n" );
		$errors++;
		continue;
	}

	// 1) Inspeção (só leitura): confirma que existe UM form com bit_rdstation e o id de teste.
	$found = array();
	fix_rdconv_inspect( $data, $found );
	if ( ! $found ) {
		printf( "    [ERRO] nenhum widget form com a action bit_rdstation\n" );
		$errors++;
		continue;
	}
	foreach ( $found as $f ) {
		printf( "    widget %s: conversion_identifier atual = '%s'\n", $f['id'], $f['cur'] );
	}
	$alvos = array_filter( $found, static fn( $f ) => $f['cur'] === FIX_RDCONV_FROM );
	if ( ! $alvos ) {
		printf( "    nada a alterar (nenhum com o id de teste)\n" );
		continue;
	}

	// 2) Gravação por substituição no JSON CRU — NÃO re-serializar.
	//    Motivo (medido 03/08/2026): json_decode + wp_json_encode(JSON_UNESCAPED_UNICODE)
	//    converte os ~50 escapes \uXXXX do original em UTF-8 literal e encurta o
	//    meta em 206 bytes, reescrevendo partes que nada têm a ver com o fix.
	//    A substituição do token exato muda só os 8 bytes pretendidos.
	$token_from = '"bit_rd_conversion_identifier":"' . FIX_RDCONV_FROM . '"';
	$token_to   = '"bit_rd_conversion_identifier":"' . FIX_RDCONV_TO . '"';
	$hits       = substr_count( $raw, $token_from );
	if ( $hits !== count( $alvos ) ) {
		printf( "    [ERRO] token no JSON cru aparece %dx mas a árvore tem %d alvo(s) — abortando por segurança\n",
			$hits, count( $alvos ) );
		$errors++;
		continue;
	}
	$new = str_replace( $token_from, $token_to, $raw );
	$delta_esperado = $hits * ( strlen( FIX_RDCONV_TO ) - strlen( FIX_RDCONV_FROM ) );
	$delta_real     = strlen( $new ) - strlen( $raw );
	if ( $delta_real !== $delta_esperado ) {
		printf( "    [ERRO] delta de bytes inesperado (%d, esperado %d) — abortando\n", $delta_real, $delta_esperado );
		$errors++;
		continue;
	}
	printf( "    len %d -> %d (delta %+d, exatamente o esperado)\n", strlen( $raw ), strlen( $new ), $delta_real );

	$backups[ $pid ] = $raw;

	if ( $apply ) {
		// wp_slash OBRIGATÓRIO — sem ele o update_post_meta grava NULL.
		// JSON_UNESCAPED_SLASHES NÃO é usado de propósito (causa drift \/ vs /).
		update_post_meta( $pid, '_elementor_data', wp_slash( $new ) );

		$check = $wpdb->get_var( $wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_elementor_data'",
			$pid
		) );
		if ( $check === null || $check === '' ) {
			printf( "    [ERRO] gravação resultou em valor vazio/NULL\n" );
			$errors++;
			continue;
		}
		if ( strpos( $check, FIX_RDCONV_FROM ) !== false ) {
			printf( "    [ERRO] o id de teste ainda está presente após gravar\n" );
			$errors++;
			continue;
		}
		if ( strpos( $check, FIX_RDCONV_TO ) === false ) {
			printf( "    [ERRO] o id definitivo não apareceu após gravar\n" );
			$errors++;
			continue;
		}
		// _elementor_element_cache sobrevive a flush de cache — apagar explicitamente.
		delete_post_meta( $pid, '_elementor_element_cache' );
		clean_post_cache( $pid );
		printf( "    gravado e verificado (len %d) + element_cache limpo\n", strlen( $check ) );
	}
	$total++;
}

if ( $apply && $backups ) {
	$dir = wp_get_upload_dir()['basedir'] . '/rdstation-conv-backups';
	wp_mkdir_p( $dir );
	$file = $dir . '/eldata_blog' . $blog . '_' . gmdate( 'Ymd-His' ) . '.json';
	file_put_contents( $file, wp_json_encode(
		array( 'when' => gmdate( 'c' ), 'blog' => $blog, 'from' => FIX_RDCONV_FROM, 'to' => FIX_RDCONV_TO, 'original' => $backups ),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
	) );
	printf( "\n  backup: %s\n", $file );
}

printf( "\n=== %s — %d post(s) alterado(s), %d erro(s) ===\n",
	$apply ? 'APLICADO' : 'DRY-RUN (nada gravado)', $total, $errors );
if ( ! $apply ) {
	printf( "Para aplicar: acrescente --apply ou FIX_RDCONV_APPLY=1\n" );
}

exit( $errors > 0 ? 1 : 0 );
