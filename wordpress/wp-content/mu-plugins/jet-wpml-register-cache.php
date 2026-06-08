<?php
/**
 * Plugin Name: JetEngine WPML Register String Cache
 * Description: Elimina ~500 queries MySQL por request frontend em sites JetEngine + WPML.
 *              Cobre as DUAS metades: (A) pula o RE-REGISTRO de strings no frontend e
 *              (B) cacheia a TRADUÇÃO de labels via transient (12h).
 * Version:     1.1.1
 * Author:      Bureau de Tecnologia
 *
 * Problema resolvido (incidente CPU 100% / 504 em 2026-06-08):
 *   O pacote de compat JetEngine↔WPML (jet-engine/.../packages/wpml/inc/package.php)
 *   engata translate_admin_labels()/translate_cpt_name() INCONDICIONALMENTE no init.
 *   Em CADA pageview de frontend não-cacheada, para cada uma das ~500 labels:
 *     1. do_action('wpml_register_single_string')  → icl_register_string → 1 query (REGISTRO)
 *     2. apply_filters('wpml_translate_single_string') → lookup tradução   → 1 query (TRADUÇÃO)
 *   = ~1000+ queries SQL antes do template renderizar. CPU-bound. Slow log confirmou
 *   99,4% das entradas em frontend (index.php), frame icl_st_translate_admin_string 17.645×.
 *
 *   A v1.0.0 só cobria (B). As ~504 queries de REGISTRO (A) continuavam disparando.
 *   v1.1.0 fecha o gap.
 *
 *   v1.1.1 (2026-06-08, pós-auditoria) corrige 2 achados:
 *     - GAP DE IDIOMA nos CPT Labels: translate_cpt_name() chama o filtro SEM o arg $lang,
 *       então a chave de cache ficava md5(context|name|'') — não distinguia PT/EN e podia
 *       servir o rótulo do CPT no idioma errado (o 1º idioma a popular o cache vencia).
 *       Fix: derivar o idioma efetivo via apply_filters('wpml_current_language') quando
 *       $lang vier vazio, antes de compor a chave. Admin/Relations Labels já passavam $lang.
 *     - GUARD REST: bit_jet_wpml_is_frontend() não cobria REST_REQUEST → strings novas
 *       criadas via REST (Gutenberg/headless) poderiam não registrar. Agora o registro (A)
 *       permanece ativo em requests REST (tratadas como "não-frontend" para fins de registro).
 *
 * Estratégia:
 *   (A) REGISTRO — wpml_register_single_string é um do_action (sem short-circuit nativo).
 *       No frontend, REMOVEMOS de uma vez (no init, antes do JetEngine rodar) o handler
 *       do WPML ST que escuta esse hook, APENAS quando a chamada é do JetEngine. As strings
 *       já estão TODAS registradas no banco (wp_icl_strings: 504 blog 1 / 296 blog 2) —
 *       o re-registro no frontend é puro overhead. Registro continua normal no admin/REST.
 *   (B) TRADUÇÃO — wpml_translate_single_string é apply_filters (suporta short-circuit).
 *       Cacheamos o resultado por md5(context|name|lang_efetivo) em transient 12h.
 *
 * Segurança:
 *   - Só atua no frontend (return cedo se is_admin / WP_ADMIN / WP_CLI / DOING_CRON / REST).
 *   - O bloqueio do registro é SELETIVO por contexto "Jet Engine*": um wrapper em
 *     priority 1 desregistra o handler WPML antes da call e o restaura em priority 9999,
 *     para não afetar registro de strings de OUTROS plugins no mesmo hook.
 *   - Strings novas do JetEngine passam a ser registradas só no admin/save — correto.
 *
 * Invalidação do cache de tradução:
 *   wp eval 'delete_transient("bit_jet_wpml_labels"); echo "OK\n";'
 *   (necessário após editar traduções de labels JetEngine no WPML String Translation)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Detecta se estamos servindo uma página de frontend (onde o re-registro é desperdício).
 * Mantém registro ativo em: wp-admin, wp-cli, cron, REST — contextos onde strings novas
 * podem precisar ser registradas (ex.: salvar CPT/relation via REST/Gutenberg).
 */
function bit_jet_wpml_is_frontend() {
    if ( function_exists( 'is_admin' ) && is_admin() ) {
        return false;
    }
    if ( defined( 'WP_ADMIN' ) && WP_ADMIN ) {
        return false;
    }
    if ( defined( 'WP_CLI' ) && WP_CLI ) {
        return false;
    }
    if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
        return false;
    }
    if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
        return false;
    }
    return true;
}

if ( ! bit_jet_wpml_is_frontend() ) {
    return;
}

