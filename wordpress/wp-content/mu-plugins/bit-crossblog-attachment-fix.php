<?php
/**
 * Plugin Name:  BIT Cross-Blog Attachment Fix
 * Description:  Fixes cross-blog attachment resolution for blog 2 (/cultura/)
 *               accessing attachments stored in blog 1 (site principal).
 *               Covers: wp_get_attachment_url (lightbox, audio, video, download
 *               button), get_attached_file (download handler, SVG manager),
 *               pré-população de cache para process_download e widget gallery,
 *               featured image cross-blog (REST save, Admin Columns Pro
 *               inline edit + render Elementor dynamic tag post-featured-image),
 *               WPML duplicate-on-translate orphan attachments (Elementor CSS
 *               background-image, slides, gallery, jet-carousel em páginas EN).
 *               Complementa o Network Media Library, que cobre IDs existentes
 *               em wp_posts blog 1 mas NÃO cobre attachments duplicados pelo
 *               WPML que existem apenas em wp_2_posts.
 * Version:      1.6.0
 * Author:       Bureau IT
 * Network:      true
 *
 * v1.6.0 (cobertura NML cross-blog default):
 *   - Hook 14 (wp_calculate_image_srcset) — cobre attachments NML cross-blog
 *     que NÃO passam pelo Hook 13 (limitado a órfãos WPML). Caso típico:
 *     dev faz upload de imagem via wp-admin do blog 2 → NML armazena no
 *     blog 1 (wp_posts) → página do blog 2 referencia via Elementor →
 *     srcset reconstruído com /sites/2/uploads/... que retorna 403 do S3.
 *     Heurística zero-SQL: $image_src JÁ está em /uploads/ (sem /sites/N/)
 *     porque NML/Hook 9 corrigiu, E pelo menos uma URL do srcset contém
 *     /sites/N/. Reescreve só essas URLs. Safe: attachment legitimamente do
 *     subsite tem $image_src com /sites/N/ e o hook não age.
 *
 * v1.5.2 (cobertura srcset completa):
 *   - Hook 13 (wp_calculate_image_srcset) — corrige URLs do srcset que o
 *     WP core reconstrói via wp_get_upload_dir() do contexto blog atual,
 *     ignorando o $image_src já corrigido pelo Hook 9. Fecha último gap
 *     de URLs /sites/<N>/* no <img srcset>.
 *
 * v1.5.1 (auditoria pós-implementação):
 *   - try/finally em Hooks 9/10/11/12 (proteção contra $reentry travado)
 *   - Hook 12 (wp_get_attachment_metadata) — fecha gap srcset/og:image/REST
 *   - Cache $failed no Hook 11 (evita stat() repetido)
 *   - Parametrização via constantes BIT_CROSSBLOG_* (portabilidade)
 *   - error_log() em falha SQL (sem degradação silenciosa)
 *   - Validação de $wpdb->prefix antes de concat (defesa em profundidade)
 *   - Removido sanity check switch_to_blog(SOURCE) — 50% menos queries/trid
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
//                           e settings.gallery[].id (single gallery type)
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
			foreach ( $settings['galleries'] ?? [] as $gallery ) {
				foreach ( $gallery['multiple_gallery'] ?? [] as $item ) {
					$id = absint( $item['id'] ?? 0 );
					if ( $id ) $ids[] = $id;
				}
			}
			// Single gallery type — chave 'gallery[]'.
			foreach ( $settings['gallery'] ?? [] as $item ) {
				$id = absint( $item['id'] ?? 0 );
				if ( $id ) $ids[] = $id;
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

// ─────────────────────────────────────────────────────────────────────────────
// 5. REST API — pré-popula cache do attachment cross-blog ANTES do
//    set_post_thumbnail() validar via get_post($thumbnail_id).
//
//    Bug: set_post_thumbnail() (wp-includes/post.php) faz
//    `if ( $post && $thumbnail_id && get_post( $thumbnail_id ) )` — quando o
//    attachment só existe em wp_posts (blog 1) e o save vem do blog 2 via
//    REST `/wp/v2/{post_type}` com `featured_media: ID`, `get_post(ID)` retorna
//    NULL → handle_featured_media() retorna `rest_invalid_featured_media` e
//    o _thumbnail_id NÃO é gravado.
//
//    Fix: ao detectar request REST de POST/PUT em blog 2 com `featured_media`,
//    pré-popula o object cache do blog 2 com o post-object do attachment do
//    blog 1. set_post_thumbnail() encontra o attachment e grava normalmente.
//
//    NOTA: o ID gravado em wp_2_postmeta pode colidir com posts não-attachment
//    no blog 2 (ex: ID 91671 = attachment blog 1 + post `artistas` blog 2).
//    Por isso o hook 6 (abaixo) também pré-popula o cache na leitura.
// ─────────────────────────────────────────────────────────────────────────────
add_filter( 'rest_pre_dispatch', function ( $result, $server, $request ) {
	if ( get_current_blog_id() !== 2 ) return $result;

	$method = $request->get_method();
	if ( ! in_array( $method, [ 'POST', 'PUT', 'PATCH' ], true ) ) return $result;

	$featured = (int) $request->get_param( 'featured_media' );
	if ( ! $featured ) return $result;

	bit_crossblog_warm_cache( [ $featured ] );

	return $result;
}, 5, 3 );

// ─────────────────────────────────────────────────────────────────────────────
// 7. Safety net: filtra `update_post_metadata` para `_thumbnail_id` em blog 2.
//
//    Bug: Admin Columns Pro (inline edit em wp-admin/edit.php) chama
//    set_post_thumbnail() via wp_ajax_acp_editing_request — não passa pelo
//    rest_pre_dispatch (Hook 5). Mesmo problema atinge qualquer outro plugin
//    que use set_post_thumbnail() fora do REST.
//
//    Fix: ao tentar gravar `_thumbnail_id` em blog 2, pré-popula cache do ID
//    para que se houver chamada subsequente a `get_post($thumbnail_id)`
//    (validação interna, hooks, listings), retorne o attachment do blog 1.
//
//    NOTA: este hook não força a gravação — ele apenas garante que o cache
//    esteja correto. A gravação em si é controlada pelo plugin chamador.
//    Mesmo se set_post_thumbnail() já tiver retornado false antes de chegar
//    aqui, este hook serve para caminhos que gravam direto via update_post_meta.
// ─────────────────────────────────────────────────────────────────────────────
add_filter( 'update_post_metadata', function ( $check, $object_id, $meta_key, $meta_value ) {
	if ( $meta_key !== '_thumbnail_id' ) return $check;
	if ( get_current_blog_id() !== 2 ) return $check;

	$thumbnail_id = (int) $meta_value;
	if ( $thumbnail_id ) {
		bit_crossblog_warm_cache( [ $thumbnail_id ] );
	}

	return $check;
}, 5, 4 );

// ─────────────────────────────────────────────────────────────────────────────
// 8. Pre-warm cache no AJAX do Admin Columns Pro ANTES do set_post_thumbnail()
//    validar via get_post($thumbnail_id).
//
//    O endpoint `wp_ajax_acp_editing_request` recebe `value` (ID do attachment
//    em edição featured-image). Hook 7 (update_post_metadata) cobre a fase
//    de gravação, mas set_post_thumbnail() valida ANTES de chamar
//    update_post_meta — sem este hook, retorna false e nada é gravado.
// ─────────────────────────────────────────────────────────────────────────────
add_action( 'admin_init', function () {
	if ( get_current_blog_id() !== 2 ) return;
	if ( ! wp_doing_ajax() ) return;

	$action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';
	if ( $action !== 'acp_editing_request' ) return;

	$value = $_POST['value'] ?? null;
	if ( ! $value ) return;

	$ids = [];
	if ( is_array( $value ) ) {
		array_walk_recursive( $value, function ( $v ) use ( &$ids ) {
			if ( is_numeric( $v ) ) $ids[] = (int) $v;
		} );
	} elseif ( is_numeric( $value ) ) {
		$ids[] = (int) $value;
	}

	$ids = array_unique( array_filter( $ids ) );
	if ( empty( $ids ) ) return;

	bit_crossblog_warm_cache( $ids );
}, 1 );

// ─────────────────────────────────────────────────────────────────────────────
// 6. Pré-popula cache do attachment cross-blog na leitura do `_thumbnail_id`.
//
//    Bug: Elementor dynamic tag `post-featured-image` (e qualquer código que
//    chame get_post_thumbnail_id() + wp_get_attachment_image_src()) tenta
//    resolver o ID em get_post() no contexto blog 2. Se o ID colide com outro
//    post type (ex: 91671 = attachment blog 1 + artistas blog 2), get_post()
//    retorna o post errado e o thumbnail não renderiza.
//
//    Fix: ao ler `_thumbnail_id` em wp_2_postmeta, dispara warm_cache para
//    sobrescrever o objeto cacheado pelo attachment correto do blog 1.
//    O warm_cache valida que o ID é attachment no blog 1 antes de cachear —
//    se não for, não altera nada (preserva o post do blog 2).
//
//    Performance: warm_cache é idempotente (cache estático $warmed por ID)
//    e usa update_meta_cache batch.
// ─────────────────────────────────────────────────────────────────────────────
add_filter( 'get_post_metadata', function ( $value, $object_id, $meta_key ) {
	if ( $meta_key !== '_thumbnail_id' ) return $value;
	if ( get_current_blog_id() !== 2 ) return $value;
	if ( ! $object_id ) return $value;

	// Cache estático: warm uma única vez por object_id por request.
	static $seen = [];
	if ( isset( $seen[ $object_id ] ) ) return $value;
	$seen[ $object_id ] = true;

	// Lê o meta cache populado para este post (sem disparar filtro recursivo:
	// `wp_cache_get` é direto, não passa pelo filtro `get_post_metadata`).
	$meta_cache = wp_cache_get( $object_id, 'post_meta' );
	if ( false === $meta_cache ) {
		update_meta_cache( 'post', [ $object_id ] );
		$meta_cache = wp_cache_get( $object_id, 'post_meta' );
	}

	$thumbnail_id = isset( $meta_cache['_thumbnail_id'][0] )
		? (int) $meta_cache['_thumbnail_id'][0]
		: 0;

	if ( $thumbnail_id ) {
		bit_crossblog_warm_cache( [ $thumbnail_id ] );
	}

	return $value;
}, 5, 3 );

// ─────────────────────────────────────────────────────────────────────────────
// CONFIGURAÇÃO PARAMETRIZÁVEL via constantes em wp-config.php
//
// Defaults assumem topologia atual da Concertação: blog 1 = principal (mídia),
// blog 2 = /cultura/ subsite com WPML duplicate-on-translate, idioma fonte pt-br.
//
// Para reusar este mu-plugin em outros sites BIT com topologia diferente,
// definir antes de wp-settings.php carregar:
//   define( 'BIT_CROSSBLOG_TARGET_BLOG', 2 );   // subsite que tem órfãos
//   define( 'BIT_CROSSBLOG_SOURCE_BLOG', 1 );   // blog onde mídia mora
//   define( 'BIT_CROSSBLOG_SOURCE_LANG', 'pt-br' ); // language_code do sibling
// ─────────────────────────────────────────────────────────────────────────────
defined( 'BIT_CROSSBLOG_TARGET_BLOG' ) || define( 'BIT_CROSSBLOG_TARGET_BLOG', 2 );
defined( 'BIT_CROSSBLOG_SOURCE_BLOG' ) || define( 'BIT_CROSSBLOG_SOURCE_BLOG', 1 );
defined( 'BIT_CROSSBLOG_SOURCE_LANG' ) || define( 'BIT_CROSSBLOG_SOURCE_LANG', 'pt-br' );

// ─────────────────────────────────────────────────────────────────────────────
// HELPER: resolve WPML sibling do idioma fonte para attachment "órfão" no
//         subsite alvo (existe em wp_<N>_posts mas NÃO em wp_posts do site
//         fonte). Retorna o ID do sibling (que existe no site fonte e tem
//         arquivo válido), ou 0 se sem sibling.
//
//         WPML duplicate-on-translate cria attachments EN duplicados ao
//         traduzir uma página. Esses IDs vivem em wp_<N>_posts com trid em
//         wp_<N>_icl_translations apontando para o sibling pt-br. O arquivo
//         físico NÃO é copiado para /uploads/sites/<N>/ — fica só no fonte.
//
//         Network Media Library faz switch_to_blog(SOURCE) + get_post($id),
//         mas para órfãos o ID não existe em wp_posts → NML retorna false.
//
//         Este helper preenche o gap: 1 query por trid (com cache estático)
//         e devolve o ID fonte que NML consegue resolver normalmente.
//
//         Observabilidade: emite error_log() em caso de erro SQL para
//         permitir diagnóstico em prod sem degradação silenciosa.
// ─────────────────────────────────────────────────────────────────────────────
function bit_crossblog_wpml_sibling_id( int $orphan_id ) : int {
	static $resolved = [];

	if ( $orphan_id <= 0 ) return 0;
	if ( array_key_exists( $orphan_id, $resolved ) ) return $resolved[ $orphan_id ];

	global $wpdb;

	// $wpdb->prefix em contexto subsite alvo retorna 'wp_<N>_' (ex: 'wp_2_').
	// Validar para evitar SQL injection se prefix for corrompido por config externa.
	if ( ! preg_match( '/^[a-z0-9_]+$/i', $wpdb->prefix ) ) {
		error_log( '[bit-crossblog] invalid $wpdb->prefix: ' . $wpdb->prefix );
		return $resolved[ $orphan_id ] = 0;
	}
	$translations_table = $wpdb->prefix . 'icl_translations';

	$source_lang = BIT_CROSSBLOG_SOURCE_LANG;

	// 1 query única: trid do órfão + sibling do idioma fonte no mesmo round-trip.
	// element_type='post_attachment' é estável no WPML desde v3.x (2013).
	// Sem sanity check de get_post() — confia na integridade do WPML; reduz 1 query/trid.
	$pt_id = $wpdb->get_var( $wpdb->prepare(
		"SELECT t2.element_id
		 FROM {$translations_table} t1
		 INNER JOIN {$translations_table} t2
		   ON t2.trid = t1.trid
		  AND t2.element_id != t1.element_id
		  AND t2.language_code = %s
		 WHERE t1.element_id = %d
		   AND t1.element_type = 'post_attachment'
		 LIMIT 1",
		$source_lang,
		$orphan_id
	) );

	if ( $wpdb->last_error ) {
		error_log( '[bit-crossblog] SQL error resolving orphan ' . $orphan_id . ': ' . $wpdb->last_error );
		return $resolved[ $orphan_id ] = 0;
	}

	$resolved[ $orphan_id ] = (int) $pt_id;
	return $resolved[ $orphan_id ];
}

// ─────────────────────────────────────────────────────────────────────────────
// 9. Fallback WPML para wp_get_attachment_image_src() no subsite alvo.
//
//    NML (priority 999) faz switch_to_blog(SOURCE) + chama recursivo. Se o ID
//    é órfão WPML (não existe em wp_posts do source), NML retorna false.
//    Sintoma direto: widget Slides do Elementor em página EN sem
//    background-image, gallery sem thumbnails, featured image quebrada.
//
//    Fix: ao receber false do NML, consultar WPML trid e tentar com o sibling
//    do idioma fonte (que existe no SOURCE e tem arquivo válido). Priority
//    1000 garante execução APÓS o NML. try/finally protege $reentry contra
//    exceção em chamada recursiva (sem isso, flag travaria o worker FPM).
// ─────────────────────────────────────────────────────────────────────────────
add_filter( 'wp_get_attachment_image_src', function ( $image, $attachment_id, $size, $icon ) {
	static $reentry = false;

	if ( $reentry ) return $image;
	if ( get_current_blog_id() !== BIT_CROSSBLOG_TARGET_BLOG ) return $image;
	if ( $image !== false ) return $image; // NML resolveu — nada a fazer
	if ( ! $attachment_id ) return $image;

	$pt_id = bit_crossblog_wpml_sibling_id( (int) $attachment_id );
	if ( ! $pt_id ) return $image;

	$reentry = true;
	try {
		$result = wp_get_attachment_image_src( $pt_id, $size, $icon );
	} finally {
		$reentry = false;
	}

	return $result !== false ? $result : $image;
}, 1000, 4 );

// ─────────────────────────────────────────────────────────────────────────────
// 10. Fallback WPML para wp_get_attachment_url() no subsite alvo.
//
//     Hook 1 (acima) faz switch_to_blog(SOURCE) + chama recursivo. Para
//     órfãos WPML, switch_to_blog(SOURCE) retorna URL inválida ou false. Este
//     hook entra com priority MAIOR (1001) e captura o resultado do hook 1 —
//     se ainda for a URL quebrada do subsite (/sites/<N>/...), tenta sibling.
// ─────────────────────────────────────────────────────────────────────────────
add_filter( 'wp_get_attachment_url', function ( $url, $post_id ) {
	static $reentry = false;

	if ( $reentry ) return $url;
	if ( get_current_blog_id() !== BIT_CROSSBLOG_TARGET_BLOG ) return $url;
	if ( ! $post_id ) return $url;

	// URL ainda aponta para upload dir do subsite alvo? Hook 1 não resolveu.
	$target_upload = '/wp-content/uploads/sites/' . BIT_CROSSBLOG_TARGET_BLOG . '/';
	if ( ! $url || strpos( $url, $target_upload ) === false ) return $url;

	$pt_id = bit_crossblog_wpml_sibling_id( (int) $post_id );
	if ( ! $pt_id ) return $url;

	$reentry = true;
	try {
		$pt_url = wp_get_attachment_url( $pt_id );
	} finally {
		$reentry = false;
	}

	return $pt_url ?: $url;
}, 1001, 2 );

// ─────────────────────────────────────────────────────────────────────────────
// 11. Fallback WPML para get_attached_file() no subsite alvo.
//
//     Mesmo padrão do hook 10, mas para path filesystem. Cobre code paths
//     que leem arquivo direto (download handler, SVG manager, image resize).
//     Cache $failed evita stat() repetido para IDs sem sibling resolvável.
// ─────────────────────────────────────────────────────────────────────────────
add_filter( 'get_attached_file', function ( $file, $attachment_id ) {
	static $reentry = false;
	static $failed  = [];

	if ( $reentry ) return $file;
	if ( get_current_blog_id() !== BIT_CROSSBLOG_TARGET_BLOG ) return $file;
	if ( ! $attachment_id ) return $file;
	if ( isset( $failed[ $attachment_id ] ) ) return $file;

	$target_path = '/uploads/sites/' . BIT_CROSSBLOG_TARGET_BLOG . '/';
	if ( ! $file || strpos( $file, $target_path ) === false ) return $file;

	// Path ainda aponta para sites/<N> e o arquivo NÃO existe — Hook 2 não resolveu.
	if ( file_exists( $file ) ) return $file;

	$pt_id = bit_crossblog_wpml_sibling_id( (int) $attachment_id );
	if ( ! $pt_id ) {
		$failed[ $attachment_id ] = true;
		return $file;
	}

	$reentry = true;
	try {
		$pt_file = get_attached_file( $pt_id );
	} finally {
		$reentry = false;
	}

	if ( ! $pt_file ) {
		$failed[ $attachment_id ] = true;
		return $file;
	}

	return $pt_file;
}, 1001, 2 );

// ─────────────────────────────────────────────────────────────────────────────
// 12. Fallback WPML para wp_get_attachment_metadata() no subsite alvo.
//
//     Sem este hook, srcset e og:image:width/height vão errados para órfãos:
//     core chama wp_get_attachment_metadata($orphan_id) → lê wp_<N>_postmeta
//     (que tem _wp_attachment_metadata duplicado pelo WPML mas com paths
//     apontando para /sites/<N>/* que NÃO existem fisicamente). Resultado:
//     wp_calculate_image_srcset gera URLs 404, REST API embed traz sizes
//     quebradas, Yoast/RankMath escrevem width/height baseado em meta órfã.
//
//     Fix: detectar órfão (file existe em wp_<N>_postmeta mas é path local),
//     resolver sibling e devolver metadata do sibling source. Cache $resolved
//     per-request análogo aos outros hooks.
// ─────────────────────────────────────────────────────────────────────────────
add_filter( 'wp_get_attachment_metadata', function ( $data, $attachment_id ) {
	static $reentry  = false;
	static $resolved = [];

	if ( $reentry ) return $data;
	if ( get_current_blog_id() !== BIT_CROSSBLOG_TARGET_BLOG ) return $data;
	if ( ! $attachment_id ) return $data;
	if ( array_key_exists( $attachment_id, $resolved ) ) {
		return $resolved[ $attachment_id ] !== null ? $resolved[ $attachment_id ] : $data;
	}

	// Heurística para detectar órfão: data tem 'file' mas o arquivo físico
	// não existe no upload dir do subsite. Mais barato que query SQL para
	// IDs legítimos (caso comum).
	$is_orphan = false;
	if ( is_array( $data ) && ! empty( $data['file'] ) ) {
		$upload    = wp_get_upload_dir();
		$full_path = trailingslashit( $upload['basedir'] ) . $data['file'];
		if ( ! file_exists( $full_path ) ) {
			$is_orphan = true;
		}
	}

	if ( ! $is_orphan ) {
		$resolved[ $attachment_id ] = null;
		return $data;
	}

	$pt_id = bit_crossblog_wpml_sibling_id( (int) $attachment_id );
	if ( ! $pt_id ) {
		$resolved[ $attachment_id ] = null;
		return $data;
	}

	$reentry = true;
	try {
		$pt_data = wp_get_attachment_metadata( $pt_id );
	} finally {
		$reentry = false;
	}

	if ( ! is_array( $pt_data ) ) {
		$resolved[ $attachment_id ] = null;
		return $data;
	}

	$resolved[ $attachment_id ] = $pt_data;
	return $pt_data;
}, 1000, 2 );

// ─────────────────────────────────────────────────────────────────────────────
// 13. Fallback WPML para wp_calculate_image_srcset() no subsite alvo.
//
//     Mesmo com Hook 12 corrigindo metadata, o wp_calculate_image_srcset()
//     reconstrói cada $sources[N]['url'] como
//     `trailingslashit(wp_get_upload_dir()['baseurl']) . $dirname . $file_size`
//     — usa o upload dir do contexto atual (blog 2), NÃO o $image_src do
//     filtro anterior (que já vem corrigido pelo Hook 9). Resultado: srcset
//     com URLs apontando para /sites/<N>/* que não existem fisicamente.
//
//     Fix: para órfãos WPML (detectados via attachment_id que tem sibling),
//     reescrever URLs do upload dir do subsite alvo para o do source.
//     Ex: https://site.com/cultura/wp-content/uploads/sites/2/2024/09/foo.jpg
//      -> https://site.com/wp-content/uploads/2024/09/foo.jpg
// ─────────────────────────────────────────────────────────────────────────────
add_filter( 'wp_calculate_image_srcset', function ( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
	if ( get_current_blog_id() !== BIT_CROSSBLOG_TARGET_BLOG ) return $sources;
	if ( ! is_array( $sources ) || empty( $sources ) ) return $sources;
	if ( ! $attachment_id ) return $sources;

	// Só age se este attachment_id é órfão WPML (tem sibling resolvível).
	$pt_id = bit_crossblog_wpml_sibling_id( (int) $attachment_id );
	if ( ! $pt_id ) return $sources;

	$target_fragment = '/wp-content/uploads/sites/' . BIT_CROSSBLOG_TARGET_BLOG . '/';
	$source_fragment = '/wp-content/uploads/';

	foreach ( $sources as $width => $src ) {
		if ( isset( $src['url'] ) && strpos( $src['url'], $target_fragment ) !== false ) {
			$sources[ $width ]['url'] = str_replace( $target_fragment, $source_fragment, $src['url'] );
		}
	}

	return $sources;
}, 1000, 5 );

// ─────────────────────────────────────────────────────────────────────────────
// 14. Fix srcset para attachments NML cross-blog comuns (caso default).
//
//     Hook 13 cobre só órfãos WPML (sibling resolvível em wp_<N>_icl_translations).
//     Este hook cobre o caso default do Network Media Library: attachment vive
//     em wp_posts (blog 1) e é referenciado por páginas do blog 2 SEM duplicação
//     WPML. Sintoma: <img srcset> com URLs /sites/<N>/uploads/... que retornam
//     403 do S3 (arquivos só existem em /uploads/<YYYY>/<MM>/, não no path do
//     subsite). Bug detectado 2026-05-21 em /cultura/ com attachment 92371
//     ("Onde possamos sonhar, 2026") — existe só em wp_posts, sem entrada
//     em wp_2_posts nem em wp_2_icl_translations.
//
//     Heurística zero-SQL de detecção (perf: ~15-25 imgs/page em prod):
//       1) $image_src JÁ está em /uploads/ (sem /sites/<N>/) — sinal de que
//          NML (priority 999 em wp_get_attachment_image_src) ou Hook 9 já
//          resolveram a URL principal. Distingue cross-blog (corrigido por
//          upstream) de attachment legitimamente do subsite (que mantém
//          /sites/<N>/ no $image_src).
//       2) Pelo menos uma URL do srcset contém /sites/<N>/uploads/. Se nenhuma
//          tem, srcset já está OK (idempotência — Hook 13 pode ter rodado antes).
//
//     Reescrita: troca `/wp-content/uploads/sites/<N>/` por `/wp-content/uploads/`
//     em cada URL do srcset que tem o padrão errado. Idempotente.
//
//     Safe fallback (NML OFF ou attachment legítimo subsite):
//       Sem NML, attachment do blog <N> vive em wp_<N>_posts com arquivo em
//       /uploads/sites/<N>/. $image_src TAMBÉM contém /sites/<N>/ → condição
//       (1) falha → hook não age → srcset preservado intacto.
//
//     Trade-offs:
//       - Não valida HTTP existence do arquivo no path reescrito (HEAD caro).
//         Confia que NML mantém /uploads/ como source-of-truth.
//       - Não usa $attachment_id para distinguir órfão de legítimo. Confia
//         exclusivamente no padrão das URLs — mais resiliente a mudanças
//         internas do NML/WPML.
//       - Priority 1100 (depois do Hook 13 = 1000) garante que se Hook 13
//         já reescreveu, condição (2) falha = no-op.
//
//     Validação dev:
//       curl -s "https://site/cultura/pagina/" | grep -oE 'srcset="[^"]{0,200}"'
//       — esperado: URLs SEM /sites/<N>/, todas em /wp-content/uploads/<YYYY>/<MM>/
// ─────────────────────────────────────────────────────────────────────────────
add_filter( 'wp_calculate_image_srcset', function ( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
	if ( get_current_blog_id() !== BIT_CROSSBLOG_TARGET_BLOG ) return $sources;
	if ( ! is_array( $sources ) || empty( $sources ) ) return $sources;

	$target_fragment = '/wp-content/uploads/sites/' . BIT_CROSSBLOG_TARGET_BLOG . '/';
	$source_fragment = '/wp-content/uploads/';

	// Condição (1): $image_src JÁ está em /uploads/ (sem /sites/N/) — sinal
	// claro de que o attachment é cross-blog e upstream (NML/Hook 9) já
	// resolveu. Se $image_src contém /sites/N/, é attachment legítimo do
	// subsite — NÃO tocar (preservar srcset original).
	if ( ! is_string( $image_src ) || strpos( $image_src, $target_fragment ) !== false ) {
		return $sources;
	}

	// Condição (2): pelo menos uma URL do srcset tem o fragment errado.
	// Idempotência: se Hook 13 ou run anterior já reescreveram, no-op.
	$needs_fix = false;
	foreach ( $sources as $src ) {
		if ( isset( $src['url'] ) && strpos( $src['url'], $target_fragment ) !== false ) {
			$needs_fix = true;
			break;
		}
	}
	if ( ! $needs_fix ) return $sources;

	foreach ( $sources as $width => $src ) {
		if ( isset( $src['url'] ) && strpos( $src['url'], $target_fragment ) !== false ) {
			$sources[ $width ]['url'] = str_replace( $target_fragment, $source_fragment, $src['url'] );
		}
	}

	return $sources;
}, 1100, 5 );
