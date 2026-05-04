# AWS Config Snapshots — concertacaoamazonia.com.br

Snapshots versionados das configurações AWS críticas. Permite rollback exato e diff visual via `git log -p`.

## Arquivos

| Arquivo | Recurso | Comando de regeneração |
|---------|---------|------------------------|
| `cf-distribution.json` | CloudFront `E2F1QD7E7YOYEB` | `aws cloudfront get-distribution-config --profile "Concertação" --id E2F1QD7E7YOYEB` |
| `waf-acl.json` | WAFv2 `ACL-WPAdminHML` (us-east-1, CLOUDFRONT scope) | `aws wafv2 get-web-acl --scope CLOUDFRONT --region us-east-1 --profile "Concertação" --name ACL-WPAdminHML --id 05522267-513d-4346-8e56-ba18b11e950b` |
| `alb.json` | ALB `amazonia-alb` (sa-east-1) | `aws elbv2 describe-load-balancers --profile "Concertação" --region sa-east-1 --names amazonia-alb` |

## Workflow obrigatório antes de update prod

```bash
cd ~/scripts/server-tools/v2/docker-dev/sites/concertacao/aws/

# 1. Snapshot atual (renomear com timestamp se quiser preservar histórico)
aws cloudfront get-distribution-config --profile "Concertação" --id E2F1QD7E7YOYEB > cf-distribution.json
aws wafv2 get-web-acl --scope CLOUDFRONT --region us-east-1 --profile "Concertação" \
  --name ACL-WPAdminHML --id 05522267-513d-4346-8e56-ba18b11e950b > waf-acl.json

# 2. Commit
git add docker-dev/sites/concertacao/aws/
git commit -m "chore(aws): snapshot pré-update <descrição>"

# 3. Aplicar mudança AWS

# 4. Snapshot pós-mudança
aws cloudfront get-distribution-config --profile "Concertação" --id E2F1QD7E7YOYEB > cf-distribution.json

# 5. Diff visual
git diff cf-distribution.json
git commit -am "chore(aws): pós-update <descrição>"
```

## Cron semanal opcional (captura drift de mudanças manuais)

Adicionar em `~/.config/bit-bpo/servertools/cron-snapshots.sh` rodando domingo 04h:
- regenera os 3 JSONs
- `git diff --quiet` — se não vazio, commit automático com mensagem "chore(aws): drift detectado YYYY-MM-DD"

## Histórico relevante

- **2026-05-04 14:55 BRT** — primeiro snapshot. Estado:
  - CF `ErrorCachingMinTTL` para 403 = `0` (mudado de 60s neste mesmo dia ~17:51 UTC)
  - WAF `OnSourceDDoSProtectionConfig.ALBLowReputationMode` = `ACTIVE_UNDER_DDOS`
  - Incidente que motivou a mudança: 2026-05-04 14:31-14:36 BRT — false-positive WAF source-DDoS bloqueando IPs residenciais BR
  - Ver: ADR `docs/superpowers/specs/2026-05-04-adr-cloudfront-error-caching-ttl.md`
