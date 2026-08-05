<?php
/**
 * add-form-id-to-email-content.php — v1 (2026-05-19)
 *
 * Adiciona marcador `[Form: <name> | ID: <form_id> | Post: <post_id>]` ao final
 * de `email_content` e `email_content_2` de todos os widgets form Elementor Pro
 * em `_elementor_data`. Permite identificar nos emails recebidos qual form/página
 * disparou — facilita diagnóstico quando vários forms compartilham mesmo destino.
 *
 * Idempotente: se o marcador já está presente, não duplica. Skip se email_content
 * vazio (form sem action email não precisa). Filtra revisions.
 *
 * Cobertura: blogs 1 + 2 do multisite.
 *
 * Uso (DEV):
 *   docker cp scripts/add-form-id-to-email-content.php concertacao-dev-wordpress:/tmp/
 *   docker exec -e REGULARIZE_DRY_RUN=1 -u www-data concertacao-dev-wordpress \
 *       wp --path=/var/www/html --url=https://cambrasmax.local:8484 \
 *       eval-file /tmp/add-form-id-to-email-content.php
 *   # Apply:
 *   docker exec -u www-data concertacao-dev-wordpress \
 *       wp --path=/var/www/html --url=https://cambrasmax.local:8484 \
 *       eval-file /tmp/add-form-id-to-email-content.php
 *
 * Uso (PROD):
 *   scp scripts/add-form-id-to-email-content.php concertacaoamazonia.com.br-prod-sa:/tmp/
 *   ssh concertacaoamazonia.com.br-prod-sa "sudo -u www-data \
 *       wp --path=/var/www/concertacaoamazonia.com.br --url=https://concertacaoamazonia.com.br \
 *       eval-file /tmp/add-form-id-to-email-content.php"
 */

if (!defined('ABSPATH')) { exit; }

global $wpdb;

$dry_run = !empty(getenv('REGULARIZE_DRY_RUN'));
$MARKER_PREFIX = "\n\n---\n[BIT-Smoke-ID]";  // assinatura única, fácil de detectar idempotência

echo "=== add-form-id-to-email-content.php v1 " . ($dry_run ? '[DRY-RUN]' : '[APPLY]') . " ===\n";

// Multisite blogs
$blogs = is_multisite() ? get_sites(['fields' => 'ids']) : [get_current_blog_id()];

$total_scanned = 0;
$total_changes = 0;
$report = [];

foreach ($blogs as $bid) {
    if (is_multisite()) switch_to_blog($bid);
    $tbl = $wpdb->postmeta;
    $posts_tbl = $wpdb->posts;

    $rows = $wpdb->get_results(
        "SELECT pm.post_id, pm.meta_value FROM {$tbl} pm
         INNER JOIN {$posts_tbl} p ON p.ID = pm.post_id
         WHERE pm.meta_key = '_elementor_data'
         AND pm.meta_value LIKE '%widgetType%form%'
         AND pm.meta_value LIKE '%email_content%'
         AND p.post_status IN ('publish','draft','private','pending')
         AND p.post_type NOT IN ('revision')",
        ARRAY_A
    );

    foreach ($rows as $row) {
        $total_scanned++;
        $data = json_decode($row['meta_value'], true);
        if (!is_array($data)) continue;
        $changed = false;

        $walk = function (array &$nodes) use (&$walk, &$changed, &$report, $row, $bid, $MARKER_PREFIX) {
            foreach ($nodes as &$node) {
                if (($node['widgetType'] ?? null) === 'form') {
                    $form_id = $node['id'] ?? '?';
                    $form_name = $node['settings']['form_name'] ?? '?';
                    // Marker incluindo identificadores rastreáveis
                    $marker = sprintf(
                        "%s Form='%s' | ID=%s | Post=%d | Blog=%d",
                        $MARKER_PREFIX,
                        addslashes($form_name),
                        $form_id,
                        $row['post_id'],
                        $bid
                    );

                    foreach (['email_content', 'email_content_2'] as $key) {
                        $cur = $node['settings'][$key] ?? null;
                        if (!is_string($cur) || $cur === '') continue;
                        // Idempotência: se marker já está no conteúdo, skip
                        if (strpos($cur, $MARKER_PREFIX) !== false) continue;
                        // Append marker ao final
                        $node['settings'][$key] = $cur . $marker;
                        $report[] = sprintf(
                            "  blog=%d post=%d form=%s/%s field=%s — marker adicionado",
                            $bid, $row['post_id'], substr($form_id, 0, 8), $form_name, $key
                        );
                        $changed = true;
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
echo "Posts varridos: {$total_scanned}\n";
echo "Posts modificados: {$total_changes}\n";

if ($dry_run) {
    echo "\nDRY-RUN — nenhuma mudança escrita.\n";
} elseif ($total_changes > 0) {
    echo "\n✅ APLICADO. Próximo: re-rodar (esperado 0 changes) + invalidar cache dos posts afetados.\n";
}
