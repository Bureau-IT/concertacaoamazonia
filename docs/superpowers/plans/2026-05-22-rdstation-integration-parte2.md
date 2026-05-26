# RD Station Integration (Parte 2) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Form Action customizada `bit_rdstation` que envia submits do Elementor Pro Forms para a API REST do RD Station Marketing (`POST /platform/conversions` via API Key), com graceful degradation (form nunca quebra se RD falhar) e logging.

**Architecture:** Mu-plugin `bit-elementor-form-rdstation.php` registra uma Form Action customizada estendendo `\ElementorPro\Modules\Forms\Classes\Action_Base`. No `run()`, monta payload JSON com email + cf_uf + tags + legal_bases e POSTa via `wp_remote_post` (timeout=8). API Key vem de `RDSTATION_PRIVATE_TOKEN` definida em `wp-config.php` (proxied do `.env`). Falhas são apenas logadas em `wp-content/uploads/bit-rdstation-logs/YYYY-MM-DD.log`, NUNCA chamam `add_error_message` (graceful).

**Tech Stack:** PHP 8.3 (mu-plugin), Elementor Pro 3.35.1 Form Action API, RD Station Marketing API REST (`/platform/conversions` via API Key), WP-CLI (validação), curl (smoke test endpoint).

**Spec:** `docs/superpowers/specs/2026-05-19-formulario-rodape-rdstation-design.md` (Parte 2).

**Escopo desta Parte 2:**
- ✅ Mu-plugin `bit-elementor-form-rdstation` v1.0.0
- ✅ Constants `RDSTATION_PRIVATE_TOKEN`/`RDSTATION_PUBLIC_TOKEN` em `wp-config.php` (a partir de `.env`)
- ✅ Script `scripts/rdstation-bootstrap-fields.php` (one-shot, cria custom fields)
- ✅ Wire-up na PREVIEW form do template footer 72234 (mapeamento email/UF)
- ✅ Logging com `BIT_RDSTATION_DEBUG` opt-in
- ❌ LGPD checkbox de consentimento (fora de escopo desta entrega — TBD próximo passo)
- ❌ Settings page admin (padrão BIT: constants em wp-config, sem UI)

**Convenções do projeto** (CLAUDE.md):
- Mu-plugin: header BIT, namespace `BIT\<Module>`, copy canonical em `docker-dev/common/mu-plugins/`
- Toda credencial em `wp-config.php` (não `wp_options`)
- WP-CLI multisite: SEMPRE `--url=` (blog 1 raiz: `cambrasmax.local:8484`)
- `wp_enqueue_*` em mu-plugins: `VERSION . '.' . filemtime()` pra cache bust ([[feedback_muplugin_asset_cache_bust_filemtime]])
- Lessons: [[feedback_smtp_constants_missing_prod.md]] (constants devem entrar no `.env` raiz sufixadas `_DEV/_HML/_PROD`, hoje estão no `.env` do site — adiar correção arquitetural pra próxima etapa)

---

## File Structure

| Path | Responsabilidade |
|---|---|
| `wordpress/wp-content/mu-plugins/bit-elementor-form-rdstation.php` | Form Action `bit_rdstation`: register, settings section, run() com POST RD via wp_remote_post + logging |
| `docker-dev/common/mu-plugins/bit-elementor-form-rdstation.php` | Cópia canônica do mu-plugin (regra `sites/CLAUDE.md`) |
| `scripts/rdstation-bootstrap-fields.php` | One-shot idempotente: GET /platform/contacts/fields → POST se não existir `cf_uf`/`cf_consent_source`/`cf_consent_timestamp` |
| `wp-config.php` (gerado via bootstrap container) | Adicionar `define( 'RDSTATION_PRIVATE_TOKEN', ... )` + `RDSTATION_PUBLIC_TOKEN` lidos de `getenv()` |
| `~/scripts/testes/concertacao/tests/09-rdstation-submit.spec.js` | Playwright spec: submit do form em dev → confirmar log + ausência de error message visual |

**Não modificados:**
- `bit-elementor-form-responsive.php` (Parte 1, não dependência)
- Template footer 72234 (Action é adicionada via UI Elementor pós-deploy)

---

## Pré-condições

- DEV concertacao subido: `std up`
- Container WP rodando: `concertacao-dev-wordpress`
- Branch dedicada: `feat-rdstation-integration-part2` (worktree existente — já criada)
- Spec Parte 2 acessível em `docs/superpowers/specs/2026-05-19-formulario-rodape-rdstation-design.md`
- Tokens `RDSTATION_PRIVATE_TOKEN` e `RDSTATION_PUBLIC_TOKEN` no `.env` do site

---

## Task 0: Validar pré-condições + sync worktree↔main

**Files:**
- Read-only: confirmar setup

- [ ] **Step 1: Worktree clean + branch correta**

Run:
```bash
cd /Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao/.claude/worktrees/feat-footer-form-unified-part1
git branch --show-current
git status --short
```

Expected:
- branch: `feat-rdstation-integration-part2`
- status: vazio (working tree clean)

Se algo diferente, ABORTAR e investigar.

- [ ] **Step 2: Container WP rodando + tokens disponíveis**

Run:
```bash
docker ps --filter "name=concertacao-dev-wordpress" --format "{{.Names}} {{.Status}}"
grep -E "^RDSTATION_(PUBLIC|PRIVATE)_TOKEN=." /Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao/.env | cut -d= -f1
```

