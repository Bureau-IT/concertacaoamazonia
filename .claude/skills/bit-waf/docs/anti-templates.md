# Anti-templates — o que NÃO fazer em WAF

> Lista curada de antipatterns observados em ACLs WAF de sites WordPress
> BIT. Cada entry com causa raiz documentada e workaround correto.

## 1. Rate-limit per IP em "all requests" sem scope-down

### Problema
Rule rate-based contando TODAS as requests de um IP (sem scope-down)
dispara para usuários reais navegando sites WordPress modernos.

### Por que falha
WordPress + Elementor + JetEngine + WPML carrega 80-150 sub-requests
por page load (CSS, JS, fontes, imagens, assets de plugins). Plugin
`instant-page` (Elementor Pro) faz prefetch silencioso ao hover.
Threshold de 300/5min estoura em 4-5 páginas de navegação.

### Sintoma
Usuário real reportando 403 ao navegar (não em ação específica). WAF
logs mostram `terminatingRuleId: RateLimit-300-Block` em IPs residenciais
brasileiros (CGNAT) com pattern de navegação humana.

### Fix correto
```json
"ScopeDownStatement": {
  "AndStatement": {
    "Statements": [
      { "NotStatement": { "Statement": { "IPSetReferenceStatement": {...} } } },
      { "NotStatement": { "Statement": { "OrStatement": { "Statements": [
          {"ByteMatchStatement": {"SearchString": "L3dwLWNvbnRlbnQv", ...}},
          {"ByteMatchStatement": {"SearchString": "L3dwLWluY2x1ZGVzLw==", ...}},
          {"ByteMatchStatement": {"SearchString": "L2Zhdmljb24uaWNv", ...}}
      ]}}}}
    ]
  }
}
```

Ver `templates/rules/rate-limit-generic.json`.

### Source
- Memory: `feedback_waf_ratelimit_static_paths.md`
- Incidente: 2026-05-04 (5h investigação até identificar)

---

## 2. Allow rule priority 0 com IPSet de país inteiro

### Problema
Tentar contornar bloqueios criando Allow rule priority 0 com
IPSet abrangente (ex: LACNIC BR — 6500 CIDRs).

### Por que falha
**Allow é terminating action** — termina avaliação WAF imediatamente
quando dispara. Se Allow priority 0 com `IPSet=BR-Country` casar, TODAS
as rules abaixo são puladas, incluindo:
- `Block-NonDev-WPAdmin` → atacante BR ganha acesso a `/wp-admin`
- `Block-XMLRPC` → bypass total
- `RateLimit-WPLogin-POST` → brute-force livre
- `AWSManagedRulesWordPressRuleSet` → exploits WP funcionam

### Sintoma após aplicar
Atacante brasileiro (CGNAT comum) consegue brute-force `/wp-admin`,
explorar exploits conhecidos, etc.

### Fix correto
- **NÃO usar Allow priority 0 com IPSet amplo.**
- Para escopo de DevTeam: usar IPSet pequeno e específico (NordVPN
  CIDRs do team) + AndStatement com path `/wp-admin/` (não toda
  request)
- Para tráfego BR legítimo passar por proteções: ajustar threshold
  da rule de Block, não bypassar

### Source
- Considerada em incidente 2026-05-04 e descartada após auditoria

---

## 3. IPSet attacker contendo ranges AWS/CloudFront

### Problema
Bloquear ranges como `64.252.0.0/16` ou `3.172.0.0/16` esperando bloquear
"atacantes" — esses são IPs de POPs CloudFront.

### Por que falha
- CloudFront edge POPs têm IPs nessas faixas
- Bloquear = self-DoS (CloudFront tentando alcançar origin de IPs próprios
  cai no Block)
- Pode quebrar X-Test-Green, blue-green deploys, signed URLs

### Sintoma
- 4xxErrorRate alto sem causa óbvia
- Tráfego CloudFront caindo (CF tentando re-route, falha)
- ALB recebe quase nada

### Fix correto
- Atacker IPSets devem conter apenas IPs **comprovadamente maliciosos**
  (Spamhaus, AbuseIPDB feeds, ranges identificados em incidente real)
