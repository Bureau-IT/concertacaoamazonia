<?php
/**
 * Plugin Name: BIT — JSF Query Count Sync
 * Description: Sincroniza spans <span class="jet-engine-query-count"> (JetEngine Query Builder dynamic tag) com eventos AJAX do JetSmartFilters. Sem este mu-plugin, o contador "Mostrando X de Y" da Espiral fica estático após filtros, paginação ou Carregar mais.
 * Version: 1.1.0
 * Author: Daniel Cambría
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Mapa query_id (JetEngine Query Builder) → provider key (JSF _element_id do listing).
 * Filter `bit_jsf_query_count_provider_map` permite estender sem editar este arquivo.
 *
 * Default cobre a página Espiral de Conhecimento (post 26826): listing #estudos
 * com custom_query_id=12 e dois headings dynamic-tag jet-query-count(query_id=12).
 */
function bit_jsf_query_count_provider_map() {
	return apply_filters( 'bit_jsf_query_count_provider_map', [
		'12' => 'estudos',
	] );
}

/**
 * Enqueue do JS de sincronização — disparado só quando a página renderiza pelo menos
 * um <span class="jet-engine-query-count">. Detectamos via filter no shutdown ou via
 * heurística: enqueue em qualquer page singular que tenha _elementor_data contendo "jet-query-count".
 */