Expected:
- container "Up X minutes (healthy)"
- 2 linhas: `RDSTATION_PUBLIC_TOKEN`, `RDSTATION_PRIVATE_TOKEN`

- [ ] **Step 3: Spec acessível**

Run:
```bash
test -f docs/superpowers/specs/2026-05-19-formulario-rodape-rdstation-design.md && echo "SPEC_OK" || echo "SPEC_MISSING"
```

Expected: `SPEC_OK`

Sem commit nesse task.

---

## Task 1: wp-config.php constants RDSTATION_*_TOKEN

**Files:**
- Modify (em container): `/var/www/html/wp-config.php`

**Contexto importante:** o `wp-config.php` no DEV é gerado pelo container bootstrap a cada `std up`. Para essa entrega, vamos adicionar manualmente — em deploy real (HML/PROD) o `docker-dev/common/scripts/bootstrap.sh` precisaria ser atualizado para auto-injetar (Task 9, opcional).

- [ ] **Step 1: Conferir estado atual do wp-config.php**

```bash
docker exec concertacao-dev-wordpress grep -E "RDSTATION|SMTP_PASSWORD" /var/www/html/wp-config.php
```

Expected: só linhas de `SMTP_*`, sem `RDSTATION_*` (esperado — vamos adicionar).

- [ ] **Step 2: Adicionar constants no wp-config.php (container)**

```bash
docker exec -u root concertacao-dev-wordpress sh -c "cat >> /var/www/html/wp-config.php << 'EOF'

// === RD Station (BIT) ===
// Tokens vêm do .env do site via bootstrap (provisionados em 2026-05-19).
// PRIVATE_TOKEN é usado pela Form Action server-side (mu-plugin bit-elementor-form-rdstation).
// PUBLIC_TOKEN é reservado para tracker JS futuro (não usado nesta entrega).
if ( getenv( 'RDSTATION_PRIVATE_TOKEN' ) ) {
    define( 'RDSTATION_PRIVATE_TOKEN', getenv( 'RDSTATION_PRIVATE_TOKEN' ) );
}
if ( getenv( 'RDSTATION_PUBLIC_TOKEN' ) ) {
    define( 'RDSTATION_PUBLIC_TOKEN', getenv( 'RDSTATION_PUBLIC_TOKEN' ) );
}
EOF
"
```

- [ ] **Step 3: Validar via WP-CLI que as constants estão setadas**

```bash
docker exec -u www-data concertacao-dev-wordpress wp --url="https://cambrasmax.local:8484/" eval 'echo "PRIVATE_TOKEN length: " . (defined("RDSTATION_PRIVATE_TOKEN") ? strlen(RDSTATION_PRIVATE_TOKEN) : "UNDEFINED") . PHP_EOL; echo "PUBLIC_TOKEN length: " . (defined("RDSTATION_PUBLIC_TOKEN") ? strlen(RDSTATION_PUBLIC_TOKEN) : "UNDEFINED") . PHP_EOL;'
```

Expected: 2 linhas com `length: <número > 0>`. Nunca `UNDEFINED`.

Se `UNDEFINED`: `getenv()` não está pegando do `.env`. Investigar se o container tem as env vars (`docker exec concertacao-dev-wordpress env | grep RDSTATION`). Se não tiver, reiniciar container (`std restart`) — env só carrega no boot.

- [ ] **Step 4: Sem commit (wp-config.php do dev é gitignored)**

`wp-config.php` no DEV é regenerado pelo bootstrap. Não vai pro git. A persistência permanente vem em Task 9 (atualizar `bootstrap.sh` no server-tools repo, OPCIONAL nesta entrega).

---

## Task 2: Smoke test do endpoint RD via curl (validação real da API)

**Files:**
- (Sem código novo — só validação)

Antes de escrever PHP, validar que o endpoint funciona e o token é válido.

- [ ] **Step 1: Curl direto à API RD com um payload mínimo**

```bash
TOKEN=$(docker exec -u www-data concertacao-dev-wordpress wp --url="https://cambrasmax.local:8484/" eval 'echo defined("RDSTATION_PRIVATE_TOKEN") ? RDSTATION_PRIVATE_TOKEN : "";')

curl -sS -X POST "https://api.rd.services/platform/conversions?api_key=$TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "event_type": "CONVERSION",
    "event_family": "CDP",
    "payload": {
      "conversion_identifier": "_smoke-test-bit",
      "email": "smoke-test@bit-bpo.com"
    }
  }'
echo
```

Expected: HTTP 200 + JSON com `event_type=CONVERSION` (ou similar success).

Se erro 401: token inválido — verificar `.env`.
Se erro 400: payload malformado — investigar.
Se timeout: rede/DNS issue.

**SALVAR a resposta de sucesso real** num arquivo pra referência futura:

```bash
curl -sS -X POST "https://api.rd.services/platform/conversions?api_key=$TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "event_type": "CONVERSION",
    "event_family": "CDP",
    "payload": {
      "conversion_identifier": "_smoke-test-bit",
      "email": "smoke-test@bit-bpo.com"
    }
  }' > /tmp/rdstation-success-response.json

cat /tmp/rdstation-success-response.json
```

Documentar formato no header docblock do mu-plugin (Task 3).

- [ ] **Step 2: Curl com email inválido pra ver formato de erro**

```bash
curl -sS -X POST "https://api.rd.services/platform/conversions?api_key=$TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "event_type": "CONVERSION",
    "event_family": "CDP",
    "payload": {
      "conversion_identifier": "_smoke-test-bit",
      "email": "not-an-email"
    }
  }'
echo
```

