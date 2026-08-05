# Plano — Stage 0 (plumbing multisite) da feature render-deps + testes na concertacao

**Site fixture:** concertacao (multisite real, container UP)
**Data:** 2026-07-18 · **Revisado:** 2026-07-18 (re-ancorado pós-`fab6184c7`)
**Todos os anchors abaixo re-verificados por `grep -n` contra o código pós-commit `fab6184c7` (HEAD de origin/main).**

---

## Context

A feature "deploy página-completa / render-deps" (Stages 1-3) está mergeada em `origin/main`
(`fab6184c7`) e validada E2E no mombak. Mas o mombak é single-site: nunca exercita o caminho
`--url`/`wp_N_`. O **Stage 0 — propagar `--url` por toda a cadeia export→import para funcionar
em multisite — está PENDENTE.** Hoje há só scaffolding inerte: o resolver
(`posts_resolve_page_render_deps`, `helpers/posts-helper.sh:492`) **já** aceita 2º arg `wp_url`
e passa `--url` ao `wp eval` (linhas 502-521), mas o orquestrador (`run_render_deps_resolver`,
`deploy-content.sh:314`, call @363) o invoca **sem** URL, e `posts_run_wp`
(`posts-helper.sh:246-257`) **não** injeta `--url`.

Este plano tem 2 fases: **(A) implementar o Stage 0** e **(B) validá-lo na concertacao** —
o único fixture multisite real. Ordem obrigatória: gate read-only → implementar → testar →
HML (nunca prod no 1º teste real).

> **Nota de revisão:** a versão original deste plano herdou line numbers do design doc
> (`structured-doodling-graham.md`), escrito ANTES de `fab6184c7` (+483 linhas em
> posts-helper.sh). Todos deslizaram 100-250 linhas, e 2 "flags a editar" (`--url` em
> export_posts.sh e import_posts.sh) **não existem** — são implementação nova. Esta versão
> está re-ancorada por `grep -n`.

---

## Caminhos reais dos arquivos (CRÍTICO — não confundir com cópias)

| Papel | Caminho REAL |
|-------|--------------|
| Resolver + `posts_run_wp` + prefixos | `helpers/posts-helper.sh` |
| Export de posts/CPT | `docker-dev/common/scripts/export_posts.sh` |
| Import (destino) | `docker-dev/common/scripts/import_posts.sh` |
| Orquestração 1-clique | `docker-dev/common/scripts/deploy-content.sh` |
| Referência multisite (COPIAR, não editar) | `docker-dev/common/scripts/export-db.sh` |
| Ponto de entrada `share deploy` | `docker-dev/common/bin/docker-dev.sh` |

⚠️ Existem cópias em `ec2-deploy/post-deploy/bin/` e um bundle **stale** em
`docker-dev/sites/totem-concertacao/tmp/`. Editar a cópia errada quebra silenciosamente.
Editar **sempre** os de `docker-dev/common/scripts/` e `helpers/`.

---

## FASE A — Implementação do Stage 0 (anchors re-verificados)

### A1. `posts_run_wp` classe-consciente — `helpers/posts-helper.sh:246-257`
Injetar `--url="$POSTS_WP_URL"` **só** quando o 1º arg é subcomando **blog-context**
(`post`, `eval`, `export`, `import`, `option`, `rewrite`, `media`); **NUNCA** em `db`/`site`
(esses usam nome de tabela literal e ignoram `--url`). `POSTS_WP_URL` não existe hoje no
arquivo — é variável nova, default vazio (single-site inalterado).

### A2. Parametrizar tabelas hardcoded → `${table_prefix}` — `helpers/posts-helper.sh`
Ocorrências REAIS de `wp_posts`/`wp_postmeta` hardcoded (não 1112/1214 como dizia o doc antigo):
- **1333-1336** (`FROM wp_posts p` + JOINs, whitelist `post_type IN ('post','page','tribe_events')`)
- **1444** (`SELECT ID FROM wp_posts ... IN ('post','page','tribe_events')`)
- **1463** (`... post_type='attachment'`)
- **1542-1546** (JOINs + whitelist)
- **1854-1857** (`UPDATE wp_postmeta` + JOINs, remap TEC)

Resolver o prefixo via `wp eval --url=X 'global $wpdb; echo $wpdb->prefix;'` (padrão
`export-db.sh:340`), **NUNCA** `wp db prefix --url` (pode retornar `wp_` em vez de `wp_2_` →
tabela errada → export vazio silencioso — bug pego no ciclo 3 da revisão). Relaxar/parametrizar
a whitelist `post_type IN (...)` em **1336, 1444, 1546** para não descartar CPTs do blog 2.
> Nota: várias queries já usam `${table_prefix}` (ex: 2390, 2839, 2900-2916) — o hardcoding é
> inconsistente, não universal. Parametrizar só as 5 acima.

