# ADR — CloudFront ErrorCachingMinTTL + S3 Cache-Control para 403 (Concertação)

**Status:** Accepted
**Date:** 2026-05-04
**Deciders:** Daniel Cambría (com auditoria de 10 agentes IA)
**Distribution:** `E2F1QD7E7YOYEB` (concertacaoamazonia.com.br + www)
**Bucket:** `concertacaoamazonia-com-br-wp-static-prd-sa`

---

## Context

Em 2026-05-04 entre 14:31-14:36 BRT, usuários reais brasileiros em guia anônima (IPs `186.220.197.37`, `200.158.183.67`) receberam página estilizada "ACESSO NEGADO 403" tentando acessar a home pública do site. Investigação identificou:

1. AWS WAF `OnSourceDDoSProtectionConfig.ALBLowReputationMode: ACTIVE_UNDER_DDOS` ativou auto-bloqueio de IPs residenciais BR (CGNAT compartilhado com botnets na Amazon IP Reputation List)
2. CloudFront `CustomErrorResponses` para 403 estava configurado com `ErrorCachingMinTTL: 60s`, apontando para `/error-403.html` no bucket S3
3. Cache de 60s nas POPs prolongou impacto: usuários continuaram vendo 403 mesmo após mitigação WAF cessar; 4xxErrorRate caiu de 79% para 28% e oscilou por 7+ minutos pós-incidente
4. AWS WAFv2 API não permite desativar `ALBLowReputationMode` (enum só aceita `ACTIVE_UNDER_DDOS` ou `ALWAYS_ON`); única forma destrutiva é recriar Web ACL inteira

## Decision

Defesa em camadas para o cache de error response 403:

**Camada 1 — CloudFront edge cache (ErrorCachingMinTTL):**

| ErrorCode | ResponsePagePath | ErrorCachingMinTTL |
|-----------|------------------|--------------------|
| 403 | /error-403.html | **5s** (era 60s) |
| 404 | (default) | 60s |
| 502 | /error-502.html | 30s |
| 503 | /error-503.html | 30s |
| 504 | /error-504.html | 30s |

**Camada 2 — S3 object Cache-Control (`error-403.html`):**
- Antes: `public, max-age=300`
- Depois: **`no-store, max-age=0`**

### Por que 5s e não 0s

Iteração da decisão durante o dia:
1. **17:51 UTC:** TTL aplicado como **0s** — fix imediato do incidente
2. **18:25 UTC:** auditoria de 10 agentes identificou 2 problemas:
   - `0s` expõe origin a reconnaissance amplification (scan de `/wp-config.php`, `/.env` etc. sem cache mascarando)
   - `Cache-Control: max-age=300` herdado do S3 fazia browser/proxies cachearem 5min mesmo com edge TTL=0
3. **18:28 UTC:** decisão refinada — TTL=5s (sweet spot dos auditores) + S3 `no-store, max-age=0` (corta cache downstream)

### Por que `no-store` no S3
`ErrorCachingMinTTL` controla apenas cache da CF edge. O `Cache-Control` da error response chega ao browser do usuário. Com `max-age=300` herdado do S3, browser cacheava 5min mesmo após mitigação central — replicava o problema do incidente em outra camada.

**Aplicação final:**
- CF update aplicado 2026-05-04 ~18:28 UTC (deploy concluído ~18:34), ETag base `E18U8859HHN70N`
- S3 metadata replace 2026-05-04 ~18:28 UTC, novo VersionId `CEcCQ5ZzQQhTdfkiccvpNba70pA8jZ2E`
- CF invalidation `I17FFNPX4LTJOYVTTOGS14SCEB`

## Consequences

### Positivas
- False-positive de 403 não fica cacheado por 60s nas POPs após mitigação
- Auto-recuperação alinhada com ciclo real do bloqueio WAF (segundos, não minutos)
- Editores logados em wp-admin não ficam presos em cauda de cache durante incidentes

### Negativas (a monitorar)
- **Reconnaissance amplification:** atacante prova `/wp-config.php`, `/.env`, `/.git/HEAD` em loop sem cache mascarando. Mitigação parcial via nginx `cf_crawlers` 2r/s + `RateLimit-300-Block` per-IP do WAF, mas distribuído (botnet 100 IPs) varre wordlist completa em ~3min
- **Origin requests sobem em incidente sustentado:** WAF cobra ~$0.60/M evals; pessimista 10 incidentes/mês = ~$22 extra. Trivial
- **S3 egress potencial** se `Cache-Control` do `error-403.html` no bucket estiver ausente (pendente validação)

