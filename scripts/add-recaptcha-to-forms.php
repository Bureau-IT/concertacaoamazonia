<?php
/**
 * add-recaptcha-to-forms.php
 *
 * Adiciona campo reCAPTCHA v3 a forms Elementor Pro que estão sem proteção.
 * Idempotente: se o form já tem recaptcha_v3, pula.
 *
 * Uso (DRY-RUN por padrão):
 *   wp eval-file add-recaptcha-to-forms.php
 * Aplicar:
 *   ADD_RECAPTCHA_APPLY=1 wp eval-file add-recaptcha-to-forms.php
 *
 * Alvo: posts passados via ADD_RECAPTCHA_POSTS (csv) ou default abaixo.
 * Multisite: rodar com --url do blog correto.
 *
 * @author Daniel Cambría
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$apply  = getenv( 'ADD_RECAPTCHA_APPLY' ) === '1';
$posts_env = getenv( 'ADD_RECAPTCHA_POSTS' );
$target_posts = $posts_env ? array_map( 'intval', explode( ',', $posts_env ) ) : array( 47313, 47382 );

// Campo reCAPTCHA v3 modelo (idêntico ao usado no form Contato 672)
function bit_recaptcha_field( $unique_id ) {
	return array(
		'_id'             => $unique_id,
		'field_type'      => 'recaptcha_v3',
		'field_label'     => 'recaptcha',
		'recaptcha_badge' => 'bottomleft',
		'custom_id'       => 'recaptcha',
	);
}

$total_changed = 0;

foreach ( $target_posts as $post_id ) {
	$raw = get_post_meta( $post_id, '_elementor_data', true );
	if ( empty( $raw ) ) {
		echo "post $post_id: SEM _elementor_data — pulando\n";
		continue;
	}
	$data = json_decode( $raw, true );
	if ( ! is_array( $data ) ) {
		echo "post $post_id: _elementor_data inválido (json_decode falhou) — pulando\n";
		continue;
	}

	$changed_in_post = 0;

	$walk = function ( &$nodes ) use ( &$walk, &$changed_in_post, $post_id ) {
		foreach ( $nodes as &$node ) {
			if ( ( $node['widgetType'] ?? '' ) === 'form' ) {
				$fields = $node['settings']['form_fields'] ?? array();
				$has_recaptcha = false;
				foreach ( $fields as $f ) {
					if ( ( $f['field_type'] ?? '' ) === 'recaptcha_v3' ) { $has_recaptcha = true; break; }
				}
				if ( ! $has_recaptcha ) {
					// _id único curto (7 hex) — padrão Elementor
					$uid = substr( md5( $post_id . ( $node['id'] ?? '' ) . 'recaptcha' ), 0, 7 );
					$node['settings']['form_fields'][] = bit_recaptcha_field( $uid );
					$changed_in_post++;
					echo "  post $post_id widget " . ( $node['id'] ?? '?' ) . ": + recaptcha_v3 (_id=$uid)\n";
				} else {
					echo "  post $post_id widget " . ( $node['id'] ?? '?' ) . ": já tem recaptcha — pula\n";
				}
			}
			if ( ! empty( $node['elements'] ) ) {
				$walk( $node['elements'] );
			}
		}
		unset( $node );
	};
	$walk( $data );

	if ( $changed_in_post > 0 ) {
		if ( $apply ) {
			// wp_slash OBRIGATÓRIO ao re-encodar _elementor_data do zero
			// (senão stripslashes_deep do metadata API quebra \n e \uXXXX → json_decode NULL)
			$new = wp_slash( wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
			update_post_meta( $post_id, '_elementor_data', $new );
			// validar pós-write
			$check = json_decode( get_post_meta( $post_id, '_elementor_data', true ), true );
			echo "post $post_id: APLICADO ($changed_in_post form(s)). Validação json_decode pós-write: " . ( is_array( $check ) ? 'OK' : 'FALHOU!!' ) . "\n";
			// limpar Elementor element cache do post
			delete_post_meta( $post_id, '_elementor_element_cache' );
		} else {
			echo "post $post_id: DRY-RUN — $changed_in_post form(s) receberiam recaptcha (set ADD_RECAPTCHA_APPLY=1 p/ aplicar)\n";
		}
		$total_changed += $changed_in_post;
	}
}

echo "\nTOTAL forms " . ( $apply ? 'alterados' : 'a alterar' ) . ": $total_changed\n";
