# RD Station Integration (Parte 2) — Implementation Plan v2

> **Para agentes:** SUB-SKILL OBRIGATÓRIA: Use superpowers:subagent-driven-development (recomendado) ou superpowers:executing-plans para implementar este plano task-a-task. Steps usam checkbox (`- [ ]`) para tracking.

> **v2 — gerado por consolidador do Ciclo 2 de revisão (2026-05-21).**
> Corrige 9 BLOCKERs e ~15 IMPORTANT issues encontrados por 5 agentes de revisão (A–E).
> Ver seção "Diff v1→v2" no final.


---

**Goal:** Form Action customizada `bit_rdstation` que envia submits do Elementor Pro Forms para a API REST do RD Station Marketing (`POST /platform/conversions` via API Key), com graceful degradation (form nunca quebra se RD falhar) e logging seguro fora do webroot.

**Architecture:** Mu-plugin `bit-elementor-form-rdstation.php` registra uma Form Action customizada estendendo `\ElementorPro\Modules\Forms\Classes\Action_Base`. No `run()`, monta payload JSON com email + cf_uf + tags e POSTa via `wp_remote_post` (timeout=8). API Key vem de `RDSTATION_API_KEY` (alias de `RDSTATION_PRIVATE_TOKEN_DEV/HML/PROD` do `.env` raiz) definida em `wp-config.php` via bootstrap. Falhas são apenas logadas em `WP_CONTENT_DIR/../logs/bit-rdstation/YYYY-MM-DD.log` (fora do webroot — LGPD), NUNCA chamam `add_error_message` (graceful).

**Tech Stack:** PHP 8.3 (mu-plugin), Elementor Pro 3.35.1 Form Action API, RD Station Marketing API REST (`/platform/conversions` via API Key), WP-CLI (validação), curl (smoke test endpoint).

**Spec:** `docs/superpowers/specs/2026-05-19-formulario-rodape-rdstation-design.md` (Parte 2).

**Escopo desta Parte 2:**
- ✅ Env vars no `.env` raiz LINKED_ENV com sufixo `_DEV/_HML/_PROD` (PRÉ-REQUISITO HML — Task 1)
- ✅ `docker-compose.yml` com env vars no serviço wordpress (Task 2)
- ✅ Mu-plugin `bit-elementor-form-rdstation` v1.0.0
- ✅ Constants idempotentes em `wp-config.php` via bootstrap.sh (Task 3)
- ✅ Log fora do webroot (`../logs/bit-rdstation/`) — LGPD (Task 8)
- ✅ Script `scripts/rdstation-bootstrap-fields.php` (one-shot, cria custom fields)
- ✅ Wire-up na PREVIEW form do template footer 72234 (mapeamento email/UF)
- ✅ Logging com `BIT_RDSTATION_DEBUG` opt-in
- ✅ Gate `/smoke` novo pra validar action + token em prod (Task 13)
- ✅ `CLAUDE.md` atualizado com entry do novo mu-plugin (Task 13)
- ❌ LGPD checkbox de consentimento (fora de escopo desta entrega — TBD próximo passo)
- ❌ Settings page admin (padrão BIT: constants em wp-config, sem UI)

**Lições aplicadas:**
- [[feedback_smtp_constants_missing_prod.md]] — tokens DEVEM estar no `.env` raiz com sufixo `_DEV/_HML/_PROD`; bootstrap.sh é PRÉ-REQUISITO HML, NÃO OPCIONAL
- [[feedback_str_replace_substring_orphan.md]] — `@file_put_contents` removido; usar verificação explícita
- [[feedback_elementor_data_wp_slash_required.md]] — `wp_slash(wp_json_encode())` obrigatório
- Agente A: token que funciona na API RD é `RDSTATION_PUBLIC_TOKEN` (nomenclatura histórica confusa — renomeado para clareza)
- Agente B: `on_export()` deve retornar `$element` (não `[]`) para não destruir o widget no export
- Agente C: log fora de webroot; wp-config append deve ser idempotente
- Agente D: tokens no raiz sufixados — PRÉ-REQUISITO HML
- Agente E: sandbox RD documentado; cobertura de testes ampliada


---

## Nota sobre Tokens RD Station (Confusão Histórica)

> **LEIA ANTES DE IMPLEMENTAR**

Os tokens no `.env` atual têm nomes semanticamente invertidos em relação ao uso real:

| Nome no `.env` | Nome RD Station | Função real | Quem usa |
|---|---|---|---|
| `RDSTATION_PUBLIC_TOKEN` | "Identificador público" | **API Key server-side** que a API `/platform/conversions?api_key=X` aceita | mu-plugin (server-side) |
| `RDSTATION_PRIVATE_TOKEN` | "Token privado" | UUID de tracking **client-side** (RD Tracker JS) | NÃO usado nesta entrega |

Empiricamente validado: `RDSTATION_PUBLIC_TOKEN` retorna HTTP 200; `RDSTATION_PRIVATE_TOKEN` retorna HTTP 401.

**Decisão v2:** renomear internamente para `RDSTATION_API_KEY` no `.env` raiz e na constante PHP. O `.env` do site mantém os nomes antigos durante a transição; a Task 1 cria os novos nomes sufixados. Comentários no código explicam a confusão histórica.

---

## File Structure

| Path | Responsabilidade |
|---|---|
| `wordpress/wp-content/mu-plugins/bit-elementor-form-rdstation.php` | Form Action `bit_rdstation`: register, settings section, run() com POST RD via wp_remote_post + logging |
| `wordpress/wp-content/mu-plugins/bit-elementor-form-rdstation/class-form-action.php` | Classe `Form_Action` separada |
| `docker-dev/common/mu-plugins/bit-elementor-form-rdstation.php` | Cópia canônica do mu-plugin (regra `sites/CLAUDE.md`) |
| `docker-dev/common/mu-plugins/bit-elementor-form-rdstation/class-form-action.php` | Cópia canônica da classe |
| `scripts/rdstation-bootstrap-fields.php` | One-shot idempotente: GET /platform/contacts/fields → POST se não existir `cf_uf`/`cf_consent_source`/`cf_consent_timestamp` |
| `docker-compose.yml` | Adicionar env vars RD ao serviço `wordpress:` |
| `~/scripts/testes/concertacao/tests/09-rdstation-submit.spec.js` | Playwright spec: 5 cenários (success, graceful, token inválido, email inválido, sem config) |

**Não modificados:**
- `bit-elementor-form-responsive.php` (Parte 1, não dependência)
- Template footer 72234 (Action é adicionada via Task 9 via WP-CLI eval, não commitada)

**Logs em runtime (não versionados):**
- `wordpress/logs/bit-rdstation/YYYY-MM-DD.log` (fora do webroot — LGPD seguro)

---

## Pré-condições

- DEV concertacao subido: `std up`
- Container WP rodando: `concertacao-dev-wordpress`
- Branch: confirmar que `feat-rdstation-integration-part2` branchou de main (ou de `feat-footer-form-unified-part1` já mergeada). Ver Task 0 step 2.
- Spec Parte 2 acessível em `docs/superpowers/specs/2026-05-19-formulario-rodape-rdstation-design.md`
- Tokens `RDSTATION_PUBLIC_TOKEN` e `RDSTATION_PRIVATE_TOKEN` no `.env` do site (ponto de partida — serão movidos para o raiz na Task 1)


---

## Task 0: Validar pré-condições + estado da branch

**Files:** Read-only — confirmar setup

- [ ] **Step 1: Worktree clean + branch correta**

```bash
cd /Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao/.claude/worktrees/feat-footer-form-unified-part1
git branch --show-current
git status --short
git log --oneline -5
```

Expected:
- branch: `main` ou `feat-rdstation-integration-part2`
- status: vazio (working tree clean) OU lista de arquivos da Parte 1 já commitados

Se branch tiver commits da Parte 1 não mergeados em main: investigar antes de prosseguir. A Parte 2 deve ser desenvolvida sobre main (ou sobre branch Parte 1 se ainda não mergeada — mas deixar explícito no PR que depende da Parte 1).

- [ ] **Step 2: Verificar se Parte 1 já foi mergeada**

```bash
git log --oneline main..HEAD | wc -l
git log --oneline origin/main..HEAD 2>/dev/null | wc -l || true
```

Se há muitos commits divergentes da main contendo "feat(footer-form)" ou "feat(responsive)": a Parte 1 ainda não foi mergeada. Documentar no PR body que este PR depende da Parte 1.

- [ ] **Step 3: Container WP rodando + tokens disponíveis**

```bash
docker ps --filter "name=concertacao-dev-wordpress" --format "{{.Names}} {{.Status}}"
grep -E "^RDSTATION_(PUBLIC|PRIVATE)_TOKEN=." \
  /Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao/.env | cut -d= -f1
```

Expected:
- container "Up X minutes (healthy)"
- 2 linhas: `RDSTATION_PUBLIC_TOKEN`, `RDSTATION_PRIVATE_TOKEN`

- [ ] **Step 4: Spec acessível**

```bash
test -f docs/superpowers/specs/2026-05-19-formulario-rodape-rdstation-design.md && echo "SPEC_OK" || echo "SPEC_MISSING"
```

Expected: `SPEC_OK`

Sem commit neste task.

---

## Task 1: Migrar tokens para `.env` raiz com sufixo `_DEV/_HML/_PROD`

> **PRÉ-REQUISITO para deploy em HML/PROD.** NÃO é opcional. Replica o padrão de `SMTP_PASSWORD_*` e `GTM_CONTAINER_ID_*` já existentes no `.env` raiz. Evita exatamente o bug documentado em [[feedback_smtp_constants_missing_prod.md]].

**Files:**
- Modify (via env-writer-helper.sh): `/Users/dcambria/scripts/server-tools/v2/.env.concertacaoamazonia.com.br.sa`
- Modify (remover linhas): `docker-dev/sites/concertacao/.env`

- [ ] **Step 1: Ler tokens atuais do `.env` do site**

```bash
grep -E "^RDSTATION_(PUBLIC|PRIVATE)_TOKEN=" \
  /Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao/.env
```

Anotar os 2 valores. Lembrar: `RDSTATION_PUBLIC_TOKEN` é o que a API aceita (será `RDSTATION_API_KEY_*`).

