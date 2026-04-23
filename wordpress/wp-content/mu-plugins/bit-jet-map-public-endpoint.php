<?php
/**
 * Plugin Name: BIT JetEngine Map Popup Public Endpoint
 * Description: Fix popup "[object Object]" no Atlas Cultural causado por nonce REST expirado em HTML cacheado (CloudFront + WP Rocket). Remove exigencia de nonce no endpoint publico /jet-engine/v2/get-map-marker-info (GET) e emite Cache-Control + Vary:Cookie para permitir cache seguro em CDN (anonimo determinantico; logado nunca cacheia).
 * Version: 1.2.0
 * Author: Daniel Cambria
 *
 * Contexto: o endpoint /wp-json/jet-engine/v2/get-map-marker-info/ retorna
 * conteudo publico (popup de posts publicados; drafts/privates ja sao
 * guardados via current_user_can no proprio endpoint). Quando o HTML da
 * pagina e cacheado, o nonce embutido expira (tick de 12h do WP) e o core
 * rejeita a request com 403 rest_cookie_invalid_nonce - o JS do JetEngine
 * cai no .fail() e exibe "[object Object]" no popup.
 *
 * Solucao: interceptar rest_pre_dispatch ANTES do rest_cookie_check_errors
 * rodar, remover o header X-WP-Nonce da request para que o WP trate como
 * anonima (o permission_callback do endpoint ja e true). Em paralelo,
 * setar Cache-Control no response para permitir que CloudFront cacheie
 * o JSON deterministico por post_id.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'rest_authentication_errors', function ( $result ) {
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';

	if ( false === strpos( $uri, '/jet-engine/v2/get-map-marker-info' ) ) {
		return $result;
	}

	if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'GET' !== strtoupper( $_SERVER['REQUEST_METHOD'] ) ) {
		return $result;
	}

	unset( $_SERVER['HTTP_X_WP_NONCE'] );
	unset( $_REQUEST['_wpnonce'] );
	unset( $_GET['_wpnonce'] );

	return $result;
}, 99 );

add_filter( 'rest_post_dispatch', function ( $response, $server, $request ) {
	if ( '/jet-engine/v2/get-map-marker-info' !== $request->get_route() ) {
		return $response;
	}

	if ( 'GET' !== $request->get_method() ) {
		return $response;
	}

	if ( is_user_logged_in() ) {
		$response->header( 'Cache-Control', 'private, no-store, max-age=0' );
		$response->header( 'Vary', 'Cookie' );
		return $response;
	}

	$response->header( 'Cache-Control', 'public, max-age=3600, s-maxage=86400' );
	$response->header( 'Vary', 'Cookie' );

	return $response;
}, 10, 3 );