Expected: HTTP 400 ou 422 com JSON `errors` array. Documentar estrutura no docblock.

---

## Task 3: Esqueleto do mu-plugin (header + guard + namespace)

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
 *              Spec: docs/superpowers/specs/2026-05-19-formulario-rodape-rdstation-design.md
 * Version:     1.0.0
 * Author:      Daniel Cambría / Bureau de Tecnologia Ltda.
 * Network:     true
 */

defined( 'ABSPATH' ) || exit;

namespace BIT\ElementorFormRDStation;

const VERSION                  = '1.0.0';
const ACTION_NAME              = 'bit_rdstation';
const RD_API_ENDPOINT          = 'https://api.rd.services/platform/conversions';
const RD_TIMEOUT_SEC           = 8;
const DEFAULT_CONVERSION_ID    = 'newsletter-footer-concertacao';
const LOG_DIR_REL              = 'bit-rdstation-logs';

// Guard: só atua se Elementor Pro estiver carregado.
add_action( 'plugins_loaded', function () {
    if ( ! class_exists( '\ElementorPro\Plugin' ) ) {
        return;
    }
    // Action registration vai em Task 4
}, 20 );
```

- [ ] **Step 2: Validar sintaxe PHP**

```bash
docker exec concertacao-dev-wordpress php -l /var/www/html/wp-content/mu-plugins/bit-elementor-form-rdstation.php
```

Expected: `No syntax errors detected`

- [ ] **Step 3: Confirmar mu-plugin é carregado pelo WordPress**

```bash
docker exec -u www-data concertacao-dev-wordpress wp --url="https://cambrasmax.local:8484/" eval 'echo defined("BIT\\ElementorFormRDStation\\VERSION") ? "LOADED" : "NOT_LOADED";'
```

Expected: `LOADED`

- [ ] **Step 4: Commit**

```bash
git add wordpress/wp-content/mu-plugins/bit-elementor-form-rdstation.php
git commit -m "feat(rdstation): esqueleto do mu-plugin com header BIT + guard Elementor Pro"
```

---

## Task 4: Registrar Form Action `bit_rdstation`

**Files:**
- Modify: `wordpress/wp-content/mu-plugins/bit-elementor-form-rdstation.php`

- [ ] **Step 1: Substituir o callback de `plugins_loaded` pela registração da Action**

Trocar o `add_action('plugins_loaded', ...)` para:

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

E criar o diretório + classe stub:

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
        // Vai em Task 5
    }

    public function run( $record, $ajax_handler ): void {
        // Vai em Task 6
    }

    public function on_export( $element ): array {
        return [];
    }
}
```

- [ ] **Step 2: Validar sintaxe**

```bash
docker exec concertacao-dev-wordpress php -l /var/www/html/wp-content/mu-plugins/bit-elementor-form-rdstation.php
docker exec concertacao-dev-wordpress php -l /var/www/html/wp-content/mu-plugins/bit-elementor-form-rdstation/class-form-action.php
```

Expected: `No syntax errors detected` (2x)

- [ ] **Step 3: Confirmar Action aparece registrada**

```bash
docker exec -u www-data concertacao-dev-wordpress wp --url="https://cambrasmax.local:8484/" eval '
do_action("elementor_pro/forms/actions/register", \ElementorPro\Plugin::instance()->modules_manager->get_modules("forms")->get_actions_registrar());
$actions = \ElementorPro\Plugin::instance()->modules_manager->get_modules("forms")->get_actions_registrar()->get_actions();
echo isset($actions["bit_rdstation"]) ? "REGISTERED ok" : "NOT_REGISTERED";
'
```

Expected: `REGISTERED ok`

(Pode ter warnings de Elementor — ignorar; o que importa é a string final.)

- [ ] **Step 4: Commit**

```bash
git add wordpress/wp-content/mu-plugins/bit-elementor-form-rdstation.php wordpress/wp-content/mu-plugins/bit-elementor-form-rdstation/
git commit -m "feat(rdstation): registra Form Action bit_rdstation no Elementor Pro"
```

---

## Task 5: register_settings_section — controles do painel Elementor

**Files:**
- Modify: `wordpress/wp-content/mu-plugins/bit-elementor-form-rdstation/class-form-action.php`

A Action precisa de controles no painel "Ações após o envio" do widget Form. Os controles aparecem quando o user seleciona "RD Station (BIT)" nas actions.

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
            'description' => 'Identificador da conversão no painel RD Station (kebab-case, estável).',
        ]
    );

    $widget->add_control(
        'bit_rd_email_field',
        [
            'label'       => 'Campo Email (custom_id)',
            'type'        => \Elementor\Controls_Manager::TEXT,
            'default'     => 'email',
            'description' => 'custom_id do field tipo email do form (ex: email, form_email, form_email_desk).',
        ]
    );

    $widget->add_control(
        'bit_rd_uf_field',
        [
            'label'       => 'Campo UF (custom_id)',
            'type'        => \Elementor\Controls_Manager::TEXT,
            'default'     => '',
            'placeholder' => 'form_regiao_desk',
            'description' => 'custom_id do field select de UF/Região. Vazio = não envia cf_uf.',
        ]
    );

    $widget->add_control(
        'bit_rd_tags',
        [
            'label'       => 'Tags (CSV)',
            'type'        => \Elementor\Controls_Manager::TEXT,
            'default'     => 'newsletter,concertacao-amazonia,footer-form',
            'description' => 'Tags aplicadas ao contato no RD Station, separadas por vírgula.',
        ]
    );

    $widget->end_controls_section();
}
```

- [ ] **Step 2: Validar sintaxe**

```bash
docker exec concertacao-dev-wordpress php -l /var/www/html/wp-content/mu-plugins/bit-elementor-form-rdstation/class-form-action.php
```

Expected: `No syntax errors detected`

- [ ] **Step 3: Validar editor Elementor manualmente**

1. Abrir editor: `https://cambrasmax.local:8484/wp-admin/post.php?post=72234&action=elementor`
2. Selecionar widget Form da PREVIEW (id 520a235)
3. Aba "Conteúdo" → "Ações após o envio"
4. Adicionar action "RD Station (BIT)" no dropdown
5. Conferir que aparece nova seção lá embaixo "RD Station (BIT)" com 4 controles: Conversion Identifier, Campo Email, Campo UF, Tags

