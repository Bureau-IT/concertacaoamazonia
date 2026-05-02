<?php
/**
 * Plugin Name: BIT Google Tag Manager
 * Plugin URI:  https://bureau-it.com
 * Description: Injeta o snippet do Google Tag Manager via constante GTM_CONTAINER_ID no wp-config.php.
 *              Funciona em multisite. Só ativa em WP_ENVIRONMENT_TYPE = 'production'.
 * Version:     1.0.0
 * Author:      Bureau de Tecnologia Ltda.
 */

defined( 'ABSPATH' ) || exit;

// Requer constante GTM_CONTAINER_ID definida no wp-config.php
if ( ! defined( 'GTM_CONTAINER_ID' ) || empty( GTM_CONTAINER_ID ) ) {
    return;
}

// Só injeta em produção
if ( function_exists( 'wp_get_environment_type' ) && wp_get_environment_type() !== 'production' ) {
    return;
}

/**
 * Snippet GTM no <head> (logo após <body> não é possível via WP hooks,
 * mas wp_head é o ponto correto para o script principal).
 */
add_action( 'wp_head', 'bit_gtm_head_snippet', 1 );
function bit_gtm_head_snippet() {
    $id = esc_attr( GTM_CONTAINER_ID );
    echo "<!-- Google Tag Manager -->\n";
    echo "<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':\n";
    echo "new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],\n";
    echo "j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=\n";
    echo "'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);\n";
    echo "})(window,document,'script','dataLayer','{$id}');</script>\n";
    echo "<!-- End Google Tag Manager -->\n";
}

/**
 * Noscript GTM logo após <body> via wp_body_open (WP 5.2+).
 */
add_action( 'wp_body_open', 'bit_gtm_body_snippet', 1 );
function bit_gtm_body_snippet() {
    $id = esc_attr( GTM_CONTAINER_ID );
    echo "<!-- Google Tag Manager (noscript) -->\n";
    echo "<noscript><iframe src=\"https://www.googletagmanager.com/ns.html?id={$id}\"\n";
    echo "height=\"0\" width=\"0\" style=\"display:none;visibility:hidden\"></iframe></noscript>\n";
    echo "<!-- End Google Tag Manager (noscript) -->\n";
}
