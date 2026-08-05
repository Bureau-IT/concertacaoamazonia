<?php
/**
 * Plugin Name: BIT TEC Versions Dedupe
 * Plugin URI:
 * Description: Garante que `tribe_events_calendar_options.previous_ecp_versions` permaneça deduplicado, evitando inflação que causa cascata WPML (incidente espiral 502 — 2026-05-02).
 * Version: 1.1.0
 * Author: Bureau de Tecnologia
 * Network: true
 *
 * Causa raiz documentada (incidente 2026-05-02):
 * TEC Pro acumula `previous_ecp_versions` em cada upgrade SEM dedupe — array crescia
 * para 103.917 entries duplicadas (2.28 MB). Cada `get_option('tribe_events_calendar_options')`
 * disparava cascata WPML (`apply_filters('option_tribe_events_calendar_options')` →
 * `WPML_Admin_Texts::translate_multiple` percorrendo todas as 103k strings) em CADA pageview
 * de boot WP, gastando 5-15s server-side.
 *
 * Validação na green isolada (2026-05-02 22:08 BRT):
 *   - Antes: 103.917 entries / 2.28 MB / TTFB cold espiral 20.7s
 *   - Após dedupe: 11 entries / 1.4 KB / TTFB cold espiral 7.9s (-62%)
 *
 * Esta defesa garante que array NUNCA volte a inflar:
 *   - Hook em `pre_update_option_tribe_events_calendar_options` aplica array_unique
 *   - Hook irmão `pre_update_site_option_*` cobre multisite via update_network_option (v1.1.0)
 *   - Invalida transient `bit_tec_ecp_versions` (do bit-tec-cache.php) quando array muda (v1.1.0)
 *   - Funciona para TEC core, TEC Pro, e qualquer plugin que escreva no option
 *
 * Coexiste com bit-tec-cache.php (v1.2.0+):
 *   - bit-tec-cache.php: cacheia LEITURA via filter `tribe_get_option_previous_ecp_versions` (transient 24h)
 *   - bit-tec-versions-dedupe.php: deduplica ESCRITA via filter `pre_update_option_*`
 *   - Ordem alfabética garante carregamento sem race
 *
 * Invalidação manual (forçar dedupe imediato):
 *   wp eval '$o=get_option("tribe_events_calendar_options"); $o["previous_ecp_versions"]=array_values(array_unique($o["previous_ecp_versions"]??[])); update_option("tribe_events_calendar_options",$o);'
 *
 * Changelog:
 *   1.1.0 (2026-05-04) — hook `pre_update_site_option_*` (multisite gap), invalidação transient
 *                        `bit_tec_ecp_versions`, threshold de log 100→10 (pega drift incremental).
 *   1.0.0 (2026-05-03) — versão inicial pós-incidente espiral 502.
 */

defined( 'ABSPATH' ) || exit;

// Hook para writes via update_option (single-site e blog atual em multisite).
add_filter( 'pre_update_option_tribe_events_calendar_options', 'bit_tec_versions_dedupe', 5, 2 );

// Hook irmão para writes via update_network_option (multisite — Auditor 3 / v1.1.0).
// Mesma lógica; closure para preservar assinatura (network filter recebe args extras).
add_filter( 'pre_update_site_option_tribe_events_calendar_options', static function ( $value, $old_value ) {
	return bit_tec_versions_dedupe( $value, $old_value );
}, 5, 2 );

/**
 * Deduplica `previous_ecp_versions` antes de qualquer write no option.
 *
 * @param mixed $value     Novo valor.
 * @param mixed $old_value Valor atual.
 * @return mixed Valor com array deduplicado.
 */
function bit_tec_versions_dedupe( $value, $old_value ) {
	if ( ! is_array( $value ) ) {
		return $value;
	}

	if ( isset( $value['previous_ecp_versions'] ) && is_array( $value['previous_ecp_versions'] ) ) {
		$before = count( $value['previous_ecp_versions'] );
		$value['previous_ecp_versions'] = array_values( array_unique( $value['previous_ecp_versions'] ) );
		$after = count( $value['previous_ecp_versions'] );

		// Log a partir de 10 entries removidas (pega drift incremental — v1.1.0).
		// Threshold anterior (>100) era cego para reinflação lenta.
		if ( ( $before - $after ) > 10 ) {
			error_log( sprintf(
				'[bit-tec-versions-dedupe] Deduplicado previous_ecp_versions: %d -> %d entries',
				$before,
				$after
			) );
		}

		// Invalida cache do bit-tec-cache.php quando array muda (v1.1.0).
		// Sem isso, leituras subsequentes via tribe_get_option() podem retornar transient
		// stale com array antigo — race conhecida do Auditor 2.
		if ( $before !== $after ) {
			delete_transient( 'bit_tec_ecp_versions' );
		}
	}

	return $value;
}
