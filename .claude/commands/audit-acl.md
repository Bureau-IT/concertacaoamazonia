---
description: Auditoria proativa de WAF Web ACL — detecta dead code, rules duplicadas, IPSets desatualizados, capacity warnings, custom response bodies órfãos, rules com 0 utilização, anti-patterns conhecidos. Output em terminal + relatório markdown completo. Recomendado executar trimestralmente.
allowed-tools: Bash, Read, TaskCreate, TaskUpdate
argument-hint: [--site=<name>] [--utilization-window=<days>]
---

# /audit-acl — Auditoria proativa de WAF ACL

Você é especialista em AWS WAF auditando uma Web ACL para identificar dívidas
técnicas e antipatterns. Diferente de `/diagnose-edge` (incidente em curso),
este é **proativo** — roda trimestralmente para identificar drift e
recomendações de manutenção.

**Princípio:** read-only completamente. Nenhuma sugestão é aplicada
automaticamente — apenas reportada.

---

## Argumentos

Parse `$ARGUMENTS`:

- `--site=<name>` — site key em `~/.config/bit-bpo/waf-sites.yaml`. Default `concertacao`.
- `--utilization-window=<days>` — janela para checagem de "rules com 0 utilização". Default `30`.
- `--no-cloudtrail` — pula consultas CloudTrail (mais rápido, perde detecção de IPSets stale).

---

## Workflow

### Step 1: Resolver config

Ler `~/.config/bit-bpo/waf-sites.yaml`. Se site não existir, abort.

Crie `OUTPUT_DIR=/tmp/audit-acl-{site}-{unixtime}`.

Variáveis a extrair: `aws_profile`, `web_acl_arn`, `web_acl_name`, `web_acl_id`,
`distribution_id`, `dev_ipset_arn`, `attacker_ipset_arn`, `log_bucket`.

### Step 2: Capturar Web ACL completa

```bash
aws wafv2 get-web-acl --profile "$PROFILE" --scope CLOUDFRONT --region us-east-1 \
  --name "$WEB_ACL_NAME" --id "$WEB_ACL_ID" \
  --output json > "$OUTPUT_DIR/acl.json"
```

Capacidades base:
- `RULES_COUNT=$(jq '.WebACL.Rules | length' acl.json)`
- `CAPACITY=$(jq '.WebACL.Capacity' acl.json)`
- `DEFAULT_ACTION=$(jq -r '.WebACL.DefaultAction | keys[0]' acl.json)`

### Step 3: Análises (em ordem)

#### 3.1 Capacity check

- `ratio = CAPACITY / 1500`
- 🟢 OK: ratio < 0.6
- 🟡 WARN: 0.6 ≤ ratio < 0.8
- 🔴 CRITICAL: ratio ≥ 0.8

Reportar valor absoluto + %.

#### 3.2 Rules com Priority duplicada

```bash
jq '[.WebACL.Rules[] | .Priority] | group_by(.) | map(select(length > 1)) | flatten' acl.json
```

Se houver: error fatal. Mesma priority = ACL inválida (não deveria existir, mas validar).

#### 3.3 Dead code: Allow após Block terminating

Para cada Allow rule, verificar se há Block rule terminating com priority MENOR
que cobre o mesmo path. Se sim, Allow é dead code.

Heurística:
- Para cada `Allow` rule R em priority P_R
- Para cada `Block` rule B em priority P_B < P_R
- Se `B.Statement` matches subset/equal de `R.Statement` (path/host/IPSet) → R é dead code

Implementação simplificada via análise textual (jq + grep) — flagga candidatos
para revisão manual:

```python
# Pseudo-Python
allows = [r for r in rules if 'Allow' in r['Action']]
blocks = [r for r in rules if 'Block' in r['Action']]
for a in allows:
    for b in blocks:
        if b['Priority'] < a['Priority']:
            # Comparar paths/hosts (heurística)
            a_path = extract_uri_match(a)
            b_path = extract_uri_match(b)
            if b_path and a_path and a_path.startswith(b_path):
                report_dead_code(a, b)
```

#### 3.4 Rules duplicadas (mesmo Statement em prioridades diferentes)

Hash do Statement (sem Priority/Name/VisibilityConfig) e agrupar:

```bash
jq '[.WebACL.Rules[] | {priority:.Priority, name:.Name, hash:(.Statement|tostring|@base64)}]' acl.json
```

Rules com mesmo hash em prioridades diferentes = candidatas a consolidar.

#### 3.5 Rules com 0 utilização (last $WINDOW dias)

Para cada rule, consultar `BlockedRequests` ou `CountedRequests` no CloudWatch:

```bash
aws cloudwatch get-metric-statistics --profile "$PROFILE" --region us-east-1 \
  --namespace AWS/WAFV2 --metric-name BlockedRequests \
  --dimensions Name=WebACL,Value="$WEB_ACL_NAME" Name=Rule,Value="$RULE_NAME" Name=Region,Value=CloudFront \
  --start-time "$(date -u -v-${WINDOW}d +%Y-%m-%dT%H:%M:%SZ)" \
  --end-time "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
  --period 86400 --statistics Sum
```

Se Sum total = 0 em $WINDOW dias:
- Allow rules com 0 = OK (significa que ninguém matchou — esperado em rules de exceção)
- Block rules com 0 = candidata a deprecação OR rule cobrindo cenário ainda não exercitado

Reportar lista separada por action.

#### 3.6 IPSets staleness (se --no-cloudtrail não passado)

```bash
# Listar IPSets referenciados pelas rules
jq '[.WebACL.Rules[]
  | recurse(.Statement?, .NotStatement?, .AndStatement?, .OrStatement?, .RateBasedStatement?)
  | select(.IPSetReferenceStatement?)
  | .IPSetReferenceStatement.ARN] | unique' acl.json
```

Para cada IPSet:
```bash
# Última modificação via CloudTrail
aws cloudtrail lookup-events --profile "$PROFILE" --region us-east-1 \
  --lookup-attributes AttributeKey=ResourceName,AttributeValue=<ipset-name> \
  --start-time "$(date -u -v-365d +%Y-%m-%dT%H:%M:%SZ)" \
  --max-results 5
```

🟡 WARN: IPSet não modificado em 90 dias (NordVPN rotaciona IPs)
🔴 CRITICAL: IPSet não modificado em 180 dias

#### 3.7 Block-AttackerRanges com ranges AWS

Se ACL tem IPSet de attacker:
```bash
aws wafv2 get-ip-set --profile "$PROFILE" --scope CLOUDFRONT --region us-east-1 \
  --id <id> --name <name>
```

Para cada CIDR no IPSet, cross-reference com `https://ip-ranges.amazonaws.com/ip-ranges.json`:
- Se range cai em `CLOUDFRONT` ou `EC2` ou `AMAZON` (regions AWS) → 🔴 ALERTA antipattern #3

Pode usar Python inline:
```python
import json, ipaddress, urllib.request
with urllib.request.urlopen('https://ip-ranges.amazonaws.com/ip-ranges.json') as f:
    aws_ranges = json.load(f)
# Para cada CIDR do IPSet, verificar overlap com aws_ranges['prefixes']
```

#### 3.8 Custom Response Bodies órfãos

```bash
# Bodies definidas
jq '.WebACL.CustomResponseBodies | keys' acl.json

# Bodies referenciadas
jq '[.WebACL.Rules[] | recurse | .CustomResponseBodyKey?] | unique | map(select(. != null))' acl.json

# Diff: definidas - referenciadas = órfãs
```

🟡 WARN se houver órfãs (waste de configuração).

#### 3.9 WAF logs habilitados?

```bash
aws wafv2 get-logging-configuration --profile "$PROFILE" --region us-east-1 \
  --resource-arn "$WEB_ACL_ARN" 2>/dev/null
```

🔴 CRITICAL se INATIVO (sem logs, próximo incidente vira 5h investigação como
em 2026-05-04).

#### 3.10 Templates last_reviewed

Ler `templates/manifest.yaml` (se skill `bit-waf` instalada localmente):
```bash
SKILL_DIR=$(dirname $(readlink -f "$0"))/../skills/bit-waf  # ajustar path
yq '.templates.rules[]' "$SKILL_DIR/templates/manifest.yaml"
```

