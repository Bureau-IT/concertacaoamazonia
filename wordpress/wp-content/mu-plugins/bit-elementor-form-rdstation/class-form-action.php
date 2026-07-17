<?php
namespace BIT\ElementorFormRDStation;

defined( 'ABSPATH' ) || exit;

// IMPORTANTE: import explicito do log() do namespace BIT — sem isso o PHP cai
// no log() global (logaritmo natural) que aceita 1 argumento e nao 3.
use function BIT\ElementorFormRDStation\log;

class Form_Action extends \ElementorPro\Modules\Forms\Classes\Action_Base {

    public function get_name(): string {
        return ACTION_NAME;
    }

    public function get_label(): string {
        return 'RD Station (BIT)';
    }

    public function register_settings_section( $widget ): void {
        $widget->start_controls_section(
            'section_bit_rdstation',
            [
                'label'     => 'RD Station (BIT)',
                'condition' => [
                    'submit_actions' => ACTION_NAME,
                ],
            ]
        );

        $widget->add_control(
            'bit_rd_conversion_identifier',
            [
                'label'       => 'Conversion Identifier',
                'type'        => \Elementor\Controls_Manager::TEXT,
                'default'     => DEFAULT_CONVERSION_ID,
                'placeholder' => DEFAULT_CONVERSION_ID,
                'description' => 'Identificador da conversao no painel RD Station (kebab-case, estavel).',
            ]
        );

        $widget->add_control(
            'bit_rd_email_field',
            [
                'label'       => 'Campo Email (custom_id)',
                'type'        => \Elementor\Controls_Manager::TEXT,
                'default'     => 'email',
                'description' => 'custom_id do field tipo email do form (ex: email, form_email_desk).',
            ]
        );

        $widget->add_control(
            'bit_rd_name_field',
            [
                'label'       => 'Campo Nome (custom_id)',
                'type'        => \Elementor\Controls_Manager::TEXT,
                'default'     => '',
                'placeholder' => 'nome',
                'description' => 'custom_id do field de nome. Vazio = nao envia name.',
            ]
        );

        $widget->add_control(
            'bit_rd_company_field',
            [
                'label'       => 'Campo Organizacao (custom_id)',
                'type'        => \Elementor\Controls_Manager::TEXT,
                'default'     => '',
                'placeholder' => 'organizacao',
                'description' => 'custom_id do field de organizacao/empresa. Vazio = nao envia company_name.',
            ]
        );

        $widget->add_control(
            'bit_rd_uf_field',
            [
                'label'       => 'Campo UF (custom_id)',
                'type'        => \Elementor\Controls_Manager::TEXT,
                'default'     => '',
                'placeholder' => 'form_regiao_desk',
                'description' => 'custom_id do field select de UF/Regiao. Vazio = nao envia cf_uf.',
            ]
        );

        $widget->add_control(
            'bit_rd_tags',
            [
                'label'       => 'Tags (CSV)',
                'type'        => \Elementor\Controls_Manager::TEXT,
                'default'     => 'newsletter,concertacao-amazonia,footer-form',
                'description' => 'Tags aplicadas ao contato no RD Station, separadas por virgula.',
            ]
        );

        $widget->end_controls_section();
    }

