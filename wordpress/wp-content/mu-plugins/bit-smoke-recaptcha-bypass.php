<?php
/**
 * Plugin Name: BIT Smoke reCAPTCHA Bypass
 * Description: Bypassa validacao reCAPTCHA do Elementor Pro Forms quando request traz header X-BIT-Smoke-Token que confere com a constante BIT_SMOKE_BYPASS_TOKEN do wp-config.php. Adiciona campo virtual __bit_smoke_test=1 no record via filter actions_before (chega aos destinos email/webhook). Emite header X-BIT-Smoke-Bypass=OK|FAILED|NOOP para telemetria. No-op se constante ausente ou vazia. Spec: docs/superpowers/specs/2026-05-14-smoke-recaptcha-bypass-design.md
 * Version: 1.1.0
 * Author: Daniel Cambria (Bureau de Tecnologia)
 */

namespace BIT\SmokeRecaptchaBypass;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const HEADER_KEY        = 'HTTP_X_BIT_SMOKE_TOKEN';
const MIN_TOKEN_LEN     = 32;
const MARKER_FIELD_ID   = '__bit_smoke_test'; // prefixo __ blinda colisao com custom_id de campos do Elementor (UI nao permite __)
const RESPONSE_HEADER   = 'X-BIT-Smoke-Bypass';
const RECAPTCHA_CLASSES = [
	'\\ElementorPro\\Modules\\Forms\\Classes\\Recaptcha_Handler',
	'\\ElementorPro\\Modules\\Forms\\Classes\\Recaptcha_V3_Handler',
];

// Estado interno usado pelo header de telemetria.
// OK    = bypass autorizado E removeu pelo menos 1 callback recaptcha
// FAILED= bypass autorizado mas NAO encontrou callbacks (drift do Elementor Pro)
// NOOP  = bypass nao autorizado (sem header, token errado, etc.)
$GLOBALS['bit_smoke_bypass_state'] = 'NOOP';

function set_state( string $state ): void {
	$GLOBALS['bit_smoke_bypass_state'] = $state;
	if ( ! headers_sent() ) {
		header( RESPONSE_HEADER . ': ' . $state );
	}
}

function is_authorized_smoke_request(): bool {
	if ( ! defined( 'BIT_SMOKE_BYPASS_TOKEN' ) ) {
		return false;
	}
	$expected = (string) constant( 'BIT_SMOKE_BYPASS_TOKEN' );
	if ( strlen( $expected ) < MIN_TOKEN_LEN ) {
		return false;
	}
	$received = isset( $_SERVER[ HEADER_KEY ] ) ? wp_unslash( (string) $_SERVER[ HEADER_KEY ] ) : '';
	if ( strlen( $received ) < MIN_TOKEN_LEN ) {
		return false;
	}
	return hash_equals( $expected, $received );
}

function maybe_log( string $msg ): void {
	if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
		return;
	}
	$prefix = isset( $_SERVER[ HEADER_KEY ] ) ? substr( (string) $_SERVER[ HEADER_KEY ], 0, 8 ) . '...' : '(none)';
	$ip     = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
	error_log( sprintf( '[bit-smoke-recaptcha-bypass] %s | token=%s | ip=%s', $msg, $prefix, $ip ) );
}

/**
 * Remove os callbacks `validation` dos handlers Recaptcha_Handler e
 * Recaptcha_V3_Handler do hook elementor_pro/forms/validation.
 *
 * remove_action() exige a MESMA instancia de objeto registrada pelo Elementor
 * Pro, que nao temos acesso direto. Por isso varremos $wp_filter procurando
 * callbacks que sejam [instancia-de-classe-Recaptcha, 'validation'] e os
 * removemos por chave.
 */
