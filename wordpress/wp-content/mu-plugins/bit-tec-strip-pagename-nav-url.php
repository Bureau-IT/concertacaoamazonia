<?php
/**
 * Plugin Name: BIT TEC Strip Pagename From Nav URLs
 * Plugin URI:
 * Description: O widget/shortcode do The Events Calendar (Views v2), quando embutido numa
 *              página com permalink (ex: /editais/, /eventos-calendario/), gera os links de
 *              navegação prev/next/today carregando ?pagename=<slug>. Esse pagename vem da
 *              canonicalização do rewrite (Rewrite::get_canonical_url re-anexa "unmatched vars"
 *              em common/src/Tribe/Rewrite.php:623). A URL resultante
 *              /eventos/lista/?pagename=editais&shortcode=...&eventDisplay=past retorna 404:
 *              a rewrite fixa post_type=tribe_events (archive) mas pagename força is_page() →
 *              WP_Query busca slug "editais" ONDE post_type='tribe_events' → 0 linhas → 404.
 *              A home (/) não sofre (não gera pagename). Removemos pagename (e vars de
 *              resolução de single conflitantes) dos links de navegação das views.
 *              Relacionado ao TEC-5754 (fix parcial em 6.15.20 não cobriu este caminho).
 * Version: 1.0.0
 * Author: Daniel Cambría (Bureau de Tecnologia)
 * Network: true
 *
 * Validação: o link "Anteriores" de /editais e /eventos-calendario deve gerar URL SEM
 * pagename e retornar 200. Filtro data-driven: cobre qualquer página com o widget TEC.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Remove query vars que conflitam com a resolução do archive de eventos das URLs de
 * navegação (prev/next/today) das Views v2 do TEC.
 *
 * Roda em prioridade 20 — DEPOIS do Events Calendar Pro (prioridade 10), que adiciona
 * ?shortcode=<hash> à URL. O shortcode hash é o que re-hidrata categoria/layout/qtd do
 * widget; pagename não carrega semântica de filtro e é seguro remover.
 *
 * @param string $url       URL de navegação gerada pela view.
 * @param bool   $canonical Se a URL é canônica (não usado).
 * @param mixed  $view      Instância da view (não usado).
 * @return string URL sem os query vars conflitantes.
 */
function conc_tec_strip_pagename_nav_url( $url, $canonical = false, $view = null ) {
	if ( empty( $url ) || ! is_string( $url ) ) {
		return $url;
	}

	// Short-circuit: só reescreve se houver var conflitante (evita custo desnecessário).
	if ( false === strpos( $url, 'pagename=' ) && false === strpos( $url, 'page_id=' ) ) {
		return $url;
	}

	return remove_query_arg(
		array( 'pagename', 'page_id', 'p', 'name', 'attachment_id' ),
		$url
	);
}
add_filter( 'tribe_events_views_v2_view_prev_url', 'conc_tec_strip_pagename_nav_url', 20, 3 );
add_filter( 'tribe_events_views_v2_view_next_url', 'conc_tec_strip_pagename_nav_url', 20, 3 );
add_filter( 'tribe_events_views_v2_view_url', 'conc_tec_strip_pagename_nav_url', 20, 3 );
