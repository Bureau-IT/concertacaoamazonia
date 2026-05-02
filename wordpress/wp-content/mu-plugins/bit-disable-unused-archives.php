<?php
/**
 * Disable unused WordPress archives
 *
 * Version: 1.1.0
 *
 * Redirects 301 to home: author, search, category, tag, date archives.
 * Returns 404 for: upload-directory archive-like URLs and missing /wp-content/cache/* and
 * /wp-content/elementor-cache/* assets that fall through to WordPress (e.g. expired WP Rocket
 * minify files), preventing them from being parsed as a generic post archive.
 * Runs early via mu-plugin to fire before Elementor Pro template routing.
 *
 * Note v1.1.0: removed the v1.2.0 attempt to 404 generic post archives via template_redirect.
 * That hook caused a production-wide FPM stall (incidente 2026-05-02 01:10) — likely because
 * is_archive() / is_post_type_archive() in template_redirect interacts badly with JetEngine
 * listings and TEC archives, causing reprocessing loops or deadlocks.
 */

add_action( 'parse_request', function ( $wp ) {
    $request_uri  = $_SERVER['REQUEST_URI'] ?? '';
    $request_path = wp_parse_url( $request_uri, PHP_URL_PATH );

    if ( ! is_string( $request_path ) ) {
        return;
    }

    if ( preg_match( '#(?:^|/)(?:wp-content/uploads)/\d{4}(?:/\d{2})?/?$#', $request_path ) ) {
        global $wp_query;
        $wp_query->set_404();
        status_header( 404 );
        nocache_headers();
        exit;
    }

    if ( preg_match( '#(?:^|/)wp-content/(?:cache|elementor-cache)/#', $request_path ) ) {
        global $wp_query;
        $wp_query->set_404();
        status_header( 404 );
        nocache_headers();
        exit;
    }

    if ( ! empty( $wp->query_vars['author_name'] ) || ! empty( $wp->query_vars['author'] ) ) {
        wp_redirect( home_url( '/' ), 301 );
        exit;
    }
}, 1 );

add_action( 'template_redirect', function () {
    if ( is_author() || is_search() || is_category() || is_tag() || is_date() ) {
        wp_redirect( home_url( '/' ), 301 );
        exit;
    }
}, 1 );
