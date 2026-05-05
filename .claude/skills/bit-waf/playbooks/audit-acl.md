# Playbook: Auditoria de Web ACL (trimestral)

> **Princípio:** auditoria proativa = manutenção preventiva. Identifica drift,
> dead code, IPSets desatualizados, antipatterns antes que virem incidente.
> Recorrência recomendada: a cada 90 dias.

## Quando rodar

- **Trimestralmente** (calendário fixo: 1º dia útil de fev/mai/ago/nov)
- Após mudança grande na ACL (> 3 rules adicionadas/removidas)
- Após incidente de segurança em qualquer site BIT (auditar todos)
- Antes de revisão de orçamento AWS (capacity planning)

## Sequência

### 1. Rodar `/audit-acl`

```
/audit-acl
```

Ou customizado:
```
/audit-acl --site=concertacao --utilization-window=90
```

Output gera relatório em `/tmp/audit-acl-{site}-{ts}/report.md` com 9 áreas
de check.

### 2. Triagem por severidade

#### Críticos (`[!]`) — agir esta semana

| Issue | Ação |
|-------|------|
| WAF logs S3 INATIVO | `helpers/enable-waf-logs.sh <site>` |
| Capacity > 80% | Consolidar rules duplicadas (ver §3) |
| Block-AttackerRanges com ranges AWS | Remover ranges AWS do IPSet (anti-pattern #3) |
| Allow rule é dead code há > 6 meses | Remover (ver §3) |
| IPSet não modificado há > 180 dias | Validar se ainda relevante (NordVPN rotaciona) |
| Templates last_reviewed > 180 dias | Comparar com versão atual em `templates/rules/` |

#### Warnings (`[~]`) — agir no trimestre

- Rules duplicadas (mesmo Statement em prioridades diferentes)
- Rules com 0 utilização nos últimos 30 dias (deprecação candidata)
- Custom Response Bodies órfãos
- IPSet não modificado há 90-180 dias

#### Info (`[i]`) — só registrar

- Rules adicionadas/removidas desde última auditoria
- Mudança de capacity vs auditoria anterior
- Top blocking rules nos últimos 30 dias

### 3. Aplicar fixes

#### Remover dead code

```bash
# Snapshot pré
helpers/snapshot-acl.sh <site>

# Get current
aws wafv2 get-web-acl ... > /tmp/current.json

# Construir payload SEM as rules dead-code
jq '
  .WebACL | {
    Name, Scope: "CLOUDFRONT", Id,
    DefaultAction,
    Rules: [.Rules[] | select(.Name | test("^(Allow-Prod-WPAdmin-Root|Allow-Prod-WPLogin)$") | not)],
    VisibilityConfig, CustomResponseBodies
  } + {LockToken: .LockToken}
' /tmp/current-full.json > /tmp/cleanup.json

aws wafv2 update-web-acl --cli-input-json file:///tmp/cleanup.json ...
```

#### Consolidar rules duplicadas

Exemplo: `Block-NonDev-WPAdmin` (priority 2) e `Block-NonDev-WPAdmin-Prod`
(priority 13) com mesmo Statement, diferentes hosts.

Substituir por **uma rule** com `OrStatement` de hosts:

```json
{
  "Name": "Block-NonDev-WPAdmin-AllHosts",
  "Priority": 2,
  "Statement": {
    "AndStatement": {
      "Statements": [
        { "NotStatement": { "Statement": { "IPSetReferenceStatement": {...DEV_IPSET...} } } },
        { "ByteMatchStatement": { "SearchString": "L3dwLWFkbWlu", "FieldToMatch": {"UriPath": {}}, ... } },
        { "OrStatement": { "Statements": [
          { "ByteMatchStatement": { "SearchString": "<HML_HOST_B64>", "FieldToMatch": {"SingleHeader": {"Name": "host"}}, ... } },
          { "ByteMatchStatement": { "SearchString": "<PROD_HOST_B64>", "FieldToMatch": {"SingleHeader": {"Name": "host"}}, ... } }
        ]}}
      ]
    }
  },
  "Action": { "Block": {...} },
  "VisibilityConfig": {...}
}
```

Aplicar via `helpers/apply-rule.sh` em modo `replace` + remover a duplicada.

#### Remover ranges AWS do IPSet

```bash
# Get current addresses
aws wafv2 get-ip-set --profile "$PROFILE" --scope CLOUDFRONT --region us-east-1 \
  --id <id> --name <name> --query 'IPSet.{Lock:LockToken,Addrs:Addresses}'

# Remover ranges AWS (ex: 64.252.x.x, 3.172.x.x são CloudFront)
NEW_ADDRS='["<addr-real-1>","<addr-real-2>"]'  # SEM os AWS

aws wafv2 update-ip-set --profile "$PROFILE" --scope CLOUDFRONT --region us-east-1 \
  --id <id> --name <name> --lock-token <lock> --addresses $NEW_ADDRS
```

### 4. Salvar relatório no repo

Ao final da auditoria, copiar relatório para o repo do site:

```bash
SITE_REPO=~/scripts/server-tools/v2/docker-dev/sites/<site>
mkdir -p "$SITE_REPO/aws/audits"
cp /tmp/audit-acl-<site>-<ts>/report.md "$SITE_REPO/aws/audits/audit-$(date +%Y-%m-%d).md"
git -C "$SITE_REPO" add aws/audits/
git -C "$SITE_REPO" commit -m "docs(aws): audit ACL $(date +%Y-%m-%d)"
```

Histórico de auditorias permite trending — número de issues caindo
trimestre a trimestre.

### 5. Atualizar `last_reviewed` nos templates

Para cada template aplicado/validado durante auditoria, bumpar
`_meta.last_reviewed` para data de hoje em `templates/rules/<name>.json`.

Atualizar também `templates/manifest.yaml`:
```yaml
last_review: 2026-08-04
next_review: 2026-11-04
```

### 6. Próximo passo se incidentes recorrentes

Se a auditoria revelar 5+ issues críticas, considerar:
- Promover review para mensal por 3 meses até estabilizar
- Adicionar AWSManagedRulesAmazonIpReputationList em Count mode
- Avaliar custo-benefício de Bot Control ($23/mês para 13M reqs)

## Antipatterns — NÃO fazer

1. **Pular auditoria por "está tudo bem"** — drift acumula silenciosamente
2. **Aplicar todas as recomendações de uma vez** — uma mudança por vez,
   validar antes da próxima
3. **Remover rules sem snapshot pré** — `helpers/snapshot-acl.sh` é mandatório
4. **Auditar durante incidente em curso** — usar `/diagnose-edge` em vez disso
5. **Confiar em "0 utilização"** sem validar contexto — algumas rules existem
   exatamente para evitar exploit raro mas catastrófico

## Referências

- Slash command: `commands/audit-acl.md`
- Template metadata: `templates/manifest.yaml`
- Anti-templates: `docs/anti-templates.md`
- Helpers: `apply-rule.sh`, `snapshot-acl.sh`
- Memory: `feedback_aws_changes_audit_trail.md`, `feedback_skill_bit_waf.md`
