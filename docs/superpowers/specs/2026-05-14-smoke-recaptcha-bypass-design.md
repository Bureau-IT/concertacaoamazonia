# Smoke reCAPTCHA bypass — mu-plugin `bit-smoke-recaptcha-bypass.php`

**Data:** 2026-05-14
**Versão atual:** v1.1.1 (header de resposta condicional + audit log incondicional)
**Status:** accepted (implementado em DEV; pendente deploy PROD/HML)
**Supersede parcial:** `2026-05-01-smoke-form-submit-design.md` decisão #1 (submit apenas no green). Após o bypass, submit em PROD passa a ser autorizado mediante token. O risco residual #1 do spec antigo ("reCAPTCHA visível") perde relevância porque o bypass remove a validação reCAPTCHA inteiramente — o smoke deixa de exercitar o caminho reCAPTCHA real; verificação dessa camada migra para Google reCAPTCHA admin console (scores, taxa de rejeição).

## Changelog

- **v1.1.1 (2026-05-14)** — Header `X-BIT-Smoke-Bypass` emitido **somente** quando request carrega `X-BIT-Smoke-Token` (qualquer valor). Sem header de entrada = sem header de saída. Evita cache-poisoning no CloudFront/WP Rocket e elimina leak de metadado revelando o mecanismo. Audit log via `error_log` agora é incondicional (não depende de `WP_DEBUG=true`) quando `is_authorized=true` — trail mínimo de uso em PROD. Docblock de `emit_diagnostic_header()` corrigido (admin-ajax não dispara `send_headers`).
- **v1.1.0 (2026-05-14)** — Filter `actions_before` em vez de action `new_record`; rename do marker para `__bit_smoke_test`; header de telemetria `X-BIT-Smoke-Bypass: OK|FAILED|NOOP`.
- **v1.0.0 (2026-05-14)** — Implementação inicial: bypass via header + constante, varredura `$wp_filter`, marker via action.

**Contexto:** smoke do formulário do rodapé não conclui submit real em PROD porque o reCAPTCHA v3 invisible (Elementor Pro) atribui score baixo a navegadores headless — backend rejeita com "Formulário Inválido - Falha na Validação do reCAPTCHA". Sem submit real, o smoke não valida o pipeline POST→handler→destino.

Spec original do smoke (`2026-05-01-smoke-form-submit-design.md`) restringiu submit ao green pra evitar poluir CRM de prod. Como green está desligado e prod precisa ser validado, este spec descreve um bypass cirúrgico do reCAPTCHA por header autenticado.

## Decisões de design

| # | Decisão | Justificativa |
|---|---------|---------------|
| 1 | Bypass via header `X-BIT-Smoke-Token` + constante `BIT_SMOKE_BYPASS_TOKEN` no `wp-config.php` | Granularidade no endpoint Elementor Pro (não no IP/IPv4 global do reCAPTCHA admin). Token versionável por ambiente, rotação manual quando vazar suspeita. |
| 2 | `hash_equals()` para comparação | Evita timing attack (não permite descoberta byte-a-byte). |
| 3 | No-op se constante ausente ou vazia | Mu-plugin pode existir em qualquer ambiente sem causar bypass acidental. Token = source of truth da feature. |
| 4 | Remoção dos callbacks `Recaptcha_Handler::validation` + `Recaptcha_V3_Handler::validation` no hook `elementor_pro/forms/validation` priority 10, via varredura de `$wp_filter` | `remove_action()` requer a mesma instância de objeto registrada pelo Elementor Pro, indisponível externamente. Varremos `$wp_filter[hook]->callbacks[10]` procurando `[obj-da-classe-Recaptcha, 'validation']` e removemos por chave. Honeypot/Akismet/validações de campo permanecem ativos. |
| 5 | Hook em `elementor_pro/init` priority 100 | Garante que `Forms\Module` foi instanciado (linha 234-235 de module.php) e os callbacks Recaptcha já estão registrados. |
| 6 | **(rev. 2026-05-14)** Marker `__bit_smoke_test=1` injetado no record via **filter** `elementor_pro/forms/record/actions_before` (ajax-handler.php:149), retornando `$record`. NÃO via action `elementor_pro/forms/new_record` (linha 209) que dispara DEPOIS das actions (email/webhook/RD Station — linhas 151-186). | Para destinos receberem o marker, ele tem que ser injetado ANTES do loop de actions. O nome `__bit_smoke_test` usa prefixo `__` que o Elementor UI bloqueia em custom_id — sem colisão. `set_fields()` itera `form_settings['form_fields']` (definição do widget), não payload livre — atacante sem token NUNCA consegue forjar este field. |
| 7 | **(novo 2026-05-14)** Response header `X-BIT-Smoke-Bypass: OK\|FAILED\|NOOP` via `send_headers` + `set_state()` | Telemetria estrutural pra smoke detectar drift do Elementor Pro (priority/classe mudou após update). Sem isso, drift falha mudo: bypass não remove callbacks, reCAPTCHA bloqueia, smoke não distingue de outros erros. Gate obrigatório no snippet PROD. |
| 8 | Log condicional em `error_log()` quando `WP_DEBUG=true`; prefixo 8 chars do token | Auditoria local em dev; em prod sem WP_DEBUG não polui logs nem expõe token. CloudWatch via stderr do FPM se quiser ativar. 8 chars hex = 32 bits de entropia logados — insuficiente pra reconstruir 256-bit token. |
| 9 | Token mínimo 32 chars; recomendado 64 hex | Entropia suficiente; gerar via `openssl rand -hex 32`. |
| 10 | Ativo em DEV + HML + PROD | Mesmo binário em todos os ambientes; só muda o token por ambiente. Smoke local em dev passa a funcionar também. |

