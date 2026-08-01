<?php
/**
 * Plugin Name: BIT Smoke reCAPTCHA Bypass
 * Description: Bypassa validacao reCAPTCHA do Elementor Pro Forms (server-side + client-side) quando request traz header X-BIT-Smoke-Token que confere com a constante BIT_SMOKE_BYPASS_TOKEN do wp-config.php. v1.3.2 (2026-05-22): nocache_headers emitido em TODA request que carrega header X-BIT-Smoke-Token (autorizado ou nao). Sem isso, responses NOOP iam pro CF cache e contaminavam requests anonimos (validado contra validate-smoke-bypass.sh teste 3/5). v1.3.1: clarifica que `header()` Cache-Control e intencional. v1.3.0: hardening — nocache_headers no send_headers; rate-limit (30/min/IP) no audit_log. v1.2.0: injeta window.grecaptcha stub no <head> que satisfaz onRecaptchaApiReady() do Elementor Pro mesmo quando gstatic.com esta bloqueado por CSP. v1.0.0: remove callbacks server-side de Recaptcha_Handler / Recaptcha_V3_Handler, adiciona __bit_smoke_test=1 no record via filter actions_before, emite X-BIT-Smoke-Bypass=OK|FAILED|NOOP condicional a header de request. Spec: docs/superpowers/specs/2026-05-14-smoke-recaptcha-bypass-design.md
 * Version: 1.3.2
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
// NOOP  = bypass nao autorizado (token errado, etc.) mas request CARREGA header
$GLOBALS['bit_smoke_bypass_state'] = 'NOOP';

/**
 * Retorna true se o request HTTP carrega o header X-BIT-Smoke-Token (qualquer
 * valor, mesmo invalido). Usado para condicionar a emissao do header de
 * resposta de telemetria — sem isso, anonimos veriam X-BIT-Smoke-Bypass=NOOP
 * em toda response cacheavel (poluindo cache do CloudFront/WP Rocket e
 * revelando existencia do mecanismo).
 */
function request_carries_smoke_header(): bool {
	return isset( $_SERVER[ HEADER_KEY ] ) && $_SERVER[ HEADER_KEY ] !== '';
}

function set_state( string $state ): void {
	$GLOBALS['bit_smoke_bypass_state'] = $state;
	if ( ! headers_sent() && request_carries_smoke_header() ) {
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

/**
 * Audit log de uso autorizado: dispara INCONDICIONAL (independe de WP_DEBUG)
 * sempre que is_authorized_smoke_request() retorna true. Sem PII; entrada
 * minima (timestamp via error_log, IP, prefixo do token, contexto).
 *
 * Log de diagnostico (drift, falhas) continua condicional a WP_DEBUG.
 */
function audit_log( string $msg ): void {
	$prefix = isset( $_SERVER[ HEADER_KEY ] ) ? substr( (string) $_SERVER[ HEADER_KEY ], 0, 8 ) . '...' : '(none)';
	$ip     = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
	$uri    = $_SERVER['REQUEST_URI'] ?? '?';

	// MED 7: rate-limit por IP+minuto. Atacante com token vazado podera floodar
	// error_log e estourar disco se cada submit emitir 2-3 audit entries.
	// Limite: 30 entries/min/IP. Excedeu, drop silencioso (telemetria interna
	// preserva o gate; audit fica documentado pelo primeiro evento).
	$bucket = 'bit_smoke_audit_' . md5( $ip . '|' . gmdate( 'YmdHi' ) );
	$count  = (int) get_transient( $bucket );
	if ( $count >= 30 ) {
		return;
	}
	set_transient( $bucket, $count + 1, 60 );

	error_log( sprintf( '[bit-smoke-recaptcha-bypass AUDIT] %s | token=%s | ip=%s | uri=%s', $msg, $prefix, $ip, substr( $uri, 0, 200 ) ) );
}

function debug_log( string $msg ): void {
	if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
		return;
	}
	$prefix = isset( $_SERVER[ HEADER_KEY ] ) ? substr( (string) $_SERVER[ HEADER_KEY ], 0, 8 ) . '...' : '(none)';
	$ip     = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
	error_log( sprintf( '[bit-smoke-recaptcha-bypass DEBUG] %s | token=%s | ip=%s', $msg, $prefix, $ip ) );
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
		// Mantem state NOOP. Nao emite header aqui — emit_diagnostic_header
		// faz isso (e so se o request carregar o header).
		return;
	}

	audit_log( 'authorized request received' );

	global $wp_filter;
	if ( empty( $wp_filter['elementor_pro/forms/validation'] ) ) {
		debug_log( 'no validation hook registered yet — bypass deferred to later request' );
		set_state( 'FAILED' );
		return;
	}

	$hook    = $wp_filter['elementor_pro/forms/validation'];
	$removed = [];

	if ( ! isset( $hook->callbacks[10] ) ) {
		debug_log( 'no priority-10 callbacks on validation hook' );
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
		debug_log( sprintf( 'recaptcha bypass ENABLED — removed: %s', implode( ',', $removed ) ) );
		set_state( 'OK' );
	} else {
		debug_log( 'no recaptcha callbacks found at priority 10 (Elementor Pro drift?)' );
		set_state( 'FAILED' );
	}
}

