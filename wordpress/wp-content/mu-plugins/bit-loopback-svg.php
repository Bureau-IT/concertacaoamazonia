<?php
/**
 * Plugin Name: BIT Loopback SVG Fix
 * Description: Corrige resolução de SVGs no jet-inline-svg quando o site é
 *              acessado via Cloudflare Tunnel.
 *
 *              Problema: jet-elements usa str_replace(site_url('/'), ABSPATH, $url)
 *              para converter URL → path do filesystem. Quando o tunnel está ativo,
 *              tunnel-url-rewrite.php faz site_url() retornar a URL do tunnel
 *              (concertacao.bureau-it.com), mas o DB armazena a URL local
 *              (cambrasmax.local:8484). O str_replace não encontra match →
 *              file_exists falha → fallback <img> sem dimensões.
 *
 *              Solução: filtro prioridade 999 que desfaz o tunnel rewrite em
 *              site_url() e option_siteurl/option_home. O ob_start do
 *              tunnel-url-rewrite.php converte cambrasmax.local → tunnel URL
 *              no HTML de saída, então o browser continua vendo URLs corretas.
 *
 * Version:     1.2.0
 * Author:      Bureau IT
 */

// Corrigir site_url('/') para jet-elements em dois cenários:
//
// Cenário A — Via tunnel:
//   tunnel-url-rewrite.php muda site_url() para o tunnel URL (concertacao.bureau-it.com).
//   O DB armazena cambrasmax.local:8484, então str_replace(site_url,ABSPATH,$url) falha.
//   Solução: desfazer o rewrite do tunnel em site_url() (ob_start cuida do HTML output).
//
// Cenário B — Subsite (/cultura/):
//   site_url('/') no subsite retorna 'https://host/cultura/', mas o SVG URL
//   usa 'https://host/wp-content/uploads/' (sem /cultura/). O str_replace falha.
//   Solução: extrair o blog path de get_option('siteurl') e removê-lo do retorno.
//
//   Nota: $current_blog->path = '/' para subsites de subdiretório — não confiável.
//   O caminho correto vem de get_option('siteurl') que armazena a URL completa do subsite.
//
// Prioridade 999 — roda após todos os outros filtros (tunnel-url-rewrite.php usa 10).
add_filter('site_url', function ($url, $path) {
    // Cenário A: desfazer tunnel rewrite (constantes definidas por tunnel-url-rewrite.php)
    if (defined('TUNNEL_PUBLIC_URL') && defined('TUNNEL_LOCAL_URL')) {
        $url = str_replace(TUNNEL_PUBLIC_URL, TUNNEL_LOCAL_URL, $url);
    }

    // Cenário B: remover blog path do subsite quando chamado com path='/'
    // jet-elements usa site_url('/') como base para converter URL → filesystem path.
    // $current_blog->path não é confiável (retorna '/') — extrair path de get_option('siteurl').
    if ($path === '/') {
        $raw_siteurl = get_option('siteurl');
        if ($raw_siteurl) {
            $parsed    = parse_url($raw_siteurl);
            $blog_path = !empty($parsed['path']) ? rtrim($parsed['path'], '/') : '';
            if ($blog_path !== '') {
                $suffix = $blog_path . '/';
                if (substr($url, -strlen($suffix)) === $suffix) {
                    $url = substr($url, 0, -strlen($blog_path));
                }
            }
        }
    }

    return $url;
}, 999, 2);
