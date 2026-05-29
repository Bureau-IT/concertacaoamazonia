<?php
/**
 * deploy-09-area-en-filter.php — cria a tradução EN do filtro Área de atuação (89879)
 * em PROD, vinculada WPML ao trid do PT, apontando para o glossário 73 (Área EN).
 *
 * Gap descoberto na validação pós-deploy 2026-05-29: o filtro Área de atuação aparecia
 * em PT na página EN do Atlas porque NÃO existia tradução EN do filtro 89879 em prod
 * (no DEV existe — id 92327). O widget Área da página aponta para 89879 nos dois idiomas;
 * a JSF traduz 89879→EN em runtime via wpml_object_id SOMENTE se a tradução EN existir.
 *
 * Lê /tmp/area_en_export.json (export da meta do 92327 do dev). Idempotente: pula se o
 * trid do 89879 já tem tradução EN. DRY-RUN por padrão; APPLY=1 grava.
 * Escreve o id criado em /tmp/area_en_created.json.
 */
if ( ! defined('ABSPATH') ) { exit; }
$APPLY = getenv('APPLY') === '1';
$SRC_PT = 89879; // filtro Área PT em prod (mesmo id do dev)
$GLOSSARY_EN = '73';

$data = json_decode(file_get_contents('/tmp/area_en_export.json'), true);
if ( ! is_array($data) || empty($data['meta']) ) { echo json_encode(['error'=>'json inválido']); return; }

$element_type = apply_filters('wpml_element_type', 'jet-smart-filters');

// validar que o PT existe e pegar o trid
if ( ! get_post($SRC_PT) ) { echo json_encode(['error'=>"filtro PT $SRC_PT inexistente"]); return; }
$trid = apply_filters('wpml_element_trid', false, $SRC_PT, $element_type);
if ( ! $trid ) { echo json_encode(['error'=>"89879 sem trid WPML"]); return; }

// idempotência: já tem tradução EN no trid?
$translations = apply_filters('wpml_get_element_translations', [], $trid, $element_type);
foreach ($translations as $lc => $t) {
    if ($lc === 'en') {
        echo json_encode(['skip'=>'tradução EN já existe', 'en_id'=>$t->element_id, 'trid'=>$trid]);
        return;
    }
}

$out = ['apply'=>$APPLY, 'trid'=>$trid, 'src_pt'=>$SRC_PT];

if ( ! $APPLY ) {
    $out['would_create'] = "EN de $SRC_PT no trid $trid, gid=$GLOSSARY_EN, ".count($data['meta'])." metas";
    echo json_encode($out, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
    return;
}

// criar o post EN
$en_id = wp_insert_post([
    'post_type'   => 'jet-smart-filters',
    'post_status' => $data['status'],
    'post_title'  => $data['title'],
], true);
if ( is_wp_error($en_id) || ! $en_id ) { echo json_encode(['error'=>'wp_insert_post falhou: '.($en_id instanceof WP_Error ? $en_id->get_error_message() : '?')]); return; }

// copiar meta as-is, garantindo glossary_id = 73
foreach ($data['meta'] as $k => $v) {
    if ($k === '_glossary_id') $v = $GLOSSARY_EN;
    update_post_meta($en_id, $k, $v);
}
update_post_meta($en_id, '_glossary_id', $GLOSSARY_EN);

// vincular WPML ao mesmo trid (en <- pt-br)
do_action('wpml_set_element_language_details', [
    'element_id'           => $en_id,
    'element_type'         => $element_type,
    'trid'                 => $trid,
    'language_code'        => 'en',
    'source_language_code' => 'pt-br',
]);

$out['en_id'] = $en_id;
file_put_contents('/tmp/area_en_created.json', json_encode(['en_id'=>$en_id,'trid'=>$trid]));
echo json_encode($out, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
