<?php
/**
 * Plugin Name: BIT Elementor Form Responsive
 * Plugin URI:  https://bureau-it.com
 * Description: Estende o widget Form do Elementor Pro tornando `form_name`,
 *              `placeholder` e `field_options_empty` device-aware (Desktop/Tablet/Mobile)
 *              via switcher nativo. Permite unificar 2 widgets de form com designs
 *              distintos em 1 widget. CSS pill/retângulo + JS placeholder por breakpoint.
 *              Spec: docs/superpowers/specs/2026-05-19-formulario-rodape-rdstation-design.md
 * Version:     1.0.0
 * Author:      Daniel Cambría / Bureau de Tecnologia Ltda.
 * Network:     true
 */

namespace BIT\ElementorFormResponsive;

defined( 'ABSPATH' ) || exit;

const VERSION      = '1.0.0';
const WIDGET_CLASS = 'bit-form-responsive';

/**
 * Adia até plugins carregarem (mu-plugins rodam antes dos plugins normais).
 * Elementor Pro precisa estar disponível para registrar os hooks de controles.
 */
add_action( 'plugins_loaded', function () {
    // Guard: Elementor Pro precisa estar carregado
    if ( ! class_exists( '\ElementorPro\Plugin' ) ) {
        return;
    }

    // Hooks de controles responsivos serão registrados aqui (Task 2+)
}, 20 );
