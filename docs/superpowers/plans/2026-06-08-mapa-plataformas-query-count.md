# Mapa de Plataformas — Query própria + fix contador — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fazer o contador "Mostrando X de Y" da página Mapa de Plataformas contar **plataformas** (não estudos) e eliminar o bug em que resultados <12 mostram "12", com paridade PT/EN; corrigir também a origem do bug na Espiral.

**Architecture:** Solução 100% data-driven (sem mu-plugin). Cria uma query JetEngine para o CPT `plataformas`, religa o listing grid a ela via `custom_query_id`, e aponta o dynamic tag `jet-query-count` para essa query usando a macro robusta `[end-item]`. Edições no `_elementor_data` feitas por script PHP que localiza widgets por ID (decode → mutate → `wp_json_encode` → `wp_slash`).

**Tech Stack:** WordPress Multisite (blog 1), JetEngine Query Builder, JetSmartFilters, Elementor, WP-CLI dentro do container `concertacao-dev-wordpress`.

---

## Contexto essencial (ler antes de começar)

**IDs envolvidos (blog 1, raiz):**

| Item | ID | Papel |
|---|---|---|
| Página Mapa de Plataformas (PT) | `26827` | onde está o count bugado |
| Página Platform Map (EN) | `75718` | sem count hoje; vai ganhar |
| Página Espiral de Conhecimento (PT) | `26826` | origem do bug `%visible%` |
| Página Spiral of Knowledge (EN) | `79123` | origem do bug + texto em PT |
| Listing grid "Listagem Plataformas Externas" | `14035` | fonte interna CPT `plataformas` |
| Query JetEngine "Objetos para Espiral" | `12` | query ERRADA que o count aponta hoje |
| Widget listing na Mapa (PT e EN) | `23d592f` | precisa de `custom_query_id` |
| Widget count na Mapa PT | `b2d868d` | reapontar + `[end-item]` |
| Widget active-filters EN (vizinho onde inserir count) | `91ad0ae` | referência de posição |
| Widget count Espiral (bugado, `%visible%`) PT/EN | `bb87a69` | trocar p/ `[end-item]` |
| Widget count Espiral (já ok, `[end-item]`) PT/EN | `0781799` | só traduzir no EN |

**`<NEW_QID>`** = ID numérico da query nova de plataformas, gerado na Task 1. **Toda referência a `<NEW_QID>` nos scripts adiante deve ser substituída pelo valor real.**

**Snapshots de segurança** já criados em
`tmp/mapa-plataformas-fix/snapshot_{26827,75718,26826,79123}.json`.

**Comando WP-CLI base (não-interativo):**
```bash
docker exec -u www-data concertacao-dev-wordpress wp <args>
```

**GOTCHAS CRÍTICOS (deste repo):**
1. `update_post_meta` de `_elementor_data` **EXIGE** `wp_slash(wp_json_encode($arr))`. Sem `wp_slash`, o `json_decode` roundtrip do WP vira NULL e a página perde CSS/quebra.
2. Após editar, **deletar `_elementor_element_cache`** da página (HTML renderizado sobrevive a flush comum).
3. Em **dev**, OPcache revalida por mtime — não precisa reload FPM. Regen do CSS Elementor é recomendado mas não crítico.
4. Query nova: **`cache_query=false`** (evita servir idioma errado em WPML).

---

## Task 1: Criar a query JetEngine "Query Plataformas"

**Files:** nenhum arquivo — cria registro no Query Builder (dev).

- [ ] **Step 1: Verificar estado atual (baseline)**

Run:
```bash
docker exec -u www-data concertacao-dev-wordpress wp post list --post_type=plataformas --post_status=publish --format=count
```
Expected: `60`

- [ ] **Step 2: Criar a query via MCP jetengine-mcp `tool-add-query`**

Chamar a ferramenta MCP com:
```json
{
  "name": "Query Plataformas",
  "query_type": "posts",
  "query_args": {
    "post_type": "plataformas",
    "post_status": "publish",
    "posts_per_page": 12,
    "orderby": "title",
    "order": "ASC"
  }
}
```
Anotar o `id` retornado → este é o **`<NEW_QID>`**.

> Se o MCP não permitir setar `cache_query`, ajustar no Step 4.

