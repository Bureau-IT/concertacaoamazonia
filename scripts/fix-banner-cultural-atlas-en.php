<?php
// Fix 4 — Traduz post_content + meta url do banner-home EN (post 76567)
// "Cultural Atlas of the Amazon opens registration for artists from the region"
//
// PROBLEMA: título traduzido para EN, mas post_content e meta url ficaram em PT.

$post_id = 76567;
$post = get_post($post_id);
if (!$post) { echo "ERROR: post $post_id not found\n"; return; }

echo "BEFORE post_content:\n" . $post->post_content . "\n\n";
echo "BEFORE meta url: " . get_post_meta($post_id, 'url', true) . "\n\n";

$new_content = '<!-- wp:paragraph -->
<p>Created by the Concertação, the collaborative platform brings together information about the territory\'s many artists and broadens the recognition of the diverse artistic expressions of the Legal Amazon.<a href="https://cambrasmax.local:8484/en/rediscovering-the-amazon-through-art-cultural-atlas-opens-registration-for-artists-from-the-region/" target="_blank" rel="noreferrer noopener"></a></p>
<!-- /wp:paragraph -->';

$new_url = 'https://cambrasmax.local:8484/en/rediscovering-the-amazon-through-art-cultural-atlas-opens-registration-for-artists-from-the-region/';

if (getenv('APPLY') !== '1') {
    echo "NEW post_content:\n" . $new_content . "\n\n";
    echo "NEW meta url: " . $new_url . "\n\n";
    echo "DRY-RUN. Set APPLY=1 to apply.\n";
    return;
}

$result = wp_update_post([
    'ID' => $post_id,
    'post_content' => $new_content,
], true);

if (is_wp_error($result)) {
    echo "ERROR wp_update_post: " . $result->get_error_message() . "\n";
    return;
}

update_post_meta($post_id, 'url', $new_url);

echo "OK: post $post_id atualizado\n";
echo "AFTER post_content:\n" . get_post($post_id)->post_content . "\n";
echo "AFTER meta url: " . get_post_meta($post_id, 'url', true) . "\n";
