<?php
/**
 * 04-create-en-tax-filters.php — Atlas Cultural (concertacao, blog 2 /cultura/)
 *
 * Cria os filtros EN (tradução WPML) de Tema prioritário (89880) e 4 Amazônias (89881),
 * que usam `_data_source=taxonomies` + `eixos`. Copia a config do PT e converte a lista
 * `_data_exclude_include` (term_ids PT) para os term_ids EN. Vincula via WPML (mesmo trid).
 *
 * O JSF, na página EN, usa o filtro EN traduzido (via wpml_object_id no render do widget),
 * com os term_ids EN na lista include -> dropdown popula e filtra.
 *
 * Idempotente: pula se já existe tradução EN. DRY-RUN por padrão; APPLY=1 grava.
 *
 * Autor: Daniel Cambría
 */

if ( ! defined('ABSPATH') ) { exit; }

$APPLY = getenv('APPLY') === '1';
global $wpdb;
$element_type = apply_filters('wpml_element_type', 'jet-smart-filters'); // post_jet-smart-filters

$FILTERS = [
    89880 => 'Tema prioritário EN',
    89881 => '4 Amazônias EN',
];

$out = ['apply'=>$APPLY, 'created'=>[], 'skipped'=>[], 'errors'=>[]];

foreach ($FILTERS as $src => $en_title) {
    // idempotência
    $existing = apply_filters('wpml_object_id', $src, 'jet-smart-filters', false, 'en');
    if ( $existing && $existing != $src ) {
        $out['skipped'][] = "{$src}: já tem EN {$existing}";
        continue;
    }
    $src_post = get_post($src);
    if ( ! $src_post ) { $out['errors'][] = "src {$src} não encontrado"; continue; }

    if ( ! $APPLY ) { $out['created'][] = "WOULD-CREATE EN of {$src} [{$en_title}]"; continue; }

    $trid = apply_filters('wpml_element_trid', false, $src, $element_type);

    $en_id = wp_insert_post([
        'post_type'   => 'jet-smart-filters',
        'post_status' => $src_post->post_status,
        'post_title'  => $en_title,
        'post_author' => $src_post->post_author,
    ], true);
    if ( is_wp_error($en_id) ) { $out['errors'][] = "insert {$src}: ".$en_id->get_error_message(); continue; }

    // copiar TODA a meta do PT
    $meta = get_post_meta($src);
    $skip = ['_edit_lock','_edit_last'];
    foreach ($meta as $k => $vals) {
        if ( in_array($k, $skip, true) ) continue;
        update_post_meta($en_id, $k, maybe_unserialize($vals[0]));
    }

    // converter _data_exclude_include PT -> EN
    $inc = maybe_unserialize($meta['_data_exclude_include'][0] ?? 'a:0:{}');
    if ( is_array($inc) ) {
        $inc_en = [];
        foreach ($inc as $tid) {
            $en_tid = apply_filters('wpml_object_id', (int)$tid, 'eixos', false, 'en');
            $inc_en[] = $en_tid ? (string)$en_tid : (string)$tid;
        }
        update_post_meta($en_id, '_data_exclude_include', $inc_en);
    }

    // vincular WPML
    do_action('wpml_set_element_language_details', [
        'element_id'=>$en_id, 'element_type'=>$element_type,
        'trid'=>$trid, 'language_code'=>'en', 'source_language_code'=>'pt-br',
    ]);

    $out['created'][] = "EN {$en_id} <- PT {$src} [{$en_title}], include EN=".json_encode($inc_en ?? []);
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
