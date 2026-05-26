# RD Station Integration (Parte 2) — Implementation Plan v3

> **Para agentes:** SUB-SKILL OBRIGATÓRIA: Use superpowers:subagent-driven-development (recomendado) ou superpowers:executing-plans para implementar este plano task-a-task. Steps usam checkbox (`- [ ]`) para tracking.

> **v3 — fixes do Ciclo 1 v2 aplicados (2026-05-22).**
> Endereça 3 BLOCKERs (B1–B3) e 11 ISSUES (I1–I11) encontrados pela rodada de revisão do v2.
> v2 (1842 linhas) mantido como histórico em `2026-05-22-rdstation-integration-parte2-v2.md`.

---

## Changelog v2 → v3 (one-liner por fix)

| Fix | Onde | Descrição |
|---|---|---|
| **B1** | Tasks 5, 8, 10 + var nova | Log path movido de `WP_CONTENT_DIR/../logs/` (servido pelo nginx — leak HTTP 200 confirmado) para **`/var/log/bit-rdstation/`** (verdadeiramente fora do webroot). Escolha justificada abaixo. |
| **B2** | Task 1 Step 3 | `env-writer-helper.sh FILE KEY VALUE` substituído por sintaxe real `env-writer-helper.sh set --file=FILE --var=KEY --value=VALUE`. |
| **B3** | Tasks 10 Step 5, 12 cenários 2 e 3 | Todos `sed -i ''` rodando DENTRO de `docker exec` trocados por `sed -i ""` (GNU sed). Tasks 1 Step 5 (que roda no host macOS) preservadas. |
| **I1** | Task 13 Step 3 | Gate `33` → **Gate `39`** (gates 33–38 já existem em `.claude/commands/smoke.md`). |
| **I2** | Task 14 Step 3 | `gh pr create` agora explicita `--base feat-footer-form-unified-part1` (Part 1 ainda não mergeada). |
| **I3** | Task 13 Step 3 | Path destino do gate especificado: `.claude/commands/smoke.md` (não `tests/01-pages-smoke.spec.js` — esse é por-página; gates customizados ficam em snippets dedicados no `smoke.md`). |
| **I4** | Task 3 Step 1 + Step 2 | Bootstrap.sh NÃO tem bloco SMTP (validado empiricamente). Nova função `setup_rdstation_constants()` chamada de `main()` após `setup_redis_cache()`. |
| **I5** | Task 3 Step 3 | Heredoc com `'"'"'WPCFG'"'"'` substituído por **arquivo temporário + `docker cp`** (auditável e robusto). |
| **I6** | Task 9 Step 1 → Step 2 | Step 1 agora EXPORTA bash vars `EMAIL_FIELD_ID` / `REGION_FIELD_ID`; Step 2 consome essas vars em vez de hardcode. |
| **I7** | Task 7 Step 3 | "Abrir editor Elementor" substituído por `wp eval` que verifica via reflection que `Action_Base` foi registrada (`bit_rdstation` no registrar). |
| **I8** | Rollback de Emergência | Pseudocódigo PHP substituído por script real que espelha o walker do Task 9 e remove `bit_rdstation` de `submit_actions`. |
| **I9** | Task 6 Step 2 + Task 8 | `use function BIT\ElementorFormRDStation\log;` adicionado nas classes que chamam `log()` (evita fallback para `log()` global do PHP). |
| **I10** | Seção nova "Limpeza de Leads de Teste" | Subseção no final documentando cleanup manual no painel RD via filtro de email `@bit-bpo.com`. |
| **I11** | Task 4 Step 5 (novo) | GET subsequente em `/platform/contacts/email:...?api_key=` valida que `cf_uf` chegou no painel (não basta POST 200). |

> **Nits do Agente B (já no v2, mantidos):** `on_export($element)` retorna `$element`; `->get()` em vez de `->get_actions()`; namespace `BIT\ElementorFormRDStation`.

---

## Justificativa B1 — Log fora do webroot (escolha A vs B)

**Validação empírica que motivou:**
```bash
docker exec concertacao-dev-wordpress mkdir -p /var/www/html/wp-content/../logs/bit-rdstation/
echo "TESTE" > /var/www/html/wp-content/../logs/bit-rdstation/test.log
curl -sIk "https://cambrasmax.local:8484/logs/bit-rdstation/test.log"
# Resultado: HTTP/2 200  ← LGPD leak
```

`WP_CONTENT_DIR/../logs/` = `/var/www/html/logs/` que é SERVIDO pelo nginx (nginx `root /var/www/html;`). O comentário "fora do webroot" no v2 era falso.

**Opção A (escolhida):** `/var/log/bit-rdstation/YYYY-MM-DD.log`
- Vantagens: fisicamente fora do webroot; alinhado com `/var/log/nginx`, `/var/log/php-fpm` já existentes; impossível de servir via HTTP por design.
- Custo: bootstrap.sh precisa criar `/var/log/bit-rdstation/` com owner `www-data`; em prod EC2 o web user pode ser diferente (`www-data` ou outro) — usar `chown` baseado em `WP_OWNER` env ou detectar via `stat`.
- Persistência entre rebuilds: `/var/log/` é volume Docker padrão (sobrevive `down`/`up`); se for `docker compose down -v` o log se perde, mas é log operacional, não DB.

**Opção B (rejeitada):** Manter dentro de wp-content + `deny all` no nginx-base.
- Rejeitada porque exige edit em `docker-dev/common/nginx-base/` (afeta TODOS os sites do server-tools, não só Concertação); deny all não impede vazamento via outras rotas (S3 sync, backup tarball, debug bar); mais frágil arquiteturalmente.

**Decisão:** Opção A. Log em `/var/log/bit-rdstation/YYYY-MM-DD.log`, criado no bootstrap.sh com owner correto, alinhado com `/var/log/nginx` e `/var/log/php-fpm`.

---

**Goal:** Form Action customizada `bit_rdstation` que envia submits do Elementor Pro Forms para a API REST do RD Station Marketing (`POST /platform/conversions` via API Key), com graceful degradation (form nunca quebra se RD falhar) e logging seguro em `/var/log/bit-rdstation/`.

**Architecture:** Mu-plugin `bit-elementor-form-rdstation.php` registra Form Action estendendo `\ElementorPro\Modules\Forms\Classes\Action_Base`. No `run()`, monta payload JSON com email + cf_uf + tags e POSTa via `wp_remote_post` (timeout=8). API Key vem de `RDSTATION_API_KEY` (alias de `RDSTATION_API_KEY_DEV/HML/PROD` do `.env` raiz) definida em `wp-config.php` via bootstrap.sh. Falhas são apenas logadas em `/var/log/bit-rdstation/YYYY-MM-DD.log` (verdadeiramente fora do webroot — LGPD), NUNCA chamam `add_error_message()` (graceful).

**Tech Stack:** PHP 8.3 (mu-plugin), Elementor Pro 3.35.1 Form Action API, RD Station Marketing API REST (`/platform/conversions` via API Key), WP-CLI (validação), curl (smoke test endpoint).

**Spec:** `docs/superpowers/specs/2026-05-19-formulario-rodape-rdstation-design.md` (Parte 2).

**Escopo desta Parte 2:**
- ✅ Env vars no `.env` raiz LINKED_ENV com sufixo `_DEV/_HML/_PROD` (Task 1)
- ✅ `docker-compose.yml` com env var no serviço wordpress (Task 2)
- ✅ Mu-plugin `bit-elementor-form-rdstation` v1.0.0
- ✅ Constants idempotentes em `wp-config.php` via bootstrap.sh — **função nova** `setup_rdstation_constants()` (Task 3)
- ✅ Log em `/var/log/bit-rdstation/` — fora do webroot por design (Task 8)
- ✅ Script `scripts/rdstation-bootstrap-fields.php` (one-shot, cria custom fields)
- ✅ Wire-up via WP-CLI walker discovery (Task 9 — Step 1 exporta IDs reais)
- ✅ Logging com `BIT_RDSTATION_DEBUG` opt-in
- ✅ Gate `/smoke` novo (Gate 39) (Task 13)
- ✅ `CLAUDE.md` atualizado com entry do novo mu-plugin
- ❌ LGPD checkbox de consentimento (TBD próximo passo)
- ❌ Settings page admin (padrão BIT: constants em wp-config)

---

## Nota sobre Tokens RD Station

Os tokens têm nomes semanticamente invertidos:

| Nome no `.env` | Nome RD Station | Função real | Quem usa |
|---|---|---|---|
| `RDSTATION_PUBLIC_TOKEN` | "Identificador público" | **API Key server-side** que `/platform/conversions?api_key=X` aceita | mu-plugin (server-side) |
| `RDSTATION_PRIVATE_TOKEN` | "Token privado" | UUID de tracking **client-side** (RD Tracker JS) | NÃO usado nesta entrega |

Empiricamente validado: `PUBLIC_TOKEN` → HTTP 200; `PRIVATE_TOKEN` → HTTP 401.

**Decisão:** renomear internamente para `RDSTATION_API_KEY_*` no `.env` raiz.

---

## File Structure

| Path | Responsabilidade |
|---|---|
| `wordpress/wp-content/mu-plugins/bit-elementor-form-rdstation.php` | Form Action `bit_rdstation`: register + função `log()` |
| `wordpress/wp-content/mu-plugins/bit-elementor-form-rdstation/class-form-action.php` | Classe `Form_Action` separada |
| `docker-dev/common/mu-plugins/bit-elementor-form-rdstation.php` | Cópia canônica (regra `sites/CLAUDE.md`) |
| `docker-dev/common/mu-plugins/bit-elementor-form-rdstation/class-form-action.php` | Cópia canônica da classe |
| `scripts/rdstation-bootstrap-fields.php` | One-shot idempotente: cria `cf_uf`/`cf_consent_source`/`cf_consent_timestamp` |
| `docker-compose.yml` | Env var `RDSTATION_API_KEY` no serviço `wordpress` |
| `docker-dev/common/scripts/bootstrap.sh` | Nova função `setup_rdstation_constants()` + criação de `/var/log/bit-rdstation/` |
| `~/scripts/testes/concertacao/tests/09-rdstation-submit.spec.js` | Playwright spec: 5 cenários |
| `.claude/commands/smoke.md` | Snippet de Gate 39 (RD Station Action registrada + KEY DEFINED) |

**Logs em runtime (volume Docker, não versionados):**
- DEV: `/var/log/bit-rdstation/YYYY-MM-DD.log`
- PROD EC2: `/var/log/bit-rdstation/YYYY-MM-DD.log` (criado pelo deploy script equivalente)

---

## Pré-condições

- DEV concertacao subido: `std up`
- Container WP rodando: `concertacao-dev-wordpress`
- Branch: confirmar que está sobre `feat-footer-form-unified-part1` (Part 1 não mergeada ainda)
- Spec Parte 2 acessível
- Tokens `RDSTATION_PUBLIC_TOKEN`/`PRIVATE_TOKEN` no `.env` do site (serão movidos)

---

## Task 0: Validar pré-condições + estado da branch

**Files:** Read-only

- [ ] **Step 1: Worktree clean + branch correta**

```bash
cd /Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao/.claude/worktrees/feat-footer-form-unified-part1
git branch --show-current
git status --short
git log --oneline -5
```

Expected:
- branch: `feat-footer-form-unified-part1` ou `feat-rdstation-integration-part2`
- status: vazio ou arquivos da Parte 1 já commitados

- [ ] **Step 2: Verificar se Parte 1 já foi mergeada**

```bash
git log --oneline main..HEAD | wc -l
git log --oneline origin/main..HEAD 2>/dev/null | wc -l || true
```

Se há commits divergentes contendo "feat(footer-form)" ou "feat(responsive)": Part 1 NÃO foi mergeada. **Isso é o caso atual.** Tarefa 14 deve abrir PR contra `feat-footer-form-unified-part1`, não contra `main`.

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

> **PRÉ-REQUISITO para deploy em HML/PROD.** Evita exatamente o bug documentado em [[feedback_smtp_constants_missing_prod.md]].

**Files:**
- Modify (via env-writer-helper.sh): `/Users/dcambria/scripts/server-tools/v2/.env.concertacaoamazonia.com.br.sa`
- Modify (sed no host macOS): `docker-dev/sites/concertacao/.env`

- [ ] **Step 1: Ler tokens atuais do `.env` do site**

```bash
grep -E "^RDSTATION_(PUBLIC|PRIVATE)_TOKEN=" \
  /Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao/.env
```

Anotar os 2 valores. Lembrar: `RDSTATION_PUBLIC_TOKEN` é o que a API aceita.

- [ ] **Step 2: Verificar se já existem no `.env` raiz**

```bash
grep -E "RDSTATION" \
  /Users/dcambria/scripts/server-tools/v2/.env.concertacaoamazonia.com.br.sa || echo "NAO_EXISTE"
```

Se já existirem: pular Step 3.

- [ ] **Step 3: Adicionar ao `.env` raiz via env-writer-helper.sh** *(Fix B2 — sintaxe correta)*

Sintaxe canonical do helper:

```bash
# Validado em /Users/dcambria/scripts/server-tools/v2/helpers/env-writer-helper.sh linha 17:
#   ./env-writer-helper.sh set --file=.env --var=KEY --value=VAL
#
# v2 (ERRADO) usava: env-writer-helper.sh FILE KEY VALUE  ← falhava silenciosamente

VALOR_PUBLIC="<colar valor do Step 1>"

/Users/dcambria/scripts/server-tools/v2/helpers/env-writer-helper.sh set \
  --file=/Users/dcambria/scripts/server-tools/v2/.env.concertacaoamazonia.com.br.sa \
  --var=RDSTATION_API_KEY_DEV \
  --value="$VALOR_PUBLIC"

/Users/dcambria/scripts/server-tools/v2/helpers/env-writer-helper.sh set \
  --file=/Users/dcambria/scripts/server-tools/v2/.env.concertacaoamazonia.com.br.sa \
  --var=RDSTATION_API_KEY_HML \
  --value="$VALOR_PUBLIC"

/Users/dcambria/scripts/server-tools/v2/helpers/env-writer-helper.sh set \
  --file=/Users/dcambria/scripts/server-tools/v2/.env.concertacaoamazonia.com.br.sa \
  --var=RDSTATION_API_KEY_PROD \
  --value="$VALOR_PUBLIC"
```

> Os 3 valores são idênticos hoje (mesma conta RD); sufixo permite override futuro de sandbox por ambiente.

- [ ] **Step 4: Verificar escrita**

```bash
grep -E "RDSTATION_API_KEY_(DEV|HML|PROD)" \
  /Users/dcambria/scripts/server-tools/v2/.env.concertacaoamazonia.com.br.sa
```

Expected: 3 linhas com os valores corretos.

- [ ] **Step 5: Remover as 2 linhas antigas do `.env` do site** *(sed no host macOS — `-i ''` correto)*

```bash
# Confirmar o que está lá
grep -n "RDSTATION" \
  /Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao/.env

# Remover via sed BSD (macOS host — exige '' após -i)
sed -i '' '/^RDSTATION_PUBLIC_TOKEN=/d' \
  /Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao/.env
sed -i '' '/^RDSTATION_PRIVATE_TOKEN=/d' \
  /Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao/.env

grep "RDSTATION" \
  /Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao/.env || echo "REMOVIDO ok"
```

Expected: `REMOVIDO ok`

- [ ] **Step 6: Commit no server-tools**

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

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
cd /Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao/.claude/worktrees/feat-footer-form-unified-part1
```

---

## Task 2: Adicionar env vars ao docker-compose.yml

**Files:**
- Modify: `docker-compose.yml`

- [ ] **Step 1: Localizar o bloco `environment:` do serviço wordpress**

```bash
grep -n "RDSTATION\|SMTP_\|environment:" \
  /Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao/docker-compose.yml | head -30
```

- [ ] **Step 2: Adicionar var RDSTATION_API_KEY no bloco environment do wordpress**

Editar `docker-compose.yml` adicionando ao `environment:` do serviço `wordpress`:

```yaml
# RD Station API Key (server-side) — lida do .env raiz LINKED_ENV
RDSTATION_API_KEY: ${RDSTATION_API_KEY_DEV}
```

- [ ] **Step 3: Validar env var chega no container**

```bash
/Users/dcambria/scripts/server-tools/v2/docker-dev/common/bin/docker-dev.sh restart

docker ps --filter "name=concertacao-dev-wordpress" --format "{{.Status}}"

docker exec concertacao-dev-wordpress env | grep RDSTATION
```

Expected: `RDSTATION_API_KEY=<valor>`

- [ ] **Step 4: Commit**

```bash
git add docker-compose.yml
git commit -m "feat(rdstation): expor RDSTATION_API_KEY no ambiente do container wordpress

