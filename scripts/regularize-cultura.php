<?php
/**
 * regularize-cultura.php — v2 (auditado por 5 agentes, correções aplicadas)
 *
 * Regulariza o blog 2 (/cultura/) do concertacao multisite eliminando dependências
 * cross-blog que quebram exports standalone do /cultura/.
 *
 * O que faz:
 *   1. Migra attachments cross-blog (existem em wp_posts blog 1 mas não em wp_2_posts):
 *      - Reusa ID original quando livre ou ocupado por revision (deletada)
 *      - Atribui ID novo + reescreve referências quando há conflito real (post artistas)
 *   2. Migra popup elementor_library cross-blog (87319 - QR Code) com taxonomia
 *   3. Registra todos os items migrados em wp_2_icl_translations (WPML)
 *   4. Invalida caches: _elementor_element_cache global, _elementor_css global,
 *      Elementor file cache, WP Rocket (page+min+used-css), Redis
 *
 * O que NÃO faz (deliberado):
 *   - Não toca URLs absolutas (concertacao.bureau-it.com): isso é responsabilidade
 *     do export standalone (`std export-db --standalone-blog=2`, a ser implementado)
 *     porque search-replace SQL direto quebra serialização PHP. Em prod as URLs
 *     funcionam normalmente; só no destino standalone precisam ser remapeadas.
 *
 * Uso:
 *   docker exec -e REGULARIZE_DRY_RUN=1 -u www-data CONTAINER \
 *       wp eval-file /tmp/regularize-cultura.php --path=/var/www/html
 *
 *   # Apply real (sem REGULARIZE_DRY_RUN):
 *   docker exec -u www-data CONTAINER \
 *       wp eval-file /tmp/regularize-cultura.php --path=/var/www/html
 *
 * Flags via env:
 *   REGULARIZE_DRY_RUN=1     Não modifica nada
 *   REGULARIZE_SKIP_ATTACHMENTS=1
 *   REGULARIZE_SKIP_POPUPS=1
 *   REGULARIZE_SKIP_WPML=1
 *
 * Idempotente — pode rodar múltiplas vezes; só age sobre o que ainda está pendente.
 * Em caso de erro, a transação inteira faz ROLLBACK (atomicidade).
 */

if (!defined('ABSPATH')) { exit; }

global $wpdb;

// ============================================================
// CONFIG / FLAGS
// ============================================================
$dry_run          = !empty(getenv('REGULARIZE_DRY_RUN'));
$skip_attachments = !empty(getenv('REGULARIZE_SKIP_ATTACHMENTS'));
$skip_popups      = !empty(getenv('REGULARIZE_SKIP_POPUPS'));
$skip_wpml        = !empty(getenv('REGULARIZE_SKIP_WPML'));

$BLOG_2_ID = 2;
$DEFAULT_LANG = 'pt-br';

function bit_log($msg, $color = '') {
    $colors = ['ok' => "\033[32m", 'warn' => "\033[33m", 'err' => "\033[31m", 'info' => "\033[36m", 'reset' => "\033[0m"];
    $c = $colors[$color] ?? '';
    $r = $color ? $colors['reset'] : '';
    echo $c . $msg . $r . "\n";
}

function bit_die($msg) {
    bit_log("FATAL: $msg", 'err');
    exit(1);
}

// Checagem que retorna ID do INSERT bem-sucedido ou aborta com rollback
function bit_insert($table, $data, $context = '') {
    global $wpdb;
    $r = $wpdb->insert($table, $data);
    if ($r === false) {
        $wpdb->query('ROLLBACK');
        bit_die("INSERT em $table falhou ($context): " . $wpdb->last_error);
    }
    return $wpdb->insert_id;
}

function bit_update($table, $data, $where, $context = '') {
    global $wpdb;
    $r = $wpdb->update($table, $data, $where);
    if ($r === false) {
        $wpdb->query('ROLLBACK');
        bit_die("UPDATE em $table falhou ($context): " . $wpdb->last_error);
    }
    return $r;
}

