<?php
/**
 * 05-fermat-coords.php — Atlas Cultural (concertacao, blog 2 /cultura/)
 *
 * Regenera as coordenadas dos artistas via espiral de Fermat AGRUPADA POR LOCALIDADE
 * (cidade|estado, ou "(estado) X" quando só há estado). Mesma coordenada gravada no
 * par PT<->EN (via WPML trid). Centroides + raios vêm de /tmp/centroides.json.
 *
 * Algoritmo idêntico ao geopoint-resolver (ângulo dourado + raio sqrt + correção de lon).
 * Idempotente por reescrita total. DRY-RUN por padrão; APPLY=1 grava.
 *
 * Autor: Daniel Cambría
 */

if ( ! defined('ABSPATH') ) { exit; }

$APPLY = getenv('APPLY') === '1';
$coords_map_raw = json_decode(file_get_contents('/tmp/centroides.json'), true);
if ( ! is_array($coords_map_raw) ) { echo json_encode(['error'=>'centroides.json inválido']); return; }
// normalizar chaves do mapa para NFC
$coords_map = [];
foreach ($coords_map_raw as $k => $v) {
    $nk = class_exists('Normalizer') ? Normalizer::normalize($k, Normalizer::FORM_C) : $k;
    $coords_map[$nk] = $v;
}

function fermat_points($lat, $lon, $n, $radius_km) {
    if ($n <= 0) return [];
    if ($n == 1) return [[round($lat,7), round($lon,7)]];
    $r_max = ($radius_km / 111.0) * 0.98;
    $golden = M_PI * (3 - sqrt(5));
    $pts = [];
    for ($i = 0; $i < $n; $i++) {
        $r = $r_max * sqrt($i / $n);
        $theta = $i * $golden;
        $cos_lat = cos(deg2rad($lat)) ?: 0.001;
        $px = $lon + ($r * cos($theta)) / $cos_lat;
        $py = $lat + $r * sin($theta);
        $pts[] = [round($py,7), round($px,7)];
    }
    return $pts;
}

global $wpdb;
// Agrupar artistas PT publish por localidade
$ids = $wpdb->get_col("SELECT ID FROM wp_2_posts WHERE post_type='artistas' AND post_status='publish'");
$groups = []; // key -> [pt_id,...]
$no_loc = 0;
foreach ($ids as $id) {
    $det = apply_filters('wpml_post_language_details', null, $id);
    if (($det['language_code'] ?? '') !== 'pt-br') continue;
    $c = trim(get_post_meta($id, 'cidade', true));
    $e = trim(get_post_meta($id, 'estado', true));
    if (!$c && !$e) { $no_loc++; continue; }
    $key = $c ? ($c.' | '.$e) : ('(estado) '.$e);
    // normalizar para NFC (banco pode ter "ã" decomposto NFD)
    if ( class_exists('Normalizer') ) { $key = Normalizer::normalize($key, Normalizer::FORM_C); }
    $groups[$key][] = (int)$id;
}

$out = ['apply'=>$APPLY, 'localities'=>count($groups), 'no_loc'=>$no_loc, 'updated_pt'=>0, 'updated_en'=>0, 'missing_centroid'=>[], 'samples'=>[]];

foreach ($groups as $key => $pt_ids) {
    if ( ! isset($coords_map[$key]) ) { $out['missing_centroid'][] = $key.' ('.count($pt_ids).')'; continue; }
    $info = $coords_map[$key];
    // ordenar IDs para determinismo
    sort($pt_ids);
    $pts = fermat_points((float)$info['lat'], (float)$info['lon'], count($pt_ids), (float)($info['radius_km'] ?? 12));

    foreach ($pt_ids as $idx => $pt_id) {
        $coord = $pts[$idx][0].','.$pts[$idx][1];
        if ($APPLY) {
            update_post_meta($pt_id, 'coordenada', $coord);
            $out['updated_pt']++;
            // par EN
            $en = apply_filters('wpml_object_id', $pt_id, 'artistas', false, 'en');
            if ($en && $en != $pt_id) { update_post_meta($en, 'coordenada', $coord); $out['updated_en']++; }
        }
        if (count($out['samples']) < 6) $out['samples'][] = "$key #$idx -> $coord (pt=$pt_id)";
    }
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
