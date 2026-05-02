<?php
/**
 * Cloudflare Tunnel URL Rewrite — Path-based Multisite Routing
 *
 * Funciona em conjunto com sunrise.php que faz o domain mapping real.
 * Reescreve URLs no output substituindo cambrasmax.local:8490 pelo tunnel hostname,
 * preservando os paths dos subsites (/5anos/, /rota26-30/, /100-dias/).
 *
 * Um único tunnel serve todos os subsites:
 *   - www-concertacao.bureau-it.com/          → blog_id=1 (site raiz)
 *   - www-concertacao.bureau-it.com/5anos/    → blog_id=2
 *   - www-concertacao.bureau-it.com/rota26-30/ → blog_id=3
 *   - www-concertacao.bureau-it.com/100-dias/  → blog_id=4
 *
 * Customizado para subsite domain mapping - EDITAR COM CUIDADO
 */

// TUNNEL_ORIGINAL_HOST é definido pelo sunrise.php (antes do multisite init)
if (!defined('TUNNEL_ORIGINAL_HOST')) {
    return;
}

// Configuração por tunnel hostname
$tunnel_configs = [
    'www-concertacao.bureau-it.com' => [
        'public_url'   => 'https://www-concertacao.bureau-it.com',
        'subsite_path' => '',   // site raiz — sem strip de path
    ],
];

if (!isset($tunnel_configs[TUNNEL_ORIGINAL_HOST])) {
    return;
}

$config = $tunnel_configs[TUNNEL_ORIGINAL_HOST];

define('TUNNEL_LOCAL_URL',    'https://cambrasmax.local:8490');
define('TUNNEL_LOCAL_ALT',    'https://localhost:8490');
define('TUNNEL_LOCAL_NOPORT', 'https://cambrasmax.local');
define('TUNNEL_PUBLIC_URL',   $config['public_url']);
define('TUNNEL_SUBSITE_PATH', $config['subsite_path']);

// URLs a substituir — ORDEM IMPORTA: subsite com prefixo primeiro, depois base
$tunnel_search  = [];
$tunnel_replace = [];

if (TUNNEL_SUBSITE_PATH !== '') {
    // Subsite URLs (com prefixo) → tunnel root (strip prefix)
    foreach ([TUNNEL_LOCAL_URL, TUNNEL_LOCAL_ALT, TUNNEL_LOCAL_NOPORT] as $local) {
        $tunnel_search[]  = $local . TUNNEL_SUBSITE_PATH;
        $tunnel_search[]  = str_replace('/', '\\/', $local . TUNNEL_SUBSITE_PATH);
        $tunnel_replace[] = TUNNEL_PUBLIC_URL;
        $tunnel_replace[] = str_replace('/', '\\/', TUNNEL_PUBLIC_URL);
    }
}

// Base URLs (shared resources: wp-content, wp-includes) → tunnel root
foreach ([TUNNEL_LOCAL_URL, TUNNEL_LOCAL_ALT, TUNNEL_LOCAL_NOPORT] as $local) {
    $tunnel_search[]  = $local;
    $tunnel_search[]  = str_replace('/', '\\/', $local);
    $tunnel_replace[] = TUNNEL_PUBLIC_URL;
    $tunnel_replace[] = str_replace('/', '\\/', TUNNEL_PUBLIC_URL);
}

// Global output buffer - captura TODO output PHP (HTML, JSON, AJAX, REST)
ob_start(function ($html) use ($tunnel_search, $tunnel_replace) {
    return str_replace($tunnel_search, $tunnel_replace, $html);
});

function tunnel_rewrite_url($url) {
    if (TUNNEL_SUBSITE_PATH !== '') {
        $url = str_replace(
            [
                TUNNEL_LOCAL_URL . TUNNEL_SUBSITE_PATH,
                TUNNEL_LOCAL_ALT . TUNNEL_SUBSITE_PATH,
                TUNNEL_LOCAL_NOPORT . TUNNEL_SUBSITE_PATH,
            ],
            [TUNNEL_PUBLIC_URL, TUNNEL_PUBLIC_URL, TUNNEL_PUBLIC_URL],
            $url
        );
    }
    return str_replace(
        [TUNNEL_LOCAL_URL, TUNNEL_LOCAL_ALT, TUNNEL_LOCAL_NOPORT],
        [TUNNEL_PUBLIC_URL, TUNNEL_PUBLIC_URL, TUNNEL_PUBLIC_URL],
        $url
    );
}

// Option-level (interceptação mais cedo possível)
add_filter('option_home',    'tunnel_rewrite_url');
add_filter('option_siteurl', 'tunnel_rewrite_url');

// URL generation functions
add_filter('home_url',     'tunnel_rewrite_url');
add_filter('site_url',     'tunnel_rewrite_url');
add_filter('admin_url',    'tunnel_rewrite_url');
add_filter('includes_url', 'tunnel_rewrite_url');
add_filter('content_url',  'tunnel_rewrite_url');
add_filter('plugins_url',  'tunnel_rewrite_url');
add_filter('rest_url',     'tunnel_rewrite_url');

// Asset loader sources
add_filter('script_loader_src', 'tunnel_rewrite_url');
add_filter('style_loader_src',  'tunnel_rewrite_url');

// Media e attachments
add_filter('wp_get_attachment_url', 'tunnel_rewrite_url');

// Theme URIs
add_filter('template_directory_uri',   'tunnel_rewrite_url');
add_filter('stylesheet_directory_uri', 'tunnel_rewrite_url');
add_filter('theme_root_uri',           'tunnel_rewrite_url');

// Upload directory (recebe array, não string)
add_filter('upload_dir', function ($uploads) {
    $uploads['url']     = tunnel_rewrite_url($uploads['url']);
    $uploads['baseurl'] = tunnel_rewrite_url($uploads['baseurl']);
    return $uploads;
});

// Desabilitar redirect_canonical para tunnel (evita loop por divergência de port)
add_filter('redirect_canonical', '__return_false');

// Redirect URLs (header Location: não é capturado pelo ob_start)
add_filter('wp_redirect', 'tunnel_rewrite_url');
