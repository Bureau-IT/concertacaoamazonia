# Runbook — Atualização de dados do Atlas Cultural (set/2026) DEV → PROD

**Data:** 2026-09-15 · **Autor:** Daniel Cambría · **Card:** <https://app.clickup.com/t/86akjhycg>
**Alvo:** `concertacaoamazonia.com.br` (prod, Aurora RDS, CloudFront `E2F1QD7E7YOYEB`, WP Rocket)

## O que este runbook leva

A planilha de setembro entregue pela cliente: **33 artistas com cidade/estado novos** e
**1 artista novo** (Angel Lima). Tudo já aplicado e conferido no dev
(`cambrasmax.local:8484/cultura/`), commit `611a808f91` em `Bureau-IT/concertacaoamazonia`.

| Passo | Script | Efeito |
|---|---|---|
| 1 | `atlas-2026-09-01-localidades.php` | cidade/estado/pais/coordenada em 33 artistas, PT **e** par EN |
| 2 | `atlas-2026-09-02-artista-novo.php` | cria Angel Lima PT+EN, metas, eixos, coordenada |
| 3 | `atlas-2026-09-03-glossario-cidades.php` | 22 cidades novas no glossário JetEngine 67 |
| 4 | `atlas-2026-09-04-listing-cidade-formato.php` | tira a vírgula órfã do card nas listagens 18139/92987 |

Os quatro são **dry-run por padrão** (`APPLY=1` grava), **idempotentes** (2ª passada grava zero)
e casam artista por **título normalizado**, não por ID — título que não casa, ou que casa com
mais de um post, é relatado e pulado.

## Pré-voo — JÁ EXECUTADO em 15/09/2026, tudo verde

Rodado contra o prod em modo leitura. Nada foi escrito.

- SSH `concertacaoamazonia.com.br-prod-sa` responde; host `auto-blueprod-20260803-concertacaoamazoniacombr`.
- Baseline: **659** artistas PT publish (mesmo estado em que o dev estava antes).
- `01` dry-run: 33 de 33 casados por título, **0 não encontrados, 0 ambíguos**, 149 metas a gravar.
  Três sem par EN — `Benito Beno Juarez`, `Bustar Maitar`, `Cecile Ndjebet` — ver "Ressalva" abaixo.
- `02` dry-run: slug `angel-lima` livre; criaria PT e EN.
- `03` dry-run: glossário 67 vai de **157 para 179** itens (22 inserções, 7 já existiam) — igual ao dev.
- `04` dry-run: listagens 18139 e 92987 existem, com os mesmos IDs de elemento do dev
  (container `c8f5aad`, cidade `f079808`, estado `f908969`), +96 bytes cada.
- Termos de eixos do artista novo existem em prod com os **mesmos IDs do dev**: PT 2487/2477/2482/2480/2481/2488
  e EN 2547/2548/2549/2553/2554/2557.

Os arquivos já estão em `/tmp/` do prod (`atlas-2026-09-dados.json` + os quatro `.php`).

## FASE 0 — Backup (obrigatório)

```bash
ssh concertacaoamazonia.com.br-prod-sa "sudo -u www-data wp --path=/var/www/concertacaoamazonia.com.br \
  db export ~/backups/atlas-2026-09-pre-\$(date +%Y%m%d-%H%M%S).sql \
  --tables=wp_2_posts,wp_2_postmeta,wp_2_terms,wp_2_term_taxonomy,wp_2_term_relationships,wp_2_icl_translations,wp_2_options"
```

## FASE 1 — Aplicar, um script por vez, dry-run antes de cada APPLY

```bash
P="sudo -u www-data wp --path=/var/www/concertacaoamazonia.com.br --url=https://concertacaoamazonia.com.br/cultura/"

# 1 — localidades (espera: atualizados_pt=33, nao_encontrados=[], ambiguos=[])
ssh concertacaoamazonia.com.br-prod-sa "$P eval-file /tmp/atlas-2026-09-01-localidades.php"
ssh concertacaoamazonia.com.br-prod-sa "sudo -u www-data APPLY=1 wp --path=... --url=... eval-file /tmp/atlas-2026-09-01-localidades.php"

# 2 — artista novo (espera: pt_id e en_id preenchidos, avisos=[])
ssh concertacaoamazonia.com.br-prod-sa "sudo -u www-data APPLY=1 wp ... eval-file /tmp/atlas-2026-09-02-artista-novo.php"

# 3 — glossário (PRECISA de --user: o JetEngine exige manage_options)
ssh concertacaoamazonia.com.br-prod-sa "sudo -u www-data APPLY=1 wp ... --user=daniel.cambria eval-file /tmp/atlas-2026-09-03-glossario-cidades.php"

# 4 — listagens
ssh concertacaoamazonia.com.br-prod-sa "sudo -u www-data APPLY=1 wp ... eval-file /tmp/atlas-2026-09-04-listing-cidade-formato.php"
```

