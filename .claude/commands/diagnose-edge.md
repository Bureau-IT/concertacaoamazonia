---
description: Diagnóstico empírico de incidente edge AWS (CloudFront + WAF + ALB) seguindo método read-only-first. Coleta métricas, sampled requests, logs S3 (se disponíveis) e origin health em paralelo. Output com VEREDICT no topo.
allowed-tools: Bash, Read, TaskCreate, TaskUpdate
argument-hint: [--site=<name>] [--window=<min>] [--ip=<addr>] [--no-origin]
---

# /diagnose-edge — Diagnóstico empírico de incidente edge

Você é especialista em diagnóstico AWS edge (CloudFront + WAF + ALB) e vai
executar uma sequência empírica de checks para o site concertação.

**Princípio fundamental** (memory `feedback_incident_diagnostic_discipline.md`):
read-only queries primeiro, hipótese depois. Hipóteses sem dataset são teatro.
Agentes ratificam premissa errada — não usar nesta fase.

---

## Argumentos

Parse argumentos de `$ARGUMENTS`:

- `--site=<name>` — site key em `~/.config/bit-bpo/waf-sites.yaml`. Default: `concertacao` (esta skill é project-only)
- `--window=<min>` — janela em minutos. Default: `60`. Aceita `180` (3h), `1440` (24h)
- `--ip=<addr>` — filtra WAF + nginx + CW por IP específico
- `--no-origin` — pula origin health snapshot (útil se SSH lento/indisponível)

Default: site=concertacao, window=60, sem filtro IP, com origin health.

---

## Workflow

### Step 1: Resolver config do site

Leia `~/.config/bit-bpo/waf-sites.yaml`. Se site não existir, abort com:
```
[!] Site '<name>' não encontrado em waf-sites.yaml
```

Variáveis úteis a extrair:
- `aws_profile`, `region`, `distribution_id`, `web_acl_arn`, `web_acl_name`, `web_acl_id`
- `log_bucket`, `ssh_alias`, `web_root`, `alb_name`, `alb_region`

Crie `OUTPUT_DIR=/tmp/diagnose-edge-{site}-{unixtime}` e salve o relatório completo lá.

### Step 2: Origin health snapshot (PARALELO via & + wait)

**Antes de qualquer check edge — verificar origin primeiro.** Lição do incidente:
viés "é WAF" sem checar origin gera 5h de investigação errada.

Execute em paralelo (background com `&`):

```bash
# 2a. ALB target health (se alb_name e alb_region definidos)
aws elbv2 describe-target-health --profile "$PROFILE" --region "$ALB_REGION" \
  --target-group-arn $(aws elbv2 describe-target-groups --profile "$PROFILE" --region "$ALB_REGION" \
    --load-balancer-arn $(...) --query 'TargetGroups[0].TargetGroupArn' --output text) \
  --query 'TargetHealthDescriptions[].{Target:Target.Id,State:TargetHealth.State}' --output json &

# 2b. ALB 5xx counts (CloudWatch HTTPCode_Target_5XX_Count last $WINDOW min)
aws cloudwatch get-metric-statistics --profile "$PROFILE" --region "$ALB_REGION" \
  --namespace AWS/ApplicationELB --metric-name HTTPCode_Target_5XX_Count \
  --dimensions Name=LoadBalancer,Value=$(...) \
  --start-time "..." --end-time "..." --period 60 --statistics Sum &

# 2c. ALB TargetResponseTime p95
# (similar)

# 2d. PHP-FPM saturação via SSH
# IMPORTANTE: comando `timeout` nao existe em macOS — usar apenas opcoes do SSH:
#   - ConnectTimeout=5: max 5s para abrir conexao
#   - ServerAliveInterval=3 + ServerAliveCountMax=2: mata sessao se SSH inativo 6s
ssh -o ConnectTimeout=5 -o ServerAliveInterval=3 -o ServerAliveCountMax=2 "$SSH_ALIAS" \
  "ss -tnp 2>/dev/null | grep ':9000' | wc -l; \
   systemctl is-active php8.3-fpm 2>/dev/null" &

wait
```

Se `--no-origin` foi passado, pular este step.

Se algum step falhar (SSH inacessivel, ALB sem permissão), warn e continuar — não abort.

### Step 3: CloudFront métricas (last $WINDOW min)

```bash
aws cloudwatch get-metric-statistics --profile "$PROFILE" --region us-east-1 \
  --namespace AWS/CloudFront --metric-name 4xxErrorRate \
  --dimensions Name=DistributionId,Value="$DIST_ID" Name=Region,Value=Global \
  --start-time "..." --end-time "..." --period 60 --statistics Average

# Repetir para 5xxErrorRate e Requests
```

