<?php
/**
 * Cloudflare Tunnel URL Rewrite — Path-based Multisite Routing
 *
 * Funciona em conjunto com sunrise.php que faz o domain mapping real.
 * Reescreve URLs no output substituindo cambrasmax.local:<porta> pelo tunnel hostname,
 * preservando os paths dos subsites (/cultura/).
 *
 * Single tunnel serve todos os subsites:
 *   - concertacao.bureau-it.com/         → blog_id=1 (site raiz)
 *   - concertacao.bureau-it.com/cultura/ → blog_id=2
 *
 * Customizado para subsite domain mapping - EDITAR COM CUIDADO
 */

// Belt-and-suspenders: este mu-plugin é DEV-ONLY. Em prod (mesmo se sunrise.php
// for sincronizado por engano com mapping de tunnel), aborta antes de qualquer ob_start.
if (function_exists('wp_get_environment_type') && wp_get_environment_type() !== 'development') {
    return;
}

// TUNNEL_ORIGINAL_HOST é definido pelo sunrise.php (antes do multisite init)
if (!defined('TUNNEL_ORIGINAL_HOST')) {
    return;
}

// Configuração por tunnel hostname
$tunnel_configs = [
    'concertacao.bureau-it.com' => [
        'public_url'   => 'https://concertacao.bureau-it.com',
        'subsite_path' => '',   // site raiz — sem strip de path
    ],
];

if (!isset($tunnel_configs[TUNNEL_ORIGINAL_HOST])) {
    return;
}

$config = $tunnel_configs[TUNNEL_ORIGINAL_HOST];

// Porta local lida dinamicamente do siteurl atual — evita drift quando NGINX_SSL_PORT muda no .env
$_siteurl_port = parse_url(get_option('siteurl'), PHP_URL_PORT);
$_local_port   = $_siteurl_port ? ':' . (int) $_siteurl_port : '';

define('TUNNEL_LOCAL_URL',    'https://cambrasmax.local' . $_local_port);
define('TUNNEL_LOCAL_ALT',    'https://localhost' . $_local_port);
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

// dns-prefetch / preconnect hints: wp_resource_hints() emite hints em formatos variados
// — string "cambrasmax.local" (host puro, gerado por wp_dependencies_unique_hosts),
// "//cambrasmax.local" (sem scheme), "https://cambrasmax.local:8484" (com scheme/porta),
// ou array ['href' => ..., 'crossorigin' => ...]. Nenhum casa o str_replace do ob_start.
//
// Prioridade PHP_INT_MAX-10: garantir execução APÓS WP Rocket / Site Kit / S3-Uploads
// (todos prio 10) que podem injetar hints próprios apontando para o host local.
add_filter('wp_resource_hints', function ($hints, $relation_type) {
    $tunnel_host = parse_url(TUNNEL_PUBLIC_URL, PHP_URL_HOST);
    foreach ($hints as $i => $hint) {
        $url = is_array($hint) ? ($hint['href'] ?? '') : $hint;
        if (stripos($url, 'cambrasmax.local') === false && stripos($url, 'localhost') === false) continue;
        // Anchors `^` + lookahead `(?=/|$)`: só casa quando o segmento é exatamente
        // o host (eventualmente com scheme + porta), não substring de outro hostname
        // como `sub.cambrasmax.local` ou `cambrasmax.local.example.com`.
        $new_url = preg_replace(
            '#^((?:https?:)?//)?(?:cambrasmax\.local|localhost)(?::\d+)?(?=/|$)#i',
            '$1' . $tunnel_host,
            $url
        );
        if (is_array($hint)) { $hint['href'] = $new_url; $hints[$i] = $hint; }
        else                 { $hints[$i]    = $new_url; }
    }
    return $hints;
}, PHP_INT_MAX - 10, 2);
