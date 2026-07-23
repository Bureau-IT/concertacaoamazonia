---
description: Auditoria proativa de WAF Web ACL + CloudFront behaviors + ALB target groups — detecta dead code, rules duplicadas, IPSets desatualizados, CF origins legacy (drift pós-cutover), TG targets unhealthy/legacy, anti-patterns conhecidos. Output em terminal + relatório markdown completo. Recomendado executar trimestralmente OU pós-cutover blue-green.
allowed-tools: Bash, Read, TaskCreate, TaskUpdate
argument-hint: [--site=<name>] [--utilization-window=<days>] [--no-cloudtrail] [--skip-cf-checks] [--skip-alb-checks]
---

# /waf-check — Auditoria proativa de WAF ACL

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
- `--skip-cf-checks` — pula steps 3.11 + 3.12 (CF behaviors + origins). Útil se sem permissão `cloudfront:GetDistributionConfig`.
- `--skip-alb-checks` — pula step 3.13 (ALB target health). Útil se sem permissão `elbv2:Describe*`.

---

## Workflow

### Step 1: Resolver config

Ler `~/.config/bit-bpo/waf-sites.yaml`. Se site não existir, abort.

Crie `OUTPUT_DIR=/tmp/waf-check-{site}-{unixtime}`.

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

Para cada rule, consultar `BlockedRequests` ou `CountedRequests` no CloudWatch.

> **CRÍTICO — dimensões.** Use **apenas** `WebACL` + `Rule`. **NÃO** inclua
> `Name=Region,Value=CloudFront`: essa dimensão não é publicada nesta conta e o
> filtro não casa com nada, retornando **Sum=0 para TODAS as rules**. Bug real
> em 2026-07-22: as 18 rules apareceram como "0 hits", o que levaria à conclusão
> falsa de "WAF ocioso, 8 rules candidatas a deprecação" — quando na verdade
> Block-AggressiveBots tinha 29.265 blocks. **Sempre validar antes** com:
> `aws cloudwatch list-metrics --namespace AWS/WAFV2 --metric-name BlockedRequests --query 'Metrics[0:5]'`
> e usar exatamente o conjunto de dimensões que aparecer.

```bash
aws cloudwatch get-metric-statistics --profile "$PROFILE" --region us-east-1 \
  --namespace AWS/WAFV2 --metric-name BlockedRequests \
  --dimensions Name=WebACL,Value="$WEB_ACL_NAME" Name=Rule,Value="$RULE_NAME" \
  --start-time "$(date -u -v-${WINDOW}d +%Y-%m-%dT%H:%M:%SZ)" \
  --end-time "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
  --period 86400 --statistics Sum
```

**Sanity check obrigatório:** se *todas* as rules retornarem 0, isso é sinal de
query errada — não de WAF ocioso. Investigar as dimensões antes de reportar.

Se Sum total = 0 em $WINDOW dias:
- Allow rules com 0 = OK (significa que ninguém matchou — esperado em rules de exceção)
- Block rules com 0 = candidata a deprecação OR rule cobrindo cenário ainda não exercitado
  **OU rule quebrada** — checar encoding do `SearchString` (ver 3.15)

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

#### 3.11 CloudFront behaviors apontando para origins legacy

Pattern recorrente pós-cutover blue-green (memo `feedback_cf_oac_green_to_assets_swap`):
behaviors prod continuam apontando para origin de stage (`/green/uploads`)
após cutover. Detecta:

```bash
aws cloudfront get-distribution-config --id "$DIST_ID" --profile "$PROFILE" | \
  jq -r '.DistributionConfig as $c |
    ($c.Origins.Items | map(select(.OriginPath | test("/green/")) | .Id) | unique) as $green |
    $c.CacheBehaviors.Items[] |
    select(.PathPattern | test("wp-content/uploads/[^_]")) |
    select(.PathPattern | test("_oac-canary") | not) |
    select(.TargetOriginId as $t | $green | index($t)) |
    "\(.PathPattern) -> \(.TargetOriginId)"'
```

🔴 CRITICAL se output não-vazio: uploads novos ficam invisíveis no CDN.
Recovery: ver memória `feedback_cf_oac_green_to_assets_swap`.

