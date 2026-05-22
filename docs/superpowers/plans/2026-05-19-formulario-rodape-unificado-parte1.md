# Form do Rodapé Unificado (Parte 1) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Eliminar os 2 widgets Form duplicados do rodapé (desktop/tablet vs mobile) consolidando num único widget device-aware, e corrigir o bug do `<option>Região</option>` sendo enviado como valor.

**Architecture:** Mu-plugin que estende o Form nativo do Elementor Pro via hooks `elementor/element/form/section_form_fields/before_section_end` (marcando 3 controles como `responsive => true`) + filter no render que injeta `placeholder` por device e CSS pill/retângulo. Script PHP standalone faz a unificação one-shot do template footer 72234. JS de ~30 linhas troca `placeholder` no `matchMedia`. CSS escopado em `.elementor-widget-form.bit-form-responsive` evita afetar outros forms.

**Tech Stack:** PHP 8.3 (mu-plugin), CSS3 (`@media`), Vanilla JS (matchMedia), WP-CLI (`wp eval-file`), Playwright (`@playwright/test` em `~/scripts/testes/concertacao/tests/`).

**Spec:** `docs/superpowers/specs/2026-05-19-formulario-rodape-rdstation-design.md` (commit `144202eaca`).

**Escopo desta Parte 1 (excluindo o que vai para a Parte 2):**
- ✅ Unificação dos 2 widgets em 1 device-aware.
- ✅ Fix do placeholder `"Região"` no select (`field_options_empty`).
- ✅ Fix do `custom_id` divergente (`form_regiao` único).
- ❌ Integração RD Station (Parte 2 — plano próprio).
- ❌ Checkbox de consentimento LGPD (Parte 2 — depende de RD).

**Convenções do projeto (CLAUDE.md):**
- Mu-plugin sempre tem header BIT (`Plugin Name: BIT ...`, `Author: Daniel Cambría / Bureau de Tecnologia Ltda.`).
- Toda credencial em `wp-config.php` constante (não aplica aqui — sem credencial).
- mu-plugin novo: copiar para `docker-dev/common/mu-plugins/` e commit em `/Users/dcambria/scripts/server-tools/v2`.
- WP-CLI em multisite: SEMPRE `--url=` (blog 1 = `cambrasmax.local:8484`, blog 2 = `cambrasmax.local:8484/cultura/`).
- `_elementor_data` update: SEMPRE `wp_slash(wp_json_encode(...))` ([[feedback_elementor_data_wp_slash_required]]).
- Após `wp elementor flush-css`: warm-up explícito via `(new \Elementor\Core\Files\CSS\Post($id))->update()` ([[feedback_elementor_flush_css_warmup]]).
- `((var++))` sob `set -e`: usar `((var++)) || true`.

---

## File Structure

| Path | Responsabilidade |
|---|---|
| `wordpress/wp-content/mu-plugins/bit-elementor-form-responsive.php` | Hooks PHP: `responsive => true` em `form_name`/`placeholder`/`field_options_empty`; filter de render injeta data-attrs `data-bit-placeholder-tablet/mobile` + classe `bit-form-responsive`. Enqueue de CSS+JS. |
| `wordpress/wp-content/mu-plugins/bit-elementor-form-responsive.css` | CSS escopado em `.elementor-widget-form.bit-form-responsive`, cobrindo deltas #3–#8 via `@media` breakpoints Elementor (`max-width: 1024px` tablet, `max-width: 767px` mobile). |
| `wordpress/wp-content/mu-plugins/bit-elementor-form-responsive.js` | `matchMedia` listener que troca atributo `placeholder` dos inputs e texto da primeira `<option disabled selected>` do select. |
| `docker-dev/common/mu-plugins/bit-elementor-form-responsive.{php,css,js}` | Cópia canônica (regra `sites/CLAUDE.md`). |
| `scripts/unify-footer-form.php` | One-shot idempotente com `--dry-run`. Carrega `_elementor_data` do post 72234, faz merge dos 2 containers em 1, remove o mobile, salva. |
| `~/scripts/testes/concertacao/tests/08-footer-form-responsive.spec.js` | Playwright spec: viewport desktop/tablet/mobile valida heading text, placeholder, layout pill vs retângulo, posição do botão, e submit funcional sem "Região" como valor. |

---

## Pré-condições

- Site concertacao subido em dev: `cd ~/scripts/server-tools/v2/docker-dev/sites/concertacao && std up` (ou `/Users/dcambria/scripts/server-tools/v2/docker-dev/common/bin/docker-dev.sh up` em ambiente não-interativo).
- Backup do template 72234 antes de qualquer mexida (Task 0).
- Branch dedicada: `git checkout -b feat/footer-form-unified-part1`.

---

## Task 0: Branch + Backup do template

**Files:**
- Read: `wordpress/wp-content/themes/...` (sem alteração)

- [ ] **Step 1: Criar branch dedicada**

Run:
```bash
cd /Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao
git status                              # confirmar working tree limpa
git checkout -b feat/footer-form-unified-part1
```

Expected: `Switched to a new branch 'feat/footer-form-unified-part1'`

- [ ] **Step 2: Backup do template 72234 (DEV)**

Run:
```bash
mkdir -p backups/templates
/Users/dcambria/scripts/server-tools/v2/docker-dev/common/bin/docker-dev.sh wp \
  --url="https://cambrasmax.local:8484/" \
  post get 72234 --field=content > backups/templates/72234-pre-unify-$(date +%Y%m%d-%H%M%S).txt
ls -lh backups/templates/
```

Expected: arquivo `72234-pre-unify-YYYYMMDD-HHMMSS.txt` com >100KB (template Elementor sério).

- [ ] **Step 3: Backup do `_elementor_data` (json bruto)**

Run:
```bash
docker exec -u www-data concertacao-dev-wordpress wp \
  --url="https://cambrasmax.local:8484/" \
  post meta get 72234 _elementor_data > backups/templates/72234-elementor-data-pre-unify-$(date +%Y%m%d-%H%M%S).json
jq 'type' backups/templates/72234-elementor-data-pre-unify-*.json | head -1
```

Expected: `"array"` (JSON válido). Se vier `parse error`, ABORTAR e investigar — o meta pode estar slashed e precisa unslashing.

- [ ] **Step 4: Commit do backup**

```bash
git add backups/templates/
git commit -m "chore(backup): snapshot template footer 72234 pré-unificação form"
```

---

## Task 1: Esqueleto do mu-plugin (header BIT + guard + enqueue stubs)

**Files:**
- Create: `wordpress/wp-content/mu-plugins/bit-elementor-form-responsive.php`

- [ ] **Step 1: Criar arquivo com header canônico + guard de ativação**

Conteúdo de `wordpress/wp-content/mu-plugins/bit-elementor-form-responsive.php`:

```php
<?php
/**
 * Plugin Name: BIT Elementor Form Responsive
 * Plugin URI:  https://bureau-it.com
 * Description: Estende o widget Form do Elementor Pro tornando `form_name`,
 *              `placeholder` e `field_options_empty` device-aware (Desktop/Tablet/Mobile)
 *              via switcher nativo. Permite unificar 2 widgets de form com designs
 *              distintos em 1 widget. CSS pill/retângulo + JS placeholder por breakpoint.
 *              Spec: docs/superpowers/specs/2026-05-19-formulario-rodape-rdstation-design.md
 * Version:     1.0.0
 * Author:      Daniel Cambría / Bureau de Tecnologia Ltda.
 * Network:     true
 */

defined( 'ABSPATH' ) || exit;

namespace BIT\ElementorFormResponsive;

const VERSION = '1.0.0';
const WIDGET_CLASS = 'bit-form-responsive';

// Guard: só atua se Elementor Pro estiver carregado (mu-plugins rodam antes de plugins, então adia)
add_action( 'plugins_loaded', function () {
    if ( ! did_action( 'elementor_pro/init' ) && ! class_exists( '\ElementorPro\Plugin' ) ) {
        return;
    }
    require_once __DIR__ . '/bit-elementor-form-responsive.php'; // self-noop: garante 1 load
}, 20 );
```

- [ ] **Step 2: Validar sintaxe**

Run:
```bash
docker exec concertacao-dev-wordpress php -l \
  /var/www/html/wp-content/mu-plugins/bit-elementor-form-responsive.php
```

Expected: `No syntax errors detected`

- [ ] **Step 3: Verificar que o mu-plugin é carregado**

Run:
```bash
docker exec -u www-data concertacao-dev-wordpress wp \
  --url="https://cambrasmax.local:8484/" \
  eval 'echo defined("BIT\\ElementorFormResponsive\\VERSION") ? "OK" : "NOT_LOADED";'
```

Expected: `OK`

- [ ] **Step 4: Commit**

```bash
git add wordpress/wp-content/mu-plugins/bit-elementor-form-responsive.php
git commit -m "feat(form-responsive): esqueleto do mu-plugin com header BIT e guard de Elementor Pro"
```

---

## Task 2: Hook que torna controles do Elementor Form responsivos

**Files:**
- Modify: `wordpress/wp-content/mu-plugins/bit-elementor-form-responsive.php` (substituir corpo dentro do `plugins_loaded` callback)

- [ ] **Step 1: Substituir o callback de `plugins_loaded` pela lógica real**

No arquivo `bit-elementor-form-responsive.php`, **trocar** o callback de `plugins_loaded` (que hoje só faz require_once) pela seguinte implementação:

```php
add_action( 'plugins_loaded', function () {
    if ( ! did_action( 'elementor_pro/init' ) && ! class_exists( '\ElementorPro\Plugin' ) ) {
        return;
    }

    // Tornar 'form_name' responsive no widget Form
    add_action( 'elementor/element/form/section_form_fields/before_section_end', function ( $element, $args ) {
        $element->update_control( 'form_name', [ 'responsive' => true ] );
    }, 10, 2 );

    // Tornar 'placeholder' e 'field_options_empty' responsive nos fields (repeater)
    add_action( 'elementor/element/form/section_form_fields/before_section_end', function ( $element, $args ) {
        // O repeater 'form_fields' tem fields como sub-controles
        $element->update_control( 'form_fields', [
            'fields' => [
                'placeholder'         => [ 'responsive' => true ],
                'field_options_empty' => [ 'responsive' => true ],
            ],
        ] );
    }, 11, 2 );
}, 20 );
```

- [ ] **Step 2: Validar sintaxe**

Run:
```bash
docker exec concertacao-dev-wordpress php -l \
  /var/www/html/wp-content/mu-plugins/bit-elementor-form-responsive.php
```

Expected: `No syntax errors detected`

- [ ] **Step 3: Validação manual no editor Elementor**

1. Abrir `https://cambrasmax.local:8484/wp-admin/post.php?post=72234&action=elementor` no browser.
2. Clicar no widget Form do desktop.
3. Verificar que ao lado do label "Form Name" agora aparece o switcher device (🖥/📱).
4. Clicar num field do tipo `select` (Região) e verificar que "Empty Option" também tem switcher device.
5. Clicar num field do tipo `email` e verificar que "Placeholder" também tem switcher device.

Se algum dos 3 não apareceu device-aware, **NÃO seguir** — pode ser que a API exata seja `args` em vez de `update_control` direto. Investigar via `\ElementorPro\Modules\Forms\Widgets\Form::register_controls()` no source do plugin (`wordpress/wp-content/plugins/elementor-pro/modules/forms/widgets/form.php`).

- [ ] **Step 4: Commit**

```bash
git add wordpress/wp-content/mu-plugins/bit-elementor-form-responsive.php
git commit -m "feat(form-responsive): controles device-aware (form_name, placeholder, field_options_empty)"
```

---

## Task 3: Filter de render — injetar data-attrs e classe

**Files:**
- Modify: `wordpress/wp-content/mu-plugins/bit-elementor-form-responsive.php` (adicionar dentro do callback `plugins_loaded`)

- [ ] **Step 1: Adicionar o filter de `elementor/widget/render_content`**

Adicionar **dentro do callback de `plugins_loaded`** (depois dos 2 `add_action` da Task 2):

```php
    // Filter no render — injetar data-attrs e classe escopo
    add_filter( 'elementor/widget/render_content', function ( $content, $widget ) {
        if ( $widget->get_name() !== 'form' ) {
            return $content;
        }

        $settings = $widget->get_settings_for_display();

        // 1. Injetar classe bit-form-responsive na div do widget
        $content = preg_replace(
            '/(class="[^"]*elementor-widget-form[^"]*)"/',
            '$1 ' . WIDGET_CLASS . '"',
            $content,
            1
        );

        // 2. Para cada field, injetar data-bit-placeholder-tablet/mobile
        if ( ! empty( $settings['form_fields'] ) && is_array( $settings['form_fields'] ) ) {
            foreach ( $settings['form_fields'] as $field ) {
                $field_id  = $field['custom_id'] ?? $field['_id'] ?? '';
                $ph_tablet = $field['placeholder_tablet'] ?? '';
                $ph_mobile = $field['placeholder_mobile'] ?? '';

                if ( ! $field_id ) {
                    continue;
                }

                // Injeta nos inputs (email/text/tel/url/etc) e textareas
                $attrs = '';
                if ( $ph_tablet !== '' ) {
                    $attrs .= ' data-bit-placeholder-tablet="' . esc_attr( $ph_tablet ) . '"';
                }
                if ( $ph_mobile !== '' ) {
                    $attrs .= ' data-bit-placeholder-mobile="' . esc_attr( $ph_mobile ) . '"';
                }
                if ( $attrs === '' ) {
                    continue;
                }

                // Procura input/textarea/select com id="form-field-{field_id}" e injeta os data-attrs
                $content = preg_replace(
                    '/(<(?:input|textarea|select)[^>]*id="form-field-' . preg_quote( $field_id, '/' ) . '"[^>]*)(>)/',
                    '$1' . $attrs . '$2',
                    $content,
                    1
                );
            }
        }

        // 3. Injetar data-bit-form-name-tablet/mobile na div do widget pra o JS trocar o heading
        $name_tablet = $settings['form_name_tablet'] ?? '';
        $name_mobile = $settings['form_name_mobile'] ?? '';
        if ( $name_tablet !== '' || $name_mobile !== '' ) {
            $extra = '';
            if ( $name_tablet !== '' ) {
                $extra .= ' data-bit-form-name-tablet="' . esc_attr( $name_tablet ) . '"';
            }
            if ( $name_mobile !== '' ) {
                $extra .= ' data-bit-form-name-mobile="' . esc_attr( $name_mobile ) . '"';
            }
            $content = preg_replace(
                '/(class="[^"]*elementor-widget-form[^"]*"[^>]*?)(>)/',
                '$1' . $extra . '$2',
                $content,
                1
            );
        }

        return $content;
    }, 10, 2 );
```

