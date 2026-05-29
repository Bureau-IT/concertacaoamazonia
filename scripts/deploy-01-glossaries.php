<?php
/**
 * deploy-01-glossaries.php — importa glossários do DEV em PROD.
 * Lê /tmp/dev_glossaries.json (export do dev: id,slug,status,labels,args,meta_fields
 * com labels/args/meta_fields já PHP-serializados como string).
 * UPDATE para ids existentes, INSERT para novos. Adiciona 71/72/73 ao orders.
 * DRY-RUN por padrão; APPLY=1 grava.
 */
if ( ! defined('ABSPATH') ) { exit; }
$APPLY = getenv('APPLY') === '1';
global $wpdb;
$table = $wpdb->prefix . 'jet_post_types';

$json = file_get_contents('/tmp/dev_glossaries.json');
$rows = json_decode($json, true);
if ( ! is_array($rows) ) { echo json_encode(['error'=>'json inválido']); return; }

$out = ['apply'=>$APPLY, 'updated'=>[], 'inserted'=>[], 'errors'=>[]];

foreach ($rows as $g) {
    $id   = (int) $g['id'];
    $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE id=%d", $id));
    // dados: labels/args/meta_fields são strings PHP-serializadas — gravar AS-IS
    $data = [
        'slug'        => $g['slug'],
        'status'      => $g['status'],
        'labels'      => $g['labels'],
        'args'        => $g['args'],
        'meta_fields' => $g['meta_fields'],
    ];
    // validar que meta_fields desserializa (sanity, não regrava)
    $check = @unserialize($g['meta_fields']);
    $nitems = is_array($check) ? count($check) : 'INVALID';

    if ( ! $APPLY ) {
        $out[$exists ? 'updated' : 'inserted'][] = "id=$id items=$nitems (".($exists?'WOULD-UPDATE':'WOULD-INSERT').")";
        continue;
    }
    if ( $exists ) {
        $ok = $wpdb->update($table, $data, ['id'=>$id]);
        $out['updated'][] = "id=$id items=$nitems wpdb=".json_encode($ok);
    } else {
        $data['id'] = $id;
        $ok = $wpdb->insert($table, $data);
        if ($ok === false) $out['errors'][] = "insert id=$id: ".$wpdb->last_error;
        else $out['inserted'][] = "id=$id items=$nitems";
    }
}

// orders: adicionar 71,72,73 se ausentes
if ( $APPLY ) {
    $orders = get_option('jet_engine_glossaries_orders', []);
    if ( ! is_array($orders) ) $orders = [];
    foreach (['71','72','73'] as $oid) { if (!in_array($oid,$orders,true)) $orders[] = $oid; }
    update_option('jet_engine_glossaries_orders', $orders);
    $out['orders'] = implode(',', $orders);
    // limpar cache glossários
    if ( function_exists('jet_engine') && isset(jet_engine()->glossaries) ) {
        if (method_exists(jet_engine()->glossaries->data,'clear_cache')) jet_engine()->glossaries->data->clear_cache();
    }
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
