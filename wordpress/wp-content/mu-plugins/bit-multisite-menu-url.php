<?php
/**
 * Plugin Name: BIT Multisite Menu URL
 * Description: Corrige URLs de menu cross-blog em multisite subdirectory com WPML.
 *              Garante que itens do Blog 1 apontando para o Blog 2 usem o padrão
 *              correto de idioma: /{subsite}/{lang}/ em vez de /{lang}/{subsite}/.
 * Version: 1.0.0
 * Author: Bureau de Tecnologia
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mapeamento de subsites: path do subsite => idiomas suportados (exceto o default).
 * Adicionar novos subsites aqui conforme o multisite crescer.
 *
 * @filter bit_multisite_subsite_langs
 */
$bit_multisite_subsite_langs = apply_filters( 'bit_multisite_subsite_langs', [
	'cultura' => [ 'en' ],
] );

/**
 * Reescreve URLs de itens de menu custom que usam o padrão errado /{lang}/{subsite}/
 * para o padrão correto /{subsite}/{lang}/ antes do render.
 *
 * Executa em priority 5, antes do filtro WPML (priority 10 em wp_nav_menu_args),
 * o que garante que o WPML não interfere com a correção.
 */
add_filter( 'wp_nav_menu_objects', function ( array $items ) use ( $bit_multisite_subsite_langs ): array {
	foreach ( $items as $item ) {
		if ( empty( $item->url ) || $item->type !== 'custom' ) {
			continue;
		}

		$url = $item->url;

		foreach ( $bit_multisite_subsite_langs as $subsite => $langs ) {
			foreach ( $langs as $lang ) {
				// Detectar padrão errado: /{lang}/{subsite}/ → /{subsite}/{lang}/
				// Ex: /en/cultura/ → /cultura/en/
				// Funciona para qualquer hostname (local, tunnel, prod).
				$wrong_pattern  = "/{$lang}/{$subsite}/";
				$correct_prefix = "/{$subsite}/{$lang}/";

				if ( strpos( $url, $wrong_pattern ) !== false ) {
					$item->url = str_replace( $wrong_pattern, $correct_prefix, $url );
					break 2;
				}
			}
		}
	}

	return $items;
}, 5 ); // Priority 5: antes do WPML (priority 10)