/**
 * (A) Pular RE-REGISTRO de strings JetEngine no frontend.
 *
 * do_action('wpml_register_single_string', $context, $name, $value) — 3 args.
 * O WPML ST escuta esse hook (callback wpml_register_single_string_action, priority 10),
 * que chama icl_register_string → 1 query por string.
 *
 * Em priority 1: se o contexto é "Jet Engine*", removemos o(s) callback(s) do WPML para
 * essa call específica. Em priority 9999: restauramos, para não afetar outros contextos.
 */
$GLOBALS['bit_jet_wpml_removed_register'] = [];

add_action( 'wpml_register_single_string', function ( $context = '', $name = '', $value = '' ) {

    if ( strpos( (string) $context, 'Jet Engine' ) !== 0 ) {
        return;
    }

    global $wp_filter;
    $hook = 'wpml_register_single_string';

    if ( empty( $wp_filter[ $hook ]->callbacks[10] ) ) {
        return;
    }

    foreach ( $wp_filter[ $hook ]->callbacks[10] as $id => $cb ) {
        $fn = $cb['function'];
        $is_wpml =
            ( is_string( $fn ) && strpos( $fn, 'wpml_register_single_string' ) !== false )
            || ( is_array( $fn ) && isset( $fn[0] ) && is_object( $fn[0] )
                 && stripos( get_class( $fn[0] ), 'wpml' ) !== false );

        if ( $is_wpml ) {
            $GLOBALS['bit_jet_wpml_removed_register'][ $id ] = $cb;
            unset( $wp_filter[ $hook ]->callbacks[10][ $id ] );
        }
    }

}, 1, 3 );

add_action( 'wpml_register_single_string', function ( $context = '', $name = '', $value = '' ) {

    if ( empty( $GLOBALS['bit_jet_wpml_removed_register'] ) ) {
        return;
    }

    global $wp_filter;
    $hook = 'wpml_register_single_string';

    foreach ( $GLOBALS['bit_jet_wpml_removed_register'] as $id => $cb ) {
        if ( ! isset( $wp_filter[ $hook ]->callbacks[10][ $id ] ) ) {
            $wp_filter[ $hook ]->callbacks[10][ $id ] = $cb;
        }
    }
    $GLOBALS['bit_jet_wpml_removed_register'] = [];

}, 9999, 3 );

/**
 * (B) Cache da TRADUÇÃO de labels JetEngine (transient 12h).
 *
 * 'wpml_translate_single_string' é apply_filters — priority 1 faz short-circuit do cache,
 * priority 999 captura a tradução real do WPML em caso de miss para persistir.
 */
add_action( 'plugins_loaded', function () {

    $cache_key = 'bit_jet_wpml_labels';
    $cache     = get_transient( $cache_key );

    if ( ! is_array( $cache ) ) {
        $cache = [];
    }

    $new_entries = [];
    $current_key = null;
    $skip_save   = false;

    add_filter( 'wpml_translate_single_string', function ( $value, $context = '', $name = '', $lang = null ) use ( &$cache, &$new_entries, &$current_key, &$skip_save ) {

        if ( strpos( (string) $context, 'Jet Engine' ) !== 0 ) {
            $current_key = null;
            $skip_save   = true;
            return $value;
        }

        // GAP DE IDIOMA (v1.1.1): translate_cpt_name() chama o filtro sem $lang, então $lang
        // chega vazio e a chave não distinguiria PT/EN. O WPML, com $lang vazio, traduz para
        // o idioma corrente — então derivamos esse idioma corrente para a chave bater no
        // conteúdo realmente retornado. Admin/Relations Labels já passam $lang explícito.
        $lang_key = (string) $lang;
        if ( $lang_key === '' ) {
            $lang_key = (string) apply_filters( 'wpml_current_language', null );
        }

        $current_key = md5( $context . '|' . $name . '|' . $lang_key );
        $skip_save   = false;

        if ( array_key_exists( $current_key, $cache ) ) {
            $skip_save = true;
            return (string) $cache[ $current_key ];
        }

        return $value;

    }, 1, 4 );

    add_filter( 'wpml_translate_single_string', function ( $translated, $context = '', $name = '', $lang = null ) use ( &$cache, &$new_entries, &$current_key, &$skip_save ) {

        if ( ! $skip_save && $current_key !== null && ! array_key_exists( $current_key, $cache ) ) {
            $cache[ $current_key ]       = (string) $translated;
            $new_entries[ $current_key ] = (string) $translated;
        }

        return $translated;

    }, 999, 4 );

    add_action( 'shutdown', function () use ( &$cache, &$new_entries, $cache_key ) {
        if ( ! empty( $new_entries ) ) {
            set_transient( $cache_key, $cache, 12 * HOUR_IN_SECONDS );
        }
    } );

}, 0 );