✅ OK: vazio. Behaviors `_oac-canary/*` excluídos (uso legítimo de stage).

#### 3.12 CloudFront origins não-referenciadas (dead origins)

Origins definidas mas sem behavior apontando:

```bash
aws cloudfront get-distribution-config --id "$DIST_ID" --profile "$PROFILE" | \
  jq -r '.DistributionConfig as $c |
    ($c.Origins.Items | map(.Id)) as $all_origins |
    (($c.CacheBehaviors.Items | map(.TargetOriginId)) + [$c.DefaultCacheBehavior.TargetOriginId] | unique) as $used |
    $all_origins | map(select(. as $o | $used | index($o) | not))[]'
```

🟡 WARN se output não-vazio. Origins órfãs:
- Custam nada diretamente mas adicionam complexidade
- Podem virar attack vector se DNS apontar para recurso externo

Memória: `feedback_aws_changes_audit_trail`.

#### 3.13 ALB Target Groups com targets unhealthy/legacy

Cross-reference TG do ALB com instâncias EC2 esperadas (blue + green ativos):

```bash
ALB_ARN=$(aws elbv2 describe-load-balancers --profile "$PROFILE" --region "$ALB_REGION" \
  --names "$ALB_NAME" --query 'LoadBalancers[0].LoadBalancerArn' --output text)
TG_ARNS=$(aws elbv2 describe-target-groups --profile "$PROFILE" --region "$ALB_REGION" \
  --load-balancer-arn "$ALB_ARN" --query 'TargetGroups[*].TargetGroupArn' --output text)

for TG in $TG_ARNS; do
  aws elbv2 describe-target-health --profile "$PROFILE" --region "$ALB_REGION" \
    --target-group-arn "$TG" \
    --query 'TargetHealthDescriptions[].{Target:Target.Id,State:TargetHealth.State,Reason:TargetHealth.Reason}'
done
```

Achados a flagar:
- 🔴 CRITICAL: target em estado `unhealthy` há > 1h (memo `project_concertacao_prod_infra` aponta
  i-0450b4bb01221ea24 como `Target.InvalidState` pré-existente).
- 🟡 WARN: target em estado `unused` há > 7 dias (provavelmente blue antiga não desregistrada
  pós-cutover — memo `feedback_blue_green_tg_cleanup`).
- 🟡 WARN: target group sem registered targets (TG órfão pós-cutover).

Cross-check contra EC2 tags (deve ter tag `Role=PROD` ou similar):
```bash
aws ec2 describe-instances --profile "$PROFILE" --region "$ALB_REGION" \
  --instance-ids <target-id> \
  --query 'Reservations[].Instances[].Tags[?Key==`Role`].Value' --output text
```

🔴 CRITICAL se target ativo no TG-PROD mas EC2 com tag `Role=PROD-OLD`
(pós-cutover esquecido).

#### 3.14 Bucket S3 legacy us-east-1 — os 3 assets restaurados respondem 200?

> **MUDANÇA 2026-07-22.** O decommission de 2026-05-22 deixou **105 refs órfãs**
> no DB apontando para 3 arquivos, que passaram a dar HTTP 403 em páginas
> publicadas. **Decisão do Daniel: restaurar os arquivos no lugar onde as URLs
> procuram**, em vez de search-replace nos 105 registros. Portanto **refs no DB
> agora são ESPERADAS e não são mais achado** — inverteu-se o que este gate mede.

O bucket `s3://concertacaoamazonia.com.br/` (us-east-1) **voltou a servir prod**.
Não deletar (ver memo `feedback_s3_bucket_concertacaoamazonia_us_east_decommission`).

**O check correto — os 3 assets respondem 200 público:**
```bash
for k in "indio-do-brasil.mp3" \
         "10000000_596334691590556_9065865888567422840_n.mp4" \
         "Uma+agenda+pelo+Desenvolvimento+da+Amazonia.pdf"; do
  code=$(curl -sI -o /dev/null -w "%{http_code}" --max-time 20 \
    "https://s3.amazonaws.com/concertacaoamazonia.com.br/$k")
  printf "%s %s\n" "$code" "$k"
done
```