Capturar:
- 4xxErrorRate avg + peak + timestamp do peak
- 5xxErrorRate avg + peak
- Requests sum total + datapoints com queda anômala (Anomaly Detection band 2σ via CloudWatch Insights se disponível)

### Step 4: WAF BlockedRequests por Rule (last $WINDOW min)

```bash
# Listar todas as rules do WebACL primeiro
aws wafv2 list-rule-groups ... # ou get-web-acl + parse

# Para cada rule (e ALL):
aws cloudwatch get-metric-statistics --profile "$PROFILE" --region us-east-1 \
  --namespace AWS/WAFV2 --metric-name BlockedRequests \
  --dimensions Name=WebACL,Value="$WEB_ACL_NAME" Name=Rule,Value="$RULE_NAME" \
  --start-time "..." --end-time "..." --period 300 --statistics Sum
```

Output: top 5 rules por blocks + total.

### Step 5: WAF SampledRequests (last 3h, max 100)

```bash
aws wafv2 get-sampled-requests --profile "$PROFILE" --region us-east-1 \
  --web-acl-arn "$WEB_ACL_ARN" \
  --rule-metric-name ALL \
  --scope CLOUDFRONT \
  --time-window StartTime="...",EndTime="..." \
  --max-items 100
```

Se `--ip=<addr>` passado: filtrar `SampledRequests[?Request.ClientIP=='$IP']`.

Output:
- Top 10 IPs blocked (ASN/geo se possível)
- Top 10 URIs blocked
- Distribution por terminatingRule

### Step 6: CloudTrail UpdateWebACL (last 7d)

Detecta regressão de config.

```bash
aws cloudtrail lookup-events --profile "$PROFILE" --region us-east-1 \
  --lookup-attributes AttributeKey=EventName,AttributeValue=UpdateWebACL \
  --start-time "$(date -u -v-7d +%Y-%m-%dT%H:%M:%SZ)" \
  --max-results 20 \
  --query 'Events[].{Time:EventTime,User:Username}'
```

Se houver mudanças, alertar — pode ser causa.

### Step 7: WAF logs S3 aggregation (se logging ativo)

```bash
aws wafv2 get-logging-configuration --profile "$PROFILE" --region us-east-1 \
  --resource-arn "$WEB_ACL_ARN" 2>/dev/null
```

Se ATIVO:
```bash
# Download recursivo do hour atual (UTC)
HOUR_NOW=$(date -u +%Y/%m/%d/%H)
aws s3 cp --profile "$PROFILE" --recursive --quiet \
  "s3://$LOG_BUCKET/AWSLogs/$ACCT/WAFLogs/cloudfront/$WEB_ACL_NAME/$HOUR_NOW/" \
  "$OUTPUT_DIR/waflogs/"

# Descompactar (mantem .gz original)
find "$OUTPUT_DIR/waflogs" -name "*.log.gz" -exec gunzip -k {} \;

# IMPORTANTE: AWS particiona por minuto (subdiretorios 00/, 05/, 10/...).
# Concatenar TODOS os .log dos subdirs com find -exec cat (NAO usar
# `cat *.log` — so pega root-level e perde os subdirs):
find "$OUTPUT_DIR/waflogs" -name "*.log" -exec cat {} + > "$OUTPUT_DIR/waflogs-all.jsonl"

# Agregar via Python (inline ou heredoc) — top IPs, URIs, rules, UAs
# Filtro --ip=<addr> aplicado se passado
```

Se INATIVO: warn explícito "logs WAF não habilitados — `helpers/enable-waf-logs.sh $SITE` para habilitar (~5-10min para primeiros logs)". Não tente habilitar automaticamente.

### Step 8: VEREDICT + output

Com base nos steps anteriores, classificar incidente:

| Veredict | Critério |
|----------|----------|
| `edge-anomaly` | CF 4xxErrorRate alto + ALB 5xx baixo + origin saudável + WAF blocks elevados |
| `origin-degraded` | ALB 5xx alto OR FPM saturado OR ALB TargetHealth UnHealthy |
| `mixed` | Sintomas em ambos camadas |
| `inconclusive` | Métricas dentro do baseline ou dados insuficientes |

Output em terminal (≤25 linhas, com cores):

