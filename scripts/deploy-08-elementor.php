<?php
/**
 * deploy-08-elementor.php — aplica TODAS as edições de _elementor_data do Atlas em PROD.
 * Idempotente. DRY-RUN por padrão; APPLY=1 grava.
 *
 * Mudanças por página (PT 57548 / EN 72730):
 *  1. Inserir widget de filtro país (pais9e6f PT / pais2cbf EN) na posição 2 do container
 *     de filtros, com filter_id ajustado p/ prod (PT 92953, EN 92954). (lê /tmp/pais_widgets_dev.json)
 *  2. Reordenar: País → Estado(89878) → Município/Território(89877) → Área(89879) → Tema(89880) → 4Amaz(89881).
 *  3. Labels (dropdown_placeholder) por filter_id, sem prefixo.
 *  4. Mapa d0df2db: _element_id=map-lista + posts_num=700 + custom_css [aria-label=]→[value=].
 *  5. Lista 4fee551 (EN apenas): apontar lisitng_id para o listing EN do card.
 *  6. Heading "Cultural Atlas of the Amazon" → "...Amazônias" (EN) + post_title.
 *  7. scroll_snap mobile-off via page_settings custom_css.
 */
if ( ! defined('ABSPATH') ) { exit; }
$APPLY = getenv('APPLY') === '1';
$LISTING_EN_CARD = (int) (getenv('LISTING_EN_CARD') ?: 0); // id do listing EN do card (Fase 8b), 0=skip

$pais_widgets = json_decode(file_get_contents('/tmp/pais_widgets_dev.json'), true);

// labels por filter_id (sem prefixo) — PT e EN
$LABELS = [
  'pt' => ['89878'=>'Estado','89877'=>'Município/Território','89879'=>'Área de atuação','89880'=>'Tema prioritário','89881'=>'4 Amazônias'],
  'en' => ['89878'=>'State','89877'=>'Municipality/Territory','89879'=>'Area of expertise','89880'=>'Priority theme','89881'=>'4 Amazônias'],
];
$PAIS_LABEL = ['pt'=>'Filtrar por País','en'=>'Country'];
$PAIS_FILTER_ID = ['pt'=>'92953','en'=>'92954'];   // IDs prod
$PAIS_WIDGET = ['pt'=>'pais9e6f','en'=>'pais2cbf'];
$ORDER = ['92953','92954','89878','89877','89879','89880','89881']; // país, estado, município, área, tema, 4amaz

$out = ['apply'=>$APPLY, 'pages'=>[]];

