<?php
/**
 * Teste de unidade do Sector_Normalizer. Sem dependencia de WordPress nem de
 * Elementor Pro — roda com PHP puro:
 *
 *     php wordpress/wp-content/mu-plugins/bit-elementor-form-rdstation/tests/test-sector-normalizer.php
 *
 * Exit 0 = tudo passou. Exit 1 = alguma assercao falhou.
 */

declare( strict_types = 1 );

define( 'BIT_RDSTATION_TEST', true );

require_once __DIR__ . '/../class-sector-normalizer.php';

use BIT\ElementorFormRDStation\Sector_Normalizer;

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
    printf( "  FAIL %s\n         esperado: %s\n         recebido: %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) );
}

echo "Sector_Normalizer::normalize()\n";

// --- As 5 opcoes PT do select (footers PT, posts 72234 e 89361) -------------
check( 'PT Setor Público',   'Setor Público',   Sector_Normalizer::normalize( 'Setor Público' ) );
check( 'PT Academia',        'Academia',        Sector_Normalizer::normalize( 'Academia' ) );
check( 'PT Sociedade Civil', 'Sociedade Civil', Sector_Normalizer::normalize( 'Sociedade Civil' ) );
check( 'PT Setor Privado',   'Setor Privado',   Sector_Normalizer::normalize( 'Setor Privado' ) );
check( 'PT Imprensa',        'Imprensa',        Sector_Normalizer::normalize( 'Imprensa' ) );

// --- As 5 opcoes EN (footers EN, posts 72921 e 89785) ----------------------
// Este bloco e a razao de ser da classe: EN precisa cair no canonico PT, senao
// o RD Station acumula dois valores para o mesmo conceito.
check( 'EN Public Sector',  'Setor Público',   Sector_Normalizer::normalize( 'Public Sector' ) );
check( 'EN Academia',       'Academia',        Sector_Normalizer::normalize( 'Academia' ) );
check( 'EN Civil Society',  'Sociedade Civil', Sector_Normalizer::normalize( 'Civil Society' ) );
check( 'EN Private Sector', 'Setor Privado',   Sector_Normalizer::normalize( 'Private Sector' ) );
check( 'EN Press',          'Imprensa',        Sector_Normalizer::normalize( 'Press' ) );

// --- Robustez de entrada ---------------------------------------------------
check( 'casing indiferente (minusculas)', 'Setor Público', Sector_Normalizer::normalize( 'setor público' ) );
check( 'casing indiferente (MAIUSCULAS)', 'Setor Público', Sector_Normalizer::normalize( 'SETOR PÚBLICO' ) );
check( 'acento multibyte em maiuscula',   'Setor Público', Sector_Normalizer::normalize( 'Setor PÚBLICO' ) );
check( 'espaco em volta e ignorado',      'Imprensa',      Sector_Normalizer::normalize( '  Imprensa  ' ) );

// --- Desconhecido devolve '' (quem chama envia cru + warn) ----------------
check( 'valor fora do mapa',        '', Sector_Normalizer::normalize( 'Terceiro Setor' ) );
check( 'string vazia',              '', Sector_Normalizer::normalize( '' ) );
check( 'so espacos',                '', Sector_Normalizer::normalize( '   ' ) );
check( 'sem acento nao e aceito',   '', Sector_Normalizer::normalize( 'Setor Publico' ) );

printf( "\n%d assercoes, %d falha(s)\n", $total, $failures );

exit( $failures > 0 ? 1 : 0 );