/**
 * Injeta campo virtual __bit_smoke_test=1 no record ANTES dos actions (email,
 * webhook, RD Station) — destinos passam a ver o marker. Sobrescreve sempre,
 * ignorando qualquer payload do cliente (blindagem contra forja: atacante sem
 * token nunca consegue setar este field porque Form_Record::set_fields()
 * itera form_settings['form_fields'] e ignora payload livre).
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
	audit_log( 'record marked ' . MARKER_FIELD_ID . '=1' );
	return $record;
}

/**
 * Emite o header X-BIT-Smoke-Bypass na response de requests GET/frontend.
 *
 * Importante: send_headers eh disparado em WP::send_headers() durante o
 * wp() main flow — NAO em admin-ajax.php (que pula esse path). No fluxo AJAX
 * do submit do form, o header eh emitido por set_state() chamado dentro de
 * disable_recaptcha_validation() (priority 100 em elementor_pro/init, que
 * roda mesmo em admin-ajax). Esta funcao cobre GET inicial / diagnostico.
 *
 * Emissao condicional ao header de request: sem X-BIT-Smoke-Token no
 * request, nao ha X-BIT-Smoke-Bypass na response. Isso evita poluir cache do
 * CloudFront/WP Rocket com metadado revelando o mecanismo.
 */
function emit_diagnostic_header(): void {
	if ( headers_sent() ) {
		return;
	}
	if ( ! request_carries_smoke_header() ) {
		return;
	}
	if ( ! is_authorized_smoke_request() ) {
		header( RESPONSE_HEADER . ': NOOP' );
		return;
	}
	// Token bate. disable_recaptcha_validation ja rodou (elementor_pro/init
	// dispara em plugins_loaded, antes do send_headers). Usa o estado atual.
	header( RESPONSE_HEADER . ': ' . $GLOBALS['bit_smoke_bypass_state'] );
}

/**
 * Injeta `window.grecaptcha` stub no <head> quando request autorizado.
 *
 * Por que (v1.2.0):
 *   Elementor Pro forms com reCAPTCHA invisible chamam, no client-side:
 *     window.grecaptcha.render(el, settings)  → retorna widgetId
 *     window.grecaptcha.ready(cb)             → chama cb() imediatamente
 *     grecaptcha.execute(widgetId, {action})  → Promise<token>
 *     window.grecaptcha.reset(widgetId)       → no-op
 *
 *   Quando a CSP do site nao libera gstatic.com em script-src, o script
 *   real do Google nunca carrega. O handler Elementor faz polling em
 *   onRecaptchaApiReady() esperando window.grecaptcha aparecer — e como
 *   nunca aparece, btn.click() do submit fica preso em e.preventDefault()
 *   dentro de onV3FormSubmit e nenhum POST chega ao admin-ajax.
 *
 *   Isso quebrava `std formtest submit` em qualquer ambiente com CSP
 *   restritiva (validado 2026-05-19 contra concertacao via tunnel).
 *
 *   O stub satisfaz a checagem `window.grecaptcha && window.grecaptcha.render`
 *   do handler; o execute() resolve um token sintetico que viaja no campo
 *   g-recaptcha-response do POST; e o server-side bypass (ja existente desde
 *   v1.0.0) ignora esse token porque a validacao foi removida.
 *
 * Como aplicar:
 *   - Carrega ANTES de qualquer script Elementor (priority 1 em wp_head).
 *   - Nao requer dependencia (nao usa wp_enqueue_script — injeta inline puro).
 *   - Zero efeito em requests nao-autorizados (early return).
 */