foreach ([57548=>'pt', 72730=>'en'] as $pg => $lang) {
  $d = get_post_meta($pg, '_elementor_data', true);
  $arr = json_decode($d, true);
  if (!is_array($arr)) { $out['pages'][$pg]=['error'=>'decode fail']; continue; }
  $changes = [];

  // localizar o container de filtros por id conhecido 'e8a0a7e' (recursivo, retorna ref ao nó).
  $CONTAINER_ID = 'e8a0a7e';
  $findById = function(&$els, $targetId) use (&$findById) {
    foreach ($els as $k => $v) {
      if (($els[$k]['id'] ?? '') === $targetId) return $els[$k];
      if (!empty($els[$k]['elements'])) {
        $r = $findById($els[$k]['elements'], $targetId);
        if ($r !== null) return $r;
      }
    }
    return null;
  };
  // setter recursivo: substitui o nó com id alvo pelo $newNode
  $setById = function(&$els, $targetId, $newNode) use (&$setById) {
    foreach ($els as $k => $v) {
      if (($els[$k]['id'] ?? '') === $targetId) { $els[$k] = $newNode; return true; }
      if (!empty($els[$k]['elements'])) {
        if ($setById($els[$k]['elements'], $targetId, $newNode)) return true;
      }
    }
    return false;
  };
  $container = $findById($arr, $CONTAINER_ID);
  if ($container === null) { $out['pages'][$pg]=['error'=>'filter container e8a0a7e not found']; continue; }

  // 1. inserir widget país se ausente
  $hasPais = false;
  foreach ($container['elements'] as $c) if (($c['id']??'')===$PAIS_WIDGET[$lang]) { $hasPais=true; break; }
  if (!$hasPais) {
    $w = $pais_widgets[$PAIS_WIDGET[$lang]];
    $w['settings']['filter_id'] = [ $PAIS_FILTER_ID[$lang] ];           // ajustar p/ prod
    $w['settings']['dropdown_placeholder'] = $PAIS_LABEL[$lang];
    $container['elements'][] = $w; // adiciona; reordenação abaixo posiciona
    $changes[] = 'inserted_pais_widget';
  }

  // 3. labels + placeholder país
  foreach ($container['elements'] as &$c) {
    $wt = $c['widgetType'] ?? '';
    if (strpos($wt,'jet-smart-filters-checkboxes')===false) continue;
    $fid = $c['settings']['filter_id'][0] ?? null;
    if ($fid === $PAIS_FILTER_ID[$lang]) { $c['settings']['dropdown_placeholder']=$PAIS_LABEL[$lang]; }
    elseif (isset($LABELS[$lang][$fid])) {
      if (($c['settings']['dropdown_placeholder']??'') !== $LABELS[$lang][$fid]) { $c['settings']['dropdown_placeholder']=$LABELS[$lang][$fid]; $changes[]="label_$fid"; }
    }
  } unset($c);

  // 2. reordenar widgets de filtro conforme $ORDER (mantém não-filtros na ordem)
  $filters = []; $others = [];
  foreach ($container['elements'] as $c) {
    if (strpos($c['widgetType']??'','jet-smart-filters-checkboxes')!==false) {
      $fid = $c['settings']['filter_id'][0] ?? '?';
      $filters[$fid] = $c;
    } else $others[] = $c;
  }
  $ordered_filters = [];
  foreach ($ORDER as $fid) if (isset($filters[$fid])) { $ordered_filters[]=$filters[$fid]; unset($filters[$fid]); }
  foreach ($filters as $c) $ordered_filters[] = $c; // resto (ex: search já está em others)
  // reconstruir: others (search etc) primeiro na posição original? Simplificar: search no início, filtros depois
  // No dev a ordem era: search(0,1) depois filtros. Manter others + ordered_filters.
  $container['elements'] = array_merge($others, $ordered_filters);
  $changes[] = 'reordered';

  // gravar o container modificado de volta na árvore
  $setById($arr, $CONTAINER_ID, $container);

  // 4. mapa d0df2db: _element_id + posts_num + custom_css cor
  $walkAll = function(&$els) use (&$walkAll, &$changes, $lang) {
    foreach ($els as &$e) {
      if (($e['id']??'')==='d0df2db') {
        if (($e['settings']['_element_id']??'')!=='map-lista') { $e['settings']['_element_id']='map-lista'; $changes[]='map_element_id'; }
        if ((string)($e['settings']['posts_num']??'')!=='700') { $e['settings']['posts_num']='700'; $changes[]='posts_num'; }
        if (isset($e['settings']['custom_css'])) {
          $new = preg_replace('/\[aria-label=/','[value=',$e['settings']['custom_css']);
          if ($new !== $e['settings']['custom_css']) { $e['settings']['custom_css']=$new; $changes[]='color_css'; }
        }
      }
      // heading título EN
      if ($lang==='en' && ($e['widgetType']??'')==='heading' && ($e['settings']['title']??'')==='Cultural Atlas of the Amazon') {
        $e['settings']['title']='Cultural Atlas of the Amazônias'; $changes[]='heading_title';
      }
      // lista 4fee551 EN -> listing EN do card
      if ($lang==='en' && ($e['id']??'')==='4fee551' && $GLOBALS['__listing_en_card']) {
        if ((string)($e['settings']['lisitng_id']??'')!==(string)$GLOBALS['__listing_en_card']) {
          $e['settings']['lisitng_id']=(string)$GLOBALS['__listing_en_card']; $changes[]='card_listing_en';
        }
      }
      if (!empty($e['elements'])) $walkAll($e['elements']);
    } unset($e);
  };
  $GLOBALS['__listing_en_card'] = $LISTING_EN_CARD;
  $walkAll($arr);

  if ($APPLY) {
    update_post_meta($pg, '_elementor_data', wp_slash(wp_json_encode($arr, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)));
    delete_post_meta($pg, '_elementor_element_cache');
    if (class_exists('\Elementor\Core\Files\CSS\Post')) (new \Elementor\Core\Files\CSS\Post($pg))->update();
    // page_settings: scroll_snap mobile-off
    $ps = get_post_meta($pg, '_elementor_page_settings', true);
    if (!is_array($ps)) $ps = [];
    $css = $ps['custom_css'] ?? '';
    if (strpos($css,'scroll-snap-type: none') === false) {
      $ps['custom_css'] = trim($css."\n@media (max-width:767px){ body, html { scroll-snap-type: none !important; } body > *, .e-con { scroll-snap-align: none !important; } }");
      update_post_meta($pg, '_elementor_page_settings', $ps);
      $changes[] = 'snap_mobile_off';
    }
    // título EN post_title
    if ($lang==='en') wp_update_post(['ID'=>$pg, 'post_title'=>'Cultural Atlas of the Amazônias']);
  }

  $out['pages'][$pg] = ['lang'=>$lang, 'changes'=>array_count_values($changes)];
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
