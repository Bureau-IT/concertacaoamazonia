<?php
/**
 * Plugin Name: BIT Yoast Article para CPTs
 * Description: Faz o Yoast emitir schema Article nos singles dos CPTs editoriais (estudos, releases, 100dias, webinarios, plenarias). Yoast 27+ só trata como artigo post type com suporte a "author" (Article_Helper::is_article_post_type -> post_type_supports('author')); a opção schema-article-type-<cpt>=Article sozinha não basta.
 * Version: 1.0.0
 * Author: Daniel Cambría
 */
if ( ! defined( 'ABSPATH' ) ) {
	return;
}
add_action( 'init', function () {
	foreach ( [ 'estudos', 'releases', '100dias', 'webinarios', 'plenarias' ] as $pt ) {
		if ( post_type_exists( $pt ) && ! post_type_supports( $pt, 'author' ) ) {
			add_post_type_support( $pt, 'author' );
		}
	}
}, 20 ); // depois do JetEngine registrar os CPTs (init 10). Gate 31g do /smoke, 10/09/2026.