- [ ] **Step 2: Validar sintaxe**

Run:
```bash
docker exec concertacao-dev-wordpress php -l \
  /var/www/html/wp-content/mu-plugins/bit-elementor-form-responsive.php
```

Expected: `No syntax errors detected`

- [ ] **Step 3: Limpar cache Elementor + flush+warmup CSS**

Run:
```bash
docker exec -u www-data concertacao-dev-wordpress wp \
  --url="https://cambrasmax.local:8484/" \
  elementor flush-css
docker exec -u www-data concertacao-dev-wordpress wp \
  --url="https://cambrasmax.local:8484/" \
  eval '(new \Elementor\Core\Files\CSS\Post(72234))->update();'
docker exec concertacao-dev-wordpress sh -c \
  'kill -USR2 $(pgrep -of "php-fpm: master" | head -1)'
```

Expected: 1ª linha imprime quantos foram deletados; 2ª e 3ª silenciosas.

- [ ] **Step 4: Inspecionar HTML renderizado**

Run:
```bash
curl -sk https://cambrasmax.local:8484/ | grep -oE 'elementor-widget-form[^"]*"' | head -3
curl -sk https://cambrasmax.local:8484/ | grep -oE 'data-bit-[a-z-]+="[^"]*"' | sort -u
```

Expected (sem ter ainda preenchido os controles tablet/mobile no editor — Task 5):
- 1ª linha: `elementor-widget-form bit-form-responsive"` aparece nos resultados.
- 2ª linha: vazia ou só com data-attrs que já foram preenchidos. Sem warnings, sem erros.

Se a classe `bit-form-responsive` NÃO aparecer, o regex do step 1 não casou — fazer dump de uma linha do HTML do widget e ajustar.

- [ ] **Step 5: Commit**

```bash
git add wordpress/wp-content/mu-plugins/bit-elementor-form-responsive.php
git commit -m "feat(form-responsive): filter de render injeta classe escopo + data-attrs responsivos"
```

---

## Task 4: CSS (deltas #3 a #8)

**Files:**
- Create: `wordpress/wp-content/mu-plugins/bit-elementor-form-responsive.css`
- Modify: `wordpress/wp-content/mu-plugins/bit-elementor-form-responsive.php` (enqueue)

- [ ] **Step 1: Criar o CSS escopado**

Conteúdo de `wordpress/wp-content/mu-plugins/bit-elementor-form-responsive.css`:

```css
/* BIT Elementor Form Responsive — escopo .bit-form-responsive */
/* Deltas #3 (layout), #4 (border-radius), #5 (background), #6 (cor placeholder), #7 (posição botão), #8 (largura botão) */

.elementor-widget-form.bit-form-responsive .elementor-field-textual,
.elementor-widget-form.bit-form-responsive select.elementor-field {
    background: transparent;
    border: 2px solid #fff;
    color: #fff;
    border-radius: 4px;
}

.elementor-widget-form.bit-form-responsive .elementor-field-textual::placeholder {
    color: rgba(255, 255, 255, 0.65);
}

.elementor-widget-form.bit-form-responsive button[type="submit"] {
    background: transparent;
    border: 2px solid #fff;
    color: #fff;
    font-weight: 700;
}

/* Tablet (≤1024px) — herda desktop por enquanto */

/* Mobile (≤767px) — pill branco vertical, botão centralizado */
@media (max-width: 767px) {
    .elementor-widget-form.bit-form-responsive .elementor-form-fields-wrapper {
        flex-direction: column;
        gap: 14px;
    }

    .elementor-widget-form.bit-form-responsive .elementor-field-group {
        width: 100% !important;
        max-width: 100% !important;
    }

    .elementor-widget-form.bit-form-responsive .elementor-field-textual,
    .elementor-widget-form.bit-form-responsive select.elementor-field {
        background: #fff;
        color: #0d3a2c;
        border: none;
        border-radius: 24px;
        padding: 12px 20px;
    }

    .elementor-widget-form.bit-form-responsive .elementor-field-textual::placeholder {
        color: rgba(127, 176, 136, 0.85);
    }

    .elementor-widget-form.bit-form-responsive .elementor-field-type-submit {
        display: flex;
        justify-content: center;
    }

    .elementor-widget-form.bit-form-responsive button[type="submit"] {
        width: auto;
        min-width: 30%;
        padding: 10px 32px;
    }
}
```

- [ ] **Step 2: Adicionar enqueue do CSS no mu-plugin**

Adicionar **dentro do callback de `plugins_loaded`** (depois do filter de `render_content`):

```php
    // Enqueue CSS+JS — só em páginas que tenham widget Form
    add_action( 'wp_enqueue_scripts', function () {
        wp_register_style(
            'bit-form-responsive',
            content_url( 'mu-plugins/bit-elementor-form-responsive.css' ),
            [],
            VERSION
        );
    } );

    // Hook de render — força enqueue do CSS quando o widget Form aparecer
    add_action( 'elementor/frontend/widget/before_render', function ( $widget ) {
        if ( $widget->get_name() === 'form' ) {
            wp_enqueue_style( 'bit-form-responsive' );
        }
    } );
```

- [ ] **Step 3: Validar sintaxe + CSS carregando**

Run:
```bash
docker exec concertacao-dev-wordpress php -l \
  /var/www/html/wp-content/mu-plugins/bit-elementor-form-responsive.php
curl -sk https://cambrasmax.local:8484/ | grep -oE 'bit-elementor-form-responsive\.css[^"]*' | head -1
```

