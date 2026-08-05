# Spec: Mitigação do incidente FPM saturation 502 — 2026-05-02 (espiral)

**Data:** 2026-05-02
**Status:** **Tier IMEDIATO parcialmente aplicado (Items 1 e 3 em produção)**; Tier ESTA SEMANA pendente; Tier MÉDIO PRAZO em re-avaliação
**Autor:** Daniel Cambría / Bureau de Tecnologia
**Revisores:** 5 agentes especializados (Arquitetura, Segurança/Blast Radius, Operação WP/WPML/JetEngine, Custos AWS/ROI, Riscos/Rollback) — completo em 2026-05-02 ~04:00 BRT
**Histórico de revisão:**
- 2026-05-02: rev1 inicial (15 itens)
- 2026-05-02 (rev2 pós-review): correções de 8 bugs críticos identificados pelos reviewers — hook JetEngine corrigido, whitelist trailing-slash, FallbackBehavior NO_MATCH, custo Bot Control corrigido, cleanup `hml:*` agendado, flock no cron, Item 15 reordenado para Tier 1
**Cliente:** Uma Concertação Pela Amazônia
**Distribuição CloudFront:** `E2F1QD7E7YOYEB`
**Instância EC2:** `i-059febbd720286cd4` (t3.large, 2 vCPUs, 8GB RAM, sa-east-1)
**Banco:** `wp_concertacao_20250316` em Aurora MySQL 8.0.42 (`amazonia-aurora-db-cluster.cluster-cbh7rhtadzwg.sa-east-1.rds.amazonaws.com`)

---

## Sumário executivo

Em 2026-05-02 entre 01:10 e 01:21 BRT, a página inicial e os widgets de "Lista de Estudos Nova Espiral" do site `concertacaoamazonia.com.br` retornaram **502 Bad Gateway** quando um usuário (Daniel Cambría) clicou em pontos da espiral SVG. A investigação em três ciclos com dez agentes especializados isolou **treze causas-raiz simultâneas e correlacionadas**, das quais quatro são suficientes para reproduzir o cenário sob carga moderada. Este spec propõe **quinze ações de mitigação** organizadas em três tiers temporais (imediato hoje, esta semana, médio prazo) com comandos exatos, validação, rollback e métricas antes/depois.

A mensagem central é: **o site está rodando há mais de uma semana sem cache de objetos efetivo** (Redis com prefix errado e drop-in ausente), o **page cache do WP Rocket é apagado de hora em hora** sem warmer ativo, e o listing JetEngine principal sofre de **N+1 query de 76 queries para 12 itens**. Em condições normais isso não basta para derrubar a aplicação porque o cache HTML do WP Rocket absorve a maioria dos hits — mas no momento em que o cache é purgado e um crawler com fingerprint de "Firefox 142" varre o site enquanto o usuário interage, vinte workers FPM saturam em segundos.

Ações Tier IMEDIATO (Itens 1, 2 e 3) são comandos de baixo risco, executáveis em 30 a 90 minutos cada, que devolvem aproximadamente 60 a 80 por cento das queries ao cache de objetos, eliminam a janela de cache frio horário e neutralizam a família de bots responsável pelo evento. Ações Tier ESTA SEMANA (Itens 4 a 9) corrigem causas-raiz estruturais de menor impacto isolado mas que se acumulam. Ações Tier MÉDIO PRAZO (Itens 10 a 15) endereçam capacidade, observabilidade e mudanças arquiteturais.

---

## Contexto

### O que aconteceu

Em 2026-05-02, por volta de 01:10 BRT, o usuário começou a observar TTFB anormalmente alto ao navegar o site. Aos 01:21 BRT, ao clicar em um dos pontos visíveis da espiral SVG renderizada na home (que dispara um listing JetEngine de "Estudos Nova Espiral" via AJAX), o navegador retornou:

```
502 Bad Gateway
The server returned an invalid or incomplete response.
```

A janela durou aproximadamente onze minutos. Durante esse tempo, o ALB respondeu 502 para uma fração não-quantificada de requisições, e nenhum alarme do BIT Monitoring disparou (o site não tem alarme dedicado para `HTTPCode_ELB_5XX_Count` no target group desta aplicação — débito técnico documentado em `feedback_post_deploy_stability.md`).

A captura de tela do navegador feita pelo usuário mostra a barra de URL em `https://concertacaoamazonia.com.br/` e o corpo "502 Bad Gateway / The server returned an invalid or incomplete response."

### O que foi investigado

Foram executados três ciclos de investigação envolvendo dez agentes especializados em paralelo. O escopo cobriu:

- Logs do nginx (`/var/log/nginx/access.log` e `/var/log/nginx/error.log`) entre 2026-05-01 00:00 BRT e 2026-05-02 02:00 BRT
- Pool de workers FPM (`pm.status_path`, slowlog inativo no momento)
- Cache do Redis local (socket `/var/run/redis/redis-server.sock`)
- Cache do WP Rocket em `/var/www/concertacaoamazonia.com.br/wp-content/cache/wp-rocket/`
- Métricas Aurora (`AuroraDBClusterCpuUtilization`, `DatabaseConnections`)
- Métricas CloudFront (`5xxErrorRate`, `OriginLatency`, `Requests`)
- Métricas EC2 (`CPUUtilization`, `CPUCreditBalance`)
- Configuração WAF (`ACL-WPAdminHML`, regra `Block-AggressiveBots` e `RateLimit-300-Block`)
- Listings JetEngine 28187 ("Lista de Estudos Nova Espiral") e dependentes

Os artefatos individuais ficam em `docs/incidents/2026-05-02/agent-N.md` (a serem anexados — placeholder na seção 12 deste spec).

### Timeline da cascata

| Hora (BRT) | Evento | Sistema |
|------------|--------|---------|
| 2026-05-01 22:30 | Cron `purge_cron_interval=10h` apaga page cache do WP Rocket integralmente | WP Rocket |
| 2026-05-01 22:30 | `d4-cache-warmup.sh` NÃO executa (não está em cron de prod) | systemd cron |
| 2026-05-01 22:30 → 2026-05-02 01:10 | Preload do Rocket parado: 29 URLs em fila `wp_wpr_rocket_cache` desde 2026-05-01 08:08 UTC (21h sem progresso, fila travada por carga prévia do meta-externalagent já mitigada em v1.12.2) | wp_wpr_rocket_cache + cron WP |
| 2026-05-02 01:10 | Bot fake `Mozilla/5.0 ... Firefox/142.0` inicia varredura de 143 URLs caras (categorias TEC, eventos antigos, listings JetEngine paginados) | nginx + FPM |
| 2026-05-02 01:10 → 01:21 | Cada request: cache HTML frio + Redis vazio + N+1 listing + Aurora burstable + redirect-PHP em alguns paths = 15-30s/request; 20 workers FPM saturam a aproximadamente 1.4 reqs/s sustentada | FPM |
| 2026-05-02 01:21 | Usuário clica ponto da espiral → request entra em fila atrás dos workers presos → ALB declara 502 após 60s (`fastcgi_read_timeout`=60 não estoura, mas o ALB target health timeout configurado é menor) | ALB → CloudFront |
| 2026-05-02 01:21 → 01:32 | Auto-recuperação parcial conforme bot reduz pace e workers terminam termos com `request_terminate_timeout=30s` (config v1.10.0+) | FPM |
| 2026-05-02 01:32 | TTFB volta ao normal (~150ms no cache hit) | — |

### Por que não foi detectado em tempo real

