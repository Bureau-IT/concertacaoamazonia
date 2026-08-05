<?php
/**
 * regularize-form-emails.php — v1
 *
 * Regulariza emails inválidos em campos do Elementor Pro Forms que estão
 * armazenados em `_elementor_data` (postmeta). Detecta:
 *
 *   - email_from / email_from_2 / email_to / email_to_2 / email_to_cc / email_reply_to /
 *     email_reply_to_2 contendo:
 *       - `:8484` ou `:8484` (port DEV vazada — bug confirmado prod 2026-05-18 21:56 BRT
 *         no form "Footer do Site" post 72234: email_from_2=email@concertacaoamazonia.com.br:8484
 *         quebrando submit do Newsletter footer com `success:false`)
 *       - hostname cambrasmax.local (DEV vazando)
 *       - qualquer string que `is_email()` rejeita
 *
 * Estratégia de fix:
 *   - email_from / email_from_2 → substituir POR `noreply@concertacaoamazonia.com.br`
 *     (canonical; combinado com Daniel 2026-05-18 22:00 BRT)
 *   - email_to / email_to_cc / email_reply_to → strip port `:8484`, trocar
 *     `cambrasmax.local` → `concertacaoamazonia.com.br`
 *   - Se ainda assim inválido, REPORTAR (não silenciar) e pular
 *
 * Uso:
 *   # DEV (local docker):
 *   docker cp scripts/regularize-form-emails.php concertacao-dev-wordpress:/tmp/
 *   docker exec -e REGULARIZE_DRY_RUN=1 -u www-data concertacao-dev-wordpress \
 *       wp --path=/var/www/html --url=https://cambrasmax.local:8484 \
 *       eval-file /tmp/regularize-form-emails.php
 *   # Apply real (sem dry-run):
 *   docker exec -u www-data concertacao-dev-wordpress \
 *       wp --path=/var/www/html --url=https://cambrasmax.local:8484 \
 *       eval-file /tmp/regularize-form-emails.php
 *
 *   # PROD (via SSH):
 *   scp scripts/regularize-form-emails.php concertacaoamazonia.com.br-prod-sa:/tmp/
 *   ssh concertacaoamazonia.com.br-prod-sa "REGULARIZE_DRY_RUN=1 sudo -u www-data \
 *       wp --path=/var/www/concertacaoamazonia.com.br --url=https://concertacaoamazonia.com.br \
 *       eval-file /tmp/regularize-form-emails.php"
 *   # Apply real (sem dry-run):
 *   ssh concertacaoamazonia.com.br-prod-sa "sudo -u www-data \
 *       wp --path=/var/www/concertacaoamazonia.com.br --url=https://concertacaoamazonia.com.br \
 *       eval-file /tmp/regularize-form-emails.php"
 *
 *   # Restringir a 1 post específico:
 *   REGULARIZE_POST_ID=72234 wp eval-file ...
 *
 * Multisite: o script varre wp_postmeta E wp_2_postmeta (blog 1 + blog 2).
 * Idempotente — re-rodar não muda nada se já está limpo.
 */

if (!defined('ABSPATH')) { exit; }

global $wpdb;

// ============================================================
// CONFIG
// ============================================================
$dry_run     = !empty(getenv('REGULARIZE_DRY_RUN'));
$only_post   = (int) (getenv('REGULARIZE_POST_ID') ?: 0);
$canonical_from = 'noreply@concertacaoamazonia.com.br';

// Campos onde validamos email (todos do Elementor Pro Forms email action)
$email_from_fields = ['email_from', 'email_from_2'];
$email_addr_fields = ['email_to', 'email_to_2', 'email_to_cc', 'email_reply_to', 'email_reply_to_2'];

// ============================================================
// HELPERS
// ============================================================
$normalize_addr = function (string $raw) : string {
    // Remove `:PORT` (1-5 digits) que vaza de hostname dev
    $clean = preg_replace('/(:\d{1,5})(?=$|,|;|\s)/', '', $raw);
    // Troca cambrasmax.local → concertacaoamazonia.com.br
    $clean = str_replace('cambrasmax.local', 'concertacaoamazonia.com.br', $clean);
    return $clean;
};

$is_email_invalid = function (string $addr) : bool {
    // Lista pode ter múltiplos emails separados por vírgula
    foreach (preg_split('/[,;]/', $addr) as $one) {
        $one = trim($one);
        if ($one === '') continue;
        // Placeholders do Elementor Pro Forms são válidos em runtime (substituídos
        // antes do send): {admin_email}, [field id="email"], {site_email}, etc.
        // Whitelistar para não classificar como inválido.
        if (preg_match('/^\{[a-z_]+\}$/i', $one)) continue;       // {admin_email}, {site_email}
        if (preg_match('/^\[field\s+id=/i', $one))  continue;     // [field id="email"]
        if (!is_email($one)) return true;
    }
    return false;
};

// ============================================================
// VARREDURA
// ============================================================
$tables = [];
foreach ($wpdb->get_col("SHOW TABLES LIKE '{$wpdb->prefix}%postmeta'") as $t) {
    // Inclui wp_postmeta, wp_2_postmeta, etc.
    $tables[] = $t;
}

