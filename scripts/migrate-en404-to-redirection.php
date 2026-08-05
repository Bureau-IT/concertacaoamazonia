<?php
/**
 * migrate-en404-to-redirection.php — tira os redirects EN do mu-plugin
 *
 * Autor : Daniel Cambría — Bureau de Tecnologia
 * Data  : 2026-08-05
 * Versão: 2.0.0
 *
 * CONTEXTO
 *   O mu-plugin `bit-en-404-redirects.php` mantinha 8 redirects EN num $map
 *   hardcoded. O site tem o plugin Redirection ativo em rede — o lugar certo,
 *   porque é editável pelo painel e auditável.
 *
 *   Pior: mu-plugins carregam ANTES dos plugins, então a regra hardcoded
 *   vencia qualquer regra equivalente do Redirection, deixando o painel
 *   aparentemente sem efeito.
 *
 *   Este script cria as 8 regras no Redirection para que o mu-plugin possa ser
 *   removido. Idempotente: regra que já exista é pulada.
 *
 * USO
 *   DRY-RUN (default):  wp --url=... eval-file migrate-en404-to-redirection.php
 *   APLICAR:            EN404_MIGRATE_APPLY=1 wp --url=... eval-file ...
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( "Este script roda somente via WP-CLI.\n" );
}

$apply = (bool) getenv( 'EN404_MIGRATE_APPLY' );

/* Cópia fiel do $map do mu-plugin v1.0.0. */
$MAP = array(
	'/en/proposals/'                   => '/en/knowledge/',
	'/en/contact/'                     => '/en/contact_us/',
	'/en/timeline/'                    => '/cultura/en/timeline/',
	'/en/cultural-atlas/'              => '/cultura/en/cultural-atlas-of-the-amazon/',
	'/en/culture/timeline/'            => '/cultura/en/timeline/',
	'/en/culture/cultural-atlas/'      => '/cultura/en/cultural-atlas-of-the-amazon/',
	'/en/culture/gallery/'             => '/cultura/en/gallery/',
	'/en/culture/porosity-exhibition/' => '/cultura/en/porosidades/',
);

$GRUPO = 1; // "Redirecionamentos" — onde vivem as 42 regras existentes

global $wpdb;
$tabela = $wpdb->prefix . 'redirection_items';

if ( $wpdb->get_var( "SHOW TABLES LIKE '$tabela'" ) !== $tabela ) {
	WP_CLI::error( "Tabela $tabela não existe — o Redirection está ativo neste blog?" );
}

WP_CLI::log( sprintf( 'Blog %d (%s) — modo: %s', get_current_blog_id(), home_url(), $apply ? 'APLICAR' : 'DRY-RUN' ) );
WP_CLI::log( sprintf( "Grupo destino: %d  |  regras hoje na tabela: %d\n",
	$GRUPO, (int) $wpdb->get_var( "SELECT COUNT(*) FROM $tabela" ) ) );

$criados = 0;
$pulados = 0;

foreach ( $MAP as $de => $para ) {
	$existe = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM $tabela WHERE url = %s", $de
	) );

	if ( $existe ) {
		WP_CLI::log( sprintf( '  = %-34s já existe no Redirection', $de ) );
		$pulados++;
		continue;
	}

	WP_CLI::log( sprintf( '  + %-34s -> %s', $de, $para ) );

	if ( $apply ) {
		// SEMPRE pela API do plugin, nunca $wpdb->insert direto.
		//
		// Red_Item::create() não é açúcar sintático: além do INSERT ele
		// normaliza a URL (o `match_url` é gravado SEM barra final), calcula
		// `position` dentro do grupo e — o que importa — chama
		// Red_Module::flush(), que invalida o cache de regras compiladas do
		// plugin. Inserindo por SQL direto a regra aparece no painel e NUNCA
		// dispara: medido aqui, com os 8 devolvendo 200 (soft 404) em vez de
		// 301, mesmo com match_url e match_data idênticos aos de uma regra boa.
		$novo = Red_Item::create( array(
			'url'         => $de,
			'action_type' => 'url',
			'action_code' => 301,
			'action_data' => array( 'url' => $para ),
			'match_type'  => 'url',
			'group_id'    => $GRUPO,
			'title'       => 'EN legado (migrado do mu-plugin bit-en-404-redirects)',
			'status'      => 'enabled',
		) );

		if ( is_wp_error( $novo ) ) {
			WP_CLI::error( "    falha ao criar $de: " . $novo->get_error_message() );
		}
		$criados++;
	}
}

WP_CLI::log( sprintf( "\nResumo: %d criado(s), %d já existente(s)", $criados, $pulados ) );

if ( $apply ) {
	// Red_Item::create() já chama Red_Module::flush() por regra; este é só o
	// object cache do WP.
	wp_cache_flush();
	WP_CLI::success( 'Regras gravadas. Agora o mu-plugin pode ser removido.' );
} else {
	WP_CLI::log( 'DRY-RUN — nada gravado. Rode com EN404_MIGRATE_APPLY=1' );
}
