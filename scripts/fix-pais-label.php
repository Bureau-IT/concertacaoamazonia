<?php
/**
 * Corrige o dropdown_placeholder do widget país PT (pais9e6f) de "Filtrar por País" → "País"
 * na página 57548. Idempotente. DRY-RUN por padrão; APPLY=1 grava.
 */
if ( ! defined('ABSPATH') ) { exit; }
$APPLY = getenv('APPLY') === '1';
$PID = 57548;
$WIDGET = 'pais9e6f';
$NEW = 'País';

$d = get_post_meta($PID, '_elementor_data', true);
$arr = json_decode($d, true);
if (!is_array($arr)) { echo json_encode(['error'=>'decode fail']); return; }

$changed = false; $old = null;
$walk = function(&$els) use (&$walk, $WIDGET, $NEW, &$changed, &$old) {
  foreach ($els as &$e) {
    if (($e['id']??'')===$WIDGET) {
      $old = $e['settings']['dropdown_placeholder'] ?? null;
      if ($old !== $NEW) { $e['settings']['dropdown_placeholder'] = $NEW; $changed = true; }
    }
    if (!empty($e['elements'])) $walk($e['elements']);
  } unset($e);
};
$walk($arr);

$out = ['apply'=>$APPLY, 'widget'=>$WIDGET, 'old'=>$old, 'new'=>$NEW, 'changed'=>$changed];
if (!$changed) { $out['skip']='já correto ou widget não encontrado'; echo json_encode($out, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE); return; }
if (!$APPLY) { echo json_encode($out, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE); return; }

update_post_meta($PID, '_elementor_data', wp_slash(wp_json_encode($arr, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)));
delete_post_meta($PID, '_elementor_element_cache');
if (class_exists('\Elementor\Core\Files\CSS\Post')) (new \Elementor\Core\Files\CSS\Post($PID))->update();
$out['saved'] = true;
echo json_encode($out, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
