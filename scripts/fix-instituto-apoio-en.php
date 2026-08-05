<?php
/**
 * fix-instituto-apoio-en.php — "Instituto de Apoio" é nome próprio, não se traduz
 *
 * Autor : Daniel Cambría — Bureau de Tecnologia
 * Data  : 2026-08-05
 * Versão: 1.2.1
 *
 * CONTEXTO
 *   A tradução EN da página (post 94330, trid 2412039) foi criada com o nome
 *   institucional TRADUZIDO — "Support Institute for an Amazon Concertation" —
 *   contrariando a regra: "Instituto de Apoio" é a designação oficial e deve
 *   aparecer literal também em inglês.
 *
 *   Corrige, na página EN:
 *     1. post_title  "Support Institute" -> "Instituto de Apoio"
 *     2. post_name   "support-institute" -> "instituto-de-apoio" (301 do antigo)
 *     3. _elementor_data: 2 ocorrências do nome completo
 *     4. item no menu "Principal - EN" (term 1628), espelhando o menu PT
 *
 * MÉTODO (_elementor_data)
 *   Substituição do TOKEN EXATO no JSON cru — nunca json_decode + re-encode,
 *   que reescreveria os escapes \uXXXX e alteraria centenas de bytes alheios ao
 *   fix. A árvore decodificada é usada só para CONFERIR o alvo. Guardas: nº de
 *   ocorrências no cru == nº de alvos previstos, e delta de bytes exatamente o
 *   esperado. Os acentos entram como escapes \uXXXX para manter o padrão do
 *   arquivo (à = à, ç = ç, ã = ã, ô = ô).
 *
 * USO
 *   DRY-RUN (default):  wp --url=... eval-file fix-instituto-apoio-en.php
 *   APLICAR:            INSTITUTO_EN_APPLY=1 wp --url=... eval-file ...
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( "Este script roda somente via WP-CLI.\n" );
}

$apply = (bool) getenv( 'INSTITUTO_EN_APPLY' );

$EN_ID        = 94330;   // página EN
$PT_ID        = 92840;   // original PT
$MENU_EN      = 1628;    // "Principal - EN"
$NOVO_TITULO  = 'Instituto de Apoio';
$NOVO_SLUG    = 'instituto-de-apoio';
$SLUG_ANTIGO  = 'support-institute';

/* Tokens no JSON cru. Acentos como \uXXXX (padrão do arquivo). */
$SUBS = array(
	array(
		'de'   => '"title":"Support Institute for\\nAmazon Concertation"',
		'para' => '"title":"Instituto de Apoio \\u00e0\\nUma Concerta\\u00e7\\u00e3o pela Amaz\\u00f4nia"',
	),
	array(
		'de'   => '<strong>Support Institute for an Amazon Concertation<\\/strong>',
		'para' => '<strong>Instituto de Apoio \\u00e0 Uma Concerta\\u00e7\\u00e3o pela Amaz\\u00f4nia<\\/strong>',
	),
);

WP_CLI::log( sprintf( "Blog %d (%s) — modo: %s", get_current_blog_id(), home_url(), $apply ? 'APLICAR' : 'DRY-RUN' ) );

$en = get_post( $EN_ID );
if ( ! $en ) {
	WP_CLI::error( "Página EN #$EN_ID não encontrada." );
}

/* ---------------------------------------------------------------- 1. título */
WP_CLI::log( "\n[1] post_title" );
if ( $en->post_title === $NOVO_TITULO ) {
	WP_CLI::log( "    = já é \"$NOVO_TITULO\"" );
} else {
	WP_CLI::log( sprintf( '    ~ "%s" -> "%s"', $en->post_title, $NOVO_TITULO ) );
}

/* ------------------------------------------------------------------ 2. slug */
WP_CLI::log( "[2] post_name (slug)" );
if ( $en->post_name === $NOVO_SLUG ) {
	WP_CLI::log( "    = já é \"$NOVO_SLUG\"" );
} else {
	WP_CLI::log( sprintf( '    ~ "%s" -> "%s"  (301 do antigo será registrado)', $en->post_name, $NOVO_SLUG ) );
}

