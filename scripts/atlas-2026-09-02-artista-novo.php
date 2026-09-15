<?php
/**
 * atlas-2026-09-02-artista-novo.php — Atlas Cultural (concertacao, blog 2 /cultura/)
 *
 * Cria o artista novo da planilha de setembro/2026 (PT + tradução EN, vinculadas
 * por trid do WPML), com metas, termos de eixos nos dois idiomas e coordenada.
 *
 * Idempotente pelo SLUG: se o post PT já existe, ele é reaproveitado e só o que
 * difere é gravado; se o par EN já existe, idem. Rodar duas vezes não duplica.
 *
 * Os termos EN são resolvidos pelo `wpml_object_id` a partir dos IDs PT — e as
 * relações PT que o WPML copia para o post EN são LIMPAS depois, senão a contagem
 * dos termos PT conta o post EN duas vezes (armadilha registrada no
 * 03-link-en-terms.php).
 *
 * DRY-RUN por padrão; APPLY=1 grava. JSON em DADOS= (padrão /tmp/atlas-2026-09-dados.json).
 *
 * Autor: Daniel Cambría
 */

if ( ! defined('ABSPATH') ) { exit; }

$APPLY = getenv('APPLY') === '1';
$FILE  = getenv('DADOS') ?: '/tmp/atlas-2026-09-dados.json';

if ( ! file_exists($FILE) ) { echo json_encode(['error'=>"dados não encontrados: $FILE"]); return; }
$dados = json_decode(file_get_contents($FILE), true);
$a = $dados['novo_artista'] ?? null;
if ( ! is_array($a) ) { echo json_encode(['error'=>'JSON sem novo_artista']); return; }

$out = ['apply'=>$APPLY, 'nome'=>$a['nome'], 'pt_id'=>0, 'en_id'=>0, 'acoes'=>[], 'avisos'=>[]];

$bloco = function( $texto ) {
    $texto = trim( (string) $texto );
    if ( $texto === '' ) { return ''; }
    return "<!-- wp:paragraph -->\n<p>" . esc_html($texto) . "</p>\n<!-- /wp:paragraph -->";
};

// autor: o mesmo dos artistas cadastrados mais recentemente, não um ID fixo
global $wpdb;
$autor = (int) $wpdb->get_var(
    "SELECT post_author FROM {$wpdb->posts}
      WHERE post_type='artistas' AND post_status='publish' AND post_author <> 0
      ORDER BY post_date DESC LIMIT 1"
);
if ( ! $autor ) { $autor = 1; }

$element_type = apply_filters('wpml_element_type', 'artistas');

// ---------- PT ----------
$pt = get_page_by_path( $a['slug'], OBJECT, 'artistas' );
if ( $pt ) {
    $pt_id = (int) $pt->ID;
    $out['acoes'][] = "PT já existe (id={$pt_id}) — reaproveitado";
} else {
    if ( ! $APPLY ) {
        $out['acoes'][] = "CRIARIA PT '{$a['nome']}' (slug={$a['slug']}, autor={$autor})";
        $pt_id = 0;
    } else {
        $pt_id = wp_insert_post([
            'post_type'    => 'artistas',
            'post_status'  => 'publish',
            'post_title'   => $a['nome'],
            'post_name'    => $a['slug'],
            'post_author'  => $autor,
            'post_content' => $bloco( $a['desc_pt'] ),
        ], true);
        if ( is_wp_error($pt_id) ) { echo json_encode(['error'=>$pt_id->get_error_message()]); return; }
        $out['acoes'][] = "PT criado id={$pt_id}";
        do_action('wpml_set_element_language_details', [
            'element_id' => $pt_id, 'element_type' => $element_type,
            'trid' => false, 'language_code' => 'pt-br', 'source_language_code' => null,
        ]);
    }
}
$out['pt_id'] = $pt_id;

$metas = [
    '_tipo'                    => $a['tipo'],
    'tema'                     => $a['tema'],
    'site-do-artista'          => $a['site'],
    'outros-sites-do-artista'  => $a['outros_sites'],
    'busca-rapida'             => $a['nome'],
    'cidade'                   => $a['cidade'],
    'estado'                   => $a['estado'],
    'pais'                     => $a['pais'],
    'coordenada'               => $a['coordenada'],
    'copied_media_ids'         => 'a:0:{}',
    'referenced_media_ids'     => 'a:0:{}',
];

$grava_metas = function( $id ) use ( $metas, $APPLY, &$out ) {
    if ( ! $id ) { return; }
    foreach ( $metas as $k => $v ) {
        // comparar SERIALIZADO: as duas metas de mídia voltam como array de
        // get_post_meta, e comparar array com string dá sempre "diferente"
        $atual = maybe_serialize( get_post_meta($id, $k, true) );
        if ( (string) $atual === (string) $v ) { continue; }
        if ( $APPLY ) {
            // as duas serializadas entram cruas: update_post_meta serializaria de novo
            if ( in_array($k, ['copied_media_ids','referenced_media_ids'], true) ) {
                update_post_meta($id, $k, maybe_unserialize($v));
            } else {
                update_post_meta($id, $k, $v);
            }
        }
        $out['acoes'][] = "meta[$id] $k = " . ($v === '' ? '(vazio)' : $v);
    }
};
$grava_metas( $pt_id );

