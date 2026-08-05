<?php
/**
 * remove-mailerlite-action.php — v1 (2026-05-19)
 *
 * Remove o action "mailerlite" de submit_actions de TODOS os forms Elementor Pro
 * em `_elementor_data`. Mantém demais actions intactos. Adicionalmente limpa
 * todas as chaves de config mailerlite_* dentro do mesmo widget form.
 *
 * Motivação: API key MailerLite invalidou/expirou; action retorna 401
 * `Rest Client Error: response code 401` em runtime. Cliente decidiu remover
 * integração até ter nova chave configurada. Aplicado primeiro em DEV (validar
 * idempotência) depois em PROD via SSH.
 *
 * Cobertura: blog 1 + blog 2 (multisite via SHOW TABLES LIKE '%postmeta').
 * Filtra revisions (não rodam em runtime). Idempotente.
 *
 * Uso (DEV):
 *   docker cp scripts/remove-mailerlite-action.php concertacao-dev-wordpress:/tmp/
 *   docker exec -e REGULARIZE_DRY_RUN=1 -u www-data concertacao-dev-wordpress \
 *       wp --path=/var/www/html --url=https://cambrasmax.local:8484 \
 *       eval-file /tmp/remove-mailerlite-action.php
 *   # Apply real (sem dry-run):
 *   docker exec -u www-data concertacao-dev-wordpress \
 *       wp --path=/var/www/html --url=https://cambrasmax.local:8484 \
 *       eval-file /tmp/remove-mailerlite-action.php
 *
 * Uso (PROD):
 *   scp scripts/remove-mailerlite-action.php concertacaoamazonia.com.br-prod-sa:/tmp/
 *   ssh concertacaoamazonia.com.br-prod-sa "REGULARIZE_DRY_RUN=1 sudo -u www-data \
 *       wp --path=/var/www/concertacaoamazonia.com.br --url=https://concertacaoamazonia.com.br \
 *       eval-file /tmp/remove-mailerlite-action.php"
 *   # Apply:
 *   ssh concertacaoamazonia.com.br-prod-sa "sudo -u www-data \
 *       wp --path=/var/www/concertacaoamazonia.com.br --url=https://concertacaoamazonia.com.br \
 *       eval-file /tmp/remove-mailerlite-action.php"
 */

if (!defined('ABSPATH')) { exit; }

global $wpdb;

$dry_run = !empty(getenv('REGULARIZE_DRY_RUN'));

echo "=== remove-mailerlite-action.php v1 " . ($dry_run ? '[DRY-RUN]' : '[APPLY]') . " ===\n";

$tables = [];
foreach ($wpdb->get_col("SHOW TABLES LIKE '{$wpdb->prefix}%postmeta'") as $t) {
    $tables[] = $t;
}
// Multisite: também pegar wp_2_postmeta, wp_3_postmeta...
foreach ($wpdb->get_col("SHOW TABLES LIKE '{$wpdb->base_prefix}%postmeta'") as $t) {
    if (!in_array($t, $tables)) $tables[] = $t;
}
echo "Tabelas postmeta: " . implode(', ', $tables) . "\n\n";

$total_scanned = 0;
$total_changes = 0;
$report = [];

foreach ($tables as $tbl) {
    // Descobrir tabela de posts correspondente (wp_postmeta -> wp_posts, wp_2_postmeta -> wp_2_posts)
    $posts_tbl = preg_replace('/postmeta$/', 'posts', $tbl);

    // Multisite: detectar blog_id pelo prefixo da tabela e switch_to_blog antes
    // do update_post_meta. Sem isso, update_post_meta atua em wp_postmeta (blog 1)
    // mesmo quando $tbl é wp_2_postmeta, e nada persiste (não-idempotente).
    if (is_multisite() && preg_match('/^' . preg_quote($wpdb->base_prefix, '/') . '(\d+)_postmeta$/', $tbl, $m)) {
        switch_to_blog((int)$m[1]);
    } elseif (is_multisite()) {
        switch_to_blog(1);
    }

    $rows = $wpdb->get_results(
        "SELECT pm.post_id, pm.meta_value FROM {$tbl} pm
         INNER JOIN {$posts_tbl} p ON p.ID = pm.post_id
         WHERE pm.meta_key = '_elementor_data'
         AND pm.meta_value LIKE '%mailerlite%'
         AND p.post_status IN ('publish','draft','private','pending')
         AND p.post_type NOT IN ('revision')",
        ARRAY_A
    );

    foreach ($rows as $row) {
        $total_scanned++;
        $data = json_decode($row['meta_value'], true);
        if (!is_array($data)) continue;
        $changed = false;

        $walk = function (array &$nodes) use (&$walk, &$changed, &$report, $row, $tbl) {
            foreach ($nodes as &$node) {
                if (($node['widgetType'] ?? null) === 'form') {
                    $form_id = substr($node['id'] ?? '?', 0, 7);
                    $form_name = $node['settings']['form_name'] ?? '?';

                    // 1. Remover "mailerlite" de submit_actions
                    $actions = $node['settings']['submit_actions'] ?? [];
                    if (is_array($actions) && in_array('mailerlite', $actions, true)) {
                        $new_actions = array_values(array_filter($actions, fn($a) => $a !== 'mailerlite'));
                        $report[] = sprintf(
                            "  [%s] post=%d form=%s/%s  submit_actions: %s -> %s",
                            $tbl, $row['post_id'], $form_id, $form_name,
                            json_encode($actions), json_encode($new_actions)
                        );
                        $node['settings']['submit_actions'] = $new_actions;
                        $changed = true;
                    }

                    // 2. Limpar todas as chaves mailerlite_* (api_key, group, fields_map, etc)
                    $removed_keys = [];
                    foreach (array_keys($node['settings']) as $k) {
                        if (strpos($k, 'mailerlite') === 0) {
                            unset($node['settings'][$k]);
                            $removed_keys[] = $k;
                            $changed = true;
                        }
                    }
                    if (!empty($removed_keys)) {
                        $report[] = sprintf(
                            "  [%s] post=%d form=%s/%s  removed config keys: %s",
                            $tbl, $row['post_id'], $form_id, $form_name,
                            implode(',', $removed_keys)
                        );
                    }
                }
                if (!empty($node['elements']) && is_array($node['elements'])) {
                    $walk($node['elements']);
                }
            }
        };
        $walk($data);

        if ($changed && !$dry_run) {
            $new_value = wp_slash(wp_json_encode($data, JSON_UNESCAPED_UNICODE));
            update_post_meta($row['post_id'], '_elementor_data', $new_value);
            $total_changes++;
        } elseif ($changed) {
            $total_changes++;
        }
    }

    if (is_multisite()) restore_current_blog();
}

echo "\n=== RELATÓRIO ===\n";
foreach ($report as $line) echo $line . "\n";

echo "\n=== TOTAIS ===\n";
echo "Posts varridos (com mailerlite no JSON): {$total_scanned}\n";
echo "Posts modificados: {$total_changes}\n";

if ($dry_run) {
    echo "\nDRY-RUN — nenhuma mudança escrita.\n";
} elseif ($total_changes > 0) {
    echo "\n✅ APLICADO. Próximo: invalidar cache dos posts afetados e CloudFront se necessário.\n";
}
