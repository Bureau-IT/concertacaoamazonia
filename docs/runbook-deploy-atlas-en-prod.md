# Runbook — Deploy Atlas Cultural (PT+EN) DEV → PROD

**Data:** 2026-05-29 · **Autor:** Daniel Cambría · **Ambiente alvo:** `concertacaoamazonia.com.br` (prod, Aurora RDS, CloudFront `E2F1QD7E7YOYEB`, WP Rocket)

## Contexto

Todo o trabalho do Atlas foi feito e validado no DEV (`cambrasmax.local:8484`). PROD está no estado **original** (antes do trabalho). Este runbook replica em prod, faseado, com backup e validação entre fases.

**Delta dev→prod (confirmado por inspeção):**

| Item | DEV | PROD | Ação |
|------|-----|------|------|
| Artistas EN (WPML) | 656 | 1 (lixo trash 92003) | criar 656 |
| Metafield `pais` | 645 | 0 | popular (já vem nos posts via dado) |
| Filtros país JSF | 92323/92324 | nenhum | criar |
| Glossários país | 71 (17), 72 (17), Área EN 73 (15) | ausentes | criar |
| Glossário Cidades 67 | 157 itens (typo II) | 121 itens (typo `ll`) | atualizar |
| Glossário Estados 68 | 19 | 11 | atualizar |
| Taxonomia `eixos` translatable | sim (33 termos EN) | não (sync=0) | tornar + traduzir |
| Coordenadas (Fermat) | regeneradas | dispersão antiga | regenerar |
| `_elementor_data` Atlas (PT 57548 / EN 72730) | labels, ordem, snap-mobile, cor `[value=]`, título EN, `_element_id=map-lista`, `posts_num=700`, JEDV `+` | original | aplicar fixes |
| Listing EN do card (92987) + swap widget 4fee551 EN | criado | ausente | criar/apontar |
| `plus-black.svg` higienizado | sim | ids duplicados | higienizar |

**IDs estruturais batem dev↔prod** (verificado): páginas 57548/72730, listings 15372/18139/27183, filtros 89879/89880/89881, artistas PT 657 (mesmos IDs). **MAS** IDs NOVOS criados em prod (posts EN, glossários 71/72/73, filtros país, listing 92987) podem diferir dos do dev — capturar os IDs gerados em prod e usar nos passos seguintes.

**Scripts reutilizáveis** (em `sites/concertacao/scripts/`, idempotentes, dry-run/APPLY): `01-eixos-translate.php`, `02-create-en-artistas.php`, `03-link-en-terms.php`, `04-create-en-tax-filters.php`, `05-fermat-coords.php`. Rodam em prod via `scp` + `wp eval-file` com `--path=/var/www/concertacaoamazonia.com.br --url=https://concertacaoamazonia.com.br/cultura/`.

## Pré-requisitos
- SSH `concertacaoamazonia.com.br-prod-sa` ativo (NordVPN BR/IT range).
- mu-plugin `bit-crossblog-attachment-fix.php` ≥ 1.6.0 em prod (validar).
- WPML media duplication OFF nos 2 blogs em prod (validar — evita órfãos).
- Confirmar que o `pais` por artista será populado: o dado vem do CSV (5) reconciliado no dev. Para prod, **popular via o mesmo mapa de reconciliação** (artista→pais), não copiar do dev.

## FASE 0 — Backup + baseline (OBRIGATÓRIO)
```bash
ssh concertacaoamazonia.com.br-prod-sa "sudo -u www-data mysqldump --single-transaction \
  -h <AURORA_ENDPOINT> -u concertacao-v2 -p<...> wp_concertacao_20250316 \
  wp_2_posts wp_2_postmeta wp_2_terms wp_2_term_taxonomy wp_2_term_relationships \
  wp_2_icl_translations wp_2_jet_post_types wp_2_options | gzip > ~/backups/atlas-en-pre-deploy-$(date +%Y%m%d-%H%M%S).sql.gz"
```
- Baseline: contagem artistas PT, markers do mapa, opções dos filtros, `cf-cache-status` da home.
- **Aurora:** charset/collation/sql_mode podem diferir do MySQL local — por isso fasear e validar.

## FASE 1 — Glossários país (71/72) + Área EN (73) + atualizar Cidades(67)/Estados(68)
- Recriar em prod os glossários JetEngine com os MESMOS itens do dev (exportar do dev via `jet_engine()->glossaries->settings->get()` → JSON → inserir em prod em `wp_2_jet_post_types` + `jet_engine_glossaries_orders`).
- **Capturar os IDs gerados em prod.** Se prod aceitar IDs 71/72/73 (livres lá), ótimo (batem com dev). Senão, remapear nos passos 4 e nos `_elementor_data`.
- Atualizar glossário 67 (Cidades → 157 itens, typo `II`, ordenado) e 68 (Estados → 19, ordenado, sem "Peru (País)"), Área 69 (ordenado).
- País 71 ordenado com **Brasil 1º**; 72 idem **Brazil 1º**.
- **Validar:** `jet_engine()->glossaries->settings->get()` em prod lista 71/72/73 com contagens corretas.

## FASE 2 — eixos translatable + traduzir 33 termos (`01-eixos-translate.php`)
```bash
scp scripts/01-eixos-translate.php concertacaoamazonia.com.br-prod-sa:/tmp/
# dry-run
ssh prod "sudo -u www-data wp --path=... --url=.../cultura/ eval-file /tmp/01-eixos-translate.php"
# apply
ssh prod "sudo -u www-data APPLY=1 wp ... eval-file /tmp/01-eixos-translate.php"
```
- **Validar:** 33 termos EN criados, counts PT intactos.