1. Não há alarme CloudWatch para `HTTPCode_ELB_5XX_Count` deste target group
2. O alarme `CloudFront 5XX Rate Critical` (rule_id 10) tem threshold 10% por 600s; a janela de 11 minutos com 502 esporádico ficou abaixo
3. FPM `slowlog` não está habilitado no pool (`request_slowlog_timeout` não setado)
4. O painel BIT Monitoring (PRs #129-137) tem o site mas sem rule por instância para esta família de evento

---

## Causas-raiz

Treze itens descobertos pelos dez agentes, ordenados por impacto na cascata:

### 1. Redis efetivamente desativado (impacto CRÍTICO)

- `WP_REDIS_PREFIX` em `wp-config.php` produção contém `'hml:'` (drift de configuração — provavelmente ficou de uma operação de import/export entre HML e PROD em data anterior).
- O drop-in `object-cache.php` em `wp-content/object-cache.php` está **AUSENTE**. `wp_using_ext_object_cache()` retorna `false`.
- Plugin `redis-cache 2.7.0` aparece como **active** no `wp_options` mas é inerte sem o drop-in.
- `redis-cli -s /var/run/redis/redis-server.sock dbsize` retorna `0` (zero chaves).
- `redis-cli info clients` mostra `connected_clients:10` em três dias de uptime — sinal de que praticamente nenhuma página está populando ou lendo cache.
- **Consequência:** WordPress executa todas as queries do core (`wp_options`, `wp_postmeta`, `wp_posts`) contra Aurora a cada pageview, sem nenhum nível de cache de objetos. Em uma página com listing JetEngine de 12 itens, isso significa 76 queries diretas no banco em vez de zero a três (pós-cache hit).

Evidência:

```bash
# Verificar prefix
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo -u www-data wp --path=/var/www/concertacaoamazonia.com.br config get WP_REDIS_PREFIX"
# Saída atual: hml:

# Verificar drop-in
ssh concertacaoamazonia.com.br-prod-sa \
  "ls -la /var/www/concertacaoamazonia.com.br/wp-content/object-cache.php 2>&1"
# Saída atual: ls: cannot access ...: No such file or directory

# Verificar uso pelo WP
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo -u www-data wp --path=/var/www/concertacaoamazonia.com.br eval \
    'var_dump(wp_using_ext_object_cache());'"
# Saída atual: bool(false)
```

### 2. Page cache do WP Rocket vazio no momento (impacto ALTO)

Combinação tóxica de três fatores:

- `wp_options.wp_rocket_settings.purge_cron_interval = 10` (10h, padrão WP Rocket).
- `d4-cache-warmup.sh v1.2.0` existe em `/Users/dcambria/scripts/server-tools/v2/ec2-deploy/post-deploy/d4-cache-warmup.sh` mas **NÃO** está agendado em `crontab -u www-data -l`. Ele só é executado pelo `phase7-cutover.sh` em deploys blue-green.
- A fila de preload do Rocket (`wp_wpr_rocket_cache` table) tinha 29 URLs em status `pending` desde 2026-05-01 08:08 UTC quando o purge horário disparou às 22:30. O preload natural via `cron` do WP estava efetivamente parado.

**Consequência:** das 22:30 às 01:10 (2h40min), o servidor entregou cada pageview via PHP completo. O bot escolheu a janela perfeita para varrer.

### 3. N+1 query em listings JetEngine (impacto ALTO)

Listing 28187 ("Lista de Estudos Nova Espiral") é o widget que renderiza os pontos clicáveis da espiral. Análise via JetEngine `wp_jet_listing_query_log`:

- 12 itens listados → 76 queries totais por render
- 29 queries em `wp_postmeta` (uma por meta-key por item, sem batch via `update_meta_cache`)
- 15 queries em `wp_posts` (resolução de relacionamentos JetEngine)
- 32 queries diversas (taxonomies, WPML translations, auxiliares)
- 93% do tempo gasto é PHP puro (não SQL) — sinal de overhead em filtros aplicados em cada item, não de banco lento

Cada item passa por `apply_filters('translate_post_meta', ...)` do WPML, que por sua vez aciona o filtro `get_post_metadata` do WordPress core, encadeando 5 a 8 callbacks por chamada.

### 4. Memory por worker FPM = 245MB, não 50MB (impacto ALTO via miscalibragem)

Auditoria com `pmap -x $(pgrep -f 'php-fpm.*pool wordpress')` em três workers ativos:

| Worker PID | RSS (MB) | Heap PHP (MB) |
|------------|----------|---------------|
| 31472 | 248 | 132 |
| 31473 | 241 | 128 |
| 31481 | 245 | 130 |

Média 245MB. Briefing original do site (notas de capacity em `project_concertacao_prod_infra.md`) documentava 50MB/worker — número 5x menor que o real. Em t3.large com 7.6GB úteis para FPM (8GB total - 400MB SO):

- Limite teórico real: 7600 / 245 ≈ **31 workers**
- Limite seguro com headroom 25%: 7600 * 0.75 / 245 ≈ **23 workers**
- Configuração atual (`pm.max_children=20`): segura mas próxima do teto

**Consequência:** subir `max_children` arbitrariamente não é seguro. O número 100 que aparecia em algumas notas era baseado no briefing de 50MB e levaria a OOM reproduzível.

### 5. Bot fake-Firefox/142.0 + Chrome/147 família (impacto ALTO)

Análise dos UAs nos logs nginx das 24h precedentes:

| User-Agent (assinatura) | Reqs 01/05 | Reqs 02/05 | Comportamento |
|-------------------------|------------|------------|---------------|
| `Mozilla/5.0 (Windows NT 10.0; Win64; x64) ... Firefox/142.0` | 309 | 143 | Referer literal `https://concertacaoamazonia.com.br` (sem trailing slash) — assinatura forte de bot. Zero assets baixados (CSS, JS, imagens) — comportamento headless. |
| `Mozilla/5.0 ... Chrome/147.0.0.0 Safari/537.36` | 1.621 | (residual) | Mesmo padrão: zero assets, Referer literal sem path |
| `Mozilla/5.0 ... HeadlessChrome/146.0.7390.65 Safari/537.36` | (subset Chrome) | — | Versão Chrome confessadamente headless |

Versões impossíveis em 2026-05-02 (Firefox stable é 138, Chrome stable é 133). Família clara de bot único usando UA versions futurísticas.

A regra WAF `Block-AggressiveBots` (criada no incidente 2026-04-20) só pega 9 UAs literais (GPTBot, DataForSeoBot, AhrefsBot, SemrushBot, MJ12bot, Bytespider, Baiduspider, YandexImages, PetalBot). Não pega a família atual.

### 6. WAF `RateLimit-300-Block` usa `AggregateKeyType=IP` (impacto ALTO)

Inspeção via AWS CLI:

```bash
aws wafv2 get-web-acl --name ACL-WPAdminHML --scope CLOUDFRONT --id 05522267-513d-4346-8e56-ba18b11e950b \
  --region us-east-1 \
  --query 'WebACL.Rules[?Name==`RateLimit-300-Block`].Statement.RateBasedStatement' --output json
```

Retorna `AggregateKeyType: IP`. No contexto CloudFront, esse "IP" é o IP do **edge CF**, não o IP real do cliente — todos os clientes que chegam por uma mesma POP CloudFront são contabilizados juntos. A regra `RateLimit-WPLogin-POST` no mesmo WebACL já usa `AggregateKeyType: FORWARDED_IP` corretamente, mas a `RateLimit-300-Block` ficou esquecida no padrão genérico.

**Consequência:** a regra é praticamente inutilizada contra bots distribuídos. Para chegar a 300 reqs/5min de uma única POP CF, um único cliente precisaria fazer 1 req/seg sustentado contra essa POP — improvável e quase nunca acontece.

### 7. Redirects 301 caindo no PHP (impacto MÉDIO)

Análise dos logs:

- 6.8% do tráfego de 24h foi código 301 (462 redirects em 2 dias)
- 58% desses 301s foram de bots SEO (DotBot 69, BacklinksExtended 24, etc.)
- Cada 301 que cai no `/index.php` (porque o nginx não tem map de redirect estático) custa 3-16s de PHP-FPM:
  - 0.5s para WP carregar
  - 0.5s para WPML resolver linguagem
  - 1-15s para `redirect_canonical()` rodar (dependendo se trailing-slash, slug renomeado, post deletado)
- Estimativa: ~1.400 worker-seconds/dia desperdiçados em 301

Trailing-slash canonical é o caso mais frequente: `GET /editais` → `301 /editais/` passa por `redirect_canonical()` no PHP. Um `rewrite ^([^.]*[^/])$ $1/ permanent;` no nginx eliminaria.

### 8. TEC slug duplicado PT/EN (impacto BAIXO/MÉDIO)

WPML cadastrou tanto `/eventos-calendario/category/X` (slug EN) quanto `/eventos-calendario/categoria/X` (slug PT-BR) como rotas válidas no `wp_rewrite_rules`. Cada uma retorna 301 lento via PHP, contribuindo para o desperdício do item 7.

### 9. `_label` fora da cache key do CloudFront (impacto FUNCIONAL latente)

A cache policy do CF para `wp-includes/*` e `wp-content/*` (PR `feedback_cf_blue_green_cache_pollution.md` mantida) inclui `X-Test-Green` e parâmetros de filtro JSF, mas o parâmetro `_label` usado por widgets JetEngine não está na cache key. Diferentes labels servem mesma resposta cacheada → bug funcional latente, sem relação com o 502 desta noite, mas correção paralela é barata.

### 10. OPcache `file_cache` ausente (impacto MÉDIO)

`/etc/php/8.3/fpm/conf.d/10-opcache.ini` atual:

```ini
opcache.memory_consumption=256
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=10000
opcache.validate_timestamps=0
; file_cache não definido
```

Contradiz a recomendação registrada em `feedback_opcache_file_cache.md`. Sem `file_cache`, restart do FPM zera o bytecode cache → cold start de 10-30s no primeiro request a cada arquivo PHP. `memory_consumption=256` pode ser pequeno: site tem ~9.500 arquivos PHP indexados (incluindo Elementor, JetEngine, WPML, mu-plugins), cada um ocupando 32-64KB no OPcache. Estimativa: 256MB cobre 95% do hot path mas deixa 5% sendo recompilado a cada request.

### 11. Aurora db.t3.medium burstable (impacto BAIXO atual, RISCO oculto)

Aurora cluster está em uma instância da família `db.t3.medium` (2 vCPU, 4GB). CPU médio das últimas 7 dias: 12-18% (saudável). `CPUCreditBalance` em torno de 580/720 (saudável). Mas `t3.medium` é **burstable** — em sustentado >20% perde créditos e cai para baseline 20%. Sob a carga gerada pelo cenário deste incidente (Redis vazio + N+1), Aurora foi para 35-40% momentâneo, ainda dentro do envelope mas sem margem para um pico de tráfego real.

### 12. `pm=dynamic` com `start_servers=5, min_spare_servers=1` (impacto BAIXO)

Pool atual em `/etc/php/8.3/fpm/pool.d/wordpress.conf`:

```ini
pm = dynamic
pm.max_children = 20
pm.start_servers = 5
pm.min_spare_servers = 1
pm.max_spare_servers = 6
pm.max_requests = 200
request_terminate_timeout = 30
```

`min_spare_servers=1` é subdimensionado. Quando o tráfego sobe, o FPM mata workers ociosos agressivamente, e quando o pico chega ele precisa fork-startar workers — startup de PHP+autoload custa 200-500ms por worker. Aparece nos logs como `WARNING: [pool wordpress] seems busy (you may need to increase pm.start_servers, or pm.min/max_spare_servers)`.

### 13. Sem swap configurado (impacto BAIXO/RISCO)

`free -h` no servidor mostra `Swap: 0B 0B 0B`. Se `max_children` for elevado e workers passarem dos 245MB médios (ex.: Elementor editor com Beaver Builder ativo via JetSmartFilters), risco de OOM kill silencioso. Boa prática Linux moderna é manter `swap=4GB` mesmo com bastante RAM, como rede de segurança.

---

## Decisões e trade-offs

### Por que três tiers temporais

A pressão não é homogênea. Os Itens 1, 2 e 3 individualmente teriam evitado o 502 desta noite (qualquer um sozinho); juntos formam barreira muito mais robusta que itens posteriores. Endereçar tudo num único deploy aumenta superfície de risco e diminui janela de validação por item. A separação em tiers permite:

- **Tier IMEDIATO:** ações de alto impacto e baixo risco que podem rodar ainda hoje, sem deploy completo.
- **Tier ESTA SEMANA:** mudanças que precisam de janela de manutenção pequena (reload FPM, reload nginx) e validação tier-1 já concluída.
- **Tier MÉDIO PRAZO:** capacity changes (instance type, WAF Bot Control, observabilidade nova) que precisam de orçamento, COUNT mode antes de BLOCK, ou aprovação expandida.

### Por que NÃO subir `max_children` de imediato

O número correto sob 245MB/worker é 23 workers seguros, 31 teóricos. Subir para 30+ aumenta capacidade marginalmente mas não resolve a saturação que vem de **request lentos** (cada um custa 15-30s). A saída real é tornar requests rápidos via Itens 1, 2, 3 e 6. Após eles, eventualmente subir para 25 workers vira opção sensata, mas sem swap configurado é prematuro.

### Por que NÃO desligar WP Rocket purge_cron_interval ainda

`purge_cron_interval` existe para evitar que CSS/JS gerado fique stale por dias após mudanças invisíveis. 10h é agressivo demais; 24h ou 48h é razoável. Mas zero implica responsabilidade total do operador em invalidar manualmente após cada deploy, e o site já documenta deploys em que esse step foi esquecido (`feedback_filesystem_cache_post_deploy.md`). Solução é manter purge horário (ou aumentar para 24h) **com warmer em cron** (Item 2) — não desligar.

### Por que `nginx if + Referer` (Item 3) é solução temporária

Bloqueio por User-Agent regex em `nginx if` ou `map` é frágil: o operador do bot pode trocar a string em segundos. Mas trocar custa **algum** tempo, e nesse intervalo o site fica protegido. WAF Bot Control (Item 11) é a resposta estrutural — porém custa USD 15/mês, requer 7 dias em modo COUNT antes de BLOCK, e exige aprovação de orçamento. Item 3 é stop-gap de 24-72h até Item 11 entrar.

### Por que `update_meta_cache` em mu-plugin (Item 6) e não em filtro `pre_get_posts`

JetEngine renderiza cada item do listing por meio de um shortcode renderizado em loop, não via `WP_Query` direto. `pre_get_posts` opera no nível do query, não no nível do listing. A intervenção correta é hookar `jet-engine/listing/before-loop` (filtro do JetEngine) e chamar `update_post_meta_cache($ids)` com os IDs do batch atual. Isso é uma linha em mu-plugin novo, completamente reversível.

### Opção considerada e rejeitada: migrar listings para HTML estático no theme

Trade-off entre flexibilidade (cliente edita listing pela UI) e performance (HTML estático seria ordens de magnitude mais rápido). Cliente Uma Concertação Pela Amazônia tem editores não-técnicos atualizando os pontos da espiral mensalmente. Migrar para HTML estático destrói esse fluxo. Item 13 (surrogate cache Redis) preserva o fluxo de edição e captura ~100% do ganho.

### Opção considerada e rejeitada: usar FastCGI cache do nginx em vez de WP Rocket

FastCGI cache no nginx é mais rápido e mais simples que WP Rocket. Mas o site usa WP Rocket para outras funcionalidades (lazy load, defer JS, RUCSS pra CSS crítico). Migrar para FastCGI cache + manter WP Rocket apenas para outras funções é projeto de 1-2 semanas, com risco de regressão. Mantemos WP Rocket; investimento canaliza para warmer + bypass-nginx (já em prod desde v1.6.0).

---

## Plano de implementação

Cada item segue o mesmo template:

- **Pré-requisitos**
- **Comandos exatos** (idempotentes onde possível)
- **Validação** (como medir que o fix funcionou)
- **Rollback** (passos para reverter)
- **Janela de manutenção sugerida**

Comandos assumem:
- Diretório de trabalho: `/Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao`
- Alias SSH: `concertacaoamazonia.com.br-prod-sa`
- WP_ROOT: `/var/www/concertacaoamazonia.com.br`
- WEB_USER: `www-data`

---

### Reordenação pós-review (CORREÇÃO R5)

Os 5 reviewers identificaram que **Item 15 (CloudWatch alarms)** deveria estar no Tier IMEDIATO,
não em "1 mês". Sem alarme 5XX e baseline pré-fix, próximo incidente repete invisível.
Custo trivial (USD 1,50/mês). Fluxo recomendado:

```
Tier IMEDIATO ANTES de TUDO:
  1. Item 15a — alarme HTTPCode_ELB_5XX_Count (sub-conjunto do Item 15)
                 → criar baseline ANTES de mexer em código
  2. Item 1   — Redis prefix + drop-in           [JÁ APLICADO 2026-05-02 02:41]
  3. Item 3   — Nginx Referer block              [JÁ APLICADO 2026-05-02 02:40]
  4. Item 2   — Cron warmer (com flock!)
Tier ESTA SEMANA:
  5. Item 8   — Slowlog FPM (visibilidade)
  6. Item 15b — alarmes restantes (FPM, Aurora, load avg)
  7. Item 9   — OPcache file_cache (ANTES de Item 6 para evitar cold start)
  8. Item 6   — mu-plugin update_meta_cache (validar em HML primeiro)
  9. Item 7   — purge_cron 24h
  10. Item 5  — WAF FORWARDED_IP em COUNT
  11. Item 4  — trailing-slash com whitelist
Tier MÉDIO PRAZO:
  12. Item 10 — CF Function (com try/catch fail-open)
  13. Item 12 — map redirects estáticos
  14. Item 11 — Bot Control (avaliar necessidade após Items 3+10)
  15. Item 13 — Surrogate cache (avaliar após Tier 2)
  16. Item 14 — t3.xlarge (60d após Tier 2 com dados reais)
```

---

### Item 1 — Corrigir `WP_REDIS_PREFIX` hml→prd e ativar object-cache.php drop-in

**Tier:** IMEDIATO (HOJE)
**Impacto:** CRÍTICO — devolve 60-80% das queries ao cache de objetos
**Risco:** BAIXO
**Esforço:** 30 minutos

#### Pré-requisitos

- Plugin `redis-cache 2.7.0` ativo (já está)
- Redis local rodando em `/var/run/redis/redis-server.sock` (já está, `systemctl status redis-server`)
- Acesso SSH ao prod

#### Comandos

```bash
# 1. Backup do wp-config.php atual
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo cp /var/www/concertacaoamazonia.com.br/wp-config.php \
       /var/www/concertacaoamazonia.com.br/wp-config.php.bak-$(date +%Y%m%d-%H%M%S)"

# 2. Corrigir WP_REDIS_PREFIX no wp-config.php (de 'hml:' para 'prd:')
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo sed -i \"s/define( *'WP_REDIS_PREFIX', *'hml:' *);/define('WP_REDIS_PREFIX', 'prd:');/\" \
       /var/www/concertacaoamazonia.com.br/wp-config.php"

# 3. Ativar drop-in via plugin redis-cache (cria object-cache.php)
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo -u www-data wp --path=/var/www/concertacaoamazonia.com.br redis enable"

# 4. Verificar drop-in foi criado
ssh concertacaoamazonia.com.br-prod-sa \
  "ls -la /var/www/concertacaoamazonia.com.br/wp-content/object-cache.php"
# Esperado: arquivo presente, owned by www-data:www-data

# 5. Reload PHP-FPM (drop-in só é carregado em request novo)
ssh concertacaoamazonia.com.br-prod-sa "sudo systemctl reload php8.3-fpm"

# 6. Smoke: bater 3 URLs e ver Redis populado
ssh concertacaoamazonia.com.br-prod-sa \
  "for url in / /editais/ /eventos-calendario/; do \
     curl -s -o /dev/null -w '%{http_code} %{time_total}\\n' \
       https://concertacaoamazonia.com.br\$url; \
   done"

# 7. Confirmar DBSIZE > 0
ssh concertacaoamazonia.com.br-prod-sa \
  "redis-cli -s /var/run/redis/redis-server.sock dbsize"
# Esperado: > 50 chaves após 3 hits
```

#### Validação

- `wp eval 'var_dump(wp_using_ext_object_cache());'` retorna `bool(true)`
- `redis-cli dbsize` cresce continuamente conforme tráfego
- TTFB do segundo hit em uma página é >= 50% mais rápido que o primeiro hit (cache miss → cache hit)
- `wp redis status` retorna `Status: Connected`

#### Pós-aplicação obrigatória (CORREÇÃO REVIEW R2+R3)

```bash
# 1. Validar multisite blog 2 está populando cache também
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo redis-cli -s /run/redis/redis.sock -a \"\$REDIS_PASS\" --no-auth-warning \
    --scan --pattern 'prd:2:*' | head -5"
# Esperado: keys do blog 2 aparecem após hits em /cultura/*

# 2. Forçar regeneração de transients WPML (R3) — caches stale podem ter sido
# escritos no DB durante período sem object cache
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo -u www-data wp --path=/var/www/concertacaoamazonia.com.br transient delete --all"

# 3. Cleanup de keys órfãs `hml:*` (R2: memory leak silencioso)
# AGENDAR para D+30 (após confirmar zero regressão):
# /schedule remote agent: rodar em 2026-06-01
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo redis-cli -s /run/redis/redis.sock -a \"\$REDIS_PASS\" --no-auth-warning \
    --scan --pattern 'hml:*' | xargs -r sudo redis-cli -s /run/redis/redis.sock \
    -a \"\$REDIS_PASS\" --no-auth-warning del"
# Esperado: log de quantas keys foram deletadas (provavelmente 0 — Redis estava
# vazio quando aplicamos #1, mas pode haver keys recentes se HML compartilha Redis)
```

Métrica de baseline a coletar antes:

```bash
ssh concertacaoamazonia.com.br-prod-sa \
  "for i in 1 2 3; do \
     curl -s -o /dev/null -w 'TTFB cold: %{time_starttransfer}s\\n' \
       'https://concertacaoamazonia.com.br/?nocache=$RANDOM'; \
   done"
```

Esperado pós-fix: cold ~ atual, mas warm (segundo hit mesma URL) cai 60-80%.

#### Rollback

```bash
# Restaurar wp-config
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo cp /var/www/concertacaoamazonia.com.br/wp-config.php.bak-* \
       /var/www/concertacaoamazonia.com.br/wp-config.php"

# Desativar drop-in
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo -u www-data wp --path=/var/www/concertacaoamazonia.com.br redis disable"

# Reload FPM
ssh concertacaoamazonia.com.br-prod-sa "sudo systemctl reload php8.3-fpm"
```

#### Janela de manutenção

Não requer downtime. Pode ser executado a qualquer hora — drop-in é carregado em request novo, requests em flight não são afetados.

---

### Item 2 — Cron `d4-cache-warmup.sh` a cada 10h, 5 minutos após purge

**Tier:** IMEDIATO (HOJE)
**Impacto:** ALTO — fim da janela de cache frio horário
**Risco:** BAIXO
**Esforço:** 1 hora (instalação + 1 ciclo de validação)

#### Pré-requisitos

- Item 1 já em produção (sem object cache, warmer pode amplificar pressão se algo der errado)
- `d4-cache-warmup.sh v1.2.0` em `/Users/dcambria/scripts/server-tools/v2/ec2-deploy/post-deploy/d4-cache-warmup.sh` (já existe)
- Cópia do script no servidor em `/opt/deploy/d4-cache-warmup.sh` (instalada no provisionamento via post-deploy.sh)

#### Comandos

```bash
# 1. Verificar versão instalada no servidor
ssh concertacaoamazonia.com.br-prod-sa \
  "head -10 /opt/deploy/d4-cache-warmup.sh | grep Versão"
# Esperado: # Versão: 1.2.0

# 2. Identificar horário do purge_cron_interval
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo -u www-data wp --path=/var/www/concertacaoamazonia.com.br \
    eval 'echo wp_next_scheduled(\"rocket_purge_time_event\");'"
# Saída: timestamp Unix do próximo purge

# 3. Adicionar entrada no crontab do www-data
# Estratégia: rodar a cada 10h, sempre 5min após purge horário do Rocket.
# Cron 0 8,18,4 * * * (3 vezes ao dia, 8h/18h/4h BRT, 5min após purge típico)
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo -u www-data crontab -l 2>/dev/null > /tmp/cron.bak; \
   echo '5 8,18,4 * * * flock -n /var/lock/d4-warmup.lock /opt/deploy/d4-cache-warmup.sh --max=200 --pages-only \\
                       >> /var/log/d4-warmup.log 2>&1' \
   # CORREÇÃO REVIEW (R5): flock evita overlap catastrófico se warmup #N demora
   # >10h (job anterior trava). Sem flock, #N+1 inicia em paralelo dobrando carga
   # FPM. Idêntico ao cenário 2026-05-01 08:08-22:30 que congestionou preload.
     >> /tmp/cron.bak; \
   sudo -u www-data crontab /tmp/cron.bak; \
   rm /tmp/cron.bak"

# 4. Verificar crontab
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo -u www-data crontab -l | grep d4-cache-warmup"

# 5. Executar warmup manualmente para popular cache agora
ssh concertacaoamazonia.com.br-prod-sa \
  "/opt/deploy/d4-cache-warmup.sh --max=200 --pages-only"
```

#### Validação

```bash
# Verificar arquivos de cache HTML gerados
ssh concertacaoamazonia.com.br-prod-sa \
  "find /var/www/concertacaoamazonia.com.br/wp-content/cache/wp-rocket/ \
       -name 'index-https.html' -mmin -10 | wc -l"
# Esperado: > 50 arquivos novos

# Smoke: hits cold devem servir do cache
ssh concertacaoamazonia.com.br-prod-sa \
  "curl -s -o /dev/null -w 'TTFB: %{time_starttransfer}s\\n' \
    https://concertacaoamazonia.com.br/"
# Esperado: TTFB < 0.05s (servido do disco via WP Rocket nginx bypass)

# Aguardar próximo purge horário e ver se warmer roda 5min depois
ssh concertacaoamazonia.com.br-prod-sa \
  "tail -50 /var/log/d4-warmup.log"
```

#### Rollback

```bash
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo -u www-data crontab -l | grep -v d4-cache-warmup | sudo -u www-data crontab -"
```

#### Janela de manutenção

Não requer downtime. Warmer faz curl localhost — não impacta tráfego externo. Custo de execução: 200 requests sequenciais, ~5min de uso de FPM em baixa concorrência (1 req por vez).

---

### Item 3 — nginx: bloquear Referer literal sem path → 444

**Tier:** IMEDIATO (HOJE)
**Impacto:** ALTO — neutraliza bot atual
**Risco:** ZERO (regra cirúrgica, traffic legítimo NUNCA tem Referer literal sem path)
**Esforço:** 30 minutos

#### Pré-requisitos

- Acesso SSH ao prod
- Capacidade de reload nginx

#### Comandos

```bash
# 1. Backup do site config
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo cp /etc/nginx/sites-available/concertacaoamazonia.com.br \
       /etc/nginx/sites-available/concertacaoamazonia.com.br.bak-$(date +%Y%m%d-%H%M%S)"

# 2. Adicionar map no nginx.conf (idempotente)
ssh concertacaoamazonia.com.br-prod-sa \
  "if ! sudo grep -q '\\\$is_referer_self_naked' /etc/nginx/nginx.conf; then \
     sudo tee -a /etc/nginx/conf.d/anti-bot-referer.conf > /dev/null <<'EOF'
# Bloqueio cirúrgico: Referer literal == origem sem path/trailing-slash
# Tráfego legítimo NUNCA envia esse Referer — só bots que setaram literal o domínio.
# Incidente 2026-05-02 (FPM saturation 502).
map \$http_referer \$is_referer_self_naked {
    default                                            0;
    \"https://concertacaoamazonia.com.br\"             1;
    \"http://concertacaoamazonia.com.br\"              1;
    \"https://www.concertacaoamazonia.com.br\"         1;
}
EOF
   else \
     echo 'Map já existe — pulando.'; \
   fi"

# 3. Adicionar bloqueio no location / do site
# CUIDADO: editar dentro do server block existente, antes do try_files.
# Não usar sed para inserir multilinha — preferimos editor controlado.
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo cp /etc/nginx/sites-available/concertacaoamazonia.com.br /tmp/site.conf.tmp; \
   sudo awk '/limit_req zone=cf_crawlers/ && !inserted { \
     print \"        if (\\\$is_referer_self_naked = 1) { return 444; }\"; \
     inserted=1 \
   } 1' /tmp/site.conf.tmp | sudo tee /etc/nginx/sites-available/concertacaoamazonia.com.br > /dev/null"

# 4. Test syntax
ssh concertacaoamazonia.com.br-prod-sa "sudo nginx -t"

# 5. Reload
ssh concertacaoamazonia.com.br-prod-sa "sudo systemctl reload nginx"

# 6. Smoke: bot fingerprint deve receber 444 (conexão fechada)
ssh concertacaoamazonia.com.br-prod-sa \
  "curl -s -o /dev/null -w '%{http_code}\\n' \
     -H 'Referer: https://concertacaoamazonia.com.br' \
     http://localhost/"
# Esperado: vazio ou erro de conexão (444 fecha TCP)

# 7. Smoke: usuário legítimo (sem Referer ou Referer com path) recebe 200
ssh concertacaoamazonia.com.br-prod-sa \
  "curl -s -o /dev/null -w '%{http_code}\\n' http://localhost/"
ssh concertacaoamazonia.com.br-prod-sa \
  "curl -s -o /dev/null -w '%{http_code}\\n' \
     -H 'Referer: https://concertacaoamazonia.com.br/editais/' \
     http://localhost/"
# Esperado: 200 e 200
```

#### Validação

Após 1h em produção:

```bash
# Contar 444 nos últimos 60min
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo awk -v start=\"\$(date -d '60 min ago' '+%d/%b/%Y:%H:%M:%S')\" \
       '\$0 > start && / 444 / { count++ } END { print count }' \
       /var/log/nginx/access.log"
```

Esperado: > 50 reqs/h (a família de bot mantém pace; queda gradual conforme operador percebe e troca o UA).

#### Rollback

```bash
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo rm /etc/nginx/conf.d/anti-bot-referer.conf; \
   sudo cp /etc/nginx/sites-available/concertacaoamazonia.com.br.bak-* \
       /etc/nginx/sites-available/concertacaoamazonia.com.br; \
   sudo nginx -t && sudo systemctl reload nginx"
```

#### Janela de manutenção

`nginx -s reload` é zero-downtime. Pode executar a qualquer hora.

---

### Item 4 — nginx: trailing-slash redirect em `location /`

**Tier:** ESTA SEMANA
**Impacto:** ALTO — elimina ~24% dos 301s caindo no PHP
**Risco:** MÉDIO (regex incorreto pode quebrar URLs com extensão)
**Esforço:** 2 horas (incluindo validação contra URLs com `.html`/`.xml`/`.json`)

#### Pré-requisitos

- Item 3 em produção
- Lista de URLs do site para regression test (ex.: `wp-sitemap.xml`)

#### Comandos

```bash
# 1. Adicionar rewrite ANTES do try_files do WP Rocket bypass.
# Heurística do regex: paths que NÃO terminam em '/', NÃO contêm '.', e não são wp-admin/wp-json
# PR no 03-nginx-sites.sh (versionar como v1.14.0):

# Patch proposto (aplicar em dev, depois propagar via deploy):
#
#     location / {
#         if (\$deny_meta_html = 1) { return 429; }
#         if (\$is_referer_self_naked = 1) { return 444; }       # Item 3
#         limit_req zone=cf_crawlers burst=5 nodelay;
#
#         # NOVO Item 4 — trailing slash redirect (sem PHP)
#         # CORREÇÃO REVIEW (R1+R2+R3): regex original quebrava OAuth callbacks,
#         # webhooks (POST 301→GET perde body), wp-admin/wp-json/xmlrpc.
#         # Whitelist explícita ANTES do rewrite + audit obrigatório de slugs com ponto.
#         # Audit em prod: `wp post list --post_status=publish --fields=ID,post_name | grep -E '\\.[a-z0-9]+'`
#         set \$skip_trailing 0;
#         if (\$request_uri ~ ^/(wp-admin|wp-json|wp-login\\.php|xmlrpc\\.php|oauth|webhook|callback|\\.well-known)) {
#             set \$skip_trailing 1;
#         }
#         if (\$request_method !~ ^(GET|HEAD)$) {  # POST/PUT/DELETE: nunca redirect (perde body)
#             set \$skip_trailing 1;
#         }
#         if (\$skip_trailing = 0) {
#             rewrite ^([^.]*[^/])$ \$1/ permanent;
#         }
#
#         try_files \$uri \$rocket_root_cache \$rocket_cache_file \$uri/ /index.php?\$args;
#     }

# 2. Aplicar patch em dev primeiro
# (ver bumpa do 03-nginx-sites.sh para v1.14.0 num commit dedicado)

# 3. Testar localmente em dev (Docker)
cd /Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao
std restart
curl -s -o /dev/null -w '%{http_code}\\n' https://cambrasmax.local:8484/editais
# Esperado: 301
curl -s -o /dev/null -w '%{http_code} %{redirect_url}\\n' \
  -L https://cambrasmax.local:8484/editais
# Esperado: termina em 200 https://cambrasmax.local:8484/editais/

# 4. Test regression (URLs com extensão NÃO devem redirect)
curl -s -o /dev/null -w '%{http_code}\\n' https://cambrasmax.local:8484/wp-sitemap.xml
# Esperado: 200 (NÃO 301)
curl -s -o /dev/null -w '%{http_code}\\n' https://cambrasmax.local:8484/feed
# Esperado: 301 → /feed/ (correto, é canonical do WP)

# 5. Após validação em dev, propagar via deploy ec2-deploy
```

#### Validação

```bash
# Contar 301s da home antes e depois do deploy
# Antes:
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo awk '/ 301 / && /index.php/ { count++ } END { print count }' /var/log/nginx/access.log"
# Pós-deploy 24h:
# Esperado: queda > 50% nos 301s, com nginx fazendo o redirect direto (sem PHP)

# Verificar via tail que rewrite aparece nos logs nginx (não no PHP-FPM)
ssh concertacaoamazonia.com.br-prod-sa \
  "tail -100 /var/log/nginx/access.log | awk '\$9 == 301 { print \$7 }' | head"
```

#### Rollback

Reverter commit no `03-nginx-sites.sh`, redeployar via post-deploy padrão. Janela de risco: rewrite que quebrou URL legítima — rollback resolve em 5min.

#### Janela de manutenção

Deploy normal de site config (reload nginx). Zero-downtime.

---

### Item 5 — WAF: corrigir `RateLimit-300-Block` para `FORWARDED_IP` + COUNT 7d antes de BLOCK

**Tier:** ESTA SEMANA
**Impacto:** ALTO — recupera proteção contra bots distribuídos via CF
**Risco:** MÉDIO (rule mal calibrada pode bloquear traffic legítimo de redes corporativas com NAT)
**Esforço:** 1h de implementação + 7 dias COUNT mode + revisão para BLOCK

#### Pré-requisitos

- Acesso AWS WAF us-east-1
- WebACL `ACL-WPAdminHML` (id `05522267-513d-4346-8e56-ba18b11e950b`)

#### Comandos

```bash
# 1. Capturar config atual da rule
aws wafv2 get-web-acl \
  --name ACL-WPAdminHML --scope CLOUDFRONT \
  --id 05522267-513d-4346-8e56-ba18b11e950b \
  --region us-east-1 \
  --profile Concertação \
  > /tmp/webacl-current.json

# 2. Editar tmp/webacl-current.json:
#    - Localizar rule "RateLimit-300-Block"
#    - Mudar Action de "Block" para "Count" (apenas para fase COUNT 7d)
#    - Mudar AggregateKeyType de "IP" para "FORWARDED_IP"
#    - Adicionar ForwardedIPConfig: { HeaderName: "X-Forwarded-For", FallbackBehavior: "NO_MATCH" }
#    - CORREÇÃO REVIEW (R2+R5): default DEVE ser NO_MATCH. MATCH bloqueia health checks
#      ALB sem XFF e qualquer request bypass do CloudFront. NO_MATCH é fail-open seguro.
#
# Esperado JSON após edição (trecho da rule):
#
#   {
#     "Name": "RateLimit-300-Block",
#     "Priority": 4,
#     "Action": { "Count": {} },
#     "Statement": {
#       "RateBasedStatement": {
#         "Limit": 300,
#         "AggregateKeyType": "FORWARDED_IP",
#         "ForwardedIPConfig": {
#           "HeaderName": "X-Forwarded-For",
#           "FallbackBehavior": "NO_MATCH"
#         }
#       }
#     },
#     "VisibilityConfig": { ... }
#   }

# 3. Aplicar update (precisa do LockToken)
LOCK_TOKEN=$(jq -r '.LockToken' /tmp/webacl-current.json)
aws wafv2 update-web-acl \
  --name ACL-WPAdminHML --scope CLOUDFRONT \
  --id 05522267-513d-4346-8e56-ba18b11e950b \
  --region us-east-1 \
  --profile Concertação \
  --lock-token "$LOCK_TOKEN" \
  --default-action '{"Allow":{}}' \
  --visibility-config '{"SampledRequestsEnabled":true,"CloudWatchMetricsEnabled":true,"MetricName":"ACL-WPAdminHML"}' \
  --rules file:///tmp/webacl-current-rules.json

# 4. Validar via console: WAF → Sampled Requests → ver hits da regra em modo COUNT
```

Aguardar 7 dias em modo COUNT. Inspecionar `SampledRequests`:

```bash
aws wafv2 get-sampled-requests \
  --web-acl-arn "arn:aws:wafv2:us-east-1:ACCOUNT:global/webacl/ACL-WPAdminHML/05522267-513d-4346-8e56-ba18b11e950b" \
  --rule-metric-name "RateLimit-300-Block" \
  --scope CLOUDFRONT \
  --time-window "StartTime=$(date -d '7 days ago' --iso-8601=seconds),EndTime=$(date --iso-8601=seconds)" \
  --max-items 100 \
  --region us-east-1
```

Verificar:
- Os IPs marcados pela regra são realmente bots (não usuários legítimos com NAT corporativo)
- Volume de matches está em linha com expectativa (não disparando 1000+ matches/dia em IPs legítimos)

Após 7 dias com false-positive rate baixo, mudar `Action` de `Count` para `Block`.

#### Validação

```bash
# Em modo BLOCK, ver hits sendo bloqueados:
aws cloudwatch get-metric-statistics \
  --namespace AWS/WAFV2 \
  --metric-name BlockedRequests \
  --dimensions Name=Rule,Value=RateLimit-300-Block Name=WebACL,Value=ACL-WPAdminHML Name=Region,Value=CloudFront \
  --start-time "$(date -d '24 hours ago' --iso-8601)" \
  --end-time "$(date --iso-8601)" \
  --period 3600 \
  --statistics Sum \
  --region us-east-1
```

#### Rollback

Editar webacl JSON: voltar `Action: { Block: {} }` para `Count`, ou reverter `AggregateKeyType` para `IP`. Apply via `update-web-acl`. Tempo: 5min.

#### Janela de manutenção

WAF updates são zero-downtime. Sem janela.

---

### Item 6 — mu-plugin: `update_meta_cache` em batch para listings JetEngine

**Tier:** ESTA SEMANA
**Impacto:** ALTO — corta ~50% das queries N+1 do listing 28187
**Risco:** BAIXO (filter aditivo, não muda fluxo principal)
**Esforço:** 4 horas (criação, teste em dev, deploy)

#### Pré-requisitos

- Item 1 em produção (sem object cache, o batch tem ganho menor)
- Identificação dos hooks de JetEngine para "before render listing"

#### Implementação

Criar mu-plugin novo em `/Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao/wordpress/wp-content/mu-plugins/bit-jetengine-meta-cache.php`:

```php
<?php
/**
 * Plugin Name: BIT JetEngine Meta Cache (batch update)
 * Description: Pré-aquece o post_meta cache antes de cada listing JetEngine
 *              para evitar N+1 query (1 query por meta-key por item).
 * Author:      Bureau de Tecnologia
 * Version:     1.0.0
 *
 * Incidente referente: 2026-05-02 spiral 502 saturation.
 * Ref: docs/superpowers/specs/2026-05-02-spiral-502-mitigation.md (Item 6)
 */

if (!defined('ABSPATH')) {
    exit;
}

// CORREÇÃO REVIEW (R1+R3): hook correto é `jet-engine/listing/query/items`
// (validado em wordpress/wp-content/plugins/jet-engine/includes/components/listings/render/listing-grid.php).
// O hook anterior `jet-engine/listing/grid/posts` NÃO existe — bug silent no-op.
add_filter('jet-engine/listing/query/items', function ($query, $settings = null, $render = null) {
    if (empty($query) || !is_array($query)) {
        return $query;
    }

    $ids = array_map(static function ($p) {
        if (is_object($p) && isset($p->ID)) {
            return (int) $p->ID;
        }
        if (is_array($p) && isset($p['ID'])) {
            return (int) $p['ID'];
        }
        if (is_numeric($p)) {
            return (int) $p;
        }
        return 0;
    }, $query);

    $ids = array_filter($ids);

    // Circuit breaker (R2): listings com >100 IDs viram potencial OOM. Limit a 100.
    if (count($ids) > 100) {
        return $query;
    }

    if (count($ids) > 1) {
        // Batch update_meta_cache: 1 query no lugar de N
        update_meta_cache('post', $ids);
    }

    return $query;
}, 10, 3);

// LIMITAÇÃO documentada: este filter é INERTE para JetEngine CCT
// (`wp_jet_cct_*`), que usa tabela própria sem `wp_postmeta`. Se um listing
// migrar para CCT no futuro, este mu-plugin deixa de surtir efeito sem alarme.
```

#### Comandos

```bash
# 1. Criar arquivo em dev
cd /Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao

# (Editar wordpress/wp-content/mu-plugins/bit-jetengine-meta-cache.php conforme spec acima)

# 2. Copiar para repositório canônico (regra do CLAUDE.md)
cp wordpress/wp-content/mu-plugins/bit-jetengine-meta-cache.php \
   /Users/dcambria/scripts/server-tools/v2/docker-dev/common/mu-plugins/

# 3. Validar em dev
std cache-flush
std wp eval 'do_action("init"); echo wp_using_ext_object_cache() ? "cache ok" : "no cache";'

# 4. Smoke: abrir página da espiral em dev e verificar query count
# (instalar Query Monitor temporariamente, ou usar SAVEQUERIES)
std wp config set SAVEQUERIES true
# Abrir https://cambrasmax.local:8484/ e contar queries antes/depois
# Esperado: queda de 76 para ~30 queries
std wp config delete SAVEQUERIES

# 5. Deploy em prod (rsync mu-plugin + reload FPM)
rsync -avz wordpress/wp-content/mu-plugins/bit-jetengine-meta-cache.php \
  concertacaoamazonia.com.br-prod-sa:/var/www/concertacaoamazonia.com.br/wp-content/mu-plugins/
ssh concertacaoamazonia.com.br-prod-sa "sudo systemctl reload php8.3-fpm"

# 6. Cirurgia: invalidar cache da home apenas
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo -u www-data wp --path=/var/www/concertacaoamazonia.com.br \
    rocket_clean_post --post_id=$(sudo -u www-data wp --path=/var/www/concertacaoamazonia.com.br \
      eval 'echo get_option(\"page_on_front\");')"
```

#### Validação

Em dev (com Query Monitor):

```bash
# Abrir Query Monitor antes do fix: 76 queries
# Após fix: ~30 queries
# Tempo de execução: -50% mínimo na home
```

Em prod (sem Query Monitor — slowlog):

```bash
# Após habilitar slowlog (Item 8), comparar tempos da home antes/depois
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo grep 'wp-rocket' /var/log/php8.3-fpm-slow.log | tail -20"
```

#### Rollback

```bash
# Remover mu-plugin
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo rm /var/www/concertacaoamazonia.com.br/wp-content/mu-plugins/bit-jetengine-meta-cache.php"
ssh concertacaoamazonia.com.br-prod-sa "sudo systemctl reload php8.3-fpm"
```

#### Janela de manutenção

`reload` zero-downtime. Mu-plugin é aditivo — se filter falhar, posts originais retornam intactos.

---

### Item 7 — Reduzir `purge_cron_interval` 10h → 24h

**Tier:** ESTA SEMANA
**Impacto:** MÉDIO — reduz frequência de cold cache windows de 2.4/dia para 1/dia
**Risco:** BAIXO (cliente pode reportar conteúdo CSS/JS stale)
**Esforço:** 30 minutos

#### Pré-requisitos

- Item 2 em produção (warmer agendado)

#### Comandos

```bash
# 1. Verificar config atual
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo -u www-data wp --path=/var/www/concertacaoamazonia.com.br \
    option pluck wp_rocket_settings purge_cron_interval"
# Esperado: 10

# 2. Mudar para 24 — CORREÇÃO REVIEW (R1): `wp option patch update` não dispara
# os hooks internos do WP Rocket que regeneram advanced-cache.php e config files.
# Usar `wp eval` com get_option/update_option + do_action para garantir consistência.
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo -u www-data wp --path=/var/www/concertacaoamazonia.com.br eval '
    \$opts = get_option(\"wp_rocket_settings\");
    \$old = \$opts[\"purge_cron_interval\"];
    \$opts[\"purge_cron_interval\"] = 24;
    update_option(\"wp_rocket_settings\", \$opts);
    do_action(\"update_option_wp_rocket_settings\", null, \$opts);
    echo \"purge_cron_interval: \$old -> 24\\n\";
  '"

# 2b. Regenerar advanced-cache.php e config para refletir a mudança
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo -u www-data wp --path=/var/www/concertacaoamazonia.com.br \
    rocket regenerate --file=advanced-cache && \
   sudo -u www-data wp --path=/var/www/concertacaoamazonia.com.br \
    rocket regenerate --file=config"

# 3. Mudar `purge_cron_unit` se necessário (deve ser 'HOUR_IN_SECONDS')
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo -u www-data wp --path=/var/www/concertacaoamazonia.com.br \
    option pluck wp_rocket_settings purge_cron_unit"
# Esperado: HOUR_IN_SECONDS

# 4. Atualizar cron entry do Item 2 para refletir purge 24h
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo -u www-data crontab -l | sed 's/0 8,18,4 \\* \\* \\*/5 8 * * */' | \
   sudo -u www-data crontab -"
```

#### Validação

```bash
# Verificar que purge_time_event foi reagendado para 24h ahead
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo -u www-data wp --path=/var/www/concertacaoamazonia.com.br \
    eval 'echo date(\"Y-m-d H:i:s\", wp_next_scheduled(\"rocket_purge_time_event\"));'"
```

#### Rollback

```bash
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo -u www-data wp --path=/var/www/concertacaoamazonia.com.br \
    option patch update wp_rocket_settings purge_cron_interval 10"
```

#### Janela de manutenção

Sem janela. Mudança apenas em `wp_options`.

---

### Item 8 — Slowlog FPM ativo (`request_slowlog_timeout=10s`)

**Tier:** ESTA SEMANA
**Impacto:** MÉDIO — visibilidade essencial para próximos incidentes
**Risco:** ZERO
**Esforço:** 30 minutos

#### Comandos

```bash
# 1. Backup do pool config
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo cp /etc/php/8.3/fpm/pool.d/wordpress.conf \
       /etc/php/8.3/fpm/pool.d/wordpress.conf.bak-$(date +%Y%m%d-%H%M%S)"

# 2. Adicionar slowlog (idempotente)
ssh concertacaoamazonia.com.br-prod-sa \
  "if ! sudo grep -q '^slowlog' /etc/php/8.3/fpm/pool.d/wordpress.conf; then \
     echo '
slowlog = /var/log/php8.3-fpm-slow.log
request_slowlog_timeout = 10s
request_slowlog_trace_depth = 30' \
     | sudo tee -a /etc/php/8.3/fpm/pool.d/wordpress.conf > /dev/null; \
   fi"

# 3. Criar arquivo log com permissões
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo touch /var/log/php8.3-fpm-slow.log; \
   sudo chown www-data:www-data /var/log/php8.3-fpm-slow.log; \
   sudo chmod 644 /var/log/php8.3-fpm-slow.log"

# 4. Logrotate para o slowlog
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo tee /etc/logrotate.d/php-fpm-slow > /dev/null <<'EOF'
/var/log/php8.3-fpm-slow.log {
    daily
    rotate 14
    missingok
    notifempty
    compress
    delaycompress
    sharedscripts
    postrotate
        systemctl reload php8.3-fpm > /dev/null 2>&1 || true
    endscript
}
EOF"

# 5. Reload FPM
ssh concertacaoamazonia.com.br-prod-sa "sudo systemctl reload php8.3-fpm"

# 6. Validar
ssh concertacaoamazonia.com.br-prod-sa "ls -la /var/log/php8.3-fpm-slow.log"
```

#### Validação

```bash
# Forçar request lento (debug intencional, depois remover)
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo -u www-data wp --path=/var/www/concertacaoamazonia.com.br \
    eval 'sleep(15); echo \"done\";' --quiet"

# Verificar que apareceu no slowlog
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo tail -50 /var/log/php8.3-fpm-slow.log"
```

#### Rollback

```bash
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo cp /etc/php/8.3/fpm/pool.d/wordpress.conf.bak-* \
       /etc/php/8.3/fpm/pool.d/wordpress.conf; \
   sudo systemctl reload php8.3-fpm"
```

#### Janela de manutenção

`reload` zero-downtime. Slowlog não impacta perf de requests rápidos (overhead < 1ms para checagem do timestamp inicial).

---

### Item 9 — OPcache `memory=384MB` + `file_cache` ativado

**Tier:** ESTA SEMANA
**Impacto:** MÉDIO — cold start de FPM 5x mais rápido pós-restart
**Risco:** BAIXO
**Esforço:** 30 minutos

#### Comandos

```bash
# 1. Backup
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo cp /etc/php/8.3/fpm/conf.d/10-opcache.ini \
       /etc/php/8.3/fpm/conf.d/10-opcache.ini.bak-$(date +%Y%m%d-%H%M%S)"

# 2. Criar diretório do file_cache
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo mkdir -p /var/cache/php-opcache; \
   sudo chown www-data:www-data /var/cache/php-opcache; \
   sudo chmod 750 /var/cache/php-opcache"

# 3. Atualizar config
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo tee /etc/php/8.3/fpm/conf.d/10-opcache.ini > /dev/null <<'EOF'
zend_extension=opcache.so

opcache.enable=1
opcache.enable_cli=0
opcache.memory_consumption=384
opcache.interned_strings_buffer=32
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0
opcache.revalidate_freq=0
opcache.fast_shutdown=1

; File cache: bytecode persistido em disco, sobrevive a restart
opcache.file_cache=/var/cache/php-opcache
opcache.file_cache_only=0
opcache.file_cache_consistency_checks=1
EOF"

# 4. Restart FPM (reload não recarrega .ini do OPcache)
# IMPORTANTE: usar `restart`, não `reload`. Aceitar 1-2s de gap.
ssh concertacaoamazonia.com.br-prod-sa "sudo systemctl restart php8.3-fpm"

# 5. Validar
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo -u www-data wp --path=/var/www/concertacaoamazonia.com.br \
    eval 'print_r(opcache_get_status()[\"memory_usage\"]);'"
# Esperado: used_memory ~ 100-150MB após warmup, free_memory ~ 234-284MB
```

#### Validação

```bash
# Verificar populated
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo find /var/cache/php-opcache/ -type f -name '*.bin' | wc -l"
# Esperado após 1h: > 1000 arquivos .bin
```

#### Rollback

```bash
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo cp /etc/php/8.3/fpm/conf.d/10-opcache.ini.bak-* \
       /etc/php/8.3/fpm/conf.d/10-opcache.ini; \
   sudo rm -rf /var/cache/php-opcache/*; \
   sudo systemctl restart php8.3-fpm"
```

#### Janela de manutenção

`restart` causa ~1-2s sem servir requests. Janela: madrugada (03:00-04:00 BRT) para mínimo impacto.

---

### Item 10 — CloudFront Function: regex UA versão futurística

**Tier:** PRÓXIMA SEMANA
**Impacto:** ALTO — pega família atual sem custo WAF
**Risco:** MÉDIO (regex muito amplo pode bloquear browser legítimo)
**Esforço:** 4 horas (escrita + 24h em modo "viewer-only logging" + cutover)

#### Implementação

Criar CloudFront Function (JavaScript runtime) em `viewer-request`:

```javascript
function handler(event) {
    var request = event.request;
    var ua = request.headers['user-agent'] && request.headers['user-agent'].value || '';

    // Regex: Firefox 142+ ou Chrome 147+ (versões impossíveis em 2026-05)
    // Atualizar limites a cada 6 meses (Firefox stable bumpa ~6 versões/ano).
    var firefoxFuture = /Firefox\/(1[4-9]\d|[2-9]\d\d)/.test(ua);
    var chromeFuture = /Chrome\/(1[4-9]\d|[2-9]\d\d)\.0\.0\.0/.test(ua);
    var headlessAny = /HeadlessChrome/.test(ua);

    if (firefoxFuture || chromeFuture || headlessAny) {
        return {
            statusCode: 403,
            statusDescription: 'Forbidden',
            headers: {
                'cache-control': { value: 'no-store' }
            }
        };
    }

    return request;
}
```

Deploy:

```bash
aws cloudfront create-function \
  --name BlockFutureBrowserUAs \
  --function-config Comment="Block bots with future UA versions",Runtime=cloudfront-js-2.0 \
  --function-code fileb:///path/to/handler.js \
  --region us-east-1 --profile Concertação

aws cloudfront publish-function \
  --name BlockFutureBrowserUAs \
  --if-match "$ETAG" \
  --region us-east-1

# Associar à distribuição E2F1QD7E7YOYEB no behavior default
# (via console: Behaviors → Default → Function associations → Viewer request → BlockFutureBrowserUAs)
```

#### Validação

```bash
# Smoke: simular bot fake-Firefox
curl -s -o /dev/null -w '%{http_code}\\n' \
  -H 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) Gecko/20100101 Firefox/142.0' \
  https://concertacaoamazonia.com.br/
# Esperado: 403

# Smoke: browser legítimo
curl -s -o /dev/null -w '%{http_code}\\n' \
  -H 'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/133.0.0.0 Safari/537.36' \
  https://concertacaoamazonia.com.br/
# Esperado: 200
```

#### Rollback

CloudFront → Behaviors → Default → Function associations → remover associação. Atualização propaga em ~2min.

#### Janela de manutenção

Sem janela. CloudFront Functions são deploy global em segundos.

---

### Item 11 — WAF Bot Control Common (modo COUNT 7d → BLOCK)

**Tier:** 2-3 SEMANAS (após orçamento aprovado)
**Impacto:** ALTO — proteção gerenciada continuamente atualizada pela AWS
**Risco:** MÉDIO (managed rules podem ter false positives em traffic legítimo)
**Esforço:** 4 horas (subscribe + COUNT 7 dias + revisão)

#### Custo estimado

- AWS Managed Rules — Bot Control Common: USD 10/mes (subscription) + USD 1,00 por **milhão** de requests inspecionados. Volume atual ~1,5M/mes (não 10M) → USD 10 + USD 1,50 = **USD 11,50/mes** (CORREÇÃO R4: spec original tinha pricing de 2022 desatualizado e volume superestimado)

#### Comandos

```bash
# 1. Adicionar managed rule group ao WebACL
# Via console (mais fácil que CLI nesse caso):
# WAF → Web ACLs → ACL-WPAdminHML → Rules → Add rules → Add managed rule groups
# → AWS Managed Rules → Bot Control - Common
# → Action: Count (modo de aprendizado)

# 2. Aguardar 7 dias e exportar Sampled Requests
aws wafv2 get-sampled-requests \
  --web-acl-arn "arn:aws:wafv2:us-east-1:ACCOUNT:global/webacl/ACL-WPAdminHML/05522267-513d-4346-8e56-ba18b11e950b" \
  --rule-metric-name "AWSManagedRulesBotControlRuleSet" \
  --scope CLOUDFRONT \
  --time-window "StartTime=$(date -d '7 days ago' --iso-8601=seconds),EndTime=$(date --iso-8601=seconds)" \
  --max-items 500 \
  --region us-east-1

# 3. Revisar amostras: confirmar que matches são bots reais (não usuários)
# 4. Mudar Action para Block via console
```

#### Validação

```bash
# Após BLOCK, métricas WAF
aws cloudwatch get-metric-statistics \
  --namespace AWS/WAFV2 \
  --metric-name BlockedRequests \
  --dimensions Name=Rule,Value=AWSManagedRulesBotControlRuleSet Name=WebACL,Value=ACL-WPAdminHML Name=Region,Value=CloudFront \
  --start-time "$(date -d '24 hours ago' --iso-8601)" \
  --end-time "$(date --iso-8601)" \
  --period 3600 \
  --statistics Sum \
  --region us-east-1
```

#### Rollback

WebACL → remover managed rule group. Imediato.

#### Janela de manutenção

Sem janela.

---

### Item 12 — Map nginx para top 5 slug-renamed redirects

**Tier:** 2 SEMANAS
**Impacto:** MÉDIO — elimina ~50 reqs/dia caindo no PHP via 301
**Risco:** BAIXO
**Esforço:** 2 horas

#### Comandos

Identificar top redirects via log:

```bash
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo awk '\$9 == 301 { print \$7 }' /var/log/nginx/access.log | \
       sort | uniq -c | sort -rn | head -20"
```

Para cada redirect identificado, adicionar map em `/etc/nginx/conf.d/redirects-static.conf`:

```nginx
map $request_uri $static_redirect {
    default                                              "";
    "~^/eventos-calendario/categoria/([^/]+)/?$"         /eventos-calendario/category/$1/;
    "~^/old-slug/?$"                                     /new-slug/;
    # ... top 5 conforme análise
}

server {
    # ... server existing ...
    if ($static_redirect != "") {
        return 301 $static_redirect;
    }
}
```

#### Validação

```bash
# Smoke
curl -s -o /dev/null -w '%{http_code} %{redirect_url}\\n' \
  https://concertacaoamazonia.com.br/eventos-calendario/categoria/teste/
# Esperado: 301 /eventos-calendario/category/teste/

# Verificar que é nginx fazendo (não PHP)
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo tail -100 /var/log/nginx/access.log | grep '/eventos-calendario/categoria/' | head"
# Esperado: status 301, sem upstream PHP
```

#### Rollback

```bash
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo rm /etc/nginx/conf.d/redirects-static.conf; sudo nginx -t && sudo systemctl reload nginx"
```

#### Janela de manutenção

`reload` zero-downtime.

---

### Item 13 — Surrogate cache Redis para listings JetEngine

**Tier:** 1 MÊS (mudança maior)
**Impacto:** ALTO — listing render direto do Redis (microssegundos)
**Risco:** MÉDIO (invalidação correta exige hooks completos: post update, term update, language switch)
**Esforço:** 2-3 dias (design + implementação + testes)

#### Visão de alto nível

Cachear o **HTML completo** do output de cada listing JetEngine em Redis com chave que considere:

- `listing_id`
- `query_args` hash (filtros JSF, paginação)
- `WPML language`
- `_label` (item 9 — corrigir cache key também)
- `is_user_logged_in`

Hook de invalidação em:
- `save_post` (post de qualquer post-type listado)
- `wp_update_term`
- `delete_post`
- `wpml_translation_update`

#### Implementação resumida

Criar mu-plugin `bit-jetengine-html-surrogate.php` (especificação detalhada em sub-spec separado, fora deste documento).

Trade-off documentado: ganho de perf vs. complexidade de invalidação. Validar em dev por 2 semanas antes de produção.

#### Validação

TTFB do widget após cache hit: < 50ms (vs. 800-1500ms atual).

#### Rollback

Desativar mu-plugin via `wp plugin deactivate` ou rename. Listings voltam ao comportamento atual.

#### Janela de manutenção

Reload FPM padrão.

---

### Item 14 — Upgrade EC2 t3.large → t3.xlarge

**Tier:** 1 MÊS (após Itens 1-13 estabilizados)
**Impacto:** ALTO — 4 vCPUs e 16GB resolvem CPU-bound + permitem `max_children=40`
**Risco:** MÉDIO (downtime de stop/start ~5min se feito sem blue-green; ou full deploy blue-green)
**Esforço:** 1-2h se via stop/start, 4-6h se via blue-green

#### Custo

- t3.large: USD 0.0832/h × 720h = ~USD 60/mês
- t3.xlarge: USD 0.1664/h × 720h = ~USD 120/mês
- Delta: USD 60/mês

#### Estratégia recomendada

Aproveitar próximo cycle de blue-green deploy (já documentado em `2026-04-02-blue-green-deploy-design.md`) e provisionar green com t3.xlarge desde o início. Cutover natural sem downtime adicional.

#### Validação

```bash
# Após cutover, verificar instance type
ssh concertacaoamazonia.com.br-prod-sa \
  "ec2-metadata --instance-type"
# Esperado: instance-type: t3.xlarge

# Recalibrar pool FPM:
# pm.max_children: 20 → 40
# pm.start_servers: 5 → 10
# pm.min_spare_servers: 1 → 5
# pm.max_spare_servers: 6 → 15
```

#### Rollback

Em blue-green, manter blue (t3.large) registrado no TG por 60 dias. Re-cutover em incidente.

#### Janela de manutenção

Cutover blue-green: ~10min, gerenciável em janela noturna.

---

### Item 15 — CloudWatch alarms: FPM saturação + Aurora CPU + Load avg

**Tier:** 1 MÊS
**Impacto:** MÉDIO — observabilidade essencial para evitar próximos incidentes
**Risco:** ZERO
**Esforço:** 4 horas

#### Métricas a adicionar via BIT Monitoring (`bit-monitoring/backend/scripts/create_cloudwatch_alert_rules.py`)

| Alarme | Threshold | Action |
|--------|-----------|--------|
| `EC2 LoadAvg 1min` (custom metric via OTel hostmetrics) | warning > 4 (2x vCPUs), critical > 8 | Notify Slack |
| `FPM Active Workers` (via fpm-status scrape) | warning > 70%, critical > 90% | Notify Slack |
| `Aurora DBClusterCpuUtilization` | warning > 60%, critical > 80% | Notify Slack |
| `Aurora CPUCreditBalance` | warning < 200, critical < 50 | Notify Slack |
| `EC2 SwapUsedBytes` (após Item habilitar swap) | warning > 1GB | Notify Slack |
| `ALB HTTPCode_ELB_5XX_Count` (target group concertacao-prod-tg) | warning > 5/5min, critical > 20/5min | Notify Slack |

#### Implementação

Adicionar entries em `bit-monitoring/backend/scripts/create_cloudwatch_alert_rules.py`. Configurar OTel Collector hostmetrics receiver para coletar load avg.

#### Validação

```bash
# Lista alarmes para a instância
aws cloudwatch describe-alarms \
  --alarm-name-prefix "concertacao-prod" \
  --region sa-east-1 --profile Concertação \
  --query 'MetricAlarms[].AlarmName'
```

#### Rollback

Remover alarms via console ou script.

#### Janela de manutenção

Sem janela.

---

## Métricas de validação

### Antes/depois por fix

| Métrica | Baseline (2026-05-02 01:30) | Pós-Tier-1 (Itens 1-3) | Pós-Tier-2 (Itens 4-9) | Pós-Tier-3 (Itens 10-15) |
|---------|----------------------------|------------------------|------------------------|--------------------------|
| TTFB cold home | 1.2-1.8s | 0.8-1.2s | 0.4-0.7s | 0.2-0.5s |
| TTFB warm home (cache hit) | 0.05s (quando existe) | 0.05s (sempre existe) | 0.05s | 0.05s |
| TTFB widget espiral cold | 8-15s | 4-8s | 1.5-3s | 0.3-0.5s (Item 13) |
| `redis-cli dbsize` | 0 | > 5.000 | > 10.000 | > 10.000 |
| Workers FPM ocupados (média 24h) | 8-12 | 4-7 | 2-4 | 2-4 |
| Workers FPM ocupados (p99 24h) | 20 (saturação) | 15-18 | 10-12 | 8-10 |
| ALB 5XX rate (24h) | ~1.5% no incidente | < 0.1% | < 0.05% | < 0.05% |
| Aurora CPU (média) | 12-18% | 8-12% | 4-8% | 4-8% |
| Aurora CPU (p99) | 35-40% | 20-25% | 10-15% | 10-15% |
| 301s/dia caindo no PHP | ~400 | ~400 (Item 4 ainda não) | < 50 | < 50 |
| 444s/h (bot rejection Item 3) | 0 | 50-150 | 50-100 | 0 (Item 11 absorve) |

### Smoke test pós-cada-tier

Após cada tier completar, executar:

```bash
cd /Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao
# Smoke do framework Bureau
./smoke/run.sh prod

# Comparar com baseline anterior
diff <(cat smoke/results/last.json) <(cat smoke/results/baseline.json)
```

---

## Riscos e mitigações

| Item | Risco | Mitigação |
|------|-------|-----------|
| 1 | Drop-in incompatível com WP version atual | Testar em dev primeiro; redis-cache 2.7.0 e WP 6.4+ são compatíveis. Rollback em 5min. |
| 1 | `WP_REDIS_PREFIX='prd:'` colide com instância HML que usa mesmo Redis | Verificar antes: `redis-cli keys 'prd:*' | head`. Se vazio, seguro. Caso contrário, escolher prefix único `concertacao_prod:`. |
| 2 | Warmer satura FPM | Script suporta `--max=200`; sequencial; 1 req/s aprox; testar em dev com `std`. |
| 3 | Map nginx pega usuário legítimo com Referer manualmente setado | Praticamente impossível: navegador não envia esse Referer literal sem path. Validação: 0 hits em 24h pré-deploy. |
| 4 | Rewrite quebra URL `/some.path` que tem ponto mas é page real | Regex `^([^.]*[^/])$` exclui qualquer URL com ponto. Mas "/2026.04" como slug quebra. Audit antes via `wp post list`. |
| 5 | FORWARDED_IP rate limit com FallbackBehavior=MATCH bloqueia tráfego sem XFF (raro mas existe) | Em CloudFront sempre vem XFF. Usar `FallbackBehavior: NO_MATCH` se incidente. |
| 6 | Hook `jet-engine/listing/grid/posts` mudou em versão nova do JetEngine | Validar nome do hook em dev antes; ler doc do JetEngine atual. |
| 7 | Cliente reclama de CSS stale após 24h | WP Rocket purge manual + `wp rocket clean --confirm` resolve em qualquer momento. |
| 8 | Slowlog com requests legítimos longos (export grande) | Threshold 10s é alto; export costuma ser < 30s. Tunar para 30s se ruído. |
| 9 | OPcache file_cache cheio → disk I/O de leitura | Monitor disk I/O após deploy; usar `du -sh /var/cache/php-opcache/` (esperado 100-200MB). |
| 10 | Regex CF Function bloqueia browser legítimo (Firefox 142+ existirá em 2027) | Atualizar regex semestralmente; documentar em runbook. |
| 11 | Bot Control bloqueia bot legítimo (Googlebot mal classificado) | 7 dias COUNT antes de BLOCK + revisão manual de samples. |
| 12 | Map redirect estático colide com slug renomeado novo | Audit `wp post list --post_status=publish --fields=ID,post_name`. |
| 13 | Invalidação errada → cache stale por horas | Validar 2 semanas em dev; cobrir todos hooks de mutação; fallback expira em 1h via TTL. |
| 14 | Cutover blue-green falha; downtime > 10min | Rollback documentado em `2026-04-02-blue-green-deploy-design.md` Fase de Rollback. |
| 15 | Alarme com false positive frequente → fadiga | Calibrar thresholds em fase COUNT/sem alerting; só ativar paging após 7 dias estáveis. |

---

## Custos estimados

### One-time

- Item 11 (WAF Bot Control): USD 0 (subscription gratuita; cobrança por uso)
- Item 14 (upgrade t3.xlarge): USD 0 (nova instância como green; blue desligada após 60 dias)
- Outros itens: USD 0 (mudanças de config)

### Mensais

| Item | Custo mensal | Observação |
|------|--------------|-----------|
| Item 10 (CloudFront Function) | USD 0.10 por milhão de invocações | Volume atual ~10M/mês = USD 1 |
| Item 11 (WAF Bot Control) | USD 10 + USD 1,00/M requests inspecionados | Volume atual ~1,5M/mês = USD 1,50; **total ~USD 11,50/mês** (CORREÇÃO R4) |
| Item 14 (t3.xlarge vs t3.large) | USD 60 delta | Necessário se Item 13 não cobrir saturação |
| Item 15 (CloudWatch alarms) | USD 0.10 por alarme/mês | 6 alarmes = USD 0.60 |
| **Total novo gasto mensal estimado** | **~USD 28-90** | Depende do Item 14 |

### Compensação

- Redução de ~60% no Aurora I/O reduz custo IO-Optimized da Aurora (estimado USD 5-10/mês de economia).
- Redução de FPM saturation evita potencial scaling vertical emergencial.

---

## Cronograma proposto

```
Tier IMEDIATO (HOJE — 2026-05-02 a 2026-05-03)
├── Item 1: Redis prefix + drop-in            [09:00 BRT]  (30min)
├── Item 2: Cron warmer                        [10:00 BRT]  (1h + observar 1 ciclo)
└── Item 3: Nginx Referer block                [11:30 BRT]  (30min)

Tier ESTA SEMANA (2026-05-04 a 2026-05-09)
├── Item 8: FPM slowlog                        [seg 2026-05-05]  (30min)
├── Item 9: OPcache file_cache                 [seg 2026-05-05]  (30min, restart FPM 04:00)
├── Item 7: purge_cron 24h                     [ter 2026-05-06]  (30min)
├── Item 4: nginx trailing-slash               [ter 2026-05-06]  (2h dev + deploy)
├── Item 5: WAF FORWARDED_IP COUNT mode        [qua 2026-05-07]  (1h + 7d aprendizado)
├── Item 6: mu-plugin update_meta_cache        [qui 2026-05-08]  (4h dev + deploy)

Tier MÉDIO PRAZO (2026-05-10 a 2026-06-02)
├── Item 10: CF Function future UA             [seg 2026-05-12]  (4h + 24h logging)
├── Item 5 BLOCK mode                          [qua 2026-05-14]  (após COUNT 7d)
├── Item 11: WAF Bot Control COUNT             [seg 2026-05-12]  (4h + 7d aprendizado)
├── Item 12: nginx static redirects            [qua 2026-05-14]  (2h)
├── Item 11 BLOCK mode                         [seg 2026-05-19]  (após COUNT 7d)
├── Item 15: CloudWatch alarms                 [qua 2026-05-21]  (4h)
├── Item 13: Redis surrogate cache             [seg 2026-05-26]  (2-3 dias dev)
└── Item 14: t3.xlarge via blue-green deploy   [seg 2026-06-02]  (4-6h cutover)
```

---

## Approval / sign-off

| Tier | Aprovador | Pré-condição |
|------|-----------|--------------|
| IMEDIATO (Itens 1, 2, 3) | Daniel Cambría | — |
| ESTA SEMANA (Itens 4-9) | Daniel Cambría | Tier IMEDIATO em produção e estável por 24h |
| MÉDIO PRAZO Itens 10-13, 15 | Daniel Cambría | Tier ESTA SEMANA em produção e estável por 7d |
| MÉDIO PRAZO Item 11 (Bot Control) | Daniel Cambría + orçamento | Aprovação budget USD 30/mes |
| MÉDIO PRAZO Item 14 (t3.xlarge) | Daniel Cambría + orçamento | Aprovação budget USD 60/mes; blue-green window agendada |

---

## Anexos

### Outputs dos 10 agentes da investigação

A serem materializados em `/Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao/docs/incidents/2026-05-02/`:

- `agent-01-redis-prefix-audit.md` — análise do drift `WP_REDIS_PREFIX=hml:` e drop-in ausente
- `agent-02-wp-rocket-cache-state.md` — análise do purge horário, fila de preload travada, ausência de warmer em cron
- `agent-03-jetengine-listing-28187-profiling.md` — query log do listing principal, evidência das 76 queries
- `agent-04-fpm-memory-pmap.md` — pmap dos workers, refutação da nota de 50MB, recálculo de capacity
- `agent-05-bot-fingerprint-analysis.md` — análise dos UAs fake-Firefox/142, Chrome/147, HeadlessChrome/146
- `agent-06-waf-rate-limit-aggregation-key.md` — auditoria do `RateLimit-300-Block` AggregateKeyType=IP
- `agent-07-redirect-301-cost-analysis.md` — quantificação dos 462 301s em 2 dias, 58% bots SEO
- `agent-08-tec-slug-duplication.md` — slug PT/EN duplicado em wp_rewrite_rules
- `agent-09-cf-cache-key-label-bug.md` — `_label` fora da cache key do CloudFront
- `agent-10-opcache-fpm-aurora-swap-baseline.md` — config atual de OPcache, FPM dynamic, Aurora burstable, sem swap

### Memos relacionados (já existentes)

- `feedback_meta_crawler_block.md` — incidente análogo 2026-05-01 com bot meta-externalagent (mitigação 03-nginx-sites.sh v1.12.2)
- `feedback_tec_crawler_traps.md` — incidente original 2026-04-08 + 2026-04-20
- `feedback_post_deploy_stability.md` — checklist de estabilidade pós-deploy
- `feedback_opcache_file_cache.md` — recomendação histórica de file_cache
- `feedback_surgical_cache_invalidation.md` — regra de invalidação cirúrgica em prod
- `feedback_redis_postmeta_invalidation.md` — invalidação correta de postmeta no Redis
- `project_concertacao_prod_infra.md` — infra atual (instance type, FPM, CF, S3)

### Arquivos relevantes citados

- `/Users/dcambria/scripts/server-tools/v2/ec2-deploy/post-deploy/03-nginx-sites.sh` v1.13.0 (defesas atuais nginx)
- `/Users/dcambria/scripts/server-tools/v2/ec2-deploy/post-deploy/d4-cache-warmup.sh` v1.2.0 (warmer já existente, falta cron)
- `/Users/dcambria/scripts/server-tools/v2/ec2-deploy/post-deploy/14-renew-all-caches.sh` (warmer alternativo top-N)
- `/Users/dcambria/scripts/server-tools/v2/docker-dev/common/mu-plugins/` (canonical de mu-plugins do server-tools)
- `/Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao/wordpress/wp-content/mu-plugins/` (mu-plugins do site)

---

## Status updates log

| Data | Ação | Operador | Resultado |
|------|------|----------|-----------|
| 2026-05-02 | Spec criado | Daniel Cambría | proposed |
| 2026-05-02 02:40 BRT | **Item 3 deployed** (Referer literal block) | Daniel Cambría | ✅ APLICADO — TEST 1 retorna 444; TEST 2/3 passam; auditoria 1h pós-aplicação: 1 hit 444 (curl validação), zero falsos-positivos (Pingdom/UptimeRobot/Slack/Discord/AMP não foram afetados) |
| 2026-05-02 02:41 BRT | **Item 1 deployed** (Redis prefix + drop-in) | Daniel Cambría | ✅ APLICADO — DBSIZE 0→2.897+ keys; TTFB cold espiral 13.7s→5.65s (-59%); TTFB warm 5-12ms; hit rate 35% inicial; Memória Redis 16MB/1.91GB |
| 2026-05-02 ~04:00 BRT | Spec revisado por 5 agentes | Reviewers 1-5 | 8 bugs críticos identificados; 3 reordenações; 5 melhorias de custo. Spec atualizado com correções (B1: hook `query/items`, B3: trailing-slash whitelist, B4: WP Rocket option patch via wp eval, B7: Bot Control USD 11,50/mês, B8: FallbackBehavior NO_MATCH, R5: cleanup keys `hml:*` em D+30, flock no cron warmer, Item 15 reordenado para Tier 1) |
| 2026-05-02 17:25 BRT | Slowlog FPM ativo (request_slowlog_timeout=5s) | Daniel Cambría | Item 8 aplicado — capturou cascata WPML+TEC Series Provider em 17 de 20 traces |
| 2026-05-02 ~21:00 BRT | Green isolada lançada (EC2 + Aurora restored from snapshot) | Daniel Cambría | Validação isolada do fix dedupe `previous_ecp_versions` — TTFB -62% confirmado, terminate pós-validação (USD ~1 custo total) |
| 2026-05-03 00:00 BRT | **NOVO ITEM 16: Dedupe `previous_ecp_versions` em prod** | Daniel Cambría | ✅ APLICADO — Option 2.28MB → 1.4KB (-99.9%). 103.917 → 11 entries. TTFB médio prod -52%. Espiral 10.7s→3.6s, /atuacao/grupos 18.6s→2.3s, /contato 13.1s→2.9s. Backup em /var/backups/tec-options/. mu-plugin defensivo bit-tec-versions-dedupe.php instalado em prod (hook pre_update_option_*) |
| _AAAA-MM-DD_ | Item 15a (alarme 5XX) deployed | _operador_ | _resultado_ |
| _AAAA-MM-DD_ | Item 2 deployed (com flock) | _operador_ | _resultado_ |
| _AAAA-MM-DD_ | Cleanup keys `hml:*` (D+30 = 2026-06-01) | _operador_ | _resultado_ |
| _AAAA-MM-DD_ | Tier 1 stable for 24h | _operador_ | go/no-go Tier 2 |
| _AAAA-MM-DD_ | Item 8 deployed | _operador_ | _resultado_ |
| _AAAA-MM-DD_ | Item 9 deployed | _operador_ | _resultado_ |
| _AAAA-MM-DD_ | Item 7 deployed | _operador_ | _resultado_ |
| _AAAA-MM-DD_ | Item 4 deployed | _operador_ | _resultado_ |
| _AAAA-MM-DD_ | Item 5 COUNT mode | _operador_ | _resultado_ |
| _AAAA-MM-DD_ | Item 6 deployed | _operador_ | _resultado_ |
| _AAAA-MM-DD_ | Tier 2 stable for 7d | _operador_ | go/no-go Tier 3 |
| _AAAA-MM-DD_ | Item 10 deployed | _operador_ | _resultado_ |
| _AAAA-MM-DD_ | Item 5 BLOCK mode | _operador_ | _resultado_ |
| _AAAA-MM-DD_ | Item 11 COUNT mode | _operador_ | _resultado_ |
| _AAAA-MM-DD_ | Item 12 deployed | _operador_ | _resultado_ |
| _AAAA-MM-DD_ | Item 11 BLOCK mode | _operador_ | _resultado_ |
| _AAAA-MM-DD_ | Item 15 deployed | _operador_ | _resultado_ |
| _AAAA-MM-DD_ | Item 13 deployed | _operador_ | _resultado_ |
| _AAAA-MM-DD_ | Item 14 cutover | _operador_ | _resultado_ |
| _AAAA-MM-DD_ | Postmortem retro 90 dias | Daniel Cambría | re-avaliar specs |

---

## Re-avaliação

**Quando:** 90 dias após Tier 3 completar (estimado 2026-09-01).
**Quem:** Daniel Cambría.
**Triggers automáticos antes do retro:**
- Reincidência de saturação FPM com perfil similar → abrir post-mortem dedicado.
- Item 11 (Bot Control) bloqueando > 1% de traffic legítimo → revisar imediatamente.
- Custo mensal AWS > USD 50 acima do baseline → revisar Item 11/14.

---

## Não-objetivos

- **Não cobrir** redesign do listing JetEngine 28187 em HTML estático (preserva fluxo de edição do cliente).
- **Não cobrir** migração para Aurora Serverless v2 (escopo separado, ROI a investigar).
- **Não cobrir** substituição WP Rocket por FastCGI cache (escopo separado).
- **Não cobrir** reescrita do mu-plugin `bit-elementor-espiral-widget.php` (a UX da espiral é gerenciada pelo cliente; otimização do widget vem em sub-spec separado).

---

## Referências

- Memo `feedback_meta_crawler_block.md` (incidente análogo 2026-05-01)
- Memo `feedback_tec_crawler_traps.md` (incidente origem 2026-04-08)
- Memo `feedback_post_deploy_stability.md` (checklist estabilidade)
- Memo `feedback_opcache_file_cache.md` (recomendação histórica)
- Memo `feedback_surgical_cache_invalidation.md` (regra de invalidação cirúrgica)
- Spec `2026-04-02-blue-green-deploy-design.md` (deploy blue-green para Item 14)
- Incidente `2026-04-20-tec-crawler-cloudfront-5xx.md` (incidente análogo, 03-nginx-sites.sh v1.7.0+)
- BIT Monitoring rule_id 10 (CloudFront 5XX Rate Critical)
- AWS WAF WebACL `ACL-WPAdminHML` (id `05522267-513d-4346-8e56-ba18b11e950b`, region us-east-1)
- CloudFront Distribution `E2F1QD7E7YOYEB`
- EC2 Instance `i-059febbd720286cd4` (t3.large, sa-east-1)
- Aurora cluster `amazonia-aurora-db-cluster.cluster-cbh7rhtadzwg.sa-east-1.rds.amazonaws.com`