Se não aparece: Action não foi registrada corretamente. Voltar Task 4 step 3.

- [ ] **Step 4: Commit**

```bash
git add wordpress/wp-content/mu-plugins/bit-elementor-form-rdstation/class-form-action.php
git commit -m "feat(rdstation): controles do painel Elementor (conversion_id, email field, uf field, tags)"
```

---

## Task 6: run() — POST para RD Station com graceful degradation

**Files:**
- Modify: `wordpress/wp-content/mu-plugins/bit-elementor-form-rdstation/class-form-action.php`

- [ ] **Step 1: Implementar `run()`**

Substituir o stub vazio por:

```php
public function run( $record, $ajax_handler ): void {
    // 1. Conferir token disponível — se não, log + return (graceful)
    if ( ! defined( 'RDSTATION_PRIVATE_TOKEN' ) || ! RDSTATION_PRIVATE_TOKEN ) {
        \BIT\ElementorFormRDStation\log( 'warn', 'RDSTATION_PRIVATE_TOKEN não definido — submit ignorado' );
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
        \BIT\ElementorFormRDStation\log( 'warn', 'Email inválido — submit ignorado', [ 'raw' => $email_raw, 'field' => $email_field ] );
        return;
    }

    // 4. Montar payload
    $payload = [
        'conversion_identifier' => $conversion_id,
        'email'                 => $email,
    ];

    if ( $uf_raw ) {
        $payload['cf_uf'] = sanitize_text_field( $uf_raw );
    }

    if ( $tags_csv ) {
        $payload['tags'] = array_values( array_filter( array_map( 'trim', explode( ',', $tags_csv ) ) ) );
    }

    // legal_bases default — sem checkbox de consent ainda (TBD próxima entrega)
    $payload['legal_bases'] = [
        [ 'category' => 'communications', 'type' => 'consent', 'status' => 'granted' ],
    ];

    $body = [
        'event_type'   => 'CONVERSION',
        'event_family' => 'CDP',
        'payload'      => $payload,
    ];

    // 5. POST com timeout curto
    $url = RD_API_ENDPOINT . '?api_key=' . rawurlencode( RDSTATION_PRIVATE_TOKEN );

    $response = wp_remote_post( $url, [
        'timeout' => RD_TIMEOUT_SEC,
        'headers' => [ 'Content-Type' => 'application/json' ],
        'body'    => wp_json_encode( $body ),
    ] );

    // 6. Log resultado — NUNCA chama add_error_message (graceful)
    if ( is_wp_error( $response ) ) {
        \BIT\ElementorFormRDStation\log( 'error', 'wp_remote_post falhou', [
            'msg'   => $response->get_error_message(),
            'email' => $email,
        ] );
        return;
    }

    $code = wp_remote_retrieve_response_code( $response );
    $resp_body = wp_remote_retrieve_body( $response );

    if ( $code >= 200 && $code < 300 ) {
        \BIT\ElementorFormRDStation\log( 'info', "OK $code", [ 'email' => $email, 'cid' => $conversion_id ] );
    } else {
        \BIT\ElementorFormRDStation\log( 'error', "RD respondeu $code", [
            'email' => $email,
            'body'  => substr( $resp_body, 0, 500 ),
        ] );
    }
}
```

- [ ] **Step 2: Criar a função `log()` no mu-plugin principal**

Adicionar ao final de `wordpress/wp-content/mu-plugins/bit-elementor-form-rdstation.php` (antes do fechamento do arquivo PHP, depois do `add_action plugins_loaded`):

```php
/**
 * Log estruturado pra wp-content/uploads/bit-rdstation-logs/YYYY-MM-DD.log.
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
    $upload = wp_upload_dir();
    $dir    = $upload['basedir'] . '/' . LOG_DIR_REL;
    if ( ! is_dir( $dir ) ) {
        wp_mkdir_p( $dir );
    }
    $file = $dir . '/' . gmdate( 'Y-m-d' ) . '.log';
    $line = sprintf(
        "[%s] [%s] %s%s\n",
        gmdate( 'Y-m-d H:i:s' ),
        strtoupper( $level ),
        $msg,
        $ctx ? ' ' . wp_json_encode( $ctx, JSON_UNESCAPED_UNICODE ) : ''
    );
    @file_put_contents( $file, $line, FILE_APPEND | LOCK_EX );
}
```

- [ ] **Step 3: Validar sintaxe (ambos arquivos)**

```bash
docker exec concertacao-dev-wordpress php -l /var/www/html/wp-content/mu-plugins/bit-elementor-form-rdstation.php
docker exec concertacao-dev-wordpress php -l /var/www/html/wp-content/mu-plugins/bit-elementor-form-rdstation/class-form-action.php
```

