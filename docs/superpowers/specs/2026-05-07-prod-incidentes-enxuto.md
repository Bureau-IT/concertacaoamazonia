# Plano Enxuto — Mitigação Concertação (1 semana)

**Data:** 2026-05-07
**Autor:** Daniel Cambría
**Status:** ready-to-execute
**Substitui na prática:** `2026-05-06-prod-incidentes-mitigation-plan.md` (rev3 fica como arquivo de decisões registradas — 13 auditores)

---

## Por que enxuto

Devil's advocate (Auditor 3.5 do Ciclo 3) detectou: 13 auditores × 6h auditoria + 687 linhas de spec = meta-trabalho > execução. Sistema já estável desde 2026-05-02 (Item 16 dedupe TEC, mu-plugin v1.1.0, Referer regex v1.15.1, Redis drop-in). Plano denso vira checklist abandonada.

**Decisão:** executar 3 ações em 1 semana. Reavaliar em 14 dias com dados dos alarmes.

---

## Estado atual confirmado em prod

- ✅ Item 16 dedupe `previous_ecp_versions` (1.4 KB / 11 entries)
- ✅ mu-plugin `bit-tec-versions-dedupe` v1.1.0 (commit `3954de059`)
- ✅ nginx Referer block v1.15.1 sem `/?` (commit `be1840599`)
- ✅ Smoke rev2 Fase 7.9 / gate 19b (commit `b58794929`)
- ✅ Slowlog FPM ativo
- ✅ Redis drop-in `object-cache.php` (DBSIZE > 80k, hit rate 86%)

**Único incidente desde 2026-05-02:** YandexBot 504 em 2026-05-05 03:09 BRT (1.636 reqs/17min em `/veiculo/*`), descoberto retroativamente porque alarmes 5XX **não existem** ainda.

---

## 3 Ações

### Ação 1 — Alarmes 5XX no bit-monitoring (P0, 2h)

**Por que primeiro:** sem isso, todo resto opera às cegas. É a única ação **observável**.

**Onde:** dashboard bit-monitoring → Admin → Infra Alerts → Notification Rules → New Rule.

**5 NotificationRules** apontando para resources concertação (instance `i-02b3a897d355953f3`, target group `concertacao-prod-tg`, Aurora cluster `amazonia-aurora-db-cluster`):

| Nome | Trigger | Threshold | Severity |
|------|---------|-----------|----------|
| ALB Target 5XX High | `aws.alb.http_5xx_count` | > 50 / 5min | critical |
| ALB Target 5XX Medium | `aws.alb.http_5xx_count` | > 10 / 5min | warning |
| ALB Response Time | `aws.alb.target_response_time` | p95 > 8s | warning |
| ALB Unhealthy Hosts | `aws.alb.unhealthy_hosts` | > 0 | critical |
| RDS CPU Critical | `aws.rds.cpu_utilization` | > 90% | critical |

**Campos UI obrigatórios por rule:**
- Trigger Type: `CLOUDWATCH_METRIC_THRESHOLD`
- Channel IDs: copiar do "Host Metric Alert" existente
- Cooldown: 15min

**Validação:** `curl https://concertacaoamazonia.com.br/wp-content/inexistente-$(date +%s)` em loop por 60s — gera 404 (não 5XX). Para testar 5XX real sem `stop nginx`: aguardar próximo evento natural OU monitorar 7d em modo `warning` antes de promover `critical` para SMS.

**Rollback:** deletar rule via UI. < 1min.

---

### Ação 2 — Bloquear YandexBot no nginx (P0, 30min)

**Por que:** vetor demonstrado em 2026-05-05 (1.6k reqs/17min). Mesmo padrão de bloqueio meta-externalagent v1.12.2 já operacional.

**Onde:** `ec2-deploy/post-deploy/03-nginx-sites.sh` v1.15.1 → v1.16.0.

**Map a adicionar em `nginx.conf` (depois de `deny_meta_html`):**
```nginx
map "$http_user_agent::$request_uri" $deny_yandex_html {
    default 0;
    "~*YandexBot.*::(?!/wp-content/uploads/)" 1;
}
```

**`if` no `location /` (antes do `limit_req`):**
```nginx
if ($deny_yandex_html = 1) { return 429; }
```

**Validação:**
```bash
# Bot bloqueado
curl -s -o /dev/null -w '%{http_code}' \
  -H 'User-Agent: Mozilla/5.0 (compatible; YandexBot/3.0)' \
  -H 'Host: concertacaoamazonia.com.br' \
  http://127.0.0.1/veiculo/sumauma/
# Esperado: 429

# YandexImages liberado em /uploads/
curl -s -o /dev/null -w '%{http_code}' \
  -H 'User-Agent: YandexBot/3.0' \
  -H 'Host: concertacaoamazonia.com.br' \
  http://127.0.0.1/wp-content/uploads/2024/foo.jpg
# Esperado: 200 (ou 404 se arquivo não existir, mas não 429)
```

**Rollback:** reverter `03-nginx-sites.sh` para v1.15.1, `nginx -t && systemctl reload nginx`. < 2min.

