<?php
/**
 * Plugin Name: BIT Elementor Form RD Station
 * Plugin URI:  https://bureau-it.com
 * Description: Form Action customizada que envia submits do Elementor Pro Forms
 *              para a API REST do RD Station Marketing (POST /platform/conversions
 *              via API Key). Graceful: falhas da API NUNCA quebram o submit do form.
 *
 *              API Key: RDSTATION_API_KEY em wp-config.php (resolucao de sufixo
 *              _DEV/_HML/_PROD feita pelo bootstrap.sh via docker-compose.yml).
 *
 *              NOTA HISTORICA: a API /platform/conversions aceita como api_key o token
 *              que o painel RD Station chama de "Identificador publico" (PUBLIC_TOKEN).
 *              O nome foi invertido semanticamente — aqui usamos RDSTATION_API_KEY.
 *
 *              Resposta sucesso: {"event_uuid": "<uuid>"}.
 *              Erros: HTTP 400, {"errors":[{"error_type","error_message","path"}]}.
 *
 *              Log: /var/log/bit-rdstation/YYYY-MM-DD.log (FORA do webroot por design).
 *              Diretorio criado pelo bootstrap.sh (DEV) ou Task 3.5 + tmpfiles.d (PROD).
 *
 *              Spec: docs/superpowers/specs/2026-05-19-formulario-rodape-rdstation-design.md
 * Version:     1.2.0
 * Author:      Daniel Cambría / Bureau de Tecnologia Ltda.
 * Network:     true
 */

namespace BIT\ElementorFormRDStation;

defined( 'ABSPATH' ) || exit;

const VERSION               = '1.2.0';
const ACTION_NAME           = 'bit_rdstation';
const RD_API_ENDPOINT       = 'https://api.rd.services/platform/conversions';
const RD_TIMEOUT_SEC        = 8;
const DEFAULT_CONVERSION_ID = 'newsletter-footer-concertacao';
const LOG_DIR               = '/var/log/bit-rdstation';

/**
 * Registra a Form Action bit_rdstation no Elementor Pro.
 * Guard: so atua se Elementor Pro estiver carregado.
 */
add_action( 'plugins_loaded', function () {
    if ( ! class_exists( '\ElementorPro\Plugin' ) ) {
        return;
    }

    require_once __DIR__ . '/bit-elementor-form-rdstation/class-form-action.php';

    add_action( 'elementor_pro/forms/actions/register', function ( $registrar ) {
        $registrar->register( new Form_Action() );
    } );
}, 20 );

/**
 * Log estruturado em /var/log/bit-rdstation/ (FORA do webroot por design).
 *
 * Diretorio criado pelo bootstrap.sh (setup_rdstation_constants) em DEV
 * ou via Task 3.5 + /etc/tmpfiles.d/bit-rdstation.conf em PROD/HML.
 *
 * Sempre loga warn/error. Loga info apenas se BIT_RDSTATION_DEBUG=true.
 *
 * SEGURANCA (N3 v4):
 * NUNCA logar $url ou request headers — contem api_key na query string
 * (RDSTATION_API_KEY). Logar payload de $body com api_key tambem leakaria.
 * Loggar apenas: email (PII OK ate logrotate 90d), conversion_id, response body
 * resumido (substr 500 chars).
 *
 * @param string $level 'info' | 'warn' | 'error'
 * @param string $msg
 * @param array  $ctx
 */
function log( string $level, string $msg, array $ctx = [] ): void {
    if ( $level === 'info' && ! ( defined( 'BIT_RDSTATION_DEBUG' ) && BIT_RDSTATION_DEBUG ) ) {
        return;
    }

    if ( ! is_dir( LOG_DIR ) ) {
        // Fallback: tentar criar (caso o bootstrap nao tenha rodado, ex: deploy parcial)
        if ( ! @mkdir( LOG_DIR, 0750, true ) && ! is_dir( LOG_DIR ) ) {
            error_log( sprintf( '[BIT RDStation] LOG_DIR_MISSING: %s — criar via bootstrap.sh ou Task 3.5', LOG_DIR ) );
            return;
        }
    }

    if ( ! is_writable( LOG_DIR ) ) {
        error_log( sprintf( '[BIT RDStation] LOG_DIR_NOT_WRITABLE: %s — verificar owner/permissoes', LOG_DIR ) );
        return;
    }

    $log_file = LOG_DIR . '/' . gmdate( 'Y-m-d' ) . '.log';
    $line     = sprintf(
        "[%s] [%s] %s%s\n",
        gmdate( 'Y-m-d H:i:s' ),
        strtoupper( $level ),
        $msg,
        $ctx ? ' ' . wp_json_encode( $ctx, JSON_UNESCAPED_UNICODE ) : ''
    );

    // Escrita explicita — sem @ silent suppressor (violacao do padrao BIT).
    $result = file_put_contents( $log_file, $line, FILE_APPEND | LOCK_EX );
    if ( $result === false ) {
        error_log( '[BIT RDStation] WRITE_FAILED ' . $log_file . ' | ' . $line );
    }
}