Sem isso getenv() dentro do PHP retorna string vazia.
Padrao: docker-compose.yml mapeia VAR_DEV do .env raiz para VAR no container.

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>"
```

---

## Task 3: wp-config.php — constants idempotentes via bootstrap.sh + criação de /var/log

> **Fix I4:** o v2 alegava "após o bloco SMTP", mas **NÃO existe bloco SMTP em `bootstrap.sh`** (validado: `grep -n SMTP bootstrap.sh` retorna 0 resultados — wp-config é criado via heredoc inline em `setup_wordpress_core()` linhas 147–208).
>
> **Solução v3:** criar nova função `setup_rdstation_constants()` (espelhando o padrão de `setup_redis_cache()` linhas 289–329) e chamá-la em `main()` após `setup_redis_cache`. Função também cria `/var/log/bit-rdstation/` com owner correto (Fix B1).
>
> **Fix I5:** o heredoc aninhado com `'"'"'WPCFG'"'"'` do v2 Step 3 é substituído por arquivo temporário + `docker cp` — robusto e auditável.

**Files:**
- Modify: `docker-dev/common/scripts/bootstrap.sh` (no server-tools)
- Create one-shot: `/tmp/rdstation-block.php` (host) → `docker cp` para container

- [ ] **Step 1: Ler a estrutura atual do bootstrap.sh para identificar âncora**

```bash
grep -n "^setup_\|^main\|^}" /Users/dcambria/scripts/server-tools/v2/docker-dev/common/scripts/bootstrap.sh | head -30
```

Identificar:
- Linha onde termina `setup_redis_cache()` (~329)
- Linha onde `main()` chama `setup_redis_cache` (~578)

- [ ] **Step 2: Adicionar nova função `setup_rdstation_constants()` ao bootstrap.sh**

Inserir após `setup_redis_cache()` (antes de `remove_default_plugins`):

```bash
# Configurar RD Station API Key — define constante em wp-config.php
# e cria /var/log/bit-rdstation/ com owner web user (Fix B1 — log fora do webroot)
setup_rdstation_constants() {
    log_info "Configurando RD Station constants..."

    cd "$WP_ROOT"

    # 2.1 — Criar /var/log/bit-rdstation/ com owner www-data
    if [[ ! -d /var/log/bit-rdstation ]]; then
        mkdir -p /var/log/bit-rdstation
        chown www-data:www-data /var/log/bit-rdstation
        chmod 750 /var/log/bit-rdstation
        log_info "Diretorio /var/log/bit-rdstation/ criado (owner=www-data, mode=750)"
    fi

    # 2.2 — Injetar define() no wp-config com guard idempotente
    # NOTA: api_key do painel RD = "Identificador publico" (PUBLIC_TOKEN)
    # Renomeado para RDSTATION_API_KEY por clareza semantica.
    if ! grep -q "RDSTATION_API_KEY" "$WP_ROOT/wp-config.php"; then
        log_info "Injetando RDSTATION_API_KEY em wp-config.php..."
        cat >> "$WP_ROOT/wp-config.php" << 'WPCFG'

// === RD Station (BIT) ===
// RDSTATION_API_KEY = token server-side aceito pela API /platform/conversions?api_key=X
// (no painel RD chama-se "Identificador publico" — nome historicamente invertido)
if ( getenv( 'RDSTATION_API_KEY' ) ) {
    define( 'RDSTATION_API_KEY', getenv( 'RDSTATION_API_KEY' ) );
}
WPCFG
        log_success "RDSTATION_API_KEY adicionado em wp-config.php"
    else
        log_info "RDSTATION_API_KEY ja presente em wp-config.php (skip)"
    fi
}
```

Adicionar a chamada em `main()` após `setup_redis_cache`:

```bash
    setup_redis_cache
    setup_rdstation_constants    # <-- ADICIONAR ESTA LINHA
    setup_essential_plugins
```

- [ ] **Step 3: Aplicar o bloco no wp-config.php DEV atual via arquivo temporário** *(Fix I5)*

Em vez do heredoc com triple-quoting frágil do v2, usar `docker cp`:

```bash
# 3.1 — Criar arquivo temporário no host (sem aninhamento de heredoc)
cat > /tmp/rdstation-block.php <<'PHP'

// === RD Station (BIT) ===
// RDSTATION_API_KEY = token server-side aceito pela API /platform/conversions?api_key=X
if ( getenv( 'RDSTATION_API_KEY' ) ) {
    define( 'RDSTATION_API_KEY', getenv( 'RDSTATION_API_KEY' ) );
}
PHP

# 3.2 — Verificar idempotência (guard) antes de copiar
if docker exec concertacao-dev-wordpress grep -q "RDSTATION_API_KEY" /var/www/html/wp-config.php; then
    echo "Ja presente — skip"
else
    docker cp /tmp/rdstation-block.php concertacao-dev-wordpress:/tmp/rdstation-block.php
    docker exec concertacao-dev-wordpress sh -c \
      'cat /tmp/rdstation-block.php >> /var/www/html/wp-config.php && rm /tmp/rdstation-block.php'
    echo "Adicionado"
fi

# 3.3 — Limpar o tmp do host
rm /tmp/rdstation-block.php
```

- [ ] **Step 4: Criar `/var/log/bit-rdstation/` no container atual (DEV em execução)**

```bash
docker exec -u root concertacao-dev-wordpress sh -c '
  mkdir -p /var/log/bit-rdstation && \
  chown www-data:www-data /var/log/bit-rdstation && \
  chmod 750 /var/log/bit-rdstation && \
  ls -la /var/log/bit-rdstation
'
```

Expected: diretório existe, owner `www-data:www-data`, mode `750`.

- [ ] **Step 5: Validar constante via WP-CLI**

```bash
docker exec -u www-data concertacao-dev-wordpress \
  wp --url="https://cambrasmax.local:8484/" eval \
  'echo "RDSTATION_API_KEY: " . (defined("RDSTATION_API_KEY") ? "DEFINED (len=".strlen(RDSTATION_API_KEY).")" : "UNDEFINED") . PHP_EOL;'
```

Expected: `RDSTATION_API_KEY: DEFINED (len=XX)` onde XX > 0.

- [ ] **Step 6: Verificar idempotência**

```bash
docker exec concertacao-dev-wordpress \
  grep -c "define.*RDSTATION_API_KEY" /var/www/html/wp-config.php
```

Expected: `1`

- [ ] **Step 7: Commit do bootstrap.sh no server-tools**

```bash
cd /Users/dcambria/scripts/server-tools/v2
git add docker-dev/common/scripts/bootstrap.sh
git commit -m "$(cat <<'EOF'
feat(rdstation): nova funcao setup_rdstation_constants() no bootstrap

- Cria /var/log/bit-rdstation/ (owner www-data, mode 750) — log fora do webroot
- Injeta RDSTATION_API_KEY em wp-config.php com guard grep -q (idempotente)
- Chamada de main() apos setup_redis_cache
- Padrao espelha setup_redis_cache() existente

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
cd /Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao/.claude/worktrees/feat-footer-form-unified-part1
```

---

## Task 4: Smoke test do endpoint RD via curl

> **Fix I11:** novo Step 5 valida via GET subsequente que `cf_uf` realmente persistiu no painel (não basta POST 200).

**Files:** (sem código novo)

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

Expected: `HTTP_CODE: 200` + JSON com `event_uuid`.

Se `HTTP_CODE: 401`: token errado. Voltar Task 1 Step 1.

- [ ] **Step 2: Validar formato de resposta de sucesso**

```bash
curl -sS -X POST "https://api.rd.services/platform/conversions?api_key=$TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"event_type":"CONVERSION","event_family":"CDP","payload":{"conversion_identifier":"_smoke-test-bit","email":"smoke-test-bit-rdstation@bit-bpo.com"}}' \
  | python3 -m json.tool
```

Expected: `{"event_uuid": "<uuid>"}`.

- [ ] **Step 3: Validar formato de erro 400**

```bash
curl -sS -w "\nHTTP_CODE: %{http_code}\n" \
  -X POST "https://api.rd.services/platform/conversions?api_key=$TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"event_type":"CONVERSION","event_family":"CDP","payload":{"conversion_identifier":"","email":"not-valid"}}' \
  | python3 -m json.tool
```

Expected: `HTTP_CODE: 400` + JSON `{"errors":[{"error_type",...}]}`.

- [ ] **Step 4: Enviar com cf_uf**

```bash
EMAIL_TESTE="smoke-test-bit-rdstation-cfuf-$(date +%s)@bit-bpo.com"
curl -sS -w "\nHTTP_CODE: %{http_code}\n" \
  -X POST "https://api.rd.services/platform/conversions?api_key=$TOKEN" \
  -H "Content-Type: application/json" \
  -d "{\"event_type\":\"CONVERSION\",\"event_family\":\"CDP\",\"payload\":{\"conversion_identifier\":\"_smoke-test-bit\",\"email\":\"$EMAIL_TESTE\",\"cf_uf\":\"AC\"}}"
