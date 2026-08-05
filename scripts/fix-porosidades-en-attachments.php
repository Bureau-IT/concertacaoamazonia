<?php
/**
 * fix-porosidades-en-attachments.php — v1.0.0
 *
 * Corrige a página /cultura/en/porosidades/ (post ID 72741) onde as imagens
 * de background dos containers Elementor não renderizam porque o WPML criou
 * attachments duplicados em blog 2 (IDs 89811-89830+) cujas URLs apontam para
 * /sites/2/ e que filtros (Network Media Library) interceptam retornando false
 * em wp_get_attachment_image_src().
 *
 * Estratégia: reescrever _elementor_data do post 72741 substituindo os IDs
 * de attachment EN (blog 2 órfãos) pelos IDs PT originais (também blog 2, mas
 * registrados na NML) via mapeamento WPML trid.
 *
 * Tarefa ClickUp: https://app.clickup.com/t/86ahetvkf
 *
 * Uso (dry-run):
 *   docker cp scripts/fix-porosidades-en-attachments.php concertacao-dev-wordpress:/tmp/
 *   docker exec -e POROSIDADES_DRY_RUN=1 -u www-data concertacao-dev-wordpress \
 *     wp eval-file /tmp/fix-porosidades-en-attachments.php \
 *     --url="https://cambrasmax.local:8484/cultura/en/porosidades/"
 *
 *   # Aplicar:
 *   docker exec -u www-data concertacao-dev-wordpress \
 *     wp eval-file /tmp/fix-porosidades-en-attachments.php \
 *     --url="https://cambrasmax.local:8484/cultura/en/porosidades/"
 *
 * Flags via env:
 *   POROSIDADES_DRY_RUN=1   Não modifica nada
 *
 * Idempotente: ao rodar 2x, o segundo run não acha nenhum ID para trocar.
 * Backup automático em wp_2_options chave `_porosidades_en_72741_backup_<timestamp>`.
 */

if (!defined('ABSPATH')) {
    echo "Erro: rodar via wp eval-file.\n";
    exit(1);
}

$DRY_RUN  = !empty(getenv('POROSIDADES_DRY_RUN'));
$POST_ID  = 72741; // Porosidades Exposition (EN)
global $wpdb;

echo "=== fix-porosidades-en-attachments v1.0.0 ===\n";
echo "DRY_RUN: " . ($DRY_RUN ? 'YES' : 'NO') . "\n";
echo "Target post: {$POST_ID} (blog " . get_current_blog_id() . ")\n\n";

// Sanity: estamos no blog 2?
if (get_current_blog_id() !== 2) {
    echo "ERRO: este script deve rodar no contexto blog 2 (/cultura/). Use --url=.../cultura/en/...\n";
    exit(1);
}

// 1. Ler _elementor_data atual (RAW — sem stripslashes, get_post_meta retorna already-unslashed)
$data_raw = get_post_meta($POST_ID, '_elementor_data', true);
if (empty($data_raw)) {
    echo "ERRO: post {$POST_ID} sem _elementor_data\n";
    exit(1);
}

echo "_elementor_data atual: " . strlen($data_raw) . " bytes\n";

// 2. Decodificar JSON
$data = json_decode($data_raw, true);
if (!is_array($data)) {
    echo "ERRO: _elementor_data não é JSON válido (erro: " . json_last_error_msg() . ")\n";
    exit(1);
}

// 3. Construir mapa EN→PT via WPML para attachments encontrados
$en_ids = [];
$walker = function ($node) use (&$walker, &$en_ids) {
    if (!is_array($node)) return;
    // Procurar todos os campos com formato {"id":NNNN,"url":"...","source":"library"}
    array_walk_recursive($node, function ($v, $k) use (&$en_ids) {
        // Não usado: array_walk_recursive não dá pra detectar struct pai
    });
    // Em vez disso, percorrer manual
    foreach ($node as $key => $val) {
        if (is_array($val)) {
            // Detectar shape de attachment field do Elementor: tem 'id' int + 'url' string
            if (isset($val['id']) && isset($val['url']) && is_int($val['id']) && $val['id'] > 0) {
                $en_ids[$val['id']] = true;
            } else {
                $walker($val);
            }
        }
    }
};
$walker($data);

echo "IDs de attachment encontrados em _elementor_data: " . count($en_ids) . "\n";

if (empty($en_ids)) {
    echo "Nada para mapear. Encerrando.\n";
    exit(0);
}

// 4. Buscar mapeamento EN→PT no WPML
$placeholders = implode(',', array_fill(0, count($en_ids), '%d'));
$rows = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT en.element_id AS en_id, pt.element_id AS pt_id, en.trid
         FROM {$wpdb->prefix}icl_translations en
         JOIN {$wpdb->prefix}icl_translations pt
           ON pt.trid = en.trid AND pt.language_code = 'pt-br'
         WHERE en.language_code = 'en'
           AND en.element_type = 'post_attachment'
           AND en.element_id IN ($placeholders)",
        array_keys($en_ids)
    )
);