- [ ] **Step 2: Verificar se já existem no `.env` raiz**

```bash
grep -E "RDSTATION" \
  /Users/dcambria/scripts/server-tools/v2/.env.concertacaoamazonia.com.br.sa || echo "NAO_EXISTE"
```

Se já existirem com sufixo correto: pular Step 3 e ir direto ao Step 4.

- [ ] **Step 3: Adicionar ao `.env` raiz via env-writer-helper.sh**

Substituir `<VALOR_PUBLIC>` pelo valor lido no Step 1 (que é o que a API aceita):

```bash
# env-writer-helper.sh é o ÚNICO responsável por escrever em .env
/Users/dcambria/scripts/server-tools/v2/helpers/env-writer-helper.sh \
  /Users/dcambria/scripts/server-tools/v2/.env.concertacaoamazonia.com.br.sa \
  RDSTATION_API_KEY_DEV "<VALOR_PUBLIC>"

/Users/dcambria/scripts/server-tools/v2/helpers/env-writer-helper.sh \
  /Users/dcambria/scripts/server-tools/v2/.env.concertacaoamazonia.com.br.sa \
  RDSTATION_API_KEY_HML "<VALOR_PUBLIC>"

/Users/dcambria/scripts/server-tools/v2/helpers/env-writer-helper.sh \
  /Users/dcambria/scripts/server-tools/v2/.env.concertacaoamazonia.com.br.sa \
  RDSTATION_API_KEY_PROD "<VALOR_PUBLIC>"
```

> Nota: Os 3 valores são idênticos (mesma conta RD da Concertação), mas precisam de sufixo para que o `config-helper.sh` resolva automaticamente no deploy. Se no futuro o cliente quiser conta de sandbox em DEV, basta trocar `_DEV`.

- [ ] **Step 4: Verificar escrita**

```bash
grep -E "RDSTATION_API_KEY_(DEV|HML|PROD)" \
  /Users/dcambria/scripts/server-tools/v2/.env.concertacaoamazonia.com.br.sa
```

Expected: 3 linhas com os valores corretos.

- [ ] **Step 5: Remover as 2 linhas antigas do `.env` do site**

```bash
# Primeiro, confirmar o que está lá
grep -n "RDSTATION" \
  /Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao/.env

# Remover (usando sed compatível com macOS)
sed -i '' '/^RDSTATION_PUBLIC_TOKEN=/d' \
  /Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao/.env
sed -i '' '/^RDSTATION_PRIVATE_TOKEN=/d' \
  /Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao/.env

# Confirmar remoção
grep "RDSTATION" \
  /Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao/.env || echo "REMOVIDO ok"
```

Expected: `REMOVIDO ok`

- [ ] **Step 6: Commit no server-tools (`.env` raiz é no repo v2)**

```bash
cd /Users/dcambria/scripts/server-tools/v2
git add .env.concertacaoamazonia.com.br.sa
git add docker-dev/sites/concertacao/.env
git commit -m "$(cat <<'EOF'
feat(concertacao/rdstation): migrar tokens para .env raiz com sufixo _DEV/_HML/_PROD

RDSTATION_PUBLIC_TOKEN (que a API /platform/conversions aceita como api_key)
renomeado para RDSTATION_API_KEY_{DEV,HML,PROD}.

Segue o padrao de SMTP_PASSWORD_* e GTM_CONTAINER_ID_* ja no raiz.
Evita bug documentado em feedback_smtp_constants_missing_prod.md.

RDSTATION_PRIVATE_TOKEN (UUID tracking JS) removido do site .env.
EOF
)"
cd /Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao/.claude/worktrees/feat-footer-form-unified-part1
```

---

## Task 2: Adicionar env vars ao docker-compose.yml do site

> **BLOCKER v1:** `getenv('RDSTATION_API_KEY')` retornava `""` porque a var não estava no bloco `environment:` do serviço wordpress.

**Files:**
- Modify: `docker-compose.yml`

- [ ] **Step 1: Localizar o bloco `environment:` do serviço wordpress**

```bash
grep -n "RDSTATION\|SMTP_\|environment:" \
  /Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao/docker-compose.yml | head -30
```

Identificar onde `SMTP_*` e outras credenciais são passadas para o container.

- [ ] **Step 2: Adicionar as vars RDSTATION no bloco environment do wordpress**

Editar `docker-compose.yml` adicionando ao `environment:` do serviço `wordpress`:

```yaml
# RD Station API Key (server-side) — lida do .env raiz LINKED_ENV
RDSTATION_API_KEY: ${RDSTATION_API_KEY_DEV}
```

- [ ] **Step 3: Validar que a env var chega no container**

```bash
# Reiniciar o serviço wordpress para pegar a nova variável
/Users/dcambria/scripts/server-tools/v2/docker-dev/common/bin/docker-dev.sh restart

# Aguardar container ficar healthy
docker ps --filter "name=concertacao-dev-wordpress" --format "{{.Status}}"

# Verificar env var dentro do container
docker exec concertacao-dev-wordpress env | grep RDSTATION
```

Expected: `RDSTATION_API_KEY=<valor>`

- [ ] **Step 4: Commit**

```bash
git add docker-compose.yml
git commit -m "feat(rdstation): expor RDSTATION_API_KEY no ambiente do container wordpress

Sem isso getenv() dentro do PHP retorna string vazia.
Padrao: docker-compose.yml mapeia VAR_DEV do .env raiz para VAR no container."
```

---

## Task 3: wp-config.php — constants idempotentes via bootstrap.sh

> **BLOCKER v1 (duplo):** (1) `cat >>` duplicava o bloco de defines a cada `std restart` → PHP fatal error. (2) O `wp-config.php` é gerado pelo bootstrap, então edição manual não persiste.

**Solução v2:** adicionar o bloco ao `docker-dev/common/scripts/bootstrap.sh` com guard idempotente (`grep -q`).

**Files:**
- Identify + Modify: `docker-dev/common/scripts/bootstrap.sh` (no server-tools)

- [ ] **Step 1: Localizar onde SMTP_* são injetados no wp-config**

```bash
grep -n "SMTP\|define.*getenv\|wp-config" \
  /Users/dcambria/scripts/server-tools/v2/docker-dev/common/scripts/bootstrap.sh | head -20
```

Identificar o padrão exato e adicionar bloco RDSTATION após o bloco SMTP (manter ordem lógica).

- [ ] **Step 2: Adicionar bloco RDSTATION ao bootstrap.sh com guard idempotente**

No `bootstrap.sh` do server-tools, após o bloco de SMTP:

```bash
# === RD Station API Key (BIT) ===
# RDSTATION_API_KEY vem do .env raiz via docker-compose.yml.
# ATENCAO: no painel RD o token e chamado "identificador publico" (PUBLIC_TOKEN),
# mas e o token aceito pela API /platform/conversions?api_key=X como server-side.
# Renomeado para RDSTATION_API_KEY para clareza semantica.
if ! grep -q "RDSTATION_API_KEY" /var/www/html/wp-config.php; then
    cat >> /var/www/html/wp-config.php << 'WPCFG'

// === RD Station (BIT) ===
// RDSTATION_API_KEY = token server-side aceito pela API /platform/conversions?api_key=X
if ( getenv( 'RDSTATION_API_KEY' ) ) {
    define( 'RDSTATION_API_KEY', getenv( 'RDSTATION_API_KEY' ) );
}
WPCFG
fi
```

- [ ] **Step 3: Adicionar o bloco manual no wp-config.php DEV atual (com guard)**

```bash
docker exec -u root concertacao-dev-wordpress sh -c '
  grep -q "RDSTATION_API_KEY" /var/www/html/wp-config.php || cat >> /var/www/html/wp-config.php << '"'"'WPCFG'"'"'

// === RD Station (BIT) ===
if ( getenv( '"'"'RDSTATION_API_KEY'"'"' ) ) {
    define( '"'"'RDSTATION_API_KEY'"'"', getenv( '"'"'RDSTATION_API_KEY'"'"' ) );
}
WPCFG
'
```

- [ ] **Step 4: Validar constants via WP-CLI**

```bash
docker exec -u www-data concertacao-dev-wordpress \
  wp --url="https://cambrasmax.local:8484/" eval \
  'echo "RDSTATION_API_KEY: " . (defined("RDSTATION_API_KEY") ? "DEFINED (len=".strlen(RDSTATION_API_KEY).")" : "UNDEFINED") . PHP_EOL;'
```

Expected: `RDSTATION_API_KEY: DEFINED (len=XX)` onde XX > 0.

- [ ] **Step 5: Verificar idempotência**

```bash
docker exec concertacao-dev-wordpress \
  grep -c "define.*RDSTATION_API_KEY" /var/www/html/wp-config.php || true
```

Expected: `1` (não duplicou).

- [ ] **Step 6: Commit do bootstrap.sh no server-tools**

```bash
cd /Users/dcambria/scripts/server-tools/v2
git add docker-dev/common/scripts/bootstrap.sh
git commit -m "feat(rdstation): injetar RDSTATION_API_KEY no wp-config.php via bootstrap

Guard idempotente (grep -q) evita duplicacao em re-run.
Segue o padrao do bloco SMTP_* existente."
cd /Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao/.claude/worktrees/feat-footer-form-unified-part1
```


---

## Task 4: Smoke test do endpoint RD via curl (validação real da API)

> Sempre executar antes de escrever PHP. Valida token + endpoint + formato de resposta real.

**Files:** (sem código novo — só validação)

- [ ] **Step 1: Extrair token e validar endpoint**

```bash
TOKEN=$(docker exec -u www-data concertacao-dev-wordpress \
  wp --url="https://cambrasmax.local:8484/" eval \
  'echo defined("RDSTATION_API_KEY") ? RDSTATION_API_KEY : "";')

if [ -z "$TOKEN" ]; then
  echo "ERRO: RDSTATION_API_KEY nao definido — verificar Tasks 1-3"
  exit 1
fi
echo "Token presente (len=${#TOKEN})"

curl -sS -w "\nHTTP_CODE: %{http_code}\n" \
  -X POST "https://api.rd.services/platform/conversions?api_key=$TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"event_type":"CONVERSION","event_family":"CDP","payload":{"conversion_identifier":"_smoke-test-bit","email":"smoke-test-bit-rdstation@bit-bpo.com"}}'
```

