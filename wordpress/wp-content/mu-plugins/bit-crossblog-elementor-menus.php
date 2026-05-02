<?php
/**
 * Plugin Name: BIT Cross-Blog Elementor Menus
 * Description: Injeta menus de outros blogs do multisite como opções disponíveis
 *              no widget nav-menu do Elementor Pro. Os menus do blog de origem
 *              são prefixados com "blog{N}:" para identificação, e na renderização
 *              o conteúdo é carregado via switch_to_blog().
 * Version: 1.1.0
 * Author: Bureau de Tecnologia
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Configuração: quais blogs terão seus menus injetados no Elementor do blog atual.
 * Chave: blog_id de origem. Valor: label de prefixo exibido na lista.
 *
 * @filter bit_crossblog_elementor_menu_sources
 */
function bit_crossblog_elementor_menu_sources(): array {
	return apply_filters( 'bit_crossblog_elementor_menu_sources', [
		1 => 'Raiz',
	] );
}

/**
 * Prefixo usado nos slugs para identificar menus cross-blog.
 * Formato: "crossblog{blog_id}_{menu_slug}"
 */
function bit_crossblog_menu_slug( int $blog_id, string $slug ): string {
	return "crossblog{$blog_id}_{$slug}";
}

/**
 * Detecta se um slug é cross-blog e retorna [blog_id, slug_original] ou false.
 */
function bit_crossblog_parse_slug( string $slug ) {
	if ( preg_match( '/^crossblog(\d+)_(.+)$/', $slug, $m ) ) {
		return [ (int) $m[1], $m[2] ];
	}
	return false;
}

// ─── 1. Injetar menus remotos na lista do widget Elementor ───────────────────

add_filter( 'wp_get_nav_menus', function ( array $menus, array $args ) {
	// Só no admin (Elementor editor carrega isso via AJAX no painel)
	if ( ! is_admin() && ! wp_doing_ajax() ) {
		return $menus;
	}

	$sources = bit_crossblog_elementor_menu_sources();

	foreach ( $sources as $blog_id => $label_prefix ) {
		if ( (int) $blog_id === get_current_blog_id() ) {
			continue;
		}

		switch_to_blog( $blog_id );
		$remote_menus = get_terms( [
			'taxonomy'   => 'nav_menu',
			'hide_empty' => true,
		] );
		restore_current_blog();

		if ( is_wp_error( $remote_menus ) || empty( $remote_menus ) ) {
			continue;
		}

		foreach ( $remote_menus as $menu ) {
			// Clonar o objeto para não mutar o original
			$proxy          = clone $menu;
			$proxy->slug    = bit_crossblog_menu_slug( $blog_id, $menu->slug );
			$proxy->name    = "[{$label_prefix}] {$menu->name}";
			$proxy->term_id = $menu->term_id; // ID do blog remoto (usado só no editor)
			$menus[]        = $proxy;
		}
	}

	return $menus;
}, 10, 2 );

// ─── 2. Interceptar wp_nav_menu() para renderizar com switch_to_blog ─────────

add_filter( 'wp_nav_menu_args', function ( array $args ): array {
	$menu_val = $args['menu'] ?? '';

	// Aceitar WP_Term (quando o Elementor já resolveu o objeto)
	if ( $menu_val instanceof WP_Term ) {
		$slug = $menu_val->slug;
	} elseif ( is_string( $menu_val ) ) {
		$slug = $menu_val;
	} else {
		return $args;
	}

	$parsed = bit_crossblog_parse_slug( $slug );
	if ( ! $parsed ) {
		return $args;
	}

	[ $blog_id, $original_slug ] = $parsed;

	// Marcar para interceptação em wp_nav_menu_objects
	$args['bit_crossblog_id']   = $blog_id;
	$args['bit_crossblog_slug'] = $original_slug;

	// Substituir pelo objeto real do blog remoto
	switch_to_blog( $blog_id );
	$real_menu    = wp_get_nav_menu_object( $original_slug );
	restore_current_blog();

	if ( $real_menu ) {
		$args['menu'] = $real_menu;
	}

	return $args;
}, 5 );

// ─── 3. Carregar os itens do menu do blog remoto ─────────────────────────────

add_filter( 'wp_get_nav_menu_items', function ( $items, $menu, $args ) {
	// $args chega como array (não objeto) — acessar como array
	$args_array = is_object( $args ) ? (array) $args : (array) $args;

	if ( empty( $args_array['bit_crossblog_id'] ) ) {
		return $items;
	}

	$blog_id = (int) $args_array['bit_crossblog_id'];

	// Remover a marcação para evitar recursão ao chamar wp_get_nav_menu_items
	// internamente no contexto do blog remoto
	$clean_args = $args_array;
	unset( $clean_args['bit_crossblog_id'], $clean_args['bit_crossblog_slug'] );

	switch_to_blog( $blog_id );
	$remote_items = wp_get_nav_menu_items( $menu->term_id, $clean_args );
	restore_current_blog();

	return $remote_items ?: $items;
}, 10, 3 );

// ─── 4. Garantir que wp_nav_menu_objects passa pelo mu-plugin de correção ─────
//     (bit-multisite-menu-url.php já atua em priority 5 nesse hook)
//     Nada a fazer aqui — os itens do blog 1 já têm URLs corretas.
