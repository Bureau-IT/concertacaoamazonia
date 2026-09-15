<?php
/**
 * atlas-2026-09-03-glossario-cidades.php — Atlas Cultural (concertacao, blog 2)
 *
 * Acrescenta ao glossário JetEngine "Atlas - Cidades" as cidades que passaram a
 * existir nos artistas e ainda não eram opção de filtro. Sem isto a cidade
 * aparece no card e NÃO aparece no dropdown "Município/Território" — o filtro lê
 * o glossário, não os dados.
 *
 * Só INSERE, nunca remove nem reordena o que já está lá: cada cidade nova entra
 * na posição alfabética (comparação sem acento, caixa-insensível, que é a ordem
 * que a lista já segue). Idempotente — cidade presente é ignorada.
 *
 * Precisa de capacidade `manage_options`: rodar com `--user=<login admin>`.
 * DRY-RUN por padrão; APPLY=1 grava. JSON em DADOS=.
 *
 * Autor: Daniel Cambría
 */

if ( ! defined('ABSPATH') ) { exit; }

$APPLY    = getenv('APPLY') === '1';
$FILE     = getenv('DADOS') ?: '/tmp/atlas-2026-09-dados.json';
$GLOSSARY = (int) ( getenv('GLOSSARIO') ?: 67 );

if ( ! function_exists('jet_engine') || ! jet_engine()->glossaries ) {
    echo json_encode(['error'=>'JetEngine/glossaries indisponível']); return;
}
if ( ! current_user_can('manage_options') ) {
    echo json_encode(['error'=>'sem manage_options — rodar com --user=<admin>']); return;
}
if ( ! file_exists($FILE) ) { echo json_encode(['error'=>"dados não encontrados: $FILE"]); return; }

$dados   = json_decode(file_get_contents($FILE), true);
$cidades = $dados['glossario_cidades'] ?? [];
if ( ! $cidades ) { echo json_encode(['error'=>'JSON sem glossario_cidades']); return; }

// chave de ordenação: sem acento, minúscula — a ordem que a lista já usa
$chave = function( $s ) {
    $s = (string) $s;
    if ( class_exists('Normalizer') ) { $s = Normalizer::normalize($s, Normalizer::FORM_D); }
    $s = preg_replace('/\p{Mn}+/u', '', $s);
    $s = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    return trim( preg_replace('/\s+/u', ' ', $s) );
};

$alvo = null;
foreach ( jet_engine()->glossaries->settings->get() as $gl ) {
    if ( (int) ( $gl['id'] ?? 0 ) === $GLOSSARY ) { $alvo = $gl; break; }
}
if ( ! $alvo ) { echo json_encode(['error'=>"glossário {$GLOSSARY} não encontrado"]); return; }

$campos    = array_values( $alvo['fields'] ?? [] );
$existente = [];
foreach ( $campos as $f ) { $existente[ $chave( $f['value'] ?? '' ) ] = true; }

$out = [
    'apply'      => $APPLY,
    'glossario'  => $GLOSSARY . ' — ' . ( $alvo['name'] ?? '?' ),
    'antes'      => count($campos),
    'ja_tinha'   => 0,
    'inseridas'  => [],
];

foreach ( $cidades as $cidade ) {
    $cidade = trim( (string) $cidade );
    if ( $cidade === '' ) { continue; }
    $k = $chave( $cidade );
    if ( isset($existente[$k]) ) { $out['ja_tinha']++; continue; }

    $pos = count($campos);
    foreach ( $campos as $i => $f ) {
        if ( strcmp( $chave( $f['value'] ?? '' ), $k ) > 0 ) { $pos = $i; break; }
    }
    array_splice( $campos, $pos, 0, [ ['value'=>$cidade, 'label'=>$cidade, 'is_checked'=>false] ] );
    $existente[$k] = true;
    $out['inseridas'][] = sprintf('%s (posição %d)', $cidade, $pos + 1);
}

$out['depois'] = count($campos);

if ( $out['inseridas'] && $APPLY ) {
    $item = [
        'id'          => $GLOSSARY,
        'name'        => $alvo['name'] ?? '',
        'source'      => $alvo['source'] ?? 'manual',
        'source_file' => $alvo['source_file'] ?? '',
        'label_col'   => $alvo['label_col'] ?? '',
        'fields'      => $campos,
    ];
    jet_engine()->glossaries->data->set_request( $item );
    $ok = jet_engine()->glossaries->data->edit_item( false );
    $out['gravado'] = (bool) $ok;
    if ( ! $ok ) { $out['notices'] = jet_engine()->glossaries->get_notices(); }
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