if ( $apply && ( $en->post_title !== $NOVO_TITULO || $en->post_name !== $NOVO_SLUG ) ) {
	$r = wp_update_post( array(
		'ID'         => $EN_ID,
		'post_title' => $NOVO_TITULO,
		'post_name'  => $NOVO_SLUG,
	), true );
	if ( is_wp_error( $r ) ) {
		WP_CLI::error( 'wp_update_post: ' . $r->get_error_message() );
	}
	WP_CLI::success( 'título e slug gravados.' );
	// O 301 do slug antigo é criado pelo próprio plugin Redirection (ativo em
	// rede), que monitora mudança de slug. Não duplicar em mu-plugin: eles
	// carregam ANTES dos plugins e a cópia hardcoded venceria a regra visível
	// no admin, deixando quem editasse pelo painel sem entender o efeito.
	WP_CLI::log( sprintf( '    301 /en/%s/ -> /en/%s/ fica a cargo do plugin Redirection',
		$SLUG_ANTIGO, $NOVO_SLUG ) );
}

/* -------------------------------------------------------- 3. elementor_data */
WP_CLI::log( "[3] _elementor_data" );
$raw = get_post_meta( $EN_ID, '_elementor_data', true );
if ( $raw === '' ) {
	WP_CLI::warning( '    sem _elementor_data — nada a fazer.' );
} else {
	$antes_bytes = strlen( $raw );
	if ( json_decode( $raw, true ) === null ) {
		WP_CLI::error( '    _elementor_data já está inválido ANTES da edição — abortando.' );
	}

	$novo          = $raw;
	$delta_previsto = 0;
	$total_hits     = 0;

	foreach ( $SUBS as $i => $s ) {
		$hits = substr_count( $novo, $s['de'] );
		WP_CLI::log( sprintf( '    token %d: %d ocorrência(s)', $i + 1, $hits ) );
		if ( $hits === 0 ) {
			WP_CLI::log( '      (já corrigido ou token divergente — pulando)' );
			continue;
		}
		$total_hits     += $hits;
		$delta_previsto += $hits * ( strlen( $s['para'] ) - strlen( $s['de'] ) );
		$novo            = str_replace( $s['de'], $s['para'], $novo );
	}

	if ( $total_hits === 0 ) {
		WP_CLI::log( '    = nada a substituir' );
	} else {
		$delta_real = strlen( $novo ) - $antes_bytes;
		WP_CLI::log( sprintf( '    bytes: %d -> %d (delta real %+d, previsto %+d)',
			$antes_bytes, strlen( $novo ), $delta_real, $delta_previsto ) );

		if ( $delta_real !== $delta_previsto ) {
			WP_CLI::error( '    delta de bytes diferente do previsto — abortando (substituição ambígua).' );
		}
		if ( json_decode( $novo, true ) === null ) {
			WP_CLI::error( '    JSON ficaria inválido — abortando.' );
		}

		if ( $apply ) {
			update_post_meta( $EN_ID, '_elementor_data', wp_slash( $novo ) );

			// update_post_meta NÃO acusa corrupção — conferir sempre.
			$check = get_post_meta( $EN_ID, '_elementor_data', true );
			if ( strlen( $check ) !== strlen( $novo ) || json_decode( $check, true ) === null ) {
				WP_CLI::error( sprintf( '    GRAVAÇÃO CORROMPIDA: esperado %d bytes, gravado %d',
					strlen( $novo ), strlen( $check ) ) );
			}
			delete_post_meta( $EN_ID, '_elementor_element_cache' );
			WP_CLI::success( '    _elementor_data gravado e verificado; element_cache limpo.' );
		}
	}
}

/* ---------------------------------------------------------------- 4. menu EN */
WP_CLI::log( "[4] menu \"Principal - EN\" (term $MENU_EN)" );
$ja_existe = false;
foreach ( wp_get_nav_menu_items( $MENU_EN ) ?: array() as $it ) {
	if ( (int) $it->object_id === $EN_ID && $it->object === 'page' ) {
		$ja_existe = (int) $it->ID;
		break;
	}
}