Expected:
- `No syntax errors detected`
- `bit-elementor-form-responsive.css?ver=1.0.0`

- [ ] **Step 4: Inspeção visual em desktop (browser)**

Abrir `https://cambrasmax.local:8484/` em browser (≥1025px largura), scroll até o footer, verificar:
- Form desktop continua com inputs retangulares borda branca (não regrediu).
- Form mobile continua escondido (`hidden-mobile`).
- DevTools: confirmar que o CSS `.bit-form-responsive` está aplicado no widget (Computed style).

- [ ] **Step 5: Inspeção visual em mobile (DevTools responsive ≤767px)**

DevTools → device toolbar → iPhone SE (375px). Scroll até footer:
- Como o widget mobile original está escondido até Task 5 (unificação), o desktop continua aparecendo só que agora com layout column + inputs pill brancos (porque o CSS aplica via `@media`).

Se layout ficar quebrado (sobreposição, overflow), ajustar CSS e voltar ao Step 3.

- [ ] **Step 6: Commit**

```bash
git add wordpress/wp-content/mu-plugins/bit-elementor-form-responsive.{php,css}
git commit -m "feat(form-responsive): CSS escopado cobre 8 deltas + enqueue condicional"
```

---

## Task 5: JS — matchMedia troca placeholder por breakpoint

**Files:**
- Create: `wordpress/wp-content/mu-plugins/bit-elementor-form-responsive.js`
- Modify: `wordpress/wp-content/mu-plugins/bit-elementor-form-responsive.php` (enqueue do JS)

- [ ] **Step 1: Criar o JS**

Conteúdo de `wordpress/wp-content/mu-plugins/bit-elementor-form-responsive.js`:

```javascript
(function () {
    'use strict';

    var BREAKPOINTS = {
        mobile: '(max-width: 767px)',
        tablet: '(max-width: 1024px) and (min-width: 768px)'
    };

    function currentDevice() {
        if (window.matchMedia(BREAKPOINTS.mobile).matches) return 'mobile';
        if (window.matchMedia(BREAKPOINTS.tablet).matches) return 'tablet';
        return 'desktop';
    }

    function applyResponsiveText(form) {
        var device = currentDevice();

        // 1. Inputs/textareas — troca atributo placeholder
        var inputs = form.querySelectorAll('[data-bit-placeholder-tablet], [data-bit-placeholder-mobile]');
        inputs.forEach(function (el) {
            if (!el.dataset.bitPlaceholderDesktop) {
                el.dataset.bitPlaceholderDesktop = el.getAttribute('placeholder') || '';
            }
            var key = 'bitPlaceholder' + device.charAt(0).toUpperCase() + device.slice(1);
            var val = el.dataset[key] || el.dataset.bitPlaceholderDesktop;
            el.setAttribute('placeholder', val);

            // Para selects: troca o texto da primeira <option disabled selected>
            if (el.tagName === 'SELECT') {
                var emptyOpt = el.querySelector('option[disabled][selected], option[value=""][disabled]');
                if (emptyOpt) {
                    emptyOpt.textContent = val;
                }
            }
        });

        // 2. Form name (heading) — troca textContent do .elementor-field-group-html h2/h3/h4 ou .elementor-message-form-name
        var nameTablet = form.dataset.bitFormNameTablet;
        var nameMobile = form.dataset.bitFormNameMobile;
        var headingTarget = form.querySelector('.elementor-form-heading, .elementor-field-group-html h2, .elementor-field-group-html h3, .elementor-field-group-html h4');
        if (headingTarget) {
            if (!headingTarget.dataset.bitFormNameDesktop) {
                headingTarget.dataset.bitFormNameDesktop = headingTarget.textContent;
            }
            if (device === 'mobile' && nameMobile) {
                headingTarget.textContent = nameMobile;
            } else if (device === 'tablet' && nameTablet) {
                headingTarget.textContent = nameTablet;
            } else {
                headingTarget.textContent = headingTarget.dataset.bitFormNameDesktop;
            }
        }
    }

    function initAll() {
        document.querySelectorAll('.elementor-widget-form.bit-form-responsive').forEach(applyResponsiveText);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }

    // Re-aplica em resize / orientation change
    var resizeTimer;
    window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(initAll, 120);
    });
    window.addEventListener('orientationchange', initAll);
})();
```

- [ ] **Step 2: Adicionar enqueue do JS no mu-plugin**

Em `bit-elementor-form-responsive.php`, **dentro do `wp_enqueue_scripts` callback** (junto com o `wp_register_style`), adicionar:

```php
        wp_register_script(
            'bit-form-responsive',
            content_url( 'mu-plugins/bit-elementor-form-responsive.js' ),
            [],
            VERSION,
            true // footer
        );
```

E **dentro do `elementor/frontend/widget/before_render`** (junto com o `wp_enqueue_style`), adicionar:

```php
            wp_enqueue_script( 'bit-form-responsive' );
```

- [ ] **Step 3: Validar sintaxe + JS carregando**

Run:
```bash
docker exec concertacao-dev-wordpress php -l \
  /var/www/html/wp-content/mu-plugins/bit-elementor-form-responsive.php
curl -sk https://cambrasmax.local:8484/ | grep -oE 'bit-elementor-form-responsive\.js[^"]*' | head -1
```

Expected:
- `No syntax errors detected`
- `bit-elementor-form-responsive.js?ver=1.0.0`

- [ ] **Step 4: Validação manual — definir um placeholder mobile no editor**

1. Abrir `https://cambrasmax.local:8484/wp-admin/post.php?post=72234&action=elementor`
2. Clicar no widget Form do desktop, ir no field `form_email`, no controle Placeholder ativar Mobile (📱) e digitar `"TESTE MOBILE"`.
3. Update.
4. Limpar cache: `std cache-flush` (interativo) ou:
   ```bash
   docker exec -u www-data concertacao-dev-wordpress wp \
     --url="https://cambrasmax.local:8484/" cache flush
   docker exec -u www-data concertacao-dev-wordpress wp \
     --url="https://cambrasmax.local:8484/" elementor flush-css
   docker exec -u www-data concertacao-dev-wordpress wp \
     --url="https://cambrasmax.local:8484/" eval \
     '(new \Elementor\Core\Files\CSS\Post(72234))->update();'
   ```
5. Abrir browser ≥768px → input mostra "E-mail" (desktop).
6. DevTools responsive ≤767px → input mostra "TESTE MOBILE".

Se mobile não trocar, abrir Console e procurar erro JS. Se `data-bit-placeholder-mobile` não estiver no HTML, o problema está no filter PHP (Task 3) — voltar e debug.

- [ ] **Step 5: Reverter o placeholder de teste**

