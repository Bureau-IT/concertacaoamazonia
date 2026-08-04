<?php
/**
 * Teste de unidade do Consent_Resolver. Sem WordPress, sem Elementor:
 *
 *     php wordpress/wp-content/mu-plugins/bit-elementor-form-rdstation/tests/test-consent-resolver.php
 *
 * Exit 0 = tudo passou. Exit 1 = alguma assercao falhou.
 */

declare( strict_types = 1 );

define( 'BIT_RDSTATION_TEST', true );

require_once __DIR__ . '/../class-consent-resolver.php';

use BIT\ElementorFormRDStation\Consent_Resolver;

$failures = 0;
$total    = 0;

function check( string $label, string $expected, string $actual ): void {
    global $failures, $total;
    $total++;

    if ( $expected === $actual ) {
        printf( "  ok   %s\n", $label );
        return;
    }

    $failures++;
    printf( "  FAIL %s\n         esperado: %s\n         recebido: %s\n", $label, $expected, $actual );
}

const G = Consent_Resolver::GRANTED;
const D = Consent_Resolver::DECLINED;

echo "Consent_Resolver::status() — caminho feliz\n";

// 'on' e o que o browser manda para checkbox marcado sem atributo value.
check( "'on' (checkbox marcado)", G, Consent_Resolver::status( 'on' ) );
check( "'ON' maiusculo",          G, Consent_Resolver::status( 'ON' ) );
check( "' on ' com espacos",      G, Consent_Resolver::status( ' on ' ) );
check( "'1'",                     G, Consent_Resolver::status( '1' ) );
check( "'yes'",                   G, Consent_Resolver::status( 'yes' ) );
check( "bool true",               G, Consent_Resolver::status( true ) );
check( "texto do aceite",         G, Consent_Resolver::status( 'Aceito receber comunicações' ) );

echo "\nConsent_Resolver::status() — tudo que NAO e consentimento\n";

// Checkbox desmarcado nao chega no POST; Form_Record devolve ''.
check( "'' (checkbox desmarcado)", D, Consent_Resolver::status( '' ) );
check( "'   ' (so espacos)",       D, Consent_Resolver::status( '   ' ) );
check( "null (campo ausente)",     D, Consent_Resolver::status( null ) );
check( "bool false",               D, Consent_Resolver::status( false ) );
check( "'0'",                      D, Consent_Resolver::status( '0' ) );
check( "'off'",                    D, Consent_Resolver::status( 'off' ) );
check( "'OFF' maiusculo",          D, Consent_Resolver::status( 'OFF' ) );
check( "'false'",                  D, Consent_Resolver::status( 'false' ) );
check( "'no'",                     D, Consent_Resolver::status( 'no' ) );
check( "'nao'",                    D, Consent_Resolver::status( 'nao' ) );
check( "'não' com til",            D, Consent_Resolver::status( 'não' ) );
check( "'undefined' (bug de JS)",  D, Consent_Resolver::status( 'undefined' ) );

echo "\nConsent_Resolver::status() — tipos inesperados caem para declined\n";

check( "array",  D, Consent_Resolver::status( [ 'on' ] ) );
check( "objeto", D, Consent_Resolver::status( new stdClass() ) );

echo "\nConsent_Resolver::legal_bases()\n";

$total++;
$granted = Consent_Resolver::legal_bases( G );
if ( $granted === [ [ 'category' => 'communications', 'type' => 'consent', 'status' => 'granted' ] ] ) {
    echo "  ok   granted monta o bloco correto\n";
} else {
    $failures++;
    echo "  FAIL granted: " . json_encode( $granted ) . "\n";
}

$total++;
$declined = Consent_Resolver::legal_bases( D );
if ( $declined === [ [ 'category' => 'communications', 'type' => 'consent', 'status' => 'declined' ] ] ) {
    echo "  ok   declined monta o bloco correto\n";
} else {
    $failures++;
    echo "  FAIL declined: " . json_encode( $declined ) . "\n";
}

// Blindagem: valor arbitrario nunca vira status arbitrario no payload.
$total++;
$lixo = Consent_Resolver::legal_bases( 'talvez' );
if ( $lixo[0]['status'] === 'declined' ) {
    echo "  ok   status invalido cai para declined (nao vaza para o payload)\n";
} else {
    $failures++;
    echo "  FAIL status invalido virou: " . $lixo[0]['status'] . "\n";
}

printf( "\n%d assercoes, %d falha(s)\n", $total, $failures );
exit( $failures > 0 ? 1 : 0 );
