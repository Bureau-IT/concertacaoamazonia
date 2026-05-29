<?php
/**
 * 01-eixos-translate.php — Atlas Cultural (concertacao, blog 2 /cultura/)
 *
 * Torna a taxonomia `eixos` translatable no WPML e cria a tradução EN de TODOS os
 * termos com count>0 (conjunto plano 40-56 + hierárquico filhos de 2475/2478).
 * O label do termo EN vem do dicionário PT->EN. value/query continua por term_id PT
 * (a associação artista->termo EN é feita no 03-link-en-terms.php).
 *
 * Idempotente: pula termo que já tem par EN vinculado no trid.
 * DRY-RUN por padrão; defina APPLY=1 para gravar.
 *
 * Uso:
 *   docker exec -u www-data concertacao-dev-wordpress \
 *     wp --url="https://cambrasmax.local:8484/cultura/" eval-file /tmp/01-eixos-translate.php
 *   (com APPLY=1 prefixado para aplicar)
 *
 * Autor: Daniel Cambría
 */

if ( ! defined('ABSPATH') ) { exit; }

$APPLY = getenv('APPLY') === '1';
$TAX   = 'eixos';

// Dicionário PT (nome decodificado) -> EN
$DICT = [
    'Biodiversidade'           => 'Biodiversity',
    'Bioeconomia'              => 'Bioeconomy',
    'CT&I'                     => 'ST&I',
    'Cidades'                  => 'Cities',
    'Cultura'                  => 'Culture',
    'Educação'                 => 'Education',
    'Energia'                  => 'Energy',
    'OTRF'                     => 'TPLR',
    'PIQCTS'                   => 'IPLCs',
    'Saúde'                    => 'Health',
    'Segurança'                => 'Security',
    'Sistemas agroalimentares' => 'Agri-food systems',
    'Transição'                => 'Transition',
    'Áreas conservadas'        => 'Conserved areas',
    'Áreas convertidas'        => 'Converted areas',
    'Áreas de transição'       => 'Transition Areas',
];

global $wpdb;
$out = ['apply'=>$APPLY, 'made_translatable'=>false, 'translated'=>[], 'skipped'=>[], 'errors'=>[]];

// --- 1) tornar eixos translatable (sync_option=1) ---
$settings = get_option('icl_sitepress_settings');
$current  = $settings['taxonomies_sync_option'][$TAX] ?? 0;
if ( (int)$current !== 1 ) {
    if ( $APPLY ) {
        $settings['taxonomies_sync_option'][$TAX] = 1;
        update_option('icl_sitepress_settings', $settings);
        $out['made_translatable'] = true;
    } else {
        $out['made_translatable'] = 'WOULD-SET (dry-run)';
    }
} else {
    $out['made_translatable'] = 'already=1';
}

$element_type = apply_filters('wpml_element_type', $TAX); // tax_eixos

// --- 2) traduzir cada termo count>0 ---
$terms = $wpdb->get_results(
    "SELECT t.term_id, t.name, tt.term_taxonomy_id, tt.parent, tt.count
     FROM {$wpdb->terms} t
     JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=t.term_id
     WHERE tt.taxonomy='{$TAX}' AND tt.count>0
     ORDER BY tt.parent, t.name", ARRAY_A
);

foreach ($terms as $t) {
    $pt_term_id = (int)$t['term_id'];
    $pt_ttid    = (int)$t['term_taxonomy_id'];
    $name_clean = html_entity_decode($t['name'], ENT_QUOTES);

    if ( ! isset($DICT[$name_clean]) ) {
        $out['skipped'][] = "no-dict: {$pt_term_id} [{$name_clean}]";
        continue;
    }
    $en_name = $DICT[$name_clean];

    // registrar original PT (trid) se ainda não houver
    $trid = apply_filters('wpml_element_trid', false, $pt_ttid, $element_type);
    if ( ! $trid ) {
        if ( $APPLY ) {
            do_action('wpml_set_element_language_details', [
                'element_id'           => $pt_ttid,
                'element_type'         => $element_type,
                'trid'                 => false,
                'language_code'        => 'pt-br',
                'source_language_code' => null,
            ]);
            $trid = apply_filters('wpml_element_trid', false, $pt_ttid, $element_type);
        } else {
            $out['translated'][] = "WOULD-CREATE: {$name_clean} -> {$en_name} (pt_ttid={$pt_ttid}, new trid)";
            continue;
        }
    }

    // já existe EN nesse trid?
    $existing_en = $wpdb->get_var($wpdb->prepare(
        "SELECT element_id FROM {$wpdb->prefix}icl_translations
         WHERE trid=%d AND language_code='en' AND element_type=%s",
        $trid, $element_type
    ));
    if ( $existing_en ) {
        $out['skipped'][] = "already-en: pt_ttid={$pt_ttid} trid={$trid} en_ttid={$existing_en}";
        continue;
    }

    if ( ! $APPLY ) {
        $out['translated'][] = "WOULD-CREATE: {$name_clean} -> {$en_name} (pt_ttid={$pt_ttid} trid={$trid})";
        continue;
    }

    // criar termo EN (slug único por term_id PT para evitar colisão entre "Cities" 52/54)
    $en_slug = sanitize_title($en_name) . '-en-' . $pt_term_id;
    $new = wp_insert_term($en_name, $TAX, ['slug'=>$en_slug, 'parent'=>0]);
    if ( is_wp_error($new) ) {
        $out['errors'][] = "wp_insert_term [{$en_name}/{$pt_term_id}]: " . $new->get_error_message();
        continue;
    }
    $new_ttid = (int)$new['term_taxonomy_id'];

    do_action('wpml_set_element_language_details', [
        'element_id'           => $new_ttid,
        'element_type'         => $element_type,
        'trid'                 => $trid,
        'language_code'        => 'en',
        'source_language_code' => 'pt-br',
    ]);

    $out['translated'][] = "{$name_clean} -> {$en_name} (pt_term={$pt_term_id} pt_ttid={$pt_ttid} trid={$trid} en_term={$new['term_id']} en_ttid={$new_ttid})";
}

$out['summary'] = [
    'terms_scanned' => count($terms),
    'translated'    => count($out['translated']),
    'skipped'       => count($out['skipped']),
    'errors'        => count($out['errors']),
];

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
