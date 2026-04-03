<?php
/**
 * Plugin Name: BIT TEC Cache
 * Plugin URI:
 * Description: Cache 24h de tribe_get_option('previous_ecp_versions') via filtro — evita DB query + usort() a cada request.
 * Version: 1.2.0
 * Author: Bureau de Tecnologia
 * Network: true
 *
 * tribe_get_option() expõe apply_filters("tribe_get_option_{$optionName}") — interceptamos aqui.
 * Spike CPU 02/04/2026: usort() em TEC install.php:14 aparecia centenas de vezes/hora no slow log
 * porque tribe_events_is_new_install() fazia DB query + usort() a cada request sem cache.
 * O TEC não tem guard if(!function_exists) em install.php, então override direto causa fatal error.
 *
 * Invalidação manual: wp eval 'delete_transient("bit_tec_ecp_versions"); echo "OK\n";'
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'tribe_get_option_previous_ecp_versions', 'bit_tec_cache_ecp_versions', 1, 3 );

/**
 * Cacheia o valor de previous_ecp_versions por 24h via transient.
 * Elimina DB query + usort() a cada request em tribe_events_is_new_install().
 *
 * @param mixed  $value      Valor retornado por tribe_get_option.
 * @param string $optionName Nome da opção.
 * @param mixed  $default    Valor padrão.
 * @return mixed Array de versões (do transient ou do banco).
 */
function bit_tec_cache_ecp_versions( $value, $optionName, $default ) {
	$cache_key = 'bit_tec_ecp_versions';
	$cached    = get_transient( $cache_key );

	if ( false !== $cached ) {
		return $cached;
	}

	// Não cachear valores inválidos (option ausente no banco retorna false).
	if ( false === $value || null === $value ) {
		return ! is_null( $default ) ? $default : [];
	}

	// Primeira chamada válida: $value vem do banco — armazenar no transient.
	set_transient( $cache_key, $value, DAY_IN_SECONDS );

	return $value;
}