function disable_recaptcha_validation(): void {
	if ( ! is_authorized_smoke_request() ) {
		// Mantem state NOOP; nao emite header agora pois pode mudar so quando AJAX rodar.
		return;
	}

	global $wp_filter;
	if ( empty( $wp_filter['elementor_pro/forms/validation'] ) ) {
		maybe_log( 'no validation hook registered yet — bypass deferred to later request' );
		set_state( 'FAILED' );
		return;
	}

	$hook    = $wp_filter['elementor_pro/forms/validation'];
	$removed = [];

	if ( ! isset( $hook->callbacks[10] ) ) {
		maybe_log( 'no priority-10 callbacks on validation hook' );
		set_state( 'FAILED' );
		return;
	}

	foreach ( $hook->callbacks[10] as $key => $cb ) {
		$fn = $cb['function'] ?? null;
		if ( ! is_array( $fn ) || count( $fn ) !== 2 ) {
			continue;
		}
		[ $obj, $method ] = $fn;
		if ( ! is_object( $obj ) || $method !== 'validation' ) {
			continue;
		}
		$obj_class = '\\' . get_class( $obj );
		if ( in_array( $obj_class, RECAPTCHA_CLASSES, true ) ) {
			unset( $hook->callbacks[10][ $key ] );
			$removed[] = $obj_class;
		}
	}

	if ( empty( $hook->callbacks[10] ) ) {
		unset( $hook->callbacks[10] );
	}

	if ( ! empty( $removed ) ) {
		maybe_log( sprintf( 'recaptcha bypass ENABLED — removed: %s', implode( ',', $removed ) ) );
		set_state( 'OK' );
	} else {
		maybe_log( 'no recaptcha callbacks found at priority 10 (Elementor Pro drift?)' );
		set_state( 'FAILED' );
	}
}

/**
 * Injeta campo virtual __bit_smoke_test=1 no record ANTES dos actions (email,
 * webhook, RD Station) — destinos passam a ver o marker. Sobrescreve sempre,
 * ignorando qualquer payload do cliente (blindagem contra forja: atacante sem
 * token nunca consegue setar este field).
 *
 * Filter (nao action): precisa retornar $record. Disparado em
 * ajax-handler.php:149 via apply_filters('elementor_pro/forms/record/actions_before').
 *
 * Prefixo __ evita colisao com custom_id de campos definidos no Elementor UI.
 */
function mark_record_as_smoke_test( $record, $ajax_handler ) {
	if ( ! is_authorized_smoke_request() ) {
		return $record;
	}
	$fields = (array) $record->get( 'fields' );
	$fields[ MARKER_FIELD_ID ] = [
		'id'        => MARKER_FIELD_ID,
		'type'      => 'hidden',
		'title'     => 'BIT Smoke Test Marker',
		'value'     => '1',
		'raw_value' => '1',
		'required'  => '',
	];
	$record->set( 'fields', $fields );
	maybe_log( 'record marked ' . MARKER_FIELD_ID . '=1' );
	return $record;
}

/**
 * Em requests GET (operador diagnosticando), emitir o header de telemetria
 * tambem mesmo sem AJAX. Em requests AJAX (POST do form), o header eh setado
 * por disable_recaptcha_validation() / set_state(). Aqui rodamos cedo, no
 * send_headers, para garantir que GET tambem tenha o sinal.
 */
function emit_diagnostic_header(): void {
	if ( headers_sent() ) {
		return;
	}
	if ( ! is_authorized_smoke_request() ) {
		header( RESPONSE_HEADER . ': NOOP' );
		return;
	}
	// Token bate, mas pode ainda nao ter rodado elementor_pro/init (depende
	// se Elementor Pro carrega antes do send_headers). Default OK; se depois
	// disable_recaptcha_validation falhar, ele reemite FAILED.
	header( RESPONSE_HEADER . ': ' . $GLOBALS['bit_smoke_bypass_state'] );
}

add_action( 'elementor_pro/init', __NAMESPACE__ . '\\disable_recaptcha_validation', 100 );
add_filter( 'elementor_pro/forms/record/actions_before', __NAMESPACE__ . '\\mark_record_as_smoke_test', 5, 2 );
add_action( 'send_headers', __NAMESPACE__ . '\\emit_diagnostic_header', 1 );
