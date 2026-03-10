<?php
/**
 * Cloudflare Tunnel URL Rewrite
 * Substitui URLs locais por URLs publicas quando acessado via tunnel.
 *
 * Intercepta no nivel dos filtros do WordPress (home_url, site_url, etc.)
 * com ob_start global como fallback para garantir zero vazamento.
 *
 * Modo SUBSITE: funciona em conjunto com sunrise.php que faz o domain mapping
 * real (seta $current_blog direto com domain=tunnel-hostname).
 * Reescreve URLs com subsite path e variante sem porta.
 *
 * Modo SITE RAIZ: funciona standalone, sem sunrise.php.
 * Reescreve URLs locais 1:1 para o tunnel hostname.
 *
 * Gerado automaticamente por: std tunnel setup - NAO EDITAR MANUALMENTE
 *
 * Placeholders substituidos pelo tunnel-helper.sh:
 *   concertacao.bureau-it.com - hostname publico do Cloudflare
 *   https://cambrasmax.local:8484       - URL local HTTPS (ex: https://cambrasmax.local:8494)
 *   cambrasmax.local      - hostname local sem protocolo (ex: cambrasmax.local)
 *   8484      - porta HTTPS local (ex: 8494)
 *       - path do subsite (ex: /rota26-30) ou vazio para site raiz
 */

// --- Deteccao de acesso via tunnel ---
// Subsite: TUNNEL_ORIGINAL_HOST e definido pelo sunrise.php
// Site raiz: checa HTTP_HOST diretamente
if (defined('TUNNEL_ORIGINAL_HOST')) {
    if (TUNNEL_ORIGINAL_HOST !== 'concertacao.bureau-it.com') {
        return;
    }
} else {
    if (!isset($_SERVER['HTTP_HOST']) || $_SERVER['HTTP_HOST'] !== 'concertacao.bureau-it.com') {
        return;
    }
}

define('TUNNEL_LOCAL_URL', 'https://cambrasmax.local:8484');
define('TUNNEL_LOCAL_ALT', 'https://localhost:8484');
define('TUNNEL_PUBLIC_URL', 'https://concertacao.bureau-it.com');
define('TUNNEL_SUBSITE_PATH', '');
// Variante sem porta — redirect_canonical() do WP gera URLs sem port em alguns casos
define('TUNNEL_LOCAL_NOPORT', 'https://cambrasmax.local');

