<?php
/**
 * Montagem da lista de tags enviada ao RD Station.
 *
 * Por que existe como classe separada: mesma razao do Sector_Normalizer — a
 * Form_Action estende \ElementorPro\Modules\Forms\Classes\Action_Base e nada que
 * more nela roda sem o Elementor Pro carregado. Esta classe nao depende de
 * WordPress nem de Elementor, e por isso tem teste de unidade de verdade em
 * tests/test-tag-builder.php.
 *
 * A resolucao do idioma (que exige WPML) fica FORA daqui de proposito: esta
 * classe recebe o codigo ja resolvido e so decide como vira tag.
 */

namespace BIT\ElementorFormRDStation;

defined( 'ABSPATH' ) || defined( 'BIT_RDSTATION_TEST' ) || exit;

class Tag_Builder {

    /**
     * Tag fixa em TODO lead originado por este plugin.
     *
     * Serve para o time de marketing separar, dentro do painel do RD, o que
     * entrou pela integracao do site do que entrou por importacao manual,
     * landing page do proprio RD ou outra origem qualquer.
     */
    public const PLUGIN_TAG = 'bit-plugins-rdstation';

    /**
     * Monta a lista final de tags.
     *
     * Ordem: as tags configuradas no form, depois o idioma, depois a tag do
     * plugin. E so estetica no painel — o RD nao da semantica a ordem —, mas
     * mantem o que o operador escreveu na frente.
     *
     * @param string      $csv       Conteudo do controle bit_rd_tags (CSV).
     * @param string|null $lang_code Codigo WPML ja resolvido (ex: 'pt-br', 'en').
     *                               null/vazio = nao acrescenta tag de idioma.
     * @return string[] Lista sem duplicatas e sem entradas vazias.
     */
    public static function build( string $csv, ?string $lang_code = null ): array {
        $tags = array_map( 'trim', explode( ',', $csv ) );

        $lang_tag = self::lang_tag( $lang_code );
        if ( $lang_tag !== '' ) {
            $tags[] = $lang_tag;
        }

        $tags[] = self::PLUGIN_TAG;

        // Dedupe case-insensitive preservando a primeira grafia vista: o RD trata
        // "Newsletter" e "newsletter" como tags DIFERENTES, entao mandar as duas
        // parte a segmentacao em duas sem ninguem perceber.
        $seen = [];
        $out  = [];

        foreach ( $tags as $tag ) {
            if ( $tag === '' ) {
                continue;
            }

            $key = self::lower( $tag );
            if ( isset( $seen[ $key ] ) ) {
                continue;
            }

            $seen[ $key ] = true;
            $out[]        = $tag;
        }

        return $out;
    }

    /**
     * Converte o codigo WPML na tag de idioma.
     *
     * 'pt-br' vira 'pt' (e nao 'pt-br') porque a tag responde "em que idioma a
     * pessoa preencheu", nao "que locale o WPML usa". Se um dia entrar 'es',
     * 'es-mx' ou 'fr', a mesma regra ja resolve sem tocar nesta classe.
     *
     * @return string Tag, ou '' quando nao ha idioma resolvido.
     */
    public static function lang_tag( ?string $lang_code ): string {
        $code = self::lower( trim( (string) $lang_code ) );

        if ( $code === '' ) {
            return '';
        }

        // Regiao fora: pt-br -> pt, en-us -> en.
        $base = explode( '-', $code )[0];

        // Defensivo: qualquer coisa que nao pareca codigo de idioma vira tag
        // nenhuma, em vez de sujar o painel com lixo.
        if ( ! preg_match( '/^[a-z]{2,3}$/', $base ) ) {
            return '';
        }

        return $base;
    }

    /**
     * mb_strtolower com degradacao graciosa — mesma razao do Sector_Normalizer:
     * sem mbstring, tag acentuada apenas deixa de deduplicar, nao quebra.
     */
    private static function lower( string $value ): string {
        if ( function_exists( 'mb_strtolower' ) ) {
            return mb_strtolower( $value, 'UTF-8' );
        }

        return strtolower( $value );
    }
}
