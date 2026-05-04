# AWS Managed Rule Groups — referência

> Referência das managed rule groups disponíveis pela AWS (vendor `AWS`) que
> fazem sentido para sites WordPress BIT. Outras vendor managed groups
> (Fortinet, F5, Imperva) custam extra — não usar sem orçamento aprovado.

## Convenção de uso

```json
{
  "Name": "AWS-<RuleSetName>",
  "Priority": <N>,
  "Statement": {
    "ManagedRuleGroupStatement": {
      "VendorName": "AWS",
      "Name": "<ManagedRuleSetName>"
    }
  },
  "OverrideAction": {"None": {}},
  "VisibilityConfig": { ... }
}
```

`OverrideAction.None` mantém comportamento default. Para auditar antes de
ativar block, usar `{"Count": {}}` por 7-14 dias.

## Recomendados para WordPress BIT

### `AWSManagedRulesWordPressRuleSet` (✅ usado)

WCU: ~50-100. Custo: zero (managed).

**Inclui (subrules):**
- `WordPressBadAuth_BODY` — ataques contra wp-login.php
- `WordPressExploitableCommands_QUERYARGUMENTS` — exploits conhecidos via QS
- `WordPressExploitablePaths_URIPATH` — paths sensíveis (wp-config.php, .env, etc)

**Risco:** pode bloquear admin-ajax.php legítimo se body contiver pattern
suspeito. Se houver report de admin quebrado, considerar
`RuleActionOverrides` para subrule específica.

**Aplicação:** `templates/rules/aws-managed-wordpress.json`.

### `AWSManagedRulesAmazonIpReputationList` (⚠️ avaliar)

WCU: ~25. Custo: zero.

**O que faz:** bloqueia IPs da Amazon IP Reputation List + Spamhaus DROP
+ outras feeds AWS internas.

**Por que avaliar:** durante incidente 2026-05-04, suspeitamos que era essa
lista bloqueando CGNAT BR. Refutado depois (era `RateLimit-300-Block`),
mas vale ativar em **modo Count** por 7 dias para mapear quantos IPs BR
residenciais caem na lista. Se for >1%, não ativar em Block.

**Estado atual:** não ativada na Concertação.

### `AWSManagedRulesCommonRuleSet` (✅ recomendado base)

WCU: ~700. Custo: zero.

**O que faz:** OWASP Top 10 genérico — XSS, SQLi básico, file inclusion,
size constraints. Fundação de proteção web genérica.

**Risco:** WCU pesado (~50% do limite default 1500) — checar se cabe
junto com outras rules.

**Estado atual:** não ativada (tem AWSManagedRulesWordPressRuleSet que
cobre WP-specific; Common adicional pode ser overkill).

### `AWSManagedRulesKnownBadInputsRuleSet` (✅ baixo custo)

WCU: ~200. Custo: zero.

**O que faz:** payloads conhecidos de exploits recentes (CVEs últimos
12 meses).

**Estado atual:** não ativada — vale considerar adicionar.

### `AWSManagedRulesSQLiRuleSet`

WCU: ~200. Custo: zero.

Não necessário se já usa `AWSManagedRulesCommonRuleSet` (que inclui
detecção SQLi).

### `AWSManagedRulesAdminProtectionRuleSet` (❌ não usar)

WCU: ~100. Custo: zero.

**Por que NÃO:** bloqueia paths admin genéricos (`/admin`, `/login`,
`/setup`) — útil para apps customizadas, mas conflita com WordPress
(`/wp-admin/` não é coberto, mas o ruleset bloqueia patterns que confundem
com admin-ajax e plugins).

Para WordPress, melhor usar `Block-NonDev-WPAdmin` custom (já existe).

### `AWSManagedRulesBotControlRuleSet` (💰 pago)

Custo: $10/mês fixo + $1/M reqs analyzed.

**O que faz:** detecta bots via JA3 fingerprint, browser anomalies,
verified bot list (Googlebot, Bingbot, etc.).

**Trade-off:** $10-23/mês para Concertação. **Não recomendado no MVP.**
Se incidentes recorrentes de bot continuarem, ativar **Common Inspection
Level** em modo Count por 7 dias antes de comprometer com Block.

**Estado atual:** descartado (orçamento ONG).

## Pago (avaliar caso a caso)

### `AWSManagedRulesAnti DDoSRuleSet`

Custo: $1/M reqs avaliadas + WCU pesado.

Distinto de `OnSourceDDoSProtectionConfig.ALBLowReputationMode` (que é
ALB-only). Anti-DDoS group atua em CLOUDFRONT scope mas custa.

**Não usar** sem incidente DDoS comprovado e orçamento aprovado.

## Workflow para ativar managed rule group nova

1. Adicionar com `OverrideAction.Count` (não Block)
2. Aguardar 7-14 dias de tráfego real
3. Analisar `CountedRequests` por subrule via CloudWatch:
   ```bash
   aws cloudwatch get-metric-statistics --namespace AWS/WAFV2 \
     --metric-name CountedRequests \
     --dimensions Name=WebACL,Value=<acl> Name=Rule,Value=<ManagedRuleSetName>
   ```
4. Se false-positive rate < 1%, mudar para `OverrideAction.None` (Block)
5. Se false-positive rate > 5%, manter Count + investigar subrule causadora
6. Pode usar `RuleActionOverrides` para excluir subrules específicas
   sem desabilitar o group inteiro

## Antipatterns

❌ Ativar managed rule group direto em Block sem Count primeiro
❌ Múltiplos managed groups que se sobrepõem (Common + WordPress + KnownBadInputs ao mesmo tempo sem audit)
❌ Bot Control sem orçamento aprovado pelo cliente

## Referências

- AWS docs: [Managed rule groups list](https://docs.aws.amazon.com/waf/latest/developerguide/aws-managed-rule-groups-list.html)
- AWS docs: [Override managed rule actions](https://docs.aws.amazon.com/waf/latest/developerguide/web-acl-rule-group-override-options.html)
