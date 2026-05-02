<?php
/**
 * bit-opcache-invalidate.php — Endpoint de invalidação cirúrgica de OPcache
 * Autor: Daniel Cambría / Bureau de Tecnologia
 * Versão: 1.0.0
 * Data: 2026-04-02
 *
 * Propósito:
 *   Invalida um arquivo PHP específico no OPcache do pool PHP-FPM via HTTP.
 *   Diferente de `opcache_reset()` chamado via WP-CLI (SAPI CLI — instância
 *   separada de OPcache), este endpoint roda DENTRO do pool FPM e invalida
 *   o bytecode cacheado daquele processo, sem descartar o cache inteiro.
 *
 * Uso (via deploy-muplugin.sh):
 *   POST /bit-opcache-invalidate.php
 *   Body: token=SECRET&file=/path/absoluto/arquivo.php&force=1
 *
 * Segurança:
 *   - Token secreto obrigatório (OPCACHE_INVALIDATE_TOKEN em wp-config.php)
 *   - Aceita apenas POST
 *   - Restringe paths a WP_ROOT (impede invalidação de arquivos arbitrários)
 *   - Sem output de debug em caso de falha de autenticação
 *   - Não indexável (X-Robots-Tag + Cache-Control)
 *
 * Deploy:
 *   Colocar em WP_ROOT (mesmo nível de wp-config.php), NÃO em mu-plugins.
 *   scp scripts/bit-opcache-invalidate.php HOST:/var/www/concertacaoamazonia.com.br/
 *
 * Adicionar ao wp-config.php:
 *   define('OPCACHE_INVALIDATE_TOKEN', 'gere-um-token-com-openssl-rand-hex-32');
 */

// Headers de segurança imediatos
header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate, private');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');

// Apenas POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

// Carregar token do wp-config.php sem inicializar o WordPress inteiro
// (evita carregar plugins, banco, etc.)
$wp_config_path = __DIR__ . '/wp-config.php';
if (!file_exists($wp_config_path)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'config_not_found']);
    exit;
}

// Extrair OPCACHE_INVALIDATE_TOKEN do wp-config sem executar o arquivo completo
$config_content = file_get_contents($wp_config_path);
if (preg_match("/define\s*\(\s*['\"]OPCACHE_INVALIDATE_TOKEN['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $config_content, $matches)) {
    $expected_token = $matches[1];
} else {
    // Token não configurado — endpoint desabilitado
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'endpoint_not_configured']);
    exit;
}

// Validar token (timing-safe)
$provided_token = $_POST['token'] ?? '';
if (!hash_equals($expected_token, $provided_token)) {
    // Sem informação sobre o motivo — idêntico ao 404 para atacante
    http_response_code(404);
    echo json_encode(['ok' => false]);
    exit;
}

// Verificar se OPcache está disponível neste contexto FPM
if (!function_exists('opcache_invalidate')) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'opcache_unavailable']);
    exit;
}

// Validar parâmetro file
$file = $_POST['file'] ?? '';
if (empty($file)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing_file_param']);
    exit;
}

// Resolver path real para evitar traversal
$real_file = realpath($file);
$wp_root   = realpath(__DIR__);

// Garantir que o arquivo está dentro de WP_ROOT
if ($real_file === false || strpos($real_file, $wp_root) !== 0) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'path_outside_wp_root']);
    exit;
}

// Apenas arquivos PHP
if (pathinfo($real_file, PATHINFO_EXTENSION) !== 'php') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'not_a_php_file']);
    exit;
}

// Verificar se o arquivo existe
if (!file_exists($real_file)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'file_not_found', 'file' => basename($real_file)]);
    exit;
}

// Executar invalidação
// force=1 garante que o arquivo é removido do cache mesmo que timestamps
// estejam desabilitados (opcache.validate_timestamps=0)
$force  = isset($_POST['force']) && $_POST['force'] === '1';
$result = opcache_invalidate($real_file, $force);

if ($result) {
    http_response_code(200);
    echo json_encode([
        'ok'     => true,
        'file'   => basename($real_file),
        'forced' => $force,
        'sapi'   => PHP_SAPI,
    ]);
} else {
    // Arquivo pode não estar no cache (nunca foi compilado) — não é erro crítico
    http_response_code(200);
    echo json_encode([
        'ok'      => true,
        'file'    => basename($real_file),
        'cached'  => false,
        'message' => 'file_not_in_opcache_or_already_invalidated',
    ]);
}