- [ ] **Step 3: Confirmar a query criada e capturar o ID**

Run:
```bash
docker exec -u www-data concertacao-dev-wordpress wp eval '
$m = \Jet_Engine\Query_Builder\Manager::instance();
foreach($m->get_queries() as $id=>$q){ if($q->name==="Query Plataformas"){ echo "NEW_QID=".$id."\n"; } }
'
```
Expected: `NEW_QID=<algum número>` (ex.: `NEW_QID=76`). **Guardar esse número.**

- [ ] **Step 4: Garantir `cache_query=false` e validar a contagem da query**

Run (substituir `<NEW_QID>`):
```bash
docker exec -u www-data concertacao-dev-wordpress wp eval '
$qid = <NEW_QID>;
$m = \Jet_Engine\Query_Builder\Manager::instance();
$q = $m->get_query_by_id($qid);
echo "total = ".$q->get_items_total_count()."\n";
echo "cache_query = ".var_export($q->cache_query, true)."\n";
'
```
Expected: `total = 60` e `cache_query` vazio/false. Se `cache_query` vier `true`, editar a query no admin JetEngine (Query Builder → Query Plataformas → aba Cache → desmarcar) e repetir.

- [ ] **Step 5: Commit (registro de progresso — sem arquivos de código ainda)**

Não há arquivo versionado nesta task (query vive no banco dev). Pular commit; anotar `<NEW_QID>` no checklist.

---

## Task 2: Religar o listing grid à nova query (PT 26827 + EN 75718)

O widget `23d592f` existe igual nas duas páginas. Vamos setar **`custom_query=yes`** (CHAVE CORRETA — gate de `get_query_id()`) + `custom_query_id=<NEW_QID>` e ajustar `posts_num=12`.

> **⚠️ CHAVE CORRETA = `custom_query`, NÃO `use_custom_query`.** Validado em dev:
> `query-builder/listings/manager.php::get_query_id()` lê
> `filter_var($settings['custom_query'], FILTER_VALIDATE_BOOLEAN)`. Sem
> `custom_query=yes`, o listing renderiza pela fonte interna, o hook que emite os
> count-fragments do AJAX (`maybe_setup_filter`) NÃO dispara, e o contador fica
> congelado em "12" sob qualquer filtro (= o bug "<12 mostra 12"). NÃO usar
> `use_custom_query` (não é lido). O script abaixo já usa a chave certa e remove
> `use_custom_query` se existir.

**Files:**
- Modify (banco): meta `_elementor_data` de `26827` e `75718`

- [ ] **Step 1: Backup fresco dos dois metas**

Run:
```bash
cd /Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao/tmp/mapa-plataformas-fix
for ID in 26827 75718; do docker exec -u www-data concertacao-dev-wordpress wp post meta get $ID _elementor_data --format=json > pre_task2_$ID.json; done
ls -la pre_task2_*.json
```
Expected: dois arquivos `pre_task2_26827.json` e `pre_task2_75718.json` com ~40KB cada.

- [ ] **Step 2: Aplicar o relink via script PHP (decode → mutate by ID → encode+slash)**

Run (substituir `<NEW_QID>` pelo valor real):
```bash
docker exec -u www-data concertacao-dev-wordpress wp eval '
$qid = "<NEW_QID>";
foreach ([26827, 75718] as $pid) {
  $raw = get_post_meta($pid, "_elementor_data", true);
  $data = json_decode($raw, true);
  if (!is_array($data)) { echo "ERRO decode em $pid\n"; continue; }
  $changed = false;
  $mut = function(&$els) use (&$mut, $qid, &$changed) {
    foreach ($els as &$el) {
      if (($el["id"] ?? "") === "23d592f") {
        $el["settings"]["custom_query"] = "yes";      // CHAVE CORRETA (gate get_query_id)
        $el["settings"]["custom_query_id"] = $qid;
        unset($el["settings"]["use_custom_query"]);    // remove chave errada se existir
        $el["settings"]["posts_num"] = 12;
        $changed = true;
      }
      if (!empty($el["elements"])) $mut($el["elements"]);
    }
  };
  $mut($data);
  if (!$changed) { echo "AVISO: widget 23d592f nao encontrado em $pid\n"; continue; }
  $encoded = wp_json_encode($data);
  if (json_decode($encoded) === null) { echo "ERRO: encode invalido em $pid\n"; continue; }
  update_post_meta($pid, "_elementor_data", wp_slash($encoded));
  echo "OK $pid relinkado para query $qid\n";
}
'
```
Expected:
```
OK 26827 relinkado para query <NEW_QID>
OK 75718 relinkado para query <NEW_QID>
```

