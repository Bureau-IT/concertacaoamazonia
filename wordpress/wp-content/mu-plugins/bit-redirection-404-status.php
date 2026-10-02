<?php
/**
 * Plugin Name: BIT Redirection 404 Status
 * Description: Devolve o 404 às URLs inexistentes quando o Redirection tem
 *              "Permalink Migration" configurado. Sem isto, toda URL que não
 *              existe responde 200 com o último post (soft 404).
 * Version: 1.0.0
 * Author: Daniel Cambría / Bureau de Tecnologia Ltda.
 *
 * A causa, medida em 02/10/2026 com o Redirection 5.10.0: no pre_handle_404
 * (prioridade 10), Red_Permalinks::migrate() roda $wp->init() e
 * $wp->parse_request() de novo, no $wp global, com a estrutura antiga
 * (/%postname%/). Quando a regra antiga casa e acha um post, ele redireciona e
 * encerra — é o 301 de /<slug>/ para /blog/<slug>/, que deve continuar. Quando
 * casa e não acha nada, ele restaura o $wp_query, mas não o $wp: as query vars
 * ficam as da estrutura antiga, sem o error=404. Desde o WP 6.1 o send_headers
 * roda depois do handle_404, então ele não vê mais o erro e não manda o 404.
 *
 * O conserto não toca o plugin (o update apagaria): guarda o estado do $wp
 * antes do hook do Redirection e o devolve depois. Se o Redirection
 * redirecionou, o request já acabou e a restauração nunca roda.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function bit_redirection_404_status_state( ?array $set = null ): ?array {
	static $state = null;

	if ( null !== $set ) {
		$state = $set ?: null;
	}

	return $state;
}

add_filter( 'pre_handle_404', function ( $result ) {
	global $wp;

	if ( $wp instanceof WP ) {
		bit_redirection_404_status_state( [
			'query_vars'    => $wp->query_vars,
			'query_string'  => $wp->query_string,
			'request'       => $wp->request,
			'matched_rule'  => $wp->matched_rule,
			'matched_query' => $wp->matched_query,
			'did_permalink' => $wp->did_permalink,
		] );
	}

	return $result;
}, 9 );

add_filter( 'pre_handle_404', function ( $result ) {
	global $wp;

	$state = bit_redirection_404_status_state();
	bit_redirection_404_status_state( [] );

	if ( $state && $wp instanceof WP && $wp->query_vars !== $state['query_vars'] ) {
		foreach ( $state as $prop => $value ) {
			$wp->$prop = $value;
		}

		// O migrate() roda $wp->query_posts(), que refaz a query principal
		// ($wp_the_query) com a estrutura antiga, e depois devolve ao $wp_query
		// um clone da original. As duas deixam de ser o mesmo objeto, e o
		// Yoast e o Elementor, que leem a principal, tratavam a página como
		// single do último post (título, canonical, schema Article).
		if ( $GLOBALS['wp_query'] instanceof WP_Query ) {
			$GLOBALS['wp_the_query'] = $GLOBALS['wp_query']; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		}
	}

	return $result;
}, 11 );
