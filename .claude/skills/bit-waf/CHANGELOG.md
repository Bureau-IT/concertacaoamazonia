# bit-waf — CHANGELOG

## 1.2.1 — 2026-05-04 (patch após teste real)

### Refinamentos do `/diagnose-edge` baseados em teste end-to-end

Validacao do MVP em concertacao (estado normal) revelou 3 issues nao bloqueadores
mas que poluiam output. Spec atualizada com workarounds explicitos.

### Mudancas em `commands/diagnose-edge.md`

- **Step 2 (origin health) — SSH timeout:** removido `timeout 10 ssh ...`
  (comando `timeout` nao existe em macOS). Substituido por opcoes nativas SSH:
  `-o ConnectTimeout=5 -o ServerAliveInterval=3 -o ServerAliveCountMax=2`.

- **Step 7 (S3 logs) — subdirs por minuto:** snippet de aggregation atualizado
  com `find -exec cat {} +` ao inves de `cat *.log`. AWS particiona logs WAF
  em subdiretorios `<HH>/00/`, `<HH>/05/` etc — recursive find e mandatorio.

- **Princípios + Gotchas — adicionados:**
  - Item 8: `LC_NUMERIC=C` ou `export LC_NUMERIC=C` antes de `printf` com
    decimais (locale pt_BR quebra `%.2f`).
  - Secao "Gotchas conhecidos" documentando macOS vs Linux differences,
    locale pt_BR, e S3 logs subdirs.

### Validacao

Teste end-to-end em concertacao (estado normal) executou todos 8 steps:
- VERDICT correto: `inconclusive` (4xx avg 14.9%, threshold edge-anomaly = 30%)
- 143 blocks identificados (todos Block-AggressiveBots de Singapore — UA
  Bytespider/Android-spoof, comportamento esperado)
- RateLimit-300-Block = 0 (fix de hoje confirmado em producao)
- Origin health: ALB healthy, FPM ativo, 5xx=0
- WAF logs S3 fluindo (5 arquivos / 540 events na ultima hora)
- Tempo total real: ~3min (otimizavel com mais paralelizacao em v1.3)

## 1.2.0 — 2026-05-04 (Fatia 3)

### Adicionado
- `playbooks/incident-diagnose.md` — passo a passo executavel para diagnostico
  de incidente edge AWS. Sequencia: rodar /diagnose-edge → interpretar VERDICT
  → branch por tipo (origin-degraded vs edge-anomaly vs mixed vs inconclusive)
  → identificar rule responsavel → proximos passos. Inclui scripts curtos para
  agregacao de WAF logs S3 quando logs estao habilitados.
- `playbooks/deploy-rule.md` — workflow seguro de aplicacao de WAF rule em prod.
  7 steps: pre-flight auth → snapshot pre-mudanca → construir payload →
  check-capacity → apply → validate → monitor 30min. Rollback documentado via
  git show de commit anterior. Casos especiais: custom response body, adicionar
  rule nova, remover rule.
- `docs/managed-rule-groups.md` — referencia das AWS managed rule groups
  relevantes para WordPress (AWSManagedRulesWordPressRuleSet usado, outros
  avaliados). Workflow para ativar nova managed rule (Count → 7-14d → Block).
  Bot Control descartado por orcamento.
- `docs/anti-templates.md` — 6 antipatterns documentados com causa raiz +
  sintoma + fix correto + source: rate-limit sem scope-down, Allow priority 0
  amplo, IPSet attacker com ranges AWS, rules duplicadas, Allow apos Block,
  mexer em OnSourceDDoSProtectionConfig em CF scope.
- Memory: `feedback_skill_bit_waf.md` registrando criacao da skill e como usar.

### Notas
- Fatia 3 fecha o MVP da skill. Restam apenas:
  - v1.3 (futuro): helper apply-rule.sh com diff visual + rollback automatizado
  - v1.4 (futuro): /audit-acl slash command + playbooks/audit-acl.md
- Templates ainda nao foram aplicados em produção a partir da skill — proximo
  uso real sera o teste de fogo da Fatia 1+2+3.

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