- [ ] **Step 3: Validar a gravação (settings do widget + JSON íntegro)**

Run:
```bash
docker exec -u www-data concertacao-dev-wordpress wp eval '
foreach ([26827,75718] as $pid){
  $raw = get_post_meta($pid,"_elementor_data",true);
  $d = json_decode($raw,true);
  echo "[$pid] json_valid=".(is_array($d)?"SIM":"NAO")."\n";
  $f=null; $w=function($els)use(&$w,&$f){foreach($els as $el){if(($el["id"]??"")==="23d592f")$f=$el["settings"];if(!empty($el["elements"]))$w($el["elements"]);}};
  $w($d);
  echo "   custom_query=".($f["custom_query"]??"-")." custom_query_id=".($f["custom_query_id"]??"-")." posts_num=".($f["posts_num"]??"-")."\n";
}
'
```
Expected (ambas): `json_valid=SIM`, `custom_query=yes custom_query_id=<NEW_QID> posts_num=12`.

- [ ] **Step 3b: Validar AO VIVO que o contador atualiza no filtro (gate do fix)**

Abrir `https://cambrasmax.local:8484/conhecimento/mapa-das-plataformas/` no browser, digitar "Emergência" no campo de busca (com keystrokes reais — `pressSequentially` no Playwright; eventos sintéticos `dispatchEvent` NÃO disparam o debounce do JSF de forma confiável). Aguardar o AJAX.
Expected: o contador muda de "Mostrando 12 de 60..." para **"Mostrando 1 de 1 plataformas cadastradas"** (1 card renderizado). Se continuar "12 de 60" com 1 card, o `custom_query` NÃO pegou — revisar Step 2.

- [ ] **Step 4: Limpar caches Elementor das 2 páginas**

Run:
```bash
docker exec -u www-data concertacao-dev-wordpress wp eval '
foreach ([26827,75718] as $pid){
  delete_post_meta($pid, "_elementor_element_cache");
  if (class_exists("\\Elementor\\Plugin")) {
    \Elementor\Plugin::$instance->files_manager->clear_cache(); // OK em dev; ver nota
  }
  echo "cache limpo $pid\n";
}
'
```
> **Nota prod:** `clear_cache()` é GLOBAL. Em **dev** é aceitável. Em **prod** usar `(new \Elementor\Core\Files\CSS\Post($pid))->update()` por página (ver Task 6). Aqui (dev) o global simplifica.

Expected: `cache limpo 26827` / `cache limpo 75718`.

- [ ] **Step 5: Flush dev**

Run:
```bash
/Users/dcambria/scripts/server-tools/v2/docker-dev/common/bin/docker-dev.sh cache-flush
```
Expected: flush concluído sem erro.

- [ ] **Step 6: Commit do snapshot pós-edição (rastreabilidade)**

Os `_elementor_data` vivem no banco (não versionados). Atualizar snapshots:
```bash
cd /Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao/tmp/mapa-plataformas-fix
for ID in 26827 75718; do docker exec -u www-data concertacao-dev-wordpress wp post meta get $ID _elementor_data --format=json > post_task2_$ID.json; done
echo "snapshots pós-task2 salvos"
```
Expected: dois arquivos `post_task2_*.json`. (Sem git commit — são artefatos de tmp/, gitignored.)

---

## Task 3: Reapontar o count PT (26827) para a nova query com `[end-item]`

**Files:** Modify (banco) meta `_elementor_data` de `26827`, widget `b2d868d`.

**Texto alvo PT:** `Mostrando [end-item] de %total% plataformas cadastradas`

- [ ] **Step 1: Aplicar via script PHP (constrói o dynamic tag com query certa)**