echo
echo "EMAIL_TESTE=$EMAIL_TESTE"
```

Expected: `HTTP_CODE: 200`. Anotar `$EMAIL_TESTE` para o Step 5.

- [ ] **Step 5: Validar via GET subsequente que cf_uf persistiu** *(Fix I11 — novo)*

```bash
# Pequena espera para propagação no painel RD (~5s)
sleep 5

curl -sS "https://api.rd.services/platform/contacts/email:${EMAIL_TESTE}?api_key=$TOKEN" \
  | python3 -m json.tool | tee /tmp/rd-contact.json

# Validar cf_uf
UF_PAINEL=$(jq -r '.cf_uf // empty' /tmp/rd-contact.json)
if [ "$UF_PAINEL" = "AC" ]; then
  echo "OK: cf_uf=AC chegou no painel RD"
else
  echo "ATENCAO: cf_uf esperado=AC, recebido=$UF_PAINEL"
  echo "Se vazio: campo cf_uf nao foi pre-cadastrado no painel RD (Task 11)"
fi
rm /tmp/rd-contact.json
```

Expected: `cf_uf=AC chegou no painel RD`.

Se `ATENCAO: vazio`: rodar Task 11 ou criar manualmente no painel RD (Configurações → Campos Personalizados). RD aceita silenciosamente com 200, mas SÓ persiste valor se o campo existir no painel.

> **Sandbox RD:** Esses curls enviam leads reais. Convenção: emails `*@bit-bpo.com` com prefixo `smoke-test-bit-` / `playwright-` para facilitar cleanup. Ver "Limpeza de Leads de Teste" no final.

Sem commit neste task.

---

## Task 5: Esqueleto do mu-plugin

**Files:**
- Create: `wordpress/wp-content/mu-plugins/bit-elementor-form-rdstation.php`

- [ ] **Step 1: Criar arquivo com header canônico + guard** *(Fix B1: log path atualizado)*

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
 *              que o painel RD Station chama de "Identificador publico" (PUBLIC_TOKEN).
 *              O nome foi invertido semanticamente — aqui usamos RDSTATION_API_KEY.
 *
 *              Resposta sucesso: {"event_uuid": "<uuid>"}.
 *              Erros: HTTP 400, {"errors":[{"error_type","error_message","path"}]}.
 *
 *              Log: /var/log/bit-rdstation/YYYY-MM-DD.log (FORA do webroot por design).
 *              Diretorio criado pelo bootstrap.sh (owner=www-data, mode=750).
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
const LOG_DIR               = '/var/log/bit-rdstation';

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

- [ ] **Step 3: Confirmar mu-plugin é carregado**

```bash
docker exec -u www-data concertacao-dev-wordpress \
  wp --url="https://cambrasmax.local:8484/" eval \
  'echo defined("BIT\\ElementorFormRDStation\\VERSION") ? "LOADED" : "NOT_LOADED";'
```

Expected: `LOADED`

- [ ] **Step 4: Commit**

```bash
git add wordpress/wp-content/mu-plugins/bit-elementor-form-rdstation.php
git commit -m "feat(rdstation): esqueleto do mu-plugin com header BIT + guard Elementor Pro

LOG_DIR=/var/log/bit-rdstation (fora do webroot por design, criado pelo bootstrap).

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>"
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

- [ ] **Step 2: Criar o diretório + classe stub** *(Fix I9 — `use function` explícito)*

```bash
mkdir -p /Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao/.claude/worktrees/feat-footer-form-unified-part1/wordpress/wp-content/mu-plugins/bit-elementor-form-rdstation
```

Conteúdo de `wordpress/wp-content/mu-plugins/bit-elementor-form-rdstation/class-form-action.php`:

```php
<?php
namespace BIT\ElementorFormRDStation;

defined( 'ABSPATH' ) || exit;

// IMPORTANTE: import explicito do log() do namespace BIT — sem isso o PHP cai
// no log() global (logaritmo natural) que aceita 1 argumento e nao 3.
use function BIT\ElementorFormRDStation\log;

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

- [ ] **Step 5: Commit**

```bash
git add wordpress/wp-content/mu-plugins/bit-elementor-form-rdstation.php \
        wordpress/wp-content/mu-plugins/bit-elementor-form-rdstation/
git commit -m "feat(rdstation): registra Form Action bit_rdstation no Elementor Pro

use function BIT\\ElementorFormRDStation\\log adicionado para evitar fallback
ao log() global do PHP (logaritmo natural). on_export() retorna \$element.

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>"
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

- [ ] **Step 3: Validar Action está registrada via WP-CLI (sem abrir editor)** *(Fix I7 — headless)*

Substituí "abrir editor Elementor" por verificação por reflection:

```bash
docker exec -u www-data concertacao-dev-wordpress \
  wp --url="https://cambrasmax.local:8484/" eval '
$registrar = \ElementorPro\Plugin::instance()->modules_manager->get_modules("forms")->get_actions_registrar();
$actions = $registrar->get();

if ( ! isset( $actions["bit_rdstation"] ) ) {
    echo "FAIL: bit_rdstation nao registrada\n";
    exit;
}

$action = $actions["bit_rdstation"];
$ref = new \ReflectionClass( $action );
echo "REGISTERED class=" . $ref->getName() . "\n";
echo "name=" . $action->get_name() . " label=" . $action->get_label() . "\n";

// Verificar que metodos requeridos existem
foreach ( ["get_name","get_label","register_settings_section","run","on_export"] as $m ) {
    echo $m . ": " . ( $ref->hasMethod($m) ? "OK" : "MISSING" ) . "\n";
}
'
```

Expected:
```
REGISTERED class=BIT\ElementorFormRDStation\Form_Action
name=bit_rdstation label=RD Station (BIT)
get_name: OK
get_label: OK
register_settings_section: OK
run: OK
on_export: OK
```

> Validação manual no editor (`/wp-admin/post.php?post=72234&action=elementor`) é OPCIONAL e fica como QA humano antes do PR. NÃO é gate executável.

- [ ] **Step 4: Commit**

```bash
git add wordpress/wp-content/mu-plugins/bit-elementor-form-rdstation/class-form-action.php
git commit -m "feat(rdstation): controles do painel Elementor (conversion_id, email field, uf field, tags)

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>"
```

---

## Task 8: run() + log() — POST para RD Station com graceful degradation

