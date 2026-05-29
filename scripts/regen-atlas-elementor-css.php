<?php
// Regenera o Elementor CSS das páginas do Atlas (PT 57548 + EN 72730)
// O widget país foi inserido via script — o CSS pode não ter as regras de typography dele.
foreach ([57548, 72730] as $pg) {
  if (class_exists('\Elementor\Core\Files\CSS\Post')) {
    $css = new \Elementor\Core\Files\CSS\Post($pg);
    $css->update(); // regenera o arquivo post-NN.css
    echo "regen Elementor CSS post=$pg\n";
  }
  delete_post_meta($pg, '_elementor_element_cache');
}
// WP Rocket: limpar minify (pode estar servindo post-NN.css stale)
if (function_exists('rocket_clean_minify')) { rocket_clean_minify('css'); echo "rocket_clean_minify css\n"; }
foreach ([57548, 72730] as $pg) if (function_exists('rocket_clean_post')) rocket_clean_post($pg);
echo "rocket_clean_post done\n";
wp_cache_flush();
echo "OK\n";