- Cross-reference com [AWS IP Ranges](https://ip-ranges.amazonaws.com/ip-ranges.json)
  antes de adicionar — excluir CloudFront/ALB ranges

### Source
- ACL Concertação tem `Block-AttackerRanges-2026-03-31` com 4 ranges
  `64.252.x.x` + `3.172.x.x` — auditor identificou como dívida técnica;
  validação cross-reference com CF prefix list pendente

---

## 4. Rules duplicadas em prioridades diferentes (dead code)

### Problema
ACL com `Block-NonDev-WPAdmin` em priority 2 E `Block-NonDev-WPAdmin-Prod`
em priority 13 (mesmo padrão de match).

### Por que falha
- Block é terminating — a primeira casar termina avaliação
- Rule em priority 13 só dispara se priority 2 NÃO casar (mas se ambas
  têm mesmo match, são idênticas — segunda nunca dispara)
- Confusão para próximo operador
- WCU desperdiçado

### Sintoma
- WAF metrics mostram blocks na rule de priority menor sempre, na maior
  sempre 0
- Auditoria de ACL identifica "dead code"

### Fix correto
- Consolidar em única rule via OrStatement de hosts (HML + Prod)
- Ou deletar a duplicada

### Source
- ACL Concertação tem essas duplicações — auditoria documentada como
  dívida técnica em ADR `2026-05-04-adr-cloudfront-error-caching-ttl.md`

---

## 5. Allow rule depois de Block terminating (prioridade errada)

### Problema
ACL com `Block-NonDev-WPAdmin` priority 2 E `Allow-Prod-WPAdmin` priority 10.

### Por que falha
- Block priority 2 dispara primeiro (é terminating)
- Allow priority 10 NUNCA dispara (Block já terminou avaliação)
- Allow virou dead code

### Sintoma
- Métrica `BlockedRequests` da Block rule alta
- Métrica `AllowedRequests` da Allow rule sempre 0
- Confusão: "achei que Allow estivesse cobrindo prod"

### Fix correto
- Allow rule precisa ter priority **MENOR** que Block que ela quer
  excepcionar
- Padrão: Allow specific (priority baixa) → Block geral (priority alta)
- Exemplo: Allow `/wp-admin/admin-ajax.php` priority 1, Block
  `/wp-admin/*` (não-DevTeam) priority 2

### Alternativa
- Em vez de Allow + Block separadas, usar Block com scope-down NotStatement:
  `Block where uri starts_with /wp-admin AND NOT uri ends_with /admin-ajax.php`
- Menos rules, menos confusão

### Source
- ACL Concertação tem Allow priority 10/11/12 após Block priority 2/3 —
  identificado como dead code em auditoria

---

## 6. Mexer em `OnSourceDDoSProtectionConfig` em ACL CLOUDFRONT scope

### Problema
Tentar desligar `ALBLowReputationMode` em ACL associada a CloudFront
distribution acreditando que vai parar de bloquear IPs CGNAT BR.

### Por que falha
- `OnSourceDDoSProtectionConfig` é feature **ALB-only** (GA jun/2025)
- Em ACL CLOUDFRONT scope, o campo é **metadado cosmético** — aparece
  como default forçado mas não atua
- API rejeita `DISABLED` (enum só aceita `ACTIVE_UNDER_DDOS` ou `ALWAYS_ON`)
- Tentativa de omitir o campo no `update-web-acl` resulta em sticky
  config (preserva valor antigo silenciosamente)

### Sintoma
- 4h+ investigação culpando feature errada
- Tentativas de update via CLI/Console rejeitadas ou sem efeito visível

### Fix correto
- **Ignorar** o campo em ACLs CLOUDFRONT scope
- Causa de bloqueios em CloudFront vem de outras fontes:
  - Rules custom da própria ACL (90% dos casos — investigar SampledRequests)
  - AWS Shield Standard automatic mitigation (raro, não loga)
  - AWSManagedRulesAntiDDoSRuleSet (se referenciada)

### Source
- Memory: `feedback_waf_source_ddos_nondeterministic.md`
- Incidente 2026-05-04 (4h investigando hipótese errada)

---

## Como adicionar novo antipattern aqui

Quando incidente ou auditoria revelar antipattern novo:

1. Documentar em **memory feedback** primeiro (`feedback_waf_*.md`)
2. Após 90 dias de observação, se padrão se confirma, promover para
   este arquivo com:
   - **Problema:** descrição em 1-2 frases
   - **Por que falha:** mecanismo técnico
   - **Sintoma:** o que observa em métricas/logs
   - **Fix correto:** alternativa que funciona
   - **Source:** memory feedback + ADR + incidente
3. Atualizar `templates/manifest.yaml` se template precisar evoluir
4. Bumpar versão da skill

## Referências

- Memory entries: `feedback_waf_*`, `feedback_cf_*`, `feedback_aws_changes_audit_trail`
- Manifest: `templates/manifest.yaml`
- Playbooks: `playbooks/incident-diagnose.md`, `playbooks/deploy-rule.md`