Run (substituir `<NEW_QID>`):
```bash
docker exec -u www-data concertacao-dev-wordpress wp eval '
$qid = "<NEW_QID>";
$pid = 26827;
$fmt = "Mostrando [end-item] de %total% plataformas cadastradas";
$settings = ["query_id"=>$qid, "custom_format"=>$fmt, "count_type"=>"custom_format"];
$enc = rawurlencode(wp_json_encode($settings));
$tag = "[elementor-tag id=\"94acaed\" name=\"jet-query-count\" settings=\"".$enc."\"]";
$raw = get_post_meta($pid,"_elementor_data",true);
$data = json_decode($raw,true);
$changed=false;
$mut=function(&$els)use(&$mut,$tag,&$changed){
  foreach($els as &$el){
    if(($el["id"]??"")==="b2d868d"){ $el["settings"]["__dynamic__"]["title"]=$tag; $changed=true; }
    if(!empty($el["elements"]))$mut($el["elements"]);
  }
};
$mut($data);
if(!$changed){echo "AVISO b2d868d nao achado\n";exit;}
$encoded=wp_json_encode($data);
if(json_decode($encoded)===null){echo "ERRO encode\n";exit;}
update_post_meta($pid,"_elementor_data",wp_slash($encoded));
echo "OK count 26827 reapontado para query $qid com [end-item]\n";
'
```
Expected: `OK count 26827 reapontado para query <NEW_QID> com [end-item]`

- [ ] **Step 2: Validar o dynamic tag gravado**

Run:
```bash
docker exec -u www-data concertacao-dev-wordpress wp eval '
$d=json_decode(get_post_meta(26827,"_elementor_data",true),true);
$f=null;$w=function($els)use(&$w,&$f){foreach($els as $el){if(($el["id"]??"")==="b2d868d")$f=$el;if(!empty($el["elements"]))$w($el["elements"]);}};
$w($d);
echo "json_valid=".(is_array($d)?"SIM":"NAO")."\n";
preg_match("/settings=\"([^\"]+)\"/",$f["settings"]["__dynamic__"]["title"],$m);
echo "decoded=".rawurldecode($m[1])."\n";
'
```
Expected: `json_valid=SIM` e `decoded={"query_id":"<NEW_QID>","custom_format":"Mostrando [end-item] de %total% plataformas cadastradas","count_type":"custom_format"}`

- [ ] **Step 3: Limpar cache da página + flush**

Run:
```bash
docker exec -u www-data concertacao-dev-wordpress wp eval 'delete_post_meta(26827,"_elementor_element_cache"); echo "ok\n";'
/Users/dcambria/scripts/server-tools/v2/docker-dev/common/bin/docker-dev.sh cache-flush
```
Expected: `ok` + flush concluído.

- [ ] **Step 4: Verificação no browser (PT)**

Via Playwright MCP ou navegador: abrir `https://cambrasmax.local:8484/conhecimento/mapa-das-plataformas/`.
Expected: cabeçalho de resultados mostra **"Mostrando 12 de 60 plataformas cadastradas"** (12 itens na 1ª página, 60 total). Listing renderiza cards de plataformas (não estudos).

- [ ] **Step 5: Verificação do bug "<12" (PT)**

Buscar um termo que retorne poucos resultados (ex.: digitar um nome específico de plataforma no campo de busca).
Expected: a mensagem mostra o número REAL (ex.: "Mostrando 2 de 2 plataformas cadastradas"), **nunca "12"**.

---

## Task 4: Adicionar o count no EN (75718) com `[end-item]`, em inglês

O EN não tem widget de count. Vamos **clonar** o widget `b2d868d` do PT (com novo ID) e inseri-lo no container do EN que contém o active-filters (`91ad0ae`, dentro do container em path `/0/1/1/0`), em inglês.

**Texto alvo EN:** `Showing [end-item] of %total% registered platforms`

**Files:** Modify (banco) meta `_elementor_data` de `75718`.

- [ ] **Step 1: Inserir o widget count clonado via script PHP**