Expected: 2× `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add wordpress/wp-content/mu-plugins/bit-elementor-form-rdstation.php wordpress/wp-content/mu-plugins/bit-elementor-form-rdstation/
git commit -m "feat(rdstation): run() POSTa pra RD via wp_remote_post + log estruturado"
```

---

## Task 7: Wire-up no template footer 72234 (PREVIEW form)

**Files:**
- Modify (via Elementor editor manual ou via WP-CLI eval): `_elementor_data` post 72234, widget Form 520a235

A Action precisa ser ativada na PREVIEW form. Pode ser via editor (manual) ou via PHP direto. Vou via PHP pra ser reproduzível.

- [ ] **Step 1: Conferir custom_id atual dos fields da PREVIEW**

```bash
docker exec -u www-data concertacao-dev-wordpress wp --url="https://cambrasmax.local:8484/" eval '
$raw = get_post_meta(72234, "_elementor_data", true);
$data = json_decode($raw, true);
function find_form($n) {
    foreach ($n as $x) {
        if (($x["widgetType"] ?? "") === "form" && ($x["id"] ?? "") === "520a235") return $x;
        if (!empty($x["elements"])) { $r = find_form($x["elements"]); if ($r) return $r; }
    }
    return null;
}
$w = find_form($data);
foreach ($w["settings"]["form_fields"] as $f) {
    echo $f["custom_id"] . " (type=" . $f["field_type"] . ")\n";
}
'
```

Expected output (algo como):
```
form_email_desk (type=email)
form_regiao_desk (type=select)
recaptcha_desk (type=recaptcha_v3)
```

Anotar os custom_ids exatos para o próximo step.

- [ ] **Step 2: Adicionar action "bit_rdstation" + settings via WP-CLI**

```bash
docker exec -u www-data concertacao-dev-wordpress wp --url="https://cambrasmax.local:8484/" eval '
$raw = get_post_meta(72234, "_elementor_data", true);
$data = json_decode($raw, true);

function find_path(&$nodes, $tid, $path = []) {
    foreach ($nodes as $i => $n) {
        $np = array_merge($path, [$i]);
        if (($n["widgetType"] ?? "") === "form" && ($n["id"] ?? "") === $tid) return $np;
        if (!empty($n["elements"])) {
            $r = find_path($n["elements"], $tid, array_merge($np, ["elements"]));
            if ($r !== null) return $r;
        }
    }
    return null;
}
$path = find_path($data, "520a235");
$ref = &$data;
foreach ($path as $k) $ref = &$ref[$k];
$s = &$ref["settings"];

// Adicionar bit_rdstation às submit_actions se ainda não estiver
$actions = $s["submit_actions"] ?? [];
if (!is_array($actions)) $actions = explode(",", (string)$actions);
if (!in_array("bit_rdstation", $actions, true)) {
    $actions[] = "bit_rdstation";
}
$s["submit_actions"] = $actions;

// Settings da action
$s["bit_rd_conversion_identifier"] = "newsletter-footer-concertacao";
$s["bit_rd_email_field"]           = "form_email_desk";
$s["bit_rd_uf_field"]              = "form_regiao_desk";
$s["bit_rd_tags"]                  = "newsletter,concertacao-amazonia,footer-form";

$encoded = wp_slash(wp_json_encode($data));
delete_post_meta(72234, "_elementor_data");
add_post_meta(72234, "_elementor_data", $encoded, true);
clean_post_cache(72234);
\Elementor\Plugin::$instance->files_manager->clear_cache();
echo "submit_actions agora: " . wp_json_encode($s["submit_actions"]) . "\n";
echo "ok\n";
'
```

Expected: `submit_actions agora: ["email","bit_rdstation"]` (ou similar com bit_rdstation incluído) + `ok`.

- [ ] **Step 3: Confirmar no editor que a Action está ativa**

1. Abrir editor: `https://cambrasmax.local:8484/wp-admin/post.php?post=72234&action=elementor`
2. Selecionar widget Form 520a235
3. Aba "Conteúdo" → "Ações após o envio"
4. Conferir que "RD Station (BIT)" aparece selecionado no dropdown
5. Conferir que a seção "RD Station (BIT)" lá embaixo mostra os 4 settings preenchidos com os valores corretos

- [ ] **Step 4: Sem commit** (mudança no DB, não em código git)

A mudança fica no `_elementor_data` do template. Não vai pro git. Em deploy, o template inteiro é exportado/importado via `share deploy` ou similar.

---

## Task 8: Submit funcional + validação do log

**Files:**
- Read-only: log file

- [ ] **Step 1: Ativar BIT_RDSTATION_DEBUG temporariamente**

```bash
docker exec -u root concertacao-dev-wordpress sh -c "echo \"define( 'BIT_RDSTATION_DEBUG', true );\" >> /var/www/html/wp-config.php"
docker exec -u www-data concertacao-dev-wordpress wp --url="https://cambrasmax.local:8484/" eval 'echo defined("BIT_RDSTATION_DEBUG") && BIT_RDSTATION_DEBUG ? "ON" : "OFF";'
```

Expected: `ON`

- [ ] **Step 2: Limpar log antigo e fazer submit**

