# Mapa de Plataformas — Query própria + correção do contador "Mostrando X de Y"

**Data:** 2026-06-08
**Site:** Concertação Amazônica (blog 1, raiz)
**Autor:** Daniel Cambría

## Problema

A página **Mapa de Plataformas** (`/conhecimento/mapa-das-plataformas/`, PT post `26827`,
EN "Platform Map" post `75718`) recebeu, por copy-paste da página **Espiral de
Conhecimento**, um widget de contagem ("Mostrando X de Y") cujo dynamic tag
`jet-query-count` está cravado em **`query_id="12"`** — a query *"Objetos para
Espiral"* (CPT `objetos`+`estudos`, 421 itens). Resultado: a mensagem conta
**estudos**, não **plataformas**, e fala "estudos cadastrados".

Além disso, há um **bug herdado**: para qualquer busca cujo resultado seja
**menor que 12**, a mensagem continua dizendo "Mostrando **12**...".

### Diagnóstico (verificado no dev)

Padrão de wiring na **Espiral** (referência que funciona):

| Componente | Valor | Papel |
|---|---|---|
| Listing grid `1a6ba01` | `_element_id=estudos`, `custom_query_id=12` | renderiza via Query Builder query 12 |
| Filtros JSF | `query_id=estudos` | apontam para o `_element_id` do listing (provider) |
| Count `0781799` (corrigido) | `query_id=12`, `[end-item]` | conta via query 12, robusto |
| Count `bb87a69` (bugado) | `query_id=12`, `%visible%` | conta via query 12, mas `%visible%` congela |

Estado atual da **Mapa de Plataformas (PT 26827)**:

| Componente | Valor | Status |
|---|---|---|
| Listing grid `23d592f` (listing `14035`) | `_element_id=plataformas-de-pesquisa`, **sem `custom_query_id`** | usa fonte interna do listing (CPT `plataformas`, 60 publicadas) ✅ |
| Filtros JSF (busca/checkbox/active/paginação) | `query_id=plataformas-de-pesquisa` | ✅ já corretos |
| **Count `b2d868d`** | `query_id=12`, `%visible%`, texto "estudos cadastrados" | ❌ aponta para query errada |

Estado atual da **Mapa EN (75718)**: tem o listing `23d592f` igual, mas **NÃO tem
widget de count** (nenhum heading com dynamic tag).

### Causa raiz dos dois sintomas

1. **"Conta estudos, não plataformas"** — o count lê a query 12 (estudos), que
   nenhum filtro da página Mapa dispara.
2. **"<12 mostra 12"** — `jet-query-count` só funciona com **queries do Query
   Builder**. A macro `%visible%` (`Posts_Query::get_items_page_count()` =
   `$wp_query->post_count`) é atualizada no AJAX pelo JS do JetSmartFilters
   **apenas quando o `<span data-query="N">` casa com o provider que o filtro
   dispara**. Como o count aponta para a query 12 (não disparada na Mapa), o
   número fica congelado no valor renderizado no servidor na 1ª página
   (`posts_per_page=12`). A macro **`[end-item]`** (`get_end_item_index_on_page()`)
   limita ao `post_count` real e é a forma robusta — por isso a Espiral já tem
   um widget corrigido (`0781799`) usando `[end-item]`.

O `jet-query-count` exige um `query_id` numérico do Query Builder. O listing de
plataformas hoje **não** usa uma query do Query Builder (usa a fonte interna do
listing `14035`). Logo, para o count refletir os filtros corretamente, é preciso
criar uma query de plataformas e religar listing + count a ela — exatamente o
padrão da Espiral.

## Solução escolhida (Opção A)

Criar uma **query "Query Plataformas"** no JetEngine Query Builder espelhando a
fonte interna do listing `14035`, religar o listing a ela via `custom_query_id`,
e apontar o count para ela usando **`[end-item]`**. Corrigir também a Espiral
(origem do bug `%visible%`). Paridade PT/EN com textos localizados.

