# CLAUDE.md — Concertação Amazônica (Docker Dev)

Site WordPress Multisite do projeto Uma Concertação Pela Amazônia.
Para padrões gerais de operação de sites, ver [sites/CLAUDE.md](../CLAUDE.md).


## Ambientes


### Desenvolvimento Local

| Item | Valor |
|------|-------|
| URL HTTPS | `https://cambrasmax.local:8484` |
| URL HTTP | `http://cambrasmax.local:8084` (redireciona para HTTPS) |
| MySQL externo | `localhost:3310` |
| Redis | `localhost:6383` |
| Stack name | `concertacao` |

#### Cloudflare Tunnel

Esse tunnel garante acesso externo ao ambiente de desenvolvimento.
O tunnel está **habilitado** (`CF_TUNNEL_ENABLED=true`) e expõe o site externamente em:

```
https://concertacao.bureau-it.com
```

### Site em Produção

https://concertacaoamazonia.com.br


## Multisite (Subdirectory Mode)

WordPress Multisite com path-based routing:

| blog_id | URL | Tabelas |
|---------|-----|---------|
| 1 | `concertacaoamazonia.com.br/` | `wp_*` |
| 2 | `concertacaoamazonia.com.br/cultura/` | `wp_2_*` |

- Uploads gerenciados pelo plugin **Network Media Library** (`network-media-library/`), que unifica as bibliotecas de mídia de todos os blogs — não acessar uploads por blog diretamente
- Nginx tem rewrites específicos para multisite (wp-admin, wp-includes, wp-content, ms-files)
- **Sempre usar `--url=` no WP-CLI** para operações em subsites:
  ```bash
  std wp --url="https://cambrasmax.local:8484/cultura/" option get siteurl
  ```

## Multisite + NML — Comportamento de imagens

Toda a mídia vive no **blog 1** (raiz). O blog 2 (`/cultura/`) referencia esses IDs via Network Media Library. Isso funciona transparentemente na maior parte dos casos, mas tem dois efeitos colaterais que o mu-plugin `bit-crossblog-attachment-fix.php` corrige (Hooks 1–8 para attachments comuns, Hooks 9–13 para órfãos WPML em páginas EN/ES). README completo: [`wordpress/wp-content/mu-plugins/bit-crossblog-attachment-fix.README.md`](wordpress/wp-content/mu-plugins/bit-crossblog-attachment-fix.README.md).

- **Sempre subir imagens pelo seletor de mídia do Elementor** — não colar `<img src="...">` direto no HTML do widget. O ID precisa estar registrado para os hooks pegarem.
- **WPML media duplication deve ficar OFF nos 2 blogs** — duplicate-on-translate cria attachments órfãos em `wp_2_posts` sem arquivo físico (sintoma: imagens quebradas em `/cultura/en/*`).
- **`elementor_css_print_method=external` no blog 2** — `internal` faz os widgets de listing JetEngine quebrarem.
- **Sintoma de regressão**: imagens 403/404 em `/cultura/` com URLs contendo `/uploads/sites/2/`. Rodar `/smoke` — o gate 26 (`wpml_orphan_leak`) detecta automaticamente.
- **Versão mínima do mu-plugin em prod: 1.5.2** (cobertura srcset completa). Validar com `grep '* Version' .../mu-plugins/bit-crossblog-attachment-fix.php`.

## SSH Produção

```bash
# HML (IP: 52.67.96.50, t3.xlarge, running) — único ambiente v2 ativo
ssh concertacaoamazonia.com.br-prod-sa
```

**Características do Produção:**
- nginx na porta 80, SSL termina no Load Balancer (não no servidor)
- Banco: Aurora RDS externo (sem MySQL local)
- DB name: `wp_concertacao_20250316`, user: `concertacao-v2`
- Aurora endpoint: `amazonia-aurora-db-cluster.cluster-cbh7rhtadzwg.sa-east-1.rds.amazonaws.com`
- Health check na porta 8080 para o Load Balancer
- Redis compartilhado — não permite `FLUSHDB`

WP_ROOT: `/var/www/concertacaoamazonia.com.br`

## mu-plugins específicos deste site

Além dos mu-plugins padrão, este site tem:

| Arquivo | Função |
|---------|--------|
| `bit-dropdown-btn.php` | Botão dropdown customizado |
| `bit-elementor-espiral-widget.php` | Widget Elementor para a espiral SVG |
| `bit-wpml-circle.php` | Integração WPML com circle menu |
| `bit-crossblog-attachment-fix.php` | Fix cross-blog para attachments do blog 1 em contexto de blog 2 (URL, path, download, gallery, lightbox titles) |
| `tunnel-url-rewrite.php` | Rewrite de URLs no modo tunnel |
| `bit-tec-cache.php` | Cache 24h de `tribe_get_option('previous_ecp_versions')` — elimina DB query + `usort()` custoso a cada request em `tribe_events_is_new_install()` (spike CPU 02/04/2026) |
| `bit-elementor-form-rdstation.php` | Form Action `bit_rdstation` (v1.2.0) — envia leads (email, name, company_name, cf_uf, tags) para o RD Station via `RDSTATION_API_KEY`. Conectada nos 4 footers + Contato PT/EN. Graceful: falha da API não quebra o submit. Log em `/var/log/bit-rdstation/` (fora do webroot). Validação: Gate 55 do `/smoke` |

## Banco de Dados

- MySQL 8.0 local (container) — imagem `mysql:8.0`
- **Não** usa Aurora RDS (somente HML/Prod usam)
- JetEngine CCT de participantes: tabela `wp_jet_cct_participantes_cct` (1.287 registros)

**Produção (Aurora MySQL):**
- Engine: Aurora MySQL `8.0.42` (verificado 2026-03-31)
- Endpoint: `amazonia-aurora-db-cluster.cluster-cbh7rhtadzwg.sa-east-1.rds.amazonaws.com`
- DB name: `wp_concertacao_20250316` · user: `concertacao-v2`
- ⚠️ Comportamentos podem diferir do MySQL 8.0 local em charset/collation/sql_mode — testar migrações em HML antes de prod

## Plugins Críticos

- Elementor + Elementor Pro
- JetEngine + extensões (CCT participantes, listagens, filtros)
- The Events Calendar
- WPML (multilíngue)
- WP Rocket + Redis (cache)
- S3 Uploads (mídia — **ativo em produção pós-CF-OAC**, prefix `/assets` no bucket `concertacaoamazonia-com-br-wp-static-prd-sa`)
- Network Media Library (multisite)

> **Imagens otimizadas (WebP+AVIF):** geradas via mu-plugin `bit-webp-on-upload.php`
> (uploads em runtime — DEV-ONLY) e script `d5-generate-webp.sh` no post-deploy
> (prod). Sem dependência de plugin EWWW (removido em 2026-05-02).

## WebP+AVIF — Operação

O nginx serve `imagem.jpg.avif` (preferido) ou `imagem.jpg.webp` (fallback) via
`try_files $uri.avif $uri.webp $uri` quando o browser anuncia `Accept: image/avif`
ou `image/webp`. Sem suporte: serve raster JPG/PNG normal.

**Geração:**

1. **Em uploads (runtime, DEV):** mu-plugin `bit-webp-on-upload.php` dispara
   `wp_schedule_single_event` no `wp_generate_attachment_metadata`, gerando
   derivados via `proc_open` array (sem shell, zero injection). DEV-ONLY:
   guard `wp_get_environment_type() === 'development'` impede execução em prod.

2. **Em deploy (PROD/HML):** `d5-generate-webp.sh` no post-deploy varre
   `wp-content/uploads` e gera pendentes (idempotente via mtime check).
   Coordena com `10-importwpcontent.sh` via `flock /var/lock/generate-webp.lock`.

3. **Bulk histórico:** `std webp-bulk` em dev (uploads/2026 já validado:
   97% WebP / 94% AVIF coverage). `--force` regenera todos.

**Engine:** `docker-dev/common/scripts/generate-webp.sh` — bash standalone
com `cwebp` + `avifenc` + `identify` (alpha detection PNG + CMYK pre-convert).

**Spec:** `docs/superpowers/specs/2026-05-02-webp-automation-design.md`

## Deploy de Tema em Produção

### Procedimento obrigatório após rsync de arquivos PHP

```bash
# 1. Rsync do tema (executar a partir do diretório do site)
rsync -avz --delete \
  wordpress/wp-content/themes/hello-elementor-child/ \
  concertacaoamazonia.com.br-prod-sa:/var/www/concertacaoamazonia.com.br/wp-content/themes/hello-elementor-child/

# 2. OBRIGATÓRIO — Recarregar PHP-FPM (WP-CLI não limpa o pool FPM)
ssh concertacaoamazonia.com.br-prod-sa "sudo systemctl reload php8.3-fpm"

# 3. Invalidar cache WP Rocket da página alterada
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo -u www-data wp --path=/var/www/concertacaoamazonia.com.br rocket_clean_post --post_id=<ID>"
```

**Por que php-fpm reload é necessário:** `wp eval 'opcache_reset()'` roda no SAPI CLI — OPcache isolado do pool FPM (SAPI fpm-fcgi). Sem o reload, PHP-FPM continua servindo bytecode da versão anterior. Sintoma: mudanças PHP refletem via WP-CLI mas não no browser.

