<?php
/**
 * fix-atlas-en-pagination-string.php — v1.0.0
 *
 * Traduz a string hardcoded "Mostrando %start-item%-%end-item% de [total] artistas."
 * que vive URL-encoded dentro de um dynamic tag JetEngine (jet-query-count) no
 * _elementor_data da página Atlas EN (post 72730).
 *
 * WPML não traduz essa string porque é payload interno de dynamic tag JetEngine,
 * fora do alcance do Translation Editor e do String Translation. A correção é
 * editar _elementor_data EN diretamente, sem tocar no PT.
 *
 * Tarefa ClickUp: https://app.clickup.com/t/86ahettug
 *
 * Uso (dry-run):
 *   docker cp scripts/fix-atlas-en-pagination-string.php concertacao-dev-wordpress:/tmp/
 *   docker exec -e ATLAS_DRY_RUN=1 -u www-data concertacao-dev-wordpress \
 *     wp eval-file /tmp/fix-atlas-en-pagination-string.php \
 *     --url="https://cambrasmax.local:8484/cultura/en/cultural-atlas-of-the-amazon/"
 *
 *   # Aplicar:
 *   docker exec -u www-data concertacao-dev-wordpress \
 *     wp eval-file /tmp/fix-atlas-en-pagination-string.php \
 *     --url="https://cambrasmax.local:8484/cultura/en/cultural-atlas-of-the-amazon/"
 *
 * Flags via env:
 *   ATLAS_DRY_RUN=1   Não modifica nada
 *
 * Idempotente: ao rodar 2x, a 2ª execução não acha mais a string PT e sai sem ação.
 * Backup automático em wp_2_options chave `_atlas_en_72730_backup_<timestamp>`.
 */

if (!defined('ABSPATH')) {
    echo "Erro: rodar via wp eval-file.\n";
    exit(1);
}

$DRY_RUN = !empty(getenv('ATLAS_DRY_RUN'));
$POST_ID = 72730; // Cultural Atlas of the Amazon (EN)

// String original (PT, encoded como dentro do dynamic tag) e tradução EN
$ORIGINAL_PT = 'Mostrando %start-item%-%end-item% de [total] artistas.';
$TRANSLATED_EN = 'Showing %start-item%-%end-item% of [total] artists.';

// URL-encoded versions (como aparecem dentro de settings="...")
$ORIGINAL_PT_ENCODED   = rawurlencode($ORIGINAL_PT);
$TRANSLATED_EN_ENCODED = rawurlencode($TRANSLATED_EN);

echo "=== fix-atlas-en-pagination-string v1.0.0 ===\n";
echo "DRY_RUN: " . ($DRY_RUN ? 'YES' : 'NO') . "\n";
echo "Target post: {$POST_ID} (blog " . get_current_blog_id() . ")\n";
echo "Substituir: \"$ORIGINAL_PT\"\n";
echo "Por:        \"$TRANSLATED_EN\"\n\n";

// Sanity: blog 2
if (get_current_blog_id() !== 2) {
    echo "ERRO: deve rodar no blog 2 (/cultura/). Use --url=.../cultura/en/...\n";
    exit(1);
}

// 1. Ler _elementor_data
$data_raw = get_post_meta($POST_ID, '_elementor_data', true);
if (empty($data_raw)) {
    echo "ERRO: post {$POST_ID} sem _elementor_data\n";
    exit(1);
}

echo "_elementor_data atual: " . strlen($data_raw) . " bytes\n";

// 2. Procurar ocorrências (versões PT-BR e EN para checar idempotência)
$pt_count = substr_count($data_raw, $ORIGINAL_PT_ENCODED);
$en_count = substr_count($data_raw, $TRANSLATED_EN_ENCODED);
echo "Ocorrências da string PT encoded: $pt_count\n";
echo "Ocorrências da string EN encoded: $en_count\n";

if ($pt_count === 0) {
    if ($en_count > 0) {
        echo "Nada a fazer: já está traduzido (idempotente).\n";
        exit(0);
    }
    echo "ERRO: não encontrei a string PT no _elementor_data. Verificar se o widget mudou.\n";
    exit(1);
}

// 3. Backup
$backup_key = '_atlas_en_72730_backup_' . time();
if ($DRY_RUN) {
    echo "\n[DRY] Backup seria gravado em wp_2_options['$backup_key']\n";
} else {
    update_option($backup_key, $data_raw, false);
    echo "\nBackup gravado em wp_2_options['$backup_key']\n";
}

// 4. Substituir
$new_data_raw = str_replace($ORIGINAL_PT_ENCODED, $TRANSLATED_EN_ENCODED, $data_raw, $count);
echo "Substituições aplicadas: $count\n";
echo "_elementor_data novo: " . strlen($new_data_raw) . " bytes\n";

// Sanity check: o JSON ainda é válido?
$decoded = json_decode($new_data_raw, true);
if (!is_array($decoded)) {
    echo "ERRO: JSON resultante inválido. Abortando. Erro: " . json_last_error_msg() . "\n";
    exit(1);
}
echo "JSON resultante: válido (" . count($decoded) . " top-level elements)\n";

// 5. Gravar (update_post_meta faz wp_slash internamente, mas como é string raw
//    sem barras adicionais, passar com wp_slash explícito é seguro)
if ($DRY_RUN) {
    echo "\n[DRY] update_post_meta seria chamado mas não foi.\n";
} else {
    $result = update_post_meta($POST_ID, '_elementor_data', wp_slash($new_data_raw));
    if ($result === false) {
        echo "ERRO: update_post_meta retornou false\n";
        exit(1);
    }
    echo "_elementor_data atualizado.\n";

    // 6. Flush Elementor CSS + warm-up
    if (class_exists('\Elementor\Plugin')) {
        \Elementor\Plugin::$instance->files_manager->clear_cache();
        $css_file = new \Elementor\Core\Files\CSS\Post($POST_ID);
        $css_file->update();
        echo "Elementor CSS regenerado para post {$POST_ID}.\n";
    }

    // 7. Object cache
    wp_cache_delete($POST_ID, 'post_meta');
    clean_post_cache($POST_ID);
    echo "Object cache invalidado.\n";

    // 8. WP Rocket cirúrgico
    if (function_exists('rocket_clean_post')) {
        rocket_clean_post($POST_ID);
        echo "WP Rocket cache limpo.\n";
    }
}

echo "\n=== Resumo ===\n";
echo "Post: 72730 (Cultural Atlas of the Amazon EN)\n";
echo "Mode: " . ($DRY_RUN ? 'DRY-RUN (nada gravado)' : 'APPLIED') . "\n";
if (!$DRY_RUN) {
    echo "Backup: wp_2_options['$backup_key']\n";
    echo "Para reverter: update_post_meta(72730, '_elementor_data', wp_slash(get_option('$backup_key')))\n";
}
echo "\nValidar via: curl -sk 'https://cambrasmax.local:8484/cultura/en/cultural-atlas-of-the-amazon/' | grep -oE 'Showing[^<]{0,40}'\n";
