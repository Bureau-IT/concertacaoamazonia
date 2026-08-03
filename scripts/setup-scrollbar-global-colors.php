<?php
/**
 * setup-scrollbar-global-colors.php
 *
 * Passa as cores da barra de rolagem a serem governadas pelos Global Colors do
 * Elementor, em vez dos hex cravados em css/base.css:66-67.
 *
 * Contexto: base.css declarava
 *     --bit-scrollbar-thumb: var(--e-global-color-accent, #FE78A9);
 * no escopo :root/html. O Elementor só declara --e-global-color-* dentro de
 * .elementor-kit-N (que vive no <body>), então o var() NUNCA resolvia e a
 * scrollbar caía sempre no fallback. Resultado: mudar o Acento no painel não
 * tinha efeito nenhum. O tema agora imprime as cores no <html> lendo o kit
 * (ver bureau_it_scrollbar_global_colors() em functions.php).
 *
 * Este script prepara os Global Colors correspondentes, alinhados aos cinzas da
 * paleta do site:
 *   - scroll [handle]        (novo)     #474747  — Accent color (cinza da paleta)
 *   - scroll [track]         (existia)  #DADADA  — Offwhite (estava #000000)
 *   - scroll [handle:hover]  (existia)  #1C1C1C  — Main color (mais escuro)
 *
 * Histórico: até 03/08/2026 a barra renderizava #FE78A9 / #DEDDD1 (hex cravado
 * no base.css, sem relação com a paleta). A v1.0.0 deste script criou as cores
 * preservando esses valores; em seguida foram trocadas para os cinzas acima.
 *
 * Idempotente: só escreve o que estiver divergente.
 *
 * Uso (dev):
 *   docker exec -u www-data concertacao-dev-wordpress \
 *     wp --url="https://cambrasmax.local:8484" eval-file /tmp/setup-scrollbar-global-colors.php
 *
 * Aplica em TODOS os blogs da rede (kit ativo de cada um).
 *
 * @author  Daniel Cambría
 * @version 1.1.0
 * @date    2026-08-02
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

const SCROLL_HANDLE_ID = '5c0117b';

/** _id => [title, color] */
$targets = [
	SCROLL_HANDLE_ID => [ 'scroll [handle]', '#474747' ],
	'f1d8cc9'        => [ 'scroll [track]', '#DADADA' ],
	'44d5626'        => [ 'scroll [handle:hover]', '#1C1C1C' ],
];

$blog_ids = is_multisite()
	? get_sites( [ 'fields' => 'ids', 'number' => 0 ] )
	: [ 0 ];

foreach ( $blog_ids as $blog_id ) {
	if ( is_multisite() ) {
		switch_to_blog( $blog_id );
	}

	$kit_id = (int) get_option( 'elementor_active_kit' );
	if ( ! $kit_id ) {
		echo "blog {$blog_id}: sem kit ativo — pulado\n";
		if ( is_multisite() ) {
			restore_current_blog();
		}
		continue;
	}

	$settings = get_post_meta( $kit_id, '_elementor_page_settings', true );
	if ( ! is_array( $settings ) ) {
		echo "blog {$blog_id}: kit {$kit_id} sem _elementor_page_settings — pulado\n";
		if ( is_multisite() ) {
			restore_current_blog();
		}
		continue;
	}

	$custom  = isset( $settings['custom_colors'] ) && is_array( $settings['custom_colors'] )
		? $settings['custom_colors']
		: [];
	$changed = false;

	foreach ( $targets as $id => list( $title, $color ) ) {
		$found = false;

		foreach ( $custom as $i => $entry ) {
			if ( ( $entry['_id'] ?? '' ) !== $id ) {
				continue;
			}
			$found = true;
			if ( strcasecmp( $entry['color'] ?? '', $color ) !== 0 ) {
				echo "blog {$blog_id}: {$title} ({$id}) " . ( $entry['color'] ?? '(vazio)' ) . " -> {$color}\n";
				$custom[ $i ]['color'] = $color;
				$changed               = true;
			}
			// Não sobrescreve título já existente — o usuário pode ter renomeado.
			break;
		}

		if ( ! $found ) {
			echo "blog {$blog_id}: criando {$title} ({$id}) = {$color}\n";
			$custom[] = [
				'_id'   => $id,
				'title' => $title,
				'color' => $color,
			];
			$changed = true;
		}
	}

	if ( $changed ) {
		$settings['custom_colors'] = $custom;
		update_post_meta( $kit_id, '_elementor_page_settings', $settings );
		echo "blog {$blog_id}: kit {$kit_id} atualizado\n";
	} else {
		echo "blog {$blog_id}: kit {$kit_id} já estava correto\n";
	}

	if ( is_multisite() ) {
		restore_current_blog();
	}
}

// Regenera o CSS do kit para que as novas vars saiam no post-{kit}.css.
if ( class_exists( '\Elementor\Plugin' ) ) {
	\Elementor\Plugin::$instance->files_manager->clear_cache();
	echo "Elementor: cache de CSS limpo\n";
}

echo "Concluído.\n";
