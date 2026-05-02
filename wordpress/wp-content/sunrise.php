<?php
/**
 * Cloudflare Tunnel → Multisite Domain Remap (sunrise)
 *
 * Caso: tunnel aponta para o site RAIZ do multisite.
 * Subsites sao acessiveis por path (/cultura-v2/).
 *
 * Apenas remapeia HTTP_HOST para que ms-settings.php encontre
 * a rede e resolva os subsites por REQUEST_URI normalmente.
 */

$tunnel_domain_remap = [
    'concertacao.bureau-it.com' => 'cambrasmax.local:8484',
];

if (!isset($_SERVER['HTTP_HOST']) || !isset($tunnel_domain_remap[$_SERVER['HTTP_HOST']])) {
    return;
}

define('TUNNEL_ORIGINAL_HOST', $_SERVER['HTTP_HOST']);

$local = $tunnel_domain_remap[$_SERVER['HTTP_HOST']];
$_SERVER['HTTP_HOST'] = $local;

if (strpos($local, ':') !== false) {
    $_SERVER['SERVER_PORT'] = explode(':', $local)[1];
}
