# Playbook: Diagnóstico de Incidente Edge

> **Princípio:** read-only queries primeiro, hipótese depois. Hipóteses sem
> dataset são teatro (lição do incidente 2026-05-04 — 5h investigando hipótese
> errada). Use este playbook ANTES de propor qualquer fix.

## Quando usar

- Usuário reportando 403 ou erro de acesso
- 4xxErrorRate ou 5xxErrorRate fora do baseline
- Tráfego CloudFront caindo abruptamente
- Alarms CloudWatch disparando

## Sequência (ordem importa)

### 0. Antes de qualquer coisa

```bash
# Confirmar AWS profile + acesso
aws sts get-caller-identity --profile "Concertação"
```

Se falhar, abortar e resolver auth primeiro.

### 1. Rodar `/diagnose-edge`

```
/diagnose-edge
```

Ou com filtros se usuário reportou IP específico:
```
/diagnose-edge --ip=186.220.197.37 --window=180
```

Output gera **VERDICT** no topo:
- `edge-anomaly` — problema na camada CloudFront/WAF
- `origin-degraded` — ALB/FPM/Aurora saturados
- `mixed` — sintomas em ambas camadas
- `inconclusive` — métricas dentro do baseline

### 2. Se VERDICT = `origin-degraded`

**NÃO mexer em WAF.** Investigar origem primeiro:

```bash
# FPM saturação
ssh concertacaoamazonia.com.br-prod-sa "ss -tnp | grep ':9000' | wc -l; \
  sudo systemctl status php8.3-fpm | head"

# Aurora connections + slow queries
aws cloudwatch get-metric-statistics --profile "Concertação" --region sa-east-1 \
  --namespace AWS/RDS --metric-name DatabaseConnections \
  --dimensions Name=DBClusterIdentifier,Value=amazonia-aurora-db-cluster \
  --start-time "$(date -u -v-1H +%Y-%m-%dT%H:%M:%SZ)" \
  --end-time "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
  --period 60 --statistics Average

# nginx error log
ssh concertacaoamazonia.com.br-prod-sa "sudo tail -100 /var/log/nginx/error.log | grep -v 'notice'"
```

Causas comuns:
- Pico de tráfego legítimo (campanha, viral)
- Crawler agressivo passando por WAF (ver bot-uas.txt)
- Aurora deadlock ou slow query
- FPM `max_children` baixo

### 3. Se VERDICT = `edge-anomaly`

WAF SampledRequests do `/diagnose-edge` mostra `terminatingRule`. Identificar a rule responsável:

| Rule | Provável causa | Ação |
|------|----------------|------|
| `RateLimit-300-Block` | Threshold mal calibrado OU usuário em loop OU bot | Ver §3.1 |
| `Block-AggressiveBots` | Bot legítimo conhecido (SemrushBot, etc.) | Validar — geralmente esperado |
| `Block-NonDev-WPAdmin` | IP fora do DevTeam tentando wp-admin | Esperado, sem ação |
| `RateLimit-WPLogin-POST` | Brute-force | Esperado, manter |
| `AWS-WordPress-ManagedRules` | Subrule managed disparou | Identificar via SampledRequests + considerar override |
| `Block-AttackerRanges-*` | IPSet de attacker | Validar IPSet não está vazado (CGNAT BR?) |

#### 3.1 RateLimit-300-Block disparando em IPs residenciais BR

Sintoma: usuário real Claro/Vivo/TIM/Oi sendo bloqueado.

```bash
# WAF logs S3 — agregar blocks por IP nas últimas 1h
aws s3 cp --recursive --profile "Concertação" \
  s3://aws-waf-logs-concertacao-prd-use1/AWSLogs/379590274801/WAFLogs/cloudfront/ACL-WPAdminHML/$(date -u +%Y/%m/%d/%H)/ \
  /tmp/waf-logs/

find /tmp/waf-logs -name "*.log.gz" -exec gunzip {} \;
cat /tmp/waf-logs/*.log | python3 -c "
import json, sys
from collections import Counter
ips_blocked = Counter()
rules = Counter()
for line in sys.stdin:
    e = json.loads(line.strip())
    if e.get('action') != 'BLOCK': continue
    ips_blocked[e['httpRequest']['clientIp']] += 1
    rules[e.get('terminatingRuleId', 'NA')] += 1
print('Top IPs:', ips_blocked.most_common(10))
print('Rules:', dict(rules))
"
```