### Fonte interna a espelhar (listing 14035)

```
listing_post_type: plataformas
listing_tax: category            (não usada para filtro — CPT plataformas só tem ano, eixos)
```

CPT `plataformas`: **60 publicadas**. Sem orderby explícito no listing → a query
nova usa orderby por título ASC (padrão estável para listagem de plataformas).
Filtro de tema (checkbox `13571`) injeta a query var `categorias-da-plataforma`
sobre a taxonomia `eixos` via glossary; o provider JSF é `plataformas-de-pesquisa`
(= `_element_id` do listing). A query nova deve ser do tipo `posts`,
`post_type=plataformas`, `post_status=publish`, `posts_per_page=12`, sem tax_query
fixa (os filtros injetam dinamicamente, como na query 12).

> **Nota — posts_per_page:** definido em **12** (decisão do Daniel 2026-06-08),
> alinhado à query 12 da Espiral. O widget de listing tinha `posts_num=8`; será
> ajustado para 12 também, ou o `custom_query_id` sobrescreve o `posts_num` do
> widget (a query manda no paging). O `[end-item]` cobre qualquer valor.

## Componentes da mudança

### 1. Nova query JetEngine — "Query Plataformas"

- Tipo: `posts`
- `post_type: ["plataformas"]`, `post_status: ["publish"]`
- `posts_per_page: 12`
- `orderby: title ASC`
- Sem meta/tax query fixa (filtros injetam via provider)
- **Criada via JetEngine** (UI ou MCP `jetengine-mcp tool-add-query`), capturando
  o `id` numérico gerado (chamado `<NEW_QID>` adiante).

### 2. Mapa PT (26827) — religar listing + corrigir count

No `_elementor_data`:

- **Listing `23d592f`**: adicionar `"use_custom_query":"yes"` e
  `"custom_query_id":"<NEW_QID>"`. Manter `_element_id=plataformas-de-pesquisa`.
  Ajustar `posts_num` para `12` (ou deixar a query mandar no paging).
- **Count `b2d868d`**: trocar o dynamic tag para
  `query_id=<NEW_QID>`, `count_type=custom_format`,
  `custom_format = "Mostrando [end-item] de %total% plataformas cadastradas"`.

### 3. Mapa EN (75718) — religar listing + ADICIONAR count

- **Listing `23d592f`**: mesma alteração (`custom_query_id=<NEW_QID>`).
- **Adicionar** um widget heading idêntico ao count PT, posicionado no mesmo
  container de resultados, com dynamic tag:
  `custom_format = "Showing [end-item] of %total% registered platforms"`.

### 4. Espiral PT (26826) — corrigir bug %visible%

- **Remover** o widget bugado `bb87a69` (usa `%visible%`), OU trocá-lo para
  `[end-item]`. Decisão: **trocar `%visible%` → `[end-item]`** no `bb87a69`
  (menos invasivo que remover; mantém qualquer estilo aplicado). O widget
  `0781799` já está correto e fica como está.
  - Após o ajuste, os dois widgets ficam consistentes (ambos `[end-item]`).
    Se houver duplicidade visual, avaliar remoção de um deles — mas isso é
    cosmético e fora do escopo do bug.

### 5. Espiral EN (79123) — corrigir bug + traduzir

- `bb87a69`: `%visible%` → `[end-item]` **e** texto para inglês:
  `"Showing [end-item] of a total of %total% registered studies."`
- `0781799`: traduzir texto para inglês:
  `"Showing [end-item] of a total of %total% registered studies."`

## Fluxo de dados (depois)

```
[Filtros JSF query_id=plataformas-de-pesquisa]
        │ AJAX (provider = _element_id do listing)
        ▼
[Listing 23d592f custom_query_id=<NEW_QID>] ── renderiza CPT plataformas filtrado
        │
        ▼
[Count b2d868d query_id=<NEW_QID> [end-item]] ── JS JSF atualiza <span data-query=<NEW_QID>>
        com o post_count real (≤ total filtrado) → some o "12" fantasma
```

