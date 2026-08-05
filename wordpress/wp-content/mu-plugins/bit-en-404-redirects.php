<?php
/**
 * Plugin Name: BIT — Redirects EN 404
 * Description: Redireciona URLs antigas/inexistentes do site EN para destinos vivos, antes do WPML capturar como 404. Origens vinham de versões anteriores do menu/SEO em PROD que ainda recebem visitas (links externos, indexação Google, e-mails).
 * Version: 1.0.0
 * Author: Daniel Cambría
 */

if ( ! defined( 'ABSPATH' ) ) exit;

( function () {
	if ( php_sapi_name() === 'cli' ) return;
	if ( ! isset( $_SERVER['REQUEST_URI'] ) ) return;

	$path = strtok( $_SERVER['REQUEST_URI'], '?' );
	if ( substr( $path, -1 ) !== '/' ) $path .= '/';

	$map = [
		'/en/proposals/'                    => '/en/knowledge/',
		'/en/contact/'                      => '/en/contact_us/',
		'/en/timeline/'                     => '/cultura/en/timeline/',
		'/en/cultural-atlas/'               => '/cultura/en/cultural-atlas-of-the-amazon/',
		'/en/culture/timeline/'             => '/cultura/en/timeline/',
		'/en/culture/cultural-atlas/'       => '/cultura/en/cultural-atlas-of-the-amazon/',
		'/en/culture/gallery/'              => '/cultura/en/gallery/',
		'/en/culture/porosity-exhibition/'  => '/cultura/en/porosidades/',
	];

	if ( ! isset( $map[ $path ] ) ) return;

	$scheme = ( ! empty( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] !== 'off' ) ? 'https' : 'http';
	$host   = $_SERVER['HTTP_HOST'] ?? 'cambrasmax.local';
	$target = $scheme . '://' . $host . $map[ $path ];

	header( 'Location: ' . $target, true, 301 );
	exit;
} )();
