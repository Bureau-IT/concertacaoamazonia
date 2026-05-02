<?php
/**
 * Plugin Name: JetElements S3 Downloads Redirect
 * Description: Intercepta downloads do JetElements e redireciona diretamente para S3, evitando sobrecarga do servidor
 * Version: 1.0.0
 * Author: Daniel Cambría + Warp
 * 
 * Este plugin resolve o problema de compatibilidade entre JetElements Download Handler e S3-Uploads
 * fazendo redirect direto para URLs do S3 em vez de processar downloads localmente.
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
        
        // Log da requisição para monitoramento
        error_log("JET-S3-REDIRECT: Interceptando download hash: $hash IP: $ip");
        
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
        
        // Obtém a URL do S3 diretamente
        $s3_url = wp_get_attachment_url($attachment_id);
        
        if (!$s3_url) {
            error_log("JET-S3-REDIRECT: URL não encontrada para attachment ID: $attachment_id IP: $ip");
            wp_die('Arquivo não encontrado', 'Erro 404', array('response' => 404));
            return;
        }
        
        // Se for URL S3, faz redirect direto
        if (strpos($s3_url, 's3.') !== false || strpos($s3_url, 'amazonaws.com') !== false) {
            
            // Obtém informações do arquivo
            $filename = basename($s3_url);
            $filesize = $this->get_s3_filesize($attachment_id);
            
            // Log do redirect
            error_log("JET-S3-REDIRECT: SUCCESS - Redirecionando ID $attachment_id ($filename, {$filesize}MB) para S3 | IP: $ip");
            
            // Headers de segurança e cache
            header('X-Robots-Tag: noindex, nofollow', true);
            header('Cache-Control: no-cache, no-store, must-revalidate', true);
            header('Pragma: no-cache', true);
            header('Expires: 0', true);
            header('X-Redirect-Reason: JetElements-S3-Optimization', true);
            
            // Redirect 302 (temporário) para o S3
            wp_redirect($s3_url, 302);
            exit;
        }
        
        // Se não for S3, deixa o JetElements processar normalmente
        error_log("JET-S3-REDIRECT: URL não é S3, deixando JetElements processar: $s3_url IP: $ip");
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
