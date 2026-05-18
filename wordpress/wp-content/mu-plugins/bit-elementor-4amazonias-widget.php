<?php
/**
 * Plugin Name:  BIT Elementor 4 Amazonias Widget
 * Description:  Widget Elementor "BIT 4 Amazonias" — renderiza o framework
 *               4 amazonias x 4 linhas (Premissa, Especificas, Estruturantes,
 *               Transversais) com layout cards lado-a-lado em desktop (header
 *               sticky + fade) e tabs por linha em mobile (com setas). Cores
 *               configuraveis via Global Colors. WPML-compatible: textos PT/EN
 *               hardcoded com troca por ICL_LANGUAGE_CODE.
 * Version:      1.0.0
 * Author:       Bureau IT
 * Network:      false
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Icone customizado no painel do Elementor
add_action( 'elementor/editor/after_enqueue_styles', function () {
    $svg = 'data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 24 24%22 fill=%22none%22 stroke=%22%23444%22 stroke-width=%222%22%3E%3Crect x=%222%22 y=%223%22 width=%229%22 height=%229%22 rx=%221%22/%3E%3Crect x=%2213%22 y=%223%22 width=%229%22 height=%229%22 rx=%221%22/%3E%3Crect x=%222%22 y=%2213%22 width=%229%22 height=%229%22 rx=%221%22/%3E%3Crect x=%2213%22 y=%2213%22 width=%229%22 height=%229%22 rx=%221%22/%3E%3C/svg%3E';
    echo '<style>.eicon-bit-4amazonias::before{content:"";display:block;width:1em;height:1em;background:url("' . esc_attr( $svg ) . '") center/contain no-repeat;}</style>' . "\n";
} );

// Registra widget
add_action( 'elementor/widgets/register', function ( $widgets_manager ) {
    require_once __DIR__ . '/bit-elementor-4amazonias-widget/widget-class.php';
    $widgets_manager->register( new \BIT_4Amazonias_Widget() );
} );

// Enqueue front-end CSS/JS quando widget esta presente
add_action( 'elementor/frontend/after_enqueue_scripts', function () {
    // CSS inline + JS estao dentro do render() do widget para portabilidade.
    // Aqui apenas registramos um handle vazio caso queira override externo.
} );
