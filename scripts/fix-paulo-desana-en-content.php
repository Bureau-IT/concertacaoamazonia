<?php
// Fix 1 — Desfaz double-escape no post_content do Paulo Desana EN (blog 2, ID 90315)
// Causa: wp_slash aplicado em conteúdo já slash'd (padrão idêntico ao incidente
// regularize-cultura v2 2026-05-07 — ver feedback memory).

switch_to_blog(2);

$post_id = 90315;
$post = get_post($post_id);
if (!$post) { echo "ERROR: post not found in blog 2\n"; return; }

$raw = $post->post_content;
echo "BEFORE length: " . strlen($raw) . "\n";
echo "BEFORE sample (first 350):\n" . substr($raw, 0, 350) . "\n\n";

// stripslashes uma vez deve desfazer a corrupção \\" -> \"
$fixed = stripslashes($raw);

echo "AFTER length: " . strlen($fixed) . "\n";
echo "AFTER sample (first 350):\n" . substr($fixed, 0, 350) . "\n\n";

// Sanity check 1: wp:image deve aparecer com JSON limpo após o fix
if (!preg_match('/<!-- wp:image \{"id":\d+/', $fixed)) {
    echo "ABORT: padrão <!-- wp:image {\"id\":N --> não encontrado após stripslashes\n";
    echo "Pode ser que post_content esteja em outro estado de escape.\n";
    return;
}

// Sanity check 2: deve haver pelo menos 6 imagens (Anita, Gilda, Carmem, Herculino, IMG_0690, Manoel)
$img_count = preg_match_all('/<!-- wp:image /', $fixed);
echo "wp:image blocks encontrados: $img_count\n";
if ($img_count < 6) {
    echo "ABORT: esperado >=6 imagens, encontrado $img_count\n";
    return;
}

if (getenv('APPLY') !== '1') {
    echo "\nDRY-RUN. Para aplicar: docker exec -e APPLY=1 ... eval-file ...\n";
    return;
}

// wp_update_post aplica wp_slash internamente — passar valor LIMPO
$result = wp_update_post([
    'ID' => $post_id,
    'post_content' => $fixed,
], true);

if (is_wp_error($result)) {
    echo "ERROR wp_update_post: " . $result->get_error_message() . "\n";
    return;
}

echo "OK: post $post_id atualizado\n";

// Validate persistencia: re-read from DB
$check = get_post($post_id);
echo "POST-WRITE sample (first 200):\n" . substr($check->post_content, 0, 200) . "\n";
