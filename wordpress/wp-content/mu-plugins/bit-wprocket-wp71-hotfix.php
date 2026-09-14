<?php
/**
 * Plugin Name: BIT WP Rocket × WordPress 7.1 hotfix
 * Description: Remove do init o callback WP_Rocket\ThirdParty\Plugins\CDN\Cloudflare::unregister_cloudflare_clean_on_post(). No WordPress 7.1 a chave de callbacks com Closure passou a ser (string) spl_object_id() — numérica, logo vira int como chave de array — e o WP Rocket 3.20.5 (strict_types) faz substr($key) e estoura com TypeError em toda request (fatal 500 no site inteiro, medido no dev em 14/09/2026 com a Closure do Elementor Pro em deleted_post). O callback só serve para quem usa o plugin Cloudflare, que este site não tem. Remover quando o WP Rocket publicar a correção.
 * Version: 1.1.0
 * Author: Daniel Cambría
 */
if ( ! defined( 'ABSPATH' ) ) {
	return;
}
add_action( 'init', function () {
	global $wp_filter, $wp_version;
	// Só onde o defeito existe: antes do 7.1 a chave era spl_object_hash (hex) e o substr passava.
	if ( version_compare( (string) $wp_version, '7.1', '<' ) || empty( $wp_filter['init'] ) ) {
		return;
	}
	// A remoção é segura por AUSÊNCIA do plugin Cloudflare. Ausência não avisa quando acaba:
	// se um dia ele for instalado com WP Rocket, o log denuncia que a integração ficou sem o cleanup.
	if ( function_exists( 'is_plugin_active' ) && is_plugin_active( 'cloudflare/cloudflare.php' ) ) {
		error_log( 'bit-wprocket-wp71-hotfix: plugin Cloudflare ativo — o hotfix remove o cleanup do WP Rocket para evitar o fatal; revisar.' );
	}
	foreach ( $wp_filter['init']->callbacks as $priority => $callbacks ) {
		foreach ( $callbacks as $key => $cb ) {
			$fn = $cb['function'] ?? null;
			if ( is_array( $fn ) && is_object( $fn[0] ?? null )
				&& $fn[0] instanceof \WP_Rocket\ThirdParty\Plugins\CDN\Cloudflare
				&& 'unregister_cloudflare_clean_on_post' === ( $fn[1] ?? '' ) ) {
				unset( $wp_filter['init']->callbacks[ $priority ][ $key ] );
			}
		}
	}
}, 0 ); // antes da prioridade 10 em que o WP Rocket registra o subscriber
