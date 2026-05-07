# Plano de Ação — Mitigação de incidentes prod concertação (2026-05-06)

**Data:** 2026-05-06
**Status:** **rev3** — pós-auditoria Ciclo 2 (5 agentes) + meta-revisão (3 agentes); aguardando Ciclo 3
**Autor:** Daniel Cambría / Bureau de Tecnologia
**Cliente:** Uma Concertação Pela Amazônia
**Distribuição CloudFront:** `E2F1QD7E7YOYEB`
**Instância EC2:** `i-02b3a897d355953f3` (t3.large, sa-east-1a)

**Histórico de revisões:**
- rev1 (2026-05-06): draft inicial com 9 ações, 3 semanas, USD 17/mês, 14h eng
- rev2 (2026-05-06): pós-Ciclo 1 — 9 bugs corrigidos, cronograma 3 → 6 semanas, custos recalculados (USD 12.10/mês real), Ação 9 cortada, Ação 8 condicional ao AWS Activate Grant, escopo BIT-wide para Ações 1/3/4, runbooks individuais exigidos, snapshots pré-mudança WAF/CF obrigatórios
- **rev3 (2026-05-07):** pós-Ciclo 2 + meta-revisão — 3 bugs bloqueantes reais corrigidos
  (CRIT-1 flock inválido, CRIT-2 AWS CLI ausente, N3 janela noturna contraditória) + 2
  omissões recuperadas (N6 smoke baseline, CRIT-5 Ação 5 KB literal); banda USD 12-22 →
  **USD 12.10/mês** (sem cap inflado); R7 deputy CORTADO (ONG sem budget; Daniel opera
  solo sem incidente atribuível a bus factor); priorização "P×S>12" → "P×S≥12" (apenas
  R6/R7 são >12; R1/R3/R5/R8/R9 são =12); seção "Estado real do sistema" reconhece
  fixes já aplicados em prod desde 2026-05-02 (Item 16 dedupe TEC, mu-plugin v1.1.0,
  Referer regex v1.15.1, smoke 7.9). Mantém 6 semanas dado corte do R7.

---

## Sumário executivo

Auditoria de logs em 2026-05-06 (durante investigação de incidente FP do regex Referer)
revelou **incidente oculto não-detectado em 2026-05-05 03:09–03:26 BRT**: YandexBot saturou
FPM com 1.632 requests em 17 min em CPT JetEngine `/veiculo/*`, gerando 1.636 × 504
(gateway timeout). Não disparou alarme porque bit-monitoring ainda não tem regras 5XX
configuradas para concertação.

Combinado com pendências abertas do spec `2026-05-02-spiral-502-mitigation.md` (Tiers 2 e
3 não-aplicados), o sistema continua **vulnerável a saturação por crawlers agressivos**
sem detecção. Este plano propõe **9 ações priorizadas** organizadas em 3 tiers temporais
(P0 hoje, P1 esta semana, P2 esta quinzena), com defesas em camadas e observabilidade
proativa.

---

## Contexto

### Incidentes recentes

