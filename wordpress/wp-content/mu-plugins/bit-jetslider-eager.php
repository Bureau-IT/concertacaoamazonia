<?php
/**
 * Plugin Name: BIT JetSlider Eager Loading
 * Plugin URI:
 * Description: Fix JetSlider/JetEngine carrosséis — exclui do WP Rocket Delay JS + força eager loading.
 * Version: 2.1.0
 * Author: Bureau de Tecnologia
 * Network: true
 *
 * Causa raiz combinada:
 * 1. JetElements hardcoda loading="lazy" nas <img class="sp-image"> (jet-elements-slider.php:3413)
 * 2. WP Rocket Delay JavaScript adia jQuery + Slider Pro até interação do usuário
 *    → sem JS, o Slider Pro nem inicializa → carrossel fica invisível
 *
 * Fix:
 * 1. Exclui scripts do JetSlider do WP Rocket Delay JS (rocket_delay_js_exclusions)
 * 2. Troca loading="lazy" por loading="eager" nas sp-image via Elementor filter
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'elementor/widget/render_content', 'bit_jetslider_eager_fix', 15, 2 );
add_filter( 'rocket_delay_js_exclusions', 'bit_jetslider_delay_exclusions' );

/**
 * Troca loading="lazy" por loading="eager" nas sp-image do JetSlider.
 */
function bit_jetslider_eager_fix( $content, $widget ) {
	if ( ! $widget || 'jet-slider' !== $widget->get_name() ) {
		return $content;
	}

	if ( empty( $content ) || false === strpos( $content, 'sp-image' ) ) {
		return $content;
	}

	return preg_replace(
		'/(<img\s[^>]*class="sp-image"[^>]*)\sloading="lazy"/i',
		'$1 loading="eager"',
		$content
	);
}

/**
 * Exclui scripts de carrosséis do WP Rocket Delay JavaScript.
 *
 * O Delay JS adia tudo até interação do usuário — sem jQuery + libs de slider,
 * os carrosséis não inicializam. Excluir esses scripts permite que
 * inicializem normalmente no DOMContentLoaded.
 *
 * Inclui:
 * - JetSlider (jet-elements): usa Slider Pro
 * - JetEngine Listing Grid: usa Slick quando em modo carousel
 *
 * @param array $exclusions Padrões de exclusão existentes.
 * @return array Padrões com exclusões adicionadas.
 */
function bit_jetslider_delay_exclusions( $exclusions ) {
	// jQuery (dependência comum)
	$exclusions[] = 'jquery-core';
	$exclusions[] = 'jquery-migrate';
	$exclusions[] = 'jquery\.min\.js';
	$exclusions[] = 'jquery-migrate\.min\.js';
	// Elementor frontend (registra widget handlers)
	$exclusions[] = 'elementor-frontend';
	$exclusions[] = 'elementor/assets/js';
	// JetSlider (jet-elements + Slider Pro)
	$exclusions[] = 'jet-slider';
	$exclusions[] = 'sliderPro';
	$exclusions[] = 'jet-elements';
	$exclusions[] = 'jet-plugins';
	// JetEngine Listing Grid carousel (Slick)
	$exclusions[] = 'slick';
	$exclusions[] = 'jet-engine';
	return $exclusions;
}
