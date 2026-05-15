<?php
/**
 * remove-mailerlite-from-forms.php
 *
 * Remove a action "mailerlite" do array submit_actions de TODOS os Form
 * widgets do Elementor em _elementor_data, em ambos os blogs do multisite.
 *
 * Backup de _elementor_data salvo em meta key _elementor_data_pre_mailerlite_removal_<TS>.
 *
 * Uso:
 *   sudo -u www-data wp eval-file scripts/remove-mailerlite-from-forms.php [--dry-run]
 *
 * Idempotente: se um post já não tem mailerlite, é skipped.
 * Cobre revisions também (não estraga histórico do post pai).
 */

$dry_run = ! empty( getenv( 'DRY_RUN' ) );
$ts      = time();
$summary = [
	'blogs'         => [],
	'total_posts'   => 0,
	'total_changed' => 0,
	'errors'        => [],
];

// Multisite: itera os blogs ativos.
$blog_ids = is_multisite() ? wp_list_pluck( get_sites( [ 'fields' => 'ids' ] ), 0 ) : [ 1 ];
if ( empty( $blog_ids ) ) {
	$blog_ids = is_multisite()
		? array_map( fn( $s ) => (int) $s->blog_id, get_sites() )
		: [ 1 ];
}

foreach ( $blog_ids as $blog_id ) {
	if ( is_multisite() ) {
		switch_to_blog( $blog_id );
	}

	global $wpdb;
	$prefix = $wpdb->prefix;
	WP_CLI::log( "[blog={$blog_id}] prefix={$prefix} — buscando posts com mailerlite..." );

	$ids = $wpdb->get_col(
		"SELECT post_id FROM {$prefix}postmeta
		 WHERE meta_key = '_elementor_data'
		   AND meta_value LIKE '%mailerlite%'"
	);

	$blog_changed = 0;
	foreach ( $ids as $post_id ) {
		$summary['total_posts']++;
		$raw = get_post_meta( $post_id, '_elementor_data', true );
		if ( empty( $raw ) ) {
			continue;
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			$summary['errors'][] = "blog={$blog_id} post={$post_id}: JSON inválido";
			continue;
		}

		$changes = 0;
		walk_elements( $data, $changes );

		if ( $changes === 0 ) {
			continue;
		}

		if ( $dry_run ) {
			WP_CLI::log( "  [DRY] blog={$blog_id} post={$post_id}: {$changes} occurrence(s) seriam removidas" );
			$blog_changed++;
			continue;
		}

		// Backup do _elementor_data original
		update_post_meta( $post_id, "_elementor_data_pre_mailerlite_removal_{$ts}", $raw );

		// Re-encode e salvar (wp_slash obrigatório — _elementor_data é JSON com aspas)
		$new_json = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( $new_json === false ) {
			$summary['errors'][] = "blog={$blog_id} post={$post_id}: falha ao re-encodar JSON";
			continue;
		}
		update_post_meta( $post_id, '_elementor_data', wp_slash( $new_json ) );

		// Limpar cache do Elementor pra esse post (CSS regen on next render)
		delete_post_meta( $post_id, '_elementor_css' );
		delete_post_meta( $post_id, '_elementor_element_cache' );

		WP_CLI::log( "  [OK] blog={$blog_id} post={$post_id}: {$changes} occurrence(s) removida(s)" );
		$blog_changed++;
		$summary['total_changed']++;
	}

	$summary['blogs'][ $blog_id ] = $blog_changed;

	if ( is_multisite() ) {
		restore_current_blog();
	}
}

WP_CLI::log( '' );
WP_CLI::log( '=== Resumo ===' );
WP_CLI::log( 'Posts inspecionados: ' . $summary['total_posts'] );
WP_CLI::log( 'Posts alterados:     ' . $summary['total_changed'] );
foreach ( $summary['blogs'] as $bid => $count ) {
	WP_CLI::log( "  blog {$bid}: {$count} posts" );
}
if ( ! empty( $summary['errors'] ) ) {
	WP_CLI::warning( 'Erros encontrados:' );
	foreach ( $summary['errors'] as $e ) {
		WP_CLI::warning( "  - {$e}" );
	}
}
if ( $dry_run ) {
	WP_CLI::log( '' );
	WP_CLI::log( 'DRY-RUN — nada foi modificado. Rode sem --dry-run para aplicar.' );
} else {
	WP_CLI::log( '' );
	WP_CLI::log( "Backup salvo em meta key: _elementor_data_pre_mailerlite_removal_{$ts}" );
	WP_CLI::log( 'Cache Elementor do post limpo (_elementor_css, _elementor_element_cache).' );
	WP_CLI::log( 'Próximos passos: flush Redis + regen Elementor CSS via wp elementor flush-css.' );
}

/**
 * Recursão sobre os elementos do _elementor_data.
 * Para cada widget Form, remove "mailerlite" de submit_actions[] e
 * também limpa o sub-array de mapping (mailerlite_*) por completude.
 */
function walk_elements( array &$elements, int &$changes ): void {
	foreach ( $elements as &$el ) {
		if ( ! is_array( $el ) ) {
			continue;
		}

		$is_form = isset( $el['widgetType'] ) && $el['widgetType'] === 'form';
		if ( $is_form && isset( $el['settings'] ) && is_array( $el['settings'] ) ) {
			$s = &$el['settings'];

			if ( isset( $s['submit_actions'] ) && is_array( $s['submit_actions'] ) ) {
				$before = count( $s['submit_actions'] );
				$s['submit_actions'] = array_values(
					array_filter(
						$s['submit_actions'],
						fn( $a ) => $a !== 'mailerlite'
					)
				);
				$after = count( $s['submit_actions'] );
				if ( $after < $before ) {
					$changes += ( $before - $after );
				}
			}

			// Limpa também todas as configs mailerlite_* (mapping, api_key reference, lists)
			foreach ( array_keys( $s ) as $key ) {
				if ( strpos( $key, 'mailerlite_' ) === 0 ) {
					unset( $s[ $key ] );
				}
			}
			unset( $s );
		}

		// Recursão em filhos
		if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
			walk_elements( $el['elements'], $changes );
		}
	}
}
