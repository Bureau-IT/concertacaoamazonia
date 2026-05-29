<?php
/**
 * deploy-03-reconcile-meta.php — aplica pais/cidade/estado por artista (CSV 5).
 * Lê /tmp/reconcile_map_prod.json [{id,pais,cidade,estado}]. Idempotente.
 * Só grava quando o valor difere. estado vazio nos internacionais é aplicado
 * (set vazio). DRY-RUN por padrão; APPLY=1 grava.
 */
if ( ! defined('ABSPATH') ) { exit; }
$APPLY = getenv('APPLY') === '1';
$map = json_decode(file_get_contents('/tmp/reconcile_map_prod.json'), true);
if ( ! is_array($map) ) { echo json_encode(['error'=>'json inválido']); return; }

$out = ['apply'=>$APPLY, 'pais_set'=>0, 'cidade_set'=>0, 'estado_set'=>0, 'estado_cleared'=>0, 'samples'=>[]];
foreach ($map as $r) {
    $id = (int) $r['id'];
    if ( ! get_post($id) ) continue;
    // pais
    $cur = get_post_meta($id, 'pais', true);
    if ( $r['pais'] !== '' && $cur !== $r['pais'] ) {
        if ($APPLY) update_post_meta($id, 'pais', $r['pais']);
        $out['pais_set']++;
    }
    // cidade
    $cur = get_post_meta($id, 'cidade', true);
    if ( $r['cidade'] !== '' && $cur !== $r['cidade'] ) {
        if ($APPLY) update_post_meta($id, 'cidade', $r['cidade']);
        $out['cidade_set']++;
    }
    // estado: set valor OU limpar (internacionais com estado='')
    $cur = get_post_meta($id, 'estado', true);
    if ( $r['estado'] !== '' ) {
        if ( $cur !== $r['estado'] ) { if ($APPLY) update_post_meta($id,'estado',$r['estado']); $out['estado_set']++; }
    } else {
        // estado alvo vazio (internacional): limpar só se atualmente tem valor E é país
        if ( $cur !== '' && $r['pais'] !== '' && $r['pais'] !== 'Brasil' ) {
            if ($APPLY) update_post_meta($id,'estado',''); $out['estado_cleared']++;
        }
    }
    if (count($out['samples'])<5) $out['samples'][] = "id=$id pais={$r['pais']} cidade={$r['cidade']} estado=".($r['estado']?:'(empty)');
}
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
