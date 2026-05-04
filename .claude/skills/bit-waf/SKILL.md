---
name: bit-waf
description: Operação AWS WAF para sites BIT (project-only, escopo Concertação). Inclui slash command `/diagnose-edge` para diagnóstico empírico, helpers shell para snapshot/enable-logs, templates de rules WordPress, playbooks de incidente. Use quando precisar diagnosticar incidente edge AWS, modificar WAF rule em produção, auditar ACL, ou criar bucket de logs WAF.
---

# bit-waf — Operação AWS WAF (Concertação)

Skill **project-only** para o site Concertação. Documenta o método empírico de
diagnóstico e operação WAF aprendido após o incidente de 2026-05-04.

## Quando usar

- Usuário relata 403/erro de acesso ao site
- 4xxErrorRate ou 5xxErrorRate fora do baseline
- Necessidade de modificar Web ACL (rule, IPSet, threshold)
- Auditoria recorrente da ACL
- Setup de logging WAF S3 num site novo

## Arquitetura

```
.claude/skills/bit-waf/
├── SKILL.md              ← este arquivo (entrypoint)
├── README.md             ← guia humano detalhado
├── docs/                 ← referência técnica (managed rules, anti-patterns)
├── playbooks/            ← passo a passo de operações comuns
├── templates/            ← rules WordPress, scope-downs, IPSets
└── helpers/              ← shell scripts reutilizáveis
    ├── enable-waf-logs.sh
    └── snapshot-acl.sh

.claude/commands/
└── diagnose-edge.md      ← slash command operacional

~/.config/bit-bpo/waf-sites.yaml  ← mapping site → AWS config
```

## Princípios

1. **Read-only primeiro.** Coletar evidência empírica antes de hipotetizar.
   Hipóteses sem dataset são teatro (lição do incidente 04/05/2026).
2. **Snapshot sempre.** Antes de qualquer update na Web ACL, rodar
   `helpers/snapshot-acl.sh concertacao` — versiona estado atual em git para
   rollback exato.
3. **Scope-down obrigatório** em rate-based rules per-IP (excluir
   `/wp-content/`, `/wp-includes/`, `/favicon.ico`). WordPress carrega
   80-150 sub-requests por página.
4. **Invalidação cirúrgica.** Nunca invalidar CloudFront com `/*` (memory
   `feedback_cloudfront_invalidation.md`).
5. **WAF logs S3 antes de hipótese.** Se logs não habilitados, primeiro
   passo é habilitar e aguardar 5-10min.

## Workflows comuns

### Incidente edge em curso
1. `/diagnose-edge` (read-only, ~30-45s)
2. Avaliar VERDICT
3. Se `edge-anomaly` por rate-limit: ver `playbooks/incident-diagnose.md`
4. Snapshot antes de qualquer mudança: `helpers/snapshot-acl.sh concertacao`
5. Aplicar fix + validar

### Habilitar logs WAF num site sem logging
1. `helpers/enable-waf-logs.sh concertacao` (idempotente)
2. Aguardar 5-10min para primeiros logs S3 chegarem

### Auditoria de ACL
- Adiada para v1.2 (não emergencial)

## Memory entries relacionadas

- `feedback_waf_ratelimit_static_paths.md`
- `feedback_waf_source_ddos_nondeterministic.md`
- `feedback_wp_rocket_min_cf_stale.md`
- `feedback_aws_changes_audit_trail.md`
- `feedback_incident_diagnostic_discipline.md`
- `feedback_cloudfront_invalidation.md`

## Versionamento

Esta skill segue semver. Mudanças registradas em `CHANGELOG.md`.

Versão atual: **1.0.0** (Fatia 1 — diagnose-edge + helpers)

## Escopo

Project-only para Concertação. Para usar em outros sites BIT (mombak,
escoladobairro, www-concertacao, elosbensevalores), copiar manualmente
para `.claude/skills/bit-waf/` no repo do site e adicionar entry em
`~/.config/bit-bpo/waf-sites.yaml`.
