<?php
/**
 * Plugin Name: BIT — JetEngine Load More AJAX URL Fix
 * Description: Força o endpoint AJAX do JetEngine Listing Grid (load-more, lazy-load) a usar /wp-admin/admin-ajax.php em vez da URL da página atual. Sem este mu-plugin, o JetEngine envia POST para a URL da página com ?nocache=<ts>, e o CloudFront rejeita com 403 porque o DefaultCacheBehavior só permite HEAD/GET. O handler wp_ajax_jet_engine_ajax já está registrado no plugin e responde em admin-ajax.php.
 * Version: 1.0.0
 * Author: Daniel Cambria
 *
 * Filtro alvo: jet-engine/listings/ajax-listing-url (frontend.php:166-169 do
 * jet-engine plugin). Aplica em TODOS os listings com load-more do site
 * (Espiral de Conhecimento, Mapa de Plataformas, Publicações, 4 Amazônias
 * + versões EN — 8 páginas em prod). Compatível com bit-jsf-query-count-sync
 * (continua escutando o evento JS jet-engine/listing-grid/after-load-more).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_filter( 'jet-engine/listings/ajax-listing-url', function ( $url ) {
	return esc_url( admin_url( 'admin-ajax.php' ) );
}, 10, 1 );