Expected: `HTTP_CODE: 200` + JSON com `event_uuid` (apenas esse campo na resposta de sucesso).

Se `HTTP_CODE: 401`: o token no `.env` ainda está errado — o `RDSTATION_PUBLIC_TOKEN` (não o `PRIVATE_TOKEN`) é o que a API aceita. Voltar ao Task 1 Step 1 e verificar qual valor foi copiado para `RDSTATION_API_KEY_DEV`.

> **Nota sobre sandbox:** este curl envia um lead real para a conta de produção do cliente (RD Station não tem sandbox gratuito por padrão). Usar `conversion_identifier="_smoke-test-bit"` e email `smoke-test-bit-rdstation@bit-bpo.com` — limpar manualmente depois no painel RD. O pattern `@bit-bpo.com` facilita identificar leads de teste para cleanup.

- [ ] **Step 2: Validar formato de resposta de sucesso**

```bash
curl -sS -X POST "https://api.rd.services/platform/conversions?api_key=$TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"event_type":"CONVERSION","event_family":"CDP","payload":{"conversion_identifier":"_smoke-test-bit","email":"smoke-test-bit-rdstation@bit-bpo.com"}}' \
  | python3 -m json.tool
```

Expected: `{"event_uuid": "<uuid>"}` (apenas esse campo — não há `event_type` na resposta).

- [ ] **Step 3: Validar formato de erro 400**

```bash
curl -sS -w "\nHTTP_CODE: %{http_code}\n" \
  -X POST "https://api.rd.services/platform/conversions?api_key=$TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"event_type":"CONVERSION","event_family":"CDP","payload":{"conversion_identifier":"","email":"not-valid"}}' \
  | python3 -m json.tool
```

Expected: `HTTP_CODE: 400` + JSON com estrutura:
```json
{"errors": [{"error_type": "...", "error_message": "...", "validation_rules": [...], "path": "..."}]}
```

- [ ] **Step 4: Confirmar que cf_uf aceita valor sem pré-cadastro**

```bash
curl -sS -w "\nHTTP_CODE: %{http_code}\n" \
  -X POST "https://api.rd.services/platform/conversions?api_key=$TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"event_type":"CONVERSION","event_family":"CDP","payload":{"conversion_identifier":"_smoke-test-bit","email":"smoke-test-bit-rdstation@bit-bpo.com","cf_uf":"PA"}}'
echo
```

Expected: `HTTP_CODE: 200` (RD aceita silenciosamente; cf_uf pode ou não aparecer no painel sem pré-cadastro do campo).

Sem commit neste task.

---

## Task 5: Esqueleto do mu-plugin (header + guard + namespace)

**Files:**
- Create: `wordpress/wp-content/mu-plugins/bit-elementor-form-rdstation.php`

- [ ] **Step 1: Criar arquivo com header canônico + guard**

Conteúdo de `wordpress/wp-content/mu-plugins/bit-elementor-form-rdstation.php`:

```php
<?php
/**
 * Plugin Name: BIT Elementor Form RD Station
 * Plugin URI:  https://bureau-it.com
 * Description: Form Action customizada que envia submits do Elementor Pro Forms
 *              para a API REST do RD Station Marketing (POST /platform/conversions
 *              via API Key). Graceful: falhas da API NUNCA quebram o submit do form.
 *
 *              API Key: RDSTATION_API_KEY em wp-config.php (resolucao de sufixo
 *              _DEV/_HML/_PROD feita pelo bootstrap.sh via docker-compose.yml).
 *
 *              NOTA HISTORICA: a API /platform/conversions aceita como api_key o token
 *              que o painel RD Station chama de "identificador publico" (PUBLIC_TOKEN).
 *              O nome foi invertido semanticamente — aqui usamos RDSTATION_API_KEY.
 *              Nao confundir com RDSTATION_PRIVATE_TOKEN (UUID tracking JS, nao usado).
 *
 *              Resposta de sucesso: {"event_uuid": "<uuid>"} (apenas esse campo).
 *              Erros: HTTP 400, {"errors":[{"error_type","error_message","path"}]}.
 *
 *              Log: WP_CONTENT_DIR/../logs/bit-rdstation/YYYY-MM-DD.log (FORA do webroot).
 *
 *              Spec: docs/superpowers/specs/2026-05-19-formulario-rodape-rdstation-design.md
 * Version:     1.0.0
 * Author:      Daniel Cambria / Bureau de Tecnologia Ltda.
 * Network:     true
 */

defined( 'ABSPATH' ) || exit;

namespace BIT\ElementorFormRDStation;

const VERSION               = '1.0.0';
const ACTION_NAME           = 'bit_rdstation';
const RD_API_ENDPOINT       = 'https://api.rd.services/platform/conversions';
const RD_TIMEOUT_SEC        = 8;
const DEFAULT_CONVERSION_ID = 'newsletter-footer-concertacao';

// Guard: so atua se Elementor Pro estiver carregado.
add_action( 'plugins_loaded', function () {
    if ( ! class_exists( '\ElementorPro\Plugin' ) ) {
        return;
    }
    // Action registration vai em Task 6
}, 20 );
```

- [ ] **Step 2: Validar sintaxe PHP**

```bash
docker exec concertacao-dev-wordpress \
  php -l /var/www/html/wp-content/mu-plugins/bit-elementor-form-rdstation.php
```

Expected: `No syntax errors detected`

- [ ] **Step 3: Confirmar mu-plugin é carregado pelo WordPress**

```bash
docker exec -u www-data concertacao-dev-wordpress \
  wp --url="https://cambrasmax.local:8484/" eval \
  'echo defined("BIT\\ElementorFormRDStation\\VERSION") ? "LOADED" : "NOT_LOADED";'
```

Expected: `LOADED`

- [ ] **Step 4: Commit**

```bash
git add wordpress/wp-content/mu-plugins/bit-elementor-form-rdstation.php
git commit -m "feat(rdstation): esqueleto do mu-plugin com header BIT + guard Elementor Pro"
```

---

## Task 6: Registrar Form Action `bit_rdstation`

**Files:**
- Modify: `wordpress/wp-content/mu-plugins/bit-elementor-form-rdstation.php`
- Create: `wordpress/wp-content/mu-plugins/bit-elementor-form-rdstation/class-form-action.php`

- [ ] **Step 1: Substituir o callback de `plugins_loaded` pela registração da Action**

```php
add_action( 'plugins_loaded', function () {
    if ( ! class_exists( '\ElementorPro\Plugin' ) ) {
        return;
    }
    require_once __DIR__ . '/bit-elementor-form-rdstation/class-form-action.php';

    add_action( 'elementor_pro/forms/actions/register', function ( $registrar ) {
        $registrar->register( new Form_Action() );
    } );
}, 20 );
```

- [ ] **Step 2: Criar o diretório + classe stub**

```bash
mkdir -p /Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao/.claude/worktrees/feat-footer-form-unified-part1/wordpress/wp-content/mu-plugins/bit-elementor-form-rdstation
```

Conteúdo de `wordpress/wp-content/mu-plugins/bit-elementor-form-rdstation/class-form-action.php`:

```php
<?php
namespace BIT\ElementorFormRDStation;

defined( 'ABSPATH' ) || exit;

class Form_Action extends \ElementorPro\Modules\Forms\Classes\Action_Base {

    public function get_name(): string {
        return ACTION_NAME;
    }

    public function get_label(): string {
        return 'RD Station (BIT)';
    }

    public function register_settings_section( $widget ): void {
        // Implementado em Task 7
    }

    public function run( $record, $ajax_handler ): void {
        // Implementado em Task 8
    }

    /**
     * on_export() — DEVE retornar $element (nao array vazio).
     * Retornar [] DESTROI o widget no export do template Elementor (BLOCKER v1 corrigido).
     */
    public function on_export( $element ): array {
        // Remover settings que nao devem vazar no export JSON do template
        unset( $element['settings']['bit_rd_conversion_identifier'] );
        return $element;
    }
}
```

- [ ] **Step 3: Validar sintaxe**

```bash
docker exec concertacao-dev-wordpress \
  php -l /var/www/html/wp-content/mu-plugins/bit-elementor-form-rdstation.php
docker exec concertacao-dev-wordpress \
  php -l /var/www/html/wp-content/mu-plugins/bit-elementor-form-rdstation/class-form-action.php
```

Expected: `No syntax errors detected` (2x)

- [ ] **Step 4: Confirmar Action aparece registrada**

```bash
docker exec -u www-data concertacao-dev-wordpress \
  wp --url="https://cambrasmax.local:8484/" eval '
$registrar = \ElementorPro\Plugin::instance()->modules_manager->get_modules("forms")->get_actions_registrar();
$actions = $registrar->get();
echo isset($actions["bit_rdstation"]) ? "REGISTERED ok" : "NOT_REGISTERED";
'
```

Expected: `REGISTERED ok`

> Nota: usar `->get()` e nao `->get_actions()` — o metodo correto do registrar e `get()`.

- [ ] **Step 5: Commit**

```bash
git add wordpress/wp-content/mu-plugins/bit-elementor-form-rdstation.php \
        wordpress/wp-content/mu-plugins/bit-elementor-form-rdstation/
git commit -m "feat(rdstation): registra Form Action bit_rdstation no Elementor Pro

on_export() retorna \$element (nao []) para nao destruir widget no export."
```


---

## Task 7: register_settings_section — controles do painel Elementor

**Files:**
- Modify: `wordpress/wp-content/mu-plugins/bit-elementor-form-rdstation/class-form-action.php`

- [ ] **Step 1: Implementar `register_settings_section()`**

Substituir o stub vazio por:

```php
public function register_settings_section( $widget ): void {
    $widget->start_controls_section(
        'section_bit_rdstation',
        [
            'label'     => 'RD Station (BIT)',
            'condition' => [
                'submit_actions' => ACTION_NAME,
            ],
        ]
    );

    $widget->add_control(
        'bit_rd_conversion_identifier',
        [
            'label'       => 'Conversion Identifier',
            'type'        => \Elementor\Controls_Manager::TEXT,
            'default'     => DEFAULT_CONVERSION_ID,
            'placeholder' => DEFAULT_CONVERSION_ID,
            'description' => 'Identificador da conversao no painel RD Station (kebab-case, estavel).',
        ]
    );

    $widget->add_control(
        'bit_rd_email_field',
        [
            'label'       => 'Campo Email (custom_id)',
            'type'        => \Elementor\Controls_Manager::TEXT,
            'default'     => 'email',
            'description' => 'custom_id do field tipo email do form (ex: email, form_email_desk).',
        ]
    );

    $widget->add_control(
        'bit_rd_uf_field',
        [
            'label'       => 'Campo UF (custom_id)',
            'type'        => \Elementor\Controls_Manager::TEXT,
            'default'     => '',
            'placeholder' => 'form_regiao_desk',
            'description' => 'custom_id do field select de UF/Regiao. Vazio = nao envia cf_uf.',
        ]
    );

    $widget->add_control(
        'bit_rd_tags',
        [
            'label'       => 'Tags (CSV)',
            'type'        => \Elementor\Controls_Manager::TEXT,
            'default'     => 'newsletter,concertacao-amazonia,footer-form',
            'description' => 'Tags aplicadas ao contato no RD Station, separadas por virgula.',
        ]
    );

    $widget->end_controls_section();
}
```

- [ ] **Step 2: Validar sintaxe**

```bash
docker exec concertacao-dev-wordpress \
  php -l /var/www/html/wp-content/mu-plugins/bit-elementor-form-rdstation/class-form-action.php
```

Expected: `No syntax errors detected`

- [ ] **Step 3: Validar editor Elementor manualmente**

1. Abrir editor: `https://cambrasmax.local:8484/wp-admin/post.php?post=72234&action=elementor`
2. Selecionar widget Form da PREVIEW (id `520a235`)
3. Aba "Conteudo" → "Acoes apos o envio"
4. Adicionar action "RD Station (BIT)" no dropdown
5. Confirmar que aparece nova secao "RD Station (BIT)" com 4 controles: Conversion Identifier, Campo Email, Campo UF, Tags

Se nao aparecer: Action nao foi registrada corretamente. Voltar Task 6 step 4.

- [ ] **Step 4: Commit**

```bash
git add wordpress/wp-content/mu-plugins/bit-elementor-form-rdstation/class-form-action.php
git commit -m "feat(rdstation): controles do painel Elementor (conversion_id, email field, uf field, tags)"
```

---

## Task 8: run() + log() — POST para RD Station com graceful degradation

> **Mudancas v2 neste task:**
> - Constante `RDSTATION_API_KEY` (nao `RDSTATION_PRIVATE_TOKEN`)
> - Log fora do webroot: `WP_CONTENT_DIR . '/../logs/bit-rdstation/'` — LGPD seguro
> - `file_put_contents` com verificacao explicita de erro (nao `@` silent suppressor)
> - `legal_bases` com `status=declined` por default ate checkbox LGPD existir
> - UF: validar contra lista de siglas BR (evita enviar label "Regiao" como valor)

**Files:**
- Modify: `wordpress/wp-content/mu-plugins/bit-elementor-form-rdstation/class-form-action.php`
- Modify: `wordpress/wp-content/mu-plugins/bit-elementor-form-rdstation.php`

- [ ] **Step 1: Implementar `run()`**

Substituir o stub vazio por:

```php
public function run( $record, $ajax_handler ): void {
    // 1. Conferir token disponivel — se nao, log + return (graceful)
    if ( ! defined( 'RDSTATION_API_KEY' ) || ! RDSTATION_API_KEY ) {
        log( 'warn', 'RDSTATION_API_KEY nao definido — submit ignorado (graceful)' );
        return;
    }

    // 2. Pegar settings da action
    $form_settings = $record->get( 'form_settings' );
    $conversion_id = trim( $form_settings['bit_rd_conversion_identifier'] ?? DEFAULT_CONVERSION_ID );
    $email_field   = trim( $form_settings['bit_rd_email_field'] ?? 'email' );
    $uf_field      = trim( $form_settings['bit_rd_uf_field'] ?? '' );
    $tags_csv      = trim( $form_settings['bit_rd_tags'] ?? '' );

    // 3. Pegar fields submetidos
    // Shape real dos fields: ['id','type','title','value','raw_value','required']
    $raw_fields = $record->get( 'fields' );
    $email_raw  = $raw_fields[ $email_field ]['value'] ?? '';
    $uf_raw     = $uf_field ? ( $raw_fields[ $uf_field ]['value'] ?? '' ) : '';

    // Nota: RD aceita email invalido com HTTP 200 — sanitize_email() e o unico gate real.
    $email = sanitize_email( $email_raw );
    if ( ! $email ) {
        log( 'warn', 'Email invalido ou vazio — submit ignorado', [ 'raw' => $email_raw, 'field' => $email_field ] );
        return;
    }

    // 4. Montar payload
    $payload = [
        'conversion_identifier' => $conversion_id ?: DEFAULT_CONVERSION_ID,
        'email'                 => $email,
    ];

    // UF: garantir que e uma sigla de estado (2 letras uppercase), nao o label "Regiao"
    if ( $uf_raw ) {
        $uf_clean = strtoupper( sanitize_text_field( $uf_raw ) );
        $br_states = [
            'AC','AL','AM','AP','BA','CE','DF','ES','GO','MA','MG','MS',
            'MT','PA','PB','PE','PI','PR','RJ','RN','RO','RR','RS','SC',
            'SE','SP','TO',
        ];
        if ( in_array( $uf_clean, $br_states, true ) ) {
            $payload['cf_uf'] = $uf_clean;
        } else {
            log( 'warn', 'cf_uf valor invalido (nao e sigla BR) — ignorado', [ 'raw' => $uf_raw ] );
        }
    }

    if ( $tags_csv ) {
        $payload['tags'] = array_values(
            array_filter( array_map( 'trim', explode( ',', $tags_csv ) ) )
        );
    }

    // legal_bases: "declined" por default — sem checkbox de consentimento ainda.
    // LGPD: nao representar "granted" sem confirmacao explicita do lead.
    // TODO: quando checkbox LGPD for implementado, ler $form_settings['bit_rd_consent_field']
    //       e setar status='granted' se marcado, 'declined' se nao marcado.
    $payload['legal_bases'] = [
        [ 'category' => 'communications', 'type' => 'consent', 'status' => 'declined' ],
    ];

    $body = [
        'event_type'   => 'CONVERSION',
        'event_family' => 'CDP',
        'payload'      => $payload,
    ];

    // 5. POST — api_key na query string (padrao da API RD /platform/conversions)
    // TODO performance: migrar para wp_schedule_single_event async se saturacao FPM detectada.
    // Custo atual: max 8s sincronico por submit.
    $url = add_query_arg( 'api_key', RDSTATION_API_KEY, RD_API_ENDPOINT );

    $response = wp_remote_post( $url, [
        'timeout' => RD_TIMEOUT_SEC,
        'headers' => [ 'Content-Type' => 'application/json' ],
        'body'    => wp_json_encode( $body ),
    ] );

    // 6. Log resultado — NUNCA chama add_error_message (graceful)
    if ( is_wp_error( $response ) ) {
        log( 'error', 'wp_remote_post falhou', [
            'msg'   => $response->get_error_message(),
            'email' => $email,
        ] );
        return;
    }

    $code      = wp_remote_retrieve_response_code( $response );
    $resp_body = wp_remote_retrieve_body( $response );

    if ( $code >= 200 && $code < 300 ) {
        // Resposta de sucesso: {"event_uuid": "<uuid>"} — apenas esse campo.
        log( 'info', "OK $code", [ 'email' => $email, 'cid' => $conversion_id ] );
    } else {
        // Erro: {"errors":[{"error_type","error_message","validation_rules","path"}]}
        log( 'error', "RD respondeu $code", [
            'email' => $email,
            'body'  => substr( $resp_body, 0, 500 ),
        ] );
    }
}
```

- [ ] **Step 2: Criar a funcao `log()` no mu-plugin principal — FORA do webroot (LGPD)**

Adicionar ao final de `wordpress/wp-content/mu-plugins/bit-elementor-form-rdstation.php`:

```php
/**
 * Log estruturado fora do webroot (LGPD — nao acessivel publicamente).
 *
 * Diretorio: WP_CONTENT_DIR . '/../logs/bit-rdstation/' (um nivel acima de wp-content).
 * Em DEV Docker: /var/www/html/logs/bit-rdstation/ (fora de /wp-content/uploads/).
 * Em PROD EC2:   /var/www/concertacaoamazonia.com.br/logs/bit-rdstation/ (fora do nginx root).
 *
 * NUNCA logar em wp-content/uploads/ — seria acessivel publicamente e via S3 public policy.
 *
 * Sempre loga warn/error. Loga info apenas se BIT_RDSTATION_DEBUG=true.
 *
 * @param string $level 'info' | 'warn' | 'error'
 * @param string $msg
 * @param array  $ctx
 */
function log( string $level, string $msg, array $ctx = [] ): void {
    if ( $level === 'info' && ! ( defined( 'BIT_RDSTATION_DEBUG' ) && BIT_RDSTATION_DEBUG ) ) {
        return;
    }

    $log_dir = dirname( WP_CONTENT_DIR ) . '/logs/bit-rdstation';

    if ( ! is_dir( $log_dir ) ) {
        if ( ! wp_mkdir_p( $log_dir ) ) {
            error_log( sprintf( '[BIT RDStation] LOG_DIR_CREATE_FAILED: %s', $log_dir ) );
            return;
        }
    }

    $log_file = $log_dir . '/' . gmdate( 'Y-m-d' ) . '.log';
    $line     = sprintf(
        "[%s] [%s] %s%s\n",
        gmdate( 'Y-m-d H:i:s' ),
        strtoupper( $level ),
        $msg,
        $ctx ? ' ' . wp_json_encode( $ctx, JSON_UNESCAPED_UNICODE ) : ''
    );

    // Escrita explicita — sem @ silent suppressor (violacao do padrao BIT).
    $result = file_put_contents( $log_file, $line, FILE_APPEND | LOCK_EX );
    if ( $result === false ) {
        error_log( '[BIT RDStation] WRITE_FAILED ' . $log_file . ' | ' . $line );
    }
}
```

- [ ] **Step 3: Criar o diretorio de log no container**