function inject_grecaptcha_stub(): void {
	if ( ! is_authorized_smoke_request() ) {
		return;
	}
	// HIGH 3: nocache_headers ja foi emitido por emit_nocache_headers_when_authorized()
	// no hook send_headers (priority 1), antes do template_redirect que dispara o
	// output buffer. wp_head roda DEPOIS — headers_sent() seria true aqui.
	// Token sintetico previsivel — server-side ignora porque validacao foi
	// removida em disable_recaptcha_validation(). Prefixo identificavel em
	// caso de leak para logs do Google (jamais sera enviado, mas defesa em
	// profundidade).
	$token = 'bit-smoke-bypass-' . substr( md5( (string) microtime( true ) ), 0, 16 );
	?>
	<script id="bit-smoke-grecaptcha-stub">
	/* BIT Smoke reCAPTCHA Bypass v1.2.0 — client-side stub.
	   Token autorizado detectado server-side; substituindo grecaptcha real
	   para satisfazer handler Elementor Pro quando CSP bloqueia gstatic.com. */
	(function () {
		if (window.grecaptcha && window.grecaptcha.render) { return; }
		var widgetCounter = 0;
		window.grecaptcha = {
			ready: function (cb) { if (typeof cb === 'function') { cb(); } },
			render: function () { return 'bit-smoke-widget-' + (++widgetCounter); },
			execute: function () { return Promise.resolve('<?php echo esc_js( $token ); ?>'); },
			reset: function () { /* no-op */ }
		};
	})();
	</script>
	<?php
}

/**
 * HIGH 3 (v1.3.0): forca no-cache no send_headers (antes do output buffer)
 * quando request autorizado. Sem isto, proxy/CF/WP Rocket podem cachear a
 * HTML com o stub injetado e servir para anonimos posteriormente.
 * Roda no mesmo hook que emit_diagnostic_header (priority 1).
 */
function emit_nocache_headers_when_authorized(): void {
	if ( headers_sent() ) {
		return;
	}
	// v1.3.2 (2026-05-22): emitir no-cache em TODA request que CARREGA o
	// header X-BIT-Smoke-Token (autorizado OU nao). Sem isso, responses
	// nao-autorizadas (X-BIT-Smoke-Bypass: NOOP) vao pro cache do CF e
	// contaminam requests subsequentes (incluindo anonimos), revelando
	// existencia do mecanismo. Validado contra validate-smoke-bypass.sh
	// teste 3/5 (header AUSENTE em request sem token vazou NOOP via CF).
	if ( ! request_carries_smoke_header() ) {
		return;
	}
	// nocache_headers() ja emite Cache-Control no-cache, must-revalidate,
	// max-age=0 + Expires 1984. Adicionamos `private, no-store` mais
	// restritivos (necessarios contra CF/proxy edge cachear).
	nocache_headers();
	header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0' );
}

add_action( 'elementor_pro/init', __NAMESPACE__ . '\\disable_recaptcha_validation', 100 );
add_filter( 'elementor_pro/forms/record/actions_before', __NAMESPACE__ . '\\mark_record_as_smoke_test', 5, 2 );
add_action( 'send_headers', __NAMESPACE__ . '\\emit_diagnostic_header', 1 );
add_action( 'send_headers', __NAMESPACE__ . '\\emit_nocache_headers_when_authorized', 1 );
add_action( 'wp_head', __NAMESPACE__ . '\\inject_grecaptcha_stub', 1 );
