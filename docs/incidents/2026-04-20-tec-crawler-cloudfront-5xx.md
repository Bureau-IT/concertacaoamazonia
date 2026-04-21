# 2026-04-20 — CloudFront 5XX em concertacaoamazonia.com.br por crawlers TEC

**Severidade:** Critical
**Alerta:** BIT Monitoring rule_id 10 ("CloudFront 5XX Rate Critical", threshold 10%, duration 600s)
**Valor no pico:** 14.67% de 5xxErrorRate
**Duração:** ~2h13 (12:08 → 14:21 BRT)
**Autor:** Daniel Cambría

---

## Timeline

| Horário (BRT) | Evento |
|---|---|
| 12:08 | Alerta disparado (rule_id 10). Thiago Canani notifica. |
| 14:15 | Inicio do diagnóstico via SSH no origin (i-059febbd720286cd4). |
| 14:21 | Causa raiz identificada. Plano de mitigação montado. |
| 14:30 | Etapa 1.1 — `systemctl reload php8.3-fpm` (reciclagem de workers). |
| 14:32 | Etapa 1.2 — `robots.txt` físico criado com Disallows TEC. |
| 14:35 | Etapa 1.3 — WAF `Block-AggressiveBots` estendida com 4 UAs (Bytespider, Baiduspider, YandexImages, PetalBot). |
| 14:40 | Etapa 2.1 — FPM tuning: `max_children` 18→10, `request_terminate_timeout` 120s→30s, `max_requests` 500→200. |
| 14:42 | Etapa 2.2 — nginx `fastcgi_read_timeout` 60→20s (default public PHP location apenas). |
| 14:45 | Etapa 2.3 — map `$cleaned_args` acrescentado token `jet_download`. |
| 14:50 | Load médio do origin: 0.18 (antes: 18.22). CloudFront 5xxErrorRate: 0.00%. 499s no nginx no período: 0 (antes: 434 em 30 min). |

## Causa raiz

**Saturação de CPU no origin** causada por 19 workers PHP-FPM presos processando queries
lentas de `usort()` sobre `tribe_events` (TEC Month View / Week View), disparadas por
crawlers (Bytespider dominante, seguido de Baiduspider, YandexImages, PetalBot) batendo
em URLs com query strings TEC que burlavam o cache:

- `?tribe-bar-date=YYYY-MM-DD`
- `?eventDate=YYYY-MM-DD`
- `?eventDisplay=past`
- `?jet_download=...`

O CloudFront contabiliza origin timeouts como 5XX (mesmo quando a aplicação nunca retorna
um 5XX real — ALB `HTTPCode_Target_5XX_Count` = 3 no período, CloudFront reportou 14.67%).

### Por que quebrou desta vez

Das 4 camadas de defesa documentadas em memory `feedback_tec_crawler_traps.md`
(incidente original 2026-04-08):

| Camada | Estado em 2026-04-20 |
|---|---|
| 1. `$cleaned_args` strip no nginx | ✅ presente |
| 2. `$rocket_skip_reason` usando `$cleaned_args` | ✅ presente |
| 3. `robots.txt` com Disallows TEC | ❌ **AUSENTE** — WP gerava virtualmente, apenas `Disallow: /wp-admin/` |
| 4. FPM `max_children` ≈ 5/vCPU | ⚠️ **DEGRADADO** — 18 em 2 vCPUs = 9/vCPU |

Crawlers respeitam parcialmente robots.txt; mesmo com robots, alguns (Bytespider, Baidu)
exigem bloqueio explícito no WAF — que também estava faltando.

## Ações tomadas (permanentes)

1. **robots.txt físico** — `/var/www/concertacaoamazonia.com.br/robots.txt` criado com
   Disallows TEC + `jet_download`. Invalidação CloudFront: `I5VM2LX8DDH4UYYL06SF4ASV7I`.
2. **WAF `ACL-WPAdminHML` / rule `Block-AggressiveBots`** — acrescentados 4 ByteMatchStatement
   ao OrStatement existente: `Bytespider`, `Baiduspider`, `YandexImages`, `PetalBot`.
   UAs que já estavam bloqueados (preservados): GPTBot, DataForSeoBot, AhrefsBot, SemrushBot, MJ12bot.
