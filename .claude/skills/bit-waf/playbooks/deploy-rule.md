# Playbook: Deploy seguro de WAF rule

> **Princípio:** snapshot antes, validate depois. Mudanças WAF em prod são
> reversíveis, mas só se houver baseline em git para diff/rollback.
> Lição: `feedback_aws_changes_audit_trail`.

## Quando usar

- Aplicar template de `templates/rules/*.json` numa ACL
- Modificar rule existente (threshold, scope-down, action)
- Adicionar nova rule em produção

## Sequência obrigatória (5 steps)

### Step 1: Pre-flight

```bash
# Identificar site + carregar config
SITE=concertacao
SKILL_DIR=~/.claude/skills/bit-waf  # se project-only, ajustar para .claude do projeto

# Validar AWS auth + carregar variáveis do waf-sites.yaml
PROFILE=$(python3 -c "import yaml; print(yaml.safe_load(open('$HOME/.config/bit-bpo/waf-sites.yaml'))['sites']['$SITE']['aws_profile'])")
WEB_ACL_ARN=$(python3 -c "import yaml; print(yaml.safe_load(open('$HOME/.config/bit-bpo/waf-sites.yaml'))['sites']['$SITE']['web_acl_arn'])")
WEB_ACL_NAME=$(python3 -c "import yaml; print(yaml.safe_load(open('$HOME/.config/bit-bpo/waf-sites.yaml'))['sites']['$SITE']['web_acl_name'])")
WEB_ACL_ID=$(python3 -c "import yaml; print(yaml.safe_load(open('$HOME/.config/bit-bpo/waf-sites.yaml'))['sites']['$SITE']['web_acl_id'])")

aws sts get-caller-identity --profile "$PROFILE"  # valida auth
```

### Step 2: Snapshot pré-mudança (OBRIGATÓRIO)

```bash
$SKILL_DIR/helpers/snapshot-acl.sh $SITE
```

Isso versiona em git o estado atual completo (CF + WAF + ALB) antes de qualquer
mudança. Se algo der errado, `git show HEAD~1:aws/waf-acl.json` tem o estado
exato pré-mudança.

### Step 3: Construir payload de update

```bash
# Capturar snapshot atual + LockToken
aws wafv2 get-web-acl --profile "$PROFILE" --scope CLOUDFRONT --region us-east-1 \
  --name "$WEB_ACL_NAME" --id "$WEB_ACL_ID" \
  > /tmp/waf-current.json

LOCK=$(jq -r .LockToken /tmp/waf-current.json)
echo "LockToken: $LOCK"

# Modificar rules conforme objetivo (ex: substituir 1 rule por template)
# Exemplo: aplicar template rate-limit-generic.json substituindo a rule existente
TEMPLATE=$(jq '.rule' "$SKILL_DIR/templates/rules/rate-limit-generic.json")

# Substituir placeholders
DEV_IPSET_ARN=$(python3 -c "import yaml; print(yaml.safe_load(open('$HOME/.config/bit-bpo/waf-sites.yaml'))['sites']['$SITE']['dev_ipset_arn'])")
TEMPLATE=$(echo "$TEMPLATE" | sed "s|{{DEV_IPSET_ARN}}|$DEV_IPSET_ARN|g")

# Construir payload completo do update-web-acl
jq --argjson newrule "$TEMPLATE" '
{
  Name: .WebACL.Name,
  Scope: "CLOUDFRONT",
  Id: .WebACL.Id,
  DefaultAction: .WebACL.DefaultAction,
  Rules: [.WebACL.Rules[] | if .Name == $newrule.Name then $newrule else . end],
  VisibilityConfig: .WebACL.VisibilityConfig,
  CustomResponseBodies: .WebACL.CustomResponseBodies,
  LockToken: .LockToken
}' /tmp/waf-current.json > /tmp/waf-update.json
```

### Step 4: Check capacity ANTES de update

```bash
# Validar WCU do payload novo (cada ACL tem limite de 1500 WCU default)
RULES_JSON=$(jq -c .Rules /tmp/waf-update.json)
aws wafv2 check-capacity --profile "$PROFILE" --region us-east-1 \
  --scope CLOUDFRONT --rules "$RULES_JSON"
# Output: { "Capacity": <numero> }
```

Se exceder 1500, abort. Pode pedir aumento de quota AWS, mas é raro precisar.

### Step 5: Apply

```bash
aws wafv2 update-web-acl --profile "$PROFILE" --region us-east-1 \
  --cli-input-json file:///tmp/waf-update.json \
  --query "NextLockToken" --output text
# Output: novo LockToken (gravar para próximas mudanças no mesmo dia)
```

### Step 6: Validate (5min após apply — propagação)