add_action( 'wp_enqueue_scripts', function () {
	if ( is_admin() ) return;
	if ( ! is_singular() ) return;

	$post = get_post();
	if ( ! $post ) return;

	$elementor_data = get_post_meta( $post->ID, '_elementor_data', true );
	if ( empty( $elementor_data ) || strpos( $elementor_data, 'jet-query-count' ) === false ) return;

	// Inline script — depende de jQuery (já enfileirado pelo JSF/JetEngine)
	wp_register_script( 'bit-jsf-query-count-sync', '', [ 'jquery' ], '1.1.0', true );
	wp_enqueue_script( 'bit-jsf-query-count-sync' );

	$provider_map_json = wp_json_encode( bit_jsf_query_count_provider_map() );

	$inline = <<<JS
( function ( \$ ) {
	'use strict';

	var providerMap = {$provider_map_json};

	// Format originals: armazena o texto do heading pai de cada span jet-engine-query-count
	// para que possamos re-aplicar o "custom_format" após cada AJAX.
	// O dynamic tag renderiza só o número (não o "Mostrando X de Y..."), então o texto
	// está nos siblings/parents do span. Captura inicial:
	var initialFormats = new Map();

	// Último total conhecido por queryId do JSF (ex: 'estudos').
	// Fonte: pagination.found_posts da resposta AJAX do JSF/JetEngine — é o ÚNICO
	// lugar onde o total já filtrado está disponível no client. O objeto
	// JetSmartFilters.filterGroups[...] NÃO expõe found_posts nesta versão
	// (grp.props é null), por isso não serve como fonte.
	var lastFoundPosts = {};

	// O ajaxSuccess dispara ANTES do jet-filter-content-rendered, então quando o
	// handler de render roda o total já está em lastFoundPosts.
	\$( document ).ajaxSuccess( function ( evt, xhr, settings ) {
		var json = xhr && xhr.responseJSON;
		if ( ! json || ! json.pagination || typeof json.pagination.found_posts === 'undefined' ) return;

		var found = parseInt( json.pagination.found_posts, 10 );
		if ( ! isFinite( found ) ) return;

		// settings.data traz 'provider=jet-engine%2Festudos' (urlencoded). O queryId
		// é o segmento após a barra. Sem provider identificável, guarda no coringa '*'.
		var queryId = '*';
		var raw = typeof settings.data === 'string' ? settings.data : '';
		var m = raw.match( /(?:^|&)provider=([^&]*)/ );
		if ( m ) {
			var decoded = decodeURIComponent( m[1] );
			var slash = decoded.indexOf( '/' );
			if ( slash > -1 ) queryId = decoded.slice( slash + 1 );
		}
		lastFoundPosts[ queryId ] = found;
	} );

	function resolveTotal( queryId ) {
		if ( typeof lastFoundPosts[ queryId ] !== 'undefined' ) return lastFoundPosts[ queryId ];
		if ( typeof lastFoundPosts['*'] !== 'undefined' ) return lastFoundPosts['*'];
		return null;
	}

	function captureFormats() {
		\$( '.jet-engine-query-count' ).each( function () {
			var \$span = \$( this );
			var queryId = String( \$span.data( 'query' ) || '' );
			var countType = '';
			// classes: count-type-total, count-type-start-item, count-type-end-item, count-type-visible
			\$.each( this.classList, function ( i, cls ) {
				if ( cls.indexOf( 'count-type-' ) === 0 ) countType = cls.replace( 'count-type-', '' );
			} );
			var initialValue = parseInt( \$span.text(), 10 );
			if ( ! isFinite( initialValue ) ) initialValue = 0;
			initialFormats.set( this, {
				queryId: queryId,
				countType: countType,
				initialValue: initialValue
			} );
		} );
	}

	function updateCounters( queryId, queryArgs ) {
		// queryArgs: { total, visible, startItem, endItem }
		// queryId: queryId do JSF (ex: 'estudos') — NÃO o providerKey ('jet-engine').
		\$( '.jet-engine-query-count' ).each( function () {
			var meta = initialFormats.get( this );
			if ( ! meta ) return;
			// Mapeia query_id do JetEngine (ex: '12') para o queryId do JSF; se não bate, skip.
			var mapped = providerMap[ meta.queryId ];
			if ( mapped !== queryId ) return;

			var v = null;
			switch ( meta.countType ) {
				case 'total':       v = queryArgs.total; break;
				case 'visible':     v = queryArgs.visible; break;
				case 'start-item':  v = queryArgs.startItem; break;
				case 'end-item':    v = queryArgs.endItem; break;
				default:            v = queryArgs.total;
			}
			if ( v !== null && isFinite( v ) ) {
				\$( this ).text( v );
			}
		} );
	}

	function countCards( \$container, queryId ) {
		// Cards renderizados no estado atual. Cobre tanto o re-render do filtro
		// (página 1) quanto o "Carregar mais" (que empilha no mesmo container).
		if ( \$container && \$container.length ) {
			var n = \$container.find( '.jet-listing-grid__item' ).length;
			if ( n ) return n;
		}
		var \$byId = \$( '#' + queryId );
		if ( \$byId.length ) return \$byId.find( '.jet-listing-grid__item' ).length;
		return \$( '.jet-listing-grid__item' ).length;
	}

	function readTotalFromDOM( queryId ) {
		// Lê o total atual exibido no DOM para preservar estado entre eventos.
		var total = null;
		\$( '.jet-engine-query-count.count-type-total' ).each( function () {
			var meta = initialFormats.get( this );
			if ( meta && providerMap[ meta.queryId ] === queryId && total === null ) {
				var v = parseInt( \$( this ).text(), 10 );
				if ( isFinite( v ) ) total = v;
			}
		} );
		return total;
	}

	function syncProvider( queryId, \$container ) {
		var visible = countCards( \$container, queryId );
		var total = resolveTotal( queryId );
		if ( total === null ) total = readTotalFromDOM( queryId );

		updateCounters( queryId, {
			total:     total,
			visible:   visible,
			startItem: visible > 0 ? 1 : 0,
			endItem:   visible
		} );
		// Re-capturar formats para refletir o novo estado (próximo evento usa esses valores)
		captureFormats();
	}

	\$( document ).ready( function () {
		captureFormats();

		// Evento JSF: disparado após qualquer filtro AJAX.
		// Payload: [\$provider, providerInstance, providerKey, queryId]
		// ATENÇÃO: providerKey é o content provider ('jet-engine'); quem casa com o
		// providerMap é o queryId ('estudos'). Usar providerKey aqui trava o contador.
		\$( document ).on( 'jet-filter-content-rendered', function ( evt, \$provider, providerInstance, providerKey, queryId ) {
			var id = queryId || ( providerInstance && providerInstance.queryId );
			if ( ! id ) return;
			syncProvider( id, \$provider );
		} );

		// Evento JetEngine: disparado após Load More do listing-grid (não passa pelo JSF).
		// O payload do evento na minificação atual não entrega o jQuery wrapper de forma
		// estável; usamos o DOM: para cada provider mapeado, lê os cards em #<queryId>.
		\$( document ).on( 'jet-engine/listing-grid/after-load-more', function () {
			\$.each( providerMap, function ( jetQueryId, queryId ) {
				var \$container = \$( '#' + queryId );
				if ( ! \$container.length ) return;
				syncProvider( queryId, \$container );
			} );
		} );
	} );

} )( jQuery );
JS;

	wp_add_inline_script( 'bit-jsf-query-count-sync', $inline );
}, 20 );