// Insere/registra attachment em wp_2_icl_translations com trid local novo.
// Idempotente: se já existir entry, retorna trid existente.
function bit_register_wpml_blog2($element_id, $element_type, $language_code) {
    global $wpdb, $dry_run;
    // Já existe?
    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT trid FROM wp_2_icl_translations WHERE element_id=%d AND element_type=%s",
        $element_id, $element_type
    ), ARRAY_A);
    if ($existing) return (int) $existing['trid'];

    if ($dry_run) return 0;

    // Gerar trid novo (max + 1) — escopo do blog 2
    $next_trid = (int) $wpdb->get_var("SELECT COALESCE(MAX(trid), 0) + 1 FROM wp_2_icl_translations");

    $r = $wpdb->insert('wp_2_icl_translations', [
        'element_type'         => $element_type,
        'element_id'           => $element_id,
        'trid'                 => $next_trid,
        'language_code'        => $language_code,
        'source_language_code' => null, // origem; nova entry standalone
    ]);
    if ($r === false) {
        $wpdb->query('ROLLBACK');
        bit_die("INSERT wp_2_icl_translations falhou (id=$element_id type=$element_type): " . $wpdb->last_error);
    }
    return $next_trid;
}

bit_log("=== regularize-cultura.php v2 ===", 'info');
bit_log("Mode: " . ($dry_run ? 'DRY-RUN' : 'APPLY'), $dry_run ? 'warn' : 'ok');
echo "\n";

// ============================================================
// PRÉ-CHECKS
// ============================================================
bit_log("--- Pré-checks ---", 'info');

// Verificar tabelas necessárias
$required_tables = ['wp_posts', 'wp_postmeta', 'wp_2_posts', 'wp_2_postmeta', 'wp_2_icl_translations'];
foreach ($required_tables as $t) {
    $exists = $wpdb->get_var("SHOW TABLES LIKE '$t'");
    if (!$exists) bit_die("Tabela ausente: $t");
}
bit_log("  ✓ Tabelas OK", 'ok');

// Idioma default WPML do blog 2
$wpml_lang = $wpdb->get_var("SELECT option_value FROM wp_2_options WHERE option_name='WPML_LANGUAGE_NEGOTIATION_TYPE_default_language' LIMIT 1");
if ($wpml_lang) {
    $DEFAULT_LANG = $wpml_lang;
}
bit_log("  ✓ WPML default lang blog 2: $DEFAULT_LANG", 'ok');
echo "\n";

// ============================================================
// INICIAR TRANSAÇÃO
// ============================================================
if (!$dry_run) {
    $wpdb->query('SET autocommit=0');
    $wpdb->query('START TRANSACTION');
    bit_log("✓ Transação iniciada", 'ok');
}

