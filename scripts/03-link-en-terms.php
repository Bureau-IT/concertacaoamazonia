<?php
/**
 * 03-link-en-terms.php — Atlas Cultural (concertacao, blog 2 /cultura/)
 *
 * Associa cada artista EN aos termos `eixos` EN correspondentes aos termos PT do
 * seu par. Mapeia PT->EN via trid WPML. Isto popula o count dos termos EN (os
 * filtros Tema prioritário / 4 Amazônias passam a listar e filtrar em EN).
 *
 * Idempotente: wp_set_object_terms com $append=false (reescreve o conjunto EN).
 * DRY-RUN por padrão; APPLY=1 grava. BATCH/OFFSET opcionais.
 *
 * Autor: Daniel Cambría
 */

if ( ! defined('ABSPATH') ) { exit; }

$APPLY  = getenv('APPLY') === '1';
$OFFSET = (int) (getenv('OFFSET') ?: 0);
$BATCH  = (int) (getenv('BATCH')  ?: 0);
$TAX    = 'eixos';

global $wpdb;
$element_type     = apply_filters('wpml_element_type', 'artistas'); // post_artistas
$tax_element_type = apply_filters('wpml_element_type', $TAX);        // tax_eixos

// Pré-construir mapa term_taxonomy_id PT -> term_id EN (via trid)
$rows = $wpdb->get_results(
    "SELECT pt.element_id AS pt_ttid, en.element_id AS en_ttid
     FROM {$wpdb->prefix}icl_translations pt
     JOIN {$wpdb->prefix}icl_translations en
       ON en.trid = pt.trid AND en.element_type = pt.element_type AND en.language_code='en'
     WHERE pt.element_type = '{$tax_element_type}' AND pt.language_code='pt-br'", ARRAY_A
);
$ttid_pt_to_en_termid = [];
foreach ($rows as $r) {
    $en_term_id = $wpdb->get_var($wpdb->prepare(
        "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id=%d", (int)$r['en_ttid']
    ));
    if ($en_term_id) $ttid_pt_to_en_termid[(int)$r['pt_ttid']] = (int)$en_term_id;
}

// Lista de pares PT->EN artistas
$pairs = $wpdb->get_results(
    "SELECT pt.element_id AS pt_id, en.element_id AS en_id
     FROM {$wpdb->prefix}icl_translations pt
     JOIN {$wpdb->prefix}icl_translations en
       ON en.trid=pt.trid AND en.element_type=pt.element_type AND en.language_code='en'
     JOIN {$wpdb->posts} pp ON pp.ID=pt.element_id AND pp.post_status='publish'
     WHERE pt.element_type='{$element_type}' AND pt.language_code='pt-br'
     ORDER BY pt.element_id", ARRAY_A
);
if ($BATCH > 0) $pairs = array_slice($pairs, $OFFSET, $BATCH);

$out = ['apply'=>$APPLY,'offset'=>$OFFSET,'batch'=>$BATCH,'map_size'=>count($ttid_pt_to_en_termid),'linked'=>0,'no_terms'=>0,'errors'=>[],'samples'=>[]];

if ($APPLY) { wp_defer_term_counting(true); }

foreach ($pairs as $p) {
    $pt_id = (int)$p['pt_id'];
    $en_id = (int)$p['en_id'];

    // term_taxonomy_ids de eixos do PT
    $pt_ttids = $wpdb->get_col($wpdb->prepare(
        "SELECT tr.term_taxonomy_id FROM {$wpdb->term_relationships} tr
         JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id
         WHERE tr.object_id=%d AND tt.taxonomy=%s", $pt_id, $TAX
    ));
    if ( ! $pt_ttids ) { $out['no_terms']++; continue; }

    $en_term_ids = [];
    foreach ($pt_ttids as $ttid) {
        if ( isset($ttid_pt_to_en_termid[(int)$ttid]) ) $en_term_ids[] = $ttid_pt_to_en_termid[(int)$ttid];
    }
    $en_term_ids = array_values(array_unique($en_term_ids));
    if ( ! $en_term_ids ) { $out['no_terms']++; continue; }

    if ( ! $APPLY ) {
        $out['linked']++;
        if (count($out['samples'])<5) $out['samples'][]="WOULD-LINK EN {$en_id} <- PT {$pt_id}: ".count($en_term_ids)." termos eixos EN";
        continue;
    }

    // WPML: atribuir termos no contexto de idioma EN (sem isto, wp_set_object_terms não grava os termos EN)
    do_action('wpml_switch_language', 'en');
    $res = wp_set_object_terms($en_id, $en_term_ids, $TAX, false);
    do_action('wpml_switch_language', 'pt-br');
    if ( is_wp_error($res) ) { $out['errors'][]="set_terms {$en_id}: ".$res->get_error_message(); continue; }
    $out['linked']++;
    if (count($out['samples'])<5) $out['samples'][]="linked EN {$en_id} <- PT {$pt_id}: ".count($en_term_ids)." termos";
}

if ($APPLY) {
    wp_defer_term_counting(false);
    // recontagem explícita dos termos eixos EN
    $en_termids = array_values($ttid_pt_to_en_termid);
    wp_update_term_count_now($wpdb->get_col(
        "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy='{$TAX}'"
    ), $TAX);
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