echo "=== regularize-form-emails.php v1 " . ($dry_run ? '[DRY-RUN]' : '[APPLY]') . " ===\n";
echo "Tabelas postmeta: " . implode(', ', $tables) . "\n";
if ($only_post) echo "Restrito ao post_id={$only_post}\n";
echo "\n";

$total_posts_scanned = 0;
$total_forms_found   = 0;
$total_fixes         = 0;
$total_unfixable     = 0;
$report              = [];

foreach ($tables as $tbl) {
    $where_post = $only_post ? "AND post_id = {$only_post}" : '';
    $rows = $wpdb->get_results(
        "SELECT post_id, meta_value FROM {$tbl}
         WHERE meta_key = '_elementor_data' AND meta_value LIKE '%widgetType%form%' {$where_post}",
        ARRAY_A
    );
    if (!$rows) continue;

    foreach ($rows as $row) {
        $total_posts_scanned++;
        $post_id = (int) $row['post_id'];
        $raw     = $row['meta_value'];

        // `get_results()` retorna RAW (sem stripslashes). Decode direto.
        $data = json_decode($raw, true);
        if (!is_array($data)) continue;

        $changed = false;

        // Walker recursivo: procura widgetType=form em qualquer profundidade
        $walk = function (array &$nodes) use (
            &$walk, &$changed, &$total_forms_found, &$total_fixes, &$total_unfixable,
            $email_from_fields, $email_addr_fields, $normalize_addr, $is_email_invalid,
            $canonical_from, $post_id, $tbl, &$report
        ) {
            foreach ($nodes as &$node) {
                if (($node['widgetType'] ?? null) === 'form') {
                    $total_forms_found++;
                    $form_id = $node['id'] ?? '?';
                    $form_name = $node['settings']['form_name'] ?? '?';

                    // email_from + email_from_2: forçar canonical se inválido
                    foreach ($email_from_fields as $fld) {
                        $cur = $node['settings'][$fld] ?? null;
                        if ($cur === null || $cur === '') continue;
                        if ($is_email_invalid($cur)) {
                            $report[] = sprintf(
                                "  [%s] post=%d form=%s/%s  %s: %s  ->  %s",
                                $tbl, $post_id, $form_id, $form_name, $fld, $cur, $canonical_from
                            );
                            $node['settings'][$fld] = $canonical_from;
                            $changed = true;
                            $total_fixes++;
                        }
                    }

                    // email_to / cc / reply_to: tentar normalizar (strip port, trocar hostname)
                    foreach ($email_addr_fields as $fld) {
                        $cur = $node['settings'][$fld] ?? null;
                        if ($cur === null || $cur === '') continue;
                        if (!$is_email_invalid($cur)) continue;
                        $fixed = $normalize_addr($cur);
                        if ($is_email_invalid($fixed)) {
                            $report[] = sprintf(
                                "  [%s] post=%d form=%s/%s  %s: %s  -> ❌ UNFIXABLE (não é email válido após normalize)",
                                $tbl, $post_id, $form_id, $form_name, $fld, $cur
                            );
                            $total_unfixable++;
                            continue;
                        }
                        $report[] = sprintf(
                            "  [%s] post=%d form=%s/%s  %s: %s  ->  %s",
                            $tbl, $post_id, $form_id, $form_name, $fld, $cur, $fixed
                        );
                        $node['settings'][$fld] = $fixed;
                        $changed = true;
                        $total_fixes++;
                    }
                }
                if (!empty($node['elements']) && is_array($node['elements'])) {
                    $walk($node['elements']);
                }
            }
        };
        $walk($data);

        if ($changed && !$dry_run) {
            // RAW retornado precisa de wp_slash() no UPDATE para preservar backslashes do JSON
            // (lição feedback_wpdb_get_results_no_wp_slash.md — wp_slash() necessário aqui
            //  porque vamos passar via update_post_meta que faz stripslashes)
            $new_value = wp_slash(wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $r = update_post_meta($post_id, '_elementor_data', $new_value);
            if ($r === false) {
                echo "❌ ERRO update_post_meta post={$post_id} tabela={$tbl}\n";
            }
        }
    }
}

echo "\n=== RELATÓRIO ===\n";
foreach ($report as $line) echo $line . "\n";

echo "\n=== TOTAIS ===\n";
echo "Posts varridos: {$total_posts_scanned}\n";
echo "Forms encontrados: {$total_forms_found}\n";
echo "Fixes aplicados: {$total_fixes}\n";
echo "Unfixable (precisa intervenção manual): {$total_unfixable}\n";

if ($dry_run) {
    echo "\nDRY-RUN — nenhuma mudança escrita. Re-rode SEM REGULARIZE_DRY_RUN=1 para aplicar.\n";
} else if ($total_fixes > 0) {
    echo "\n✅ APLICADO. Próximos passos:\n";
    echo "  1. Limpar cache do post afetado:\n";
    echo "     wp eval 'rocket_clean_post(<POST_ID>);'\n";
    echo "  2. Em PROD: invalidar CloudFront cirúrgico do path do form (raiz da home se for footer).\n";
    echo "  3. Re-rodar /smoke para validar submit_ok=true.\n";
}
