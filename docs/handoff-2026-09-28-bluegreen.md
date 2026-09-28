# Handoff — blue-green da Concertação, parado antes do cutover (28/09/2026)

> Autor: sessão `unified-blog-search-jetsearch` (Daniel Cambría + par de IA), 27–28/09/2026.
> Card: **`86akq2u3h`** (<https://app.clickup.com/t/86akq2u3h>), lista `901328330382`
> (bit-servertools). Os três comentários do card têm o relato completo de cada fase.

## 1. Leia primeiro

- Este arquivo: `/Users/dcambria/bit-apps/bit-servertools/docker-dev/sites/concertacao/docs/handoff-2026-09-28-bluegreen.md`
- State do blue-green: `/Users/dcambria/.config/bit-bpo/servertools/blue-green-state-concertacao.json`
  (o da rodada anterior foi arquivado como `…json.archived-run-20260803`).
- Orquestrador: `/Users/dcambria/bit-apps/bit-servertools/ec2-deploy/blue-green-automation/blue-green.sh`
  (README no mesmo diretório; fase 7 = `phase7-cutover.sh` v1.11.0).
- Memórias novas desta rodada, em `~/.claude/projects/-Users-dcambria-bit-apps-bit-servertools-docker-dev-sites-concertacao/memory/`:
  `project_bluegreen_green_stale_state_armadilhas.md`, `project_waf_regional_alb_sizeqs_busca.md`,
  `feedback_waf_cli_input_json_searchstring_base64.md`.
- **NÃO executar o cutover sem o Daniel dizer "pode" nesta sessão nova.** A pergunta foi feita
  e ficou sem resposta quando a sessão foi passada adiante. Aprovação da sessão anterior não vale.

## 2. Onde parou — retrato medido em 28/09/2026 11:18 BRT

Comandos rodados na hora: `git log`, `git status`, `git rev-list`, `jq` no state, `aws ec2 describe-instances` e `check-ec2.php`.

- **Repo do site:** branch `main`, `git rev-list --count origin/main..HEAD` = 0. O último commit de
  conteúdo é `d56a773e71` (snapshots de WAF/SG); o commit seguinte é o deste handoff. Os commits
  da sessão: `8a357f2a4c` (trabalho de dev: local-cache 2.3.0, espiral, tec.css, form, uploads 200M),
  `e1ecc6e7b2` (espiral 2.4.0, form CSS 1.5.1) e `d56a773e71`. Árvore: só `?? wordpress/.tmb/`, que não é desta sessão.
- **Fases no state:** 1, 2, 3, 4, 6_validate e 65_warmup = `completed`; **7_cutover e 8_cleanup = `pending`**.
- **Instâncias** (as duas `running`):
  - blue/prod atual `i-0ed3101ee64c886ee` — 52.67.96.50, hostname `auto-blueprod-20260803-…`;
  - green `i-056f5b1e3d32c3c66` — 54.207.84.130, hostname `autoconcertacaoamazoniacombrv2-hml`.
  - `check-ec2.php` com `X-Test-Green: true` responde da green; sem o header, da blue.
- **Banco da green:** `wp_concertacao_20260927` (Aurora). Dumps de hoje: `db_20260927_152453_…sql.gz`
  e `wpcontent_20260927_152605_…tar.gz` em `s3://concertacao-backups/concertacao/green/`.
- **Cache da green:** o warmup fechou com 2.479 páginas no WP Rocket e o complemento multilíngue
  subiu para 2.512 (98 páginas, 34 em EN, todas 200). O CSS do Elementor ficou com 907 presentes
  e 0 frios (`elementor-warm-check.sh --scope=all --warm`). **Isso envelhece:** o passo 1b2 do
  cutover esvazia o CSS e o page cache de propósito, e o 1g reaquece.

**Entregue e fechado:** fases 1–6.5; mudança de WAF regional (seção 5); acessos Italy #457.
**Entregue e ainda aberto:**
- validação de formulários sem submit real (seção 6);
- defeitos do toolkit ainda não enviados ao bit-servertools (fila, item 4).

**Não começado:** fase 7 (cutover) e fase 8 (cleanup).

## 3. A fila, nesta ordem

1. **Perguntar ao Daniel se pode fazer o cutover** e esperar o "pode". Comando:
   `cd /Users/dcambria/bit-apps/bit-servertools/ec2-deploy/blue-green-automation && LC_ALL=C.UTF-8 ./blue-green.sh cutover --project concertacao --profile "Concertação" --env-file /Users/dcambria/bit-apps/bit-servertools/.env.concertacaoamazonia.com.br.sa`
   A confirmação forte é digitar `CUTOVER`; em modo não interativo, `--i-really-mean-it=CUTOVER`,
   e só com o "pode" do Daniel. Rodar em background com Monitor: o 1g reaquece por dezenas de minutos.
2. **Logo depois do cutover, na nova prod (alias `-prod-sa`, que ainda aponta para 52.67.96.50 — ver seção 6):**
   - `std warm-check --target=prod --warm` e `std css-health --target=prod`;
   - a sequência da busca, nesta ordem: aquecer o origin → flush de HTML nos **três** alvos
     (WP Rocket blog 1, WP Rocket blog 2, CloudFront `/*`) → só então
     `BASE_URL=https://concertacaoamazonia.com.br npx playwright test 13-busca-crossblog.spec.js`
     em `testes/`. É exceção documentada à regra da invalidação cirúrgica (ver `project_busca_crossblog_deploy_prod`).
3. **Fase 8:** `blue-green.sh cleanup …`. Depois dela, em `~/.ssh/config`:
   - apontar `concertacaoamazonia.com.br-prod-sa` para **54.207.84.130**;
   - comentar de novo o bloco `-green-sa`, com a nota que está nele.
4. **Mandar à sessão `bit-servertools-9b`** os defeitos do toolkit (o repo não é seu; SendMessage):
   - `phase3-share-and-import.sh:1310` — `FQDN_HML: unbound variable`, derruba depois da 13b;
   - a purga 13b apaga `cache/wp-rocket/`, e o `advanced-cache.php` sai no bootstrap sem ele;
   - o post-deploy da fase 2 importa sobras de `green/` e grava `.deploy-09…done`;
   - o teste de SSH do post-deploy compara a resposta ao pé da letra e quebra com o aviso de locale;
   - o bundle do post-deploy não leva `docker-dev/common/scripts/`, então o `08` não instala
     `generate-webp.sh` e o `d5` falha. **A prod atual também não o tem;**
   - `16`/`17-export*` — `env-read-helper.sh` ausente no bundle e `emoji-helper.sh:124` com `env_hml` unbound;
   - regras ALB e1/e2 colidem com a prioridade 210 já ocupada;
   - CLAUDE.md raiz lista só BR #89 e Italy #157 como faixas SSH — agora há Italy #457 e Brazil #129;
   - `waf-sites.yaml` e a skill `bit-waf` não conhecem a ACL regional `amazonia-waf`.
5. Card `86akq2u3h`: status a cada avanço, fechar em `concluído` só depois do item 3.

## 4. Regras da sessão

- Commit só com branch: `git checkout -b …` numa chamada e `git add <caminho>` + `git commit -m` na
  seguinte; depois `checkout main`, `merge --ff-only`, `push` e `branch -d`. Trailer
  `Card: 86akq2u3h` + `Co-Authored-By`. Antes de escrever a mensagem, usar a skill `/bit-general:commit`.
- Nunca `rm` (o hook barra até o texto `rm ` dentro de um grep); mover para `/Users/dcambria/.Trash`.
- SSH/scp para as EC2 sempre com `LC_ALL=C.UTF-8`.
- **A prod morre no cutover e o dev é a fonte da verdade:** nunca propor restaurar nada da prod
  (`feedback_never_reconcile_dev_from_prod`).
- Jamais reverter backup de banco sem consultar o Daniel.
- **Como este documento se edita.** Ele é datado e tem um autor. Corrija aqui o que a sua
  medição provar falso — deixar afirmação morta é pior que corrigir. **Não acrescente aqui
  o que você descobriu:** isso vai no seu próprio handoff. Quando ele existir, ponha o
  caminho dele na primeira linha deste.

## 5. Não refaça

- **A busca 1.5.x passa pelo WAF.** A ACL **regional** `amazonia-waf` (ALB, sa-east-1) barrava
  query string acima de 2048 bytes (`SizeRestrictions_QUERYSTRING`); o widget manda ~2750.
  Corrigido e versionado em `d56a773e71` (`aws/waf-regional-amazonia-waf-{pre,post}-20260928-020931.json`).
  Medido depois do fix: busca 200 na green, na prod e no /cultura/; `/wp-json/wp/v2/pages` e
  `…/search-posts-x` com a mesma query continuam 403.
- **`13-busca-crossblog` D e F falham contra a green e não são defeito.**
  - D: a cache policy `wp-cache-default-hostaware` só põe `eixo,ical,tax,jsf,jet_download,outlook-ical`
    na chave, então `/?s=` é Hit da home. A origem da green, medida por SSH em 127.0.0.1, dá 301 e o legado 302 → `/busca/?s=`.
  - F: o spec abre o artista em aba nova. Medido à mão com Playwright, header em nível de contexto:
    o popup abre com "Hadna Abreu", 119 marcadores, `get-map-marker-info` 200 — igual ao dev.
  - No **dev** os 8 passam (`BASE_URL=https://cambrasmax.local:8484`).
- **`99-green-visual` falha em /contato/ e não é regressão:** o `requestStorageAccess: Permission denied`
  vem do iframe do reCAPTCHA e aparece igual na prod (medido com Playwright nas duas).
- **Os 403 de imagens `2026/09/*` na green são esperados:** só existem em `green/` e são promovidas no
  swap 1c/1d do cutover. O `99-green-visual` valida pelo `_oac-canary` (751 arquivos).
- **Resíduos de `cambrasmax.local` (21.242) e do túnel (1.852) no banco da green não são conteúdo.**
  São `guid` (21.235, pulados de propósito), `elementor_log`, `fs_api_cache`, `pue_key_status_*`
  e strings do Admin Columns. O HTML servido tem 0 ocorrências.
- **Diff de conteúdo prod × green já feito** (posts dos dois blogs, por ID e `post_modified`).
  O que só existe na prod e **vai sumir**:
  - eventos 95721 `fundo-da-amazonia-oriental-fao-e-do-funbio` e 95735 `projeto-rais`;
  - página 95588 `iniciativas-estruturantes-v2`, criados na prod pelo fabricio.ferrari.
  Mais cerca de 20 edições da prod que voltam à versão do dev. O Daniel já sabe; por regra, isso
  não é drift a reconciliar. Anexos: nenhum só na prod.
- **Versões:** Elementor 3.35.8 → 4.2.4 (Pro 3.35.1 → 4.2.3) e WP 7.0.6 → 7.1.2. O Daniel confirmou.
  O hotfix `bit-wprocket-wp71-hotfix.php` está na green com md5 igual ao do dev.
- **O `wp-config` da green tem as mesmas constantes da prod** (diff por nome), e o
  `BIT_SMOKE_BYPASS_TOKEN` já foi copiado. As diferenças de valor são as esperadas
  (DB_NAME, S3 bucket, ENV_TYPE, REDIS_PREFIX, REDIS_PASSWORD, salts); o 1b troca o que precisa.

## 6. Cuidados, por tarefa

- **Cutover × CSS:** o 1b2 (`phase7-cutover.sh:1198`) chama `files_manager->clear_cache()`, que
  apaga todo o CSS do Elementor, e o compat do WP Rocket esvazia o page cache junto. O 1g
  (`:2042`) reaquece só as páginas (`--pages-only`). Por isso o warm-check `--warm` depois do cutover **não é opcional**.
- **Cutover × `cache/wp-rocket/`:** confira que o diretório continua existindo depois do 1b2. Se
  sumir, o WP Rocket volta a não cachear. Sintoma: sem `cached@` no rodapé do HTML;
  correção: `sudo -u www-data mkdir -p …/wp-content/cache/wp-rocket`.
- **Formulários:** não houve submit real. O `_smoke-green` manda `smoke+…@bureau-it.com` para forms
  com a ação `bit_rdstation`, o que criaria lead real no RD do cliente
  (`feedback_rdstation_test_separation`). Quem decide como testar é o Daniel.
- **Alias SSH:** até a fase 8, `-prod-sa` = blue (52.67.96.50) e `-green-sa` = green (54.207.84.130).
  Depois do cutover o EIP 52.67.96.50 passa para a green — confirme com `hostname` antes de agir.
- **Acessos liberados nesta rodada (autorizados pelo Daniel):**
  - Italy #457 `187.13.213.0/24` na porta 22 do `web-prod-sg` (`sg-07a970b305ccf0939`);
  - o mesmo range no IP set `NordBrazil90CIDR` do WAF do CloudFront, que também libera wp-admin/wp-login da prod.

## 7. O combinado com o Daniel

- O cutover depende do "pode" dele, e só dele.
- Ele está conectado na NordVPN Italy #457. Se o SSH falhar, confira o IP com `curl ifconfig.me`
  e compare com as faixas do SG antes de culpar a rede: em 27/09 o "sem VPN" era, na verdade, alias e locale.
- O que tentar sozinho antes de chamá-lo: tudo que é leitura, tudo que é na green, e o
  diagnóstico de qualquer 403/404 (bisseção por curl, WAF sampled requests nas **duas** ACLs).