```bash
docker exec -u www-data concertacao-dev-wordpress wp --url="https://cambrasmax.local:8484/" eval '
$upload = wp_upload_dir();
$logfile = $upload["basedir"] . "/bit-rdstation-logs/" . gmdate("Y-m-d") . ".log";
if (file_exists($logfile)) unlink($logfile);
echo "log limpo: $logfile\n";
'
```

Submit via curl (igual o que o JS do form faria):

```bash
# Pegar nonce do form (Elementor Pro exige)
curl -sk "https://cambrasmax.local:8484/" -o /tmp/home.html
NONCE=$(grep -oE 'name="post_id" value="72234"' /tmp/home.html | head -1)

# Submit
curl -sS -X POST "https://cambrasmax.local:8484/?elementor-pro=submit-form" \
  -H "X-Requested-With: XMLHttpRequest" \
  -d "form_fields[form_email_desk]=test-$(date +%s)@bit-bpo.com" \
  -d "form_fields[form_regiao_desk]=PA" \
  -d "form_id=520a235" \
  -d "post_id=72234"
echo
```

Expected: JSON com `success:true` (Elementor Pro retorna isso quando email action sucede). Mesmo se RD action falhar, success:true porque é graceful.

Se erro 403/nonce: o submit precisa do mu-plugin `bit-smoke-recaptcha-bypass` ativo (já existe no projeto pra smoke tests). Alternativa: fazer submit via Playwright que simula browser completo.

- [ ] **Step 3: Validar log gerado**

```bash
docker exec -u www-data concertacao-dev-wordpress wp --url="https://cambrasmax.local:8484/" eval '
$upload = wp_upload_dir();
$logfile = $upload["basedir"] . "/bit-rdstation-logs/" . gmdate("Y-m-d") . ".log";
echo file_exists($logfile) ? file_get_contents($logfile) : "LOG_NAO_EXISTE\n";
'
```

Expected: 1 linha com `[INFO] OK 200 {"email":"test-...@bit-bpo.com","cid":"newsletter-footer-concertacao"}` (ou similar).

Se log NÃO existe: a Action não rodou. Investigar:
- `submit_actions` tem `bit_rdstation`? (Task 7 step 2)
- Action está registrada? (`do_action elementor_pro/forms/actions/register`)
- Hook conectado? Adicionar log na entrada do `run()` temporariamente.

Se log mostra `[ERROR] RD respondeu 4XX`: investigar body da resposta. Pode ser custom field não cadastrado (`cf_uf`) — vai pra Task 9.

- [ ] **Step 4: Confirmar no painel RD Station que a conversão chegou**

Login em `https://app.rdstation.com.br/` da conta Concertação → Leads → buscar pelo email `test-<timestamp>@bit-bpo.com`. Confirmar que aparece com:
- conversion identifier `newsletter-footer-concertacao`
- tags aplicadas
- `cf_uf=PA` (se cf_uf existir — senão pular)

- [ ] **Step 5: Desativar BIT_RDSTATION_DEBUG**

```bash
docker exec -u root concertacao-dev-wordpress sed -i "/define.*'BIT_RDSTATION_DEBUG'/d" /var/www/html/wp-config.php
docker exec -u www-data concertacao-dev-wordpress wp --url="https://cambrasmax.local:8484/" eval 'echo defined("BIT_RDSTATION_DEBUG") ? "STILL_ON" : "OFF";'
```

Expected: `OFF`

- [ ] **Step 6: Sem commit** (não há mudança de código nesse task — só validação)

---

## Task 9: Bootstrap custom fields (cf_uf, cf_consent_*) no RD Station

**Files:**
- Create: `scripts/rdstation-bootstrap-fields.php`

`cf_uf` precisa existir no painel RD antes de payload com `cf_uf:"PA"` ser aceito (caso contrário, RD ignora silenciosamente).

- [ ] **Step 1: Criar o script standalone (idempotente)**

Conteúdo de `scripts/rdstation-bootstrap-fields.php`:

```php
<?php
/**
 * rdstation-bootstrap-fields.php — One-shot idempotente.
 *
 * Cria os custom fields necessários no painel RD Station via API:
 *   - cf_uf                : UF brasileira (string)
 *   - cf_consent_source    : URL onde o consent foi dado (string)
 *   - cf_consent_timestamp : ISO 8601 do momento do submit (string)
 *
 * Idempotente: GET /platform/contacts/fields → POST apenas se não existe.
 *
 * ATENÇÃO: este endpoint exige OAuth2 Bearer Token (não API Key). Para uso
 * one-shot, gerar token via:
 *   https://api.rd.services/auth/dialog?client_id=XXX&redirect_uri=YYY
 * Token volátil (24h). Pode também ser feito MANUALMENTE pelo painel RD em
 * Settings > Custom Fields — talvez seja mais simples nesta entrega.
 *
 * Uso:
 *   docker exec -u www-data concertacao-dev-wordpress wp \
 *     --url="https://cambrasmax.local:8484/" \
 *     eval-file /var/www/html/wp-content/uploads/_scripts/rdstation-bootstrap-fields.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    fwrite( STDERR, "Run via wp eval-file\n" );
    exit( 1 );
}

$bearer = getenv( 'RDSTATION_BEARER' );
if ( ! $bearer ) {
    fwrite( STDERR, "ERRO: definir RDSTATION_BEARER no ambiente (OAuth2 access_token, válido 24h).\n" );
    fwrite( STDERR, "Alternativa: criar campos manualmente no painel RD > Settings > Custom Fields.\n" );
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

// 1. Listar fields existentes
$response = wp_remote_get( 'https://api.rd.services/platform/contacts/fields', [
    'headers' => [ 'Authorization' => 'Bearer ' . $bearer ],
    'timeout' => 10,
] );

if ( is_wp_error( $response ) ) {
    fwrite( STDERR, "ERRO ao listar fields: " . $response->get_error_message() . "\n" );
    exit( 1 );
}

$body     = wp_remote_retrieve_body( $response );
$existing = json_decode( $body, true );
$existing_ids = [];
if ( is_array( $existing ) ) {
    foreach ( $existing as $f ) {
        if ( isset( $f['api_identifier'] ) ) {
            $existing_ids[] = $f['api_identifier'];
        }
    }
}
echo "Fields existentes: " . count( $existing_ids ) . "\n";

// 2. Criar os que faltam
foreach ( $fields_to_create as $field ) {
    if ( in_array( $field['api_identifier'], $existing_ids, true ) ) {
        echo "✓ " . $field['api_identifier'] . " já existe — pulando\n";
        continue;
    }

    $resp = wp_remote_post( 'https://api.rd.services/platform/contacts/fields', [
        'timeout' => 10,
        'headers' => [
            'Authorization' => 'Bearer ' . $bearer,
            'Content-Type'  => 'application/json',
        ],
        'body'    => wp_json_encode( $field ),
    ] );

    if ( is_wp_error( $resp ) ) {
        echo "✗ " . $field['api_identifier'] . " falhou: " . $resp->get_error_message() . "\n";
        continue;
    }
    $code = wp_remote_retrieve_response_code( $resp );
    if ( $code === 201 ) {
        echo "+ " . $field['api_identifier'] . " criado\n";
    } else {
        echo "? " . $field['api_identifier'] . " HTTP $code: " . substr( wp_remote_retrieve_body( $resp ), 0, 200 ) . "\n";
    }
}
echo "done\n";
```

- [ ] **Step 2: Validar sintaxe**

```bash
php -l scripts/rdstation-bootstrap-fields.php
```

Expected: `No syntax errors detected`

- [ ] **Step 3: Alternativa manual (recomendada — mais simples)**

Se o user já tem acesso ao painel RD Station, é mais simples criar os 3 fields manualmente:
1. `https://app.rdstation.com.br/` → Settings → Custom Fields
2. Criar 3 fields STRING/TEXT_INPUT: `cf_uf`, `cf_consent_source`, `cf_consent_timestamp`
3. Pular execução do script

Documentar a escolha no commit.

- [ ] **Step 4: Commit do script (mesmo se não executado)**

```bash
git add scripts/rdstation-bootstrap-fields.php
git commit -m "feat(rdstation): script bootstrap dos custom fields (cf_uf + consent)

Idempotente. Exige OAuth2 Bearer Token (não API Key) — esse endpoint
não aceita api_key. Alternativa simples: criar manualmente no painel
RD Station > Settings > Custom Fields."
```

---

## Task 10: Cópia canônica + Playwright spec + PR

**Files:**
- Create: `docker-dev/common/mu-plugins/bit-elementor-form-rdstation.php`
- Create: `docker-dev/common/mu-plugins/bit-elementor-form-rdstation/class-form-action.php`
- Create: `~/scripts/testes/concertacao/tests/09-rdstation-action.spec.js`

- [ ] **Step 1: Cópia canônica pro server-tools**

```bash
mkdir -p /Users/dcambria/scripts/server-tools/v2/docker-dev/common/mu-plugins/bit-elementor-form-rdstation/
cp wordpress/wp-content/mu-plugins/bit-elementor-form-rdstation.php \
   /Users/dcambria/scripts/server-tools/v2/docker-dev/common/mu-plugins/
cp wordpress/wp-content/mu-plugins/bit-elementor-form-rdstation/class-form-action.php \
   /Users/dcambria/scripts/server-tools/v2/docker-dev/common/mu-plugins/bit-elementor-form-rdstation/

# Verificar MD5 idêntico
md5 -q wordpress/wp-content/mu-plugins/bit-elementor-form-rdstation.php
md5 -q /Users/dcambria/scripts/server-tools/v2/docker-dev/common/mu-plugins/bit-elementor-form-rdstation.php
```

Expected: 2× MD5 idênticos.

NÃO commitar no server-tools repo (main pode estar com WIP — deferred igual à Parte 1).

- [ ] **Step 2: Spec Playwright `09-rdstation-action.spec.js`**

Conteúdo:

```javascript
'use strict';

const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');

try { require('dotenv').config({ path: path.join(__dirname, '..', '.env.local') }); } catch {}

const screenshotsDir = path.join(__dirname, '..', 'screenshots', 'rdstation');
test.beforeAll(() => { fs.mkdirSync(screenshotsDir, { recursive: true }); });

test.describe('RD Station Form Action — submit + graceful', () => {

    test('submit do form do rodapé chega na success message (graceful mesmo se RD falhar)', async ({ browser }) => {
        const context = await browser.newContext({
            viewport: { width: 1440, height: 900 },
            ignoreHTTPSErrors: true,
        });
        const page = await context.newPage();
        await page.goto('/', { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(1500);
        try { await page.locator('button:has-text("ACEITAR")').click({ timeout: 3000 }); } catch (e) {}
        await page.waitForTimeout(500);

        // Scroll até o form
        const form = page.locator('.elementor-element[data-id="520a235"]').first();
        await form.scrollIntoViewIfNeeded();

        // Preencher
        const email = `playwright-test-${Date.now()}@bit-bpo.com`;
        await form.locator('input[type="email"]').fill(email);
        await form.locator('select').selectOption({ value: 'PA' });

        // Submit
        await form.locator('button[type="submit"]').click();

        // Esperar success message (Elementor Pro default)
        const successMsg = form.locator('.elementor-message-success');
        await expect(successMsg).toBeVisible({ timeout: 10000 });

        await form.screenshot({ path: path.join(screenshotsDir, 'submit-success.png') });
        await context.close();
    });
});
```

