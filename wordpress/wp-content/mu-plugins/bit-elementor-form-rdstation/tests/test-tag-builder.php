<?php
/**
 * Teste de unidade do Tag_Builder. Sem dependencia de WordPress nem de
 * Elementor Pro — roda com PHP puro:
 *
 *     php wordpress/wp-content/mu-plugins/bit-elementor-form-rdstation/tests/test-tag-builder.php
 *
 * Exit 0 = tudo passou. Exit 1 = alguma assercao falhou.
 */

declare( strict_types = 1 );

define( 'BIT_RDSTATION_TEST', true );

require_once __DIR__ . '/../class-tag-builder.php';

use BIT\ElementorFormRDStation\Tag_Builder;

$failures = 0;
$total    = 0;

function check( string $label, array $expected, array $actual ): void {
    global $failures, $total;
    $total++;

    if ( $expected === $actual ) {
        printf( "  ok   %s\n", $label );
        return;
    }

    $failures++;
    printf(
        "  FAIL %s\n         esperado: %s\n         recebido: %s\n",
        $label,
        json_encode( $expected, JSON_UNESCAPED_UNICODE ),
        json_encode( $actual, JSON_UNESCAPED_UNICODE )
    );
}

function check_str( string $label, string $expected, string $actual ): void {
    global $failures, $total;
    $total++;

    if ( $expected === $actual ) {
        printf( "  ok   %s\n", $label );
        return;
    }

    $failures++;
    printf( "  FAIL %s\n         esperado: %s\n         recebido: %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) );
}

echo "Tag_Builder::lang_tag()\n";

check_str( 'pt-br vira pt',        'pt', Tag_Builder::lang_tag( 'pt-br' ) );
check_str( 'PT-BR (maiusculo)',    'pt', Tag_Builder::lang_tag( 'PT-BR' ) );
check_str( 'en continua en',       'en', Tag_Builder::lang_tag( 'en' ) );
check_str( 'en-us vira en',        'en', Tag_Builder::lang_tag( 'en-us' ) );
check_str( 'es (idioma futuro)',   'es', Tag_Builder::lang_tag( 'es' ) );
check_str( 'null = sem tag',       '',   Tag_Builder::lang_tag( null ) );
check_str( 'vazio = sem tag',      '',   Tag_Builder::lang_tag( '' ) );
check_str( 'espacos = sem tag',    '',   Tag_Builder::lang_tag( '   ' ) );
check_str( 'lixo nao vira tag',    '',   Tag_Builder::lang_tag( '12345' ) );
check_str( 'lixo longo nao passa', '',   Tag_Builder::lang_tag( 'portugues' ) );

echo "\nTag_Builder::build()\n";

// --- Caso real: footer PT (posts 72234 / 89361) ----------------------------
check(
    'footer PT',
    [ 'newsletter', 'concertacao-amazonia', 'footer-form', 'bit-website-integration', 'pt', 'bit-plugins-rdstation' ],
    Tag_Builder::build( 'newsletter,concertacao-amazonia,footer-form,bit-website-integration', 'pt-br' )
);

// --- Caso real: footer EN (posts 72921 / 89785) ----------------------------
check(
    'footer EN',
    [ 'newsletter', 'concertacao-amazonia', 'footer-form', 'bit-website-integration', 'en', 'bit-plugins-rdstation' ],
    Tag_Builder::build( 'newsletter,concertacao-amazonia,footer-form,bit-website-integration', 'en' )
);

// --- Caso real: contato PT (post 672) --------------------------------------
check(
    'contato PT',
    [ 'contato', 'concertacao-amazonia', 'bit-website-integration', 'pt', 'bit-plugins-rdstation' ],
    Tag_Builder::build( 'contato,concertacao-amazonia,bit-website-integration', 'pt-br' )
);

// --- A tag do plugin entra mesmo sem CSV nenhum ----------------------------
check(
    'CSV vazio ainda leva a tag do plugin',
    [ 'bit-plugins-rdstation' ],
    Tag_Builder::build( '', null )
);

check(
    'CSV vazio + idioma',
    [ 'en', 'bit-plugins-rdstation' ],
    Tag_Builder::build( '', 'en' )
);

// --- Higiene do CSV --------------------------------------------------------
check(
    'espacos e virgulas soltas sao limpos',
    [ 'newsletter', 'contato', 'pt', 'bit-plugins-rdstation' ],
    Tag_Builder::build( '  newsletter , , contato ,,', 'pt-br' )
);

// --- Dedupe ----------------------------------------------------------------
check(
    'duplicata no CSV cai fora',
    [ 'newsletter', 'pt', 'bit-plugins-rdstation' ],
    Tag_Builder::build( 'newsletter,newsletter', 'pt-br' )
);

check(
    'dedupe e case-insensitive (RD trata Newsletter != newsletter)',
    [ 'Newsletter', 'pt', 'bit-plugins-rdstation' ],
    Tag_Builder::build( 'Newsletter,newsletter,NEWSLETTER', 'pt-br' )
);

check(
    'operador que ja escreveu a tag do plugin no CSV nao a duplica',
    [ 'newsletter', 'bit-plugins-rdstation' ],
    Tag_Builder::build( 'newsletter,bit-plugins-rdstation', null )
);

check(
    'operador que ja escreveu o idioma no CSV nao o duplica',
    [ 'newsletter', 'pt', 'bit-plugins-rdstation' ],
    Tag_Builder::build( 'newsletter,pt', 'pt-br' )
);

// --- WPML ausente ----------------------------------------------------------
check(
    'sem WPML: so CSV + tag do plugin',
    [ 'newsletter', 'bit-plugins-rdstation' ],
    Tag_Builder::build( 'newsletter', null )
);

printf( "\n%d assercoes, %d falha(s)\n", $total, $failures );
exit( $failures > 0 ? 1 : 0 );
