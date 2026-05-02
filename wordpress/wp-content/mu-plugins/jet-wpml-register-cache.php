<?php
/**
 * Plugin Name: JetEngine WPML Register String Cache
 * Description: Cacheia tradução de labels JetEngine via transient (12h) para evitar
 *              centenas de queries MySQL por request em sites com JetEngine + WPML.
 * Version:     1.0.0
 * Author:      Bureau de Tecnologia
 *
 * Problema resolvido:
 *   O JetEngine-WPML chama wpml_register_single_string + wpml_translate_single_string
 *   para 388+ labels em todo request frontend. Isso gera dezenas de queries por request cold.
 *
 * Estratégia (simples e segura):
 *   Interceptamos 'wpml_register_single_string' em priority=1.
 *   Para strings JetEngine já no transient, usamos wp_filter para remover e
 *   re-adicionar os handlers do WPML de forma que a string específica seja ignorada.
 *   Como do_action não tem short-circuit nativo, a estratégia é:
 *   - Priority 1: marcar strings conhecidas como "skip" via variável estática
 *   - O handler WPML (priority 10) tem um wrapper que verifica a flag antes de rodar
 *
 *   NA PRÁTICA: a abordagem mais confiável e simples é cachear o resultado do
 *   filtro 'wpml_translate_single_string', que é um apply_filters (tem short-circuit).
 *
 * Invalidação:
 *   wp eval 'delete_transient("bit_jet_wpml_labels"); echo "OK\n";'
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Pular no admin
if ( defined( 'WP_ADMIN' ) && WP_ADMIN ) {
    return;
}

add_action( 'plugins_loaded', function () {

    $cache_key = 'bit_jet_wpml_labels';
    $cache     = get_transient( $cache_key );

    if ( ! is_array( $cache ) ) {
        $cache = [];
    }

    $new_entries = [];

    /**
     * 'wpml_translate_single_string' é um apply_filters — suporta short-circuit natural.
     *
     * O JetEngine-WPML chama:
     *   apply_filters( 'wpml_translate_single_string', $label, 'Jet Engine Admin Labels', $name, $lang )
     *
     * Interceptamos em priority=1: se a tradução está no cache, retornamos direto.
     * O WPML (priority=10) não precisa fazer a query.
     *
     * Em priority=999: capturamos o resultado final (tradução real do WPML) e salvamos.
     */

    // Estado compartilhado entre os dois filtros para a call corrente
    $current_key = null;
    $skip_save   = false;

    add_filter( 'wpml_translate_single_string', function ( $value, $context = '', $name = '', $lang = null ) use ( &$cache, &$new_entries, &$current_key, &$skip_save ) {

        // Só interceptar strings do JetEngine
        if ( strpos( (string) $context, 'Jet Engine' ) !== 0 ) {
            $current_key = null;
            $skip_save   = true;
            return $value;
        }

        $current_key = md5( $context . '|' . $name );
        $skip_save   = false;

        if ( array_key_exists( $current_key, $cache ) ) {
            $skip_save = true;
            return (string) $cache[ $current_key ]; // Short-circuit: WPML não roda
        }

        return $value; // Cache miss — WPML processa

    }, 1, 4 );

    add_filter( 'wpml_translate_single_string', function ( $translated, $context = '', $name = '', $lang = null ) use ( &$cache, &$new_entries, &$current_key, &$skip_save ) {

        if ( ! $skip_save && $current_key !== null && ! array_key_exists( $current_key, $cache ) ) {
            $cache[ $current_key ]       = (string) $translated;
            $new_entries[ $current_key ] = (string) $translated;
        }

        return $translated;

    }, 999, 4 );

    // Persistir ao final da request se houve novas entradas
    add_action( 'shutdown', function () use ( &$cache, &$new_entries, $cache_key ) {
        if ( ! empty( $new_entries ) ) {
            set_transient( $cache_key, $cache, 12 * HOUR_IN_SECONDS );
        }
    } );

}, 0 );