## Arquivo: `bit-smoke-recaptcha-bypass.php`

Localização canônica: `docker-dev/common/mu-plugins/bit-smoke-recaptcha-bypass.php` + cópia em `sites/concertacao/wordpress/wp-content/mu-plugins/`.

### Comportamento

```
Request chega
  ↓
mu_plugins_loaded
  ↓
Hook: elementor_pro/init (priority 100)
  ↓
Header X-BIT-Smoke-Token presente?
  ├─ não → return (no-op)
  └─ sim
       ↓
    Constante BIT_SMOKE_BYPASS_TOKEN definida e não-vazia?
       ├─ não → return (no-op)
       └─ sim
            ↓
         hash_equals(constante, header)?
            ├─ não → return (no-op; log se WP_DEBUG)
            └─ sim
                 ↓
              remove_action('elementor_pro/forms/validation', Recaptcha_Handler::validation, 10)
              remove_action('elementor_pro/forms/validation', Recaptcha_V3_Handler::validation, 10)
              add_filter('elementor_pro/forms/new_record', mark_as_smoke_test)
              log se WP_DEBUG
```

### Hooks do Elementor Pro afetados

| Hook | Class | Method | Priority |
|------|-------|--------|----------|
| `elementor_pro/forms/validation` | `\ElementorPro\Modules\Forms\Classes\Recaptcha_Handler` | `validation` | 10 |
| `elementor_pro/forms/validation` | `\ElementorPro\Modules\Forms\Classes\Recaptcha_V3_Handler` | `validation` | 10 |

Honeypot e Akismet **não são removidos** — bot legítimo continua bloqueado se tentar usar o token. Validações de campo (required, email) idem.

### Marker meta `is_smoke_test`

```php
add_action('elementor_pro/forms/new_record', function ($record, $ajax_handler) {
  $record->update_form_settings(['is_smoke_test' => '1']);
}, 10, 2);
```

Destinos suportados:
- **Email**: o template já pode usar `[is_smoke_test]` se quiser filtrar
- **Database integrations** (Elementor entries): aparece como meta do record
- **RD Station / outros webhooks**: passa como campo extra no payload

## Configuração por ambiente

Cada ambiente tem token próprio (não reusar entre DEV/HML/PROD). `wp-config.php` é gitignored — token não vai pro repo.

### DEV (já configurado)
```php
// wordpress/wp-config.php (gitignored)
define('BIT_SMOKE_BYPASS_TOKEN', '<openssl rand -hex 32>');
```

### HML (green)
Green está desligado no momento. Deploy do mu-plugin + constante fica **pendente** até próximo cutover blue→green. Adicionar à checklist do `phase7-cutover.sh` ou trackear como task de continuidade.
```php
// /var/www/concertacaoamazonia.com.br/wp-config.php no host green (auto-hml-*)
define('BIT_SMOKE_BYPASS_TOKEN', '<openssl rand -hex 32 — outro>');
```

### PROD
```php
// /var/www/concertacaoamazonia.com.br/wp-config.php
define('BIT_SMOKE_BYPASS_TOKEN', '<openssl rand -hex 32 — outro>');
```

