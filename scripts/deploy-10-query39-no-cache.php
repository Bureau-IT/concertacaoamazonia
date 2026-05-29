<?php
/**
 * deploy-10-query39-no-cache.php — desabilita cache_query na JetEngine Query 39
 * ("Query filter counter Artistas"), usada pelo widget de card (4fee551) do Atlas EN/PT.
 *
 * CAUSA RAIZ (descoberta na validação pós-deploy 2026-05-29):
 * Com cache_query=true, o JetEngine memoiza o resultado da query no object cache (Redis)
 * com uma chave que NÃO inclui o idioma WPML. O primeiro request que populou o cache foi
 * no idioma pt-br, então a página EN recebia os post ids PT (91836...) em vez dos EN
 * (92357...). Como o mapa (provider map-lista) renderiza markers com ids EN e o card-listing
 * renderizava ids PT, o JetEngine não casava card↔marker e o clique no card NÃO abria o
 * popup no mapa (sintoma observável). Via WP-CLI com wpml_switch_language('en') a query
 * retornava EN corretamente — o que mascarou a causa e levou a investigar 7 hipóteses
 * (jet_cache table, Redis, WPML config, element_cache, OPcache, negotiation type, listing meta)
 * antes de instrumentar a query no contexto real do request frontend.
 *
 * A query 39 é leve (orderby title, 4 posts) — desabilitar o cache é seguro e definitivo.
 * Idempotente. DRY-RUN por padrão; APPLY=1 grava.
 *
 * IMPORTANTE: rodar SEMPRE com --url=https://<host>/cultura/ (blog 2). A query vive na
 * tabela GLOBAL wp_jet_post_types, mas o object cache contaminado é por-blog.
 */
if ( ! defined('ABSPATH') ) { exit; }
$APPLY = getenv('APPLY') === '1';
$QID = (int) (getenv('QID') ?: 39);

global $wpdb;
$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}jet_post_types WHERE id=%d", $QID), ARRAY_A);
if ( ! $row ) { echo json_encode(['error'=>"query $QID inexistente"]); return; }

$args = maybe_unserialize($row['args']);
$cur = $args['cache_query'] ?? null;
echo "query=$QID current_cache_query=".var_export($cur,true)."\n";

if ( $cur === false ) { echo "already disabled\n"; return; }
if ( ! $APPLY ) { echo "DRY-RUN: would set cache_query=false\n"; return; }

$args['cache_query'] = false;
$ok = $wpdb->update("{$wpdb->prefix}jet_post_types", ['args'=>maybe_serialize($args)], ['id'=>$QID]);
echo "wpdb update=".var_export($ok,true)."\n";

// reset do cache da query + object cache
if ( class_exists('\Jet_Engine\Query_Builder\Manager') ) {
    $mgr = \Jet_Engine\Query_Builder\Manager::instance();
    if ( method_exists($mgr,'get_query_by_id') ) {
        $q = $mgr->get_query_by_id($QID);
        if ( $q && method_exists($q,'reset_query_cache') ) { $q->reset_query_cache(); echo "query cache reset\n"; }
    }
}
wp_cache_flush();
echo "object cache flushed\n";
echo "OK — agora limpar _elementor_element_cache das páginas/listings do Atlas e reload php-fpm\n";