// ---------- EN ----------
$en_id = 0;
if ( $pt_id ) {
    $ja = apply_filters('wpml_object_id', $pt_id, 'artistas', false, 'en');
    if ( $ja && $ja != $pt_id ) {
        $en_id = (int) $ja;
        $out['acoes'][] = "EN já existe (id={$en_id}) — reaproveitado";
    } elseif ( $APPLY ) {
        $trid = apply_filters('wpml_element_trid', false, $pt_id, $element_type);
        if ( ! $trid ) {
            do_action('wpml_set_element_language_details', [
                'element_id' => $pt_id, 'element_type' => $element_type,
                'trid' => false, 'language_code' => 'pt-br', 'source_language_code' => null,
            ]);
            $trid = apply_filters('wpml_element_trid', false, $pt_id, $element_type);
        }
        $en_id = wp_insert_post([
            'post_type'    => 'artistas',
            'post_status'  => 'publish',
            'post_title'   => $a['nome'],
            'post_name'    => $a['slug'] . '-en',
            'post_author'  => $autor,
            'post_content' => $bloco( $a['desc_en'] ),
        ], true);
        if ( is_wp_error($en_id) ) { echo json_encode(['error'=>$en_id->get_error_message()]); return; }
        do_action('wpml_set_element_language_details', [
            'element_id' => $en_id, 'element_type' => $element_type,
            'trid' => $trid, 'language_code' => 'en', 'source_language_code' => 'pt-br',
        ]);
        $out['acoes'][] = "EN criado id={$en_id} (trid={$trid})";
    } else {
        $out['acoes'][] = "CRIARIA EN (slug={$a['slug']}-en)";
    }
}
$out['en_id'] = $en_id;
$grava_metas( $en_id );

// ---------- eixos ----------
$pt_terms = array_map('intval', $a['eixos'] ?? []);
$en_terms = [];
foreach ( $pt_terms as $t ) {
    $e = apply_filters('wpml_object_id', $t, 'eixos', false, 'en');
    if ( $e ) { $en_terms[] = (int) $e; }
    else { $out['avisos'][] = "termo eixos {$t} sem tradução EN"; }
}

if ( $pt_id ) {
    $atuais = array_map('intval', $wpdb->get_col( $wpdb->prepare(
        "SELECT tt.term_id FROM {$wpdb->term_relationships} tr
           JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
          WHERE tr.object_id = %d AND tt.taxonomy = 'eixos'", $pt_id ) ) );
    sort($atuais); $alvo = $pt_terms; sort($alvo);
    if ( $atuais !== $alvo ) {
        if ( $APPLY ) { wp_set_object_terms($pt_id, $pt_terms, 'eixos', false); }
        $out['acoes'][] = 'eixos PT ['.implode(',', $pt_terms).'] em '.$pt_id;
    }
}

if ( $en_id && $en_terms ) {
    // ler as relações CRUAS: wp_get_object_terms passa pelo filtro de idioma do
    // WPML e, no contexto PT, não devolve os termos EN — a comparação nunca fecharia
    $atuais = array_map('intval', $wpdb->get_col( $wpdb->prepare(
        "SELECT tt.term_id FROM {$wpdb->term_relationships} tr
           JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
          WHERE tr.object_id = %d AND tt.taxonomy = 'eixos'", $en_id ) ) );
    sort($atuais); $alvo = $en_terms; sort($alvo);
    if ( $atuais !== $alvo ) {
        if ( $APPLY ) {
            do_action('wpml_switch_language', 'en');
            wp_set_object_terms($en_id, $en_terms, 'eixos', false);
            do_action('wpml_switch_language', null);
            // o WPML copia as relações PT para o post EN — limpar, senão a contagem PT infla
            $tt_pt = [];
            foreach ( $pt_terms as $t ) {
                $tt = $wpdb->get_var($wpdb->prepare(
                    "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id=%d AND taxonomy='eixos'", $t));
                if ( $tt ) { $tt_pt[] = (int) $tt; }
            }
            if ( $tt_pt ) {
                $wpdb->query( $wpdb->prepare(
                    "DELETE FROM {$wpdb->term_relationships} WHERE object_id=%d AND term_taxonomy_id IN ("
                    . implode(',', array_fill(0, count($tt_pt), '%d')) . ")",
                    array_merge([$en_id], $tt_pt) ) );
                wp_update_term_count_now($tt_pt, 'eixos');
            }
            clean_object_term_cache($en_id, 'eixos');
        }
        $out['acoes'][] = 'eixos EN ['.implode(',', $en_terms).'] em '.$en_id;
    }
}

if ( $APPLY && $pt_id ) { clean_post_cache($pt_id); if ($en_id) { clean_post_cache($en_id); } }

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
