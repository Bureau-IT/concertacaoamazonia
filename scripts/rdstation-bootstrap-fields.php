<?php
/**
 * rdstation-bootstrap-fields.php — One-shot idempotente.
 *
 * Cria custom fields no painel RD Station via API REST.
 * Endpoint /platform/contacts/fields exige OAuth2 Bearer Token, NAO api_key.
 *
 * Para gerar o Bearer Token (valido 24h):
 *   Opcao simples: criar campos manualmente em:
 *   https://app.rdstation.com.br/ > Configuracoes > Campos Personalizados
 *
 * Uso via WP-CLI (com Bearer Token):
 *   RDSTATION_BEARER=<token> docker exec -u www-data concertacao-dev-wordpress \
 *     wp --url="https://cambrasmax.local:8484/" eval-file /tmp/rdstation-bootstrap-fields.php
 *
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run via: wp eval-file /path/rdstation-bootstrap-fields.php\n" );
	exit( 1 );
}

$bearer = getenv( 'RDSTATION_BEARER' );
if ( ! $bearer ) {
	fwrite( STDERR, "ERRO: variavel RDSTATION_BEARER nao definida.\n" );
	fwrite( STDERR, "Alternativa mais simples: criar campos manualmente em:\n" );
	fwrite( STDERR, "  https://app.rdstation.com.br/ > Configuracoes > Campos Personalizados\n" );
	exit( 1 );
}

$fields_to_create = [
	[
		'api_identifier'    => 'cf_uf',
		'name'              => [ 'pt-BR' => 'UF' ],
		'label'             => [ 'pt-BR' => 'UF (sigla brasileira)' ],
		'data_type'         => 'STRING',
		'presentation_type' => 'TEXT_INPUT',
	],
	[
		'api_identifier'    => 'cf_consent_source',
		'name'              => [ 'pt-BR' => 'Origem do Consentimento' ],
		'label'             => [ 'pt-BR' => 'URL onde o consentimento foi coletado' ],
		'data_type'         => 'STRING',
		'presentation_type' => 'TEXT_INPUT',
	],
	[
		'api_identifier'    => 'cf_consent_timestamp',
		'name'              => [ 'pt-BR' => 'Timestamp do Consentimento' ],
		'label'             => [ 'pt-BR' => 'ISO 8601 do submit' ],
		'data_type'         => 'STRING',
		'presentation_type' => 'TEXT_INPUT',
	],
];

$response = wp_remote_get( 'https://api.rd.services/platform/contacts/fields', [
	'headers' => [ 'Authorization' => 'Bearer ' . $bearer ],
	'timeout' => 10,
] );

if ( is_wp_error( $response ) ) {
	fwrite( STDERR, "ERRO ao listar fields: " . $response->get_error_message() . "\n" );
	exit( 1 );
}

$code = wp_remote_retrieve_response_code( $response );
if ( $code !== 200 ) {
	fwrite( STDERR, "ERRO HTTP $code: " . wp_remote_retrieve_body( $response ) . "\n" );
	exit( 1 );
}

$body         = wp_remote_retrieve_body( $response );
$existing     = json_decode( $body, true );
$existing_ids = [];
if ( is_array( $existing ) ) {
	foreach ( $existing as $f ) {
		if ( isset( $f['api_identifier'] ) ) {
			$existing_ids[] = $f['api_identifier'];
		}
	}
}
echo "Fields existentes: " . count( $existing_ids ) . "\n";

foreach ( $fields_to_create as $field ) {
	if ( in_array( $field['api_identifier'], $existing_ids, true ) ) {
		echo "[OK] " . $field['api_identifier'] . " ja existe — pulando\n";
		continue;
	}
	$resp = wp_remote_post( 'https://api.rd.services/platform/contacts/fields', [
		'timeout' => 10,
		'headers' => [
			'Authorization' => 'Bearer ' . $bearer,
			'Content-Type'  => 'application/json',
		],
		'body' => wp_json_encode( $field ),
	] );
	if ( is_wp_error( $resp ) ) {
		echo "[ERRO] " . $field['api_identifier'] . ": " . $resp->get_error_message() . "\n";
		continue;
	}
	$code = wp_remote_retrieve_response_code( $resp );
	if ( $code === 201 ) {
		echo "[CRIADO] " . $field['api_identifier'] . "\n";
	} else {
		echo "[?] " . $field['api_identifier'] . " HTTP $code: " .
			 substr( wp_remote_retrieve_body( $resp ), 0, 200 ) . "\n";
	}
}
echo "done\n";
