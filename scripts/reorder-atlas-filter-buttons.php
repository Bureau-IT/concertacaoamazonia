<?php
/**
 * Reordena filhos do container de filtros e8a0a7e na página $PID para a ordem do prod:
 * heading → search → [container botões] → html → dropdowns de filtro
 * (move o container de botões c81c4ef + html 79a71a4 para logo após o search)
 * Idempotente. DRY-RUN por padrão; APPLY=1 grava.
 */
if ( ! defined('ABSPATH') ) { exit; }
$APPLY = getenv('APPLY') === '1';
$PID = (int) getenv('PID');
$CID = 'e8a0a7e';
$SEARCH = '01f2e58';
$BTN_CONTAINER = 'c81c4ef';
$HTML_SEP = '79a71a4';

$d = get_post_meta($PID, '_elementor_data', true);
$arr = json_decode($d, true);
if (!is_array($arr)) { echo json_encode(['error'=>'decode fail']); return; }

$out = ['apply'=>$APPLY, 'pid'=>$PID];

$setById = function(&$els, $cid, $newChildren) use (&$setById) {
  foreach ($els as $k=>$v) {
    if (($els[$k]['id']??'')===$cid) { $els[$k]['elements'] = $newChildren; return true; }
    if (!empty($els[$k]['elements'])) if ($setById($els[$k]['elements'],$cid,$newChildren)) return true;
  }
  return false;
};
$getById = function($els, $cid) use (&$getById) {
  foreach ($els as $e) {
    if (($e['id']??'')===$cid) return $e;
    if (!empty($e['elements'])) { $r=$getById($e['elements'],$cid); if ($r!==null) return $r; }
  }
  return null;
};

$container = $getById($arr, $CID);
if (!$container) { echo json_encode(['error'=>"container $CID não encontrado"]); return; }

$children = $container['elements'] ?? [];
$order_before = array_map(fn($c)=>$c['id']??'?', $children);

// separar: search, btn_container, html_sep, e o resto (heading + filtros)
$search=null; $btn=null; $html=null; $rest=[];
foreach ($children as $c) {
  $id = $c['id']??'';
  if ($id===$SEARCH) $search=$c;
  elseif ($id===$BTN_CONTAINER) $btn=$c;
  elseif ($id===$HTML_SEP) $html=$c;
  else $rest[] = $c;
}
// rest tem [heading, dropdowns...]. Queremos: heading, search, btn, html, dropdowns
// heading é o 1º do rest (42b9d1e); separar heading dos dropdowns
$heading=null; $dropdowns=[];
foreach ($rest as $c) {
  if (($c['widgetType']??'')==='heading' && $heading===null) $heading=$c;
  else $dropdowns[] = $c;
}

$new = [];
if ($heading) $new[] = $heading;
if ($search)  $new[] = $search;
if ($btn)     $new[] = $btn;
if ($html)    $new[] = $html;
foreach ($dropdowns as $d2) $new[] = $d2;

$order_after = array_map(fn($c)=>$c['id']??'?', $new);
$out['order_before'] = $order_before;
$out['order_after'] = $order_after;

// idempotência: se já está na ordem alvo, não grava
if ($order_before === $order_after) { $out['skip']='já na ordem alvo'; echo json_encode($out, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE); return; }

if (!$APPLY) { echo json_encode($out, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE); return; }

$setById($arr, $CID, $new);
update_post_meta($PID, '_elementor_data', wp_slash(wp_json_encode($arr, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)));
delete_post_meta($PID, '_elementor_element_cache');
if (class_exists('\Elementor\Core\Files\CSS\Post')) (new \Elementor\Core\Files\CSS\Post($PID))->update();
$out['saved'] = true;
echo json_encode($out, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