Run (substituir `<NEW_QID>`):
```bash
docker exec -u www-data concertacao-dev-wordpress wp eval '
$qid = "<NEW_QID>";
$pid = 75718;
$fmt = "Showing [end-item] of %total% registered platforms";
$settings = ["query_id"=>$qid,"custom_format"=>$fmt,"count_type"=>"custom_format"];
$tag = "[elementor-tag id=\"94acaed\" name=\"jet-query-count\" settings=\"".rawurlencode(wp_json_encode($settings))."\"]";
// novo widget (clone do b2d868d, id novo unico)
$newId = " b2d868e"; $newId = trim($newId);
$widget = [
  "id"=>$newId,"elType"=>"widget","widgetType"=>"heading","elements"=>[],
  "settings"=>[
    "title"=>"","align"=>"left","title_color"=>"#FFFFFF",
    "typography_typography"=>"custom","typography_font_family"=>"Just Sans",
    "typography_font_size"=>["unit"=>"em","size"=>0.9,"sizes"=>[]],
    "typography_font_weight"=>"400","header_size"=>"div",
    "typography_line_height"=>["unit"=>"em","size"=>1,"sizes"=>[]],
    "_element_vertical_align"=>"center","_flex_align_self_mobile"=>"center",
    "__dynamic__"=>["title"=>$tag],
    "__globals__"=>["title_color"=>"globals/colors?id=text","typography_typography"=>""],
    "_title"=>"1-xx of xxx platforms"
  ]
];
$raw = get_post_meta($pid,"_elementor_data",true);
$data = json_decode($raw,true);
// localizar container /0/1/1/0 (o que tem o active-filters 91ad0ae) e prepend o count
$inserted=false;
$walk=function(&$els)use(&$walk,&$widget,&$inserted){
  foreach($els as &$el){
    if(!empty($el["elements"])){
      // se este container contem diretamente o 91ad0ae, inserir o count como primeiro filho
      foreach($el["elements"] as $child){ if(($child["id"]??"")==="91ad0ae"){ array_unshift($el["elements"],$widget); $inserted=true; break; } }
      if($inserted) return;
      $walk($el["elements"]);
      if($inserted) return;
    }
  }
};
$walk($data);
if(!$inserted){echo "AVISO: container do 91ad0ae nao encontrado; nada inserido\n";exit;}
$encoded=wp_json_encode($data);
if(json_decode($encoded)===null){echo "ERRO encode\n";exit;}
update_post_meta($pid,"_elementor_data",wp_slash($encoded));
echo "OK count EN inserido (id ".$widget["id"].") em 75718, query $qid\n";
'
```
Expected: `OK count EN inserido (id b2d868e) em 75718, query <NEW_QID>`

- [ ] **Step 2: Validar inserção + JSON íntegro**

Run:
```bash
docker exec -u www-data concertacao-dev-wordpress wp eval '
$d=json_decode(get_post_meta(75718,"_elementor_data",true),true);
echo "json_valid=".(is_array($d)?"SIM":"NAO")."\n";
$f=null;$w=function($els)use(&$w,&$f){foreach($els as $el){if(($el["id"]??"")==="b2d868e")$f=$el;if(!empty($el["elements"]))$w($el["elements"]);}};
$w($d);
if(!$f){echo "ERRO: widget novo nao encontrado\n";exit;}
preg_match("/settings=\"([^\"]+)\"/",$f["settings"]["__dynamic__"]["title"],$m);
echo "decoded=".rawurldecode($m[1])."\n";
'
```
Expected: `json_valid=SIM` e `decoded={"query_id":"<NEW_QID>","custom_format":"Showing [end-item] of %total% registered platforms","count_type":"custom_format"}`

- [ ] **Step 3: Limpar cache + flush**

Run:
```bash
docker exec -u www-data concertacao-dev-wordpress wp eval 'delete_post_meta(75718,"_elementor_element_cache"); echo "ok\n";'
/Users/dcambria/scripts/server-tools/v2/docker-dev/common/bin/docker-dev.sh cache-flush
```
Expected: `ok` + flush.

- [ ] **Step 4: Verificação no browser (EN)**

Abrir a versão EN (via WPML switcher na página, ou `.../en/...` se aplicável). Confirmar a URL EN real do "Platform Map".
Expected: aparece **"Showing 12 of 60 registered platforms"**; com busca de poucos resultados, mostra o número real (não 12).

---

## Task 5: Corrigir a Espiral (PT 26826 + EN 79123) — `custom_query=yes` no listing + traduzir EN

