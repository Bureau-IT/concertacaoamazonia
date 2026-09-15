<?php
/**
 * atlas-2026-09-01-localidades.php — Atlas Cultural (concertacao, blog 2 /cultura/)
 *
 * Aplica cidade/estado/pais (e a coordenada correspondente) nos artistas que a
 * planilha de setembro/2026 alterou. Escreve no post PT e no par EN — as três
 * metas de localidade e a coordenada são idioma-neutras.
 *
 * O casamento é por TÍTULO normalizado, não por ID: os IDs do dev não valem no
 * prod. Título que não casa, ou que casa com mais de um post, é RELATADO e
 * pulado — nunca resolvido no palpite.
 *
 * Só grava o que difere. Idempotente. DRY-RUN por padrão; APPLY=1 grava.
 * O JSON vem de DADOS= (padrão /tmp/atlas-2026-09-dados.json).
 *
 * Autor: Daniel Cambría
 */

if ( ! defined('ABSPATH') ) { exit; }

$APPLY = getenv('APPLY') === '1';
$FILE  = getenv('DADOS') ?: '/tmp/atlas-2026-09-dados.json';

if ( ! file_exists($FILE) ) { echo json_encode(['error'=>"dados não encontrados: $FILE"]); return; }
$dados = json_decode(file_get_contents($FILE), true);
if ( ! is_array($dados) || empty($dados['artistas']) ) { echo json_encode(['error'=>'JSON inválido ou sem artistas']); return; }

function atlas_norm_titulo( $s ) {
    $s = (string) $s;
    if ( class_exists('Normalizer') ) { $s = Normalizer::normalize($s, Normalizer::FORM_C); }
    $s = preg_replace('/\s+/u', ' ', trim($s));
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

global $wpdb;

// índice título normalizado -> IDs dos posts PT
$linhas = $wpdb->get_results(
    "SELECT p.ID, p.post_title
       FROM {$wpdb->posts} p
       JOIN {$wpdb->prefix}icl_translations t
         ON t.element_id = p.ID AND t.element_type = 'post_artistas' AND t.language_code = 'pt-br'
      WHERE p.post_type = 'artistas' AND p.post_status IN ('publish','draft','pending','private')"
);
$indice = [];
foreach ( $linhas as $l ) { $indice[ atlas_norm_titulo($l->post_title) ][] = (int) $l->ID; }

$out = [
    'apply' => $APPLY, 'total' => count($dados['artistas']),
    'atualizados_pt' => 0, 'atualizados_en' => 0, 'ja_corretos' => 0,
    'metas_gravadas' => 0,
    'sem_par_en' => [], 'nao_encontrados' => [], 'ambiguos' => [], 'mudancas' => [],
];

$CAMPOS = ['cidade', 'estado', 'pais', 'coordenada'];

foreach ( $dados['artistas'] as $a ) {
    $chave = atlas_norm_titulo( $a['nome'] );

    if ( empty($indice[$chave]) ) { $out['nao_encontrados'][] = $a['nome']; continue; }
    if ( count($indice[$chave]) > 1 ) { $out['ambiguos'][] = $a['nome'].' => '.implode(',', $indice[$chave]); continue; }

    $pt_id = $indice[$chave][0];
    $en_id = apply_filters('wpml_object_id', $pt_id, 'artistas', false, 'en');
    if ( ! $en_id || $en_id == $pt_id ) { $en_id = 0; $out['sem_par_en'][] = $a['nome']." (pt={$pt_id})"; }

    $alvo = [];
    foreach ( $CAMPOS as $c ) {
        if ( array_key_exists($c, $a) && $a[$c] !== null ) { $alvo[$c] = (string) $a[$c]; }
    }

    $mudou = false;
    foreach ( $alvo as $meta => $valor ) {
        foreach ( [ $pt_id, $en_id ] as $pos => $id ) {
            if ( ! $id ) { continue; }
            $atual = get_post_meta($id, $meta, true);
            if ( (string) $atual === $valor ) { continue; }
            if ( $APPLY ) { update_post_meta($id, $meta, $valor); }
            $out['metas_gravadas']++;
            $mudou = true;
            if ( $pos === 0 && count($out['mudancas']) < 200 ) {
                $out['mudancas'][] = sprintf('%s [%d] %s: %s -> %s',
                    $a['nome'], $pt_id, $meta, ($atual === '' ? '(vazio)' : $atual), ($valor === '' ? '(vazio)' : $valor));
            }
        }
    }

    if ( $mudou ) { $out['atualizados_pt']++; if ( $en_id ) { $out['atualizados_en']++; } }
    else { $out['ja_corretos']++; }
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
