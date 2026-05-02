<?php
/**
 * Plugin Name: BIT Security Hardening
 * Plugin URI:  https://bureau-it.com/
 * Description: Hardening de segurança WordPress — bloqueia enumeração de usuários, remove
 *              informações de versão, limpa headers XML-RPC e protege endpoints REST.
 *              Compatível com WordPress Multisite, WPML, JetEngine e WP Rocket.
 * Version:     1.2.0
 * Author:      Daniel Cambría / Bureau de Tecnologia Ltda.
 * Network:     true
 */

/**
 * Changelog:
 *
 * 1.2.0 — 2026-04-08
 *   [NEW] Section 8: TEC REST API — bloqueia /tribe/events/v1/ e /tribe_events/ para
 *         não-autenticados. PRESERVA /tribe/views/v2/ (frontend AJAX do calendário —
 *         month switching, "Load More"). Evita que crawlers saturem FPM via REST API.
 *
 * 1.1.0 — 2026-03-31
 *   [C1] Bug crítico: blocked_patterns com backslash-escape inoperante — padrões tinham
 *        escapes de regex mas str_contains() usa busca literal; corrigido para strings plain.
 *        Adicionado guard is_admin() para não bloquear requisições do wp-admin.
 *   [C2] Guard is_admin() adicionado à verificação de arquivos sensíveis (via C1).
 *   [A1] capability list_users substituída por edit_posts no filtro rest_endpoints e
 *        permission_callback — list_users bloqueava editores no Elementor Pro.
 *   [A2] home_url('/') substituído por network_site_url('/') no redirect de ?author=N
 *        para evitar retornar URL do subsite em Multisite.
 *   [A6] $username sanitizado com sanitize_text_field() no log de falhas de login.
 *   [M1] Remoção de ?ver= restringida a arquivos de /wp-includes/ e /wp-admin/ para
 *        evitar atingir plugins/temas com versão coincidente ao WP core.
 *   [M2] Removido header Referrer-Policy duplicado (nginx já envia o header).
 *   [M3] Removido ini_set('expose_php') — não funciona em PHP-FPM.
 *
 * 1.0.0 — versão inicial
 */

defined('ABSPATH') || exit;

// =============================================================================
// 1. XMLRPC — Remover links e headers do <head> (bloqueio real é feito no nginx)
// =============================================================================

// Remove link rel="EditURI" (RSD) do <head> — expõe que o site usa WP + xmlrpc.php
remove_action('wp_head', 'rsd_link');

// Remove link rel="wlwmanifest" do <head> (Windows Live Writer — obsoleto)
remove_action('wp_head', 'wlwmanifest_link');

// Desabilita o processamento de métodos XML-RPC (mesmo que o arquivo exista no disco)
// Exceção: não bloquear quando JetPack estiver ativo (usa XML-RPC para sync remoto)
add_filter('xmlrpc_enabled', '__return_false');

// Remove o header X-Pingback que anuncia a URL do xmlrpc.php
add_filter('wp_headers', function (array $headers): array {
    unset($headers['X-Pingback']);
    return $headers;
});

// Remove o header X-Pingback também via send_headers (fallback para páginas não-WP)
add_action('send_headers', function (): void {
    if (headers_sent()) {
        return;
    }
    header_remove('X-Pingback');
});

// =============================================================================
// 2. Enumeração de usuários — REST API e redirecionamento ?author=N
// =============================================================================

// Bloquear /wp-json/wp/v2/users para não autenticados
// Usuários autenticados como editor/admin ainda têm acesso (necessário para Elementor/WP admin)
// [A1] Corrigido: capability list_users → edit_posts (list_users bloqueava editores no Elementor Pro)
add_filter('rest_endpoints', function (array $endpoints): array {
    // Manter acesso para usuários autenticados com capability 'edit_posts' (editors + admins)
    if (is_user_logged_in() && current_user_can('edit_posts')) {
        return $endpoints;
    }

    // Bloquear o endpoint de listagem pública de usuários
    $routes_to_block = [
        '/wp/v2/users',
        '/wp/v2/users/(?P<id>[\d]+)',
    ];

    foreach ($routes_to_block as $route) {
        if (isset($endpoints[$route])) {
            foreach ($endpoints[$route] as $index => $handler) {
                // Permitir apenas GET autenticado — bloquear listagem pública
                if (isset($handler['methods']) && $handler['methods'] === \WP_REST_Server::READABLE) {
                    $endpoints[$route][$index]['permission_callback'] = function (): bool {
                        return is_user_logged_in() && current_user_can('edit_posts');
                    };
                }
            }
        }
    }

    return $endpoints;
});

// Bloquear enumeração via ?author=N (redireciona para home em vez de revelar login)
// Não bloquear no admin ou em contextos autenticados
add_action('template_redirect', function (): void {
    // Ignorar admin e AJAX
    if (is_admin() || wp_doing_ajax()) {
        return;
    }

    // Ignorar usuários autenticados
    if (is_user_logged_in()) {
        return;
    }

    // Bloquear ?author=N quando não há slug de usuário no path (enumeração numérica)
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if (isset($_GET['author']) && ! is_author()) {
        // [A2] Corrigido: home_url('/') → network_site_url('/') para Multisite
        wp_safe_redirect(network_site_url('/'), 301);
        exit;
    }

    // Bloquear também quando a URL já resolveu para uma página de autor
    // (enumeração via slug ainda revela existência do usuário — retornar 404)
    if (is_author()) {
        global $wp_query;
        $wp_query->set_404();
        status_header(404);
        // Não fazer exit — deixar o tema renderizar a página 404 normalmente
    }
});