🔴 CRITICAL se algum ≠ 200: conteúdo quebrado em `/atuacao/faq/`,
`/historia-da-concertacao/`, plenárias e 3 templates Elementor.
Recuperar do tombstone (fonte original preservada):
```bash
aws s3 cp "s3://concertacaoamazonia.com.br/_tombstoned_20260522-005438/<file>" \
          "s3://concertacaoamazonia.com.br/<file>" --acl public-read --region us-east-1
```
O `--acl public-read` é essencial: o bucket não tem policy e usa ACL por objeto
(sem ownership controls = modo ObjectWriter legado). Objetos sem esse grant = 403.

🔴 CRITICAL se surgir uma chave legacy **nova** (≠ das 3 conhecidas): significa
conteúdo novo publicado com URL do bucket legacy. Aí sim vale corrigir na origem.
Enumerar cobrindo `wp_postmeta` (Elementor!), `wp_posts` e `wp_2_posts` — a
varredura só em `post_content` perde ~96% das refs.

🟡 WARN se o tombstone `_tombstoned_20260522-005438/` (7 objetos) sumir — é a
fonte de recuperação. **Não tem mais TTL: manter indefinidamente.**

**Gotcha `+` vs espaço:** a URL usa `Uma+agenda+...pdf`, a chave S3 tem espaços.
S3 path-style decodifica `+` como espaço — a URL resolve. Não criar variante com
`+` literal. (Em search-replace de nomes com espaço, `+`-encoded é uma **4ª
variante**, além de plain/JSON-escaped/URL-encoded.)

✅ OK: 0 refs no DB e tombstone com idade ≤ 30 dias.

#### 3.15 SearchString double-encoded (rule silenciosamente inerte)

A API do WAF devolve `SearchString` em base64. Se alguém aplicar uma rule já
codificando o valor manualmente, ela vira **double-encoded**: a rule passa a
procurar a *string base64* literal no tráfego, casa com nada e fica inerte —
sem erro, sem alarme, com métrica 0.

Bug real encontrado em 2026-07-22: `Block-TikTokSpider` procurava
`VGlrVG9rU3BpZGVy` (base64 de `TikTokSpider`) no User-Agent. 0 blocks em 30d,
contra 3.159 no histórico anterior.

```bash
# Decodificar 1x e verificar se o resultado AINDA é base64 de ASCII legível
jq -r '.WebACL.Rules[] | .Name as $n
  | [.. | objects | select(has("SearchString")) | .SearchString][]
  | "\($n)\t\(.)"' acl.json | sort -u \
| while IFS=$'\t' read -r name s; do
    d1=$(printf '%s' "$s" | base64 -d 2>/dev/null || true)
    d2=$(printf '%s' "$d1" | base64 -d 2>/dev/null || true)
    # só é double-encoding se o 2º decode der ASCII imprimível (>=3 chars)
    if [[ -n "$d2" ]] && printf '%s' "$d2" | LC_ALL=C grep -qE '^[[:print:]]{3,}$'; then
      printf "  [!] %-28s '%s' -> DOUBLE -> '%s'\n" "$name" "$d1" "$d2"
    fi
  done
```

🔴 CRITICAL se houver match: a rule não protege nada. Re-aplicar com o valor em
texto puro (a AWS codifica sozinha).

**Falso positivo a evitar:** nomes como `Baiduspider`/`AhrefsBot` estão no
alfabeto base64 por acaso e "decodificam" para bytes binários. Por isso o filtro
exige que o 2º decode seja **ASCII imprimível** — só aí é double-encoding real.

Cross-check barato: rule de Block com 0 hits + histórico anterior > 0 é o
sintoma clássico.

### Step 4: Output

Estrutura do output (terminal ≤30 linhas + arquivo completo):

```
═══════════════════════════════════════════════════════════
 WAF-CHECK ·  concertacao  ·  ACL-WPAdminHML  ·  18 rules
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
Full report: /tmp/waf-check-concertacao-{ts}/report.md
```

Cores:
- `[+]` verde — OK
- `[~]` amarelo — warning, revisar
- `[!]` vermelho — crítico, ação recomendada
- `[i]` azul — info

### Step 5: Salvar relatório completo

`OUTPUT_DIR/report.md` com todos os achados, classificação por severidade,
e recomendações específicas por issue (com link para `playbooks/waf-check.md`
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