Para cada rule da ACL atual cujo Name corresponde a um template:
- Se `last_reviewed` > 90 dias → 🟡 WARN
- Se `last_reviewed` > 180 dias → 🔴 CRITICAL

### Step 4: Output

Estrutura do output (terminal ≤30 linhas + arquivo completo):

```
═══════════════════════════════════════════════════════════
 AUDIT-ACL  ·  concertacao  ·  ACL-WPAdminHML  ·  18 rules
═══════════════════════════════════════════════════════════

CAPACITY  : 322/1500 WCU (21%) ✅
LOGS S3   : ATIVO (aws-waf-logs-concertacao-prd-use1)
LAST AUDIT: 2026-02-04 (90d ago) — recomendado a cada 90d

DEAD CODE / DUPLICATAS
  [!] Allow-Prod-WPAdmin-Root (priority 10) — Block-NonDev-WPAdmin priority 2
      cobre /wp-admin/* primeiro (terminating) → Allow nunca dispara
  [!] Allow-Prod-WPLogin (priority 11) — mesmo padrão
  [!] Allow-Prod-WPAdmin-Subsite (priority 12) — mesmo padrão
  [~] Block-NonDev-WPAdmin (priority 2) e Block-NonDev-WPAdmin-Prod (priority 13)
      têm Statement similar — consolidar via OrStatement de hosts

UTILIZAÇÃO (last 30d)
  [+] 6 rules com blocks: Block-AggressiveBots, RateLimit-300-Block, ...
  [~] 3 rules com 0 blocks: Block-XMLRPC, AWS-WordPress-ManagedRules, ...
      → revisar se ainda fazem sentido

IPSETS
  [+] NordBrazil90CIDR        — atualizado 12d atrás
  [!] AttackerRanges-2026-03-31 — atualizado 65d atrás
      (Concertação tem ranges AWS CloudFront — antipattern #3)

CUSTOM BODIES
  [+] BIT-Recurso-Indisponivel — referenciado por 4 rules

──────────────────────────────────────────────────────────
3 issues críticas · 5 warnings · 2 info
Full report: /tmp/audit-acl-concertacao-{ts}/report.md
```

Cores:
- `[+]` verde — OK
- `[~]` amarelo — warning, revisar
- `[!]` vermelho — crítico, ação recomendada
- `[i]` azul — info

### Step 5: Salvar relatório completo

`OUTPUT_DIR/report.md` com todos os achados, classificação por severidade,
e recomendações específicas por issue (com link para `playbooks/audit-acl.md`
para guidance).

### Step 6: Apresentar resumo ao usuário

Após gerar relatório:
- Total de issues por severidade (crítico/warning/info)
- Top 3 ações recomendadas
- Caminho do relatório completo

---

## Princípios obrigatórios

1. **READ-ONLY APENAS.** Auditoria não modifica nada.
2. **Não inventar issues.** Reportar apenas evidências objetivas (métricas zero, hashes idênticos, ranges AWS comprovados).
3. **Severidade calibrada.** Critical = ação recomendada esta semana. Warning = próximo trimestre. Info = registro histórico.
4. **Output sem emojis decorativos** — usar `[+]`, `[!]`, `[~]`, `[i]` + cores BIT.
5. **`LC_NUMERIC=C`** antes de printf decimais (locale pt_BR).
6. **Cross-reference templates locais** — comparar rule da ACL atual vs `templates/rules/<name>.json` (se Name match) e flagga drift.

## Gotchas

- **CloudWatch metrics quotas:** se ACL tem >50 rules, batches de 5-10 paralelos para evitar throttling.
- **CloudTrail lookup window:** 90 dias máximo. Para histórico maior, precisaria de CloudTrail Lake (extra).
- **AWS IP ranges file (200KB):** cachear em `/tmp/aws-ip-ranges-$(date +%Y%m%d).json` para evitar re-download.
- **bash 4+ requerido** para arrays associativos em scripts auxiliares.

## Quando recomendar próxima ação

- Issues críticas: `playbooks/deploy-rule.md` para corrigir cirurgicamente
- Warnings: agrupar em backlog para próxima janela trimestral
- Info: somente registrar em `aws/audit-{site}-{date}.md` no repo do site
