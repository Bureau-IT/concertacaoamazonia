<?php
/**
 * update-footer-form-email-template.php
 *
 * Atualiza o template de email do formulário "Footer do Site" (form_id=18af5b7)
 * no post 72234 (template Footer Elementor). Aplica em ambos os blogs do
 * multisite (footer compartilhado via Network).
 *
 * Mudanças:
 * - email_content reescrito (legível, sem campos vazios, sem ruído)
 * - email_subject ajustado com prefixo [Newsletter]
 * - email_content_type = html (formatação básica)
 * - email_include_metadata = remove dump técnico do Elementor (Data/Horário/IP/UA)
 *
 * Backup salvo em meta key _elementor_data_pre_email_template_<TS>.
 *
 * Idempotente: skip se template já está no formato novo (detecta marker
 * "[Newsletter Rodapé]" no email_subject).
 *
 * Uso (DEV ou PROD via SSH):
 *   sudo -u www-data wp eval-file scripts/update-footer-form-email-template.php
 */

namespace BIT\UpdateFooterEmailTemplate;

const FOOTER_TEMPLATE_POST_ID = 72234;
const TARGET_FORM_ID          = '18af5b7';
const TEMPLATE_VERSION        = 'v3';
const NEW_SUBJECT             = '[Newsletter Rodapé v3] Nova inscrição em Uma Concertação pela Amazônia';

const NEW_CONTENT = <<<HTML
<p>Olá, Letícia!</p>

<p>Uma nova pessoa se inscreveu na newsletter do rodapé do site.</p>

<p>
  <strong>E-mail:</strong> [field id="form_email_desk"]<br>
  <strong>Estado:</strong> [field id="form_email_regiao"]
</p>

<p>Até breve,<br>
<em>BIT BPO Customer Care</em></p>
HTML;

// Metadata desejada (em ordem): Data, Horário, URL da página, User Agent, IP remoto.
// EXCLUI 'credit' (Powered by Elementor) propositalmente.
const METADATA_KEYS = [ 'date', 'time', 'page_url', 'user_agent', 'remote_ip' ];

function update_form( $post_id, $form_id ): array {
	$raw = get_post_meta( $post_id, '_elementor_data', true );
	if ( empty( $raw ) ) {
		return [ 'ok' => false, 'reason' => 'meta_not_found' ];
	}
	$data = json_decode( $raw, true );
	if ( ! is_array( $data ) ) {
		return [ 'ok' => false, 'reason' => 'json_invalid' ];
	}

	$found    = false;
	$skipped  = false;
	walk_form( $data, $form_id, $found, $skipped );

	if ( ! $found ) {
		return [ 'ok' => false, 'reason' => 'form_not_found' ];
	}
	if ( $skipped ) {
		return [ 'ok' => true, 'reason' => 'already_updated', 'changed' => false ];
	}

	// Backup pre-edit
	update_post_meta( $post_id, '_elementor_data_pre_email_template_' . time(), $raw );

	$new_json = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	if ( $new_json === false ) {
		return [ 'ok' => false, 'reason' => 'json_encode_fail' ];
	}
	update_post_meta( $post_id, '_elementor_data', wp_slash( $new_json ) );
	delete_post_meta( $post_id, '_elementor_css' );
	delete_post_meta( $post_id, '_elementor_element_cache' );

	return [ 'ok' => true, 'reason' => 'updated', 'changed' => true ];
}

function walk_form( array &$elements, string $target_id, bool &$found, bool &$skipped ): void {
	foreach ( $elements as &$el ) {
		if ( ! is_array( $el ) ) {
			continue;
		}
		if ( ( $el['widgetType'] ?? '' ) === 'form' && ( $el['id'] ?? '' ) === $target_id ) {
			$found = true;
			$s     = &$el['settings'];

			// Idempotência: detectar versão atual do template no subject
			if ( isset( $s['email_subject'] ) && strpos( $s['email_subject'], '[Newsletter Rodapé ' . TEMPLATE_VERSION . ']' ) === 0 ) {
				$skipped = true;
				return;
			}

			$s['email_subject']      = NEW_SUBJECT;
			$s['email_content']      = NEW_CONTENT;
			$s['email_content_type'] = 'html';
			// Metadata curada: Data, Horário, URL da página, User Agent, IP remoto.
			// Chave CORRETA é 'form_metadata' (e não 'email_include_metadata' que
			// nunca é lida pela action Email — bug induzido pela UI do Elementor
			// que mostra 'Meta Data' mas grava em form_metadata internamente).
			// SEM 'credit' (que renderiza "Powered by: Elementor").
			$s['form_metadata']      = METADATA_KEYS;
			// Limpar chave antiga (caso tenha sido setada por versão anterior do script)
			unset( $s['email_include_metadata'] );

			unset( $s );
			return;
		}
		if ( ! empty( $el['elements'] ) ) {
			walk_form( $el['elements'], $target_id, $found, $skipped );
			if ( $found ) {
				return;
			}
		}
	}
}

// ---------------- main ----------------

$blog_ids = is_multisite()
	? array_map( fn( $s ) => (int) $s->blog_id, get_sites() )
	: [ 1 ];

foreach ( $blog_ids as $bid ) {
	if ( is_multisite() ) {
		switch_to_blog( $bid );
	}

	$r = update_form( FOOTER_TEMPLATE_POST_ID, TARGET_FORM_ID );
	if ( $r['ok'] && ! empty( $r['changed'] ) ) {
		\WP_CLI::log( "[blog={$bid}] post=" . FOOTER_TEMPLATE_POST_ID . " form=" . TARGET_FORM_ID . " ATUALIZADO" );
	} elseif ( $r['ok'] ) {
		\WP_CLI::log( "[blog={$bid}] post=" . FOOTER_TEMPLATE_POST_ID . " form=" . TARGET_FORM_ID . " já no formato novo (skip)" );
	} else {
		\WP_CLI::log( "[blog={$bid}] post=" . FOOTER_TEMPLATE_POST_ID . " form=" . TARGET_FORM_ID . " — reason: " . $r['reason'] );
	}

	if ( is_multisite() ) {
		restore_current_blog();
	}
}

\WP_CLI::log( '' );
\WP_CLI::log( 'Próximos passos:' );
\WP_CLI::log( '  wp cache flush' );
\WP_CLI::log( '  wp elementor flush-css' );