// =============================================================================
// 3. Remover versão do WordPress de todos os outputs públicos
// =============================================================================

// Remove versão de scripts e estilos enfileirados (?ver=X.Y.Z)
// [M1] Corrigido: restringir remoção apenas a arquivos de /wp-includes/ e /wp-admin/
//      para não atingir plugins/temas com versão coincidente ao WP core
add_filter('style_loader_src',  '_bit_security_remove_wp_version_query_string', 9999);
add_filter('script_loader_src', '_bit_security_remove_wp_version_query_string', 9999);

function _bit_security_remove_wp_version_query_string(string $src): string {
    if (is_admin()) {
        return $src;
    }

    $wp_version = get_bloginfo('version');
    // Só remover de arquivos core do WordPress, não de plugins/temas
    if (!empty($wp_version) && str_contains($src, 'ver=' . $wp_version)) {
        if (str_contains($src, '/wp-includes/') || str_contains($src, '/wp-admin/')) {
            $src = remove_query_arg('ver', $src);
        }
    }

    return $src;
}

// Remove a meta tag generator do <head> (ex: <meta name="generator" content="WordPress 6.7">)
remove_action('wp_head', 'wp_generator');

// Remove versão do feed RSS
add_filter('the_generator', '__return_empty_string');

// =============================================================================
// 4. Headers de segurança HTTP — ocultar informações do servidor
// =============================================================================

add_action('send_headers', function (): void {
    if (headers_sent()) {
        return;
    }

    // Remover headers que revelam tecnologia do servidor
    header_remove('X-Powered-By');    // PHP version (ex: PHP/8.3.x)
    header_remove('Server');          // Nginx version (já desabilitado por server_tokens off, mas por segurança)
    header_remove('X-Generator');     // Alguns plugins adicionam este header

    // [M2] Removido: header Referrer-Policy duplicado — nginx já envia este header
    // [M3] Removido: ini_set('expose_php') — não funciona em PHP-FPM
});

// =============================================================================
// 5. Proteção de arquivos sensíveis via wp_die em requisições diretas
// =============================================================================

// Bloquear acesso direto ao debug.log (caso WP_DEBUG_LOG esteja ativo em dev)
// [C1] Corrigido: padrões tinham backslash-escape de regex mas str_contains() usa busca
//      literal — removidos os escapes. [C2] Adicionado guard is_admin().
add_action('init', function (): void {
    if (is_admin()) {
        return;
    }

    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
    $request_uri = $_SERVER['REQUEST_URI'] ?? '';

    $blocked_patterns = [
        'debug.log',
        'error_log',
        'wp-config.php',
        '.env',
    ];

    foreach ($blocked_patterns as $pattern) {
        if (str_contains($request_uri, $pattern)) {
            // Nginx já bloqueia estes, mas esta camada garante via PHP também
            wp_die('Forbidden', 'Forbidden', ['response' => 403]);
        }
    }
});

// =============================================================================
// 6. Limpar resposta de erro de login (não revelar se usuário existe)
// =============================================================================

// WordPress por padrão exibe mensagens diferentes para "usuário não encontrado"
// vs "senha incorreta" — isso facilita enumeração via login form
add_filter('login_errors', function (): string {
    return '<strong>Erro:</strong> Usuário ou senha inválidos.';
});

// =============================================================================
// 7. Proteção adicional de wp-login.php em Multisite
// =============================================================================

// Em Multisite, cada subsite tem wp-admin/ mas todos usam o mesmo wp-login.php
// Adicionar cookie de segurança customizado não interfere com o fluxo do Multisite
// pois o WordPress gerencia os auth cookies por domínio/path automaticamente

// Aumentar lockout nativo (via filtro — não depende de plugin)
// WordPress não tem lockout nativo, mas podemos registrar tentativas falhas para log
add_action('wp_login_failed', function (string $username): void {
    $ip = sanitize_text_field(
        $_SERVER['HTTP_CF_CONNECTING_IP']  // IP real via Cloudflare
        ?? $_SERVER['HTTP_X_FORWARDED_FOR'] // IP real via ALB/proxy
        ?? $_SERVER['REMOTE_ADDR']          // IP direto
        ?? 'unknown'
    );

    // Logar tentativa falha para análise posterior (sem dados sensíveis)
    // [A6] Corrigido: $username sanitizado com sanitize_text_field()
    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
    error_log(
        sprintf(
            '[BIT Security] Login failed — user: %s | ip: %s | ua: %s',
            sanitize_text_field($username),
            $ip,
            substr(sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 100)
        )
    );
});

// =============================================================================
// 8. TEC REST API — bloquear endpoints custosos para não-autenticados
// =============================================================================

// Bloqueia /tribe/events/v1/ e /tribe_events/ (REST API custosa, sem uso público)
// PRESERVA /tribe/views/v2/ (frontend AJAX do calendário — month switching, Load More)
// Crawlers que atingem estes endpoints geram queries de 30-60s que saturam FPM workers
add_filter('rest_endpoints', function (array $endpoints): array {
    if (is_user_logged_in()) {
        return $endpoints;
    }

    foreach ($endpoints as $route => $handler) {
        if ($route === '/tribe/events/v1'
            || str_starts_with($route, '/tribe/events/v1/')
            || str_starts_with($route, '/tribe_events/')
        ) {
            unset($endpoints[$route]);
        }
    }

    return $endpoints;
}, 20);
