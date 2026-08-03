<?php
/**
 * fix-wpml-duplicate-attachments.php — reaponta traduções EN para o attachment
 *                                       original do par PT e descarta a duplicata
 *
 * Autor : Daniel Cambría — Bureau de Tecnologia
 * Data  : 2026-08-03
 * Versão: 1.0.0
 *
 * CONTEXTO
 *   O duplicate-on-translate do WPML cria um attachment NOVO para a tradução
 *   quando o par PT já referencia um attachment perfeitamente utilizável. O
 *   resultado é um par de IDs distintos apontando para o MESMO
 *   `_wp_attached_file` — o CLAUDE.md deste site pede que essa duplicação fique
 *   desligada exatamente por isso (attachment órfão sem arquivo físico próprio,
 *   imagens 403/404 em /cultura/en/*).
 *
 *   Sintoma que originou o script (2026-08-03, pré-cutover blue-green):
 *     26666 Grupos de Trabalho (PT) → eldata        → 94368  (original)
 *     71726 Workgroups         (EN) → eldata        → 95002  (duplicata)
 *     90006 banner-home        (PT) → _thumbnail_id → 94369  (original)
 *     91368 banner-home        (EN) → _thumbnail_id → 95004  (duplicata)
 *
 *   Sem o fix, o deploy dev→green levaria as duplicatas para produção.
 *
 * SEGURANÇA
 *   - Dry-run por DEFAULT. Só grava com FIX_DUP_ATT_APPLY=1.
 *   - Aborta se a duplicata e o original não tiverem o MESMO `_wp_attached_file`
 *     (garante que nenhuma imagem diferente seja substituída).
 *   - Backup JSON em uploads/attachment-dedupe-backups/ antes de gravar.
 *   - `_elementor_data` é gravado com wp_slash() — sem isso o update_post_meta
 *     grava NULL e a página fica em branco.
 *   - Substituição no eldata é pelo token exato `"id":<N>` (não pelo número
 *     solto), evitando colisão com qualquer outro campo numérico.
 *
 * USO
 *   Dry-run (default):
 *     ./common/bin/docker-dev.sh wp --url="https://cambrasmax.local:8484" \
 *         eval-file scripts/fix-wpml-duplicate-attachments.php
 *   Aplicar:
 *     ./common/bin/docker-dev.sh wp --url="https://cambrasmax.local:8484" \
 *         eval-file scripts/fix-wpml-duplicate-attachments.php --apply
 *     (ou FIX_DUP_ATT_APPLY=1 no ambiente)
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Este script roda via `wp eval-file`.\n" );
	exit( 1 );
}

global $wpdb;

// ---------------------------------------------------------------------------
// Pares a corrigir: post que referencia => [duplicata, original, onde]
// 'onde' = 'eldata' (_elementor_data) ou 'thumb' (_thumbnail_id)
// ---------------------------------------------------------------------------
$PAIRS = array(
	array( 'post' => 71726, 'dup' => 95002, 'orig' => 94368, 'where' => 'eldata' ),
	array( 'post' => 91368, 'dup' => 95004, 'orig' => 94369, 'where' => 'thumb'  ),
);

// Duplicatas que ficam órfãs depois do repontamento.
//
// ATENÇÃO — NÃO mandar para a lixeira. A duplicata compartilha o MESMO
// `_wp_attached_file` do original; um `wp_delete_attachment` futuro (esvaziar a
// lixeira, limpeza automática, plugin de manutenção) apagaria o arquivo FÍSICO
// que 94368/94369 ainda usam, quebrando as páginas PT. Órfão sem referência é
// inofensivo — não é exportado no WXR dos posts e não chega em produção.
// Só remover manualmente depois de zerar o `_wp_attached_file` da duplicata.
$ORPHANS = array( 95002, 95004 );

$apply = getenv( 'FIX_DUP_ATT_APPLY' ) === '1' || in_array( '--apply', (array) $GLOBALS['argv'], true );

printf( "\n=== fix-wpml-duplicate-attachments — blog %d — modo %s ===\n\n",
	get_current_blog_id(), $apply ? 'APPLY' : 'DRY-RUN' );

$backup  = array( 'when' => gmdate( 'c' ), 'blog' => get_current_blog_id(), 'items' => array() );
$changes = 0;
$errors  = 0;

foreach ( $PAIRS as $p ) {
	$post = (int) $p['post'];
	$dup  = (int) $p['dup'];
	$orig = (int) $p['orig'];

	// --- guarda 1: os dois attachments existem e são attachments
	foreach ( array( $dup, $orig ) as $a ) {
		if ( get_post_type( $a ) !== 'attachment' ) {
			printf( "  [ERRO] %d não é attachment neste blog — par %d ignorado\n", $a, $post );
			$errors++;
			continue 2;
		}
	}

	// --- guarda 2: mesmo arquivo físico
	$f_dup  = get_post_meta( $dup, '_wp_attached_file', true );
	$f_orig = get_post_meta( $orig, '_wp_attached_file', true );
	if ( $f_dup === '' || $f_dup !== $f_orig ) {
		printf( "  [ERRO] arquivos diferentes — %d='%s' vs %d='%s' — par %d ignorado\n",
			$dup, $f_dup, $orig, $f_orig, $post );
		$errors++;
		continue;
	}
	printf( "  par %d: %d -> %d  (arquivo comum: %s)\n", $post, $dup, $orig, $f_orig );

	if ( $p['where'] === 'thumb' ) {
		$cur = get_post_meta( $post, '_thumbnail_id', true );
		if ( (int) $cur !== $dup ) {
			printf( "    _thumbnail_id já é %s — nada a fazer\n", $cur !== '' ? $cur : '(vazio)' );
			continue;
		}
		$backup['items'][] = array( 'post' => $post, 'meta' => '_thumbnail_id', 'old' => $cur );
		printf( "    _thumbnail_id: %d -> %d\n", $dup, $orig );
		if ( $apply ) {
			update_post_meta( $post, '_thumbnail_id', $orig );
		}
		$changes++;
		continue;
	}

	// --- eldata: ler RAW (sem wp_slash na leitura) e trocar o token exato
	$ed = $wpdb->get_var( $wpdb->prepare(
		"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_elementor_data'",
		$post
	) );
	if ( $ed === null || $ed === '' ) {
		printf( "    [ERRO] _elementor_data vazio no post %d\n", $post );
		$errors++;
		continue;
	}

	$needle = '"id":' . $dup;
	$hits   = substr_count( $ed, $needle );
	if ( $hits === 0 ) {
		printf( "    token %s não encontrado — nada a fazer\n", $needle );
		continue;
	}

	$new = str_replace( $needle, '"id":' . $orig, $ed );
	printf( "    _elementor_data: %d ocorrência(s) de %s -> \"id\":%d (len %d -> %d)\n",
		$hits, $needle, $orig, strlen( $ed ), strlen( $new ) );

	$backup['items'][] = array(
		'post'    => $post,
		'meta'    => '_elementor_data',
		'old_md5' => md5( $ed ),
		'old'     => $ed,
	);

	if ( $apply ) {
		// wp_slash OBRIGATÓRIO: sem ele o update_post_meta grava NULL.
		update_post_meta( $post, '_elementor_data', wp_slash( $new ) );

		$check = $wpdb->get_var( $wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_elementor_data'",
			$post
		) );
		if ( $check === null || $check === '' || substr_count( $check, $needle ) > 0 ) {
			printf( "    [ERRO] verificação pós-gravação falhou no post %d\n", $post );
			$errors++;
			continue;
		}
		printf( "    gravado e verificado (len %d)\n", strlen( $check ) );
	}
	$changes++;
}

// ---------------------------------------------------------------------------
// Backup + lixeira
// ---------------------------------------------------------------------------
if ( $apply && $backup['items'] ) {
	$dir = wp_get_upload_dir()['basedir'] . '/attachment-dedupe-backups';
	wp_mkdir_p( $dir );
	$file = $dir . '/dedupe_' . get_current_blog_id() . '_' . gmdate( 'Ymd-His' ) . '.json';
	file_put_contents( $file, wp_json_encode( $backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
	printf( "\n  backup: %s\n", $file );
}

printf( "\n  --- estado das duplicatas (nada é removido: ver nota em \$ORPHANS) ---\n" );
foreach ( $ORPHANS as $a ) {
	if ( get_post_type( $a ) !== 'attachment' ) {
		printf( "    %d: não é attachment neste blog — pulado\n", $a );
		continue;
	}
	$still = $wpdb->get_col( $wpdb->prepare(
		"SELECT DISTINCT post_id FROM {$wpdb->postmeta}
		  WHERE ( meta_key = '_elementor_data' AND meta_value LIKE %s )
		     OR ( meta_key = '_thumbnail_id'   AND meta_value = %s )",
		'%"id":' . $a . '%', (string) $a
	) );
	if ( $still ) {
		printf( "    %d: ainda referenciado por %s\n", $a, implode( ',', $still ) );
	} else {
		printf( "    %d: órfão (sem referências) — mantido de propósito, arquivo compartilhado\n", $a );
	}
}

// ---------------------------------------------------------------------------
// Invalidação de cache dos posts tocados
// ---------------------------------------------------------------------------
if ( $apply && $changes ) {
	printf( "\n  --- invalidando cache dos posts tocados ---\n" );
	foreach ( $PAIRS as $p ) {
		$post = (int) $p['post'];
		// _elementor_element_cache sobrevive a flush de cache — apagar explicitamente.
		delete_post_meta( $post, '_elementor_element_cache' );
		clean_post_cache( $post );
		printf( "    post %d: _elementor_element_cache removido + clean_post_cache\n", $post );
	}
}

printf( "\n=== %s — %d alteração(ões), %d erro(s) ===\n",
	$apply ? 'APLICADO' : 'DRY-RUN (nada gravado)', $changes, $errors );

if ( ! $apply ) {
	printf( "Para aplicar: acrescente --apply ou FIX_DUP_ATT_APPLY=1\n" );
}

exit( $errors > 0 ? 1 : 0 );
