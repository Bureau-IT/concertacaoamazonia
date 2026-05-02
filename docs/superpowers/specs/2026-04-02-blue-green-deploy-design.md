# Spec: Blue-Green Deploy — Concertação Amazônica

**Data:** 2026-04-02  
**Autor:** Daniel Cambría / Bureau de Tecnologia  
**Status:** Aprovado para implementação (v3 — pós-revisão de 6 agentes)

---

## Contexto

Dev está à frente de prod em tema, plugins e banco. O objetivo é substituir prod por uma nova EC2 (green) provisionada a partir do estado atual do dev, com zero downtime e rollback disponível durante todo o processo.

---

## Convenção de nomenclatura

| Nome | Papel | Tag EC2 |
|------|-------|---------|
| **blue** | Instância atual em produção | `20251022 concertacaoamazonia.com.br [PROD]` |
| **green** | Nova instância (staging → vira prod após virada) | `YYYYMMDD concertacaoamazonia.com.br [PROD-GREEN]` |

Após a virada confirmada: renomear tag green para `[PROD]`. Blue fica em stop até terminate em 60 dias.

---

## Abordagem de acesso ao green durante validação

> ⚠️ **Regra ALB source-ip não funciona com CloudFront** — o ALB vê o IP do edge CF, não o IP do cliente. Solução: acesso direto ao IP público da green com `/etc/hosts` temporário no host do auditor.

```
Auditor (Italy #157) → IP público green (porta 443 direta, SG libera 45.11.82.0/24)
Público              → CloudFront → ALB → concertacao-prod-tg → blue (inalterado)
```

---

## Arquitetura