Se top IPs são CGNAT residencial BR + rule = `RateLimit-300-Block`:
- **Threshold mal calibrado.** Ver `templates/rules/rate-limit-generic.json` e aplicar.
- Sem scope-down WP, navegação real estoura limit (80-150 sub-requests/página).

#### 3.2 Block-AggressiveBots disparando em massa

Sintoma: 90%+ dos blocks de uma rule só.

Validar:
```bash
# Top User-Agents bloqueados
cat /tmp/waf-logs/*.log | python3 -c "
import json, sys
from collections import Counter
ua = Counter()
for line in sys.stdin:
    e = json.loads(line.strip())
    if e.get('action') != 'BLOCK': continue
    if e.get('terminatingRuleId') != 'Block-AggressiveBots': continue
    for h in e['httpRequest']['headers']:
        if h['name'].lower() == 'user-agent':
            ua[h['value'][:80]] += 1
            break
print(ua.most_common(10))
"
```

Se UA é bot conhecido (SemrushBot, etc.), comportamento esperado. Se UA é
browser legítimo, investigar pattern do `bot-uas.txt` que possa ter falso-positivo.

### 4. Se VERDICT = `mixed`

Cross-reference WAF logs S3 com nginx access log:
- WAF mostra blocks
- nginx mostra requests que passaram pelo WAF mas falharam no origin

Se nginx tem alta taxa de 502/503/504, problema é origem mesmo (volta para §2).
Se nginx só tem 200 + 404 (path scanning), 4xxErrorRate alto é ruído de bots.

### 5. Se VERDICT = `inconclusive`

Possibilidades:
- Tráfego dentro do baseline (false alarm do alerta)
- Logs WAF não habilitados (correr `helpers/enable-waf-logs.sh`)
- Janela errada (incidente foi antes da `--window`)

```bash
# Verificar logs habilitados
aws wafv2 get-logging-configuration --profile "Concertação" --region us-east-1 \
  --resource-arn "arn:aws:wafv2:us-east-1:379590274801:global/webacl/ACL-WPAdminHML/05522267-..."

# Se vazio, habilitar
~/.claude/skills/bit-waf/helpers/enable-waf-logs.sh concertacao
# Aguardar 5-10min para primeiros logs
```

## Antipatterns — NÃO fazer

1. **Hipotetizar antes de coletar logs** — diagnóstico empírico primeiro
2. **Disparar agentes para "interpretar" antes dos dados estarem na mesa** —
   agentes ratificam premissa errada
3. **Aplicar fix em prod antes de identificar a rule responsável** —
   mudança às cegas pode mascarar
4. **Invalidar CloudFront com `/*`** — derruba o servidor por cache miss avalanche
5. **Mexer em `OnSourceDDoSProtectionConfig`** — feature ALB-only, não atua em
   CLOUDFRONT scope (lição: `feedback_waf_source_ddos_nondeterministic`)

## Próximos passos após diagnóstico

- Se rule mal calibrada: `playbooks/deploy-rule.md`
- Se bot legítimo: ajustar `templates/patterns/bot-uas.txt` ou criar Allow rule específica
- Se origem saturada: investigação separada (FPM tuning, Aurora optimization)
- Se config regrediu (CloudTrail mostrou UpdateWebACL recente): rollback via
  snapshot em `sites/<site>/aws/`

## Referências

- Memory: `feedback_incident_diagnostic_discipline.md`
- Memory: `feedback_waf_ratelimit_static_paths.md`
- Memory: `feedback_aws_changes_audit_trail.md`
- ADR: `docs/superpowers/specs/2026-05-04-adr-cloudfront-error-caching-ttl.md`