## Gotchas conhecidos (deste repo)

1. **`update_post_meta` de `_elementor_data` EXIGE `wp_slash`** após
   `wp_json_encode`, senão `json_decode` roundtrip vira NULL e a página "perde
   CSS"/quebra. Sempre: decode → modificar array → `wp_json_encode` → `wp_slash`
   → `update_post_meta`. Validar `json_valid` pós-gravação + backup íntegro do
   meta antes de editar. (ver memórias `feedback_elementor_data_update_post_meta_needs_wp_slash`)
2. **`_elementor_element_cache`** guarda HTML renderizado por página e SOBREVIVE
   a flush comum. Após editar, `delete_post_meta(id,'_elementor_element_cache')`
   nas 4 páginas (26827, 75718, 26826, 79123).
3. **Regen Elementor CSS + minify** — após editar `_elementor_data`, regenerar
   o CSS do post (`(new \Elementor\Core\Files\CSS\Post($id))->update()`) e, se
   necessário, remover minify físico. Em **dev**, `opcache.validate_timestamps=1`
   torna isso menos crítico, mas regenerar evita layout stale.
4. **JetEngine query cache + WPML** — se a query nova tiver `cache_query=true`,
   pode servir idioma errado em WPML (chave de cache sem idioma). Criar a query
   com **`cache_query=false`** (ver `feedback_jetengine_query_cache_breaks_wpml_lang`).
5. **Não injetar host de túnel** nem copiar `_elementor_data` inteiro entre
   idiomas — aplicar só o DELTA por ID de widget.

## Aplicação e ambiente

- **Tudo primeiro em DEV** (`cambrasmax.local:8484` PT, `.../en/` EN).
- **Validação dev:** abrir as 4 páginas, conferir contagem com 0/1/2/12/60
  resultados (busca por termo raro p/ <12), confirmar que "<12" some.
- **Deploy prod:** o CPT `plataformas` e os IDs de página/listing/widget são os
  mesmos em prod (mesma base). A query nova precisa existir em prod com o
  **mesmo `<NEW_QID>`** — JetEngine queries são por-ambiente. Estratégia:
  - Criar a query em dev, anotar o JSON da definição, recriar em prod via MCP/UI
    (o `id` pode diferir entre ambientes). **Os `custom_query_id`/`query_id` nos
    `_elementor_data` precisam usar o ID de prod.** → Por isso o deploy é em duas
    etapas: (a) criar query em prod e capturar o ID real; (b) gravar os
    `_elementor_data` de prod com esse ID.
  - Após gravar em prod: `delete_post_meta _elementor_element_cache` + regen CSS
    + `wp rocket clean` cirúrgico das 4 páginas + CloudFront invalidate dos paths.
- **mu-plugin?** Não. Solução 100% data-driven (query + `_elementor_data`), sem
  código novo. Segue o padrão de `feedback_jsf_offset_breaks_pagination`
  (fix nativo sem mu-plugin).

## Critérios de sucesso

1. Mapa PT: "Mostrando N de 60 plataformas cadastradas", N = itens reais na
   página (≤8 antes do Load More; cap correto quando filtro retorna <8).
2. Mapa EN: "Showing N of M registered platforms" (count novo, localizado).
3. Busca com 2 resultados → mostra "2", nunca "12"/"8".
4. Filtros (checkbox temas, busca, paginação, Load More) continuam funcionando
   na Mapa (provider `plataformas-de-pesquisa` intacto).
5. Espiral PT/EN: contador correto com `[end-item]`, EN localizado.
6. Sem regressão de layout/CSS (validar via browser + `/smoke` se aplicável).

## Fora de escopo

- Redesenho visual do widget de contagem.
- Tradução de outros textos das páginas além do count.
- Remoção do widget duplicado na Espiral (cosmético).