Voltar no editor e deletar `"TESTE MOBILE"` do mobile (deixar vazio — vai herdar do desktop). Update.

- [ ] **Step 6: Commit**

```bash
git add wordpress/wp-content/mu-plugins/bit-elementor-form-responsive.{php,js}
git commit -m "feat(form-responsive): JS matchMedia troca placeholder e heading por device"
```

---

## Task 6: Cópia canônica para server-tools/common

**Files:**
- Create: `~/scripts/server-tools/v2/docker-dev/common/mu-plugins/bit-elementor-form-responsive.{php,css,js}`

- [ ] **Step 1: Copiar os 3 arquivos**

Run:
```bash
cp wordpress/wp-content/mu-plugins/bit-elementor-form-responsive.php \
   /Users/dcambria/scripts/server-tools/v2/docker-dev/common/mu-plugins/
cp wordpress/wp-content/mu-plugins/bit-elementor-form-responsive.css \
   /Users/dcambria/scripts/server-tools/v2/docker-dev/common/mu-plugins/
cp wordpress/wp-content/mu-plugins/bit-elementor-form-responsive.js \
   /Users/dcambria/scripts/server-tools/v2/docker-dev/common/mu-plugins/
ls -la /Users/dcambria/scripts/server-tools/v2/docker-dev/common/mu-plugins/bit-elementor-form-responsive.*
```

Expected: 3 arquivos com tamanho >0.

- [ ] **Step 2: Confirmar MD5 idêntico**

Run:
```bash
for f in bit-elementor-form-responsive.{php,css,js}; do
  md5_site=$(md5 -q "wordpress/wp-content/mu-plugins/$f")
  md5_common=$(md5 -q "/Users/dcambria/scripts/server-tools/v2/docker-dev/common/mu-plugins/$f")
  echo "$f: site=$md5_site common=$md5_common $([ "$md5_site" = "$md5_common" ] && echo MATCH || echo MISMATCH)"
done
```

Expected: 3 linhas todas terminando em `MATCH`.

- [ ] **Step 3: Commit no repo server-tools**

```bash
cd /Users/dcambria/scripts/server-tools/v2
git status
git add docker-dev/common/mu-plugins/bit-elementor-form-responsive.{php,css,js}
git commit -m "feat(mu-plugins): bit-elementor-form-responsive v1.0.0 — canonical copy"
cd /Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao
```

Expected: 3 arquivos commitados no repo `server-tools`.

---

## Task 7: Script de unificação dos 2 widgets em 1 (com --dry-run)

**Files:**
- Create: `scripts/unify-footer-form.php`

- [ ] **Step 1: Criar o script PHP standalone (executável via `wp eval-file`)**

Conteúdo de `scripts/unify-footer-form.php`:

```php
<?php
/**
 * unify-footer-form.php — One-shot idempotente.
 *
 * Unifica os 2 widgets Form do template footer 72234 (containers
 * d1e32f6 desktop/tablet e 3e45cefe mobile) em UM único widget device-aware.
 *
 * Uso (via WP-CLI):
 *   docker exec -u www-data concertacao-dev-wordpress wp \
 *     --url="https://cambrasmax.local:8484/" \
 *     eval-file /var/www/html/wp-content/uploads/_scripts/unify-footer-form.php [APPLY]
 *
 * Sem o segundo argumento ("APPLY"), roda em DRY-RUN: imprime o diff esperado, não salva.
 * Com APPLY: aplica e salva (usa wp_slash + wp_json_encode).
 *
 * Multisite-safe: opera no blog 1 (raiz) onde o template footer 72234 vive.
 *
 * Spec: docs/superpowers/specs/2026-05-19-formulario-rodape-rdstation-design.md
 */

if ( ! defined( 'ABSPATH' ) ) {
    fwrite( STDERR, "Run via wp eval-file\n" );
    exit( 1 );
}

$apply = isset( $args[0] ) && strtoupper( $args[0] ) === 'APPLY';

$template_id        = 72234;
$container_desktop  = 'd1e32f6';   // widget Form a manter como base
$container_mobile   = '3e45cefe';  // widget Form a remover (após copiar metadados responsivos)
$widget_id_desktop  = 'e85e505';   // ID do Form widget dentro do container desktop
$widget_id_mobile   = '18af5b7';   // ID do Form widget dentro do container mobile

echo "─── unify-footer-form.php " . ( $apply ? '[APPLY MODE]' : '[DRY-RUN]' ) . " ───\n";

$raw = get_post_meta( $template_id, '_elementor_data', true );
if ( empty( $raw ) ) {
    echo "ERRO: _elementor_data vazio para template $template_id\n";
    exit( 1 );
}

$data = is_array( $raw ) ? $raw : json_decode( wp_unslash( $raw ), true );
if ( ! is_array( $data ) ) {
    echo "ERRO: _elementor_data não é JSON válido (json_last_error=" . json_last_error_msg() . ")\n";
    exit( 1 );
}

// Helper recursivo: encontra elemento por id e retorna referência mutável (& return)
function &bit_find_by_id( array &$nodes, string $id ) {
    $found = null;
    foreach ( $nodes as &$node ) {
        if ( ( $node['id'] ?? '' ) === $id ) {
            $found = &$node;
            return $found;
        }
        if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
            $sub = &bit_find_by_id( $node['elements'], $id );
            if ( $sub !== null ) {
                $found = &$sub;
                return $found;
            }
        }
    }
    return $found;
}

// Helper: remove elemento por id da árvore
function bit_remove_by_id( array &$nodes, string $id ) : bool {
    foreach ( $nodes as $i => &$node ) {
        if ( ( $node['id'] ?? '' ) === $id ) {
            array_splice( $nodes, $i, 1 );
            return true;
        }
        if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
            if ( bit_remove_by_id( $node['elements'], $id ) ) {
                return true;
            }
        }
    }
    return false;
}

$desktop_form = &bit_find_by_id( $data, $widget_id_desktop );
$mobile_form  = &bit_find_by_id( $data, $widget_id_mobile );

if ( $desktop_form === null ) {
    echo "ERRO: widget desktop $widget_id_desktop não encontrado\n";
    exit( 1 );
}
if ( $mobile_form === null ) {
    echo "AVISO: widget mobile $widget_id_mobile não encontrado — script provavelmente já rodou. Idempotente: nada a fazer.\n";
    exit( 0 );
}

// Copiar form_name do mobile para form_name_mobile do desktop
$desktop_settings = &$desktop_form['settings'];
$mobile_settings  =  $mobile_form['settings'];

$desktop_settings['form_name_mobile'] = $mobile_settings['form_name'] ?? '';
echo "+ form_name_mobile = " . json_encode( $desktop_settings['form_name_mobile'] ) . "\n";

// Para cada field do desktop, achar o equivalente no mobile (mesmo custom_id) e copiar placeholder/field_options_empty
if ( ! empty( $desktop_settings['form_fields'] ) && is_array( $desktop_settings['form_fields'] ) ) {
    foreach ( $desktop_settings['form_fields'] as $i => &$d_field ) {
        $cid = $d_field['custom_id'] ?? '';
        if ( ! $cid ) continue;

        // achar field equivalente no mobile — match por custom_id (form_regiao / form_email)
        // ATENÇÃO: mobile usa form_email_regiao para o select de Região; tratar como alias do form_regiao
        $mobile_alias = ( $cid === 'form_regiao' ) ? [ 'form_regiao', 'form_email_regiao' ] : [ $cid ];
        $m_match = null;
        foreach ( $mobile_settings['form_fields'] ?? [] as $m_field ) {
            if ( in_array( $m_field['custom_id'] ?? '', $mobile_alias, true ) ) {
                $m_match = $m_field;
                break;
            }
        }
        if ( ! $m_match ) continue;

        if ( ! empty( $m_match['placeholder'] ) && ( $d_field['placeholder'] ?? '' ) !== $m_match['placeholder'] ) {
            $d_field['placeholder_mobile'] = $m_match['placeholder'];
            echo "+ field[$cid].placeholder_mobile = " . json_encode( $m_match['placeholder'] ) . "\n";
        }
        if ( ! empty( $m_match['field_options_empty'] ) ) {
            $d_field['field_options_empty_mobile'] = $m_match['field_options_empty'];
            echo "+ field[$cid].field_options_empty_mobile = " . json_encode( $m_match['field_options_empty'] ) . "\n";
        }
        // CRUCIAL: o select Região tem "Região" como 1ª <option> real. Reescrever field_options removendo essa linha
        if ( $cid === 'form_regiao' && ! empty( $d_field['field_options'] ) ) {
            $opts = explode( "\n", $d_field['field_options'] );
            $first = trim( $opts[0] );
            if ( $first === 'Região' || $first === 'Região | Região' ) {
                array_shift( $opts );
                $d_field['field_options'] = implode( "\n", $opts );
                $d_field['field_options_empty'] = 'Região'; // placeholder via campo dedicado
                echo "+ field[$cid].field_options: removida primeira linha 'Região' (placeholder agora via field_options_empty)\n";
            }
        }
    }
    unset( $d_field );
}

// Remover restrição hide_mobile do container desktop (e do widget interno) para que apareça em todos os devices
$desktop_container = &bit_find_by_id( $data, 'd1e32f6' );
if ( $desktop_container && ! empty( $desktop_container['settings'] ) ) {
    foreach ( [ 'hide_mobile', 'hide_tablet', 'hide_desktop' ] as $k ) {
        if ( isset( $desktop_container['settings'][ $k ] ) && $desktop_container['settings'][ $k ] !== '' ) {
            echo "+ container d1e32f6: removendo $k=" . $desktop_container['settings'][ $k ] . "\n";
            $desktop_container['settings'][ $k ] = '';
        }
    }
}

// Remover o container mobile inteiro
if ( bit_remove_by_id( $data, $container_mobile ) ) {
    echo "+ container $container_mobile (mobile) REMOVIDO\n";
} else {
    echo "AVISO: container $container_mobile não encontrado para remoção\n";
}

if ( ! $apply ) {
    echo "\n[DRY-RUN] Nada salvo. Rerun com 'APPLY' como argumento para aplicar.\n";
    exit( 0 );
}

// APPLY: salvar com wp_slash + wp_json_encode ([[feedback_elementor_data_wp_slash_required]])
$encoded = wp_slash( wp_json_encode( $data ) );
$ok      = update_post_meta( $template_id, '_elementor_data', $encoded );
echo "\n[APPLY] update_post_meta retornou: " . var_export( $ok, true ) . "\n";

// Flush + warmup CSS ([[feedback_elementor_flush_css_warmup]])
if ( class_exists( '\Elementor\Plugin' ) ) {
    \Elementor\Plugin::$instance->files_manager->clear_cache();
    ( new \Elementor\Core\Files\CSS\Post( $template_id ) )->update();
    echo "+ Elementor: cache limpo + CSS regenerado para template $template_id\n";
}

echo "\n✓ Done. Validar: abrir https://cambrasmax.local:8484/ em desktop e mobile.\n";
```

- [ ] **Step 2: Copiar o script para a área montada no container**

```bash
mkdir -p wordpress/wp-content/uploads/_scripts
cp scripts/unify-footer-form.php wordpress/wp-content/uploads/_scripts/
ls -la wordpress/wp-content/uploads/_scripts/
```

Expected: arquivo lá.

- [ ] **Step 3: Validar sintaxe**

```bash
docker exec concertacao-dev-wordpress php -l \
  /var/www/html/wp-content/uploads/_scripts/unify-footer-form.php
```

Expected: `No syntax errors detected`

- [ ] **Step 4: DRY-RUN — verificar diff esperado**

```bash
docker exec -u www-data concertacao-dev-wordpress wp \
  --url="https://cambrasmax.local:8484/" \
  eval-file /var/www/html/wp-content/uploads/_scripts/unify-footer-form.php
```

Expected (saída do `[DRY-RUN]`):
- `+ form_name_mobile = "Inscreva-se para fazer parte da rede..."`
- `+ field[form_email].placeholder_mobile = "Insira seu melhor email"`
- `+ field[form_regiao].placeholder_mobile = ...` (se houver)
- `+ field[form_regiao].field_options: removida primeira linha 'Região' (placeholder agora via field_options_empty)`
- `+ container d1e32f6: removendo hide_mobile=true`
- `+ container 3e45cefe (mobile) REMOVIDO`
- `[DRY-RUN] Nada salvo.`

Se aparecer `ERRO:`, parar e investigar. Se aparecer `AVISO: widget mobile ... não encontrado — script provavelmente já rodou`, significa idempotência funcionando (já aplicado).

- [ ] **Step 5: APPLY**

```bash
docker exec -u www-data concertacao-dev-wordpress wp \
  --url="https://cambrasmax.local:8484/" \
  eval-file /var/www/html/wp-content/uploads/_scripts/unify-footer-form.php APPLY
```

Expected: mesmas linhas `+` do dry-run, terminando com:
- `[APPLY] update_post_meta retornou: true`
- `+ Elementor: cache limpo + CSS regenerado para template 72234`
- `✓ Done.`

- [ ] **Step 6: Reload PHP-FPM (garantir que OPcache não sirva render antigo)**

```bash
docker exec concertacao-dev-wordpress sh -c \
  'kill -USR2 $(pgrep -of "php-fpm: master" | head -1)'
```

- [ ] **Step 7: Confirmar via DB que o container mobile sumiu**