if ( $ja_existe ) {
	WP_CLI::log( "    = item já existe (#$ja_existe)" );
} else {
	// Espelhar a posição do item PT: filho do mesmo pai, mesma ordem.
	$pai_en = 0;
	$ordem  = 0;
	foreach ( wp_get_nav_menu_items( 3 ) ?: array() as $it ) {   // menu PT "Principal"
		if ( (int) $it->object_id === $PT_ID && $it->object === 'page' ) {
			$ordem  = (int) $it->menu_order;
			$pai_pt = (int) $it->menu_item_parent;
			if ( $pai_pt ) {
				// achar o equivalente EN do item-pai
				$pai_pt_obj = get_post_meta( $pai_pt, '_menu_item_object_id', true );
				$pai_en_obj = apply_filters( 'wpml_object_id', (int) $pai_pt_obj, 'page', false, 'en' );
				foreach ( wp_get_nav_menu_items( $MENU_EN ) ?: array() as $it_en ) {
					if ( (int) $it_en->object_id === (int) $pai_en_obj ) {
						$pai_en = (int) $it_en->ID;
						break;
					}
				}
			}
			break;
		}
	}
	WP_CLI::log( sprintf( '    + criar item -> página %d, pai=%d, ordem=%d', $EN_ID, $pai_en, $ordem ) );

	if ( $apply ) {
		$item = wp_update_nav_menu_item( $MENU_EN, 0, array(
			'menu-item-object-id'   => $EN_ID,
			'menu-item-object'      => 'page',
			'menu-item-type'        => 'post_type',
			'menu-item-status'      => 'publish',
			'menu-item-title'       => $NOVO_TITULO,
			'menu-item-parent-id'   => $pai_en,
			'menu-item-position'    => $ordem,
		) );
		if ( is_wp_error( $item ) ) {
			WP_CLI::error( 'wp_update_nav_menu_item: ' . $item->get_error_message() );
		}
		WP_CLI::success( "    item de menu #$item criado." );
	}
}

/* ------------------------------------------- 5. strings do WPML (precedência)
 * O WPML Translation Editor guarda o texto traduzido em icl_string_translations
 * e o SOBREPÕE ao _elementor_data na renderização. Corrigir só o Elementor
 * (passo 3) não muda o que o visitante lê — foi o que aconteceu na 1ª tentativa.
 * Estas strings são texto puro, então acentos vão em UTF-8 direto (ao contrário
 * do JSON do Elementor, que usa \uXXXX).
 */
WP_CLI::log( "[5] strings do WPML (icl_string_translations)" );

global $wpdb;

$STR_SUBS = array(
	'Support Institute for' . "\n" . 'Amazon Concertation' => 'Instituto de Apoio à' . "\n" . 'Uma Concertação pela Amazônia',
	'Support Institute for an Amazon Concertation'        => 'Instituto de Apoio à Uma Concertação pela Amazônia',
);

$tabela = $wpdb->prefix . 'icl_string_translations';
$linhas = $wpdb->get_results(
	"SELECT id, string_id, language, value FROM {$tabela} WHERE value LIKE '%Support Institute%'",
	ARRAY_A
);

if ( ! $linhas ) {
	WP_CLI::log( '    = nenhuma string a corrigir' );
} else {
	foreach ( $linhas as $l ) {
		$novo_valor = $l['value'];
		foreach ( $STR_SUBS as $de => $para ) {
			$novo_valor = str_replace( $de, $para, $novo_valor );
		}
		if ( $novo_valor === $l['value'] ) {
			WP_CLI::warning( sprintf( '    id=%s [%s]: contém "Support Institute" mas nenhum token bateu — revisar à mão',
				$l['id'], $l['language'] ) );
			continue;
		}
		WP_CLI::log( sprintf( '    ~ id=%s [%s] %d -> %d bytes', $l['id'], $l['language'],
			strlen( $l['value'] ), strlen( $novo_valor ) ) );
		if ( $apply ) {
			$wpdb->update( $tabela, array( 'value' => $novo_valor ), array( 'id' => (int) $l['id'] ) );
		}
	}
	if ( $apply ) {
		// o WPML cacheia strings; sem isso a página segue servindo o valor antigo
		if ( function_exists( 'icl_cache_clear' ) ) {
			icl_cache_clear();
		}
		wp_cache_flush();
		WP_CLI::success( '    strings gravadas e cache WPML limpo.' );
	}
}

