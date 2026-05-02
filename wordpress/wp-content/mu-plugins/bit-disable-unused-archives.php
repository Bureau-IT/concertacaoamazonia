<?php
/**
 * Disable unused WordPress archives
 *
 * Redirects 301 to home: author, search, category, tag, date archives.
 * Also returns 404 for upload-directory archive-like URLs when they reach WordPress.
 * Runs early via mu-plugin to fire before Elementor Pro template routing.
 */

add_action( 'parse_request', function ( $wp ) {
    $request_uri = $_SERVER['REQUEST_URI'] ?? '';
    $request_path = wp_parse_url( $request_uri, PHP_URL_PATH );

    if ( is_string( $request_path ) && preg_match( '#(?:^|/)(?:wp-content/uploads)/\d{4}(?:/\d{2})?/?$#', $request_path ) ) {
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