> **CORREÇÃO REAL (validada em dev 2026-06-08):** o contador da Espiral congela em
> "12" no filtro pela MESMA causa da Mapa — o listing `1a6ba01` tinha só
> `custom_query_id=12` SEM `custom_query=yes`, então renderizava pela fonte interna
> e o count não atualizava no AJAX. **O fix decisivo é setar `custom_query=yes` no
> listing `1a6ba01` (PT e EN)** — comprovado: busca "adolesc" (2 resultados) saiu
> de "12 de 421" travado para "2 de 2" correto, inclusive no widget `%visible%`.
> A troca `%visible%`→`[end-item]` é cleanup cosmético (ambas as macros dão o mesmo
> valor correto quando os fragments atualizam). Tradução EN segue necessária.

Passos: (1) `custom_query=yes` no `1a6ba01` em PT+EN; (2) [cosmético] trocar
`bb87a69` `%visible%`→`[end-item]` em PT; (3) traduzir `bb87a69`+`0781799` no EN.

**Files:** Modify (banco) meta `_elementor_data` de `26826` e `79123`.

- [ ] **Step 0: Fix decisivo — `custom_query=yes` no listing 1a6ba01 (PT + EN)**

Run:
```bash
docker exec -u www-data concertacao-dev-wordpress wp eval '
foreach([26826,79123] as $pid){
  $d=json_decode(get_post_meta($pid,"_elementor_data",true),true);
  $chg=false;$w=function(&$els)use(&$w,&$chg){foreach($els as &$el){if(($el["id"]??"")==="1a6ba01"){$el["settings"]["custom_query"]="yes";$el["settings"]["custom_query_id"]="12";$chg=true;}if(!empty($el["elements"]))$w($el["elements"]);}};
  $w($d);
  $enc=wp_json_encode($d); if(json_decode($enc)===null){echo "ERRO encode $pid\n";continue;}
  update_post_meta($pid,"_elementor_data",wp_slash($enc));
  delete_post_meta($pid,"_elementor_element_cache");
  echo $chg?"OK $pid listing custom_query=yes\n":"AVISO 1a6ba01 nao achado em $pid\n";
}
'
```
Expected: `OK 26826 ...` e `OK 79123 ...`.

- [ ] **Step 1: Backup fresco**

Run:
```bash
cd /Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao/tmp/mapa-plataformas-fix
for ID in 26826 79123; do docker exec -u www-data concertacao-dev-wordpress wp post meta get $ID _elementor_data --format=json > pre_task5_$ID.json; done
echo "backups task5 ok"
```
Expected: dois arquivos `pre_task5_*.json`.

- [ ] **Step 2: PT Espiral (26826) — trocar `bb87a69` para `[end-item]`**

Run:
```bash
docker exec -u www-data concertacao-dev-wordpress wp eval '
$pid=26826;
$fmt="Mostrando [end-item] de %total% estudos cadastrados";
$settings=["query_id"=>"12","custom_format"=>$fmt,"count_type"=>"custom_format"];
$tag="[elementor-tag id=\"94acaed\" name=\"jet-query-count\" settings=\"".rawurlencode(wp_json_encode($settings))."\"]";
$d=json_decode(get_post_meta($pid,"_elementor_data",true),true);
$chg=false;$w=function(&$els)use(&$w,$tag,&$chg){foreach($els as &$el){if(($el["id"]??"")==="bb87a69"){$el["settings"]["__dynamic__"]["title"]=$tag;$chg=true;}if(!empty($el["elements"]))$w($el["elements"]);}};
$w($d);
if(!$chg){echo "AVISO bb87a69 nao achado em $pid\n";exit;}
$enc=wp_json_encode($d);if(json_decode($enc)===null){echo "ERRO encode\n";exit;}
update_post_meta($pid,"_elementor_data",wp_slash($enc));
echo "OK Espiral PT bb87a69 -> [end-item]\n";
'
```
Expected: `OK Espiral PT bb87a69 -> [end-item]`

- [ ] **Step 3: EN Espiral (79123) — `bb87a69` e `0781799` em inglês com `[end-item]`**