3. **PHP-FPM pool** (`/etc/php/8.3/fpm/pool.d/wordpress.conf`):
   - `pm.max_children`: 18 → 10
   - `pm.start_servers`: 6 → 4
   - `pm.min_spare_servers`: 3 → 2
   - `pm.max_spare_servers`: 10 → 6
   - `pm.max_requests`: 500 → 200 (recicla mais rápido, libera memória)
   - `request_terminate_timeout`: 120s → 30s (mata worker preso rápido)
4. **nginx** (`/etc/nginx/sites-available/concertacaoamazonia.com.br`):
   - Location PHP default: `fastcgi_read_timeout` 60 → 20s, `fastcgi_send_timeout` 60 → 20s
   - Mantidos: wp-admin 120s, async-upload 300s.
5. **nginx map `$cleaned_args`** (`/etc/nginx/nginx.conf`): token `jet_download` acrescentado
   (já tinha `tribe-bar-date`, `eventDate`, `eventDisplay=past`, `ical`, `outlook-ical`, etc.)

Todos os arquivos têm backup `.bak-2026-04-20`.

## Métricas antes/depois

| Métrica | Antes | Depois |
|---|---|---|
| Load avg (1 min) | 18.22 | 0.18 |
| FPM workers em estado R | 19 (presos 37-42 min) | 5 (healthy) |
| 499 no nginx / 30 min | 434 | 0 |
| CloudFront 5xxErrorRate | 14.67% | 0.00% |
| curl -A Bytespider | HTTP/2 301 | HTTP/2 403 (WAF) |
| Browser normal | — | HTTP/2 301 (sem alteração) |

## Prevenção (pós-incidente)

### 1. Novos alertas no BIT Monitoring

Acrescentados em `bit-monitoring/backend/scripts/create_cloudwatch_alert_rules.py`:

- `CloudFront 4XX Rate High` — warning @ 5% (captura tempestades de crawler antes do 5XX)
- `CloudFront 4XX Rate Critical` — critical @ 20%
- `EC2 CPU High` — warning @ 80%, 10 min
- `EC2 CPU Critical` — critical @ 90%, 10 min

Load-avg não foi adicionado: a métrica ainda não é coletada pelo OTel Collector atual
(requer `hostmetrics` receiver com scraper `load`). Fica como débito técnico separado.

### 2. robots.txt como parte do deploy

TODO: atualizar `server-tools/v2/ec2-deploy/scripts/03-nginx-sites.sh` (ou script adjacente)
para gerar `robots.txt` com Disallows TEC no blue-green deploy. Sem isso, o próximo cutover
recria o problema.

### 3. Decisões confirmadas para manter

- **CloudFront cache policy:** manter `WP-Dynamic-ShortTTL-NoQS` com whitelist apenas para
  `jet_download` na cache key.
- **WP Rocket nginx bypass:** manter ativo (nginx serve HTML direto do disco).
- **FPM sizing:** ~5 workers por vCPU no t3.large (2 vCPUs → 10). Se subir a instância
  (ex: t3.xlarge), recalibrar proporcional.

## Checklist pré-próximo deploy

- [ ] Confirmar que `robots.txt` físico continua presente após o deploy
- [ ] Conferir `pm.max_children` em `/etc/php/8.3/fpm/pool.d/wordpress.conf` — não deixar voltar a 18
- [ ] Conferir `fastcgi_read_timeout` no site nginx — não deixar voltar a 60s
- [ ] Conferir WAF `Block-AggressiveBots` contém os 9 UAs (GPTBot, DataForSeoBot, AhrefsBot, SemrushBot, MJ12bot, Bytespider, Baiduspider, YandexImages, PetalBot)

## Referências

- Plan: `~/.claude/plans/monitoramento-apontou-falha-12-08-serene-sloth.md`
- Memory: `feedback_tec_crawler_traps.md`, `project_concertacao_prod_infra.md`
- WAF WebACL: `ACL-WPAdminHML` (CloudFront scope, us-east-1, id `05522267-513d-4346-8e56-ba18b11e950b`)
- Distribuição CloudFront: `E2F1QD7E7YOYEB`
- Instância EC2: `i-059febbd720286cd4` (t3.large, 2 vCPUs)