```bash
docker exec -u www-data concertacao-dev-wordpress wp \
  --url="https://cambrasmax.local:8484/" \
  eval 'echo strpos( get_post_meta(72234, "_elementor_data", true), "3e45cefe" ) === false ? "REMOVIDO_OK" : "AINDA_PRESENTE";'
```

Expected: `REMOVIDO_OK`

- [ ] **Step 8: Idempotência — rodar novamente o APPLY**

```bash
docker exec -u www-data concertacao-dev-wordpress wp \
  --url="https://cambrasmax.local:8484/" \
  eval-file /var/www/html/wp-content/uploads/_scripts/unify-footer-form.php APPLY
```

Expected: `AVISO: widget mobile 18af5b7 não encontrado — script provavelmente já rodou. Idempotente: nada a fazer.`

- [ ] **Step 9: Commit do script**

```bash
git add scripts/unify-footer-form.php
git commit -m "feat(footer-form): script unify-footer-form.php — dry-run + apply idempotente"
```

---

## Task 8: Validação visual + funcional

**Files:**
- (Sem nova edição — só verificação)

- [ ] **Step 1: Visual — desktop**

Abrir `https://cambrasmax.local:8484/` em browser (≥1025px), scroll até o footer.
Verificar:
- Heading "Cadastre-se para receber novidades" (texto desktop).
- 1 linha com 3 elementos: input E-mail (retangular borda branca) + select Região (idem) + botão ENVIAR.
- Não há mais 2 forms — só 1.

- [ ] **Step 2: Visual — tablet (≤1024px)**

DevTools → iPad (768px). Verificar mesmo comportamento desktop (herda).

- [ ] **Step 3: Visual — mobile (≤767px)**

DevTools → iPhone SE (375px). Verificar:
- Heading muda para "Inscreva-se para fazer parte da rede..." (se Task 5 + Task 7 funcionaram).
- Layout vertical: 3 elementos empilhados.
- Inputs pill brancos (border-radius 24px, background branco).
- Botão centralizado abaixo, fundo transparente borda branca.

Se heading NÃO trocou em mobile, é provável que o seletor do JS `headingTarget` não casou — abrir DevTools e ver onde está o texto. Ajustar o seletor na Task 5 step 1 e re-aplicar.

- [ ] **Step 4: Bug Região — submeter sem trocar o select**

1. Em desktop, preencher só o email (`teste-region@bit-bpo.com`) e clicar Enviar **sem trocar o select**.
2. Esperado: HTML5 validation bloqueia ("Por favor, selecione um item da lista") — `field_options_empty` renderiza `<option value="" disabled selected>` e `required=true` rejeita vazio.
3. Trocar para "Acre" (qualquer UF) e enviar.
4. Verificar via DB:
   ```bash
   docker exec -u www-data concertacao-dev-wordpress wp \
     --url="https://cambrasmax.local:8484/" \
     db query "SELECT id, form_name, created_at FROM wp_e_submissions ORDER BY id DESC LIMIT 1\G"
   docker exec -u www-data concertacao-dev-wordpress wp \
     --url="https://cambrasmax.local:8484/" \
     db query "SELECT key, value FROM wp_e_submissions_values WHERE submission_id = (SELECT MAX(id) FROM wp_e_submissions)\G"
   ```

Expected: `form_regiao` aparece com value `AC` (sigla, não "Região").

- [ ] **Step 5: Smoke /smoke (gates já existentes)**

Rodar o smoke completo do site para garantir que Parte 1 não regrediu nada:
```bash
/Users/dcambria/scripts/server-tools/v2/docker-dev/common/bin/docker-dev.sh wp \
  --url="https://cambrasmax.local:8484/" \
  cache flush
# E rodar o playwright smoke se houver script wrapper:
ls ~/scripts/testes/concertacao/  # ver se tem package.json com "smoke" script
```

Se o site tem skill `/smoke` instalada (mencionada no CLAUDE.md raiz), invocá-la para validar gates 26 (WPML orphan), 28 (stale paths) e 29 (form bypass header).

- [ ] **Step 6: Commit do estado (nenhuma alteração — só marca)**

Sem novos arquivos. Pular o commit deste step.

---

## Task 9: Spec Playwright pra regressão

**Files:**
- Create: `~/scripts/testes/concertacao/tests/08-footer-form-responsive.spec.js`

- [ ] **Step 1: Criar a spec seguindo o padrão dos vizinhos**

Conteúdo de `/Users/dcambria/scripts/testes/concertacao/tests/08-footer-form-responsive.spec.js`:

```javascript
'use strict';

const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');

try { require('dotenv').config({ path: path.join(__dirname, '..', '.env.local') }); } catch {}

const screenshotsDir = path.join(__dirname, '..', 'screenshots', 'footer-form');
test.beforeAll(() => { fs.mkdirSync(screenshotsDir, { recursive: true }); });

const HEADING_DESKTOP = /Cadastre-se para receber novidades/i;
const HEADING_MOBILE  = /Inscreva-se para fazer parte da rede/i;
const PLACEHOLDER_DESKTOP = /^E-mail$/;
const PLACEHOLDER_MOBILE  = /^Insira seu melhor email$/;

async function gotoHomeAndScrollFooter(page) {
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(800);
    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
    await page.waitForTimeout(400);
}

test.describe('Form do rodapé — unificado e device-aware', () => {

    test('desktop (1440x900): heading + placeholder + layout retângulo', async ({ browser }) => {
        const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
        const page = await context.newPage();
        await gotoHomeAndScrollFooter(page);

        const form = page.locator('.elementor-widget-form.bit-form-responsive').first();
        await expect(form).toBeVisible();

        // heading desktop
        await expect(form).toContainText(HEADING_DESKTOP);

        // placeholder desktop
        const emailInput = form.locator('input[type="email"]').first();
        await expect(emailInput).toHaveAttribute('placeholder', PLACEHOLDER_DESKTOP);

        await form.screenshot({ path: path.join(screenshotsDir, 'desktop.png') });
        await context.close();
    });

    test('mobile (375x812): heading + placeholder + layout pill', async ({ browser }) => {
        const context = await browser.newContext({ viewport: { width: 375, height: 812 }, isMobile: true });
        const page = await context.newPage();
        await gotoHomeAndScrollFooter(page);

        const form = page.locator('.elementor-widget-form.bit-form-responsive').first();
        await expect(form).toBeVisible();

        // heading mobile (trocado pelo JS)
        await expect(form).toContainText(HEADING_MOBILE);

        const emailInput = form.locator('input[type="email"]').first();
        await expect(emailInput).toHaveAttribute('placeholder', PLACEHOLDER_MOBILE);

        // CSS: border-radius pill no input
        const radius = await emailInput.evaluate(el => parseInt(getComputedStyle(el).borderRadius, 10));
        expect(radius).toBeGreaterThanOrEqual(20);

        await form.screenshot({ path: path.join(screenshotsDir, 'mobile.png') });
        await context.close();
    });

    test('Região: primeira <option> é placeholder disabled, NÃO opção válida', async ({ browser }) => {
        const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
        const page = await context.newPage();
        await gotoHomeAndScrollFooter(page);

        const select = page.locator('.elementor-widget-form.bit-form-responsive select').first();
        await expect(select).toBeVisible();

        const firstOption = select.locator('option').first();
        const isDisabled = await firstOption.evaluate(el => el.disabled);
        const value      = await firstOption.evaluate(el => el.value);
        expect(isDisabled).toBe(true);
        expect(value).toBe('');

        // O texto visível deve ser "Região"
        await expect(firstOption).toHaveText('Região');

        await context.close();
    });
});
```