**Não** comitar tokens. **Não** logar token completo (mu-plugin já loga só prefixo 8 chars com `WP_DEBUG=true`). **Não** colar token em screenshots/chat/issues — colar prefixo (`token=8315ac7c...`) suficiente pra diagnóstico.

## Cobertura multisite

O site é WordPress Multisite (blog 1 raiz, blog 2 `/cultura/`). `wp-config.php` é único — a constante serve os dois blogs automaticamente. O footer Elementor é compartilhado mas configurações WPML/destinos podem diferir. **Smoke deve rodar para ambos os blogs:**
- `https://concertacaoamazonia.com.br/` (blog 1, PT)
- `https://concertacaoamazonia.com.br/cultura/` (blog 2, PT)
- `https://concertacaoamazonia.com.br/cultura/en/` (blog 2, EN — se aplicável ao form)

## Atualização no `/smoke`

### Mudanças em `.claude/commands/smoke.md`

1. Snippet "submit real" passa a aceitar header de bypass via `BIT_SMOKE_TOKEN` env var
2. **PROD**: agora submete de verdade (não só presence), com bypass token
3. **GREEN**: mantém comportamento atual; bypass também ativo se token configurado
4. Guard hostname=hml continua existindo mas vira **avisorio** quando bypass token presente (não bloqueia)
5. Marker `is_smoke_test=1` documentado nos gates

### Novos cenários cobertos

- Pipeline POST → handler em prod (regressão real)
- Honeypot ainda ativo (não interferiu)
- Validações de campo ativas (regressão em required, email format)

### Cenários ainda não cobertos

- Entrega final no destino (email recebido, lead em RD Station): fora do escopo; cabe a teste de integração separado
- reCAPTCHA v3 em si funcionando para usuários reais: token bypassa, então smoke não detecta degradação de score; verificar via Google reCAPTCHA admin diretamente

## Não-objetivos

- Não substituir o reCAPTCHA por outra solução
- Não fazer bypass de nonce/CSRF do WP
- Não automatizar rotação de token (manual quando necessário)
- Não criar UI admin para gerenciar token

## Risco residual

| Risco | Mitigação |
|-------|-----------|
| Token vazar em log/screenshot/commit | `wp-config.php` gitignored; log usa prefixo 8 chars; rotação ≤ 90 dias; reportar prefix-only em chat/issues |
| CloudFront ou WAF capturar header em logs/cache key | Auditar CloudFront real-time logs e WAF sampled-requests antes de PROD; cache policy padrão NÃO inclui headers custom (validar). Considerar `proxy_set_header X-BIT-Smoke-Token "";` no nginx do origin após o PHP ler |
| Atacante força bruta no token | 64 chars hex = 2^256 espaço; rate-limit nginx existente cobre. Sem evidência de tentativa real esperada |
| mu-plugin esquecido em ambiente errado | No-op se constante ausente — comportamento seguro por default |
| Bypass usado pra spam de marketing | Marker `__bit_smoke_test=1` permite filtro no destino; emails todos vão para `smoke+TS@bureau-it.com` |
| Atualização do Elementor Pro muda nome/path das classes Recaptcha | Header `X-BIT-Smoke-Bypass: FAILED` sinaliza drift; smoke tem gate; deploy não procede sem `OK` |
| Atacante forja `__bit_smoke_test=1` no payload do form | Blindado pelo `Form_Record::set_fields()` que itera `form_settings['form_fields']` (definição do widget Elementor) e ignora payload livre; field só aparece se injetado por hook autorizado |
| Update do Elementor Pro muda priority do hook validation (atual=10) | Mu-plugin só busca em priority 10; se mudar, no-op + state=FAILED. Smoke detecta via header e bloqueia deploy |
| Honeypot/Akismet como única defesa secundária | Aceito: smoke é caminho privilegiado autenticado; defesa primária é o token. Marker no destino permite filtragem post-hoc |
| Drift entre versões Elementor Pro DEV/HML/PROD | Sem pinning hoje. Header de telemetria + smoke gate captura drift na hora do test, não no deploy |

## Rollout

### 1. DEV (concluído)
- [x] Mu-plugin criado em `wordpress/wp-content/mu-plugins/bit-smoke-recaptcha-bypass.php` v1.1.0
- [x] Cópia canônica em `docker-dev/common/mu-plugins/bit-smoke-recaptcha-bypass.php`
- [x] Token DEV adicionado em `wordpress/wp-config.php`
- [x] Validado via `wp eval`: bypass remove callbacks, marker injetado via filter, header de telemetria emitido, negative tests passam
- [x] `smoke.md` atualizado com snippet PROD + gates de `bypass_header`

