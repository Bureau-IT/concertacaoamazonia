<?php
/**
 * atlas-2026-09-04-listing-cidade-formato.php — Atlas Cultural (concertacao, blog 2)
 *
 * Tira a vírgula órfã do campo Cidade nas listagens VERTICAIS do Atlas (18139 PT,
 * 92987 EN), sem estragar o caso comum.
 *
 * O problema: o widget de cidade formata `"%s, "`, com a vírgula presa ao campo.
 * Artista estrangeiro não tem estado, e o `hide_if_empty` tira o widget de estado
 * do DOM — sobra **"Los Angeles, "**.
 *
 * Por que a correção NÃO é passar a vírgula para o estado (`", %s"`): o container
 * `c8f5aad` é **flex-direction: column** no desktop (só o mobile é `row`), então
 * cidade e estado SEMPRE caem em linhas separadas. Medido no dev em 15/09/2026:
 * as duas caixas com x=866 e y diferentes, 39px cada. Com a vírgula no estado, o
 * card brasileiro passa a quebrar como "Belém" / ", Pará" — linha começando por
 * vírgula, em 96% dos artistas, para consertar 4%. Foi tentado e revertido.
 *
 * A correção que vale nos dois layouts: os dois widgets sem vírgula nenhuma, e a
 * vírgula entra por CSS no FIM da cidade apenas quando existe um irmão depois
 * dela (`:not(:last-child)`) — que é exatamente a condição "tem estado", já que o
 * `hide_if_empty` remove o widget vazio do DOM em vez de escondê-lo.
 *
 * Edição por SUBSTITUIÇÃO DE TOKEN no `_elementor_data` cru — nunca decodificar e
 * recodificar o JSON. Gravação com `wp_slash()`, senão `update_post_meta` come as
 * barras. Backup em `_elementor_data_bkp_atlas_202609` antes de gravar.
 *
 * Idempotente. DRY-RUN por padrão; APPLY=1 grava. LISTAGENS=18139,92987 sobrescreve.
 *
 * Autor: Daniel Cambría
 */

if ( ! defined('ABSPATH') ) { exit; }

$APPLY = getenv('APPLY') === '1';
$IDS   = array_filter( array_map( 'intval', explode( ',', getenv('LISTAGENS') ?: '18139,92987' ) ) );

$WIDGET_CIDADE = getenv('WIDGET_CIDADE') ?: 'f079808';

$TOKEN_CIDADE_DE   = '"dynamic_field_format":"%s, "';
$TOKEN_CIDADE_PARA = '"dynamic_field_format":"%s"';
$ABRE_CIDADE       = '{"id":"' . $WIDGET_CIDADE . '","elType":"widget","settings":{';

// aspas simples no CSS de propósito: assim nada precisa ser escapado no JSON
$CSS = 'selector:not(:last-child) .jet-listing-dynamic-field__content:after{content:\', \';}';
$CUSTOM_CSS = '"custom_css":"' . $CSS . '",';

$out = ['apply'=>$APPLY, 'listagens'=>[], 'erros'=>[]];

global $wpdb;

foreach ( $IDS as $id ) {
    $r = ['id'=>$id, 'titulo'=>get_the_title($id), 'passos'=>[]];

    $raw = $wpdb->get_var( $wpdb->prepare(
        "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key='_elementor_data'", $id ) );
    if ( ! $raw ) { $out['erros'][] = "{$id}: sem _elementor_data"; continue; }

    $novo = $raw;

    // 1) formato da cidade: "%s, " -> "%s"
    $n = substr_count($novo, $TOKEN_CIDADE_DE);
    if ( $n === 1 ) {
        $novo = str_replace($TOKEN_CIDADE_DE, $TOKEN_CIDADE_PARA, $novo);
        $r['passos'][] = 'formato da cidade: "%s, " -> "%s"';
    } elseif ( $n === 0 ) {
        $r['passos'][] = 'formato da cidade já sem vírgula';
    } else {
        $out['erros'][] = "{$id}: {$n} tokens de formato de cidade — abortado"; continue;
    }

    // 2) custom_css no widget da cidade
    if ( strpos($novo, $CSS) !== false ) {
        $r['passos'][] = 'custom_css já presente';
    } else {
        $pos = strpos($novo, $ABRE_CIDADE);
        if ( $pos === false ) { $out['erros'][] = "{$id}: widget de cidade {$WIDGET_CIDADE} não encontrado"; continue; }
        if ( strpos( substr($novo, $pos, 2500), '"custom_css"' ) !== false ) {
            $out['erros'][] = "{$id}: o widget de cidade já tem OUTRO custom_css — não sobrescrevo"; continue;
        }
        $corte = $pos + strlen($ABRE_CIDADE);
        $novo  = substr($novo, 0, $corte) . $CUSTOM_CSS . substr($novo, $corte);
        $r['passos'][] = 'custom_css inserido no widget ' . $WIDGET_CIDADE;
    }

    if ( $novo === $raw ) { $r['estado'] = 'nada a fazer'; $out['listagens'][] = $r; continue; }

    if ( json_decode($novo, true) === null ) {
        $out['erros'][] = "{$id}: resultado não é JSON válido — abortado"; continue;
    }

    $r['delta_bytes'] = strlen($novo) - strlen($raw);
    $r['estado']      = $APPLY ? 'gravada' : 'GRAVARIA';

    if ( $APPLY ) {
        if ( ! get_post_meta($id, '_elementor_data_bkp_atlas_202609', true) ) {
            update_post_meta($id, '_elementor_data_bkp_atlas_202609', wp_slash($raw));
        }
        update_post_meta($id, '_elementor_data', wp_slash($novo));
        if ( class_exists('\Elementor\Core\Files\CSS\Post') ) {
            ( new \Elementor\Core\Files\CSS\Post($id) )->delete();
        }
        clean_post_cache($id);
    }

    $out['listagens'][] = $r;
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