```
═══════════════════════════════════════════════════════════
 DIAGNOSE-EDGE  ·  concertacao  ·  2026-05-04 19:45 BRT
═══════════════════════════════════════════════════════════

VERDICT: edge-anomaly

[+] Origin: HEALTHY (ALB 1/1 healthy, FPM 8/20 workers, Aurora normal)

[!] CloudFront 4xx Error Rate (last 60min):
    Avg: 18.3%  ·  Peak: 92.8% @ 14:34 BRT
    Trend: ↗ 3σ band exceeded

[!] WAF Blocks (last 60min, top 3 rules):
    1. RateLimit-300-Block  →  47 blocks (32 unique IPs, 90% BR residential)
    2. Block-AggressiveBots →  109 blocks (SemrushBot SG)
    3. Block-XMLRPC         →    2 blocks

[~] Top blocked IP: 186.220.197.37 (Claro NXT BR)
    11 blocks all on RateLimit-300-Block
    URIs: /, /sobre-nos/, /favicon.ico

[i] CloudTrail: 0 UpdateWebACL events last 7d (sem regressão de config)

[+] WAF logs S3: ATIVO (aws-waf-logs-concertacao-prd-use1)
    Last entry: 2 min ago

──────────────────────────────────────────────────────────
Full report: /tmp/diagnose-edge-concertacao-1714896000.md
```

Salvar relatório completo em markdown no `OUTPUT_DIR/report.md` com TODOS os dados brutos coletados.

### Step 9: Comunicação ao usuário

Após gerar relatório, apresentar resumo curto ao Daniel:
- Veredito
- 2-3 achados mais relevantes
- Recomendação de próximo passo (NÃO executar — só sugerir)
- Caminho do relatório completo

---

## Princípios obrigatórios

1. **READ-ONLY APENAS.** Nunca modificar WAF, CloudFront, ALB neste fluxo.
2. **Paralelizar com `&` + `wait`** quando possível (CW, ALB, SSH são independentes).
3. **SSH timeout via opções nativas** (ConnectTimeout=5 + ServerAliveInterval=3) — comando `timeout` NÃO existe em macOS por padrão, não usar.
4. **Sem emojis decorativos** — usar `[+]`, `[!]`, `[~]`, `[i]` + cores ANSI BIT (FG_GREEN/FG_RED/FG_ORANGE/FG_BLUE).
5. **VERDICT sempre no topo do output** — Daniel sob estresse precisa em 5s.
6. **Se logs não habilitados**, sugerir helper `enable-waf-logs.sh` mas NÃO executar automaticamente.
7. **Salvar relatório completo em arquivo** — terminal mostra resumo, file tem dados brutos para análise posterior.
8. **`LC_NUMERIC=C` ou `LC_ALL=C` antes de `printf` com decimais** — locale pt_BR usa vírgula em decimal e quebra `printf '%.2f'`. Definir uma vez no topo do script.

## Gotchas conhecidos (descobertos no teste 2026-05-04)

### macOS vs Linux

- Comando `timeout`: **inexistente no macOS por padrão**. Usar opções SSH nativas (ConnectTimeout, ServerAliveInterval) ou `gtimeout` se `coreutils` instalado via brew.
- `date -u -v-60M`: BSD-style do macOS. Em Linux seria `date -u -d "60 minutes ago"`. Daniel usa macOS — manter BSD-style.

### Locale pt_BR e printf

```bash
# ❌ ERRADO em locale pt_BR (vírgula vira separador decimal):
printf '%.2f' "14.899004"  # → erro "número inválido"

# ✅ CERTO — força locale C/POSIX:
LC_NUMERIC=C printf '%.2f' "14.899004"  # → "14.90"

# ✅ ALTERNATIVA — definir no topo do script:
export LC_NUMERIC=C
printf '%.2f' "14.899004"  # → "14.90"
```

### S3 logs aggregation com subdiretórios por minuto

WAF logs S3 vêm particionados por minuto: `<HH>/00/`, `<HH>/05/`, etc. `cat *.log` no diretório raiz **NÃO pega subdirs**. Sempre usar:

```bash
find "$OUTPUT_DIR/waflogs" -name "*.log" -exec cat {} + > "$OUTPUT_DIR/waflogs-all.jsonl"
```

`find ... -exec cat {} +` agrupa argumentos (mais eficiente que `\;`) e atravessa subdirectories.

---

## Quando recomendar próximas ações

Após diagnóstico, sugerir (NÃO executar) com base no veredict:

- **edge-anomaly** com top rule = `RateLimit-*`: revisar threshold + scope-down
- **edge-anomaly** com Block-AggressiveBots dominante: provavelmente saudável, bot legítimo
- **origin-degraded**: investigar FPM/Aurora antes de mexer em WAF
- **mixed**: cross-reference WAF logs S3 com nginx access log
- **inconclusive**: aguardar mais dados ou habilitar logs S3 se inativos

---

## Não fazer

- Não dispatch agentes para "interpretar" métricas — você é o agente, faça você mesmo
- Não criar hipóteses antes de coletar todos os steps 1-7
- Não invalidar CloudFront, mexer em WAF, ou qualquer ação destrutiva
- Não pular steps por conveniência — paralelizar é OK, pular não
