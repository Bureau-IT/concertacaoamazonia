<?php
/**
 * 02-create-en-artistas.php — Atlas Cultural (concertacao, blog 2 /cultura/)
 *
 * Cria a tradução EN de cada artista PT (fluxo canônico WPML), lendo o mapa
 * /tmp/artista_en_map.json (gerado pelo pré-processo Python: cruzamento CSV x site).
 *
 * - post_content EN = Description EN do CSV (ou tradução fallback; vazio se sem fonte).
 * - Copia metas idioma-neutras as-is (NÃO traduz): coordenada, tema (PT, p/ ícone),
 *   cidade, estado, pais, site-do-artista, outros-sites-do-artista, busca-rapida, _tipo,
 *   copied_media_ids, referenced_media_ids, e quaisquer metas hash de coordenada (_lat/_lng).
 * - Vincula via WPML ao trid do PT (en <- pt-br).
 *
 * Idempotente: pula artista que já tem tradução EN (via wpml_object_id).
 * DRY-RUN por padrão; APPLY=1 grava. BATCH/OFFSET opcionais p/ lotes.
 *
 * Autor: Daniel Cambría
 */

if ( ! defined('ABSPATH') ) { exit; }

$APPLY  = getenv('APPLY') === '1';
$OFFSET = (int) (getenv('OFFSET') ?: 0);
$BATCH  = (int) (getenv('BATCH')  ?: 0); // 0 = todos

$map_file = '/tmp/artista_en_map.json';
if ( ! file_exists($map_file) ) { echo json_encode(['error'=>"map not found: $map_file"]); return; }
$plan = json_decode(file_get_contents($map_file), true);
if ( ! is_array($plan) ) { echo json_encode(['error'=>'invalid map json']); return; }

if ( $BATCH > 0 ) { $plan = array_slice($plan, $OFFSET, $BATCH); }

$NEUTRAL_META = [
    'coordenada','tema','cidade','estado','pais',
    'site-do-artista','outros-sites-do-artista','busca-rapida','_tipo',
    'copied_media_ids','referenced_media_ids',
];

$element_type = apply_filters('wpml_element_type', 'artistas'); // post_artistas
$out = ['apply'=>$APPLY, 'offset'=>$OFFSET, 'batch'=>$BATCH, 'created'=>0, 'skipped'=>0, 'errors'=>[], 'samples'=>[]];

if ( $APPLY ) { wp_defer_term_counting(true); wp_suspend_cache_invalidation(true); }

foreach ($plan as $row) {
    $pt_id = (int)$row['id'];
    $pt    = get_post($pt_id);
    if ( ! $pt ) { $out['errors'][] = "pt post not found: {$pt_id}"; continue; }

    // idempotência: já existe EN?
    $existing = apply_filters('wpml_object_id', $pt_id, 'artistas', false, 'en');
    if ( $existing && $existing != $pt_id ) {
        $out['skipped']++;
        continue;
    }

    if ( ! $APPLY ) {
        $out['created']++;
        if ( count($out['samples']) < 5 ) $out['samples'][] = "WOULD-CREATE EN of {$pt_id} [{$row['title']}] source={$row['source']}";
        continue;
    }

    // trid do PT
    $trid = apply_filters('wpml_element_trid', false, $pt_id, $element_type);
    if ( ! $trid ) {
        do_action('wpml_set_element_language_details', [
            'element_id'=>$pt_id, 'element_type'=>$element_type,
            'trid'=>false, 'language_code'=>'pt-br', 'source_language_code'=>null,
        ]);
        $trid = apply_filters('wpml_element_trid', false, $pt_id, $element_type);
    }

    // criar post EN
    $en_content = $row['desc_en'] ?? '';
    $en_id = wp_insert_post([
        'post_type'   => 'artistas',
        'post_status' => $pt->post_status,
        'post_title'  => $pt->post_title,
        'post_name'   => $pt->post_name . '-en',
        'post_author' => $pt->post_author,
        'post_content'=> $en_content,
    ], true);
    if ( is_wp_error($en_id) ) { $out['errors'][] = "wp_insert_post {$pt_id}: ".$en_id->get_error_message(); continue; }

    // copiar metas neutras (as-is) — inclui metas hash de coordenada
    $all_meta = get_post_meta($pt_id);
    foreach ($all_meta as $k => $vals) {
        $copy = in_array($k, $NEUTRAL_META, true)
             || preg_match('/_(lat|lng)$/', $k)   // metas hash de coordenada do JetEngine
             || preg_match('/_hash$/', $k);
        if ( ! $copy ) continue;
        update_post_meta($en_id, $k, maybe_unserialize($vals[0]));
    }
    // featured image (se existir — improvável)
    $thumb = get_post_meta($pt_id, '_thumbnail_id', true);
    if ( $thumb ) update_post_meta($en_id, '_thumbnail_id', $thumb);

    // vincular WPML
    do_action('wpml_set_element_language_details', [
        'element_id'=>$en_id, 'element_type'=>$element_type,
        'trid'=>$trid, 'language_code'=>'en', 'source_language_code'=>'pt-br',
    ]);

    $out['created']++;
    if ( count($out['samples']) < 5 ) $out['samples'][] = "created EN {$en_id} <- PT {$pt_id} [{$pt->post_title}] source={$row['source']}";
}

if ( $APPLY ) { wp_suspend_cache_invalidation(false); wp_defer_term_counting(false); }

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
