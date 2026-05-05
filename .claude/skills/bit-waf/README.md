# bit-waf — Operação AWS WAF (Concertação)

> Skill **project-only** para o site Concertação. Para usar em outros sites BIT,
> copie esta pasta e adicione entry em `~/.config/bit-bpo/waf-sites.yaml`.

## Quick start

### Diagnóstico de incidente em curso
```
/diagnose-edge
```
Roda em ~30-45s. Output com VERDICT no topo + relatório completo em
`/tmp/diagnose-edge-concertacao-{ts}.md`.

Argumentos:
- `--window=180` — janela em minutos (default 60)
- `--ip=186.220.197.37` — filtra por IP (útil quando usuário reporta)
- `--no-origin` — pula origin health (se SSH lento)

### Snapshot AWS antes de update prod
```bash
.claude/skills/bit-waf/helpers/snapshot-acl.sh concertacao
```
Cria `aws/cf-distribution.json` + `aws/waf-acl.json` + `aws/alb.json` no repo
do site e commita com mensagem padrão. Use `--no-commit` para skip git.

### Habilitar logs WAF S3 num site sem logging
```bash
.claude/skills/bit-waf/helpers/enable-waf-logs.sh concertacao
```

### Aplicar template de rule em produção (com diff + rollback)
```bash
# Dry-run primeiro (recomendado) — mostra diff e capacity sem aplicar
.claude/skills/bit-waf/helpers/apply-rule.sh concertacao rate-limit-generic --dry-run

# Aplicar (interativo, pede confirmação 'sim')
.claude/skills/bit-waf/helpers/apply-rule.sh concertacao rate-limit-generic

# Adicionar rule nova ao invés de substituir
.claude/skills/bit-waf/helpers/apply-rule.sh concertacao block-meta-externalagent --mode=append
```
Snapshot pré + pós automático. Diff visual. Capacity check. Validação pós-update.
Comando de rollback exibido ao final.
Idempotente — re-run em site com logs ativos não quebra. Cria bucket
`aws-waf-logs-{site}-prd-use1` + lifecycle 30d/90d + AES256 + public
access block.

## Princípios

1. **Read-only primeiro.** Coletar evidência antes de hipotetizar.
2. **Snapshot sempre antes de update.** Rollback exato via git.
3. **Scope-down obrigatório** em rate-based rules per-IP em sites WordPress.
4. **Invalidação cirúrgica.** Nunca CloudFront `/*`.

## Arquivos

| Arquivo | Função |
|---------|--------|
| `SKILL.md` | Entrypoint Claude (descrição + workflow) |
| `README.md` | Este arquivo (humano) |
| `CHANGELOG.md` | Histórico de versões |
| `commands/diagnose-edge.md` | Slash command (em `.claude/commands/`) |
| `helpers/enable-waf-logs.sh` | Setup de logging WAF S3 |
| `helpers/snapshot-acl.sh` | Snapshot AWS config + git commit |
| `playbooks/incident-diagnose.md` | (v1.1) passo a passo de diagnóstico |
| `playbooks/deploy-rule.md` | (v1.1) deploy seguro de rule |
| `templates/rules/*.json` | (v1.1) templates de rules WordPress |
| `docs/managed-rule-groups.md` | (v1.2) referência AWS managed |
| `docs/anti-templates.md` | (v1.2) "NÃO fazer" — antipatterns |

## Config

`~/.config/bit-bpo/waf-sites.yaml` — mapping site → AWS profile + IDs.

Schema:
```yaml
sites:
  concertacao:
    aws_profile: "Concertação"
    region: us-east-1
    distribution_id: E2F1QD7E7YOYEB
    web_acl_arn: arn:aws:wafv2:us-east-1:.../webacl/...
    web_acl_id: 05522267-...
    web_acl_name: ACL-WPAdminHML
    dev_ipset_arn: arn:aws:wafv2:.../ipset/NordBrazil90CIDR/...
    log_bucket: aws-waf-logs-concertacao-prd-use1
    ssh_alias: concertacaoamazonia.com.br-prod-sa
    web_root: /var/www/concertacaoamazonia.com.br
    alb_name: amazonia-alb
    alb_region: sa-east-1
```

## Memory entries relacionadas

Lições incorporadas nesta skill:

- `feedback_waf_ratelimit_static_paths.md` — antipattern de rate-limit sem scope-down
- `feedback_waf_source_ddos_nondeterministic.md` — `ALBLowReputationMode` é ALB-only
- `feedback_wp_rocket_min_cf_stale.md` — WP Rocket cache stale via CloudFront
- `feedback_aws_changes_audit_trail.md` — snapshot em git antes de update
- `feedback_incident_diagnostic_discipline.md` — read-only queries antes de hipótese
- `feedback_cloudfront_invalidation.md` — nunca CF `/*`