- [ ] **Step 2: Rodar a spec apontando para DEV**

```bash
cd /Users/dcambria/scripts/testes/concertacao
BASE_URL=https://cambrasmax.local:8484 npx playwright test tests/08-footer-form-responsive.spec.js
```

Expected: 3 testes PASS, screenshots em `~/scripts/testes/concertacao/screenshots/footer-form/`.

Se algum falhar:
- Heading mobile fail → checar Task 5 step 4 (JS troca heading).
- Placeholder fail → checar Task 3 (data-attrs) + Task 5 (JS aplica).
- Região first-option fail → checar Task 7 step 4 (DRY-RUN tinha "removida primeira linha 'Região'"?).

- [ ] **Step 3: Commit no repo de testes**

```bash
cd /Users/dcambria/scripts/testes/concertacao
git status
git add tests/08-footer-form-responsive.spec.js
git commit -m "test(footer-form): spec Playwright valida desktop/mobile + bug Região"
```

---

## Task 10: Bump version, doc inline, PR

**Files:**
- Modify: `wordpress/wp-content/mu-plugins/bit-elementor-form-responsive.php` (já está em 1.0.0 — sem bump na 1ª entrega).
- (Sem outros arquivos novos.)

- [ ] **Step 1: Push da branch + abrir PR (concertacao)**

```bash
cd /Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao
git push -u origin feat/footer-form-unified-part1
gh pr create --title "feat(footer-form): unifica os 2 widgets em 1 device-aware (Parte 1)" --body "$(cat <<'EOF'
## Summary
- Mu-plugin `bit-elementor-form-responsive` v1.0.0 torna `form_name`/`placeholder`/`field_options_empty` device-aware no Form do Elementor Pro
- CSS escopado em `.bit-form-responsive` cobre 8 deltas visuais (layout, border-radius, background, posição botão...)
- JS `matchMedia` troca placeholder/heading por breakpoint
- Script `scripts/unify-footer-form.php` unifica os 2 widgets do template 72234 em 1 (idempotente, com `--dry-run`)
- Corrige bug: `<option>Região</option>` não é mais enviado como valor (vira `field_options_empty` placeholder)
- Corrige bug: `custom_id` divergente (`form_regiao` vs `form_email_regiao`) — agora único

## Spec
docs/superpowers/specs/2026-05-19-formulario-rodape-rdstation-design.md (commit 144202eaca)

## Test plan
- [ ] DEV: visual desktop ≥1025px (heading "Cadastre-se..." + inputs retângulo)
- [ ] DEV: visual mobile ≤767px (heading "Inscreva-se..." + inputs pill)
- [ ] DEV: spec Playwright `08-footer-form-responsive.spec.js` PASS (3/3)
- [ ] DEV: submit funcional — `wp_e_submissions_values.form_regiao` recebe sigla UF, NÃO "Região"
- [ ] DEV: smoke `/smoke` gates 26/28/29 verdes
- [ ] HML: rodar mesmas validações em ambiente HML após phase3 deploy
- [ ] PROD: deploy com backup do template 72234 + script de unificação idempotente

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

Expected: URL do PR retornada.

- [ ] **Step 2: PR paralelo no server-tools (cópia canônica)**

```bash
cd /Users/dcambria/scripts/server-tools/v2
git status                            # confirmar só docker-dev/common/mu-plugins/bit-elementor-form-responsive.* foram commitados
git checkout -b feat/bit-elementor-form-responsive
git push -u origin feat/bit-elementor-form-responsive
gh pr create --title "feat(mu-plugins): bit-elementor-form-responsive v1.0.0" --body "$(cat <<'EOF'
## Summary
Canonical copy do mu-plugin `bit-elementor-form-responsive` v1.0.0 estreado no projeto concertacao. Estende o widget Form do Elementor Pro tornando `form_name`/`placeholder`/`field_options_empty` device-aware (Desktop/Tablet/Mobile).

## Sites usando
- concertacao (Parte 1 do form unificado do rodapé)

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

Expected: URL do PR retornada.

- [ ] **Step 3: Cleanup local — remover script da pasta de uploads (não vai pro git)**

```bash
cd /Users/dcambria/scripts/server-tools/v2/docker-dev/sites/concertacao
rm wordpress/wp-content/uploads/_scripts/unify-footer-form.php
rmdir wordpress/wp-content/uploads/_scripts 2>/dev/null || true
# o arquivo canonical fica em scripts/unify-footer-form.php (versionado)
```

---

## Validação final consolidada

Antes de mergear o PR (Parte 1):

- [ ] **Desktop ≥1025px**: heading "Cadastre-se...", inputs retângulo borda branca, botão à direita. (Visual + Playwright PASS)
- [ ] **Tablet 768–1024px**: visual herda desktop (sem regressão).
- [ ] **Mobile ≤767px**: heading "Inscreva-se...", inputs pill branco, botão centralizado abaixo. (Visual + Playwright PASS)
- [ ] **Bug Região**: select renderiza `<option value="" disabled selected>Região</option>`; submit sem trocar é rejeitado pelo HTML5; com UF marcada, DB recebe a sigla.
- [ ] **Bug custom_id**: `wp_e_submissions_values` registra `form_regiao` único (não mais `form_email_regiao`).
- [ ] **Idempotência**: rerun do `unify-footer-form.php APPLY` reporta "Idempotente: nada a fazer".
- [ ] **Smoke**: `/smoke` (se disponível) gates 26/28/29 verdes.
- [ ] **Backup do template 72234** versionado em `backups/templates/72234-elementor-data-pre-unify-*.json` (rollback humanly readable).
- [ ] **PRs abertos**: 1 no `concertacao`, 1 no `server-tools`, ambos com test plan.

Quando todos passarem em DEV, sequência para HML e PROD será orquestrada via phase3 normal (mu-plugin + script vão junto no `wordpress/wp-content/mu-plugins/` + `scripts/`). O script `unify-footer-form.php` precisa ser rodado em HML e PROD após o deploy do mu-plugin — adicionar nota no PR.