// --- Construir arrays de search/replace ---
if (TUNNEL_SUBSITE_PATH !== '') {
    // SUBSITE: ordem importa — subsite primeiro (strip prefix), depois base (shared resources)
    $tunnel_search = [
        // 1. Subsite URLs (com prefixo) → tunnel root (strip prefix)
        TUNNEL_LOCAL_URL . TUNNEL_SUBSITE_PATH,
        str_replace('/', '\\/', TUNNEL_LOCAL_URL . TUNNEL_SUBSITE_PATH),
        TUNNEL_LOCAL_ALT . TUNNEL_SUBSITE_PATH,
        str_replace('/', '\\/', TUNNEL_LOCAL_ALT . TUNNEL_SUBSITE_PATH),
        TUNNEL_LOCAL_NOPORT . TUNNEL_SUBSITE_PATH,
        str_replace('/', '\\/', TUNNEL_LOCAL_NOPORT . TUNNEL_SUBSITE_PATH),
        // 2. Base URLs (shared resources: wp-content, wp-includes) → tunnel root
        TUNNEL_LOCAL_URL,
        str_replace('/', '\\/', TUNNEL_LOCAL_URL),
        TUNNEL_LOCAL_ALT,
        str_replace('/', '\\/', TUNNEL_LOCAL_ALT),
        TUNNEL_LOCAL_NOPORT,
        str_replace('/', '\\/', TUNNEL_LOCAL_NOPORT),
    ];
    $tunnel_replace = [
        TUNNEL_PUBLIC_URL,
        str_replace('/', '\\/', TUNNEL_PUBLIC_URL),
        TUNNEL_PUBLIC_URL,
        str_replace('/', '\\/', TUNNEL_PUBLIC_URL),
        TUNNEL_PUBLIC_URL,
        str_replace('/', '\\/', TUNNEL_PUBLIC_URL),
        TUNNEL_PUBLIC_URL,
        str_replace('/', '\\/', TUNNEL_PUBLIC_URL),
        TUNNEL_PUBLIC_URL,
        str_replace('/', '\\/', TUNNEL_PUBLIC_URL),
        TUNNEL_PUBLIC_URL,
        str_replace('/', '\\/', TUNNEL_PUBLIC_URL),
    ];
} else {
    // SITE RAIZ: substituicao 1:1 (local → tunnel)
    // Inclui NOPORT e protocol-relative para cobrir dns-prefetch e redirect_canonical
    $tunnel_public_host = parse_url(TUNNEL_PUBLIC_URL, PHP_URL_HOST);
    $tunnel_local_host = str_replace('https://', '', TUNNEL_LOCAL_NOPORT);
    $tunnel_local_port = '8484';
    $tunnel_search = [
        TUNNEL_LOCAL_URL,
        str_replace('/', '\\/', TUNNEL_LOCAL_URL),
        TUNNEL_LOCAL_ALT,
        str_replace('/', '\\/', TUNNEL_LOCAL_ALT),
        TUNNEL_LOCAL_NOPORT,
        str_replace('/', '\\/', TUNNEL_LOCAL_NOPORT),
        '//' . $tunnel_local_host . ':' . $tunnel_local_port,
        '//' . $tunnel_local_host,
    ];
    $tunnel_replace = [
        TUNNEL_PUBLIC_URL,
        str_replace('/', '\\/', TUNNEL_PUBLIC_URL),
        TUNNEL_PUBLIC_URL,
        str_replace('/', '\\/', TUNNEL_PUBLIC_URL),
        TUNNEL_PUBLIC_URL,
        str_replace('/', '\\/', TUNNEL_PUBLIC_URL),
        '//' . $tunnel_public_host,
        '//' . $tunnel_public_host,
    ];
}

// Global output buffer - captura TODO output PHP (HTML, JSON, AJAX, REST)
ob_start(function ($html) use ($tunnel_search, $tunnel_replace) {
    return str_replace($tunnel_search, $tunnel_replace, $html);
});

function tunnel_rewrite_url($url) {
    if (TUNNEL_SUBSITE_PATH !== '') {
        // Primeiro strip subsite path, depois base URL
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

// Option-level (interceptacao mais cedo possivel)
add_filter('option_home', 'tunnel_rewrite_url');
add_filter('option_siteurl', 'tunnel_rewrite_url');

// URL generation functions
add_filter('home_url', 'tunnel_rewrite_url');
add_filter('site_url', 'tunnel_rewrite_url');
add_filter('admin_url', 'tunnel_rewrite_url');
add_filter('includes_url', 'tunnel_rewrite_url');
add_filter('content_url', 'tunnel_rewrite_url');
add_filter('plugins_url', 'tunnel_rewrite_url');
add_filter('rest_url', 'tunnel_rewrite_url');

// Asset loader sources
add_filter('script_loader_src', 'tunnel_rewrite_url');
add_filter('style_loader_src', 'tunnel_rewrite_url');

// Media e attachments
add_filter('wp_get_attachment_url', 'tunnel_rewrite_url');

// Theme URIs
add_filter('template_directory_uri', 'tunnel_rewrite_url');
add_filter('stylesheet_directory_uri', 'tunnel_rewrite_url');
add_filter('theme_root_uri', 'tunnel_rewrite_url');

// Upload directory (recebe array, nao string)
add_filter('upload_dir', function ($uploads) {
    $uploads['url']     = tunnel_rewrite_url($uploads['url']);
    $uploads['baseurl'] = tunnel_rewrite_url($uploads['baseurl']);
    return $uploads;
});

// Redirect URLs (header Location: nao e capturado pelo ob_start)
add_filter('wp_redirect', 'tunnel_rewrite_url');

// Desabilitar redirect_canonical para TODOS os acessos via tunnel
// redirect_canonical() gera URLs sem porta (ex: https://host/path em vez de https://host:8484/path)
// causando redirect loop quando reescrito para o hostname do tunnel
add_filter('redirect_canonical', '__return_false');