```bash
docker exec -u www-data concertacao-dev-wordpress \
  mkdir -p /var/www/html/logs/bit-rdstation

docker exec concertacao-dev-wordpress \
  ls -la /var/www/html/logs/
```

Expected: diretorio `bit-rdstation` com owner `www-data`.

> Em PROD: o log ira para `/var/www/concertacaoamazonia.com.br/logs/bit-rdstation/` — fora do nginx root. Verificar no deploy que o diretorio existe e tem permissoes corretas para o web user.

- [ ] **Step 4: Validar sintaxe (ambos arquivos)**

```bash
docker exec concertacao-dev-wordpress \
  php -l /var/www/html/wp-content/mu-plugins/bit-elementor-form-rdstation.php
docker exec concertacao-dev-wordpress \
  php -l /var/www/html/wp-content/mu-plugins/bit-elementor-form-rdstation/class-form-action.php
```

Expected: 2x `No syntax errors detected`

- [ ] **Step 5: Commit**

```bash
git add wordpress/wp-content/mu-plugins/bit-elementor-form-rdstation.php \
        wordpress/wp-content/mu-plugins/bit-elementor-form-rdstation/
git commit -m "feat(rdstation): run() POSTa pra RD via wp_remote_post + log fora do webroot

- legal_bases.status=declined por default (sem checkbox LGPD ainda)
- Log em WP_CONTENT_DIR/../logs/bit-rdstation/ (fora do webroot e do S3)
- UF validada contra lista de siglas BR (evita enviar label como valor)
- file_put_contents sem @ (erro explicito via error_log fallback)
- api_key via add_query_arg"
```


---

## Task 9: Wire-up no template footer 72234 (PREVIEW form)

**Files:**
- Modify (via WP-CLI eval): `_elementor_data` do post 72234, widget Form `520a235`

- [ ] **Step 1: Conferir custom_id atual dos fields da PREVIEW**

```bash
docker exec -u www-data concertacao-dev-wordpress \
  wp --url="https://cambrasmax.local:8484/" eval '
$raw = get_post_meta( 72234, "_elementor_data", true );
$data = json_decode( $raw, true );
function find_form( $nodes ) {
    foreach ( $nodes as $n ) {
        if ( ( $n["widgetType"] ?? "" ) === "form" && ( $n["id"] ?? "" ) === "520a235" ) return $n;
        if ( ! empty( $n["elements"] ) ) { $r = find_form( $n["elements"] ); if ( $r ) return $r; }
    }
    return null;
}
$w = find_form( $data );
if ( ! $w ) { echo "WIDGET_NOT_FOUND\n"; return; }
foreach ( $w["settings"]["form_fields"] as $f ) {
    echo $f["custom_id"] . " (type=" . $f["field_type"] . ")\n";
}
echo "submit_actions atual: " . wp_json_encode( $w["settings"]["submit_actions"] ?? [] ) . "\n";
'
```

Anotar os custom_ids exatos dos fields de email e UF para o Step 2.

- [ ] **Step 2: Adicionar action `bit_rdstation` + settings via WP-CLI**

```bash
docker exec -u www-data concertacao-dev-wordpress \
  wp --url="https://cambrasmax.local:8484/" eval '
$raw  = get_post_meta( 72234, "_elementor_data", true );
$data = json_decode( $raw, true );
function find_path( &$nodes, $tid, $path = [] ) {
    foreach ( $nodes as $i => $n ) {
        $np = array_merge( $path, [ $i ] );
        if ( ( $n["widgetType"] ?? "" ) === "form" && ( $n["id"] ?? "" ) === $tid ) return $np;
        if ( ! empty( $n["elements"] ) ) {
            $r = find_path( $n["elements"], $tid, array_merge( $np, ["elements"] ) );
            if ( $r !== null ) return $r;
        }
    }
    return null;
}
$path = find_path( $data, "520a235" );
if ( ! $path ) { echo "WIDGET_NOT_FOUND\n"; return; }
$ref = &$data;
foreach ( $path as $k ) { $ref = &$ref[$k]; }
$s = &$ref["settings"];
$actions = $s["submit_actions"] ?? [];
if ( is_string( $actions ) ) { $actions = array_filter( explode( ",", $actions ) ); }
if ( ! in_array( "bit_rdstation", $actions, true ) ) { $actions[] = "bit_rdstation"; }
$s["submit_actions"] = array_values( $actions );
$s["bit_rd_conversion_identifier"] = "newsletter-footer-concertacao";
$s["bit_rd_email_field"]           = "form_email_desk";
$s["bit_rd_uf_field"]              = "form_regiao_desk";
$s["bit_rd_tags"]                  = "newsletter,concertacao-amazonia,footer-form";
$encoded = wp_slash( wp_json_encode( $data ) );
delete_post_meta( 72234, "_elementor_data" );
add_post_meta( 72234, "_elementor_data", $encoded, true );
clean_post_cache( 72234 );
\Elementor\Plugin::$instance->files_manager->clear_cache();
echo "submit_actions agora: " . wp_json_encode( $s["submit_actions"] ) . "\n";
echo "ok\n";
'
```

Expected: `submit_actions agora: ["email","bit_rdstation"]` + `ok`.

- [ ] **Step 3: Confirmar no editor Elementor**

1. Abrir: `https://cambrasmax.local:8484/wp-admin/post.php?post=72234&action=elementor`
2. Selecionar widget Form `520a235`
3. Aba "Conteudo" → "Acoes apos o envio"
4. Confirmar "RD Station (BIT)" no dropdown e settings preenchidos

- [ ] **Step 4: Sem commit** (mudanca no DB — nao em codigo git)

---

## Task 10: Submit funcional + validacao do log

**Files:** Read-only (validacao)

- [ ] **Step 1: Ativar BIT_RDSTATION_DEBUG (com guard idempotente)**

```bash
docker exec -u root concertacao-dev-wordpress sh -c '
  grep -q "BIT_RDSTATION_DEBUG" /var/www/html/wp-config.php || \
    echo "define( '"'"'BIT_RDSTATION_DEBUG'"'"', true );" >> /var/www/html/wp-config.php
'
docker exec -u www-data concertacao-dev-wordpress \
  wp --url="https://cambrasmax.local:8484/" eval \
  'echo defined("BIT_RDSTATION_DEBUG") && BIT_RDSTATION_DEBUG ? "ON" : "OFF";'
```

Expected: `ON`

- [ ] **Step 2: Submit via Playwright (recomendado) ou curl**

Via Playwright (preferido — evita problemas com nonce/reCAPTCHA):

```bash
cd /Users/dcambria/scripts/testes/concertacao
npx playwright test tests/09-rdstation-submit.spec.js --reporter=line
```

Alternativa via curl (requer mu-plugin `bit-smoke-recaptcha-bypass` ativo e token configurado):

```bash
EMAIL="test-$(date +%s)@bit-bpo.com"
SMOKE_TOKEN=$(docker exec -u www-data concertacao-dev-wordpress \
  wp --url='https://cambrasmax.local:8484/' config get BIT_SMOKE_BYPASS_TOKEN 2>/dev/null)

curl -sS -k -X POST "https://cambrasmax.local:8484/wp-admin/admin-ajax.php" \
  -H "X-BIT-Smoke-Token: $SMOKE_TOKEN" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  --data-urlencode "action=elementor_pro_forms_send_form" \
  --data-urlencode "form_id=520a235" \
  --data-urlencode "post_id=72234" \
  --data-urlencode "fields[form_email_desk]=$EMAIL" \
  --data-urlencode "fields[form_regiao_desk]=PA"
echo
```

Expected: JSON com `"success":true`.

- [ ] **Step 3: Validar log gerado**

```bash
docker exec -u www-data concertacao-dev-wordpress sh -c '
  LOG=/var/www/html/logs/bit-rdstation/$(date +%Y-%m-%d).log
  [ -f "$LOG" ] && cat "$LOG" || echo "LOG_NAO_EXISTE: $LOG"
'
```

Expected: linha com `[INFO] OK 200 {"email":"test-...","cid":"newsletter-footer-concertacao"}`.

- [ ] **Step 4: Verificar que log NAO e acessivel via HTTP (LGPD)**

```bash
curl -sk "https://cambrasmax.local:8484/logs/bit-rdstation/" -o /dev/null -w "%{http_code}\n"
```

Expected: `404` ou `403` (nunca `200`).

- [ ] **Step 5: Desativar BIT_RDSTATION_DEBUG**

```bash
docker exec -u root concertacao-dev-wordpress \
  sed -i '' "/define.*'BIT_RDSTATION_DEBUG'/d" /var/www/html/wp-config.php
docker exec -u www-data concertacao-dev-wordpress \
  wp --url="https://cambrasmax.local:8484/" eval \
  'echo defined("BIT_RDSTATION_DEBUG") ? "STILL_ON" : "OFF";'
```

Expected: `OFF`

Sem commit neste task.


---

## Task 11: Script bootstrap dos custom fields (cf_uf)

**Files:**
- Create: `scripts/rdstation-bootstrap-fields.php`

- [ ] **Step 1: Criar o script standalone (idempotente)**

Conteudo de `scripts/rdstation-bootstrap-fields.php`:

```php
<?php
/**
 * rdstation-bootstrap-fields.php — One-shot idempotente.
 *
 * Cria custom fields no painel RD Station via API REST.
 * Endpoint /platform/contacts/fields exige OAuth2 Bearer Token, NAO api_key.
 *
 * Para gerar o Bearer Token (valido 24h):
 *   Opcao simples: criar campos manualmente em:
 *   https://app.rdstation.com.br/ > Configuracoes > Campos Personalizados
 *
 * Uso via WP-CLI (com Bearer Token):
 *   RDSTATION_BEARER=<token> docker exec -u www-data concertacao-dev-wordpress \
 *     wp --url="https://cambrasmax.local:8484/" eval-file /tmp/rdstation-bootstrap-fields.php
 *
 * @file scripts/rdstation-bootstrap-fields.php
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    fwrite( STDERR, "Run via: wp eval-file /path/rdstation-bootstrap-fields.php\n" );
    exit( 1 );
}

$bearer = getenv( 'RDSTATION_BEARER' );
if ( ! $bearer ) {
    fwrite( STDERR, "ERRO: variavel RDSTATION_BEARER nao definida.\n" );
    fwrite( STDERR, "Alternativa mais simples: criar campos manualmente em:\n" );
    fwrite( STDERR, "  https://app.rdstation.com.br/ > Configuracoes > Campos Personalizados\n" );
    exit( 1 );
}

$fields_to_create = [
    [
        'api_identifier'    => 'cf_uf',
        'name'              => [ 'pt-BR' => 'UF' ],
        'label'             => [ 'pt-BR' => 'UF (sigla brasileira)' ],
        'data_type'         => 'STRING',
        'presentation_type' => 'TEXT_INPUT',
    ],
    [
        'api_identifier'    => 'cf_consent_source',
        'name'              => [ 'pt-BR' => 'Origem do Consentimento' ],
        'label'             => [ 'pt-BR' => 'URL onde o consentimento foi coletado' ],
        'data_type'         => 'STRING',
        'presentation_type' => 'TEXT_INPUT',
    ],
    [
        'api_identifier'    => 'cf_consent_timestamp',
        'name'              => [ 'pt-BR' => 'Timestamp do Consentimento' ],
        'label'             => [ 'pt-BR' => 'ISO 8601 do submit' ],
        'data_type'         => 'STRING',
        'presentation_type' => 'TEXT_INPUT',
    ],
];

$response = wp_remote_get( 'https://api.rd.services/platform/contacts/fields', [
    'headers' => [ 'Authorization' => 'Bearer ' . $bearer ],
    'timeout' => 10,
] );

if ( is_wp_error( $response ) ) {
    fwrite( STDERR, "ERRO ao listar fields: " . $response->get_error_message() . "\n" );
    exit( 1 );
}

$code = wp_remote_retrieve_response_code( $response );
if ( $code !== 200 ) {
    fwrite( STDERR, "ERRO HTTP $code: " . wp_remote_retrieve_body( $response ) . "\n" );
    exit( 1 );
}

$body         = wp_remote_retrieve_body( $response );
$existing     = json_decode( $body, true );
$existing_ids = [];
if ( is_array( $existing ) ) {
    foreach ( $existing as $f ) {
        if ( isset( $f['api_identifier'] ) ) {
            $existing_ids[] = $f['api_identifier'];
        }
    }
}
echo "Fields existentes: " . count( $existing_ids ) . "\n";

foreach ( $fields_to_create as $field ) {
    if ( in_array( $field['api_identifier'], $existing_ids, true ) ) {
        echo "[OK] " . $field['api_identifier'] . " ja existe — pulando\n";
        continue;
    }
    $resp = wp_remote_post( 'https://api.rd.services/platform/contacts/fields', [
        'timeout' => 10,
        'headers' => [
            'Authorization' => 'Bearer ' . $bearer,
            'Content-Type'  => 'application/json',
        ],
        'body' => wp_json_encode( $field ),
    ] );
    if ( is_wp_error( $resp ) ) {
        echo "[ERRO] " . $field['api_identifier'] . ": " . $resp->get_error_message() . "\n";
        continue;
    }
    $code = wp_remote_retrieve_response_code( $resp );
    if ( $code === 201 ) {
        echo "[CRIADO] " . $field['api_identifier'] . "\n";
    } else {
        echo "[?] " . $field['api_identifier'] . " HTTP $code: " .
             substr( wp_remote_retrieve_body( $resp ), 0, 200 ) . "\n";
    }
}
echo "done\n";
```

- [ ] **Step 2: Validar sintaxe**

```bash
php -l scripts/rdstation-bootstrap-fields.php
```

Expected: `No syntax errors detected`

- [ ] **Step 3: Executar ou criar manualmente (escolha uma opcao)**

**Opcao A — Manual (recomendada, mais simples):**

```
https://app.rdstation.com.br/ → Configuracoes → Campos Personalizados
Criar 3 campos tipo Texto: cf_uf, cf_consent_source, cf_consent_timestamp
```

**Opcao B — via script (requer OAuth2 Bearer Token valido 24h):**

```bash
RDSTATION_BEARER=<token> docker exec -u www-data concertacao-dev-wordpress \
  wp --url="https://cambrasmax.local:8484/" \
  eval-file /tmp/rdstation-bootstrap-fields.php
```

Documentar qual opcao foi escolhida no commit.

- [ ] **Step 4: Commit do script (mesmo se nao executado)**

```bash
git add scripts/rdstation-bootstrap-fields.php
git commit -m "feat(rdstation): script bootstrap dos custom fields (cf_uf + consent)

Idempotente: GET /platform/contacts/fields antes de POST.
Exige OAuth2 Bearer Token (nao API Key) — endpoint nao aceita api_key.
Alternativa simples: criar manualmente no painel RD."
```


---

## Task 12: Spec Playwright — 5 cenarios de teste

**Files:**
- Create: `~/scripts/testes/concertacao/tests/09-rdstation-submit.spec.js`

- [ ] **Step 1: Criar spec com 5 cenarios**

O spec usa `execFileSync` (nao `exec`) para evitar injecao via shell. Os comandos do container sao passados como array de argumentos:

```javascript
'use strict';

/**
 * 09-rdstation-submit.spec.js
 * Playwright spec: Form Action bit_rdstation — 5 cenarios
 *
 * 1. Submit normal: success message visivel
 * 2. Graceful sem config: RDSTATION_API_KEY nao definido → form ainda dá success
 * 3. Token invalido: API retorna 401 → log [ERROR] → form ainda da success
 * 4. Email invalido: sanitize_email() rejeita → log [WARN] → form ainda da success
 * 5. Blog 2 multisite (/cultura/): form funciona se existir no template
 */

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');
const fs = require('fs');

try {
  require('dotenv').config({ path: path.join(__dirname, '..', '.env.local') });
} catch {}

const screenshotsDir = path.join(__dirname, '..', 'screenshots', 'rdstation');
const CONTAINER = 'concertacao-dev-wordpress';
const WP_URL    = 'https://cambrasmax.local:8484/';

test.beforeAll(() => {
  fs.mkdirSync(screenshotsDir, { recursive: true });
});

/** Executa WP-CLI eval dentro do container sem shell injection */
function wpEval(phpCode) {
  return execFileSync('docker', [
    'exec', '-u', 'www-data', CONTAINER,
    'wp', '--url=' + WP_URL, 'eval', phpCode,
  ], { encoding: 'utf8' }).trim();
}

/** Le ultima linha do log de hoje */
function getLogLastLine() {
  try {
    const today = new Date().toISOString().slice(0, 10);
    return execFileSync('docker', [
      'exec', '-u', 'www-data', CONTAINER,
      'sh', '-c',
      'tail -1 /var/www/html/logs/bit-rdstation/' + today + '.log 2>/dev/null || echo ""',
    ], { encoding: 'utf8' }).trim();
  } catch {
    return '';
  }
}

/** Preenche e submete o form do rodape */
async function submitForm(page, email, uf) {
  await page.goto('/', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1000);
  try { await page.locator('button:has-text("ACEITAR")').click({ timeout: 3000 }); } catch {}
  await page.waitForTimeout(500);
  const form = page.locator('.elementor-element[data-id="520a235"]').first();
  await form.scrollIntoViewIfNeeded();
  await form.locator('input[type="email"]').fill(email);
  if (uf) {
    await form.locator('select').selectOption({ value: uf });
  }
  await form.locator('button[type="submit"]').click();
  return form;
}

test.describe('RD Station Form Action', () => {

  test('1. Submit normal — success message visivel', async ({ browser }) => {
    const ctx  = await browser.newContext({ viewport: { width: 1440, height: 900 }, ignoreHTTPSErrors: true });
    const page = await ctx.newPage();

    const email = 'playwright-ok-' + Date.now() + '@bit-bpo.com';
    const form  = await submitForm(page, email, 'PA');

    await expect(form.locator('.elementor-message-success')).toBeVisible({ timeout: 12000 });
    await form.screenshot({ path: path.join(screenshotsDir, '1-submit-success.png') });
    await ctx.close();
  });

  test('2. Graceful — sem RDSTATION_API_KEY: form ainda da success', async ({ browser }) => {
    // Remover temporariamente a constant do wp-config
    execFileSync('docker', [
      'exec', '-u', 'root', CONTAINER,
      'sed', '-i', '', "/define.*'RDSTATION_API_KEY'/d", '/var/www/html/wp-config.php',
    ]);

    const ctx  = await browser.newContext({ viewport: { width: 1440, height: 900 }, ignoreHTTPSErrors: true });
    const page = await ctx.newPage();

    const email = 'playwright-noconfig-' + Date.now() + '@bit-bpo.com';
    const form  = await submitForm(page, email, 'PA');

    await expect(form.locator('.elementor-message-success')).toBeVisible({ timeout: 12000 });
    const lastLine = getLogLastLine();
    expect(lastLine).toContain('[WARN]');
    expect(lastLine).toContain('RDSTATION_API_KEY');

    await ctx.close();

    // Restaurar define no wp-config
    wpEval(''); // warm-up — garante que container responde
    execFileSync('docker', [
      'exec', '-u', 'root', CONTAINER,
      'sh', '-c',
      "grep -q 'RDSTATION_API_KEY' /var/www/html/wp-config.php || " +
      "echo \"define( 'RDSTATION_API_KEY', getenv( 'RDSTATION_API_KEY' ) );\" >> /var/www/html/wp-config.php",
    ]);
  });

  test('3. Token invalido — [ERROR] no log, form ainda da success', async ({ browser }) => {
    // Injetar token invalido (sem shell expansion — valor literal seguro)
    execFileSync('docker', [
      'exec', '-u', 'root', CONTAINER,
      'sh', '-c',
      "grep -q 'RDSTATION_API_KEY' /var/www/html/wp-config.php && " +
      "sed -i '' 's/define.*RDSTATION_API_KEY.*/define(\"RDSTATION_API_KEY\", \"token-invalido-para-teste\");/' /var/www/html/wp-config.php || " +
      "echo \"define( 'RDSTATION_API_KEY', 'token-invalido-para-teste' );\" >> /var/www/html/wp-config.php",
    ]);

    const ctx  = await browser.newContext({ viewport: { width: 1440, height: 900 }, ignoreHTTPSErrors: true });
    const page = await ctx.newPage();

    const email = 'playwright-badtoken-' + Date.now() + '@bit-bpo.com';
    const form  = await submitForm(page, email, 'PA');

    await expect(form.locator('.elementor-message-success')).toBeVisible({ timeout: 12000 });
    const lastLine = getLogLastLine();
    expect(lastLine).toContain('[ERROR]');
    expect(lastLine).toContain('401');

    await ctx.close();

    // Restaurar token real via getenv
    execFileSync('docker', [
      'exec', '-u', 'root', CONTAINER,
      'sh', '-c',
      "sed -i '' '/define.*RDSTATION_API_KEY/d' /var/www/html/wp-config.php && " +
      "grep -q 'RDSTATION_API_KEY' /var/www/html/wp-config.php || " +
      "echo \"define( 'RDSTATION_API_KEY', getenv( 'RDSTATION_API_KEY' ) );\" >> /var/www/html/wp-config.php",
    ]);
  });

  test('4. Email invalido — sanitize_email rejeita, form ainda da success', async ({ browser }) => {
    const ctx  = await browser.newContext({ viewport: { width: 1440, height: 900 }, ignoreHTTPSErrors: true });
    const page = await ctx.newPage();

    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1000);
    try { await page.locator('button:has-text("ACEITAR")').click({ timeout: 3000 }); } catch {}
    await page.waitForTimeout(500);

    const form = page.locator('.elementor-element[data-id="520a235"]').first();
    await form.scrollIntoViewIfNeeded();

    // Bypass validacao client-side do Elementor Pro via evaluate
    await page.evaluate(() => {
      const input = document.querySelector('.elementor-element[data-id="520a235"] input[type="email"]');
      if (input) {
        const nativeSetter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
        nativeSetter.call(input, 'nao-e-um-email-valido');
        input.dispatchEvent(new Event('input', { bubbles: true }));
      }
    });
    await form.locator('select').selectOption({ value: 'PA' });
    await form.locator('button[type="submit"]').click();

    // Form ainda da success — run() retorna cedo sem chamar RD, sem quebrar o form
    await expect(form.locator('.elementor-message-success')).toBeVisible({ timeout: 12000 });
    await ctx.close();
  });

  test('5. Blog 2 multisite /cultura/ — form funciona se existir', async ({ browser }) => {
    const ctx  = await browser.newContext({ viewport: { width: 1440, height: 900 }, ignoreHTTPSErrors: true });
    const page = await ctx.newPage();

    await page.goto('/cultura/', { waitUntil: 'domcontentloaded' });
    const formExists = await page.locator('.elementor-element[data-id="520a235"]').count();

    if (formExists > 0) {
      const email = 'playwright-blog2-' + Date.now() + '@bit-bpo.com';
      const form  = await submitForm(page, email, 'PA');
      await expect(form.locator('.elementor-message-success')).toBeVisible({ timeout: 12000 });
    } else {
      test.skip(true, 'Widget 520a235 nao presente em /cultura/ — skip');
    }

    await ctx.close();
  });

});
```