### Score auditoria
- TTL=0 puro: 6/10 (decisão defensável mas reativa, expõe origin a recon)
- **TTL=5 + S3 `no-store`: 8.5/10** (defesa em camadas, alinhado com 3 auditores independentes que recomendaram sweet spot 5s; corrige ponto cego do header herdado)

## Alternatives Considered

### A. Manter ErrorCachingMinTTL=60s
**Rejeitado:** preserva problema do incidente original; cauda de impacto > janela de bloqueio WAF.

### B. ErrorCachingMinTTL=5s
**Considerado fortemente:** sweet spot entre auto-recuperação e proteção contra recon. Não aplicado por enquanto — aplicaremos como ajuste se telemetria pós-mudança mostrar elevação de probing.

### C. Desligar `ALBLowReputationMode`
**Bloqueado por API AWS** (enum não tem `DISABLED`). Único caminho é recriar ACL — destrutivo, exige detach do CloudFront, fora do escopo emergencial.

### D. Allow rule priority 0 com IPSet LACNIC BR
**Rejeitado** após auditoria: Allow é terminating action e bypassaria `Block-NonDev-WPAdmin/WPLogin/XMLRPC/AttackerRanges`, quebrando modelo de segurança. Cobertura LACNIC BR também é incompleta (~60-70%) porque CGNAT mobile rota via Cloudflare/Akamai.

### E. AWS Shield Advanced
**Rejeitado:** $3000/mês = 15× o stack AWS atual da Concertação. Desproporcional para fundação ambiental sem ataques DDoS reais documentados.

## Validation

Comando de validação pós-deploy (após CF status=Deployed):
```bash
curl -sI "https://concertacaoamazonia.com.br/wp-config.php?nocache=$RANDOM" | grep -iE "^HTTP|x-cache|age|cache-control"
# Esperado:
#   HTTP/2 403
#   cache-control: no-store, max-age=0    ← do S3 (camada 2)
#   x-cache: Error from cloudfront         ← CF respeita ErrorCachingMinTTL=5s (camada 1)
```

**Validação executada 2026-05-04 ~18:32 UTC:** ✅ todos os asserts passaram.

Métricas a monitorar 7 dias:
- `CloudFront 4xxErrorRate` — não deve subir vs baseline
- `ALB OriginRequests` — pode subir até +20% sem ser problema (4xx volta ao origin a cada 5s ao invés de 60s)
- `ALB TargetResponseTime p95` — não deve passar 1.5s sustentado
- `CloudFront BytesDownloadedFromOrigin` — pode subir marginalmente (S3 `no-store` significa CF baixa o HTML do erro mais frequentemente quando há cache miss; mas error-403.html só é servido em incidentes)

## Rollback

Estado pré-mudança preservado em `aws/cf-distribution.json` (commit anterior). Para reverter completamente:

```bash
# 1. Reverter S3 Cache-Control para max-age=300
aws s3 cp s3://concertacaoamazonia-com-br-wp-static-prd-sa/error-403.html \
  s3://concertacaoamazonia-com-br-wp-static-prd-sa/error-403.html \
  --profile "Concertação" \
  --cache-control "public, max-age=300" \
  --content-type "text/html; charset=utf-8" \
  --metadata-directive REPLACE

# 2. Reverter CF ErrorCachingMinTTL para 60
aws cloudfront get-distribution-config --profile "Concertação" --id E2F1QD7E7YOYEB > /tmp/cf-current.json
ETAG=$(jq -r .ETag /tmp/cf-current.json)
jq '.DistributionConfig | (.CustomErrorResponses.Items[] | select(.ErrorCode==403).ErrorCachingMinTTL) |= 60' \
  /tmp/cf-current.json > /tmp/cf-revert.json
aws cloudfront update-distribution --profile "Concertação" --id E2F1QD7E7YOYEB \
  --if-match "$ETAG" --distribution-config "$(jq .DistributionConfig /tmp/cf-revert.json)"

# 3. Invalidar
aws cloudfront create-invalidation --profile "Concertação" --distribution-id E2F1QD7E7YOYEB --paths "/error-403.html"
```
Propagação: ~5-10min globalmente.

## Related

- Memory: `feedback_cf_error_caching_ttl.md`, `feedback_waf_source_ddos_nondeterministic.md`, `feedback_aws_changes_audit_trail.md`
- Incidentes correlatos: `feedback_meta_crawler_block.md`, `feedback_referer_literal_bot_block.md`
- Auditoria 10 agentes: 04/05/2026
