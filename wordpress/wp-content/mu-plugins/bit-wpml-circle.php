<?php
/**
 * Plugin Name: BIT WPML Circle Switcher
 * Description: Estiliza o WPML language switcher como círculo no container .ucpa-header-icons.
 *              CSS compartilhado entre todos os sites da rede. Zero requisição HTTP extra.
 *              Fonte canônica: docker-dev/common/scripts/bit-wpml-circle.php
 *              Copiar para mu-plugins/ de cada site após alterações.
 * Version:     1.0.0
 * Author:      Bureau IT
 * Network:     true
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'wp_enqueue_scripts', function () {
    // Priority 100 garante que Elementor já registrou elementor-frontend.
    // 'registered' (não 'enqueued') evita falso-negativo antes do output.
    if ( ! wp_style_is( 'elementor-frontend', 'registered' ) ) return;

    $css = <<<'CSS'
/* WPML Language Switcher — borda circular (ucpa-header-icons) */
.wpml-ls-current-language{display:none !important}
.wpml-ls-display{display:none !important}
.wpml-ls-native~.wpml-ls-native{display:none !important}
.ucpa-header-icons .wpml-ls{border:0}
.ucpa-header-icons .wpml-ls ul{display:flex;flex-direction:row;align-items:center;gap:6px;margin:0 !important;padding:0;list-style:none}
.ucpa-header-icons .wpml-ls li{display:flex}
.ucpa-header-icons .wpml-ls-link{display:flex;align-items:center;justify-content:center;border:0 !important;padding:0;text-decoration:none}
.ucpa-header-icons .wpml-ls-bracket,
.ucpa-header-icons .wpml-ls-flag{display:none !important}
.ucpa-header-icons .wpml-ls-link .wpml-ls-native{font-size:0;line-height:0;display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border:1px solid rgba(255,255,255,0.73);border-radius:50%;transition:background 200ms,color 200ms;cursor:pointer}
.ucpa-header-icons .wpml-ls-link .wpml-ls-native::after{font-size:10px;font-weight:500;font-family:"Just Sans",sans-serif;line-height:1;color:#ffffff;text-transform:uppercase}
.ucpa-header-icons .wpml-ls-item-en .wpml-ls-link .wpml-ls-native::after{content:"EN"}
.ucpa-header-icons .wpml-ls-item-pt-br .wpml-ls-link .wpml-ls-native::after{content:"PT"}
.ucpa-header-icons .wpml-ls-link:hover .wpml-ls-native{background:white}
.ucpa-header-icons .wpml-ls-link:hover .wpml-ls-native::after{color:#000;font-weight:700}
CSS;

    wp_add_inline_style( 'elementor-frontend', $css );
}, 100 );