| Componente | Blue (atual, prod) | Green (novo, staging→prod) |
|------------|-------------------|---------------------------|
| EC2 tag Name | `20251022 ... [PROD]` | `YYYYMMDD ... [PROD-GREEN]` |
| EC2 instance | `i-0c8178fe7ee985cc9` | nova (t3.large) |
| Banco | `wp_concertacao_20250316` | `wp_concertacao_YYYYMMDD` |
| Aurora cluster | compartilhado | mesmo cluster |
| Redis | local (socket) | local (socket) |
| EIP | `eip-concertacao-prod` | reassociado na virada |
| ALB Target Group | `concertacao-prod-tg` | adicionado apenas na virada |
| Acesso durante validação | público (CloudFront→ALB) | direto via IP (Italy #157 + /etc/hosts) |

---

## Fases do Deploy

### Fase 0 — Pré-condições *(executar dias antes, não no dia do deploy)*

> Estas ações devem ser feitas com antecedência para não atrasar o deploy no dia.

**0a. Alias SSH para green** — já configurado em `~/.ssh/config` (linha 133):
```
Host concertacaoamazonia.com.br-green-sa
    Hostname 54.207.84.130
    User ubuntu
    IdentityFile ~/.ssh/amazonia-sa.pem
```
> `54.207.84.130` (`eip-concertacao-hml`) é o EIP permanente da instância green. O mesmo IP que antes era chamado de HML. Nenhuma atualização necessária após provisionamento — o EIP é reatribuído à nova instância automaticamente pelo `post-deploy.sh`.

**0b. Validar licenças em HML:**
- Testar reativação de Elementor Pro, WP Rocket, WPML, JetEngine em HML
- Confirmar que reativar em um ambiente não desativa em outro (binding por domínio vs. site-key)
- Se houver conflito, coordenar com fornecedor antes do deploy

**0c. Snapshot preventivo do blue:**
```bash
# Snapshot do volume EBS blue antes de qualquer mudança
aws ec2 create-snapshot \
  --volume-id <vol-id-blue> \
  --description "pre-deploy-green-$(date +%Y%m%d)"
```

### Fase 1 — Preparação *(no dia do deploy, ~20 min)*

1. **Avisar editores** — congelamento de 2h antes da virada: conteúdo publicado em prod nesse período se perde
2. **Criar banco no Aurora** — `wp_concertacao_YYYYMMDD` antes de rodar `post-deploy.sh` (script 04 tenta `CREATE DATABASE IF NOT EXISTS` mas requer privilégio ou pré-existência)
3. **Atualizar `DB_NAME_PROD` no Secrets Manager** (`USE_SECRETSMANAGER_PROD=true`) — blue lê o secret apenas no startup/provisionamento, não em runtime, portanto atualizar é seguro
4. **Atualizar `INSTANCE_NAME_PROD` no `.env`** → `"YYYYMMDD concertacaoamazonia.com.br [PROD-GREEN]"`
5. **Verificar S3 paths** — `SQL_IMPORT_S3_DIR_PROD` e `SQL_IMPORT_FILE_PROD` no `.env` devem apontar para o arquivo exportado do dev; ajustar se necessário
6. **Confirmar `CF_TUNNEL_HOSTNAME`** — deve estar definido no `.env` do dev como `concertacao.bureau-it.com` para que o script 09 execute `search_replace_tunnel_fqdn()` automaticamente

### Fase 2 — Provisionamento green *(~30 min)*

```bash
cd /Users/dcambria/scripts/server-tools/v2
./ec2-deploy/post-deploy.sh <green-instance-id> PROD
```

Scripts automáticos (01–18, a1–a2, b1–b2, c1, d1–d3, e1): base system, nginx, php-fpm 8.3, redis local, HyperDB → Aurora, wp-config.php gerado com `DOMAIN_CURRENT_SITE = 'concertacaoamazonia.com.br'`, WP Rocket e Redis ativados, health check na porta 8080.

**Após provisionamento:** adicionar IP da green ao `~/.ssh/config` (Fase 0a).

### Fase 3 — Import de dados *(~30–50 min)*

**3a. Export do dev para S3:**
```bash
cd /Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao
std export-db        # Exporta banco completo multisite (wp_* + wp_2_*)
std export-wpcontent
std share export db       # → S3
std share export wpcontent
```

**3b. Import na green (script 09 inclui search-replace automático):**
```bash
ssh concertacaoamazonia.com.br-green-sa \
  "sudo -u www-data /opt/deploy/09-import-database.sh"
ssh concertacaoamazonia.com.br-green-sa \
  "sudo -u www-data /opt/deploy/10-import-wpcontent.sh"
```

> O script 09 executa automaticamente:
> - `search_replace_fqdn()` — substitui URL de origem (`cambrasmax.local:8484`)
> - `search_replace_tunnel_fqdn()` — substitui URL do tunnel (`concertacao.bureau-it.com`) **se `CF_TUNNEL_HOSTNAME` estiver no `.env`**

**3c. Verificar cobertura JetEngine CCT pós-import:**
```bash
ssh concertacaoamazonia.com.br-green-sa \
  "sudo -u www-data wp --path=/var/www/concertacaoamazonia.com.br db query \
  \"SELECT COUNT(*) FROM wp_jet_cct_participantes_cct \
    WHERE CAST(cct_data AS CHAR) LIKE '%cambrasmax%' \
    OR CAST(cct_data AS CHAR) LIKE '%concertacao.bureau-it%'\""
# Esperado: 0 registros
```

**3d. Flush de transients e cache pós-import:**
```bash
ssh concertacaoamazonia.com.br-green-sa \
  "sudo -u www-data wp --path=/var/www/concertacaoamazonia.com.br \
    --network cache flush && \
   sudo -u www-data wp --path=/var/www/concertacaoamazonia.com.br \
    --network transient delete --all"
```

### Fase 4 — Acesso direto para validação *(~2 min)*

```bash
# Na máquina do auditor (Italy #157):
echo "<green-public-ip>  concertacaoamazonia.com.br" | sudo tee -a /etc/hosts

# Verificar que green responde:
curl -sk https://concertacaoamazonia.com.br/ | head -5
```

> SG da green deve ter inbound 443 liberado para `45.11.82.0/24` (Italy #157).

### Fase 5 — Reativação de licenças *(MANUAL, ~10 min)*

> ⚠️ Obrigatório. Scripts de deploy NÃO automatizam licenças. Validar em HML antes (Fase 0b) que reativar não derruba blue.

Acessar `https://concertacaoamazonia.com.br/wp-admin/` (via `/etc/hosts` apontando para green):

| Plugin | Onde |
|--------|------|
| Elementor Pro | Elementor → Licença |
| WP Rocket | Configurações → WP Rocket → Conta |
| WPML | WPML → Registro |
| JetEngine | JetPlugins → Licenças |

### Fase 6 — Validação direta via IP *(Italy #157, ~30 min mínimo)*

**Frontend:**
- [ ] Homepage carrega (PT-BR e EN)
- [ ] Subsite `/cultura/` carrega
- [ ] Imagens e uploads aparecem
- [ ] Espiral SVG renderiza
- [ ] Formulários de contato funcionam

**Eventos TEC:**
- [ ] `/eventos-calendario/` — lista de eventos
- [ ] Agrupamento de editais por mês de término
- [ ] Separadores de mês corretos

**Admin:**
- [ ] WP Admin acessível
- [ ] Elementor Editor abre em uma página
- [ ] JetEngine CCT participantes — dados presentes (1.287 registros)

**Performance:**
- [ ] `X-Rocket-Reason: OK` no header HTTP
- [ ] `wp eval "var_dump(wp_using_ext_object_cache());"` → `true`

**WPML:**
- [ ] Switcher de idioma funciona
- [ ] Conteúdo traduzido aparece em EN

### Fase 7 — Virada *(zero downtime, ~10 min)*

```bash
# 1. Backup final do banco blue (instância ainda rodando)
ssh concertacaoamazonia.com.br-prod-sa \
  "mysqldump wp_concertacao_20250316 | gzip" \
  | aws s3 cp - s3://concertacaoamazonia-com-br-wp-static-prd-sa/backups/db/wp_concertacao_20250316_final_$(date +%Y%m%d).sql.gz

# 2. Registrar green no target group de prod
aws elbv2 register-targets \
  --target-group-arn <arn-concertacao-prod-tg> \
  --targets Id=<green-instance-id>

# 3. Aguardar green healthy (State = "healthy")
aws elbv2 describe-target-health \
  --target-group-arn <arn-concertacao-prod-tg>

# 4. Reassociar EIP para green (ANTES de deregistrar blue — mantém SSH acessível)
aws ec2 associate-address \
  --instance-id <green-instance-id> \
  --allocation-id <eip-concertacao-prod-allocation-id>

# 5. Atualizar alias SSH prod para apontar para green
# Editar ~/.ssh/config: concertacaoamazonia.com.br-prod-sa → IP green

# 6. Deregistrar blue (connection draining — aguardar State = "unused", ~5 min)
aws elbv2 deregister-targets \
  --target-group-arn <arn-concertacao-prod-tg> \
  --targets Id=<blue-instance-id>

# 7. Stop blue EC2
aws ec2 stop-instances --instance-ids <blue-instance-id>

# 8. Remover /etc/hosts temporário do auditor
sudo sed -i '' '/concertacaoamazonia.com.br/d' /etc/hosts
```

> **Por que backup na Fase 7 (não na 8):** mysqldump precisa que a instância esteja rodando. Stop acontece no passo 7 — backup deve ser feito antes.

> **Por que EIP antes de deregistrar:** garante que SSH `concertacaoamazonia.com.br-prod-sa` nunca fica inacessível. Sem isso, haveria janela de ~5 min sem acesso SSH.

### Fase 8 — Warm-up e limpeza pós-virada *(~10 min)*

```bash
# Warm-up de cache no green (agora prod)
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo -u www-data /opt/deploy/14-renew-all-caches.sh"

# Confirmar WP Rocket config regenerada
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo -u www-data wp --path=/var/www/concertacaoamazonia.com.br rocket status"

# Renomear tag da instância green → [PROD]
aws ec2 create-tags \
  --resources <green-instance-id> \
  --tags Key=Name,Value="$(date +%Y%m%d) concertacaoamazonia.com.br [PROD]"
```

**Agendamento futuro (60 dias após virada):**
- Terminate EC2 blue
- Apagar banco `wp_concertacao_20250316` do Aurora (backup S3 permanece)

---

## Rollback

### Durante validação (antes da Fase 7)

Remover `/etc/hosts` temporário. Blue nunca saiu do ALB — nenhuma ação na infra.

### Após virada — janela segura (primeiras horas)

```bash
# 1. Start blue
aws ec2 start-instances --instance-ids <blue-instance-id>

# 2. Re-registrar blue no prod-tg, aguardar healthy
aws elbv2 register-targets \
  --target-group-arn <arn-concertacao-prod-tg> \
  --targets Id=<blue-instance-id>

# 3. Deregistrar green, aguardar draining
# 4. Reassociar EIP para blue, stop green
```

> ⚠️ **Perda de dados no rollback:** qualquer conteúdo publicado em green após a virada **não existe** no banco do blue (`wp_concertacao_20250316`). Rollback após publicações implica perda desses dados. Só executar rollback após confirmação explícita de que a perda é aceitável.

---

## Variáveis a verificar antes do deploy

| Variável | Onde | Ação |
|----------|------|------|
| `DB_NAME_PROD` | AWS Secrets Manager | Atualizar para `wp_concertacao_YYYYMMDD` |
| `CF_TUNNEL_HOSTNAME` | `.env` do dev | Confirmar `concertacao.bureau-it.com` |
| `SQL_IMPORT_S3_DIR_PROD` | `.env` prod | Confirmar que bate com path do `std share export` |
| `SQL_IMPORT_FILE_PROD` | `.env` prod | Atualizar para nome do arquivo exportado |
| `INSTANCE_NAME_PROD` | `.env` prod | `"YYYYMMDD concertacaoamazonia.com.br [PROD-GREEN]"` |

---

## Considerações de tempo total

| Fase | Duração estimada |
|------|-----------------|
| Pré-condições (Fase 0) | dias antes |
| Preparação (Fase 1) | 20 min |
| Provisionamento green | 30 min |
| Import dados | 30–50 min |
| Acesso direto (hosts) | 2 min |
| Licenças (manual) | 10 min |
| Validação via IP | 30 min mínimo |
| Virada | 10 min |
| Warm-up | 10 min |
| **Total (dia do deploy)** | **~2h30 mínimo** |

---

## Notas de risco

1. **Licenças** — reativar em green pode conflitar com blue se binding for por domínio. Validar em HML (Fase 0b) antes do deploy.
2. **Janela de congelamento** — editores não publicam nas 2h antes da virada. Conteúdo criado em prod nesse período se perde no rollback.
3. **Rollback = perda de dados** — após writes em green (pós-virada), rollback perde o conteúdo publicado. Só rollback com confirmação explícita.
4. **Tunnel URL no banco** — depende de `CF_TUNNEL_HOSTNAME` no `.env` dev. Verificar Fase 1 item 6.
5. **JetEngine CCT** — verificar cobertura do search-replace na Fase 3c antes de validar.
6. **SSH alias green** — criar em Fase 0a após provisionar. Sem isso, Fases 3 e 6 falham.
7. **Aurora compartilhado** — import do banco green não afeta HML nem banco blue. Operação segura.
8. **WP Rocket warm-up** — script 14 inclui `rocket_generate_config_file()` com falha silenciosa. Confirmar com `wp rocket status` na Fase 8.