- [ ] **Step 3: Commit do spec no repo de testes**

```bash
cd /Users/dcambria/scripts/testes/concertacao
git add tests/09-rdstation-action.spec.js
git commit -m "test(rdstation): spec valida submit + success message (graceful)"
cd /Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao/.claude/worktrees/feat-footer-form-unified-part1
```

- [ ] **Step 4: Push branch + abrir PR**

```bash
git push -u origin feat-rdstation-integration-part2
gh pr create --title "feat(rdstation): Form Action — integração RD Station Marketing (Parte 2)" --body "$(cat <<'EOF'
## Summary

Parte 2 do trabalho — integração RD Station via Form Action customizada do Elementor Pro Forms.

### Mudanças

**Mu-plugin novo** \`wordpress/wp-content/mu-plugins/bit-elementor-form-rdstation.{php,/class-form-action.php}\` (v1.0.0):
- Form Action \`bit_rdstation\` estendendo \`Action_Base\`
- Controles no painel: conversion_identifier, email field, UF field, tags
- run() POSTa pra \`https://api.rd.services/platform/conversions?api_key=RDSTATION_PRIVATE_TOKEN\`
- Graceful degradation: API falhando NUNCA quebra submit do form
- Log estruturado em \`wp-content/uploads/bit-rdstation-logs/YYYY-MM-DD.log\`
- BIT_RDSTATION_DEBUG=true loga sucessos também (default só warn/error)
- Cópia canônica em \`docker-dev/common/mu-plugins/\` do server-tools (commit deferred)

**wp-config.php** (não versionado, gerado pelo bootstrap container):
- \`define('RDSTATION_PRIVATE_TOKEN', getenv('RDSTATION_PRIVATE_TOKEN'))\`
- \`define('RDSTATION_PUBLIC_TOKEN', getenv('RDSTATION_PUBLIC_TOKEN'))\`

**Script** \`scripts/rdstation-bootstrap-fields.php\`:
- Cria custom fields cf_uf + cf_consent_source + cf_consent_timestamp via API
- Idempotente (GET → POST se não existe)
- Exige OAuth2 Bearer (endpoint não aceita api_key) — alternativa manual no painel RD

**Template footer 72234** (não no git):
- Action \`bit_rdstation\` adicionada às submit_actions do widget Form 520a235
- Settings mapeiam form_email_desk → email, form_regiao_desk → cf_uf

### Spec
docs/superpowers/specs/2026-05-19-formulario-rodape-rdstation-design.md (Parte 2)

### Test plan
- [ ] DEV: \`std up\` + conferir \`RDSTATION_PRIVATE_TOKEN\` defined
- [ ] DEV: smoke curl direto à API (validação token + endpoint)
- [ ] DEV: editor Elementor mostra "RD Station (BIT)" no dropdown de actions
- [ ] DEV: submit funcional via Playwright \`09-rdstation-action.spec.js\` → success msg visível
- [ ] DEV: log mostra \`[INFO] OK 200\` com BIT_RDSTATION_DEBUG=true
- [ ] DEV: painel RD Station mostra lead com tags + cf_uf
- [ ] DEV: graceful — token inválido NÃO quebra submit (form ainda dá success)
- [ ] HML: deploy via phase3, repetir smoke
- [ ] PROD: deploy, validar lead chega no painel RD da Concertação

### Limitações
- LGPD checkbox de consentimento NÃO implementado nesta entrega (próxima etapa)
- Tokens no \`.env\` do site (não no \`.env\` raiz com sufixos \`_DEV/_HML/_PROD\`) — fix arquitetural fica pra próxima entrega
- Custom fields RD precisam ser criados manualmente no painel ou via OAuth2 (api_key não aceita)

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

Expected: URL do PR retornada.

---

## Validação final consolidada

Antes de mergear:

- [ ] **Sintaxe**: 2 arquivos PHP do mu-plugin + script = clean (`php -l`)
- [ ] **Action registrada**: WP-CLI eval confirma `bit_rdstation` na lista do registrar
- [ ] **Controles no editor**: aba "Ações após envio" mostra "RD Station (BIT)" no dropdown
- [ ] **Submit funcional**: Playwright spec 09 PASS (success message visível)
- [ ] **Log gerado**: arquivo `bit-rdstation-logs/YYYY-MM-DD.log` tem linha `[INFO] OK 200` com BIT_RDSTATION_DEBUG
- [ ] **Painel RD**: lead aparece com email + tags + cf_uf=PA
- [ ] **Graceful**: setar token inválido, fazer submit, confirmar submit ainda dá success E log tem `[ERROR] RD respondeu 401`
- [ ] **Constants persistem**: `RDSTATION_PRIVATE_TOKEN` defined após restart do container
- [ ] **Cópia canônica**: MD5 do mu-plugin em `docker-dev/common/mu-plugins/` = MD5 em `wordpress/wp-content/mu-plugins/`
- [ ] **PR aberto**: 1 no concertacao, server-tools deferred

Quando todos verdes em DEV, sequência para HML e PROD via phase3 normal.