---

### Ação 3 — Cron warmer com flock (P1, 2h)

**Por que:** WP Rocket purge_cron é 10h. Sem warmer, cold cache window vira gatilho de saturação se crawler aparecer no momento errado.

**Pré-step:** `d4-cache-warmup.sh` ainda não está em `/opt/deploy/` em prod.

```bash
rsync -av ec2-deploy/post-deploy/d4-cache-warmup.sh \
  concertacaoamazonia.com.br-prod-sa:/tmp/d4-cache-warmup.sh
ssh concertacaoamazonia.com.br-prod-sa "
  sudo mv /tmp/d4-cache-warmup.sh /opt/deploy/ && \
  sudo chmod 750 /opt/deploy/d4-cache-warmup.sh && \
  sudo chown root:root /opt/deploy/d4-cache-warmup.sh"
```

**Crontab idempotente:**
```bash
ssh concertacaoamazonia.com.br-prod-sa "
  sudo -u www-data crontab -l 2>/dev/null > /tmp/cron.bak || true
  grep -q d4-cache-warmup /tmp/cron.bak && exit 0
  echo '5 3,13 * * * flock -n /var/lock/d4-warmup.lock /opt/deploy/d4-cache-warmup.sh --max=600 --pages-only >> /var/log/d4-warmup.log 2>&1' >> /tmp/cron.bak
  sudo -u www-data crontab /tmp/cron.bak
  rm /tmp/cron.bak"
```

**Notas:**
- `flock -n` apenas (não `-w`; mutuamente exclusivos no flock util-linux)
- `--max=600` para sitemap multisite blog 1+2 da concertação (~600 URLs)
- 03:05 e 13:05 BRT (5min após purge horário do WP Rocket)

**Logrotate:**
```bash
ssh concertacaoamazonia.com.br-prod-sa "sudo tee /etc/logrotate.d/d4-warmup > /dev/null <<'EOF'
/var/log/d4-warmup.log {
    daily rotate 14 compress missingok notifempty
    create 640 www-data www-data
}
EOF"
```

**Validação:**
```bash
# Aguardar próxima hora 03:05 ou 13:05 (ou trigger manual)
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo /opt/deploy/d4-cache-warmup.sh --max=10 --pages-only --dry-run"
# Esperado: lista 10 URLs sem erro
```

**Rollback:** remover linha do crontab. < 1min.

---

## Cronograma 1 semana

| Dia | Ação | Tempo |
|-----|------|-------|
| Hoje (07/05) | Ação 1 — alarmes bit-monitoring | 2h |
| Amanhã (08/05) | Ação 2 — YandexBot block + commit + deploy prod | 30min |
| Sex (09/05) | Ação 3 — warmer cron + logrotate | 2h |
| Sáb-Dom (10-11/05) | Buffer + observação spontânea | — |
| Seg (12/05) | Validação inicial | 30min |

**Total: ~5h Daniel.**

---

## Critério de fechamento

**Plano DONE quando:**
- ✅ 3 Ações deployed em prod
- ✅ 14 dias zero alarme `ALB Target 5XX > 50/5min` disparado
- ✅ Zero ticket de cliente sobre lentidão/erro

**Trigger de re-avaliação:**
- Se ≥ 1 incidente 5XX detectado pelos alarmes em 14 dias → retomar rev3 (`2026-05-06-prod-incidentes-mitigation-plan.md`) com Tier P1 (Ações 4-7) e Tier P2 (Ação 8 com Grant)
- Se zero incidente → arquivar plano enxuto como concluído. Re-avaliar em 90 dias.

---

## O que ficou de fora (e por quê)

| Item da rev3 | Por que cortado |
|--------------|-----------------|
| Ação 2 robots.txt /veiculo/ | Cosmético — bot malicioso ignora; bot legítimo já obedece |
| Ação 5 smoke health adicional | Smoke rev2 Fase 7.9 já cobre cenário crítico |
| Ação 6 robots.txt CPTs/QSs | Sobreposto com Ação 2; mesma pendência cliente |
| Ação 7 WAF FORWARDED_IP | Reativar se Ação 1 (nginx) deixar gap após 14d observação |
| Ação 8 Bot Control | Condicional ao AWS Activate Grant (45-90d) — fora do escopo de 1 sem |
| Anexo A BIT-wide rollout | Re-avaliar após validar concertação (Mombak/cop30 podem ter YandexBots ocultos próprios) |

**Spec rev3 preservado** como referência técnica em `2026-05-06-prod-incidentes-mitigation-plan.md` — decisões registradas valem.

---

## Próximo passo após este plano

Se 14d zero incidente: liberar Daniel para auditar logs dos outros 5 sites BIT (mombak, cop30casamazonia, www-concertacao, edb, escoladobairro). Provável encontrar mais YandexBots/crawlers ocultos lá — onde alarmes também não existem.

**Hipótese a validar:** o que aprendemos em concertação aplica a 5+ sites com 1/3 do esforço de planejar caso-a-caso.
