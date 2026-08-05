<?php
// Fix 2 — Listing "Outras publicações da Concertação" (página /publicacoes/, ID 72684)
//
// Problema: widget jet-listing-grid id=88a284f referencia custom_query_id=57
// (JetEngine Query Builder), mas o módulo "query-builder" NÃO está ativo no
// JetEngine — então a query nunca resolve e o listing fica em loading infinito.
//
// Solução: remover as flags custom_query/custom_query_id para que o widget
// use o posts_query inline já definido nas settings (meta_query arquivo-do-estudo
// + tax_query veiculo=822 + Destaque filter).

$post_id = 72684;
$widget_id = '88a284f';

$data_raw = get_post_meta($post_id, '_elementor_data', true);
if (!$data_raw) { echo "ERROR: no _elementor_data\n"; return; }

echo "BEFORE size: " . strlen($data_raw) . "\n";

$data = json_decode($data_raw, true);
if (!is_array($data)) { echo "ERROR: invalid JSON\n"; return; }

function patch_widget(&$elements, $target_id, &$found, &$before, &$after) {
    foreach ($elements as &$el) {
        if (isset($el['id']) && $el['id'] === $target_id) {
            $before = $el['settings'] ?? [];
            unset($el['settings']['custom_query']);
            unset($el['settings']['custom_query_id']);
            $after = $el['settings'] ?? [];
            $found = true;
            return;
        }
        if (!empty($el['elements'])) {
            patch_widget($el['elements'], $target_id, $found, $before, $after);
            if ($found) return;
        }
    }
}

$found = false; $before = []; $after = [];
patch_widget($data, $widget_id, $found, $before, $after);

if (!$found) { echo "ERROR: widget $widget_id not found\n"; return; }

echo "Widget found.\n";
echo "BEFORE custom_query: " . var_export($before['custom_query'] ?? null, true) . "\n";
echo "BEFORE custom_query_id: " . var_export($before['custom_query_id'] ?? null, true) . "\n";
echo "AFTER custom_query: " . var_export($after['custom_query'] ?? '<UNSET>', true) . "\n";
echo "AFTER custom_query_id: " . var_export($after['custom_query_id'] ?? '<UNSET>', true) . "\n";

$new_raw = wp_json_encode($data);
echo "AFTER size: " . strlen($new_raw) . "\n";

if (getenv('APPLY') !== '1') {
    echo "\nDRY-RUN. Set APPLY=1 to apply.\n";
    return;
}

// update_post_meta para _elementor_data EXIGE wp_slash(wp_json_encode())
// (ver feedback_elementor_data_wp_slash_required.md em memory)
$ok = update_post_meta($post_id, '_elementor_data', wp_slash($new_raw));
echo $ok ? "OK: _elementor_data atualizado\n" : "ERROR: update_post_meta failed (mesmo valor?)\n";

// Validate persistencia
$check = get_post_meta($post_id, '_elementor_data', true);
$decoded = json_decode($check, true);
function check_widget($els, $tid) {
    foreach ($els as $el) {
        if (isset($el['id']) && $el['id'] === $tid) return $el['settings'] ?? [];
        if (!empty($el['elements'])) { $r = check_widget($el['elements'], $tid); if ($r) return $r; }
    }
    return null;
}
$got = check_widget($decoded, $widget_id);
echo "POST-WRITE custom_query: " . var_export($got['custom_query'] ?? '<UNSET>', true) . "\n";
echo "POST-WRITE custom_query_id: " . var_export($got['custom_query_id'] ?? '<UNSET>', true) . "\n";

if (function_exists('wp_cache_flush')) wp_cache_flush();
if (class_exists('\Elementor\Plugin')) {
    \Elementor\Plugin::$instance->files_manager->clear_cache();
    // Regenerate this specific page's CSS
    $css_file = new \Elementor\Core\Files\CSS\Post($post_id);
    $css_file->update();
    echo "Elementor cache cleared + CSS regenerated for post $post_id\n";
}