/* ----------------------------------------------------- 6. CPT menu-flip */
WP_CLI::log( '[6] menu-flip #94118 (título do card de menu)' );
$mf = get_post( 94118 );
if ( ! $mf ) {
	WP_CLI::log( '    (não existe neste ambiente)' );
} elseif ( $mf->post_title === $NOVO_TITULO ) {
	WP_CLI::log( "    = já é \"$NOVO_TITULO\"" );
} else {
	WP_CLI::log( sprintf( '    ~ "%s" -> "%s"', $mf->post_title, $NOVO_TITULO ) );
	if ( $apply ) {
		wp_update_post( array( 'ID' => 94118, 'post_title' => $NOVO_TITULO ) );
		delete_post_meta( 94118, '_elementor_element_cache' );
		WP_CLI::success( '    menu-flip atualizado.' );
	}
}

/* --------------------------------------- 7. item de menu duplicado/legado */
WP_CLI::log( '[7] item de menu legado #94174' );
$legado = get_post( 94174 );
if ( ! $legado || $legado->post_type !== 'nav_menu_item' ) {
	WP_CLI::log( '    = já removido' );
} else {
	$url_legado = get_post_meta( 94174, '_menu_item_url', true );
	WP_CLI::log( sprintf( '    tipo=custom  título="%s"  url=%s', $legado->post_title, $url_legado ) );
	WP_CLI::log( '    -> remover: é item "custom" com URL fixa apontando para a página PT' );
	WP_CLI::log( '       (e com hostname hardcoded), duplicando o item novo que segue o WPML.' );
	if ( $apply ) {
		wp_delete_post( 94174, true );
		WP_CLI::success( '    item legado removido.' );
	}
}

/* ------------------------------------------------------ 8. post_content
 * O Elementor mantém em post_content uma cópia RENDERIZADA da página. Ela não
 * é usada no front (o Elementor renderiza do _elementor_data), mas o Yoast lê
 * daí para montar a meta description / og:description. Sem corrigir aqui, o
 * nome traduzido continua aparecendo em compartilhamentos e no snippet do
 * Google — invisível na página, visível no que importa para SEO.
 */
WP_CLI::log( '[8] post_content (fonte da meta description do Yoast)' );
$conteudo = $en->post_content;
$hits_pc  = substr_count( $conteudo, 'Support Institute' );
if ( ! $hits_pc ) {
	WP_CLI::log( '    = limpo' );
} else {
	$novo_conteudo = $conteudo;
	foreach ( $STR_SUBS as $de => $para ) {
		$novo_conteudo = str_replace( $de, $para, $novo_conteudo );
	}
	// o post_content usa quebra de linha real; o token com \n já cobre o H1
	$restantes = substr_count( $novo_conteudo, 'Support Institute' );
	WP_CLI::log( sprintf( '    ~ %d ocorrência(s) -> %d após substituição (%d -> %d bytes)',
		$hits_pc, $restantes, strlen( $conteudo ), strlen( $novo_conteudo ) ) );
	if ( $restantes > 0 ) {
		WP_CLI::warning( '    ainda sobram ocorrências — revisar tokens antes de aplicar' );
	}
	if ( $apply && $restantes === 0 ) {
		$r = wp_update_post( array( 'ID' => $EN_ID, 'post_content' => $novo_conteudo ), true );
		if ( is_wp_error( $r ) ) {
			WP_CLI::error( 'wp_update_post (content): ' . $r->get_error_message() );
		}
		WP_CLI::success( '    post_content atualizado.' );
	}
}

if ( ! $apply ) {
	WP_CLI::log( "\nDRY-RUN — nada gravado. Rode com INSTITUTO_EN_APPLY=1" );
}
