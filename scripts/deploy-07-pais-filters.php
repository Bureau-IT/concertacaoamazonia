<?php
/**
 * deploy-07-pais-filters.php — cria os 2 filtros país (PT/EN) em prod, vinculados WPML.
 * Lê /tmp/pais_filters_export.json (export do dev). Cria post jet-smart-filters,
 * copia toda a meta, vincula PT<-EN no mesmo trid. Idempotente (pula se já existe
 * filtro _query_var=pais no idioma). DRY-RUN por padrão; APPLY=1 grava.
 * Escreve os IDs criados em /tmp/pais_filters_created.json.
 */
if ( ! defined('ABSPATH') ) { exit; }
$APPLY = getenv('APPLY') === '1';
$data = json_decode(file_get_contents('/tmp/pais_filters_export.json'), true);
if ( ! is_array($data) ) { echo json_encode(['error'=>'json inválido']); return; }

$element_type = apply_filters('wpml_element_type', 'jet-smart-filters');
$out = ['apply'=>$APPLY, 'created'=>[], 'ids'=>[]];

// idempotência: já existe filtro pais?
global $wpdb;
$existing = $wpdb->get_col("SELECT post_id FROM {$wpdb->prefix}postmeta WHERE meta_key='_query_var' AND meta_value='pais'");
if ( count($existing) >= 2 ) {
    echo json_encode(['skip'=>'filtros país já existem','ids'=>$existing]); return;
}

if ( ! $APPLY ) {
    foreach ($data as $f) $out['created'][] = "WOULD-CREATE {$f['lang']}: {$f['title']} (".count($f['meta'])." metas)";
    echo json_encode($out, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE); return;
}

// criar PT primeiro (para ter trid), depois EN
$pt = null; $en = null;
foreach ($data as $f) if ($f['lang']==='pt-br') $pt = $f;
foreach ($data as $f) if ($f['lang']==='en') $en = $f;

// PT
$pt_id = wp_insert_post(['post_type'=>'jet-smart-filters','post_status'=>$pt['status'],'post_title'=>$pt['title']], true);
foreach ($pt['meta'] as $k=>$v) update_post_meta($pt_id, $k, $v);
do_action('wpml_set_element_language_details', ['element_id'=>$pt_id,'element_type'=>$element_type,'trid'=>false,'language_code'=>'pt-br','source_language_code'=>null]);
$trid = apply_filters('wpml_element_trid', false, $pt_id, $element_type);
$out['ids']['pt'] = $pt_id;

// EN
$en_id = wp_insert_post(['post_type'=>'jet-smart-filters','post_status'=>$en['status'],'post_title'=>$en['title']], true);
foreach ($en['meta'] as $k=>$v) update_post_meta($en_id, $k, $v);
do_action('wpml_set_element_language_details', ['element_id'=>$en_id,'element_type'=>$element_type,'trid'=>$trid,'language_code'=>'en','source_language_code'=>'pt-br']);
$out['ids']['en'] = $en_id;

$out['created'][] = "PT={$pt_id} EN={$en_id} trid={$trid}";
file_put_contents('/tmp/pais_filters_created.json', json_encode($out['ids']));

echo json_encode($out, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