> **Mudanças v3:**
> - LOG_DIR = `/var/log/bit-rdstation/` (constante do mu-plugin, Fix B1)
> - `use function BIT\ElementorFormRDStation\log;` na classe (Fix I9)

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
    $raw_fields = $record->get( 'fields' );
    $email_raw  = $raw_fields[ $email_field ]['value'] ?? '';
    $uf_raw     = $uf_field ? ( $raw_fields[ $uf_field ]['value'] ?? '' ) : '';

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

    // UF: validar contra lista de siglas BR
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

    // legal_bases: "declined" por default — sem checkbox LGPD ainda.
    // TODO: quando checkbox LGPD for implementado, ler $form_settings['bit_rd_consent_field']
    $payload['legal_bases'] = [
        [ 'category' => 'communications', 'type' => 'consent', 'status' => 'declined' ],
    ];

    $body = [
        'event_type'   => 'CONVERSION',
        'event_family' => 'CDP',
        'payload'      => $payload,
    ];

    // 5. POST — api_key na query string
    // TODO performance: migrar para wp_schedule_single_event se saturacao FPM
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
        log( 'info', "OK $code", [ 'email' => $email, 'cid' => $conversion_id ] );
    } else {
        log( 'error', "RD respondeu $code", [
            'email' => $email,
            'body'  => substr( $resp_body, 0, 500 ),
        ] );
    }
}
```

- [ ] **Step 2: Criar a função `log()` no mu-plugin principal — escrita em /var/log/bit-rdstation/** *(Fix B1)*

Adicionar ao final de `wordpress/wp-content/mu-plugins/bit-elementor-form-rdstation.php`:

```php
/**
 * Log estruturado em /var/log/bit-rdstation/ (FORA do webroot por design).
 *
 * Diretorio criado pelo bootstrap.sh (setup_rdstation_constants) com:
 *   - owner: www-data
 *   - mode:  750
 *
 * Em prod EC2: equivalente criado pelo script de deploy (systemd-tmpfiles ou bootstrap-equivalente).
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

    if ( ! is_dir( LOG_DIR ) ) {
        // Fallback: tentar criar (caso o bootstrap nao tenha rodado, ex: deploy parcial)
        if ( ! @mkdir( LOG_DIR, 0750, true ) && ! is_dir( LOG_DIR ) ) {
            error_log( sprintf( '[BIT RDStation] LOG_DIR_MISSING: %s — criar via bootstrap.sh', LOG_DIR ) );
            return;
        }
    }

    if ( ! is_writable( LOG_DIR ) ) {
        error_log( sprintf( '[BIT RDStation] LOG_DIR_NOT_WRITABLE: %s — verificar owner/permissoes', LOG_DIR ) );
        return;
    }

    $log_file = LOG_DIR . '/' . gmdate( 'Y-m-d' ) . '.log';
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

- [ ] **Step 3: Confirmar diretório de log existe** *(criado no Task 3 Step 4)*

```bash
docker exec concertacao-dev-wordpress ls -la /var/log/bit-rdstation/
```

Expected: diretório existe com owner `www-data:www-data`.

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
git commit -m "feat(rdstation): run() POSTa pra RD via wp_remote_post + log em /var/log/bit-rdstation/

- legal_bases.status=declined por default (sem checkbox LGPD ainda)
- Log em /var/log/bit-rdstation/ (FORA do webroot por design)
- UF validada contra lista de siglas BR
- file_put_contents sem @ (erro explicito via error_log fallback)
- api_key via add_query_arg
- Fallback mkdir() em LOG_DIR caso bootstrap nao tenha rodado

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>"
```

---

## Task 9: Wire-up no template footer 72234 (PREVIEW form)

> **Fix I6:** Step 1 agora EXPORTA bash vars com IDs reais; Step 2 consome-as em vez de hardcode.

**Files:**
- Modify (via WP-CLI eval): `_elementor_data` do post 72234, widget Form `520a235`

- [ ] **Step 1: Descobrir custom_id atual dos fields da PREVIEW + EXPORTAR como bash vars** *(Fix I6)*

```bash
# 1.1 — Inspecionar fields e capturar IDs reais
INSPECT=$(docker exec -u www-data concertacao-dev-wordpress \
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

$email_id  = "";
$region_id = "";
foreach ( $w["settings"]["form_fields"] as $f ) {
    $type = $f["field_type"] ?? "";
    $cid  = $f["custom_id"]  ?? "";
    echo "FIELD: $cid (type=$type)\n";
    if ( $type === "email" && ! $email_id )   { $email_id  = $cid; }
    if ( $type === "select" && ! $region_id ) { $region_id = $cid; }
}
echo "EMAIL_FIELD_ID=$email_id\n";
echo "REGION_FIELD_ID=$region_id\n";
echo "submit_actions: " . wp_json_encode( $w["settings"]["submit_actions"] ?? [] ) . "\n";
')

echo "$INSPECT"

# 1.2 — Exportar vars bash
EMAIL_FIELD_ID=$(echo "$INSPECT" | grep '^EMAIL_FIELD_ID=' | cut -d= -f2)
REGION_FIELD_ID=$(echo "$INSPECT" | grep '^REGION_FIELD_ID=' | cut -d= -f2)

# 1.3 — Validar
if [ -z "$EMAIL_FIELD_ID" ]; then
    echo "ERRO: nenhum campo type=email encontrado em widget 520a235"
    exit 1
fi
if [ -z "$REGION_FIELD_ID" ]; then
    echo "AVISO: nenhum campo type=select encontrado — bit_rd_uf_field ficara vazio (sem cf_uf)"
fi

echo "EXPORT: EMAIL_FIELD_ID=$EMAIL_FIELD_ID | REGION_FIELD_ID=$REGION_FIELD_ID"
export EMAIL_FIELD_ID REGION_FIELD_ID
```

Esperado em DEV concertacao: `EMAIL_FIELD_ID=form_email_desk`, `REGION_FIELD_ID=form_regiao_desk`. Se forem outros, o Step 2 usará os valores reais automaticamente.

- [ ] **Step 2: Adicionar action `bit_rdstation` + settings via WP-CLI usando os IDs descobertos**

```bash
docker exec -u www-data \
  -e EMAIL_FIELD_ID="$EMAIL_FIELD_ID" \
  -e REGION_FIELD_ID="$REGION_FIELD_ID" \
  concertacao-dev-wordpress \
  wp --url="https://cambrasmax.local:8484/" eval '
$email_field  = getenv( "EMAIL_FIELD_ID" );
$region_field = getenv( "REGION_FIELD_ID" );

if ( ! $email_field ) {
    echo "ERRO: EMAIL_FIELD_ID vazio — abortar\n";
    return;
}

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
$s["bit_rd_email_field"]           = $email_field;
$s["bit_rd_uf_field"]              = $region_field;
$s["bit_rd_tags"]                  = "newsletter,concertacao-amazonia,footer-form";
$encoded = wp_slash( wp_json_encode( $data ) );
delete_post_meta( 72234, "_elementor_data" );
add_post_meta( 72234, "_elementor_data", $encoded, true );
clean_post_cache( 72234 );
\Elementor\Plugin::$instance->files_manager->clear_cache();
echo "submit_actions: " . wp_json_encode( $s["submit_actions"] ) . "\n";
echo "email_field: $email_field | uf_field: $region_field\n";
echo "ok\n";
'
```

Expected: `submit_actions: ["email","bit_rdstation"]` + `ok`.

- [ ] **Step 3: Confirmar via WP-CLI (sem abrir editor)**

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
$s = $w["settings"];
echo "submit_actions: " . wp_json_encode( $s["submit_actions"] ) . "\n";
echo "bit_rd_email_field: " . ( $s["bit_rd_email_field"] ?? "MISSING" ) . "\n";
echo "bit_rd_uf_field: " . ( $s["bit_rd_uf_field"] ?? "MISSING" ) . "\n";
'
```

Expected: campos preenchidos corretamente.

- [ ] **Step 4: Sem commit** (mudança no DB)

---

## Task 10: Submit funcional + validação do log

> **Fix B3:** `sed -i ''` rodando dentro de `docker exec` (Linux/GNU sed) trocado por `sed -i ""`.

**Files:** Read-only

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

Via Playwright:

```bash
cd /Users/dcambria/scripts/testes/concertacao
npx playwright test tests/09-rdstation-submit.spec.js --reporter=line
```

Via curl (requer `bit-smoke-recaptcha-bypass` ativo):

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

- [ ] **Step 3: Validar log gerado em /var/log/bit-rdstation/** *(Fix B1)*

```bash
docker exec -u www-data concertacao-dev-wordpress sh -c '
  LOG=/var/log/bit-rdstation/$(date +%Y-%m-%d).log
  [ -f "$LOG" ] && cat "$LOG" || echo "LOG_NAO_EXISTE: $LOG"
'
```

Expected: linha com `[INFO] OK 200 {"email":"test-...","cid":"newsletter-footer-concertacao"}`.

- [ ] **Step 4: Verificar que log NÃO é acessível via HTTP** *(Fix B1 — validação reforçada)*

```bash
# /var/log/ NUNCA esta em nginx root — esperado 404
curl -sk "https://cambrasmax.local:8484/var/log/bit-rdstation/" -o /dev/null -w "%{http_code}\n"

# Tentativa de path traversal (paranoia)
curl -sk "https://cambrasmax.local:8484/../var/log/bit-rdstation/$(date +%Y-%m-%d).log" -o /dev/null -w "%{http_code}\n"

# Confirmar que NAO existe leak via /logs/ (como acontecia no v2)
curl -sk "https://cambrasmax.local:8484/logs/bit-rdstation/" -o /dev/null -w "%{http_code}\n"
```

Expected: `404` ou `403` em todos (nunca `200`).

- [ ] **Step 5: Desativar BIT_RDSTATION_DEBUG** *(Fix B3 — sed -i "" para GNU sed dentro do container)*

```bash
# DENTRO do container Linux: GNU sed → 'sed -i "" PATTERN' (sem argumento de sufixo)
# Note as DUAS aspas duplas vazias seguidas (interpretadas pelo shell, GNU sed nao recebe argumento)
# Alternativa equivalente: sed -i PATTERN (sem sufixo) — GNU sed aceita os 2 formatos
docker exec -u root concertacao-dev-wordpress \
  sed -i "/define.*'BIT_RDSTATION_DEBUG'/d" /var/www/html/wp-config.php

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

Conteúdo de `scripts/rdstation-bootstrap-fields.php`:

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

- [ ] **Step 3: Executar ou criar manualmente**

**Opção A (recomendada):** Criar manualmente em `https://app.rdstation.com.br/` → Configurações → Campos Personalizados (3 campos tipo Texto: `cf_uf`, `cf_consent_source`, `cf_consent_timestamp`).

**Opção B (via script):**
```bash
RDSTATION_BEARER=<token> docker exec -u www-data concertacao-dev-wordpress \
  wp --url="https://cambrasmax.local:8484/" \
  eval-file /tmp/rdstation-bootstrap-fields.php
```

- [ ] **Step 4: Commit**

```bash
git add scripts/rdstation-bootstrap-fields.php
git commit -m "feat(rdstation): script bootstrap dos custom fields (cf_uf + consent)

Idempotente: GET /platform/contacts/fields antes de POST.
Exige OAuth2 Bearer Token (api_key nao aceito nesse endpoint).
Alternativa simples: criar manualmente no painel RD.

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>"
```

---

## Task 12: Spec Playwright — 5 cenários de teste

> **Fix B3:** todos `sed -i ''` dentro de `docker exec` trocados por `sed -i ""`.

**Files:**
- Create: `~/scripts/testes/concertacao/tests/09-rdstation-submit.spec.js`

- [ ] **Step 1: Criar spec com 5 cenários**

```javascript
'use strict';

/**
 * 09-rdstation-submit.spec.js
 * Playwright spec: Form Action bit_rdstation — 5 cenarios
 *
 * 1. Submit normal: success message visivel
 * 2. Graceful sem config: RDSTATION_API_KEY nao definido → form ainda da success
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

/** Le ultima linha do log de hoje (em /var/log/bit-rdstation/) — Fix B1 */
function getLogLastLine() {
  try {
    const today = new Date().toISOString().slice(0, 10);
    return execFileSync('docker', [
      'exec', '-u', 'www-data', CONTAINER,
      'sh', '-c',
      'tail -1 /var/log/bit-rdstation/' + today + '.log 2>/dev/null || echo ""',
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
    // Fix B3: sed -i "" (GNU sed dentro de container Linux)
    execFileSync('docker', [
      'exec', '-u', 'root', CONTAINER,
      'sed', '-i', "/define.*'RDSTATION_API_KEY'/d", '/var/www/html/wp-config.php',
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
    wpEval('');
    execFileSync('docker', [
      'exec', '-u', 'root', CONTAINER,
      'sh', '-c',
      "grep -q 'RDSTATION_API_KEY' /var/www/html/wp-config.php || " +
      "echo \"define( 'RDSTATION_API_KEY', getenv( 'RDSTATION_API_KEY' ) );\" >> /var/www/html/wp-config.php",
    ]);
  });

  test('3. Token invalido — [ERROR] no log, form ainda da success', async ({ browser }) => {
    // Fix B3: sed -i (GNU sed sem '' sufixo)
    execFileSync('docker', [
      'exec', '-u', 'root', CONTAINER,
      'sh', '-c',
      "grep -q 'RDSTATION_API_KEY' /var/www/html/wp-config.php && " +
      "sed -i 's/define.*RDSTATION_API_KEY.*/define(\"RDSTATION_API_KEY\", \"token-invalido-para-teste\");/' /var/www/html/wp-config.php || " +
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

    // Restaurar token real via getenv (Fix B3: sed -i sem sufixo)
    execFileSync('docker', [
      'exec', '-u', 'root', CONTAINER,
      'sh', '-c',
      "sed -i '/define.*RDSTATION_API_KEY/d' /var/www/html/wp-config.php && " +
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

    // Bypass validacao client-side via evaluate
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

Expected: 4 passed, 1 skipped OU 5 passed.

- [ ] **Step 3: Desligar BIT_RDSTATION_DEBUG após testes** *(Fix B3: sed -i sem sufixo)*

```bash
docker exec -u root concertacao-dev-wordpress \
  sed -i "/define.*'BIT_RDSTATION_DEBUG'/d" /var/www/html/wp-config.php
```

- [ ] **Step 4: Commit do spec no repo de testes**

```bash
cd /Users/dcambria/scripts/testes/concertacao
git add tests/09-rdstation-submit.spec.js
git commit -m "test(rdstation): 5 cenarios Playwright — success, graceful, token invalido, email invalido, blog2

Log path atualizado para /var/log/bit-rdstation/ (Fix B1 v3).
sed -i sem sufixo (GNU sed dentro de container Linux) (Fix B3 v3).

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>"
cd /Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao/.claude/worktrees/feat-footer-form-unified-part1
```

---

## Task 13: Cópia canônica + gate /smoke (Gate 39) + CLAUDE.md

> **Fix I1:** Gate 33 → **Gate 39** (gates 33–38 já em uso).
> **Fix I3:** path destino especificado.

**Files:**
- Create (server-tools): `docker-dev/common/mu-plugins/bit-elementor-form-rdstation.php`
- Create (server-tools): `docker-dev/common/mu-plugins/bit-elementor-form-rdstation/class-form-action.php`
- Modify: `CLAUDE.md` do site
- Modify: `.claude/commands/smoke.md` (snippet de Gate 39)

- [ ] **Step 1: Cópia canônica pro server-tools**

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

Expected: 2x MD5 idênticos.

- [ ] **Step 2: Atualizar CLAUDE.md do site**

Em `CLAUDE.md` do worktree, na tabela "mu-plugins específicos deste site", adicionar:

```markdown
| `bit-elementor-form-rdstation.php` | Form Action `bit_rdstation` — envia leads do form do rodape para RD Station via RDSTATION_API_KEY. Graceful: API falha nao quebra o submit. Log em `/var/log/bit-rdstation/` (fora do webroot por design — criado pelo bootstrap.sh). |
```

- [ ] **Step 3: Adicionar Gate 39 ao `.claude/commands/smoke.md`** *(Fix I1 + I3)*

Path destino confirmado:
`/Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao/.claude/commands/smoke.md`

Gates existentes validados via grep (`gates 33–38` já presentes); **39 é o próximo livre**.

```bash
# Verificar antes de editar
grep -n "Gate 3[0-9]\|Gate 4[0-9]" \
  /Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao/.claude/commands/smoke.md \
  | tail -10
```

Adicionar snippet ao final da seção "Snippets de Gates Customizados" (procurar pelo último `### Snippet — Gate 38` e inserir depois):

````markdown
### Snippet — Gate 39 (RD Station Form Action registrada + KEY definido)

Valida que o mu-plugin `bit-elementor-form-rdstation` está ativo e a constant
`RDSTATION_API_KEY` foi injetada pelo bootstrap. Adicionado em 2026-05-22 pela
Parte 2 da integração RD Station.

```bash
# Em DEV
docker exec -u www-data concertacao-dev-wordpress \
  wp --url="https://cambrasmax.local:8484/" eval '
$registrar = \ElementorPro\Plugin::instance()->modules_manager->get_modules("forms")->get_actions_registrar();
$actions = $registrar->get();
$registered = isset( $actions["bit_rdstation"] ) ? "REGISTERED" : "MISSING";
$key_status = ( defined( "RDSTATION_API_KEY" ) && RDSTATION_API_KEY ) ? "DEFINED" : "UNDEFINED";
echo "Gate 39 — bit_rdstation=$registered | KEY=$key_status\n";
if ( $registered !== "REGISTERED" || $key_status !== "DEFINED" ) {
    echo "FAIL\n";
    exit( 1 );
}
echo "PASS\n";
'

# Em PROD (via SSH)
ssh concertacaoamazonia.com.br-prod-sa "sudo -u www-data \
  wp --path=/var/www/concertacaoamazonia.com.br \
     --url='https://concertacaoamazonia.com.br/' eval '
\$registrar = \ElementorPro\Plugin::instance()->modules_manager->get_modules(\"forms\")->get_actions_registrar();
\$actions = \$registrar->get();
\$r = isset( \$actions[\"bit_rdstation\"] ) ? \"REGISTERED\" : \"MISSING\";
\$k = ( defined( \"RDSTATION_API_KEY\" ) && RDSTATION_API_KEY ) ? \"DEFINED\" : \"UNDEFINED\";
echo \"Gate 39 — bit_rdstation=\$r | KEY=\$k\n\";
'"
```

**Critério PASS:** `bit_rdstation=REGISTERED | KEY=DEFINED`.
**FAIL = bloqueia o deploy.** Causas comuns:
- `bit_rdstation=MISSING`: mu-plugin não copiado para `mu-plugins/` em prod, ou Elementor Pro inativo.
- `KEY=UNDEFINED`: `RDSTATION_API_KEY_PROD` não setado no `.env` raiz, OU bloco `setup_rdstation_constants()` do bootstrap não rodou no deploy.
````

- [ ] **Step 4: Commit final no worktree concertacao**

```bash
git add wordpress/wp-content/mu-plugins/bit-elementor-form-rdstation.php \
        wordpress/wp-content/mu-plugins/bit-elementor-form-rdstation/ \
        CLAUDE.md \
        .claude/commands/smoke.md
git commit -m "$(cat <<'EOF'
feat(rdstation): copia canonica + CLAUDE.md + Gate 39 no /smoke

- Copia canonica em docker-dev/common/mu-plugins/ (MD5 verificado)
- CLAUDE.md: nova entry em 'mu-plugins especificos deste site'
- .claude/commands/smoke.md: snippet de Gate 39 (REGISTERED + KEY=DEFINED)
- Gate 33 do v2 colidia com gate jet_download — usado 39 (proximo livre)

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>
EOF
)"
```

- [ ] **Step 5: Commit cópia canônica no server-tools**

```bash
cd /Users/dcambria/scripts/server-tools/v2
git add docker-dev/common/mu-plugins/bit-elementor-form-rdstation.php \
        docker-dev/common/mu-plugins/bit-elementor-form-rdstation/
git commit -m "feat(rdstation): copia canonica do mu-plugin bit-elementor-form-rdstation v1.0.0

Sincronizado com sites/concertacao/wordpress/wp-content/mu-plugins/. MD5 verificado.

Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>"
cd /Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao/.claude/worktrees/feat-footer-form-unified-part1
```

---

## Task 14: Push + PR

> **Fix I2:** `gh pr create` agora com `--base feat-footer-form-unified-part1` (Part 1 não mergeada).

**Files:** Apenas git/GitHub

- [ ] **Step 1: Verificar estado final**

```bash
git log --oneline -10
git status --short
```

Expected: working tree clean.

- [ ] **Step 2: Push da branch**

```bash
git push -u origin feat-rdstation-integration-part2
```

- [ ] **Step 3: Abrir PR contra a branch de Part 1** *(Fix I2)*

> Sem `--base` o PR abriria contra `main`, contendo TODAS as mudanças da Part 1 ainda não mergeada (MR enorme, escopo poluído). `--base feat-footer-form-unified-part1` mantém o PR focado APENAS na Part 2.

```bash
gh pr create \
  --base feat-footer-form-unified-part1 \
  --title "feat(rdstation): Form Action RD Station Marketing — integracao Parte 2" \
  --body "$(cat <<'PREOF'
## Summary

Parte 2 — integracao RD Station via Form Action customizada do Elementor Pro Forms.

**IMPORTANTE:** Este PR e baseado em `feat-footer-form-unified-part1` (Parte 1 ainda nao mergeada).
Apos merge desta branch, mergear Part 1 + Part 2 em sequencia para main.

### Mudancas

**Env vars (.env raiz LINKED_ENV — PRE-REQUISITO HML):**
- RDSTATION_API_KEY_{DEV,HML,PROD} adicionados via env-writer-helper.sh
- Sintaxe correta: `set --file=... --var=... --value=...` (Fix B2 v3)
- Tokens antigos removidos do .env do site

**docker-compose.yml:**
- RDSTATION_API_KEY: ${RDSTATION_API_KEY_DEV} no bloco environment: do servico wordpress

**bootstrap.sh (server-tools) — nova funcao:**
- `setup_rdstation_constants()` espelhando padrao de `setup_redis_cache()`
- Cria `/var/log/bit-rdstation/` (owner=www-data, mode=750) — log fora do webroot
- Injeta define() em wp-config.php com guard grep -q
- Chamada de main() apos setup_redis_cache

**Mu-plugin bit-elementor-form-rdstation v1.0.0:**
- Form Action bit_rdstation estendendo Action_Base
- `use function BIT\\ElementorFormRDStation\\log;` explicito (evita fallback ao log() global)
- Controles: conversion_identifier, email field, UF field, tags
- run() POSTa para https://api.rd.services/platform/conversions?api_key=RDSTATION_API_KEY
- Graceful: API falhando NUNCA quebra o submit
- UF validada contra lista de siglas BR
- legal_bases.status=declined por default ate checkbox LGPD implementado
- on_export() retorna \$element (nao []) — nao destroi widget no export
- Log em **/var/log/bit-rdstation/** (Fix B1 v3 — antes era em wp-content/../logs que e servido pelo nginx)
- Copia canonica em docker-dev/common/mu-plugins/

**Script scripts/rdstation-bootstrap-fields.php:**
- Cria cf_uf/cf_consent_source/cf_consent_timestamp via API (idempotente)
- Exige OAuth2 Bearer (api_key nao aceito nesse endpoint)

**Playwright tests/09-rdstation-submit.spec.js (5 cenarios):**
- Submit normal → success message
- Graceful sem RDSTATION_API_KEY → form da success + log [WARN]
- Token invalido → form da success + log [ERROR] com 401
- Email invalido → form da success + log [WARN]
- Blog 2 multisite /cultura/ (skip se form nao presente)

**Smoke Gate 39 em .claude/commands/smoke.md:**
- Valida `bit_rdstation` REGISTERED + `RDSTATION_API_KEY` DEFINED
- Fix I1 v3: gates 33–38 ja existiam; usado 39 (proximo livre)

### Fixes do Ciclo 1 de revisao v2 endereçados

| ID | Fix |
|---|---|
| B1 | Log path movido para /var/log/bit-rdstation/ (validado: caminho anterior leakava HTTP 200) |
| B2 | env-writer-helper.sh sintaxe `set --file=... --var=... --value=...` |
| B3 | sed -i (sem '' sufixo) em comandos dentro de docker exec — GNU sed |
| I1 | Gate 33 → Gate 39 (colisao com jet_download) |
| I2 | gh pr create --base feat-footer-form-unified-part1 (Part 1 nao mergeada) |
| I3 | Path destino do gate: .claude/commands/smoke.md (nao tests/01-pages-smoke.spec.js) |
| I4 | bootstrap.sh nao tem bloco SMTP — criada nova funcao setup_rdstation_constants() |
| I5 | docker cp + arquivo temporario em vez de heredoc com triple-quoting |
| I6 | Step 1 do Task 9 EXPORTA EMAIL_FIELD_ID / REGION_FIELD_ID em vez de hardcode |
| I7 | Verificacao via WP-CLI eval + Reflection (sem "abrir editor Elementor") |
| I8 | Rollback Playbook usa walker real (espelha Task 9) |
| I9 | `use function BIT\\ElementorFormRDStation\\log;` em classes que chamam log() |
| I10 | Subsecao "Limpeza de Leads de Teste no Painel RD" |
| I11 | Task 4 Step 5: GET /platform/contacts/email:... valida cf_uf persistido |

### Test plan

- [ ] DEV: std up + RDSTATION_API_KEY DEFINED (Task 3 Step 5)
- [ ] DEV: curl smoke API → HTTP 200 + {"event_uuid":"..."}
- [ ] DEV: GET contact valida cf_uf=AC persistiu no painel RD (Task 4 Step 5)
- [ ] DEV: WP-CLI eval mostra bit_rdstation REGISTERED + 5 metodos OK
- [ ] DEV: Playwright spec 09 — 4/5 cenarios PASS
- [ ] DEV: log em /var/log/bit-rdstation/ existe e populado
- [ ] DEV: HTTP /logs/, /var/log/, path traversal — todos 404/403
- [ ] DEV: graceful — form da success sem token
- [ ] DEV: constants persistem apos std restart
- [ ] DEV: MD5 canonico identico em common/mu-plugins/
- [ ] HML: RDSTATION_API_KEY_HML presente no .env raiz
- [ ] HML: /var/log/bit-rdstation/ criado pelo bootstrap (verificar manualmente)
- [ ] HML: deploy phase3, repetir smoke + Playwright contra green
- [ ] HML: Gate 39 do /smoke valida REGISTERED + KEY=DEFINED
- [ ] PROD: deploy, validar lead chega no painel RD
- [ ] PROD: Gate 39 PASS
- [ ] PROD: limpar leads de teste @bit-bpo.com (ver "Limpeza de Leads de Teste")

PREOF
)"
```

Expected: URL do PR retornada.

---

## Limpeza de Leads de Teste no Painel RD *(I10 — novo)*

Durante validação DEV/HML/PROD, dezenas de leads de teste são enviados (convenção: emails `*@bit-bpo.com` com prefixos `smoke-test-bit-*`, `playwright-ok-*`, `playwright-noconfig-*`, `playwright-badtoken-*`, `playwright-blog2-*`). Cleanup pós-validação:

**Passo 1 — Localizar via filtro de email:**

```
https://app.rdstation.com.br/ → Contatos → Filtros
  Filtrar por: email → contém → "@bit-bpo.com"
  Adicionar 2º filtro: email → contém → "smoke-test-bit-" OU "playwright-"
```

**Passo 2 — Selecionar todos os resultados e excluir em lote:**

Painel RD permite seleção múltipla + ação "Excluir contatos". Não há endpoint REST público para delete em lote (a API só permite delete individual via `DELETE /platform/contacts/email:<email>`), por isso o cleanup é manual.

**Alternativa programática (lento — só se >100 leads acumulados):**

```bash
TOKEN_BEARER=<oauth-token-24h>  # api_key NAO funciona neste endpoint
# Listar
curl -sS "https://api.rd.services/platform/contacts?email=@bit-bpo.com" \
  -H "Authorization: Bearer $TOKEN_BEARER" | jq -r '.contacts[].email' > /tmp/leads-cleanup.txt

# Deletar um por um (rate limit: ~100/min)
while read -r email; do
  echo "DELETE $email"
  curl -sS -X DELETE "https://api.rd.services/platform/contacts/email:$email" \
    -H "Authorization: Bearer $TOKEN_BEARER" -o /dev/null -w "%{http_code}\n"
  sleep 0.6
done < /tmp/leads-cleanup.txt
```

**Frequência recomendada:** cleanup mensal ou imediatamente após cada ciclo de validação completo (DEV + HML + PROD).

---

## Validação Final Consolidada

Antes de mergear:

- [ ] **Env vars raiz**: 3 linhas RDSTATION_API_KEY_{DEV,HML,PROD}
- [ ] **docker-compose**: entry RDSTATION_API_KEY no serviço wordpress
- [ ] **Bootstrap**: função `setup_rdstation_constants()` + chamada em main()
- [ ] **/var/log/bit-rdstation/** existe (owner www-data, mode 750)
- [ ] **Sintaxe PHP**: 2 mu-plugin files + script = `No syntax errors detected`
- [ ] **Action registrada via Reflection**: 5 métodos OK (`get_name`, `get_label`, `register_settings_section`, `run`, `on_export`)
- [ ] **on_export() retorna $element** (não `[]`)
- [ ] **`use function BIT\ElementorFormRDStation\log;`** presente em class-form-action.php
- [ ] **Submit funcional**: Playwright spec 09 — 4+ PASS
- [ ] **Log gerado**: arquivo em `/var/log/bit-rdstation/` (FORA do webroot)
- [ ] **Log não público**: HTTP 404/403 em `/logs/`, `/var/log/`, path traversal
- [ ] **Graceful**: sem token → form da success + log `[WARN]`
- [ ] **Token inválido**: form da success + log `[ERROR]` com 401
- [ ] **UF inválida**: label "Regiao" não enviada (log `[WARN]`)
- [ ] **GET subsequente** valida cf_uf no painel (Task 4 Step 5)
- [ ] **Constants persistem após `std restart`**
- [ ] **MD5 canônico** match em `common/mu-plugins/`
- [ ] **CLAUDE.md**: entry do mu-plugin na tabela
- [ ] **Smoke Gate 39** adicionado em `.claude/commands/smoke.md`
- [ ] **PR aberto** com `--base feat-footer-form-unified-part1`

---

## Rollback de Emergência Pós-Deploy Prod *(I8 — código real)*

Espelha o walker do Task 9 para remoção real (não pseudocódigo):

```bash
# 1. Deswirear a action do form (rollback cirúrgico — mu-plugin fica no lugar)
ssh concertacaoamazonia.com.br-prod-sa "sudo -u www-data \
  wp --path=/var/www/concertacaoamazonia.com.br \
     --url='https://concertacaoamazonia.com.br/' eval '
\$raw  = get_post_meta( 72234, \"_elementor_data\", true );
\$data = json_decode( \$raw, true );
function find_path( &\$nodes, \$tid, \$path = [] ) {
    foreach ( \$nodes as \$i => \$n ) {
        \$np = array_merge( \$path, [ \$i ] );
        if ( ( \$n[\"widgetType\"] ?? \"\" ) === \"form\" && ( \$n[\"id\"] ?? \"\" ) === \$tid ) return \$np;
        if ( ! empty( \$n[\"elements\"] ) ) {
            \$r = find_path( \$n[\"elements\"], \$tid, array_merge( \$np, [\"elements\"] ) );
            if ( \$r !== null ) return \$r;
        }
    }
    return null;
}
\$path = find_path( \$data, \"520a235\" );
if ( ! \$path ) { echo \"WIDGET_NOT_FOUND — rollback abortado\n\"; return; }
\$ref = &\$data;
foreach ( \$path as \$k ) { \$ref = &\$ref[\$k]; }
\$s = &\$ref[\"settings\"];
\$actions = \$s[\"submit_actions\"] ?? [];
if ( is_string( \$actions ) ) { \$actions = array_filter( explode( \",\", \$actions ) ); }
\$actions = array_values( array_diff( \$actions, [ \"bit_rdstation\" ] ) );
\$s[\"submit_actions\"] = \$actions;
unset( \$s[\"bit_rd_conversion_identifier\"], \$s[\"bit_rd_email_field\"],
       \$s[\"bit_rd_uf_field\"], \$s[\"bit_rd_tags\"] );
\$encoded = wp_slash( wp_json_encode( \$data ) );
delete_post_meta( 72234, \"_elementor_data\" );
add_post_meta( 72234, \"_elementor_data\", \$encoded, true );
clean_post_cache( 72234 );
\Elementor\Plugin::\$instance->files_manager->clear_cache();
echo \"submit_actions: \" . wp_json_encode( \$actions ) . \"\n\";
echo \"ROLLBACK_OK\n\";
'"

# 2. Invalidar CF cirurgicamente
std cache-flush --prod --post-id=72234

# 3. Validar via Gate 39 (continua REGISTERED, mas form nao chama mais a action)
ssh concertacaoamazonia.com.br-prod-sa "sudo -u www-data \
  wp --path=/var/www/concertacaoamazonia.com.br \
     --url='https://concertacaoamazonia.com.br/' eval '
\$raw = get_post_meta( 72234, \"_elementor_data\", true );
\$data = json_decode( \$raw, true );
function find_form( \$nodes ) {
    foreach ( \$nodes as \$n ) {
        if ( ( \$n[\"widgetType\"] ?? \"\" ) === \"form\" && ( \$n[\"id\"] ?? \"\" ) === \"520a235\" ) return \$n;
        if ( ! empty( \$n[\"elements\"] ) ) { \$r = find_form( \$n[\"elements\"] ); if ( \$r ) return \$r; }
    }
    return null;
}
\$w = find_form( \$data );
echo \"submit_actions atual: \" . wp_json_encode( \$w[\"settings\"][\"submit_actions\"] ?? [] ) . \"\n\";
'"

# 4. Monitorar logs por 5min (nao deve aparecer entradas novas se rollback OK)
ssh concertacaoamazonia.com.br-prod-sa \
  "tail -f /var/log/bit-rdstation/$(date +%Y-%m-%d).log"
```

Se precisar rollback TOTAL (remover mu-plugin):

```bash
ssh concertacaoamazonia.com.br-prod-sa "sudo rm -f \
  /var/www/concertacaoamazonia.com.br/wp-content/mu-plugins/bit-elementor-form-rdstation.php && \
  sudo rm -rf /var/www/concertacaoamazonia.com.br/wp-content/mu-plugins/bit-elementor-form-rdstation/ && \
  sudo systemctl reload php8.3-fpm"
```

---

## Self-Review Pós-Fix

- **Spec coverage:** Todos os 3 BLOCKERs (B1, B2, B3) e 11 IMPORTANT issues (I1–I11) endereçados inline nas tasks correspondentes. Tabela de changelog no topo cross-referencia cada fix com sua localização. Nits do Agente B (já no v2) preservados.
- **Placeholder scan:** Nenhum `// pseudocódigo`, `// find_path(...)`, `// TODO mais tarde` que bloqueie execução. Único TODO mantido é `legal_bases: declined` (escopo deliberadamente fora desta entrega — checkbox LGPD), documentado no PR body.
- **Type consistency:** `LOG_DIR` constante no PHP (não literal repetido); `EMAIL_FIELD_ID`/`REGION_FIELD_ID` exportadas como bash vars no Task 9 e consumidas via `getenv()` no PHP do mesmo task; `RDSTATION_API_KEY` constante única do PHP, alinhada à env var no docker-compose e ao sufixo `_{DEV,HML,PROD}` no `.env` raiz; gates numerados em `.claude/commands/smoke.md` (Gate 39 ≠ Gate 33).