**Cache em dev:** `opcache.validate_timestamps=1` + `revalidate_freq=2s` — OPcache invalida automaticamente quando mtime do arquivo muda. Reload manual não é necessário em dev.

### Paridade Redis dev/prod

`object-cache.php` existe em dev. Status do plugin `redis-cache` pode divergir após reimport de banco de produção (onde está ATIVO):

```bash
std wp plugin list --name=redis-cache --fields=name,status
std wp eval "echo wp_using_ext_object_cache() ? 'Redis ATIVO' : 'Redis INATIVO';"
```

Se dev tiver Redis inativo mas prod ativo, customizações dependentes de filtros de cache do TEC (ex: Month View, Week View) podem não ser exercitadas em dev. Ativar para validar: `std wp plugin activate redis-cache && std wp redis enable`.

## WPML — Agente Poliglota

Para qualquer operação de tradução:
- Invocar `/wpml-translate` — o agente conhece a configuração multisite deste site
- **Sempre especificar URL do subsite** no WP-CLI (sem `--url=` opera no blog 1 silenciosamente)
  - Blog 1 (raiz): `cambrasmax.local:8484`
  - Blog 2 (/cultura/): `cambrasmax.local:8484/cultura/`
- Redis FLUSHDB proibido em prod — agente usa `wp cache flush` via WP-CLI
- mu-plugin `bit-wpml-circle.php` integra WPML com o circle menu — não remover

## Notas de Infra Prod (atualizado 2026-05-02)

### S3 Uploads (CF-OAC ativo)

- **Bucket:** `concertacaoamazonia-com-br-wp-static-prd-sa`
- **Prefix em produção:** `/assets` (NÃO `/green` — esse é o prefix de warmup durante blue-green)
- `S3_UPLOADS_BUCKET=concertacaoamazonia-com-br-wp-static-prd-sa/assets`
- CloudFront serve `wp-content/uploads/*` da origin S3 com `OriginPath=/assets/uploads`
- Pós-cutover blue→green: `phase7-cutover.sh v1.6.0+` faz auto-detect do swap green→assets (`CF_OAC_SWAP=auto`)
- Validação rápida: `ssh concertacaoamazonia.com.br-prod-sa "sudo -u www-data wp config get S3_UPLOADS_BUCKET"` deve retornar prefix `/assets`

#### Validação de imagens da GREEN pré-cutover (403 é esperado — NÃO é bug)

Durante a validação da green (blue-green, pré-cutover), a green grava uploads em
`s3://bucket/green/uploads/` (S3_UPLOADS_BUCKET=`.../green`), mas o CloudFront serve
`/wp-content/uploads/*` de `assets/uploads/` (prod). **Imagens NOVAS que existem só em `green/`
(entraram no dev recentemente) dão HTTP 403** ao acessar a green via URL normal — heros/backgrounds
aparecem sem imagem. **Isto é by-design do CF-OAC**: uploads só "promovem" para `assets/` no cutover
atômico (phase7, swap `green→assets`). NÃO sincronizar `green/→assets/` antes do cutover (escreve em
prod, quebra a atomicidade blue-green; análise 3-agentes 2026-06-22).

**Como validar as imagens da green corretamente** (sem tocar prod) — via mecanismo `_oac-canary`
(CF Function `uploads-oac-router` + behaviors `*/wp-content/uploads/_oac-canary/*` → origin
`S3-uploads-green`; ver [[project_oac_canary_strip_pattern]]):

1. **Automatizado (recomendado):** `testes/tests/99-green-visual.spec.js` — faz diff S3
   (`green/uploads` vs `assets/uploads`), reescreve via `page.route` as imagens só-green para
   `/wp-content/uploads/_oac-canary/<path>` (rewrite SELETIVO), e gera screenshots fullPage das
   páginas-chave. Rodar: `BASE_URL=https://concertacaoamazonia.com.br npx playwright test 99-green-visual.spec.js`
   (NordVPN ativo + profile `Concertação`). Revisar os PNGs.
2. **Smoke Gate 53:** valida que as imagens só-green servem 200 via `_oac-canary` (diff S3 + fetch).
   Pega regressão (phase3 sem `--uploads-mode=s3-sync`, ou CF Function/behavior canary quebrado).
3. **Humano ao vivo no browser:** ModHeader (que só injeta header `X-Test-Green`) **NÃO basta** —
   as imagens novas quebram. Usar **Requestly** com regra "Replace String" `/wp-content/uploads/` →
   `/wp-content/uploads/_oac-canary/` (confiável porque o `s3-sync` da phase3 faz `green ⊇ assets`;
   replace cego é seguro nesse caso) + header `X-Test-Green: true`. Alternativa limpa: revisar os
   screenshots do spec (item 1).

