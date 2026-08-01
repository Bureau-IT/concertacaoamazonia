<?php
/**
 * Plugin Name: JetElements S3 Downloads Redirect
 * Description: Intercepta downloads do JetElements e redireciona para a URL pública do attachment
 *              quando o arquivo não existe no FS local (típico em prod com CF-OAC + s3-uploads OFF).
 * Version: 1.1.1
 * Author: Daniel Cambría
 *
 * v1.1.0: o guard antigo `strpos($url,'s3.') || strpos($url,'amazonaws.com')` falhava em sites com
 *         CF-OAC (URL fica no domínio do site, sem 's3.'), causando fallthrough silencioso para o
 *         handler do JetElements que retornava `return;` quando `is_file($path_local)===false` e
 *         WordPress renderizava template default (home). Agora a decisão é baseada na existência
 *         do arquivo local: ausente → redirect 302 para a URL pública; presente → deixa JetElements
 *         processar (streaming chunked).
 */

// Previne acesso direto
if (!defined('WPINC')) {
    die;
}

class Jet_S3_Redirect {
    
    public function __construct() {
        // Intercepta ANTES do JetElements processar (priority 5, JetElements usa 99)
        add_action('init', array($this, 'handle_jet_download'), 5);
    }
    
    /**
     * Intercepta requisições jet_download e redireciona para S3
     */
    public function handle_jet_download() {
        // Verifica se é uma requisição jet_download
        if (!isset($_GET['jet_download'])) {
            return;
        }
        
        $hash = sanitize_text_field($_GET['jet_download']);
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        // Log da requisição para monitoramento (v1.1.1 marker visivel)
        error_log("JET-S3-REDIRECT[v1.1.1]: INICIO hash=$hash IP=$ip uri=" . ($_SERVER['REQUEST_URI'] ?? '?'));
        
        // Descriptografa o hash para obter o attachment ID (método JetElements)
        $attachment_id = $this->decrypt_jet_download_hash($hash);
        
        if (!$attachment_id) {
            error_log("JET-S3-REDIRECT: Hash inválido: $hash IP: $ip");
            wp_die('Download inválido', 'Erro 400', array('response' => 400));
            return;
        }
        
        // Verifica se é um attachment válido
        $post = get_post($attachment_id);
        if (!$post || $post->post_type !== 'attachment') {
            error_log("JET-S3-REDIRECT: Attachment inválido ID: $attachment_id IP: $ip");
            wp_die('Arquivo não encontrado', 'Erro 404', array('response' => 404));
            return;
        }
        
        // Obtém a URL pública do attachment
        $public_url = wp_get_attachment_url($attachment_id);

        if (!$public_url) {
            error_log("JET-S3-REDIRECT: URL não encontrada para attachment ID: $attachment_id IP: $ip");
            wp_die('Arquivo não encontrado', 'Erro 404', array('response' => 404));
            return;
        }

        // Decisão: arquivo existe localmente?
        // - Não existe (típico prod com CF-OAC + s3-uploads OFF): redirect 302 para URL pública
        //   (CF/S3 servem o binário diretamente, sem onerar o origin com readfile)
        // - Existe: deixa JetElements processar (streaming chunked, força Content-Disposition)
        $local_path = get_attached_file($attachment_id);

        if (!$local_path || !is_file($local_path)) {
            $filename = basename($public_url);
            $filesize = $this->get_s3_filesize($attachment_id);

            error_log("JET-S3-REDIRECT[v1.1.1]: SUCCESS - Redirecionando ID=$attachment_id ($filename, {$filesize}MB) para $public_url | IP=$ip");

            header('X-Robots-Tag: noindex, nofollow', true);
            header('Cache-Control: no-cache, no-store, must-revalidate', true);
            header('Pragma: no-cache', true);
            header('Expires: 0', true);
            header('X-Redirect-Reason: JetElements-S3-Optimization', true);

            wp_redirect($public_url, 302);
            exit;
        }

        // Arquivo existe localmente — JetElements processa (streaming chunked com Content-Disposition)
        error_log("JET-S3-REDIRECT[v1.1.1]: arquivo local presente, deixando JetElements processar: $local_path IP=$ip");
    }
    
    /**
     * Descriptografa o hash do JetElements usando o método exato do plugin
     * Baseado em get_encrypted_id() e decrypt_id() do JetElements
     */
    private function decrypt_jet_download_hash($hash) {
        // Obtém o mapa de hashes armazenado pelo JetElements
        $hash_map = get_option('jet_elements_download_button_hashes', array());
        
        // Verifica se o hash existe no mapa
        if (isset($hash_map[$hash])) {
            return absint($hash_map[$hash]);
        }
        
        return false;
    }
    
    /**
     * Obtém o tamanho do arquivo em MB para logs
     */
    private function get_s3_filesize($attachment_id) {
        $metadata = wp_get_attachment_metadata($attachment_id);
        
        if (isset($metadata['filesize'])) {
            return round($metadata['filesize'] / 1024 / 1024, 2);
        }
        
        return 'unknown';
    }
}

// Inicializa o plugin
new Jet_S3_Redirect();

/**
 * Impede o WP Rocket de cachear (e servir) qualquer request com ?jet_download=...
 *
 * Sem isso, o advanced-cache.php do WP Rocket processa o request ANTES do init prio 5
 * desta classe — como `jet_download` não está em `cache_query_strings`, ele colapsa a
 * request para a home e serve o `index-https.html` cacheado. O handler abaixo nunca roda.
 */
add_filter('rocket_cache_reject_uri', function($uris) {
    $uris = is_array($uris) ? $uris : [];
    // regex (WP Rocket interpreta como pattern); escape de "?" porque é literal
    $uris[] = '(?:.*)\?(?:.*)jet_download=(?:.*)';
    return $uris;
});

/**
 * Log de ativação e estatísticas
 */
add_action('init', function() {
    $activation_key = 'jet_s3_redirect_activated';
    
    if (!get_option($activation_key)) {
        error_log('JET-S3-REDIRECT: Plugin ativado - Redirecionamentos S3 habilitados para otimização de performance');
        update_option($activation_key, time());
        
        // Log das configurações atuais
        $s3_bucket = defined('S3_UPLOADS_BUCKET') ? S3_UPLOADS_BUCKET : 'não definido';
        $use_local = defined('S3_UPLOADS_USE_LOCAL') ? (S3_UPLOADS_USE_LOCAL ? 'true' : 'false') : 'não definido';
        
        error_log("JET-S3-REDIRECT: Configuração S3 - Bucket: $s3_bucket, Use Local: $use_local");
    }
}, 1);

/**
 * Função para resetar o plugin se necessário (debug)
 */
if (defined('WP_DEBUG') && WP_DEBUG && isset($_GET['reset_jet_s3_redirect'])) {
    add_action('init', function() {
        if (current_user_can('manage_options')) {
            delete_option('jet_s3_redirect_activated');
            error_log('JET-S3-REDIRECT: Plugin resetado por administrador');
            wp_die('JetElements S3 Redirect resetado com sucesso!');
        }
    });
}