- [ ] **Step 2: Rodar os 5 testes**

```bash
docker exec -u root concertacao-dev-wordpress sh -c '
  grep -q "BIT_RDSTATION_DEBUG" /var/www/html/wp-config.php || \
    echo "define( '"'"'BIT_RDSTATION_DEBUG'"'"', true );" >> /var/www/html/wp-config.php
'
cd /Users/dcambria/scripts/testes/concertacao
npx playwright test tests/09-rdstation-submit.spec.js --reporter=list --timeout=30000
```

Expected: 4 passed, 1 skipped (se /cultura/ nao tiver o form) OU 5 passed.

- [ ] **Step 3: Desligar BIT_RDSTATION_DEBUG apos testes**

```bash
docker exec -u root concertacao-dev-wordpress \
  sed -i '' "/define.*'BIT_RDSTATION_DEBUG'/d" /var/www/html/wp-config.php
```

- [ ] **Step 4: Commit do spec no repo de testes**

```bash
cd /Users/dcambria/scripts/testes/concertacao
git add tests/09-rdstation-submit.spec.js
git commit -m "test(rdstation): 5 cenarios Playwright — success, graceful, token invalido, email invalido, blog2"
cd /Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao/.claude/worktrees/feat-footer-form-unified-part1
```


---

## Task 13: Copia canonica + gate /smoke + CLAUDE.md

> 3 atividades de finalizacao agrupadas.

**Files:**
- Create (server-tools): `docker-dev/common/mu-plugins/bit-elementor-form-rdstation.php`
- Create (server-tools): `docker-dev/common/mu-plugins/bit-elementor-form-rdstation/class-form-action.php`
- Modify: `CLAUDE.md` do site
- Modify (ou create): gate no `/smoke`

- [ ] **Step 1: Copia canonica pro server-tools**

```bash
mkdir -p /Users/dcambria/scripts/server-tools/v2/docker-dev/common/mu-plugins/bit-elementor-form-rdstation/

cp wordpress/wp-content/mu-plugins/bit-elementor-form-rdstation.php \
   /Users/dcambria/scripts/server-tools/v2/docker-dev/common/mu-plugins/

cp wordpress/wp-content/mu-plugins/bit-elementor-form-rdstation/class-form-action.php \
   /Users/dcambria/scripts/server-tools/v2/docker-dev/common/mu-plugins/bit-elementor-form-rdstation/

# Verificar MD5 identico (macOS)
md5 -q wordpress/wp-content/mu-plugins/bit-elementor-form-rdstation.php
md5 -q /Users/dcambria/scripts/server-tools/v2/docker-dev/common/mu-plugins/bit-elementor-form-rdstation.php
```

Expected: 2x MD5 identicos.

- [ ] **Step 2: Atualizar CLAUDE.md do site**

No arquivo `CLAUDE.md` do worktree, na tabela "mu-plugins especificos deste site", adicionar linha:

```markdown
| `bit-elementor-form-rdstation.php` | Form Action `bit_rdstation` — envia leads do form do rodape para RD Station via RDSTATION_API_KEY. Graceful: API falha nao quebra o submit. Log em `logs/bit-rdstation/` (fora do webroot). |
```

- [ ] **Step 3: Adicionar gate /smoke para a Form Action**

Localizar o arquivo do gate /smoke e adicionar validacao:

```bash
# Localizar o smoke script
find /Users/dcambria/scripts/testes/concertacao -name "*.sh" -o -name "*.js" | \
  xargs grep -l "smoke\|gate" 2>/dev/null | head -5
```

Adicionar gate (adaptar para o formato existente no projeto):

```javascript
// Gate RD Station Action
test('Gate 33 — bit_rdstation REGISTERED + RDSTATION_API_KEY defined', async () => {
  const result = wpEval([
    '$registrar = \\ElementorPro\\Plugin::instance()',
    '  ->modules_manager->get_modules("forms")->get_actions_registrar();',
    '$actions = $registrar->get();',
    'echo isset($actions["bit_rdstation"]) ? "REGISTERED" : "MISSING";',
    'echo " | KEY=" . (defined("RDSTATION_API_KEY") && RDSTATION_API_KEY ? "DEFINED" : "UNDEFINED");',
  ].join(''));
  expect(result).toContain('REGISTERED');
  expect(result).toContain('KEY=DEFINED');
});
```

- [ ] **Step 4: Commit final no worktree concertacao**

```bash
git add wordpress/wp-content/mu-plugins/bit-elementor-form-rdstation.php \
        wordpress/wp-content/mu-plugins/bit-elementor-form-rdstation/ \
        CLAUDE.md
git commit -m "feat(rdstation): copia canonica + CLAUDE.md atualizado + gate /smoke

- Copia canonica em docker-dev/common/mu-plugins/ (MD5 verificado)
- CLAUDE.md: nova entry em 'mu-plugins especificos deste site'
- Gate /smoke #33: verifica bit_rdstation REGISTERED + RDSTATION_API_KEY DEFINED"
```

- [ ] **Step 5: Commit copia canonica no server-tools**

```bash
cd /Users/dcambria/scripts/server-tools/v2
git add docker-dev/common/mu-plugins/bit-elementor-form-rdstation.php \
        docker-dev/common/mu-plugins/bit-elementor-form-rdstation/
git commit -m "feat(rdstation): copia canonica do mu-plugin bit-elementor-form-rdstation v1.0.0

Sincronizado com sites/concertacao/wordpress/wp-content/mu-plugins/. MD5 verificado."
cd /Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao/.claude/worktrees/feat-footer-form-unified-part1
```

---

## Task 14: Push + PR

**Files:** Apenas git/GitHub

- [ ] **Step 1: Verificar estado final**

```bash
git log --oneline -10
git status --short
```

Expected: working tree clean + sequencia de commits das Tasks 5-13.

- [ ] **Step 2: Push da branch**

```bash
git push -u origin feat-rdstation-integration-part2
```

- [ ] **Step 3: Abrir PR**