try {

// ============================================================
// 1. ATTACHMENTS CROSS-BLOG
// ============================================================
$remap = []; // old_id => new_id (apenas para conflitos)
$migrated_attachments = []; // ids efetivos no blog 2 (para WPML)

if (!$skip_attachments) {
    bit_log("--- 1. Migrando attachments cross-blog ---", 'info');

    // Coletar IDs de _thumbnail_id
    $thumb_ids = $wpdb->get_col("
        SELECT DISTINCT m.meta_value
        FROM wp_2_postmeta m
        WHERE m.meta_key='_thumbnail_id'
          AND m.meta_value REGEXP '^[0-9]+$'
          AND NOT EXISTS (SELECT 1 FROM wp_2_posts a WHERE a.ID=m.meta_value AND a.post_type='attachment')
          AND EXISTS (SELECT 1 FROM wp_posts a1 WHERE a1.ID=m.meta_value AND a1.post_type='attachment')
    ");

    // Coletar IDs em _elementor_data
    $elem_rows = $wpdb->get_results("
        SELECT meta_value FROM wp_2_postmeta WHERE meta_key='_elementor_data'
    ", ARRAY_A);
    $elem_ids = [];
    foreach ($elem_rows as $r) {
        preg_match_all('/"id":(\d+)/', $r['meta_value'], $m);
        foreach ($m[1] ?? [] as $id) $elem_ids[(int)$id] = true;
        // Variant string: "id":"NUM"
        preg_match_all('/"id":"(\d+)"/', $r['meta_value'], $m);
        foreach ($m[1] ?? [] as $id) $elem_ids[(int)$id] = true;
    }
    $ids_str = implode(',', array_map('intval', array_keys($elem_ids))) ?: '0';
    $elem_cross = $wpdb->get_col("
        SELECT a1.ID FROM wp_posts a1
        WHERE a1.ID IN ($ids_str) AND a1.post_type='attachment'
          AND NOT EXISTS (SELECT 1 FROM wp_2_posts a2 WHERE a2.ID=a1.ID AND a2.post_type='attachment')
    ");

    // JetEngine meta keys que armazenam attachment IDs diretamente
    $je_keys = ['audio-1', 'audio-2', 'foto-do-artista'];
    $je_cross = [];
    foreach ($je_keys as $key) {
        $tmp = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT m.meta_value
            FROM wp_2_postmeta m
            WHERE m.meta_key=%s
              AND m.meta_value REGEXP '^[0-9]+$'
              AND NOT EXISTS (SELECT 1 FROM wp_2_posts a WHERE a.ID=m.meta_value AND a.post_type='attachment')
              AND EXISTS (SELECT 1 FROM wp_posts a1 WHERE a1.ID=m.meta_value AND a1.post_type='attachment')
        ", $key));
        $je_cross = array_merge($je_cross, $tmp);
    }

    $all_cross = array_unique(array_map('intval', array_merge($thumb_ids, $elem_cross, $je_cross)));
    sort($all_cross, SORT_NUMERIC);

    bit_log("  Total attachments cross-blog: " . count($all_cross), 'info');

    // Idempotência: detectar tombstones (rewrites de runs anteriores) — para skipar IDs já remapeados
    // Se algum ID original ainda aparece em meta blog 2, é porque o rewrite anterior falhou. Vou detectar.
    // Estratégia: para cada ID na lista, verificar se há "tombstone meta" indicando remap prévio.
    // Tombstone = entrada em wp_2_options com option_name='regularize_remap_<old>' valor=<new>
    $tombstones = [];
    if (!$dry_run || true) { // sempre ler para idempotência
        $rows = $wpdb->get_results("SELECT option_name, option_value FROM wp_2_options WHERE option_name LIKE 'regularize_remap_%'", ARRAY_A);
        foreach ($rows as $r) {
            $old = (int) substr($r['option_name'], strlen('regularize_remap_'));
            $tombstones[$old] = (int) $r['option_value'];
        }
    }
    if ($tombstones) bit_log("  Tombstones de runs anteriores: " . count($tombstones), 'info');

    // Classificar
    $reusable = [];
    $conflicts = [];
    foreach ($all_cross as $id) {
        // Se já tem tombstone, pular (já foi remapeado)
        if (isset($tombstones[$id])) continue;

        $existing = $wpdb->get_row("SELECT ID, post_type FROM wp_2_posts WHERE ID=$id", ARRAY_A);
        if (!$existing || $existing['post_type'] === 'revision') {
            $reusable[] = $id;
        } else {
            $conflicts[] = ['id' => $id, 'type' => $existing['post_type']];
        }
    }
    bit_log("    OK reuse (livre/revision): " . count($reusable), 'ok');
    bit_log("    Conflito (precisa remap): " . count($conflicts), count($conflicts) ? 'warn' : 'ok');

    // === Aplicar reuse ===
    $migrated = 0;
    $rev_deleted = 0;
    foreach ($reusable as $id) {
        $existing = $wpdb->get_row("SELECT post_type FROM wp_2_posts WHERE ID=$id", ARRAY_A);
        if ($existing && $existing['post_type'] === 'revision') {
            if (!$dry_run) {
                $r = $wpdb->delete('wp_2_posts', ['ID' => $id]);
                if ($r === false) { $wpdb->query('ROLLBACK'); bit_die("DELETE revision $id falhou: " . $wpdb->last_error); }
                $wpdb->delete('wp_2_postmeta', ['post_id' => $id]);
            }
            $rev_deleted++;
        }

        $post = $wpdb->get_row("SELECT * FROM wp_posts WHERE ID=$id", ARRAY_A);
        if (!$post) { bit_log("    skip $id (não existe em wp_posts)", 'warn'); continue; }
        $meta = $wpdb->get_results("SELECT meta_key, meta_value FROM wp_postmeta WHERE post_id=$id", ARRAY_A);

        if (!$dry_run) {
            // Aplicar wp_slash em campos textuais (post_content tem JSON Elementor em alguns casos)
            $insert_data = $post;
            // Remover ID se for AUTO_INCREMENT? Não, queremos preservar.
            bit_insert('wp_2_posts', $insert_data, "attachment $id");

            foreach ($meta as $m) {
                bit_insert('wp_2_postmeta', [
                    'post_id'    => $id,
                    'meta_key'   => $m['meta_key'],
                    'meta_value' => $m['meta_value'],
                ], "postmeta $id");
            }
        }
        $migrated_attachments[] = $id;
        $migrated++;
    }
    bit_log("  ✓ Migrados (mesmo ID): $migrated | revisions deletadas: $rev_deleted", 'ok');

    // === Aplicar conflitos (ID novo + rewrite + tombstone) ===
    $conflict_migrated = 0;
    if ($conflicts) {
        $max_id = (int) $wpdb->get_var("SELECT MAX(ID) FROM wp_2_posts");
        $next = $max_id + 1;

        foreach ($conflicts as $c) {
            $old_id = $c['id'];
            $new_id = $next++;

            $post = $wpdb->get_row("SELECT * FROM wp_posts WHERE ID=$old_id", ARRAY_A);
            $meta = $wpdb->get_results("SELECT meta_key, meta_value FROM wp_postmeta WHERE post_id=$old_id", ARRAY_A);
            $post['ID'] = $new_id;
            $post['post_name'] = ($post['post_name'] ?? '') . '-' . $new_id;
            $post['guid'] = '/cultura/?attachment_id=' . $new_id;

            if (!$dry_run) {
                bit_insert('wp_2_posts', $post, "attachment conflict {$old_id}-to-{$new_id}");
                foreach ($meta as $m) {
                    bit_insert('wp_2_postmeta', [
                        'post_id'    => $new_id,
                        'meta_key'   => $m['meta_key'],
                        'meta_value' => $m['meta_value'],
                    ], "postmeta conflict $new_id");
                }
                // Tombstone para idempotência futura
                update_blog_option(2, "regularize_remap_$old_id", $new_id);
            }

            $remap[$old_id] = $new_id;
            $migrated_attachments[] = $new_id;
            $conflict_migrated++;
            bit_log("    conflito: $old_id (type={$c['type']}) → novo $new_id", 'info');
        }
    }
    bit_log("  ✓ Migrados (ID novo): $conflict_migrated", 'ok');

    // === Rewrites para os IDs em conflito ===
    if ($remap) {
        bit_log("  Rewriting referências (IDs em conflito)...", 'info');
        $rewrites = 0;

        // 1. _thumbnail_id direto (e outros meta_keys com ID puro)
        $direct_keys = ['_thumbnail_id', 'audio-1', 'audio-2', 'foto-do-artista'];
        foreach ($remap as $old => $new) {
            foreach ($direct_keys as $key) {
                if (!$dry_run) {
                    $r = bit_update('wp_2_postmeta',
                        ['meta_value' => (string)$new],
                        ['meta_key' => $key, 'meta_value' => (string)$old],
                        "$key {$old}-to-{$new}");
                    $rewrites += (int)$r;
                }
            }
        }

        // 2. _elementor_data — substituir "id":OLD por "id":NEW (json int e string)
        // Pegar TODOS os posts com qualquer ID em conflito
        $like_clauses = [];
        foreach (array_keys($remap) as $old) {
            $like_clauses[] = "meta_value LIKE '%\"id\":$old,%'";
            $like_clauses[] = "meta_value LIKE '%\"id\":$old}%'";
            $like_clauses[] = "meta_value LIKE '%\"id\":\"$old\"%'";
        }
        $rows = $wpdb->get_results(
            "SELECT post_id, meta_value FROM wp_2_postmeta
             WHERE meta_key='_elementor_data'
               AND (" . implode(' OR ', $like_clauses) . ")",
            ARRAY_A
        );
        foreach ($rows as $r) {
            $orig = $r['meta_value'];
            $new_val = $orig;
            foreach ($remap as $old => $new) {
                // int: "id":91670, e "id":91670}
                $new_val = preg_replace('/"id":' . $old . '(?=[,}])/', '"id":' . $new, $new_val);
                // string: "id":"91670"
                $new_val = preg_replace('/"id":"' . $old . '"/', '"id":"' . $new . '"', $new_val);
            }
            if ($new_val !== $orig) {
                if (!$dry_run) {
                    // IMPORTANTE: $wpdb->get_results retorna RAW (sem stripslashes_deep),
                    // então $new_val mantém os escapes originais. $wpdb->update via
                    // ['meta_value' => $new_val] faz prepare correto (escapa SQL apenas).
                    // NÃO usar wp_slash aqui — duplicaria backslashes em JSON Elementor.
                    // (Bug detectado no teste 3: wp_slash gerava "id":"\"...\"" duplo)
                    bit_update('wp_2_postmeta',
                        ['meta_value' => $new_val],
                        ['post_id' => $r['post_id'], 'meta_key' => '_elementor_data'],
                        "_elementor_data post {$r['post_id']}");
                    delete_post_meta($r['post_id'], '_elementor_element_cache');
                }
                $rewrites++;
            }
        }

        // 3. post_content e post_excerpt: padrões inline HTML wp-image-OLD, attachment_OLD
        foreach ($remap as $old => $new) {
            // wp-image-NN é classe do <img> Gutenberg/clássico
            // attachment_NN aparece em alguns shortcodes de gallery
            $rows_pc = $wpdb->get_results($wpdb->prepare(
                "SELECT ID, post_content, post_excerpt FROM wp_2_posts
                 WHERE post_content LIKE %s OR post_content LIKE %s
                    OR post_excerpt LIKE %s OR post_excerpt LIKE %s",
                "%wp-image-$old%", "%attachment_$old%",
                "%wp-image-$old%", "%attachment_$old%"
            ), ARRAY_A);
            foreach ($rows_pc as $row) {
                $new_content = preg_replace(
                    ['/wp-image-' . $old . '\b/', '/attachment_' . $old . '\b/'],
                    ['wp-image-' . $new, 'attachment_' . $new],
                    $row['post_content']
                );
                $new_excerpt = preg_replace(
                    ['/wp-image-' . $old . '\b/', '/attachment_' . $old . '\b/'],
                    ['wp-image-' . $new, 'attachment_' . $new],
                    $row['post_excerpt']
                );
                if ($new_content !== $row['post_content'] || $new_excerpt !== $row['post_excerpt']) {
                    if (!$dry_run) {
                        // $wpdb->get_results retorna raw — não aplicar wp_slash novamente
                        bit_update('wp_2_posts',
                            ['post_content' => $new_content, 'post_excerpt' => $new_excerpt],
                            ['ID' => $row['ID']],
                            "post_content {$old}-to-{$new} in post {$row['ID']}");
                    }
                    $rewrites++;
                }
            }
        }

        bit_log("  ✓ Rewrites: $rewrites", 'ok');
    }
    echo "\n";
}

// ============================================================
// 2. POPUPS CROSS-BLOG
// ============================================================
$migrated_popups = []; // ids para WPML
if (!$skip_popups) {
    bit_log("--- 2. Migrando popups Elementor cross-blog ---", 'info');

    $rows = $wpdb->get_results("
        SELECT post_id, meta_value FROM wp_2_postmeta
        WHERE meta_key='_elementor_data'
          AND meta_value REGEXP '%22popup%22%3A%22[0-9]+%22'
    ", ARRAY_A);

    $popup_refs = [];
    foreach ($rows as $r) {
        preg_match_all('/%22popup%22%3A%22(\d+)%22/', $r['meta_value'], $m);
        foreach ($m[1] ?? [] as $id) {
            $popup_refs[(int)$id] = ($popup_refs[(int)$id] ?? 0) + 1;
        }
    }

    $cross_popups = [];
    foreach ($popup_refs as $pid => $count) {
        $b1 = $wpdb->get_row("SELECT post_type FROM wp_posts WHERE ID=$pid", ARRAY_A);
        $b2 = $wpdb->get_row("SELECT ID FROM wp_2_posts WHERE ID=$pid", ARRAY_A);
        if (!$b2 && $b1 && $b1['post_type'] === 'elementor_library') {
            $cross_popups[$pid] = $count;
        }
    }

    bit_log("  Popups cross-blog: " . count($cross_popups), 'info');

    $popup_migrated = 0;
    foreach ($cross_popups as $pid => $usage_count) {
        $existing = $wpdb->get_row("SELECT ID, post_type FROM wp_2_posts WHERE ID=$pid", ARRAY_A);
        if ($existing && $existing['post_type'] !== 'revision') {
            bit_log("    SKIP popup $pid: ID ocupado por {$existing['post_type']}", 'warn');
            continue;
        }
        if ($existing && $existing['post_type'] === 'revision') {
            if (!$dry_run) {
                $wpdb->delete('wp_2_posts', ['ID' => $pid]);
                $wpdb->delete('wp_2_postmeta', ['post_id' => $pid]);
            }
        }

        $post = $wpdb->get_row("SELECT * FROM wp_posts WHERE ID=$pid", ARRAY_A);
        $meta = $wpdb->get_results("SELECT meta_key, meta_value FROM wp_postmeta WHERE post_id=$pid", ARRAY_A);
        $term_rows = $wpdb->get_results("
            SELECT tt.taxonomy, t.slug
            FROM wp_term_relationships tr
            JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id=tr.term_taxonomy_id
            JOIN wp_terms t ON t.term_id=tt.term_id
            WHERE tr.object_id=$pid
        ", ARRAY_A);

        if (!$dry_run) {
            bit_insert('wp_2_posts', $post, "popup $pid");
            foreach ($meta as $m) {
                bit_insert('wp_2_postmeta', [
                    'post_id'    => $pid,
                    'meta_key'   => $m['meta_key'],
                    'meta_value' => $m['meta_value'],
                ], "popup meta $pid");
            }
            // Terms via API blog 2 (taxonomy elementor_library_type=popup já existe em ambos blogs)
            switch_to_blog(2);
            foreach ($term_rows as $t) {
                wp_set_object_terms($pid, $t['slug'], $t['taxonomy'], true);
            }
            restore_current_blog();
        }

        $migrated_popups[] = $pid;
        $popup_migrated++;
        bit_log("    ✓ popup $pid: {$post['post_title']} (usado em $usage_count posts)", 'ok');
    }
    bit_log("  ✓ Popups migrados: $popup_migrated", 'ok');
    echo "\n";
}

// ============================================================
// 3. WPML — registrar items migrados em wp_2_icl_translations
// ============================================================
if (!$skip_wpml) {
    bit_log("--- 3. WPML — registrando em wp_2_icl_translations ---", 'info');

    $wpml_count = 0;
    foreach ($migrated_attachments as $att_id) {
        // Detectar idioma do attachment a partir do blog 1 (se aplicável)
        $b1_lang = $wpdb->get_var($wpdb->prepare(
            "SELECT language_code FROM wp_icl_translations WHERE element_id=%d AND element_type='post_attachment' LIMIT 1",
            $att_id
        ));
        $lang = $b1_lang ?: $DEFAULT_LANG;
        bit_register_wpml_blog2($att_id, 'post_attachment', $lang);
        $wpml_count++;
    }
    foreach ($migrated_popups as $pid) {
        $b1_lang = $wpdb->get_var($wpdb->prepare(
            "SELECT language_code FROM wp_icl_translations WHERE element_id=%d AND element_type='post_elementor_library' LIMIT 1",
            $pid
        ));
        $lang = $b1_lang ?: $DEFAULT_LANG;
        bit_register_wpml_blog2($pid, 'post_elementor_library', $lang);
        $wpml_count++;
    }
    bit_log("  ✓ WPML entries criadas/confirmadas: $wpml_count", 'ok');
    echo "\n";
}

// ============================================================
// 4. CACHE INVALIDATION (apenas em apply)
// ============================================================
if (!$dry_run) {
    bit_log("--- 4. Invalidando caches ---", 'info');

    // 4.1 _elementor_element_cache global do blog 2
    $r1 = $wpdb->query("DELETE FROM wp_2_postmeta WHERE meta_key='_elementor_element_cache'");
    bit_log("  ✓ _elementor_element_cache: $r1 rows deletadas", 'ok');

    // 4.2 _elementor_css global do blog 2
    $r2 = $wpdb->query("DELETE FROM wp_2_postmeta WHERE meta_key='_elementor_css'");
    bit_log("  ✓ _elementor_css: $r2 rows deletadas", 'ok');

    // 4.3 _elementor_page_assets stale
    $r3 = $wpdb->query("DELETE FROM wp_2_postmeta WHERE meta_key='_elementor_page_assets'");
    bit_log("  ✓ _elementor_page_assets: $r3 rows deletadas", 'ok');
}

// ============================================================
// COMMIT
// ============================================================
if (!$dry_run) {
    $wpdb->query('COMMIT');
    $wpdb->query('SET autocommit=1');
    bit_log("✓ Transação commitada", 'ok');
}

} catch (Throwable $e) {
    if (!$dry_run) {
        $wpdb->query('ROLLBACK');
        $wpdb->query('SET autocommit=1');
    }
    bit_die("Exceção: " . $e->getMessage() . " em " . $e->getFile() . ":" . $e->getLine());
}

// ============================================================
// CACHE FLUSH FORA DA TRANSAÇÃO (Elementor file cache, WP Rocket, Redis)
// ============================================================
if (!$dry_run) {
    bit_log("--- 5. Flush externo ---", 'info');

    if (class_exists('\\Elementor\\Plugin')) {
        \Elementor\Plugin::$instance->files_manager->clear_cache();
        bit_log("  ✓ Elementor files_manager cache cleared", 'ok');
    }

    // WP Rocket — só clear o que afeta o blog 2 (não usar rocket_clean_domain global)
    switch_to_blog(2);
    if (function_exists('rocket_clean_domain'))   { rocket_clean_domain();   bit_log("  ✓ WP Rocket: clean_domain", 'ok'); }
    if (function_exists('rocket_clean_minify'))   { rocket_clean_minify();   bit_log("  ✓ WP Rocket: clean_minify", 'ok'); }
    if (function_exists('rocket_clean_used_css')) { rocket_clean_used_css(); bit_log("  ✓ WP Rocket: clean_used_css", 'ok'); }
    restore_current_blog();

    // Redis / object cache
    wp_cache_flush();
    bit_log("  ✓ Object cache flush", 'ok');
}

// ============================================================
// 6. VERIFICAÇÃO PÓS-EXECUÇÃO
// ============================================================
echo "\n";
bit_log("--- 6. Verificação pós-execução ---", 'info');

$remaining_thumb = (int) $wpdb->get_var("
    SELECT COUNT(DISTINCT m.meta_value)
    FROM wp_2_postmeta m
    WHERE m.meta_key='_thumbnail_id'
      AND m.meta_value REGEXP '^[0-9]+$'
      AND NOT EXISTS (SELECT 1 FROM wp_2_posts a WHERE a.ID=m.meta_value AND a.post_type='attachment')
      AND EXISTS (SELECT 1 FROM wp_posts a1 WHERE a1.ID=m.meta_value AND a1.post_type='attachment')
");
$remaining_popups = 0;
$rows = $wpdb->get_results("
    SELECT meta_value FROM wp_2_postmeta
    WHERE meta_key='_elementor_data'
      AND meta_value REGEXP '%22popup%22%3A%22[0-9]+%22'
", ARRAY_A);
$pids = [];
foreach ($rows as $r) {
    preg_match_all('/%22popup%22%3A%22(\d+)%22/', $r['meta_value'], $m);
    foreach ($m[1] ?? [] as $id) $pids[(int)$id] = true;
}
if ($pids) {
    $ids_str = implode(',', array_map('intval', array_keys($pids)));
    $remaining_popups = (int) $wpdb->get_var("
        SELECT COUNT(*) FROM wp_posts a1
        WHERE a1.ID IN ($ids_str) AND a1.post_type='elementor_library'
          AND NOT EXISTS (SELECT 1 FROM wp_2_posts a2 WHERE a2.ID=a1.ID AND a2.post_type='elementor_library')
    ");
}

bit_log("  Cross-blog _thumbnail_id remaining: $remaining_thumb", $remaining_thumb ? 'warn' : 'ok');
bit_log("  Cross-blog popups remaining: $remaining_popups", $remaining_popups ? 'warn' : 'ok');

if (!$dry_run && ($remaining_thumb > 0 || $remaining_popups > 0)) {
    bit_log("  AVISO: ainda há cross-blog refs após apply. Investigar.", 'warn');
}

echo "\n";
bit_log("=== FIM ===", 'info');