### A3. Reconciliar com o tunnel-swap EXISTENTE — `export_posts.sh:159/186`
`_tunnel_restore` (@159) e `_apply_tunnel_url` (@186, ~159-230) **já** trocam siteurl por
subsite (via `_SUBSITE_BLOG_IDS` / `wp_${bid}_options`), acionados por `--tunnel-url=`
(parse @742, call @960). O `--url` novo **não pode** fazer um 2º swap de siteurl concorrente.
**Decidir estratégia única ANTES de B3:** o `--url` do Stage 0 só troca o *contexto de blog*
do WP-CLI (prefixo/queries); o swap de siteurl continua exclusivo do `_apply_tunnel_url`.
Documentar essa fronteira no header do export_posts.sh.

### A4. Flag `--url=` NOVA (não existe hoje) + guard multisite
- **`export_posts.sh`** — arg-parse @665-**~760** (`--tunnel-url=` @742 é o vizinho). Adicionar
  `--url=*)` → `SUBSITE_URL`, propagado como `POSTS_WP_URL` às chamadas `posts_run_wp`.
- **`import_posts.sh`** — arg-parse termina ~219 (hoje **zero** ocorrências de `url`).
  Adicionar `--url=*)` → propaga `POSTS_WP_URL` ao import no destino.
- **`deploy-content.sh`** — arg-parse `case` @194-207 (options declaradas @180-187). Adicionar
  `--url=*) OPT_URL="${1#--url=}"; shift ;;`. Passar `OPT_URL` ao resolver e a cada export.
- **Guard em CADA entry point:** chamar `_validate_multisite` (`export-db.sh:311-326`, die @317)
  antes de aceitar `--url` — rejeitar `--url` em site single-site com mensagem clara.

### A5. Ligar a URL ao resolver — `deploy-content.sh:314-321` + call @363
`run_render_deps_resolver` hoje chama `posts_resolve_page_render_deps '"$page_id"'` (sem 2º arg;
comentário @316-317 já prevê isso). Passar `$OPT_URL` como 2º arg quando não-vazio. Propagar
`OPT_URL` a `resolve_path_to_postid` (@284 — hoje usa `home_url()` do blog atual) e a cada
`export-posts` da cadeia (dry-run em 473-490, deploy em ~719).

### A6. Pass-through em `docker-dev.sh` — **já resolvido pela arquitetura**
No branch `share deploy --path=` (@3868-3897), o docker-dev.sh repassa **TODOS** os args
verbatim (`"$@"`) ao `deploy-content.sh` via `env` (@3889-3895). Logo, `--url=` chega sozinho
ao deploy-content — **nenhum código novo de pass-through é necessário** para o caminho `--path`.
(O `--url=` @3286 do docker-dev.sh pertence ao `export-elementor`, função DIFERENTE — não
reusar/confundir.) Só validar que o `--url` não é confundido com `--path`/`--period`
(mutuamente exclusivos @3874-3878).

> Cada edição em `posts-helper.sh` dispara `/keymaker` (regra do CLAUDE.md). `/seubarriga` na
> regressão single-site.

---

## FASE B — Bateria de testes (concertacao, container `concertacao-dev-wordpress` UP)

### B0 — GATE crítico (read-only, antes de tudo)
Prova que `wp eval --url` retorna o prefixo do subsite. Se falhar, redesenhar A2.
```bash
docker exec -u www-data concertacao-dev-wordpress \
  wp eval 'global $wpdb; echo $wpdb->prefix;' --url=https://cambrasmax.local:8484/cultura/
# ESPERADO: wp_2_   (se sair wp_ → NUNCA usar db prefix; só este wp eval na resolução)
```

### B1 — Resolver contra o subsite (read-only) — prova o isolamento entre blogs
Página do blog 2 com jet-listing-grid (ex: 57548 atlas ou 13619 cultura):
```bash
docker exec -u www-data -e POSTS_WP_ROOT=/var/www/html concertacao-dev-wordpress bash -c '
  source /usr/local/bin/helpers/posts-helper.sh 2>/dev/null
  posts_resolve_page_render_deps <PAGE_ID_BLOG2> "https://cambrasmax.local:8484/cultura/"
' | python3 -m json.tool
```
**Asserções:** IDs em `records_by_type` existem em `wp_2_posts` (não `wp_posts`);
`listing_template_ids` são templates jet-engine do blog 2; `attachment_ids` resolvem contra
`wp_2_posts`; determinístico (2× idêntico).

### B2 — GATE crítico: degradação CCT (read-only)
A CCT `wp_jet_cct_participantes_cct` (1287 registros, verificado) é o caso de degradação segura.
Achar página do blog 2 que renderiza `participantes_cct`:
```bash
docker exec -u www-data concertacao-dev-wordpress wp db query \
  "SELECT p.ID,p.post_name FROM wp_2_posts p JOIN wp_2_postmeta m ON m.post_id=p.ID \
   AND m.meta_key='_elementor_data' AND m.meta_value LIKE '%participantes%' \
   WHERE p.post_type='page' LIMIT 5" --skip-column-names
# rodar o resolver nessa página (com --url de /cultura/)
```
**Asserções:** `unsupported_sources` NÃO vazio; `jetengine_active: true`; JSON válido + exit 0
(NÃO quebra); nenhum ID de CCT em `records_by_type` (WXR não exporta `wp_jet_cct_*`).

