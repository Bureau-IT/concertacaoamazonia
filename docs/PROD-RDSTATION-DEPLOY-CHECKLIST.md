# Pre-deploy PROD — Integração RD Station Marketing

> Complemento do plano v4 (`plans/2026-05-22-rdstation-integration-parte2-v4.md`).
> Esclarece **como** `RDSTATION_API_KEY_PROD` chega na EC2 PROD — descoberta
> 2026-05-26 ao corrigir 3 BLOCKERS de auditoria.

## TL;DR

`RDSTATION_API_KEY_PROD` é propagada **automaticamente** via o mesmo mecanismo
usado por `SMTP_PASSWORD_PROD`, `SMTP_HOST_PROD` etc.: o consolidated `.env`
local é lido por `ec2-deploy/post-deploy.sh`, convertido em `wp-env.sh`, scp'd
para a EC2, e sourceado antes de cada script post-deploy rodar.

**Não há mudança em AWS Secrets Manager** (esse é exclusivo para credenciais
de banco — DB_NAME/DB_USER/DB_PASSWORD — conforme
`ec2-deploy/post-deploy/docs/README-SECRETS.md`).

## Mecanismo (confirmado empiricamente)

1. **`.env` consolidado local**: `/Users/dcambria/scripts/server-tools/v2/.env.concertacaoamazonia.com.br.sa`
   já contém (Task 1 do v4):
   ```
   RDSTATION_API_KEY_DEV="d471758..."
   RDSTATION_API_KEY_HML="d471758..."
   RDSTATION_API_KEY_PROD="d471758..."
   ```

2. **`ec2-deploy/post-deploy.sh`** (linhas 1155-1216) lê esse `.env`,
   gera `wp-env.sh` com `export KEY="value"` para cada chave, scp para
   `$REMOTE_WORK_DIR/post-deploy/wp-env.sh` na EC2.

3. **Cada script post-deploy** (incluindo `a1-wordpress-autoconfigure.sh`)
   sourceia `wp-env.sh` no início → `${RDSTATION_API_KEY_PROD}` fica
   disponível.

4. **`a1-wordpress-autoconfigure.sh:configure_optional_constants()`** lê
   `RDSTATION_API_KEY_${env_upper}`, com cascata HML→PROD (igual SMTP), e
   injeta:
   - bloco `getenv()` guard ANTES de `wp-settings.php`, +
   - `wp config set RDSTATION_API_KEY` (hardcoded como backup quando FPM
     não herda env vars do shell).
   - cria `/var/log/bit-rdstation/` (owner www-data, mode 750).

## Pre-deploy PROD checklist (sub-step do plano v4)

Antes de rodar phase3 (ou qualquer cutover green→prod):

- [ ] **Validar `.env` raiz tem `RDSTATION_API_KEY_PROD`:**
  ```bash
  grep -E "^RDSTATION_API_KEY_PROD=" \
    /Users/dcambria/scripts/server-tools/v2/.env.concertacaoamazonia.com.br.sa
  ```
  Expected: linha presente com token válido (32 chars hex).

- [ ] **Validar `bootstrap.sh` server-tools versão correta:**
  ```bash
  grep -c "RD Station (BIT)" \
    /Users/dcambria/scripts/server-tools/v2/docker-dev/common/scripts/bootstrap.sh
  ```
  Expected: ≥1.

- [ ] **Validar `a1-wordpress-autoconfigure.sh` server-tools versão correta:**
  ```bash
  grep -c "RDSTATION_API_KEY_\${env_upper}\|RD Station (BIT)" \
    /Users/dcambria/scripts/server-tools/v2/ec2-deploy/post-deploy/a1-wordpress-autoconfigure.sh
  ```
  Expected: ≥2 (pattern lookup + bloco PHP).

- [ ] **Pós-deploy PROD — confirmar constante setada:**
  ```bash
  ssh concertacaoamazonia.com.br-prod-sa \
    "sudo -u www-data wp --path=/var/www/concertacaoamazonia.com.br \
     eval 'echo defined(\"RDSTATION_API_KEY\") ? \"DEFINED\" : \"MISSING\";'"
  ```
  Expected: `DEFINED`.

- [ ] **Pós-deploy PROD — confirmar `/var/log/bit-rdstation/` existe:**
  ```bash
  ssh concertacaoamazonia.com.br-prod-sa \
    "ls -la /var/log/bit-rdstation/"
  ```
  Expected: dir com owner `www-data:www-data`, mode 750.

## Por que NÃO AWS Secrets Manager

`README-SECRETS.md` documenta claramente que o Secrets Manager do projeto
é usado **apenas** para credenciais DB:
- `DB_NAME`, `DB_USER`, `DB_PASSWORD` (obrigatórios)
- `GITHUB_DEPLOY_KEY`, `CHECK_EC2_KEY` (opcionais)

Adicionar `RDSTATION_API_KEY_PROD` ao Secrets Manager exigiria mudar:
1. `04-wpcli-wp-install.sh` (parser do JSON do secret)
2. `create-secretsmanager.sh` (lista de chaves uploaded)
3. Política IAM da instância (já permite `secretsmanager:GetSecretValue`)

Como o padrão `.env` → `wp-env.sh` já funciona perfeitamente para SMTP
(que tem o mesmo nível de sensibilidade — credenciais SES), mantemos
RDSTATION pela mesma via. Consistência arquitetural.

## Comandos AWS CLI (NÃO executados — apenas referência)

Caso em algum futuro decida-se mover RDSTATION pro Secrets Manager:

```bash
# 1. Adicionar key ao secret existente (concertacao-prod)
CURRENT=$(aws secretsmanager get-secret-value \
  --secret-id concertacao-prod \
  --region sa-east-1 \
  --query SecretString --output text)

UPDATED=$(echo "$CURRENT" | jq \
  --arg key "d471758beccb568a0fd0bd4f8bf07a85" \
  '. + {"RDSTATION_API_KEY": $key}')

aws secretsmanager update-secret \
  --secret-id concertacao-prod \
  --region sa-east-1 \
  --secret-string "$UPDATED"

# 2. Verificar
aws secretsmanager get-secret-value \
  --secret-id concertacao-prod \
  --region sa-east-1 \
  --query SecretString --output text | jq '.RDSTATION_API_KEY'
```

**NÃO executar** sem antes adaptar `a1-wordpress-autoconfigure.sh` para ler
do secret (atualmente lê só de env var). Caminho recomendado: **manter via
`.env`** (já funciona).