Run:
```bash
docker exec -u www-data concertacao-dev-wordpress wp eval '
$pid=79123;
$fmt="Showing [end-item] of a total of %total% registered studies.";
$settings=["query_id"=>"12","custom_format"=>$fmt,"count_type"=>"custom_format"];
$tag="[elementor-tag id=\"94acaed\" name=\"jet-query-count\" settings=\"".rawurlencode(wp_json_encode($settings))."\"]";
$d=json_decode(get_post_meta($pid,"_elementor_data",true),true);
$n=0;$w=function(&$els)use(&$w,$tag,&$n){foreach($els as &$el){if(in_array(($el["id"]??""),["bb87a69","0781799"])){$el["settings"]["__dynamic__"]["title"]=$tag;$n++;}if(!empty($el["elements"]))$w($el["elements"]);}};
$w($d);
echo "widgets atualizados: $n\n";
$enc=wp_json_encode($d);if(json_decode($enc)===null){echo "ERRO encode\n";exit;}
update_post_meta($pid,"_elementor_data",wp_slash($enc));
echo "OK Espiral EN traduzido + [end-item]\n";
'
```
Expected: `widgets atualizados: 2` + `OK Espiral EN traduzido + [end-item]`

- [ ] **Step 4: Validar JSON íntegro das duas páginas**

Run:
```bash
docker exec -u www-data concertacao-dev-wordpress wp eval '
foreach([26826,79123] as $pid){ $d=json_decode(get_post_meta($pid,"_elementor_data",true),true); echo "[$pid] json_valid=".(is_array($d)?"SIM":"NAO")."\n"; }
'
```
Expected: ambas `json_valid=SIM`.

- [ ] **Step 5: Limpar cache + flush**

Run:
```bash
docker exec -u www-data concertacao-dev-wordpress wp eval 'foreach([26826,79123] as $p){delete_post_meta($p,"_elementor_element_cache");} echo "ok\n";'
/Users/dcambria/scripts/server-tools/v2/docker-dev/common/bin/docker-dev.sh cache-flush
```
Expected: `ok` + flush.

- [ ] **Step 6: Verificação no browser (Espiral PT + EN)**

Abrir `https://cambrasmax.local:8484/conhecimento/espiral-de-conhecimento/` (PT) e a versão EN.
Expected:
- PT: "Mostrando 12 de 421 estudos cadastrados"; com filtro retornando <12, número real (não 12).
- EN: texto em inglês "Showing 12 of a total of 421 registered studies."; <12 → número real.

---

## Task 6: Deploy em produção (duas etapas — ID da query é por-ambiente)

> **IMPORTANTE:** a query JetEngine é por-ambiente. O `<NEW_QID>` de prod pode diferir do de dev. Por isso: (a) criar a query em PROD e capturar o ID real `<PROD_QID>`; (b) gravar os `_elementor_data` de PROD usando `<PROD_QID>`. **Não** assumir que o ID de dev vale em prod.

**Pré-requisito:** confirmar que os IDs de página/widget em prod são os mesmos (mesma base). Validar via SSH antes.

- [ ] **Step 1: Validar IDs em prod**

Run:
```bash
ssh concertacaoamazonia.com.br-prod-sa "sudo -u www-data wp --path=/var/www/concertacaoamazonia.com.br post list --post_type=plataformas --post_status=publish --format=count"
ssh concertacaoamazonia.com.br-prod-sa "sudo -u www-data wp --path=/var/www/concertacaoamazonia.com.br post get 26827 --field=post_title"
```
Expected: contagem de plataformas (pode diferir de 60), e título `Mapa de Plataformas`. Se o título/ID divergir, **PARAR** e reavaliar (IDs podem ter drift — checar memória `project_concertacao_prod_instance_id_drift`).

- [ ] **Step 2: Criar a query "Query Plataformas" em PROD**

Como o MCP aponta para dev, criar em prod via JetEngine admin (UI) **ou** replicar a definição via WP-CLI/option. Caminho recomendado: admin JetEngine → Query Builder → Add New, mesmos args da Task 1 (post_type=plataformas, publish, posts_per_page=12, orderby title ASC, **cache desligado**).

Capturar o ID:
```bash
ssh concertacaoamazonia.com.br-prod-sa "sudo -u www-data wp --path=/var/www/concertacaoamazonia.com.br eval '
\$m=\\Jet_Engine\\Query_Builder\\Manager::instance();
foreach(\$m->get_queries() as \$id=>\$q){ if(\$q->name===\"Query Plataformas\") echo \"PROD_QID=\".\$id.\"\\n\"; }
'"
```
Expected: `PROD_QID=<número>`. **Guardar `<PROD_QID>`.**

