# bit-waf — CHANGELOG

## 1.0.0 — 2026-05-04 (Fatia 1)

Primeira versão. MVP com diagnóstico empírico + helpers de operação.

### Adicionado
- `SKILL.md`, `README.md`
- `commands/diagnose-edge.md` — slash command read-only para diagnóstico de
  incidente edge (CloudFront + WAF + ALB) com VERDICT no topo do output.
  Inclui origin health snapshot (ALB + Aurora + FPM) em paralelo via SSH +
  CloudWatch metrics + WAF SampledRequests + CloudTrail UpdateWebACL +
  WAF logs S3 aggregation (se habilitado).
- `helpers/enable-waf-logs.sh` — setup idempotente de logging WAF S3
  (bucket + lifecycle 30d→Glacier/90d→expire + AES256 + public access
  block + PutLoggingConfiguration).
- `helpers/snapshot-acl.sh` — snapshot CloudFront + WAF ACL + ALB para
  `sites/<site>/aws/` e commit em git. Flag `--no-commit` para skip git.
- `~/.config/bit-bpo/waf-sites.yaml` — mapping site → AWS config (entry
  `concertacao` completa).

### Lições incorporadas (memory feedbacks)
- Read-only queries primeiro, hipótese depois
  (`feedback_incident_diagnostic_discipline`)
- Snapshot AWS config em git antes de update prod
  (`feedback_aws_changes_audit_trail`)
- Rate-limit per IP em WP DEVE ter scope-down excluindo paths estáticos
  (`feedback_waf_ratelimit_static_paths`)
- `ALBLowReputationMode` só atua em ALB (`feedback_waf_source_ddos_nondeterministic`)
- WP Rocket cache stale via CloudFront após edição de mu-plugin
  (`feedback_wp_rocket_min_cf_stale`)
- Nunca invalidar CloudFront com `/*` (`feedback_cloudfront_invalidation`)

### Não incluído (adiado para próximas versões)
- v1.1: 8 templates de rules JSON com placeholders + manifest.yaml
- v1.1: `playbooks/incident-diagnose.md` + `playbooks/deploy-rule.md`
- v1.2: `playbooks/audit-acl.md` + `/audit-acl` slash command
- v1.2: `docs/managed-rule-groups.md` + `docs/anti-templates.md`
