<?php
/**
 * Plugin Name:  BIT Cross-Blog Dev Notice
 * Description:  Alerta no dashboard do blog 2 (/cultura/) lembrando devs do
 *               comportamento cross-blog de imagens. DEV-ONLY: guard via
 *               wp_get_environment_type() impede execução em prod/hml.
 * Version:      1.0.0
 * Author:       Bureau IT
 * Network:      true
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_notices', function () {
	// DEV-ONLY: prod/hml não devem ver este notice
	if ( wp_get_environment_type() !== 'development' ) return;

	// Só no blog 2 (subsite que referencia mídia cross-blog)
	if ( get_current_blog_id() !== 2 ) return;

	// Só para quem edita conteúdo
	if ( ! current_user_can( 'edit_posts' ) ) return;

	// Só nas telas relevantes (dashboard + edição de posts/pages)
	$screen = get_current_screen();
	if ( ! $screen || ! in_array( $screen->base, [ 'dashboard', 'post', 'edit', 'upload' ], true ) ) {
		return;
	}

	?>
	<div class="notice notice-info" style="border-left-color:#2271b1">
		<p style="font-size:13px;margin:8px 0">
			<strong>Atenção — blog /cultura/:</strong>
			toda a mídia vive no <strong>blog 1</strong> (raiz) e é compartilhada via
			Network Media Library. Use sempre o seletor de mídia do Elementor —
			<strong>não cole <code>&lt;img src="..."&gt;</code> direto no HTML</strong>
			(o ID precisa estar registrado para os hooks pegarem).
			Se uma imagem aparecer quebrada, rode <code>/smoke</code> (gates 26 e 37)
			antes de abrir bug. Detalhes:
			<code>mu-plugins/bit-crossblog-attachment-fix.README.md</code>.
		</p>
	</div>
	<?php
} );