| # | Data | Vetor | Detecção |
|---|------|-------|----------|
| 1 | 2026-04-08 | TEC crawler traps (`tribe-bar-date`, `eventDate`, `ical`) | Manual via report user |
| 2 | 2026-05-01 | meta-externalagent saturou `/?jet_download` | Manual via investigação |
| 3 | 2026-05-02 01:10 BRT | Bot fake-Firefox/142 + cascata WPML+TEC Series + Redis drop-in ausente | User reportou 502 |
| 4 | 2026-05-04 (FP introduzido) | Bug regex `/?` bloqueou 292 reqs legítimas | User reportou 502 (2 dias depois) |
| 5 | **2026-05-05 03:09 BRT (OCULTO)** | **YandexBot 1.632 reqs/17min em /veiculo/* — 1.636 × 504** | **Auditoria retroativa, 1 dia depois** |

**Padrão recorrente:** crawlers agressivos saturam FPM em CPTs JetEngine pesados; alarmes
ausentes; detecção depende de report manual ou auditoria retroativa.

### Estado pós-fixes da espiral 502

| Sistema | Estado |
|---------|--------|
| Redis drop-in ativo | ✅ |
| WP_REDIS_PREFIX=`prd:` | ✅ |
| Slowlog FPM ativo | ✅ |
| TEC `previous_ecp_versions` deduplicado (11 entries) | ✅ |
| mu-plugin defensivo `bit-tec-versions-dedupe.php` v1.1.0 | ✅ |
| nginx Referer block (regex sem `/?`) | ✅ (corrigido 2026-05-06) |
| Cron warmer `d4-cache-warmup.sh` | ❌ |
| WAF FORWARDED_IP rate-limit | ❌ |
| WAF Bot Control | ❌ |
| Alarmes 5XX no bit-monitoring para concertação | ❌ |
| robots.txt Disallow para CPTs pesados (/veiculo, /eventos-calendario) | ❌ parcial |
| YandexBot bloqueado no nginx | ❌ |

---

## Decisões e premissas

1. **Escopo:** este plano cobre **8 ações** (Ação 9 cortada — ver Anexo B) — 4 pendências
   P0/P1 da auditoria 2026-05-06 + 4 itens prioritários do spec mitigation 2026-05-02.
2. **Janela:** **6 semanas calendar** (rev2). Capacidade Daniel ~50% (founder/CEO sem
   equipe ops). 14h eng "puras" → ~26h calendar realistas (Auditor 1.5).
3. **Risco:** zero downtime planejado. Janela noturna BRT REMOVIDA (rev2 — não necessária
   para os 8 itens; 1.5 confirmou).
4. **Generalização:** mitigações P0/P1 universais (Ações 1, 3, 4) **expandidas para escopo
   BIT-wide** (mombak, www-concertacao, cop30casamazonia) — Anexo A. Ações 2, 5, 6, 7, 8
   ficam concertação-only por enquanto.
5. **Custos** (rev2 corrigida pós-1.3):
   - Recorrente: ~USD 12.10/mês (não USD 17 de rev1) — Bot Control 10 + 1.50 inspeção +
     5 alarmes CW × 0.10 + WAF logs ~0.10
   - Compensação paralela (fora do escopo): Compute SP + Aurora RI = -USD 40/mês →
     **plano completo SE PAGA 2-3x sem alterar escopo**
   - **AWS Activate Imagine Grant ONG:** condição para Ação 8 (USD 1k-5k créditos)

---

## Plano de ação — 9 itens

### TIER P0 — HOJE (2-4h, zero downtime)

#### Ação 1 — Bloquear YandexBot no nginx (`03-nginx-sites.sh` v1.16.0)

**Impacto:** ALTO — incidente 2026-05-05 demonstrou vetor ativo
**Risco:** BAIXO (idêntico ao bloqueio meta-externalagent v1.12.2 já operacional há 5 dias)
**Esforço:** 30 min

**Comandos (rev2 — Auditor 1.1: combinar UA + path para liberar /uploads/):**
```bash
# Adicionar ao map nginx.conf (depois do deny_meta_html):
# Pattern combinado UA::URI — libera /wp-content/uploads/ para YandexImages
# (tráfego legítimo SEO de imagens). Mesmo padrão de deny_meta_html v1.12.2.
map "$http_user_agent::$request_uri" $deny_yandex_html {
    default 0;
    "~*YandexBot.*::(?!/wp-content/uploads/)" 1;
}

# Em location / do site config, ANTES do limit_req:
if ($deny_yandex_html = 1) { return 429; }
```

**Validação:**
```bash
ssh concertacaoamazonia.com.br-prod-sa "curl -s -o /dev/null -w '%{http_code}' \
  -H 'User-Agent: Mozilla/5.0 (compatible; YandexBot/3.0; +http://yandex.com/bots)' \
  -H 'Host: concertacaoamazonia.com.br' http://127.0.0.1/veiculo/sumauma/"
# Esperado: 429
```

**Rollback:** reverter v1.16.0 → v1.15.1 e reload nginx (1 min).

#### Ação 2 — robots.txt: Disallow `/veiculo/` + Crawl-delay para Yandex

**Impacto:** MÉDIO — Yandex/Bing/Google respeitam; crawlers maliciosos ignoram (mas Ação 1 cobre)
**Risco:** ZERO — robots.txt é diretiva, não bloqueio
**Esforço:** 15 min

**Comandos:**
- Editar geração de robots.txt em `03-nginx-sites.sh` (template idempotente v1.9.0; bumpar v1.10.0 + atualizar `ROBOTS_MARKER`)
- Adicionar (rev2 — Auditor 1.1: Yandex deprecou `Crawl-delay` em 2018, removido):
```
User-agent: *
Disallow: /veiculo/
```

**Atenção:** `/veiculo/*` é CPT JetEngine de **veículos de imprensa** — cliente pode querer
indexação Google em alguns.

**🚨 PRÉ-REQUISITO OBRIGATÓRIO (rev2 — Auditor 1.2):**
- Snapshot Search Console: páginas atualmente indexadas em `/veiculo/*` antes de aplicar
- Confirmação explícita do cliente sobre não-indexação SEO desses CPTs
- Sem essa validação, Ação 2 fica **bloqueada**

**Rollback:** reverter linhas em `03-nginx-sites.sh` e reload.

#### Ação 3 — Criar 5 alarmes essenciais no bit-monitoring para concertação

**Impacto:** CRÍTICO — sem isso próximos incidentes seguem invisíveis
**Risco:** BAIXO (apenas observabilidade, sem block)
**Esforço:** 30 min (rodar script existente + validar via UI)

**Procedimento (rev2 — Auditor 1.1):**
⚠️ **Atenção:** `create_cloudwatch_alert_rules.py` original cria **31 alarmes**, não 5.
Rodar o script inteiro gera ruído de RDS Storage/Swap/Disk Queue (que decidimos NÃO
implementar). Opções:

**Opção A (recomendada):** criar manualmente via UI dashboard, pulando alarmes não-aprovados.

**URL exata (rev3 — Auditor 2.3 CRIT-4):** descobrir via SSH na instância bit-monitoring
(`i-0f909f8f77776fcf0`) ou via DNS público — Daniel já tem credenciais admin. Caminho
após login: **Admin → Infra Alerts → Notification Rules → New Rule**.

- Criar 5 NotificationRules essenciais para resources concertação:
  1. `ALB Target 5XX > 50/5min` (critical) — pega cenário YandexBot
  2. `ALB Target 5XX > 10/5min` (warning) — pega bot moderado
  3. `ALB Response Time p95 > 8s` (warning) + `> 15s` (critical)
  4. `ALB Unhealthy Hosts > 0` (critical) — pega cutover blue-green com gap
  5. `RDS CPU > 70%` (warning) + `> 90%` (critical) — Aurora burstable

**Campos obrigatórios da UI (cada rule):**
- Trigger Type: `CLOUDWATCH_METRIC_THRESHOLD`
- Resource: filter por instance ID (`i-02b3a897d355953f3` apex prod) OU TG ARN
  (`concertacao-prod-tg`)
- Severity Filter: `["critical", "warning"]`
- Channel IDs: usar mesmo array do "Host Metric Alert" existente (copia channels)
- Cooldown: 15min

**Opção B:** patchar script para aceitar `--include="rule1,rule2,..."` flag.

**Não implementar (decisão consolidada com analista):**
- RDS Storage (Aurora auto-scale; métrica retorna 0)
- RDS Swap (baseline normal 230-340MB; thresholds default disparariam constantemente)
- RDS Low Memory < 256MB (não acionável isoladamente)
- 23 outras rules do script — gerariam ruído sem sinal

**Validação (rev2 — Auditor 1.1: validação rev1 testava endpoint local 8080 que
sempre retorna 200, NÃO dispara métrica do ALB Target):**
- **Janela validação destrutiva (rev3 — N3 corrigido):** janela noturna 02h00-02h30 BRT
  fixa (não 03h05 que conflita com cron warmer Ação 4). Plano OPERACIONAL não exige
  janela noturna global — apenas ESTE teste destrutivo de Ação 3 a exige por sistemar
  parar nginx por 60s.
- Trigger artificial real: `sudo systemctl stop nginx` por 60s → `HTTPCode_Target_5XX_Count`
  na CloudWatch sobe → notificação chega
- Reabilitar imediatamente após validar
- **Alternativa não-destrutiva:** forçar 502 via curl com header inválido em endpoint
  conhecido — testar primeiro antes de stop nginx

**🚨 PRÉ-REQUISITO (rev2 — Auditor 1.2):**
- Definir canal: **Slack dedicado**, não WhatsApp pessoal de Daniel (bus factor)
- Documentar quem recebe se Daniel offline
- Modo `warning` 7d antes de promover a `critical` com SMS

**Generalização BIT-wide (rev2 — Auditor 1.4):** após validar em concertação,
replicar mesmas 5 rules para mombak, www-concertacao, cop30casamazonia (+2h total).

### TIER P1 — ESTA SEMANA (8-12h, zero downtime)

#### Ação 4 — Cron warmer `d4-cache-warmup.sh` com flock

**Impacto:** ALTO — fim do cold-cache window de 10h
**Risco:** BAIXO com `flock`
**Esforço:** 1h (já existe spec original, copiar para prod com flock + ajustar cron)

**Comandos (rev2 — Auditor 1.1: corrigir crontab + adicionar pré-step rsync):**

**PRÉ-STEP 1 (gap rev1):** deployar script em prod (ainda não está em `/opt/deploy/`):
```bash
rsync -av ec2-deploy/post-deploy/d4-cache-warmup.sh \
  concertacaoamazonia.com.br-prod-sa:/tmp/d4-cache-warmup.sh
ssh concertacaoamazonia.com.br-prod-sa "sudo mv /tmp/d4-cache-warmup.sh /opt/deploy/ && \
  sudo chmod 750 /opt/deploy/d4-cache-warmup.sh && \
  sudo chown root:root /opt/deploy/d4-cache-warmup.sh"
```

**PRÉ-STEP 2:** criar logrotate para `/var/log/d4-warmup.log`:
```bash
ssh concertacaoamazonia.com.br-prod-sa "sudo tee /etc/logrotate.d/d4-warmup > /dev/null <<'EOF'
/var/log/d4-warmup.log {
    daily rotate 14 compress missingok notifempty
    create 640 www-data www-data
}
EOF"
```

**Crontab (idempotente, com `|| true` para evitar abort `set -euo pipefail`):**
```bash
ssh concertacaoamazonia.com.br-prod-sa "sudo -u www-data crontab -l 2>/dev/null > /tmp/cron.bak || true; \
  grep -q 'd4-cache-warmup' /tmp/cron.bak && { echo 'já registrado'; exit 0; } ; \
  echo '5 3,13 * * * flock -n /var/lock/d4-warmup.lock \
    /opt/deploy/d4-cache-warmup.sh --max=200 --pages-only \
    >> /var/log/d4-warmup.log 2>&1' >> /tmp/cron.bak; \
  sudo -u www-data crontab /tmp/cron.bak; rm /tmp/cron.bak"
```

**Notas (rev2):**
- `flock -n` — non-blocking, sai imediato se lock detido (rev3 — Auditor 2.3 detectou que `-n` e `-w` são MUTUAMENTE EXCLUSIVOS no flock(1) util-linux; combinar gera exit 1 silencioso. Para evitar overlap, basta `-n`; para limitar tempo, usar `-w SECONDS` sem `-n`. Aqui escolhemos `-n` puro porque o objetivo é skip se rodando, não esperar)
- `2>/dev/null || true` — sobrevive ao `set -euo pipefail` se www-data não tem crontab
- `grep -q` — idempotência: re-executar não duplica linha
- `--max=600` — sitemap multisite blog 1+2 da concertação tem ~600 URLs públicas (rev3 — Auditor 2.1 N4: rev2 declarava 200 sem validação). Bumpar conforme sitemap real:
  ```bash
  ssh concertacaoamazonia.com.br-prod-sa "curl -s https://concertacaoamazonia.com.br/wp-sitemap.xml | grep -c '<loc>'"
  ```
- Promover `d4-cache-warmup.sh` para `docker-dev/common/scripts/` agora (Auditor 1.4)

#### Ação 5 — Smoke test pós-deploy automatizado em c1-validate-health.sh

**Impacto:** MÉDIO — pega regressão estrutural pós-cutover
**Risco:** ZERO (read-only)
**Esforço:** 1h

**Adicionar ao `c1-validate-health.sh`:**
- Validar drop-in `wp-content/object-cache.php` presente (já feito 2026-05-04)
- Validar Redis DBSIZE > 100
- Validar `previous_ecp_versions` count < 50
- **NOVO:** validar `tribe_events_calendar_options` size < **102400 bytes** (100 KiB literal,
  rev3 — Auditor 2.3 CRIT-5: rev2 dizia "100KB" ambíguo; tamanho atual pós-dedupe = 1402
  bytes, então 102400 é 73x folga). Comando exato:
  ```bash
  size=$(sudo -u www-data wp --path=/var/www/concertacaoamazonia.com.br option get \
      tribe_events_calendar_options --format=json 2>/dev/null | wc -c)
  [[ $size -lt 102400 ]] || { echo "TEC option inflado: $size bytes"; exit 1; }
  ```
- **N6 (Auditor 2.1):** baseline atual TEC option DOCUMENTADO antes de promover gate hard.
  Estado pós-dedupe (2026-05-03): **1.4 KB / 11 entries**. Threshold 100 KiB = 70x folga.
- **NOVO:** validar response da home retorna 200 com TTFB < 5s

#### Ação 6 — Auditoria de robots.txt + extensão para outros CPTs/QSs

**Impacto:** MÉDIO — reduz volume de tráfego crawler legítimo
**Risco:** BAIXO
**Esforço:** 1h

**Adicionar ao robots.txt:**
```
Disallow: /*?eixo=*
Disallow: /*?_label=*
Disallow: /*?jsf=*
Disallow: /*?tribe-bar-date=*
Disallow: /*?eventDate=*
```

#### Ação 7 — WAF rule "RateLimit-FORWARDED-IP" (modo COUNT 7d)

**Impacto:** ALTO — pega bots distribuídos via CF que IP edge não cobre
**Risco:** MÉDIO (modo COUNT mitiga)
**Esforço:** 2h + 7 dias observação antes de promover BLOCK

**Procedimento (rev3 — Auditor 2.3 CRIT-2: comando AWS CLI completo):**

`aws wafv2 update-web-acl` exige passar a ACL **inteira** (todas as rules) com `LockToken`
da versão atual. Workflow obrigatório:

```bash
# 1. Snapshot ACL ANTES (rev3 — pendência Ciclo 2 fechada)
mkdir -p backups/waf
aws wafv2 get-web-acl \
    --scope CLOUDFRONT \
    --id <ACL_ID> \
    --name ACL-WPAdminHML \
    --region us-east-1 \
    --profile Concertação \
    --output json > backups/waf/acl-pre-acao7-$(date +%Y%m%d_%H%M).json

LOCK_TOKEN=$(jq -r '.LockToken' backups/waf/acl-pre-acao7-*.json | tail -1)

# 2. Modificar rule RateLimit-300-Block dentro do JSON salvo:
#    - "AggregateKeyType": "IP" → "FORWARDED_IP"
#    - Adicionar "ForwardedIPConfig": {"HeaderName":"X-Forwarded-For", "FallbackBehavior":"NO_MATCH"}
#    - "Action": {"Block": {}} → {"Count": {}}
#    - Threshold: manter 300 reqs/5min (rev3 — Auditor 1.2: 300 não 100)
jq '.WebACL.Rules |= map(
    if .Name == "RateLimit-300-Block" then
        .Statement.RateBasedStatement.AggregateKeyType = "FORWARDED_IP" |
        .Statement.RateBasedStatement.ForwardedIPConfig = {
            "HeaderName": "X-Forwarded-For",
            "FallbackBehavior": "NO_MATCH"
        } |
        .Action = {"Count": {}}
    else . end
)' backups/waf/acl-pre-acao7-*.json | tail -1 > /tmp/acl-modified.json

# 3. Aplicar update (extrair só os campos updateables)
aws wafv2 update-web-acl \
    --scope CLOUDFRONT \
    --id <ACL_ID> \
    --name ACL-WPAdminHML \
    --default-action "$(jq -c '.WebACL.DefaultAction' /tmp/acl-modified.json)" \
    --rules "$(jq -c '.WebACL.Rules' /tmp/acl-modified.json)" \
    --visibility-config "$(jq -c '.WebACL.VisibilityConfig' /tmp/acl-modified.json)" \
    --lock-token "$LOCK_TOKEN" \
    --region us-east-1 \
    --profile Concertação
```

**COUNT 14d (rev3 — Auditor 1.2):** período estendido de 7→14 dias dado risco CGNAT
brasileiro. Antes de promover Block, **whitelist explícita ASNs CGNAT**:
- Vivo Móvel AS26615
- Tim AS26599
- Claro/Embratel AS28573, AS4230
- Oi AS7738
- Googlebot AS15169 (se pegar via FORWARDED_IP)
- Bingbot AS8075 (idem)

**Pré-levantar ASNs no D11** (rev3 — Auditor 2.2): consulta via `whois` ou `team-cymru`
em sample de IPs reais do `access.log` antes de aplicar Ação 7.

**Após 14d revisão:** se zero FP em SampledRequests, mudar `Action.Count` → `Action.Block`
e re-aplicar via mesmo workflow `update-web-acl`.

### TIER P2 — ESTA QUINZENA (12-20h)

#### Ação 8 — WAF Bot Control Common (modo COUNT 14d) — **CONDICIONAL**

**Impacto:** ALTO — anti-bot gerenciado AWS captura UA inconsistente automaticamente
**Risco:** MÉDIO (modo COUNT mitiga; managed rule pode mudar sem aviso)
**Esforço:** 6h + 14d observação (rev2 — Auditor 1.5: 4h era subestimado)
**Custo (rev2 — Auditor 1.1 + 1.3):** USD 12.10/mês
- USD 10/mês subscription
- USD 1/M reqs inspecionadas × ~1.5M = USD 1.50
- **+ 5 WCU por sub-rule** podem estourar tier free das ACLs já com várias rules customizadas
- Validar volume real via CloudWatch `RequestCount` antes de aplicar

**🚨 CONDICIONAL ao AWS Activate Imagine Grant (rev2 — Auditor 1.3):**
Cliente é ONG (Uma Concertação Pela Amazônia) — elegível ao programa Imagine Grant
da AWS, que oferece USD 1k-5k em créditos. Aplicação leva ~30 dias.

**Plano:**
- Daniel aplica ao Grant esta semana
- Se aprovado em 30d → Bot Control roda gratuito por 5+ anos
- Se rejeitado → aplicar Bot Control normalmente (USD 12.10/mês)

**Cobertura esperada:** YandexBot, fake-Firefox/Chrome, GPTBot, ClaudeBot, BytespiderBot,
SemrushBot — todos automaticamente sem manutenção manual de UA list.

**🚨 PRÉ-REQUISITO (rev2 — Auditor 1.2):**
- Snapshot ACL antes: `aws wafv2 get-web-acl ... --output json > backups/acl-pre-acao8.json`
- COUNT mode 14d (não 7d) por ser anti-pattern complexo
- Whitelist outbound BIT (Crocoblock licensing, Elementor licensing, WPML auto-translate)

**Generalização BIT (rev2 — Auditor 1.4):** USD 11.50 × 4 ACLs = USD 46/mês total. **Não
generalizar agora** — validar 30 dias em concertação primeiro.

---

#### Ação 9 — CloudFront Function regex UA versão futurística — **CORTADA (rev2)**

**Decisão pós-Ciclo 1 (Auditor 1.3):** **CORTADA** do escopo desta sprint.

**Justificativa:**
- Custo/benefício ruim: 4-8h eng (R$ 1.500) para USD 0.15/mês economia (Auditor 1.3
  corrigiu superstimativa de USD 5/mês — volume real 1.5M reqs × USD 0.10/M)
- Bot Control (Ação 8) já cobre **80% do escopo** (UA inconsistente, versão futurística,
  datacenter, browser fingerprint via ML)
- Risco operacional não-trivial: CF Function panic = 100% tráfego viewer afetado
- Regex `(1[4-9]\d|[2-9]\d\d)` da rev1 bloquearia Firefox 145 (stable em 2026-Q4)
  — risco de auto-DoS em poucos meses

**Reavaliar daqui a 90 dias** se Bot Control deixar gaps específicos de UA forjado.
Ver Anexo B para racional completo.

---

## Tabela consolidada (rev3 — Auditor 2.1 N1: 8 ações, sem Ação 9)

| # | Item | Tier | Esforço (calendar real) | Risco | Custo $/mês | ROI |
|---|------|------|-------------------------|-------|-------------|-----|
| 1 | Bloquear YandexBot nginx | P0 | 1h30 | Baixo | 0 | Imediato |
| 2 | robots.txt /veiculo/ | P0 | 30min | Zero | 0 | 7d |
| 3 | Alarmes 5XX bit-monitoring | P0 | 2h | Baixo | 0.50 (5×CW alarms) | Imediato |
| 4 | Cron warmer d4-cache | P1 | 2h | Baixo | 0 | 24h |
| 5 | Smoke c1-validate-health | P1 | 2h | Zero | 0 | Próximo deploy |
| 6 | robots.txt CPTs/QSs | P1 | 1h30 | Baixo | 0 | 7d |
| 7 | WAF FORWARDED_IP COUNT | P1 | 3h+14d | Médio | 0 | 21d |
| 8 | WAF Bot Control (condicional Grant) | P2 | 6h+14d | Médio | 11.50 | 28d se Grant rejeitado |
| ~~9~~ | ~~CF Function~~ | — | CORTADA (rev2) | — | — | Anexo B |

**Total real: ~26h engineering calendar, ~USD 12.10/mês recorrente** (10 + 1.50 + 0.50 + 0.10).
Cronograma 6 semanas dado corte do R7 deputy.

**Nota financeira (rev3 — Auditor 2.5):**
- Sem Grant: USD 12.10/mês × 12 = USD 145/ano
- Com Grant aprovado: USD 0 nos primeiros 12-24 meses
- TCO 24m: ROI ano 2 é **3.7x** o ano 1 (custo eng amortizado, só recorrente AWS)

---

## Cronograma proposto (6 semanas — rev2 Auditor 1.5)

**Premissa:** Daniel ~50% disponibilidade (founder/CEO sem ops dedicado). 14h "puras" → ~26h calendar.

```
Sem 1 (06-12 mai) — Tier P0
├── D1-D2 (06-07): Ações 1, 2, 3 (~5h calendar com validações)
├── D3-D5 (08-10): monitoramento + ajuste thresholds alarmes
└── Marco P0: 24h sem 5xx novo + zero FP Ação 1 (Googlebot=200, YandexBot=429)

Sem 2 (13-19 mai) — Tier P1 quick wins
├── D6-D8 (13-15): Ações 4, 5, 6 (~5.5h)
├── D9-D10 (16-17): validação smoke + cron warmer 14 ciclos consecutivos
└── Marco P1a: warmer rodou 14x sem overlap, smoke health green

Sem 3-4 (20 mai-02 jun) — Ação 7
├── D11-D12 (20-21): WAF FORWARDED_IP em COUNT (3h + snapshot ACL)
├── D13-D19 (22-28): 7d observação SampledRequests (bumpado para 14d se CGNAT brasileiro)
└── D20 (29): revisar + promover Block (1-2h) + whitelist ASNs Vivo/Tim/Claro se necessário

Sem 5 (03-09 jun) — buffer + setup Ação 8
├── D21-D22 (03-04): contingência ações anteriores (não-uso = ganhar tempo)
├── D23-D24 (05-06): WAF Bot Control COUNT (6h)
└── Decisão: aguardar AWS Activate Grant ou aplicar com custo pago?

Sem 6 (10-23 jun) — Bot Control observação
├── D25-D31: 14d COUNT observation (whitelist BIT outbound)
├── D32 (24): promover Block (1h)
└── Marco final: 14 dias zero incidente FPM saturation

Sem 7-8 (24 jun-07 jul) — buffer + retrospectiva
├── Buffer para urgências externas (founder/CEO life)
├── Smoke test completo + retrospectiva
└── Documentação atualizada nos memory files

REMOVIDO: Ação 9 (CF Function) — ver Anexo B
```

### Critérios go/no-go objetivos (rev2 — Auditor 1.5)

| Marco | Critério |
|-------|----------|
| Tier P0 stable 24h | Zero novos 5xx > thresholds dos novos alarmes; Googlebot=200, YandexBot=429 confirmados via curl; access.log sem 444 com Referer `host/` (regression do bug v1.15.0) |
| Tier P1 stable 7d | WAF FORWARDED_IP COUNT: zero entradas SampledRequests com `httpRequest.uri` em `/wp-admin` legítimo; warmer rodou 14x sem overlap; smoke health green em todos deploys |
| Bot Control 14d | Zero falso-positivo legítimo (Crocoblock licensing, WPML auto-translate); SampledRequests apenas bots conhecidos |
| Marco final | Zero alarme `ALB Target 5XX > 50/5min` por 14d; FPM workers busy max < 15/20 sustentado; zero incidente reportado por user |

---

## Métricas de validação

### KPIs (medir antes de cada Ação e 7d depois)

- **5xx rate ALB Target** (últimos 24h, último 7d) — alvo: < 0.1%
- **5xx rate CloudFront** — alvo: < 0.5%
- **TTFB p95 home** — alvo: < 3s
- **TTFB p95 espiral filtrada** — alvo: < 6s
- **Workers FPM busy max sustentado** — alvo: < 15/20
- **Aurora CPU pico (24h)** — alvo: < 70%
- **YandexBot reqs/dia** — alvo após Ação 1: ~0 (bloqueado)
- **Hits 444 com Referer `host/`** — alvo: 0 (regressão do bug v1.15.0)

### Plano de rollback global

Se qualquer Ação P0-P1 introduzir regressão grave:
1. Rollback do item específico via comando documentado
2. Snapshot AMI atual disponível: `ami-0b5e058cc150a1557` (pré-Tier 2 do spec original)
3. Em catástrofe, restore EC2 via AWS console em <30min

---

## Riscos por item

| # | Risco | Mitigação |
|---|-------|-----------|
| 1 | Bloqueio YandexBot afeta SEO Yandex BR (baixo) | YandexBot Brasil tem ~1% market share; cliente focado público brasileiro |
| 2 | robots.txt Disallow /veiculo/ remove páginas do Google | Confirmar com cliente se quer indexação SEO desses CPTs antes |
| 3 | Alarmes geram ruído inicial enquanto threshold ajusta | Modo "warning" 7d antes de promover canal SMS/WhatsApp |
| 4 | Warmer overlap (job >10h) | `flock -n` previne |
| 5 | Smoke test detecta FP que não é regressão real | Iniciar com warning-only, promover gate hard após 7d zero FP |
| 6 | Disallow QS afeta crawlers legítimos descobrindo conteúdo | QSs listados são UI state (eixo, label) — Googlebot já indexa página base |
| 7 | RateLimit FORWARDED_IP pega usuários legítimos atrás de NAT/VPN | Modo COUNT 7d valida; threshold 100 reqs/5min é generoso |
| 8 | WAF Bot Control bloqueia bot legítimo (Crocoblock, Elementor licensing) | Outbound não é afetado; managed rule aprende em COUNT |
| 9 | CF Function panic afeta 100% tráfego viewer | try/catch com fail-open + canary deploy via behavior teste |

---

## Estado real do sistema (rev3 — Meta-revisor 3)

Antes de declarar trabalho restante, reconhecer fixes JÁ EM PROD desde 2026-05-02:

| Fix | Estado | Commit/data | Efeito medido |
|-----|--------|-------------|---------------|
| Item 16 — dedupe `previous_ecp_versions` | ✅ Aplicado | 2026-05-03 00:08 BRT | Option 2.28MB → 1.4KB; TTFB médio prod -52% |
| mu-plugin `bit-tec-versions-dedupe` v1.1.0 | ✅ Em prod | commit `3954de059` | Hook `pre_update_option_*` impede re-inflação |
| nginx Referer block v1.15.1 (sem `/?`) | ✅ Em prod | commit `be1840599` (2026-05-06) | 292 FPs corrigidos; bot family Firefox/142 + Chrome/147 bloqueada |
| Smoke rev2 Fase 7.9 (gate 19b) | ✅ Em prod | commit `b58794929` | Detecta regressão do bug do regex |
| Slowlog FPM ativo | ✅ Em prod | 2026-05-02 17:25 BRT | Captura cascata >5s; viu YandexBot 504 retroativamente |
| Redis drop-in `object-cache.php` | ✅ Em prod | 2026-05-02 02:41 BRT | DBSIZE 0 → 80k+ keys; hit rate 86% |

**Conclusão pragmática:** o problema AGUDO está resolvido. Plano rev3 é **defesa em profundidade
preventiva** sobre sistema estabilizado, não remediação ativa de incidente.

**Trigger de re-priorização:** se ocorrer novo incidente de saturação FPM nos próximos 90
dias, retomar plano com urgência. Caso contrário, executar em ritmo do operador único
(Daniel solo, 50% disponibilidade).

---

## Cortes (rev3 — Meta-revisor 2)

### R7 (Bus factor / Deputy nomeado) — **CORTADO**

**Justificativa:**
- Cliente é ONG (Uma Concertação Pela Amazônia) sem orçamento para segundo operador
- Daniel opera solo há > 6 meses sem incidente atribuível a bus factor
- Treinar deputy = 4-8h sem ROI imediato; melhor diferir Q3 2026 OU contratar pontual em
  caso de catástrofe específica
- Runbooks formais (extensão do R7) consumiriam 3-4h sem ganho proporcional

**Mitigação alternativa (incorporada):**
- CLAUDE.md + memory files do projeto cobrem ~80% do que runbooks formais cobririam
- Snapshots WAF/CF (R6) seguem como pré-requisito de Ações 7/8
- Em catástrofe, restore via snapshot AMI `ami-0b5e058cc150a1557` resolve em 30min

**Re-ativar R7 SE:**
- Daniel tirar férias programadas > 5d durante execução do plano
- Cliente solicitar SLA com penalidade
- Bureau IT contratar segundo operador

---

## Anexo A — Rollout BIT-wide (rev2 — Auditor 1.4)

Premissa rev1 ("generalização não-objetivo") era **parcialmente incorreta**. Para 3 das 8
ações o custo marginal de generalização é trivial e o risco de manter sites no escuro
(invisibilidade de incidentes) é não-aceitável.

### Inventário sites BIT vulneráveis

| Site | TEC | WPML | JE | CPT JE público | Prod | Vulnerabilidade | Prioridade |
|------|-----|------|-----|----------------|------|-----------------|-----------|
| concertacao | Sim | Sim | Sim | Sim (`/veiculo/*`) | i-02b3a897... | CRÍTICA (origem) | — |
| mombak | Não | Sim | Sim | Provável | mombak-prod | ALTA — cascata WPML+JE | P0 |
| www-concertacao | Não | Sim | Sim | Provável | www.concert...prod-sa | ALTA — listings JE | P0 |
| cop30casamazonia | Não | Não | Sim | A confirmar | cop30...prod-sa | MÉDIA — JE sem WPML | P1 |
| escoladobairro | Não | Não | Sim | A confirmar | sem SSH_HOST_PROD | BAIXA-MÉDIA | P2 |
| elosbensevalores | Não | Não | Sim | A confirmar | sem SSH_HOST_PROD | BAIXA-MÉDIA | P2 |
| outros | — | — | — | — | dev/SaaS | BAIXA | P3 |

### Ações a generalizar nesta sprint

| Ação | Generalizar? | Custo marginal | Justificativa |
|------|--------------|----------------|----------------|
| **1 — YandexBot block** | **SIM** | 0 | `03-nginx-sites.sh` já é template `common/`. Re-rodar em mombak/www-concertacao/cop30 herda automaticamente. |
| **3 — Alarmes 5XX** | **SIM** | +2h (3 prods × ~30min) | bit-monitoring suporta filtro por instância. ROI imediato — próximo incidente em mombak/www-concertacao fica visível em 5min vs N dias. |
| **4 — Cron warmer** | **Estruturar `common/`** | 0 marginal | Promover `d4-cache-warmup.sh` para `docker-dev/common/scripts/` agora; ativar cron site-by-site conforme demanda. |

### Ações concertação-only (validar primeiro, replicar depois)

- **Ação 2 (robots.txt /veiculo/):** site-specific, CPT só existe em concertação
- **Ação 5 (smoke c1-validate-health):** validações TEC-específicas concertação-only;
  validações genéricas (Redis, drop-in) **devem ir para `common/`**
- **Ação 6 (robots.txt eixo/jsf):** filtros JSF específicos da Espiral
- **Ação 7 (WAF FORWARDED_IP):** auditar ACLs antes — se compartilhada, mudança é 1x
- **Ação 8 (Bot Control):** USD 11.50/ACL × 4 ACLs = USD 46/mês total. **Não generalizar
  agora** — validar 30d em concertação primeiro.

---

## Anexo B — Decisão de cortar Ação 9 (CF Function regex futurística)

**Decisão pós-Ciclo 1 (Auditor 1.3 + 1.1):** **CORTADA** desta sprint. Reavaliar daqui 90d
se Bot Control deixar gaps específicos.

### Análise custo/benefício

| Métrica | Valor |
|---------|-------|
| Custo eng | 4-8h = R$ 960-1.920 (Auditor 1.5: rev1 subestimou) |
| Custo recorrente | USD 0.15/mês (Auditor 1.3 corrigiu rev1 USD 5/mês — 33x menor) |
| Cobertura adicional vs Bot Control | ~20% (UA fingerprint que Bot Control já cobre) |
| Risco operacional | CF Function panic = 100% tráfego viewer afetado |
| Risco regex | rev1 `(1[4-9]\d|...)` bloquearia Firefox 145 stable em 2026-Q4 (auto-DoS) |

### Alternativas consideradas (rejeitadas)

- **Bot Control já cobre 80%:** managed rule da AWS detecta UA inconsistente, browser
  inconsistency, datacenter, ML fingerprint
- **Ação 1 (nginx YandexBot)** + Ação 7 (WAF FORWARDED_IP) cobrem casos específicos
  conhecidos
- Para o que SOBRA (UA forjado novo, sem Bot Control coverage): incidente individual
  custa < 1h debug + R$ 1.500 (Daniel). Bot Control ML aprende em 7-14d e adapta
  managed rule — sem trabalho manual

### Trigger para reativar Ação 9

Reavaliar SE em 90 dias:
- Bot Control + Ação 1 deixarem > 2 incidentes/mês passarem
- AWS Activate Grant aprovado (cobre custo de CF Function)
- Padrão de UA forjado emergir que managed rule não pega

---

## Memorando para outros sites BIT (futuro)

Estruturas que ficam reusáveis ao final:
- **mu-plugin canônico:** `bit-tec-versions-dedupe.php` (já em `common/mu-plugins/`)
- **Templates nginx maps:** padrão `deny_<bot>_html` aplicável a qualquer site
  (rev2: agora cobre UA+URI combinados — pattern `deny_meta_html` v1.12.2)
- **Smoke test framework:** `c1-validate-health.sh` parcialmente compartilhável
  (validações genéricas vs TEC-specific)
- **`d4-cache-warmup.sh`:** promover para `docker-dev/common/scripts/` (rev2)
- **WAF rule templates:** `RateLimit-FORWARDED-IP` em formato JSON

---

## Status updates log

| Data | Ação | Operador | Resultado |
|------|------|----------|-----------|
| 2026-05-06 | Plano criado (rev1) | Daniel Cambría | proposed |
| 2026-05-06 | Ciclo 1 — 5 agentes auditando rev1 | Auditores 1.1-1.5 | completo |
| 2026-05-06 | **Rev2 publicada com correções Ciclo 1** | Daniel Cambría | proposed — bugs B1-B9 corrigidos, Ação 9 cortada, Anexos A/B adicionados, cronograma 3→6 semanas, Ações 1/3/4 escopo BIT-wide |
| 2026-05-06 | Ciclo 2 — 5 agentes auditando rev2 | Auditores 2.1-2.5 | completo — 8 bugs (3 bloqueantes reais), 7 riscos P×S≥12, 5 inconsistências financeiras |
| 2026-05-07 | Meta-revisão — 3 agentes auditando síntese | Meta-revisores 1-3 | completo — síntese inflou bloqueantes (8 → 3), R7 deputy cortar (sem budget ONG), reconhecer fixes JÁ em prod desde 2026-05-02 |
| 2026-05-07 | **Rev3 publicada com correções Ciclo 2 + Meta** | Daniel Cambría | proposed — 3 bloqueantes reais corrigidos (CRIT-1 flock, CRIT-2 AWS CLI, N3 janela), 2 omissões Meta-1 recuperadas (N6, CRIT-5), banda USD 12-22 → USD 12.10, R7 cortado, seção "Estado real do sistema", aguardando Ciclo 3 |
| _AAAA-MM-DD_ | Ciclo 2 — 5 agentes auditando rev2 | TBD | _resultado_ |
| _AAAA-MM-DD_ | Rev3 publicada com correções ciclo 2 | _operador_ | _resultado_ |
| _AAAA-MM-DD_ | Ciclo 3 — 5 agentes auditando rev3 | TBD | _resultado_ |
| _AAAA-MM-DD_ | Plano sign-off final | Daniel Cambría | _go/no-go_ |
| _AAAA-MM-DD_ | Ação 1 deployed | _operador_ | _resultado_ |
| _AAAA-MM-DD_ | Ação 2 deployed | _operador_ | _resultado_ |
| _AAAA-MM-DD_ | Ação 3 deployed | _operador_ | _resultado_ |
| _AAAA-MM-DD_ | Tier P0 stable for 24h | _operador_ | go/no-go P1 |
| _AAAA-MM-DD_ | Ação 4 deployed | _operador_ | _resultado_ |
| _AAAA-MM-DD_ | Ação 5 deployed | _operador_ | _resultado_ |
| _AAAA-MM-DD_ | Ação 6 deployed | _operador_ | _resultado_ |
| _AAAA-MM-DD_ | Ação 7 COUNT mode | _operador_ | _resultado_ |
| _AAAA-MM-DD_ | Ação 7 BLOCK mode | _operador_ | _resultado_ |
| _AAAA-MM-DD_ | Tier P1 stable for 7d | _operador_ | go/no-go P2 |
| _AAAA-MM-DD_ | Ação 8 COUNT mode | _operador_ | _resultado_ |
| _AAAA-MM-DD_ | Ação 8 BLOCK mode | _operador_ | _resultado_ |
| _AAAA-MM-DD_ | Ação 9 deployed | _operador_ | _resultado_ |
| _AAAA-MM-DD_ | Retrospectiva 14d zero incidente | Daniel Cambría | _resultado_ |