```bash
# Confirmar mudança em produção
aws wafv2 get-web-acl --profile "$PROFILE" --scope CLOUDFRONT --region us-east-1 \
  --name "$WEB_ACL_NAME" --id "$WEB_ACL_ID" \
  | jq '.WebACL.Rules[] | select(.Name=="<nome-da-rule>")'

# Snapshot pós-mudança + commit
$SKILL_DIR/helpers/snapshot-acl.sh $SITE

# Validar comportamento via curl ou Playwright
# (depende da rule — para rate-limit, fazer 100 reqs do mesmo IP em 1min)
```

### Step 7: Monitorar

```bash
# Métricas WAF nos próximos 30min
aws cloudwatch get-metric-statistics --profile "$PROFILE" --region us-east-1 \
  --namespace AWS/WAFV2 --metric-name BlockedRequests \
  --dimensions Name=WebACL,Value="$WEB_ACL_NAME" Name=Rule,Value="<rule-name>" Name=Region,Value=CloudFront \
  --start-time "$(date -u -v-30M +%Y-%m-%dT%H:%M:%SZ)" \
  --end-time "$(date -u +%Y-%m-%dT%H:%M:%SZ)" \
  --period 60 --statistics Sum
```

Esperado:
- BlockedRequests cai para baseline (se fix de rule mal calibrada)
- BlockedRequests sobe (se nova rule foi adicionada para barrar bot conhecido)

## Rollback

Se algo der errado nos próximos 30min:

```bash
# Restaurar snapshot anterior (commit antes do update)
SITE_DIR=~/scripts/server-tools/v2/docker-dev/sites/concertacao
PREVIOUS_COMMIT=$(cd $SITE_DIR && git log --oneline aws/waf-acl.json | sed -n '2p' | awk '{print $1}')
git -C $SITE_DIR show $PREVIOUS_COMMIT:aws/waf-acl.json > /tmp/waf-rollback.json

# Capturar LockToken atual (pós-mudança)
aws wafv2 get-web-acl --profile "$PROFILE" --scope CLOUDFRONT --region us-east-1 \
  --name "$WEB_ACL_NAME" --id "$WEB_ACL_ID" \
  | jq '.LockToken' -r

# Construir payload de rollback (mesma estrutura, mas Rules do snapshot anterior)
jq --slurpfile prev /tmp/waf-rollback.json '
{
  Name: $prev[0].WebACL.Name,
  Scope: "CLOUDFRONT",
  Id: $prev[0].WebACL.Id,
  DefaultAction: $prev[0].WebACL.DefaultAction,
  Rules: $prev[0].WebACL.Rules,
  VisibilityConfig: $prev[0].WebACL.VisibilityConfig,
  CustomResponseBodies: $prev[0].WebACL.CustomResponseBodies,
  LockToken: "<lock-token-atual>"
}' /tmp/waf-rollback.json > /tmp/waf-rollback-payload.json

aws wafv2 update-web-acl --profile "$PROFILE" --region us-east-1 \
  --cli-input-json file:///tmp/waf-rollback-payload.json
```

Tempo de rollback: ~3-5min (propagação CloudFront).

## Antipatterns — NÃO fazer

1. **Update sem snapshot prévio** — perde audit trail
2. **Mudança em horário de pico** — janela curta para corrigir se errar
3. **Skip check-capacity** — pode falhar com WAFLimitExceededException sem feedback claro
4. **Múltiplas mudanças no mesmo update** — se algo quebrar, não sabe qual
5. **Esquecer LockToken** — usar o do `get-web-acl` mais recente; se outro update aconteceu, pega novo
6. **Modificar via Console e CLI no mesmo dia** — LockToken vira inválido sem aviso

## Casos especiais

### Modificar custom response body
Se rule usa `CustomResponseBodyKey`, e o body precisa mudar:
- `CustomResponseBodies` é mapa global da ACL, não da rule
- Atualizar via mesmo `update-web-acl` mas alterando `CustomResponseBodies` no payload
- Bodies referenciados que sumirem causam erro

### Adicionar rule nova (não substituir)
- Append em `Rules[]` no payload
- Garantir que `Priority` é único na ACL
- Considerar onde encaixar (Allow rules antes de Block, conforme padrão)

### Remover rule
- Filtrar do `Rules[]` no payload
- Se rule referencia IPSet, IPSet continua existindo (não deleta junto)
- Para deletar IPSet órfão depois: `aws wafv2 delete-ip-set` (cuidado, pode estar em outras ACLs)

## Referências

- Memory: `feedback_aws_changes_audit_trail.md`
- Manifest: `templates/manifest.yaml` (WCU/priority por template)
- AWS docs: [UpdateWebACL API](https://docs.aws.amazon.com/waf/latest/APIReference/API_UpdateWebACL.html)