### 2. Git commit (PENDENTE)
Ambos os arquivos estão `untracked`. Commit em dois repos:
```bash
# Repo server-tools (canônica)
cd /Users/dcambria/scripts/server-tools/v2
git add docker-dev/common/mu-plugins/bit-smoke-recaptcha-bypass.php

# Repo concertacao
cd /Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao
git add wordpress/wp-content/mu-plugins/bit-smoke-recaptcha-bypass.php \
        docs/superpowers/specs/2026-05-14-smoke-recaptcha-bypass-design.md \
        .claude/commands/smoke.md
```

### 3. PROD
```bash
# 3a. Gerar token PROD (NÃO reusar DEV)
TOKEN_PROD=$(openssl rand -hex 32)

# 3b. Backup do wp-config.php antes de editar
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo cp /var/www/concertacaoamazonia.com.br/wp-config.php \
     /var/www/concertacaoamazonia.com.br/wp-config.php.bak-$(date +%s)"

# 3c. Adicionar constante no wp-config.php (manual, via sudo nano ou sed)
# define('BIT_SMOKE_BYPASS_TOKEN', '<TOKEN_PROD>');

# 3d. Rsync do mu-plugin
rsync -avz docker-dev/common/mu-plugins/bit-smoke-recaptcha-bypass.php \
  concertacaoamazonia.com.br-prod-sa:/var/www/concertacaoamazonia.com.br/wp-content/mu-plugins/

# 3e. Limpar OPcache file_cache (regra CLAUDE.md concertacao):
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo find /var/cache/php-opcache -name '*bit-smoke-recaptcha-bypass*' -delete 2>/dev/null; \
   sudo systemctl reload php8.3-fpm"

# 3f. Validar constante carregou
ssh concertacaoamazonia.com.br-prod-sa \
  "sudo -u www-data wp --path=/var/www/concertacaoamazonia.com.br eval \
     'echo defined(\"BIT_SMOKE_BYPASS_TOKEN\") ? \"OK len=\" . strlen(BIT_SMOKE_BYPASS_TOKEN) : \"MISSING\";'"

# 3g. Validação positiva via curl
curl -sk -H "X-BIT-Smoke-Token: $TOKEN_PROD" \
  -o /dev/null -D - https://concertacaoamazonia.com.br/ | grep -i 'x-bit-smoke-bypass'
# Esperado: X-BIT-Smoke-Bypass: OK

# 3h. Validação NEGATIVA — token errado deve continuar bloqueando
curl -sk -H "X-BIT-Smoke-Token: invalidoooooooooooooooooooooooooooooooo" \
  -o /dev/null -D - https://concertacaoamazonia.com.br/ | grep -i 'x-bit-smoke-bypass'
# Esperado: X-BIT-Smoke-Bypass: NOOP

# 3i. Rodar /smoke (snippet submit real — PROD) substituindo BIT_SMOKE_TOKEN_AQUI
```

### 4. HML/green (pendente — bloqueado: green offline)
Adicionar à checklist do próximo cutover blue→green. Mesmo procedimento da seção 3, com host `concertacaoamazonia.com.br-green-sa` ou auto-hml-*.

## Rollback

Se o bypass for explorado (token vazou) ou se a versão do Elementor Pro causar drift inesperado em PROD:

```bash
# Rollback 1 — mais rápido (constante = source of truth):
ssh concertacaoamazonia.com.br-prod-sa "sudo sh -c '
  cd /var/www/concertacaoamazonia.com.br && \
  cp wp-config.php wp-config.php.bak-rollback-$(date +%s) && \
  sed -i \"/BIT_SMOKE_BYPASS_TOKEN/d\" wp-config.php && \
  find /var/cache/php-opcache -name \"*bit-smoke-recaptcha-bypass*\" -delete 2>/dev/null; \
  systemctl reload php8.3-fpm
'"
# Backup pré-edit: cp wp-config.php.bak-rollback-<ts> (auditável, reversível com diff)
# sed pattern: /BIT_SMOKE_BYPASS_TOKEN/d — deleta linha inteira, agnóstico ao formato do token
# Mu-plugin permanece instalado mas vira no-op. Imediato. Rotacionar token depois e re-deployar.

# Rollback 2 — remover mu-plugin (cinto + suspensório):
ssh concertacaoamazonia.com.br-prod-sa "sudo sh -c '
  rm /var/www/concertacaoamazonia.com.br/wp-content/mu-plugins/bit-smoke-recaptcha-bypass.php && \
  find /var/cache/php-opcache -name \"*bit-smoke-recaptcha-bypass*\" -delete 2>/dev/null; \
  systemctl reload php8.3-fpm
'"
```

