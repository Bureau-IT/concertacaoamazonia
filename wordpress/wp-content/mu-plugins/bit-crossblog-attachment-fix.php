<?php
/**
 * Plugin Name:  BIT Cross-Blog Attachment Fix
 * Description:  Fixes cross-blog attachment resolution for blog 2 (/cultura/)
 *               accessing attachments stored in blog 1 (site principal).
 *               Covers: wp_get_attachment_url (lightbox, audio, video, download
 *               button), get_attached_file (download handler, SVG manager) e
 *               pré-população de cache para process_download e widget gallery.
 *               Complementa o Network Media Library, que já cobre
 *               wp_get_attachment_image_src().
 * Version:      1.2.1
 * Author:       Bureau IT
 * Network:      true
 *
 * NOTA: Plugin específico para multisite de 2 blogs (subdirectory mode) onde
 * o blog 1 (raiz) detém a mídia e o blog 2 (/cultura/) referencia esses IDs.
 * Todos os hooks verificam get_current_blog_id() !== 2 e retornam imediatamente
 * em instalações single-site ou com topologia diferente.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// HELPER: pré-popula object cache + postmeta do blog 2 com dados do blog 1
// para uma lista de attachment IDs. Uma única switch_to_blog() por chamada.
// Usado pelos hooks de widget antes de renderizar (hooks 3 e 4).
// ─────────────────────────────────────────────────────────────────────────────
function bit_crossblog_warm_cache( array $ids ) : void {
	static $warmed = [];

	$missing = array_filter( $ids, function ( $id ) use ( &$warmed ) {
		if ( isset( $warmed[ $id ] ) ) return false;
		$post = wp_cache_get( $id, 'posts' );
		return ! $post || $post->post_type !== 'attachment';
	} );

	if ( empty( $missing ) ) return;

	switch_to_blog( 1 );
	update_meta_cache( 'post', $missing );
	$b1_posts = [];
	$b1_metas = [];
	foreach ( $missing as $id ) {
		$post = get_post( $id );
		if ( $post && $post->post_type === 'attachment' ) {
			$b1_posts[ $id ] = $post;
			$b1_metas[ $id ] = wp_cache_get( $id, 'post_meta' );
		}
		$warmed[ $id ] = true;
	}
	restore_current_blog();

	// wp_cache_set após restore — blog_prefix=2, escreve no namespace correto
	foreach ( $b1_posts as $id => $post ) {
		wp_cache_set( $id, $post, 'posts' );
		// Usa !== false para distinguir "meta vazia" (array) de "cache miss" (false).
		// Sem isso, meta vazia não seria gravada e causaria query no namespace errado.
		if ( $b1_metas[ $id ] !== false ) {
			wp_cache_set( $id, $b1_metas[ $id ] ?: [], 'post_meta' );
		}
	}
}

// ─────────────────────────────────────────────────────────────────────────────
// 1. Fix wp_get_attachment_url() para attachments cross-blog (blog 2 → blog 1).
//    Cobre: lightbox do Elementor (add_lightbox_data_attributes), JetElements
//    audio, video e download button (exibição do nome do arquivo).
//
//    wp_get_attachment_url() só aplica este filtro quando get_post() retorna o
//    objeto. Quando o cache do blog 2 é pré-populado (hooks 3/4) com dados do
//    blog 1, a função constrói uma URL com o upload dir do blog 2 (/sites/2/).
//    Este filtro recebe essa URL incorreta e a substitui pela correta do blog 1.
//    Também cobre IDs que chegam sem cache (chama switch_to_blog diretamente).
//    Cache estático $resolved/$failed evita switch_to_blog() repetido por ID.
// ─────────────────────────────────────────────────────────────────────────────
add_filter( 'wp_get_attachment_url', function ( $url, $post_id ) {
	static $switched = false;
	static $resolved = [];
	static $failed   = [];

	if ( get_current_blog_id() !== 2 ) return $url;
	if ( isset( $failed[ $post_id ] ) ) return $url;
	if ( isset( $resolved[ $post_id ] ) ) return $resolved[ $post_id ];
	if ( $switched ) return $url;

	// Se a URL já está correta (não vem do upload dir do blog 2), retorna
	$b2_upload = '/wp-content/uploads/sites/2/';
	if ( $url && strpos( $url, $b2_upload ) === false ) return $url;

	$switched = true;
	$b1_url   = false;
	try {
		switch_to_blog( 1 );
		$b1_url = wp_get_attachment_url( $post_id );
	} catch ( \Throwable $e ) {
		$failed[ $post_id ] = true;
	} finally {
		restore_current_blog();
		$switched = false;
	}

	if ( $b1_url ) {
		$resolved[ $post_id ] = $b1_url;
		return $b1_url;
	}

	$failed[ $post_id ] = true;
	return $url;
}, 999, 2 );

// ─────────────────────────────────────────────────────────────────────────────
// 2. Fix get_attached_file() para attachments cross-blog (blog 2 → blog 1).
//    Cobre: JetElements download handler (get_file_size + process_download) e
//    SVG manager (cálculo de dimensions).
//
//    Quando o cache é pré-populado com dados do blog 1, get_attached_file()
//    constrói o path com o upload dir do blog 2 (/uploads/sites/2/). Este filtro
//    intercepta e substitui pelo path correto do blog 1.
//    Cache estático $resolved/$failed evita switch_to_blog() repetido por ID.
// ─────────────────────────────────────────────────────────────────────────────
add_filter( 'get_attached_file', function ( $file, $attachment_id ) {
	static $switched = false;
	static $resolved = [];
	static $failed   = [];

	if ( get_current_blog_id() !== 2 ) return $file;
	if ( isset( $failed[ $attachment_id ] ) ) return $file;
	if ( isset( $resolved[ $attachment_id ] ) ) return $resolved[ $attachment_id ];
	if ( $switched ) return $file;

	// Se o path já está correto (não usa o diretório do blog 2), retorna
	$b2_upload_path = '/uploads/sites/2/';
	if ( $file && strpos( $file, $b2_upload_path ) === false ) return $file;

	$switched = true;
	$b1_file  = false;
	try {
		switch_to_blog( 1 );
		$b1_file = get_attached_file( $attachment_id );
	} catch ( \Throwable $e ) {
		$failed[ $attachment_id ] = true;
	} finally {
		restore_current_blog();
		$switched = false;
	}

	if ( $b1_file ) {
		$resolved[ $attachment_id ] = $b1_file;
		return $b1_file;
	}

	$failed[ $attachment_id ] = true;
	return $file;
}, 999, 2 );

// ─────────────────────────────────────────────────────────────────────────────
// 3. Pré-popula object cache do blog 2 com post + meta do blog 1 antes que
//    JetElements process_download() (init priority 99) chame get_post($id).
//    Sem isso, get_post() retorna null → Fatal Error PHP 8.
//    Hash estrutura: [ 'sha1hash' => post_id ] (gravada no blog 2 pelo widget).
//    update_meta_cache() chamado somente se o post existir no blog 1.
//    wp_cache_set() após restore_current_blog() — escreve no namespace do blog 2.
// ─────────────────────────────────────────────────────────────────────────────
add_action( 'init', function () {
	if ( get_current_blog_id() !== 2 ) return;
	if ( empty( $_GET['jet_download'] ) ) return;

	// Resolve hash → ID no blog 2 (onde a option foi gravada pelo widget)
	$hashes = get_option( 'jet_elements_download_button_hashes', [] );
	$hash   = sanitize_text_field( wp_unslash( $_GET['jet_download'] ) );
	$id     = isset( $hashes[ $hash ] ) ? (int) $hashes[ $hash ] : 0;

	// Fallback: tenta ler do blog 1 (edge case: hash gerado no admin do blog 1).
	// Executa sempre que o hash não foi encontrado no blog 2 — o blog 2 pode ter
	// outros hashes válidos e ainda assim um hash específico ter sido gravado no
	// admin do blog 1. A condição empty($hashes) era falso negativo nesse caso.
	if ( ! $id ) {
		switch_to_blog( 1 );
		$hashes_b1 = get_option( 'jet_elements_download_button_hashes', [] );
		restore_current_blog();
		$id = isset( $hashes_b1[ $hash ] ) ? (int) $hashes_b1[ $hash ] : 0;
	}

	if ( ! $id ) return;

	bit_crossblog_warm_cache( [ $id ] );
}, 5 );

// ─────────────────────────────────────────────────────────────────────────────
// 4. Antes do render de widgets Elementor/JetElements, pré-popula o object cache
//    (namespace blog 2) com posts + meta de attachments do blog 1.
//    Garante que get_post($id) retorne correto → wp_get_attachment_url() funciona.
//
//    Widgets cobertos:
//    - gallery (Elementor): IDs em settings.galleries[].multiple_gallery[].id
//    - jet-audio: ID em settings.self_url.id  (source=self_hosted)
//    - jet-video: ID em settings.self_hosted_url.id  (video_type=self_hosted)
//    - jet-download-button: ID em settings.file_attachment.id
// ─────────────────────────────────────────────────────────────────────────────
add_action( 'elementor/widget/before_render_content', function ( $widget ) {
	if ( get_current_blog_id() !== 2 ) return;

	$name     = $widget->get_name();
	$settings = $widget->get_settings_for_display();
	$ids      = [];

	switch ( $name ) {
		case 'gallery':
			// Elementor Pro Gallery widget (grupos) — chave 'galleries[].multiple_gallery'.
			// O widget básico do Elementor free usa 'wp_gallery' (estrutura plana) e não
			// é coberto aqui; o hook 1 ainda corrige URLs de forma reativa nesses casos.
			foreach ( $settings['galleries'] ?? [] as $gallery ) {
				foreach ( $gallery['multiple_gallery'] ?? [] as $item ) {
					$id = absint( $item['id'] ?? 0 );
					if ( $id ) $ids[] = $id;
				}
			}
			break;

		case 'jet-audio':
			// Controle tipo MEDIA para source=self_hosted chama-se 'self_url'
			$id = absint( $settings['self_url']['id'] ?? 0 );
			if ( $id ) $ids[] = $id;
			break;

		case 'jet-video':
			// Controle tipo MEDIA para video_type=self_hosted chama-se 'self_hosted_url'
			$id = absint( $settings['self_hosted_url']['id'] ?? 0 );
			if ( $id ) $ids[] = $id;
			break;

		case 'jet-download-button':
			$id = absint( $settings['download_file'] ?? 0 );
			if ( $id ) $ids[] = $id;
			break;
	}

	if ( empty( $ids ) ) return;

	bit_crossblog_warm_cache( $ids );
}, 5, 1 );