**NUNCA** resolver o 403 da green sincronizando `green/→assets/` (toca prod). As imagens aparecem
sozinhas no cutover.

### FPM Workers em Produção

- **Atual:** `max_children=20` (override em `.env` raiz via `FPM_MAX_CHILDREN_PROD=20`)
- Fórmula original (vCPUs*5=10) é conservadora; t3.large suporta ~27 workers (~280MB cada / 7.6GB)
- Override aplicado em 2026-05-02 durante incidente de saturação por crawler `meta-externalagent`
- Sites sem alto tráfego de crawler legítimo devem manter o default (10)

### Cache CloudFront das rotas de eventos TEC (2026-06-22)

As rotas de listagem do The Events Calendar têm **behavior CloudFront dedicado** (`eventos*`,
`eventos-calendario*`, `editais*`) com a cache policy **`WP-Events-ShortTTL-HostAware`**
(`f24028ef-ae48-446e-b0be-7789e04acba4`): **DefaultTTL=300, MaxTTL=900, QueryStringBehavior=all**,
host-aware (`X-Test-Green`+`Host`).

**Por quê:** o HTML dessas páginas é dinâmico (a paginação muda conforme eventos vencem com o tempo).
No DEFAULT behavior (policy `wp-cache-default-hostaware`, TTL 24h, whitelist de QS sem `eventDisplay`/
`tribe-bar-date`/`tribe_paged`), o CloudFront colapsava todas as variantes de navegação numa entrada
e servia paginação stale por 24h → setas "Próximos/Anteriores" não navegavam. O behavior dedicado
diferencia cada variante (QS no cache key) e mantém o cache fresco (≤5 min). MaxTTL 900s fica abaixo
das 12h dos nonces REST das Views v2 do TEC (acima disso, a navegação AJAX quebra).

- **NÃO** mexer na policy global `wp-cache-default-hostaware` para isso (é compartilhada pelo site todo).
- A home `/` NÃO está coberta (escopo separado — tem `_elementor_element_cache`; ver [[feedback_home_tec_events_view_element_cache]]).
- Smoke **Gate 51** valida a paginação (edge vs origin). Após deploy/mudança de eventos, se a paginação
  travar, invalidar `/eventos* /eventos-calendario* /editais*` (profile Concertação, dist E2F1QD7E7YOYEB).
- Blog 2 (`/cultura/`) não tem eventos → sem behavior `*/eventos*`.

### Observabilidade — OTel agent + bit-monitoring (2026-06-19)

A CPU/memória/disco/rede do prod são monitoradas pelo **bit-monitoring** via um
**OpenTelemetry Collector nativo** (systemd, não Docker — o prod roda nginx+PHP-FPM
nativo). A regra nativa "CPU High Usage" (`system.cpu.utilization > 0.9`) substitui
os alarmes CloudWatch `cpu-critical`/`cpu-warning` (desativados via `disable-alarm-actions`
em favor do bit-monitoring; só `cpu-credits-low` permanece no CloudWatch — gap de
`CPUCreditBalance` ainda não coletado pelo bit-monitoring).

- **Agent:** `otel_agents` id 9, name `concertacao-prod`, cloud_account_id 3.
  Modelo de **identidade estável reusada** (1 agent por servidor lógico; toda green
  reusa o mesmo token).
- **Token:** `OTEL_AGENT_TOKEN_PROD` no Secrets Manager `concertacaoamazonia.com.br-env-vars`
  (sa-east-1), lido em runtime pela IAM role da EC2.
- **Install automático:** post-deploy `d7-otel-collector.sh` (idempotente) — instala
  o `.deb otelcol-contrib 0.145.0`, config hostmetrics, caps systemd (256M/25%/Nice10),
  aponta para `https://status.bureau-it.com/api/v1/otel`.
- **Blue-green:** `phase8-postcutover.sh` step 4b (`apply_otel_agent_hostname`) re-aponta
  `otel_agents.hostname` para a green; `reapoint_cpu_alarms` preserva `ActionsEnabled`
  (não reverte a consolidação). Ativado via `OTEL_AGENT_NAME=concertacao-prod` no `.env` raiz.
- **Validação rápida:** `ssh concertacaoamazonia.com.br-prod-sa "systemctl is-active otelcol-contrib"`
  e checar `last_seen` recente em `otel_agents` id 9 no bit-monitoring.

### CSP — Google Charts (Sobre Nós)

A página "Sobre Nós" usa Google Charts (gstatic). CSP precisa de `gstatic.com` em `style-src`:

```
style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://*.gstatic.com;
```

Sem isso, o widget de gráficos do Google Charts não estiliza — gráficos aparecem sem formatação ou quebrados visualmente.
