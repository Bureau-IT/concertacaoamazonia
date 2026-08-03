<?php
/**
 * Normalizacao do valor de setor (select field_8aee261 dos footers) para o
 * valor canonico enviado ao RD Station em cf_setor.
 *
 * Por que existe como classe separada: a Form_Action estende
 * \ElementorPro\Modules\Forms\Classes\Action_Base, entao nada que more nela pode
 * ser exercitado sem o Elementor Pro carregado. Esta classe nao tem dependencia
 * nenhuma — nem de WordPress — e por isso tem teste de unidade real em
 * tests/test-sector-normalizer.php.
 *
 * O canonico e o label PT (nao um slug) porque quem le esse campo e o time de
 * marketing dentro do painel do RD Station.
 */

namespace BIT\ElementorFormRDStation;

defined( 'ABSPATH' ) || defined( 'BIT_RDSTATION_TEST' ) || exit;

class Sector_Normalizer {

    /**
     * Chaves em minusculas (comparacao case-insensitive), valores canonicos.
     *
     * Os labels PT e EN do MESMO select apontam para o mesmo canonico — e a
     * razao de ser desta classe. Sem isso o RD Station acumularia
     * "Public Sector" e "Setor Publico" como valores distintos do mesmo
     * conceito, partindo toda segmentacao por setor em duas.
     *
     * "Academia" e identico em PT e EN, por isso aparece uma unica vez.
     */
    private const SECTOR_MAP = [
        'setor público'   => 'Setor Público',
        'public sector'   => 'Setor Público',
        'setor privado'   => 'Setor Privado',
        'private sector'  => 'Setor Privado',
        'sociedade civil' => 'Sociedade Civil',
        'civil society'   => 'Sociedade Civil',
        'imprensa'        => 'Imprensa',
        'press'           => 'Imprensa',
        'academia'        => 'Academia',
    ];

    /**
     * Devolve o valor canonico, ou string vazia se o valor nao estiver no mapa.
     *
     * String vazia NAO significa "descartar" — significa "sem canonico". Quem
     * chama envia o valor cru e loga warn, para que o drift apareca no painel
     * do RD em vez de virar perda silenciosa de um campo obrigatorio. Contraste
     * proposital com o cf_uf, que descarta: la existe contrato semantico (as 27
     * siglas BR), aqui o campo e texto livre por decisao de design.
     *
     * @param string $raw Valor submetido pelo form.
     * @return string Canonico, ou '' se desconhecido.
     */
    public static function normalize( string $raw ): string {
        $key = self::lower( trim( $raw ) );

        return self::SECTOR_MAP[ $key ] ?? '';
    }

    /**
     * mb_strtolower com degradacao graciosa.
     *
     * "Setor Publico" tem acento e strtolower() nao da conta de multibyte. Se
     * mbstring faltar, o lookup dos valores acentuados falha e o valor vai cru
     * (+ warn) — degrada, nao quebra. Nunca fatal.
     */
    private static function lower( string $value ): string {
        if ( function_exists( 'mb_strtolower' ) ) {
            return mb_strtolower( $value, 'UTF-8' );
        }

        return strtolower( $value );
    }
}