## FASE 3 — Popular `pais` + reconciliar cidade/estado dos artistas PT
- Aplicar o mapa de reconciliação CSV(5)→artista (mesmo do dev): `pais` em 645, correções de cidade/estado (31 artistas), esvaziar `estado` dos 35 internacionais.
- **Validar:** distribuição de `pais` e contagem batem com o dev.

## FASE 4 — Criar 656 artistas EN (`02-create-en-artistas.php`)
- Gerar `/tmp/artista_en_map.json` (mesmo do dev: descrição EN do CSV por nome). `scp` para prod.
- Rodar em lotes (OFFSET/BATCH) com dry-run primeiro.
- **Validar:** 656 EN criados, `coordenada`/`tema`(PT) copiados, vínculo WPML por trid.

## FASE 5 — Associar artistas EN aos termos eixos EN (`03-link-en-terms.php`)
- **Lembrete crítico:** o script usa `wpml_switch_language('en')` antes de `wp_set_object_terms`; depois LIMPAR as term_relationships PT que o WPML copia para os posts EN (senão counts PT dobram).
- **Validar:** counts eixos PT == EN; 0 posts EN com termo PT; `wp term recount eixos`.

## FASE 6 — Coordenadas Fermat (`05-fermat-coords.php`)
- `scp /tmp/centroides.json` + script. Dry-run (confere 0 missing centroid) → apply.
- **Validar:** Manaus raio compacto (~13km), pares PT/EN com mesma coord.

## FASE 7 — Filtros país EN + filtros eixos EN (`04-create-en-tax-filters.php`) + filtro país
- Criar filtros país PT/EN (query_var=pais, glossary 71/72) + posicioná-los no Atlas (1º filtro).
- Rodar `04` para criar filtros EN de Tema(89880)/4Amazônias(89881) com `_data_exclude_include` convertido PT→EN.
- **Capturar IDs dos filtros criados** (no dev: 92323/92324/92985/92986 — em prod podem diferir).
- **Validar:** filtros aparecem e populam em EN.

## FASE 8 — `_elementor_data` das páginas Atlas (PT 57548 / EN 72730) + listings
Aplicar via scripts PHP (não copiar `_elementor_data` cru do dev — IDs de glossário/filtro podem diferir). Mudanças:
- Labels sem prefixo + ordem País→Estado→Município/Território→Área→Tema→4 Amazônias (PT) / Country→State→… (EN).
- País placeholder "Filtrar por País"/"Filter by country"; Município/Território.
- `_element_id=map-lista` no widget de mapa (d0df2db) — **faz os filtros filtrarem o mapa**.
- `posts_num=700` no mapa (d0df2db) — **faz os cliques nos cards abrirem popup**.
- Cor: `[aria-label=` → `[value=` no custom_css do d0df2db.
- Título EN: "Cultural Atlas of the Amazon" → "Cultural Atlas of the Amazônias" (heading + post_title 72730).
- Snap mobile: CSS `@media(max-width:767px){scroll-snap-type:none}` no page_settings (PT+EN).
- Listing EN 92987 (cópia do 18139 com callback glossary no tema) + apontar widget 4fee551 EN para ele.
- JEDV no widget `+` (d4b55a1) dos listings 18139+92987: exibir só com `coordenada`.
- Higienizar `plus-black.svg` (2 cópias).
- **Validar:** Gate 48 (cliques/popup), filtros filtram mapa, labels/ordem/cor, título, snap.

## FASE 9 — Cache flush cirúrgico + validação
```bash
# PHP-FPM reload (mudanças PHP/mu-plugin)
ssh prod "sudo systemctl reload php8.3-fpm"
# WP Rocket + Elementor + jet_cache
ssh prod "sudo -u www-data wp ... eval 'rocket_clean_post(57548); rocket_clean_post(72730); ... if(class_exists(\"\\Elementor\\Plugin\")) \\Elementor\\Plugin::\$instance->files_manager->clear_cache();'"
# CloudFront cirúrgico (NUNCA /*)
aws cloudfront create-invalidation --distribution-id E2F1QD7E7YOYEB \
  --paths '/cultura/atlas-cultural-das-amazonias/' '/cultura/en/cultural-atlas-of-the-amazon/' --profile Concertação
```

## FASE 10 — /smoke completo em prod
Rodar a bateria `/smoke` contra prod (incluindo Gate 48). Sobre submits de formulário: usar token de bypass real (não poluir CRM com leads inválidos — usar marcador `smoke+<ts>@bureau-it.com`).

## Rollback
- **Por fase:** cada script é idempotente e reversível (remover posts EN por `element_type=post_artistas lang=en`, remover termos EN, reverter `eixos` sync=0, restaurar `_elementor_data` dos backups `.json`).
- **Global:** restaurar o `mysqldump` da Fase 0 (apenas as tabelas blog 2 — não tocar wp_options globais sem necessidade).

## Riscos prod-específicos
- Aurora vs MySQL local: testar charset/collation nos INSERTs (acentos nos nomes/descrições).
- 656 INSERTs em Aurora com tráfego: rodar em lotes, fora de pico se possível.
- CloudFront: invalidação cirúrgica só no fim; nunca `/*`.
- WP Rocket cache por hostname: limpar prod (apex). Tunnel é dev, não afeta prod.
- WPML config global (`eixos` translatable) afeta os 2 filtros (Tema + 4 Amazônias) — validar ambos.