**Notas importantes:**
- **Ordem:** remover constante PRIMEIRO se o problema é vazamento de token (corta o vetor antes de mexer no arquivo). Remover mu-plugin primeiro se o problema é bug no plugin causando 500.
- **Em ROTAÇÃO de token** (não rollback): preferir `systemctl restart php8.3-fpm` em vez de `reload`. O `reload` faz graceful (workers idle terminam, workers ativos completam request com constante antiga) — janela 1-30s onde token antigo ainda funciona. `restart` mata workers imediatamente; janela zero, mas pequena descontinuidade.
- **Token format:** spec recomenda `openssl rand -hex 32` (hex lowercase 64 chars). O `sed /BIT_SMOKE_BYPASS_TOKEN/d` é agnóstico ao formato.

## Rotação de token

Cadência sugerida: **a cada 90 dias** ou **imediatamente** em qualquer suspeita de vazamento.

```bash
# 1. Gerar token novo
NEW_TOKEN=$(openssl rand -hex 32)

# 2. Sobrescrever constante (atômico — PHP só lê uma vez por request)
ssh concertacaoamazonia.com.br-prod-sa "sudo sh -c '
  cd /var/www/concertacaoamazonia.com.br && \
  cp wp-config.php wp-config.php.bak-rotate-$(date +%s) && \
  sed -i \"s|define( *.BIT_SMOKE_BYPASS_TOKEN.*|define(\\\"BIT_SMOKE_BYPASS_TOKEN\\\", \\\"'"$NEW_TOKEN"'\\\");|\" wp-config.php && \
  find /var/cache/php-opcache -name \"*wp-config*\" -delete 2>/dev/null; \
  systemctl restart php8.3-fpm
'"

# 3. Atualizar 1Password / cofre pessoal com novo token
# 4. Re-rodar scripts/validate-smoke-bypass.sh pra confirmar que novo token funciona
./scripts/validate-smoke-bypass.sh https://concertacaoamazonia.com.br "$NEW_TOKEN"
```

**Importante:** usar `restart` (não `reload`) em rotação — `reload` faz graceful e mantém workers velhos com token antigo na janela 1-30s. `restart` mata workers imediatamente, janela zero. Pequena descontinuidade aceitável pra eliminar duplo-aceite. PHP lê uma constante por request, FPM restart é atômico → token antigo invalidado.

## Validação negativa pós-deploy (gate obrigatório)

Antes de declarar deploy concluído, executar o script empacotado:

```bash
./scripts/validate-smoke-bypass.sh https://concertacaoamazonia.com.br "$TOKEN_PROD"
```

O script roda 6 testes idempotentes e retorna exit code = número de falhas:

| # | Teste | Esperado (v1.1.1+) |
|---|-------|--------------------|
| 1 | Token válido + header | `X-BIT-Smoke-Bypass: OK` + HTTP 200 |
| 2 | Token errado (64 chars) | `X-BIT-Smoke-Bypass: NOOP` |
| 3 | **Sem header de request** | Header `X-BIT-Smoke-Bypass` AUSENTE (v1.1.1+) |
| 4 | Token curto (<32 chars) | `X-BIT-Smoke-Bypass: NOOP` |
| 5 | Header vazio | Header AUSENTE |
| 6 | Sanidade HTTP | site responde 200/301/302 |

**Testes adicionais manuais (Playwright):**
| Teste | Comando | Esperado |
|-------|---------|----------|
| Submit form footer com token | snippet smoke "submit real — PROD" | `bypass_header=OK`, `submit_ok=true`, success message |
| Submit form footer com token errado | snippet com `BIT_SMOKE_TOKEN_AQUI` = `'x'.repeat(64)` | `bypass_header=NOOP` (lido da response), `submit_ok=false`, erro reCAPTCHA |
| Marker chegou ao destino | Conferir em Elementor entries / RD Station / email recebido se `__bit_smoke_test=1` aparece no payload do submit positivo | Field presente |

Se qualquer teste falhar: **rollback imediato** e investigar.