### B3 — Export com `--url` (escreve XML local, não toca prod)
```bash
/Users/dcambria/scripts/server-tools/v2/docker-dev/common/bin/docker-dev.sh export-posts \
  --url=https://cambrasmax.local:8484/cultura/ \
  --post-type=linha-das-artes --post-ids=<IDs> --no-upload-s3
```
**Asserções:** IDs exportados vêm de `wp_2_posts`; `_validate_multisite` aceitou (site É
multisite); SEM `--url` num multisite → default blog 1 inalterado; `--url` p/ subsite
inexistente → erro claro (não export vazio); **sem swap duplo de siteurl** (checar A3).

### B4 — Guard de não-multisite (regressão, no mombak single-site)
```bash
cd .../sites/mombak && std export-posts --url=https://qualquer/ --post-ids=4597
# ESPERADO: erro "--url só em multisite" (via _validate_multisite / export-db.sh:317)
```

### B5 — Dry-run do deploy página-completa no subsite
```bash
cd .../sites/concertacao
std share deploy --path=/cultura/<slug>/ --environment=hml \
  --url=https://cambrasmax.local:8484/cultura/ --dry-run
```
**Asserções:** plano lista CPTs do blog 2, ordem anexos→templates→CPTs→página; nenhum registro
do blog 1; CCT (se houver) aparece "IGNORADO". (Confirma que `--url` fluiu docker-dev →
deploy-content → export, per A6.)

### B6 — Deploy REAL para HML + verificação visual (só após B0-B5 verdes)
- **Confirmar ANTES:** `--environment=hml` resolve para uma instância de TESTE, não o site vivo.
  (A concertacao é site vivo; o host v2 ativo é rotulado `prod-sa` — validar o mapeamento de HML
  no deploy-content/transporte antes de qualquer escrita.)
- Deploy de 1 página do blog 2 → HML.
- **Verificação visual dev↔hml no browser obrigatória** (Playwright: contar widgets/links do
  listing) — lição do mombak: "verde nos logs ≠ render correto".
- Confirmar: registros chegaram ao blog correto (prefixo certo), anexos servíveis, URLs
  reescritas para o FQDN de hml.

---

## Riscos específicos deste fixture

1. **Swap duplo de siteurl** (`--url` novo × `_apply_tunnel_url` @186) — maior risco; A3 resolve,
   B3 valida.
2. **CCT** — resolver tentar exportar CCT = falha silenciosa; B2 é o gate.
3. **`--environment=hml` na concertacao** — confirmar que não resolve para o site vivo (B6).
4. **WPML `language_negotiation_type` difere dev/prod** (CLAUDE.md) — não confundir com bug do
   render-deps.
5. **`db prefix --url` retorna `wp_`** em algumas versões WP-CLI — por isso A2 usa `wp eval`.

---

## Ordem de execução

```
B0 (gate wp_2_)  →  [FASE A: A1→A6]  →  /keymaker  →  B1, B2 (read-only, gate B2)
  →  B3, B4 (export/guard)  →  B5 (dry-run)  →  B6 (HML + visual)  →  /seubarriga (regressão)
```
B0 e B2 são gates: se falharem, redesenho antes de qualquer escrita.

---

## Verificação end-to-end (resumo dos critérios de pronto)
- B0 retorna `wp_2_`.
- B1 resolve deps 100% do blog 2 (nenhum ID do blog 1), determinístico.
- B2 degrada CCT sem quebrar.
- B3 exporta de `wp_2_posts`, sem swap duplo, guard multisite ativo.
- B4 rejeita `--url` no mombak.
- B5 dry-run mostra a cadeia completa isolada no blog 2.
- B6 render correto em HML confirmado no browser.
- Regressão single-site (mombak, `--no-render-deps` + `estudos`) idêntica ao pré-mudança.

---

## Referências
- Feature base: `origin/main` `fab6184c7` (é o HEAD).
- Design + 3 ciclos de revisão: `/Users/dcambria/.claude/plans/structured-doodling-graham.md`
  (PRÉ-merge — fonte dos line numbers stale; usar só para o *algoritmo*, não para linhas).
- Gotchas do deploy real no mombak:
  `/Users/dcambria/.claude/projects/-Users-dcambria-scripts-server-tools-v2-docker-dev-sites-mombak/memory/deploy-pagina-completa-render-deps.md`
- Referência multisite blog_id/prefixo (copiar): `export-db.sh:309-349`.