    public function run( $record, $ajax_handler ): void {
        // N1 v4: try/catch global garante que exception PHP nao escape para o
        // wrapper do Elementor Pro Form Action — graceful em qualquer cenario.
        try {

            // 1. Conferir token disponivel — se nao, log + return (graceful)
            if ( ! defined( 'RDSTATION_API_KEY' ) || ! RDSTATION_API_KEY ) {
                log( 'warn', 'RDSTATION_API_KEY nao definido — submit ignorado (graceful)' );
                return;
            }

            // 2. Pegar settings da action
            $form_settings = $record->get( 'form_settings' );
            $conversion_id = trim( $form_settings['bit_rd_conversion_identifier'] ?? DEFAULT_CONVERSION_ID );
            $email_field   = trim( $form_settings['bit_rd_email_field'] ?? 'email' );
            $name_field    = trim( $form_settings['bit_rd_name_field'] ?? '' );
            $company_field = trim( $form_settings['bit_rd_company_field'] ?? '' );
            $uf_field      = trim( $form_settings['bit_rd_uf_field'] ?? '' );
            $tags_csv      = trim( $form_settings['bit_rd_tags'] ?? '' );

            // 3. Pegar fields submetidos
            $raw_fields  = $record->get( 'fields' );
            $email_raw   = $raw_fields[ $email_field ]['value'] ?? '';
            $name_raw    = $name_field ? ( $raw_fields[ $name_field ]['value'] ?? '' ) : '';
            $company_raw = $company_field ? ( $raw_fields[ $company_field ]['value'] ?? '' ) : '';
            $uf_raw      = $uf_field ? ( $raw_fields[ $uf_field ]['value'] ?? '' ) : '';

            $email = sanitize_email( $email_raw );
            if ( ! $email ) {
                log( 'warn', 'Email invalido ou vazio — submit ignorado', [ 'raw' => $email_raw, 'field' => $email_field ] );
                return;
            }

            // 4. Montar payload
            $payload = [
                'conversion_identifier' => $conversion_id ?: DEFAULT_CONVERSION_ID,
                'email'                 => $email,
            ];

            // name/company_name: campos padrao da API de conversoes (nao cf_*).
            // Persistencia validada empiricamente em 2026-07-17 (lead + empresa no painel RD).
            if ( $name_raw ) {
                $payload['name'] = sanitize_text_field( $name_raw );
            }
            if ( $company_raw ) {
                $payload['company_name'] = sanitize_text_field( $company_raw );
            }

            // UF: validar contra lista de siglas BR
            if ( $uf_raw ) {
                $uf_clean  = strtoupper( sanitize_text_field( $uf_raw ) );
                $br_states = [
                    'AC','AL','AM','AP','BA','CE','DF','ES','GO','MA','MG','MS',
                    'MT','PA','PB','PE','PI','PR','RJ','RN','RO','RR','RS','SC',
                    'SE','SP','TO',
                ];
                if ( in_array( $uf_clean, $br_states, true ) ) {
                    $payload['cf_uf'] = $uf_clean;
                } else {
                    log( 'warn', 'cf_uf valor invalido (nao e sigla BR) — ignorado', [ 'raw' => $uf_raw ] );
                }
            }

            if ( $tags_csv ) {
                $payload['tags'] = array_values(
                    array_filter( array_map( 'trim', explode( ',', $tags_csv ) ) )
                );
            }

            // legal_bases: "declined" por default — sem checkbox LGPD ainda.
            // TODO: quando checkbox LGPD for implementado, ler $form_settings['bit_rd_consent_field']
            $payload['legal_bases'] = [
                [ 'category' => 'communications', 'type' => 'consent', 'status' => 'declined' ],
            ];

            $body = [
                'event_type'   => 'CONVERSION',
                'event_family' => 'CDP',
                'payload'      => $payload,
            ];

            // 5. POST — api_key na query string
            // TODO performance: migrar para wp_schedule_single_event se saturacao FPM
            $url = add_query_arg( 'api_key', RDSTATION_API_KEY, RD_API_ENDPOINT );

            $response = wp_remote_post( $url, [
                'timeout' => RD_TIMEOUT_SEC,
                'headers' => [ 'Content-Type' => 'application/json' ],
                'body'    => wp_json_encode( $body ),
            ] );

            // 6. Log resultado — NUNCA chama add_error_message (graceful)
            if ( is_wp_error( $response ) ) {
                log( 'error', 'wp_remote_post falhou', [
                    'msg'   => $response->get_error_message(),
                    'email' => $email,
                ] );
                return;
            }

            $code      = wp_remote_retrieve_response_code( $response );
            $resp_body = wp_remote_retrieve_body( $response );

            if ( $code >= 200 && $code < 300 ) {
                log( 'info', "OK $code", [ 'email' => $email, 'cid' => $conversion_id ] );
            } else {
                log( 'error', "RD respondeu $code", [
                    'email' => $email,
                    'body'  => substr( $resp_body, 0, 500 ),
                ] );
            }

        } catch ( \Throwable $e ) {
            // N1 v4: NUNCA propaga exception — garante que form submit nao quebre.
            log( 'error', 'EXCEPTION em run(): ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ] );
            return;
        }
    }

    /**
     * on_export() — DEVE retornar $element (nao array vazio).
     * Retornar [] DESTROI o widget no export do template Elementor (BLOCKER v1 corrigido).
     */
    public function on_export( $element ): array {
        unset( $element['settings']['bit_rd_conversion_identifier'] );
        return $element;
    }
}
