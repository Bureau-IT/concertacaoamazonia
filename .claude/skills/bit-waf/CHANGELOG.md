# bit-waf — CHANGELOG

## 1.1.0 — 2026-05-04 (Fatia 2)

### Adicionado
- `templates/manifest.yaml` — metadata consolidado (WCU/priority/action/source) das 9 rules + scope-downs + roadmap de review trimestral
- `templates/rules/rate-limit-generic.json` — rate-based 600/5min com scope-down WP completo (CRITICAL — sem isso WP satura)
- `templates/rules/rate-limit-wplogin-post.json` — anti-brute-force POST /wp-login.php 50/5min via FORWARDED_IP
- `templates/rules/block-xmlrpc.json` — block /xmlrpc.php (cuidado: Jetpack/WPML XMLRPC sync)
- `templates/rules/allow-devteam-wpadmin.json` — Allow priority 0 com IPSet DevTeam + host match
- `templates/rules/block-nondev-wpadmin.json` — Block /wp-admin para todo IP fora do IPSet (custom response 403 com BIT-Recurso-Indisponivel)
- `templates/rules/allow-admin-ajax.json` — Allow /wp-admin/admin-ajax.php (necessario para Elementor/JetEngine/Complianz)
- `templates/rules/block-aggressive-bots.json` — UA match para 8 bots conhecidos (SemrushBot, AhrefsBot, MJ12bot, bytespider, Amazonbot, PetalBot, DotBot, DataForSeoBot) → 429
- `templates/rules/block-meta-externalagent.json` — block meta-externalagent em paths HTML mas LIBERA /wp-content/uploads/ (open graph)
- `templates/rules/aws-managed-wordpress.json` — AWSManagedRulesWordPressRuleSet com OverrideAction None
- `templates/scope-downs/wp-static-paths.json` — NotStatement reutilizavel: NOT (wp-content OR wp-includes OR favicon.ico OR robots.txt)
- `templates/patterns/bot-uas.txt` — lista curada de UAs para rotacao independente (mantida fora do JSON para versionamento simples)

### Notas
- Todos JSONs com `_meta` field documentando version, source_incident, wcu_estimate, applies_to, last_reviewed, placeholders, notes
- SearchString fields ja em base64 (formato exigido por aws wafv2 update-web-acl --cli-input-json)
- Total WCU estimado dos 9 templates: ~144 (limite default AWS = 1500, folga grande)
- AI bots (ClaudeBot, GPTBot, PerplexityBot, Google-Extended) NAO incluidos no Block-AggressiveBots — decisao por cliente. Concertacao mantem desbloqueado para advocacy ambiental
- Templates testados teoricamente contra schema AWS WAFv2 — aplicacao real requer validate via `aws wafv2 check-capacity` antes do update

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
