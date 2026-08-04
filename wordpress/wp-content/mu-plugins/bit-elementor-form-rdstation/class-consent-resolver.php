<?php
/**
 * Traduz o valor do checkbox de consentimento (campo `acceptance` do Elementor
 * Pro) no `legal_bases` que a API do RD Station espera.
 *
 * Classe separada e sem dependencia de WordPress/Elementor pela mesma razao do
 * Sector_Normalizer e do Tag_Builder — assim a regra que decide se alguem
 * consentiu ou nao tem teste de unidade de verdade (tests/test-consent-resolver.php).
 * Dado o peso juridico dessa decisao, e a classe que MAIS merece teste.
 */

namespace BIT\ElementorFormRDStation;

defined( 'ABSPATH' ) || defined( 'BIT_RDSTATION_TEST' ) || exit;

class Consent_Resolver {

    public const GRANTED  = 'granted';
    public const DECLINED = 'declined';

    /**
     * Valores que significam "NAO consentiu", alem de string vazia.
     *
     * Um checkbox HTML desmarcado nem chega no POST — o Form_Record do Elementor
     * devolve '' para ele. Os demais valores da lista sao defesa contra temas,
     * plugins ou snippets que resolvam mandar '0'/'off'/'false' explicitamente.
     */
    private const FALSY = [ '0', 'off', 'false', 'no', 'nao', 'não', 'null', 'undefined' ];

    /**
     * Regra: consentimento e OPT-IN. So e `granted` com evidencia positiva.
     *
     * Qualquer duvida — campo ausente, vazio, valor estranho, tipo inesperado —
     * resolve para `declined`. Errar para o lado de `granted` significaria
     * declarar ao RD Station que alguem autorizou receber comunicacao quando
     * talvez nao tenha autorizado; o erro inverso so deixa de enviar email.
     *
     * @param mixed $raw Valor cru do field de consentimento.
     */
    public static function status( $raw ): string {
        if ( is_bool( $raw ) ) {
            return $raw ? self::GRANTED : self::DECLINED;
        }

        if ( ! is_scalar( $raw ) ) {
            return self::DECLINED;
        }

        $value = strtolower( trim( (string) $raw ) );

        if ( $value === '' || in_array( $value, self::FALSY, true ) ) {
            return self::DECLINED;
        }

        // Marcado: o browser manda 'on' para checkbox sem atributo value.
        return self::GRANTED;
    }

    /**
     * Monta o bloco legal_bases do payload.
     *
     * `communications` + `consent` e a combinacao que o RD Station usa para
     * registrar autorizacao de contato para fins de marketing.
     *
     * @param string $status self::GRANTED | self::DECLINED
     */
    public static function legal_bases( string $status ): array {
        return [
            [
                'category' => 'communications',
                'type'     => 'consent',
                'status'   => $status === self::GRANTED ? self::GRANTED : self::DECLINED,
            ],
        ];
    }
}