Rodar cada um **duas vezes**: a segunda tem de acusar zero escritas. Se não acusar, parar.

## FASE 2 — Cache (a parte que esconde o resultado)

**O `_elementor_element_cache` sobrevive a flush e serve o card antigo.** No dev, o banco já
estava certo e a listagem continuou renderizando o formato velho até estas 81 linhas caírem.
Esta fase não é zelo: sem ela a conferência da Fase 3 mede o cache, não o trabalho.

```bash
ssh ... "$P db query \"DELETE FROM wp_2_postmeta WHERE meta_key='_elementor_element_cache'\""
ssh ... "$P eval 'if(class_exists(\"\\\\Elementor\\\\Plugin\")) \\Elementor\\Plugin::\$instance->files_manager->clear_cache();'"
ssh ... "$P cache flush"     # Redis compartilhado: NUNCA FLUSHDB neste site
```

Depois, invalidação **cirúrgica** (nunca `/*`), pela ferramenta do repositório:

```bash
std cache-flush --prod /cultura/atlas-cultural-das-amazonias/
std cache-flush --prod /cultura/en/cultural-atlas-of-the-amazon/
```

> **Aquecer o origin antes de invalidar o CloudFront** — abrir as duas URLs no origin antes de
> a borda voltar a buscar, senão o primeiro visitante paga a regeneração (histórico de 502 no Atlas).

## FASE 3 — Conferência

1. `wp db query` contando artistas PT publish: tem de ser **660** (era 659).
2. Contador do Atlas PT: "660 ARTISTAS". Contador do Atlas EN: **652** (a diferença de 8 é
   preexistente e está na Ressalva).
3. Card de artista internacional (Aaron Koblin) mostra **"Los Angeles"**, sem vírgula sobrando.
   Card brasileiro mostra "Belém," / "Pará", com a vírgula no fim da primeira linha.
4. Filtro "Município/Território" lista **179** opções, com `Los Angeles`, `Londres`, `Nova York`,
   `Cacoal`, `Oregon`, `Palmas`.
5. Buscar "Angel Lima" na busca rápida do Atlas: card com `Fotografia` e `Palmas, Tocantins`.
6. Mapa com zoom mundial: markers na Califórnia, em Nova York e em Edéa (litoral dos Camarões).
7. `/smoke` completo, com atenção ao Gate 48 (cliques e popup do Atlas).

## Rollback

Cada script é idempotente e o estado anterior está no dump da Fase 0. Especificamente:

- **Localidades:** restaurar as metas do dump (o script não guarda valor anterior por artista).
- **Angel Lima:** `wp post delete <en_id> --force` e `wp post delete <pt_id> --force`, mais a
  limpeza das linhas dele em `wp_2_icl_translations`.
- **Glossário 67:** o script só insere; remover as 22 cidades pela UI do JetEngine ou restaurar
  `wp_2_jet_post_types` do dump.
- **Listagens:** o script guarda o valor anterior em `_elementor_data_bkp_atlas_202609`.
  Restaurar com `update_post_meta($id,'_elementor_data', wp_slash($bkp))` e **repetir a Fase 2**.

## Ressalva — o que este runbook NÃO conserta

Três dos 33 artistas (`Benito Beno Juarez`, `Bustar Maitar`, `Cecile Ndjebet`) **não têm versão em
inglês**: o slot `en` do WPML deles está ocupado por um *attachment*. São oito artistas no total
nessa condição, defeito preexistente, e é por isso que o Atlas EN mostra 652 contra 660 do PT. O
dado da cliente entra no PT normalmente; no EN não entra enquanto o vínculo não for consertado.
Registrado à parte.

Também fica de fora o racha da taxonomia `eixos`: os filtros de tema do Atlas whitelistam só o
conjunto legado de termos e alcançam **134 dos 660** artistas. Angel Lima recebeu o conjunto
hierárquico, que é o que a planilha nomeia e o que 510 artistas usam — ou seja, ele fica do lado
majoritário, e invisível a esses dois filtros como os outros 509. Também registrado à parte.