```bash
gh pr create \
  --title "feat(rdstation): Form Action RD Station Marketing — integracao Parte 2" \
  --body "$(cat <<'PREOF'
## Summary

Parte 2 — integracao RD Station via Form Action customizada do Elementor Pro Forms.
Se Parte 1 ainda nao mergeada, este PR depende de feat-footer-form-unified-part1.

### Mudancas

**Env vars (.env raiz LINKED_ENV — PRE-REQUISITO HML):**
- RDSTATION_API_KEY_{DEV,HML,PROD} adicionados via env-writer-helper.sh
- Tokens antigos removidos do .env do site
- Segue padrao SMTP_PASSWORD_* e GTM_CONTAINER_ID_*; evita bug feedback_smtp_constants_missing_prod

**docker-compose.yml:**
- RDSTATION_API_KEY: ${RDSTATION_API_KEY_DEV} no bloco environment: do servico wordpress

**bootstrap.sh (server-tools):**
- Bloco idempotente (grep -q guard) injeta define() no wp-config
- Segue o padrao do bloco SMTP existente

**Mu-plugin bit-elementor-form-rdstation v1.0.0:**
- Form Action bit_rdstation estendendo Action_Base
- Controles: conversion_identifier, email field, UF field, tags
- run() POSTa para https://api.rd.services/platform/conversions?api_key=RDSTATION_API_KEY
- Graceful: API falhando NUNCA quebra o submit
- UF validada contra lista de siglas BR
- legal_bases.status=declined por default ate checkbox LGPD implementado
- on_export() retorna $element (nao []) — nao destroi widget no export
- Log em WP_CONTENT_DIR/../logs/bit-rdstation/ (fora do webroot — LGPD)
- Copia canonica em docker-dev/common/mu-plugins/ (MD5 verificado)

**Script scripts/rdstation-bootstrap-fields.php:**
- Cria cf_uf/cf_consent_source/cf_consent_timestamp via API (idempotente)
- Exige OAuth2 Bearer (api_key nao aceito nesse endpoint)

**Playwright tests/09-rdstation-submit.spec.js (5 cenarios):**
- Submit normal → success message
- Graceful sem RDSTATION_API_KEY → form da success + log [WARN]
- Token invalido → form da success + log [ERROR] com 401
- Email invalido → form da success + log [WARN]
- Blog 2 multisite /cultura/ (skip se form nao presente)

### Nota historica — tokens com nomes invertidos

O token que o painel RD chama de "identificador publico" (RDSTATION_PUBLIC_TOKEN no .env antigo)
e o que a API aceita como api_key server-side. Canonizado como RDSTATION_API_KEY.

### LGPD

legal_bases.status=declined por enquanto. Proxima entrega adiciona acceptance field.
RD cria o lead mas nao envia marketing com declined.

### TODOs registrados (fora desta entrega)

- Performance: wp_schedule_single_event async se saturacao FPM detectada (custo: max 8s sincronico)
- LGPD acceptance field no form
- api_key em QS pode aparecer em access logs nginx/ALB (risco baixo)

### Rollback Playbook

1. Deswirear a action do form (cirurgico — mu-plugin fica no lugar):

```bash
ssh concertacaoamazonia.com.br-prod-sa "sudo -u www-data \
  wp --path=/var/www/concertacaoamazonia.com.br \
     --url='https://concertacaoamazonia.com.br/' eval '
\$raw = get_post_meta(72234, \"_elementor_data\", true);
\$data = json_decode(\$raw, true);
// find widget 520a235, remover bit_rdstation de submit_actions
// update com wp_slash(wp_json_encode(...))
'"
```

2. Invalidar CF cirurgicamente: std cache-flush --prod --post-id=72234
3. Monitorar log: tail -f /var/www/concertacaoamazonia.com.br/logs/bit-rdstation/YYYY-MM-DD.log

### Test plan

- [ ] DEV: std up + confirmar RDSTATION_API_KEY defined (WP-CLI eval)
- [ ] DEV: smoke curl direto a API → HTTP 200 + {"event_uuid":"..."}
- [ ] DEV: editor Elementor mostra "RD Station (BIT)" no dropdown form 520a235
- [ ] DEV: Playwright spec 09 — 4/5 cenarios PASS
- [ ] DEV: log em logs/bit-rdstation/ (FORA de uploads/)
- [ ] DEV: log NAO acessivel via HTTP (nginx 404/403)
- [ ] DEV: graceful — form da success sem token
- [ ] DEV: constants persistem apos std restart
- [ ] DEV: MD5 canonico identico em common/mu-plugins/
- [ ] HML: RDSTATION_API_KEY_HML presente no .env raiz
- [ ] HML: deploy phase3, repetir smoke + Playwright contra green
- [ ] PROD: deploy, validar lead chega no painel RD
- [ ] PROD: gate /smoke #33 valida REGISTERED + KEY=DEFINED

PREOF
)"
```

Expected: URL do PR retornada.


---

## Validacao Final Consolidada

Antes de mergear:

- [ ] **Env vars raiz**: `grep RDSTATION_API_KEY_ .env.concertacaoamazonia.com.br.sa` mostra 3 linhas (DEV/HML/PROD)
- [ ] **docker-compose**: `grep RDSTATION docker-compose.yml` mostra entry no servico wordpress
- [ ] **Bootstrap**: `grep RDSTATION bootstrap.sh` mostra bloco com guard idempotente
- [ ] **Sintaxe PHP**: 2 arquivos mu-plugin + script = `No syntax errors detected`
- [ ] **Action registrada**: `->get()["bit_rdstation"]` presente
- [ ] **on_export() correto**: retorna `$element`, nao `[]`
- [ ] **Controles no editor**: "RD Station (BIT)" no dropdown de actions
- [ ] **Submit funcional**: Playwright spec 09 — 4+ PASS
- [ ] **Log gerado**: arquivo em `logs/bit-rdstation/` (FORA de uploads/)
- [ ] **Log nao publico**: HTTP 404/403 ao acessar `https://cambrasmax.local:8484/logs/bit-rdstation/`
- [ ] **Graceful**: sem token → form da success + log [WARN]
- [ ] **Token invalido**: form da success + log [ERROR] com 401
- [ ] **UF invalida**: label "Regiao" nao e enviada (log [WARN] "valor invalido")
- [ ] **Constants persistem**: apos `std restart`, `RDSTATION_API_KEY` ainda `DEFINED`
- [ ] **MD5 canonico**: mu-plugin em `common/mu-plugins/` = `wordpress/wp-content/mu-plugins/`
- [ ] **CLAUDE.md**: entry do mu-plugin na tabela
- [ ] **Gate /smoke**: gate 33 adicionado
- [ ] **PR aberto**: concertacao worktree + server-tools (separado)

---

## Rollback de Emergencia Pos-Deploy Prod

```bash
# 1. Deswirear a action do form (rollback cirurgico — mu-plugin fica no lugar)
ssh concertacaoamazonia.com.br-prod-sa "sudo -u www-data \
  wp --path=/var/www/concertacaoamazonia.com.br \
     --url='https://concertacaoamazonia.com.br/' eval '
\$raw = get_post_meta(72234, \"_elementor_data\", true);
\$data = json_decode(\$raw, true);
// find_path(72234, 520a235) → remover \"bit_rdstation\" de submit_actions → update com wp_slash
'"

# 2. Invalidar CF cirurgicamente
std cache-flush --prod --post-id=72234

# 3. Monitorar logs por 5min
ssh concertacaoamazonia.com.br-prod-sa \
  "tail -f /var/www/concertacaoamazonia.com.br/logs/bit-rdstation/$(date +%Y-%m-%d).log"
```

---

## Diff v1 para v2: O que Mudou

### Tasks adicionadas

| Task | Descricao | Motivacao |
|---|---|---|
| Task 1 (nova) | Migrar tokens para `.env` raiz com `_DEV/_HML/_PROD` | BLOCKER D — `feedback_smtp_constants_missing_prod` |
| Task 2 (nova) | Adicionar env vars ao `docker-compose.yml` | BLOCKER A — `getenv()` retornava "" |
| Task 3 (era Task 1) | wp-config constants com **guard idempotente** | BLOCKER C — `cat >>` duplicava defines |
| Task 13 step 2 | Atualizar CLAUDE.md | IMPORTANT D — mu-plugin novo sem entry |
| Task 13 step 3 | Gate `/smoke` novo (gate 33) | IMPORTANT D — falha silenciosa em prod |
| Secao Rollback | Playbook explicito | IMPORTANT E — ausente na v1 |

### Tasks renumeradas

| v1 | v2 | Motivo |
|---|---|---|
| Task 1 (wp-config) | Task 3 | Insercao de Tasks 1 e 2 antes |
| Task 2 (smoke curl) | Task 4 | Renumeracao |
| Task 3 (esqueleto) | Task 5 | Renumeracao |
| Task 4 (registrar action) | Task 6 | Renumeracao |
| Task 5 (settings section) | Task 7 | Renumeracao |
| Task 6 (run + log) | Task 8 | Renumeracao + mudancas maiores |
| Task 7 (wire-up) | Task 9 | Renumeracao |
| Task 8 (submit test) | Task 10 | Renumeracao + log path novo |
| Task 9 (bootstrap fields) | Task 11 | Renumeracao |
| Task 10 (canonica + PR) | Tasks 12+13+14 | Split em 3 tasks focadas |

### BLOCKERs resolvidos por agente

| Agente | BLOCKER | Resolucao |
|---|---|---|
| A | `RDSTATION_PRIVATE_TOKEN` retorna 401 | Renomeado para `RDSTATION_API_KEY` (e o PUBLIC_TOKEN); comentario historico no docblock |
| A | `getenv()` retorna "" (var nao no compose) | Task 2: adicionar ao `environment:` do docker-compose.yml |
| B | `on_export()` retorna `[]` destroi widget | Task 6: retornar `$element` com `unset()` opcional |
| B | `->get_actions()` pode nao existir | Task 6 Step 4: usar `->get()` |
| C | Log em `uploads/` = LGPD violation + S3 publico | Task 8: `WP_CONTENT_DIR/../logs/bit-rdstation/` |
| C | `cat >>` wp-config nao idempotente | Task 3: `grep -q` guard |
| D | Tokens sem `_DEV/_HML/_PROD` | Task 1: migracao completa para `.env` raiz |
| E | Sem sandbox RD documentado | Task 4 Step 1: convencao `@bit-bpo.com` + cleanup manual |
| E | Cobertura testes ~25% | Task 12: 5 cenarios (token invalido, email invalido, graceful, blog 2) |

### IMPORTANT issues resolvidos

| # | Issue | Resolucao |
|---|---|---|
| 1 | `legal_bases=granted` sem consent = LGPD falsa | Task 8: default `declined` + TODO comment |
| 2 | UF pode vir como label "Regiao" | Task 8: validacao contra lista de siglas BR |
| 3 | `@file_put_contents` suprime erros | Task 8: verificacao explicita + `error_log` fallback |
| 4 | Branch pode incluir commits Parte 1 | Task 0 Step 2: verificacao explicita |
| 5 | CLAUDE.md sem entry do mu-plugin | Task 13 Step 2 |
| 6 | Gate /smoke faltando | Task 13 Step 3 |
| 7 | Rollback playbook ausente | Secao dedicada no plano e no PR body |
| 8 | Runbook pos-deploy ausente | Test plan do PR body cobre DEV→HML→PROD |
| 9 | Performance 8s sincronico | TODO comment no codigo + nota no PR |
| 10 | api_key em QS nos access logs | Nota no PR body (risco baixo, endereçado futuramente) |