$map = [];
foreach ($rows as $r) {
    if ((int)$r->en_id !== (int)$r->pt_id) {
        $map[(int)$r->en_id] = (int)$r->pt_id;
    }
}

echo "Mapeamento EN→PT (com troca real): " . count($map) . " IDs\n";
foreach ($map as $en_id => $pt_id) {
    echo "  $en_id → $pt_id\n";
}

if (empty($map)) {
    echo "Nenhum ID precisa ser substituído (já corrigido ou todos no PT).\n";
    exit(0);
}

// 5. Validar que os IDs PT respondem corretamente a wp_get_attachment_image_src
echo "\nValidando IDs PT respondem a wp_get_attachment_image_src:\n";
$broken_pt = [];
foreach (array_unique($map) as $pt_id) {
    $src = wp_get_attachment_image_src($pt_id, 'full');
    if (!$src) {
        $broken_pt[] = $pt_id;
        echo "  ⚠️ PT $pt_id também retorna false!\n";
    }
}
if (!empty($broken_pt)) {
    echo "ERRO: " . count($broken_pt) . " IDs PT estão quebrados. Abortando.\n";
    exit(1);
}
echo "OK — todos os IDs PT resolvem corretamente.\n";

// 6. Backup do _elementor_data atual
$backup_key = '_porosidades_en_72741_backup_' . time();
if ($DRY_RUN) {
    echo "\n[DRY] Backup seria gravado em wp_2_options['$backup_key']\n";
} else {
    update_option($backup_key, $data_raw, false);
    echo "\nBackup gravado em wp_2_options['$backup_key']\n";
}

// 7. Reescrever _elementor_data trocando IDs
$replace_count = 0;
$rewriter = function (&$node) use (&$rewriter, &$map, &$replace_count) {
    if (!is_array($node)) return;
    foreach ($node as $key => &$val) {
        if (is_array($val)) {
            if (isset($val['id']) && isset($val['url']) && is_int($val['id']) && isset($map[$val['id']])) {
                $old_id = $val['id'];
                $new_id = $map[$old_id];
                $new_url = wp_get_attachment_url($new_id);
                if ($new_url) {
                    $val['id']  = $new_id;
                    $val['url'] = $new_url;
                    $replace_count++;
                }
            } else {
                $rewriter($val);
            }
        }
    }
};
$rewriter($data);

echo "Substituições aplicadas no JSON: $replace_count\n";

// 8. Reencodar e gravar (regra: wpdb->update sem wp_slash, pois data veio via get_post_meta unslashed e json_encode produz string limpa)
$new_data_raw = wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
echo "_elementor_data novo: " . strlen($new_data_raw) . " bytes\n";

if ($DRY_RUN) {
    echo "\n[DRY] update_post_meta seria chamado mas não foi.\n";
} else {
    // update_post_meta espera unslashed value (ele faz wp_slash internamente)
    $result = update_post_meta($POST_ID, '_elementor_data', wp_slash($new_data_raw));
    if ($result === false) {
        echo "ERRO: update_post_meta retornou false\n";
        exit(1);
    }
    echo "_elementor_data atualizado.\n";

    // 9. Flush Elementor CSS cache + warm-up (regra: feedback_elementor_flush_css_warmup)
    if (class_exists('\Elementor\Plugin')) {
        \Elementor\Plugin::$instance->files_manager->clear_cache();
        // Warm-up explícito (regenera post-72741.css)
        $css_file = new \Elementor\Core\Files\CSS\Post($POST_ID);
        $css_file->update();
        echo "Elementor CSS regenerado para post {$POST_ID}.\n";
    }

    // 10. WP cache flush (Redis postmeta last_changed + object cache)
    wp_cache_delete($POST_ID, 'post_meta');
    clean_post_cache($POST_ID);
    echo "Object cache invalidado para post {$POST_ID}.\n";

    // 11. WP Rocket cirúrgico
    if (function_exists('rocket_clean_post')) {
        rocket_clean_post($POST_ID);
        echo "WP Rocket cache limpo para post {$POST_ID}.\n";
    }
}

echo "\n=== Resumo ===\n";
echo "Post: 72741 (Porosidades Exposition EN)\n";
echo "IDs substituídos: $replace_count\n";
echo "Mode: " . ($DRY_RUN ? 'DRY-RUN (nada gravado)' : 'APPLIED') . "\n";
if (!$DRY_RUN) {
    echo "Backup: wp_2_options['$backup_key']\n";
    echo "Para reverter: update_post_meta(72741, '_elementor_data', wp_slash(get_option('$backup_key')))\n";
}
echo "\nValidar via: curl -sk 'https://cambrasmax.local:8484/cultura/en/porosidades/' | grep -c 'background-image:url'\n";
