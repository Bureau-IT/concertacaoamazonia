<?php
/**
 * Plugin Name: JetSlider LazyLoad Fix
 * Description: Corrige conflito entre o lazy loading do JetSlider (slider-pro) e o WP Rocket. Em produção, o EWWW Image Optimizer força o WP Rocket a usar loading="lazy" nativo, o que preserva o src real. Em dev/tunnel, sem EWWW ativo, o WP Rocket usa o método legacy (placeholder + data-lazy-src), que quebra o slider-pro. Este plugin força o uso de lazy loading nativo no WP Rocket, replicando o comportamento de produção.
 * Version: 1.2.0
 * Author: Bureau de Tecnologia
 */

defined( 'ABSPATH' ) || exit;

/**
 * Força o WP Rocket a usar loading="lazy" nativo (comportamento idêntico ao de produção
 * com EWWW Image Optimizer ativo). Sem isso, o WP Rocket usa placeholder SVG +
 * data-lazy-src, que o slider-pro (JetSlider) não consegue processar.
 */
add_filter( 'rocket_use_native_lazyload_images', '__return_true' );