- [ ] **Step 3: Aplicar TODAS as edições de `_elementor_data` em prod com `<PROD_QID>`**

Reexecutar os scripts das Tasks 2 (com `custom_query=yes`), 3, 4 e 5 (incl.
**Step 0 = `custom_query=yes` no listing Espiral `1a6ba01`**) **via SSH em prod**, substituindo:
- `docker exec -u www-data concertacao-dev-wordpress wp` → `ssh concertacaoamazonia.com.br-prod-sa "sudo -u www-data wp --path=/var/www/concertacaoamazonia.com.br ..."`
- `<NEW_QID>` → `<PROD_QID>`

> **Não esquecer:** o fix que destrava o contador é `custom_query=yes` nos listings
> `23d592f` (Mapa) e `1a6ba01` (Espiral). Sem isso, o count fica congelado em prod
> igual ao bug original. Validar AO VIVO em prod com busca <12 (Tasks 3b/EN).

> Fazer **backup prod** de cada meta antes (`wp post meta get <ID> _elementor_data --format=json > /tmp/prod_pre_<ID>.json`).

- [ ] **Step 4: Regen Elementor CSS por página (PROD — NÃO usar clear_cache global)**

Run:
```bash
ssh concertacaoamazonia.com.br-prod-sa "sudo -u www-data wp --path=/var/www/concertacaoamazonia.com.br eval '
foreach([26827,75718,26826,79123] as \$pid){ (new \\Elementor\\Core\\Files\\CSS\\Post(\$pid))->update(); delete_post_meta(\$pid,\"_elementor_element_cache\"); echo \"regen \$pid\\n\"; }
'"
```
Expected: `regen 26827` … `regen 79123`.

- [ ] **Step 5: WP Rocket cirúrgico + CloudFront das 4 páginas**

Run (paths das 4 páginas):
```bash
cd /Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao
/Users/dcambria/scripts/server-tools/v2/docker-dev/common/bin/docker-dev.sh cache-flush --prod /conhecimento/mapa-das-plataformas/
/Users/dcambria/scripts/server-tools/v2/docker-dev/common/bin/docker-dev.sh cache-flush --prod /conhecimento/espiral-de-conhecimento/
# repetir para os paths EN reais (confirmar slugs EN em prod)
```
Expected: invalidações concluídas sem erro.

- [ ] **Step 6: Validação prod (browser/curl) das 4 páginas**

Abrir as 4 URLs de prod (PT+EN de Mapa e Espiral). Confirmar:
- Mapa conta plataformas, não estudos.
- "<12" some nas 4.
- Sem regressão de layout.

---

## Task 7: Validação final e limpeza

- [ ] **Step 1: Rodar /smoke (se aplicável a estas páginas)**

Invocar `/smoke` para checar regressões gerais no Concertação pós-deploy.
Expected: gates relevantes passam (atenção a gates de imagem/CSS/listing).

- [ ] **Step 2: Limpar artefatos temporários**

Run:
```bash
rm -rf /Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao/tmp/mapa-plataformas-fix
echo "tmp limpo"
```
Expected: `tmp limpo`. (Snapshots prod em `/tmp` do servidor: remover via SSH se desejado.)

- [ ] **Step 3: Atualizar memória do projeto**

Registrar em memória: fix do count Mapa de Plataformas (query nova `<PROD_QID>`, padrão `[end-item]`, bug `%visible%` na Espiral) — para reproduzir/entender em futuras tarefas. Linkar a `[[feedback_jsf_offset_breaks_pagination]]`.

---

## Self-Review (preenchido pelo autor do plano)

- **Cobertura da spec:** query nova (T1), relink listing PT+EN (T2), count PT (T3), count EN novo (T4), Espiral PT+EN (T5), deploy prod 2-etapas (T6), validação (T7). ✅ Todas as seções da spec têm task.
- **Placeholders:** `<NEW_QID>`/`<PROD_QID>` são valores capturados em runtime, documentados explicitamente — não são placeholders de conteúdo faltante. Scripts completos em cada step. ✅
- **Consistência de IDs/nomes:** widget `23d592f` (listing), `b2d868d` (count PT), `b2d868e` (count EN novo), `bb87a69`/`0781799` (Espiral). Macro `[end-item]` consistente. Query `Query Plataformas`. ✅
