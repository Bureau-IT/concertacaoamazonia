---
description: Bateria smoke completa Concertação em prod vs green — home, atlas PT+EN, espiral, eventos, formularios. Use "/smoke" pós-deploy ou "valida tudo no concertacao".
allowed-tools: mcp__MCP_DOCKER__browser_close mcp__MCP_DOCKER__browser_run_code
disable-model-invocation: false
---

Bateria smoke pós-deploy. Testa 5 páginas críticas + 2 formulários em prod e green + 1 paridade prod/dev:

| # | Página | URL |
|---|--------|-----|
| 1 | Home | `https://concertacaoamazonia.com.br/` |
| 2 | Atlas PT | `https://concertacaoamazonia.com.br/cultura/atlas-cultural-das-amazonias/` |
| 3 | Atlas EN | `https://concertacaoamazonia.com.br/cultura/en/cultural-atlas-of-the-amazon/` |
| 4 | Espiral | `https://concertacaoamazonia.com.br/conhecimento/espiral-de-conhecimento/` |
| 5 | Eventos | `https://concertacaoamazonia.com.br/eventos-calendario/` |
| 6 | **Newsletter footer (na home)** | seletor `form[name=Newsletter]` ou `<form>` com `<input placeholder*="email">` no footer |
| 7 | **Contato** | `https://concertacaoamazonia.com.br/contato/` |
| 8 | **Agenda Integradora — paridade prod/dev** | `https://concertacaoamazonia.com.br/agenda-integradora/` vs `https://concertacao.bureau-it.com/agenda-integradora/` |

## Workflow

Para cada página, faça:
1. `browser_close`
2. `browser_run_code` com **prod** (sem header)
3. `browser_close`
4. `browser_run_code` com **green** (X-Test-Green:true via context)

**Páginas 6 e 7 (formulários) — fluxo atual (a partir de 2026-05-14):**
1. **prod**: snippet "submit real — PROD" com header `X-BIT-Smoke-Token` válido (mu-plugin `bit-smoke-recaptcha-bypass.php` v1.1.0+). Espera response header `X-BIT-Smoke-Bypass: OK` e success message visível. Marker injetado: `__bit_smoke_test=1`.
2. **prod (teste negativo)**: snippet "submit real — PROD" com token INVÁLIDO. Espera `X-BIT-Smoke-Bypass: NOOP` ou erro reCAPTCHA — garante que bypass não está aberto pra qualquer um.
3. **green**: snippet "submit real — GREEN" com `X-Test-Green: true` + `X-BIT-Smoke-Token` válido (quando green estiver vivo). Mesmas asserções.

**Fallback (token não disponível no ambiente):** snippet deprecado "validação de formulário — PROD" valida só presença/renderização — não exercita pipeline POST.

**Cobertura multisite:** rodar para blog 1 (`https://concertacaoamazonia.com.br/`) E blog 2 (`https://concertacaoamazonia.com.br/cultura/`). O footer Elementor é compartilhado mas configs WPML/destinos podem diferir.

### Snippet base por estado (páginas 1-5)

```js
async (page) => {
  const ctx = page.context();
  await ctx.setExtraHTTPHeaders(HEADER_VAL);
  await ctx.clearCookies();

  await page.goto('URL_AQUI?cb=' + Date.now(), { waitUntil: 'domcontentloaded', timeout: 30000 });

  return await page.evaluate(async () => {
    const r = await fetch('/check-ec2.php?cb=' + Date.now(), { cache: 'no-store' });
    const text = await r.text();
    const hostname = (text.match(/Hostname:\s*([^\n<]+)/) || [])[1]?.trim() || 'unknown';

    const jsf = window.JetSmartFilterSettings?.props || {};
    const allFoundPosts = [];
    for (const provider in jsf) for (const qid in jsf[provider]) {
      allFoundPosts.push({k: provider + '/' + qid, v: jsf[provider][qid].found_posts});
    }
    const cssLinks = Array.from(document.querySelectorAll('link[rel="stylesheet"]'));

    return {
      hostname,
      title: document.title,
      html_size: document.documentElement.outerHTML.length,
      stylesheets: cssLinks.length,
      uploads_elementor_css: cssLinks.filter(l => /\/uploads\/(?:sites\/\d+\/)?elementor\//.test(l.href)).length,
      elementor_cache_404s: 'set via response listener',
      jet_max_found_posts: Math.max(0, ...allFoundPosts.map(o => o.v || 0)),
      listing_items: document.querySelectorAll('.jet-listing-grid__item').length,
    };
  });
}
```

### Bypass de reCAPTCHA para submit em PROD/GREEN

Para submeter formulários reais em ambientes com reCAPTCHA v3 invisible (Elementor Pro Forms), o mu-plugin `bit-smoke-recaptcha-bypass.php` aceita header `X-BIT-Smoke-Token` autenticado contra a constante `BIT_SMOKE_BYPASS_TOKEN` no `wp-config.php`. Quando válido, remove os callbacks `Recaptcha_Handler::validation` e `Recaptcha_V3_Handler::validation`, mantém Honeypot + validações de campo ativos, e injeta `is_smoke_test=1` no record.

**Token por ambiente** (não comitar, ler do `wp-config.php` do ambiente):
- DEV: `cat wordpress/wp-config.php | grep BIT_SMOKE_BYPASS_TOKEN`
- PROD: SSH `concertacaoamazonia.com.br-prod-sa` — **OBRIGATÓRIO usar `sudo`** (wp-config.php é restrito ao `www-data`; sem `sudo` o grep retorna vazio em vez de "permission denied", causando falso negativo "constante ausente" e bloqueando os submits reais):
  ```bash
  ssh concertacaoamazonia.com.br-prod-sa "sudo grep BIT_SMOKE_BYPASS_TOKEN /var/www/concertacaoamazonia.com.br/wp-config.php"
  # Alternativa equivalente (mesma autorização):
  ssh concertacaoamazonia.com.br-prod-sa "sudo -u www-data wp --path=/var/www/concertacaoamazonia.com.br config get BIT_SMOKE_BYPASS_TOKEN"
  ```

Quando rodar o snippet "submit real — PROD" ou "submit real — GREEN", substitua `BIT_SMOKE_TOKEN_AQUI` pelo token do ambiente alvo.

Spec: `docs/superpowers/specs/2026-05-14-smoke-recaptcha-bypass-design.md`

### Snippet validação de formulário — PROD (páginas 6 e 7, sem header) — DEPRECADO em favor do "submit real — PROD" abaixo

Detecta presença e renderização correta. **NÃO submete** o form em prod. Use quando o bypass token não estiver configurado no ambiente alvo.

```js
async (page) => {
  const ctx = page.context();
  await ctx.setExtraHTTPHeaders(HEADER_VAL);
  await ctx.clearCookies();

  await page.goto('URL_AQUI?cb=' + Date.now(), { waitUntil: 'networkidle', timeout: 45000 });
  await page.waitForTimeout(1500);

  return await page.evaluate(() => {
    // Detectar formulários Elementor + JetEngine
    const forms = Array.from(document.querySelectorAll('form.elementor-form, form.jet-form, form[name]'));
    const out = forms.map(f => {
      const fields = Array.from(f.querySelectorAll('input, select, textarea')).map(el => ({
        type: el.type || el.tagName.toLowerCase(),
        name: el.name || el.id || '?',
        required: !!el.required,
        placeholder: el.placeholder || null,
      }));
      const submitBtn = f.querySelector('button[type=submit], input[type=submit], .elementor-button[type=submit]');
      const action = f.action || '';
      return {
        form_name: f.getAttribute('name') || f.id || '?',
        action: action.replace(location.origin, ''),
        method: (f.method || 'POST').toUpperCase(),
        fields_count: fields.filter(f => !['hidden','submit'].includes(f.type)).length,
        fields: fields.filter(f => !['hidden','submit'].includes(f.type)).slice(0, 8),
        submit_visible: submitBtn ? getComputedStyle(submitBtn).display !== 'none' : false,
        submit_label: submitBtn?.innerText?.trim() || submitBtn?.value || null,
      };
    });
    return { form_count: forms.length, forms: out };
  });
}
```

### Snippet submit real — PROD (páginas 6 e 7, com `X-BIT-Smoke-Token`)

Submete o form em prod via header de bypass reCAPTCHA. O mu-plugin `bit-smoke-recaptcha-bypass.php` v1.1.0+ valida o token via `hash_equals` contra `BIT_SMOKE_BYPASS_TOKEN` do `wp-config.php`, injeta `__bit_smoke_test=1` no record via filter `actions_before` (chega aos destinos email/webhook), e emite header `X-BIT-Smoke-Bypass: OK|FAILED|NOOP`. Marcador rastreável `smoke+<ts>@bureau-it.com`. Retry 1x backoff 2s.

**Gates do snippet:**
- `bypass_header === 'OK'` no GET inicial (confirma mu-plugin ativo e token válido)
- `submit_ok === true` após click + retry
- Se `bypass_header === 'FAILED'`: drift do Elementor Pro (priority mudou). Bloquear deploy e investigar.
- Se `bypass_header === 'NOOP'`: token errado ou constante ausente.

```js
async (page) => {
  const ctx = page.context();
  await ctx.setExtraHTTPHeaders({ 'X-BIT-Smoke-Token': 'BIT_SMOKE_TOKEN_AQUI' });
  await ctx.clearCookies();

  // Listener para capturar header X-BIT-Smoke-Bypass da response inicial
  let bypass_header = null;
  page.on('response', (resp) => {
    if (resp.url().startsWith('URL_AQUI') && bypass_header === null) {
      bypass_header = resp.headers()['x-bit-smoke-bypass'] || 'absent';
    }
  });

  await page.goto('URL_AQUI?cb=' + Date.now(), { waitUntil: 'networkidle', timeout: 45000 });
  await page.waitForTimeout(1500);
  await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
  await page.waitForTimeout(1500);

  // Gate 1: header tem que vir OK. NOOP/FAILED/absent = bloqueia submit.
  if (bypass_header !== 'OK') {
    return {
      submit_ok: false,
      submit_reason: 'bypass_header_not_ok',
      submit_message: `X-BIT-Smoke-Bypass=${bypass_header} (esperado OK). Mu-plugin nao deployado, token errado ou drift do Elementor Pro.`,
      bypass_header,
    };
  }

  const ts = Date.now();
  const marker = {
    email: `smoke+${ts}@bureau-it.com`,
    nome: `SMOKE TEST ${ts}`,
    msg: 'Automated smoke test from /smoke command — safe to delete.',
  };

  const submit = async (m) => {
    return await page.evaluate(async (m) => {
      const forms = Array.from(document.querySelectorAll('form'));
      const footerForm = forms.find(f =>
        !!f.closest('footer, .elementor-location-footer, [data-elementor-type="footer"]') &&
        (f.getAttribute('name') === 'Footer do Site' || f.querySelector('input[name*="form_email_desk"], input[name*="form_email"]'))
      );
      // Para página Contato (não-footer), fallback ao primeiro form não-footer
      const form = footerForm || forms.find(f => !f.closest('footer, .elementor-location-footer'));
      if (!form) return { ok: false, reason: 'form_not_found' };

      const fill = (selector, value) => {
        const el = form.querySelector(selector);
        if (el) {
          el.value = value;
          el.dispatchEvent(new Event('input',  { bubbles: true }));
          el.dispatchEvent(new Event('change', { bubbles: true }));
          return true;
        }
        return false;
      };
      fill('input[type=email], input[name*=email i], input[placeholder*=email i]', m.email);
      fill('input[name*=nome i], input[placeholder*=nome i], input[name*=name i]', m.nome);
      fill('textarea, input[name*=mensagem i], input[name*=message i]', m.msg);

      const sel = form.querySelector('select');
      if (sel && sel.options.length > 1) {
        sel.selectedIndex = 1;
        sel.dispatchEvent(new Event('change', { bubbles: true }));
      }

      const btn = form.querySelector('button[type=submit], input[type=submit], .elementor-button[type=submit], button.elementor-button');
      if (!btn) return { ok: false, reason: 'submit_btn_not_found' };

      const formContainer = form.closest('.elementor-widget-form, .elementor-element') || form.parentElement;
      btn.click();

      const deadline = Date.now() + 25000;
      while (Date.now() < deadline) {
        const success =
          formContainer?.querySelector('.elementor-message-success, .elementor-message.elementor-message-success') ||
          document.querySelector('.elementor-message-success, .jet-form-builder-message--success');
        const error =
          formContainer?.querySelector('.elementor-message-danger, .elementor-message.elementor-message-danger') ||
          document.querySelector('.elementor-message-danger, .jet-form-builder-message--error');
        if (success) return { ok: true,  message: success.innerText.trim().slice(0, 200) };
        if (error)   return { ok: false, reason: 'error_message', message: error.innerText.trim().slice(0, 200) };
        await new Promise(r => setTimeout(r, 250));
      }
      return { ok: false, reason: 'timeout_25s' };
    }, m);
  };

  let result = await submit(marker);
  if (!result.ok && result.reason !== 'error_message') {
    await page.waitForTimeout(2000);
    await page.reload({ waitUntil: 'networkidle' });
    await page.waitForTimeout(1500);
    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
    await page.waitForTimeout(1500);
    result = await submit(marker);
    result.retry = true;
  }

  return {
    submit_ok: result.ok,
    submit_reason: result.reason || null,
    submit_message: result.message || null,
    submit_retry_used: !!result.retry,
    bypass_header,
    marker,
  };
}
```

**Diagnóstico por `bypass_header`:**
- `OK` — mu-plugin ativo, token bate, callbacks reCAPTCHA removidos. Submit deveria funcionar.
- `FAILED` — token bate mas mu-plugin não encontrou callbacks reCAPTCHA pra remover. Drift do Elementor Pro (priority/classe mudou após update). Bloquear deploy, atualizar mu-plugin.
- `NOOP` — token errado, constante ausente, ou header não chegou ao PHP. Conferir constante via SSH **com `sudo`** (wp-config.php é restrito ao `www-data`): `sudo grep BIT_SMOKE_BYPASS_TOKEN /var/www/concertacaoamazonia.com.br/wp-config.php` — sem `sudo` o grep retorna vazio em vez de "permission denied" e o operador conclui (falsamente) que a constante está ausente.
- `absent` — mu-plugin não está instalado/ativo. Conferir: `ls -la /var/www/concertacaoamazonia.com.br/wp-content/mu-plugins/bit-smoke-recaptcha-bypass.php`.

**Teste negativo obrigatório:** rodar o snippet trocando `BIT_SMOKE_TOKEN_AQUI` por um token claramente inválido (`'invalid'.repeat(10)`). Esperado: `bypass_header=NOOP` E `submit_ok=false` com erro reCAPTCHA. Se passar, o bypass está aberto pra qualquer um — incidente de segurança.

### Snippet submit real — GREEN (páginas 6 e 7, com `X-Test-Green:true`)

Submete o form com marcador rastreável (`smoke+<ts>@bureau-it.com`) e valida resposta visual. Retry 1x com backoff 2s. Falha nas 2 tentativas dispara gate.

```js
async (page) => {
  const ctx = page.context();
  await ctx.setExtraHTTPHeaders({ 'X-Test-Green': 'true' });
  await ctx.clearCookies();

  await page.goto('URL_AQUI?cb=' + Date.now(), { waitUntil: 'networkidle', timeout: 45000 });
  await page.waitForTimeout(1500);

  const ts = Date.now();
  const marker = {
    email: `smoke+${ts}@bureau-it.com`,
    nome: `SMOKE TEST ${ts}`,
    msg: 'Automated smoke test from /smoke command — safe to delete.',
  };

  // GUARD anti-poluição prod (incidente 2026-05-02): se green ALB rule X-Test-Green
  // não existe (ex: green desligado pós-cutover), CloudFront ignora o header e
  // roteia para prod-blue. Submeter aqui poluiria CRM/Newsletter de produção com
  // marcadores `smoke+<ts>@bureau-it.com`. Antes de qualquer fill+click, validar
  // hostname via /check-ec2.php — só prossegue se contém "hml".
  const checkGreenLive = async () => {
    return await page.evaluate(async () => {
      try {
        const r = await fetch('/check-ec2.php?cb=' + Date.now(), {
          cache: 'no-store',
          signal: AbortSignal.timeout(5000),
        });
        const text = await r.text();
        const hostname = (text.match(/Hostname:\s*([^\n<]+)/) || [])[1]?.trim() || 'unknown';
        return { hostname, is_green: /hml/i.test(hostname) };
      } catch (e) {
        return { hostname: 'unknown', is_green: false, error: (e.message || '?').slice(0, 120) };
      }
    });
  };

  const submit = async (m) => {
    return await page.evaluate(async (m) => {
      const form = document.querySelector('form.elementor-form, form.jet-form, form[name]');
      if (!form) return { ok: false, reason: 'form_not_found' };

      const fill = (selector, value) => {
        const el = form.querySelector(selector);
        if (el) {
          el.value = value;
          el.dispatchEvent(new Event('input',  { bubbles: true }));
          el.dispatchEvent(new Event('change', { bubbles: true }));
        }
      };
      fill('input[type=email], input[name*=email i], input[placeholder*=email i]', m.email);
      fill('input[name*=nome i], input[placeholder*=nome i], input[name*=name i]', m.nome);
      fill('textarea, input[name*=mensagem i], input[name*=message i]', m.msg);

      const sel = form.querySelector('select');
      if (sel && sel.options.length > 1) sel.selectedIndex = 1;

      const btn = form.querySelector('button[type=submit], input[type=submit], .elementor-button[type=submit]');
      if (!btn) return { ok: false, reason: 'submit_btn_not_found' };
      btn.click();

      const deadline = Date.now() + 15000;
      while (Date.now() < deadline) {
        const success = document.querySelector(
          '.elementor-message-success, .jet-form-builder-message--success, .elementor-message.elementor-message-success'
        );
        const error = document.querySelector(
          '.elementor-message-danger, .jet-form-builder-message--error, .elementor-message.elementor-message-danger'
        );
        if (success) return { ok: true,  message: success.innerText.trim().slice(0, 200) };
        if (error)   return { ok: false, reason: 'error_message', message: error.innerText.trim().slice(0, 200) };
        await new Promise(r => setTimeout(r, 250));
      }
      return { ok: false, reason: 'timeout_15s' };
    }, m);
  };

  // 1ª checagem: estamos mesmo no green?
  let guard = await checkGreenLive();
  if (!guard.is_green) {
    // Retry 1x com backoff: green ALB rule pode ter sido recém-aplicada
    await page.waitForTimeout(2000);
    await page.reload({ waitUntil: 'networkidle' });
    await page.waitForTimeout(1500);
    guard = await checkGreenLive();
    if (!guard.is_green) {
      return {
        submit_ok: false,
        submit_reason: 'green_offline',
        submit_message: `hostname "${guard.hostname}" does not contain hml — green is offline, refusing to submit on prod`,
        submit_retry_used: true,
        marker: null,
      };
    }
  }

  let result = await submit(marker);

  if (!result.ok) {
    await page.waitForTimeout(2000);
    await page.reload({ waitUntil: 'networkidle' });
    await page.waitForTimeout(1500);
    // Re-validar guard de hostname antes da 2ª tentativa (green pode ter caído entre tentativas)
    const guard2 = await checkGreenLive();
    if (!guard2.is_green) {
      return {
        submit_ok: false,
        submit_reason: 'green_offline',
        submit_message: `hostname "${guard2.hostname}" does not contain hml on retry — green is offline, refusing to submit on prod`,
        submit_retry_used: true,
        marker: null,
      };
    }
    result = await submit(marker);
    result.retry = true;
  }

  return {
    submit_ok: result.ok,
    submit_reason: result.reason || null,
    submit_message: result.message || null,
    submit_retry_used: !!result.retry,
    marker,
  };
}
```

## Apresentar matriz

### Páginas 1-5 (conteúdo)

```
| Página      | PROD hostname | PROD items | PROD jet_max | GREEN hostname | GREEN items | GREEN jet_max | Status |
|-------------|---------------|-----------:|-------------:|----------------|------------:|--------------:|--------|
| Home        | blue          |        N   |          N   | hml            |         N   |          N    | ✅     |
| Atlas PT    | blue          |        1   |          1   | hml            |         4   |        656    | ✅     |
| Atlas EN    | blue          |        1   |          1   | hml            |         4   |        656    | ✅     |
| Espiral     | blue          |       12   |         12   | hml            |        12   |         12    | ✅     |
| Eventos     | blue          |        N   |          N   | hml            |         N   |          N    | ✅     |
```

### Páginas 6-7 (formulários)

```
| Página              | PROD form | PROD fields | PROD submit | GREEN submit_ok | retry | marker email                    | Status |
|---------------------|----------:|------------:|-------------|-----------------|-------|---------------------------------|--------|
| Newsletter (home)   |     1     |     2       | "ENVIAR"    | ✅              | no    | smoke+1730487123@bureau-it.com  | ✅     |
| Contato             |     1     |     N       | "ENVIAR"    | ✅              | yes   | smoke+1730487145@bureau-it.com  | ✅     |
```

PROD valida apenas presença/campos/label do submit. GREEN executa submit real com marcador rastreável (`smoke+<ts>@bureau-it.com`) e valida resposta visual de sucesso/erro.

## Fase 7.5 — Paridade prod/dev de páginas do menu (DEV = source-of-truth)

Compara renderização da MESMA página em PROD (`concertacaoamazonia.com.br`) e DEV (`concertacao.bureau-it.com` via tunnel). Detecta divergências de DOM/heading/imagens/altura que indicam regressão de deploy mesmo quando HTML retornado parece idêntico.

**DEV é a fonte da verdade**: a lista de páginas é descoberta enumerando TODAS as `page` publicadas no DEV via WP-CLI. Páginas que existem em DEV mas não em PROD = pendência de deploy. Páginas que renderizam diferente = regressão.

**Cobertura obrigatória (atualizado 2026-06-22): TODAS as `page` publicadas do site, NÃO só as dos menus.** Mudança do Dani: antes o Snippet 1 varria só os menus/submenus (~47 paths). Agora enumera 100% das pages publicadas nos 2 blogs via `wp post list --post_type=page --post_status=publish` (DEV) — ~68 paths (blog1 ~55 + blog2 ~14). Isso pega pages órfãs (fora de menu) que tinham cache CF stale ou CSS quebrado sem ninguém notar (ex: páginas de release/relatório linkadas só por posts). O Snippet 1 é **WP-CLI (Bash), não DOM scrape** — enumeração autoritativa e completa, imune a submenu escondido. Escopo: `post_type=page` (posts/eventos/CPTs têm gates próprios — 40/42-45/48/51).

### Snippet 1 — Enumerar TODAS as pages publicadas em DEV (executar 1x, via WP-CLI/Bash)

> Rodar via `Bash` (não Playwright). DEV = fonte da verdade. Saída: lista de paths
> (relativos) de todas as `page` publicadas dos 2 blogs, normalizados com trailing
> slash, prontos para o Snippet 2.

```bash
DEVCT="concertacao-dev-wordpress"
DEV_HOST="https://cambrasmax.local:8484"     # base do dev (multisite)
OUT=/tmp/smoke_all_pages.txt
: > "$OUT"

# Blog 1 (raiz) + Blog 2 (/cultura/) — todas as pages publicadas.
# Extrai o pathname da permalink (independe do host: dev/tunnel/prod).
for url_base in "$DEV_HOST" "$DEV_HOST/cultura/"; do
  docker exec -u www-data "$DEVCT" wp --url="$url_base" \
    post list --post_type=page --post_status=publish --field=url 2>/dev/null \
  | grep -E '^https?://' \
  | sed -E 's#^https?://[^/]+##' \
  | sed -E 's#([^/])$#\1/#' \
  >> "$OUT"
done

# Páginas críticas fora-de-page (incidentes) que devem SEMPRE entrar mesmo se
# não forem post_type=page no momento (ex: embed Spotify CSP — incidente 2026-05-18):
printf '%s\n' '/cultura/porosidades/' >> "$OUT"

# Normalizar: únicos, ordenados, sem vazios.
sort -u "$OUT" | grep -E '^/' > "${OUT}.sorted" && mv "${OUT}.sorted" "$OUT"
echo "Total pages descobertas: $(wc -l < "$OUT")"
cat "$OUT"
```

Esperado: ~68 paths (blog1 ~55 + blog2 ~14, PT+EN). Se vier < 50, a enumeração
falhou (dev fora do ar / multisite mal resolvido) — investigar antes de prosseguir,
NÃO rodar o Snippet 2 com lista parcial (daria falsos "pendência de deploy").
O array `urls` para o Snippet 2 = conteúdo de `/tmp/smoke_all_pages.txt`.

> **Nota de mapeamento PT↔EN:** as pages EN do dev têm slug traduzido pelo WPML
> (ex: `/en/what-we-are/`), então a enumeração já traz os paths EN reais — não
> precisa hardcodar o mapa de slugs. O Snippet 2 compara cada path dev↔prod 1:1.

### Snippet 2 — Comparar PROD vs DEV para CADA path descoberto

Receba o array `urls` do Snippet 1 e itere. **Substitua `PATHS_AQUI` pela lista descoberta.**

```js
async (page) => {
  // Lista de paths descoberta pelo Snippet 1 (substituir antes de rodar)
  const paths = PATHS_AQUI; // ex: ['/atuacao/', '/conhecimento/', ...]

  const measurePage = async (url) => {
    await page.context().clearCookies();
    // Fix 4 — Capturar erros de console + 4xx/5xx em recursos.
    // Detecta JS errors (TypeError, ReferenceError) e assets quebrados que nao
    // aparecem na altura/headings mas indicam regressao funcional.
    const consoleErrors = [];
    const cspErrors = [];
    const failedResources = [];
    const consoleHandler = (msg) => {
      if (msg.type() === 'error') {
        const t = msg.text();
        // CSP errors são sempre bugs reais — bucket separado, sem whitelist.
        // Servidor manda header CSP, browser bloqueia, plugin nenhum reverte.
        if (/violates the following Content Security Policy directive/i.test(t)) {
          cspErrors.push(t.slice(0, 240));
        } else {
          consoleErrors.push(t.slice(0, 160));
        }
      }
    };
    const responseHandler = (resp) => {
      if (resp.status() >= 400 && resp.status() < 600) {
        const u = resp.url();
        // Ignorar assets externos (CDN, ads, analytics) — só interessa o proprio site
        if (u.includes('concertacaoamazonia.com.br') || u.includes('concertacao.bureau-it.com') || u.includes('cambrasmax.local')) {
          failedResources.push(`${resp.status()} ${u.substring(u.lastIndexOf('/')+1).slice(0, 60)}`);
        }
      }
    };
    page.on('console', consoleHandler);
    page.on('response', responseHandler);

    try {
      const resp = await page.goto(url + '?cb=' + Date.now(), { waitUntil: 'networkidle', timeout: 45000 });
      await page.waitForTimeout(1500);

      // Fix 3 — Aceitar cookie banner Complianz (PT/EN) consistentemente em ambos
      // Banner aparecendo em um e não em outro causa altura diferente (falso positivo gate 13).
      await page.evaluate(() => {
        const btns = Array.from(document.querySelectorAll('button, a'));
        const acceptBtn = btns.find(b => /aceitar|accept|allow/i.test(b.innerText || '') && b.offsetWidth > 0);
        if (acceptBtn) acceptBtn.click();
      });
      await page.waitForTimeout(500);

      // Fix 2 — Forçar lazy-load: scroll até o final, voltar ao topo, aguardar imagens
      // Sem isso, imagens fora do viewport reportam naturalWidth=0 → diff espúria entre prod/dev.
      await page.evaluate(async () => {
        await new Promise(resolve => {
          const totalHeight = document.body.scrollHeight;
          let scrolled = 0;
          const step = 500;
          const timer = setInterval(() => {
            window.scrollBy(0, step);
            scrolled += step;
            if (scrolled >= totalHeight) {
              clearInterval(timer);
              window.scrollTo(0, 0);
              resolve();
            }
          }, 100);
        });
      });
      await page.waitForTimeout(1000); // tempo extra para lazy-loaded images decodificarem

      const data = await page.evaluate((status) => {
        // Fix 1 — Normalizar headings: whitespace múltiplo → 1 espaço; NBSP → espaço.
        // JSON.stringify ficava sensível a \n, espaço duplo, NBSP entre prod/dev.
        const headings = Array.from(document.querySelectorAll('h1, h2, h3'))
          .map(h => h.innerText.trim()
            .replace(/\s+/g, ' ')        // normaliza whitespace múltiplo
            .replace(/ /g, ' ')     // NBSP → espaço normal
            .slice(0, 80))
          .filter(Boolean);
        const downloadBtns = Array.from(document.querySelectorAll('a, button'))
          .filter(b => /download/i.test(b.innerText || ''))
          .map(b => b.innerText.trim()
            .replace(/\s+/g, ' ')
            .replace(/ /g, ' ')
            .slice(0, 40));
        const imgsRendered = Array.from(document.querySelectorAll('img'))
          .filter(i => i.naturalWidth >= 100).length;
        return {
          status,
          page_height: document.body.scrollHeight,
          headings,
          heading_count: headings.length,
          download_btns: downloadBtns,
          download_btn_count: downloadBtns.length,
          rendered_images: imgsRendered,
          elementor_sections: document.querySelectorAll('.elementor-section, .e-con').length,
        };
      }, resp ? resp.status() : 0);

      return {
        ...data,
        console_errors: consoleErrors,
        console_error_count: consoleErrors.length,
        csp_errors: cspErrors,
        csp_error_count: cspErrors.length,
        failed_resources: failedResources,
        failed_resource_count: failedResources.length,
      };
    } catch (e) {
      return {
        error: (e.message || '?').slice(0, 120),
        status: 0,
        console_errors: consoleErrors,
        csp_errors: cspErrors,
        failed_resources: failedResources,
      };
    } finally {
      page.off('console', consoleHandler);
      page.off('response', responseHandler);
    }
  };

  const results = [];
  for (const path of paths) {
    const prod = await measurePage('https://concertacaoamazonia.com.br' + path);
    const dev  = await measurePage('https://concertacao.bureau-it.com' + path);

    if (prod.error || dev.error || !prod.headings || !dev.headings) {
      results.push({
        path,
        prod_status: prod.status,
        dev_status: dev.status,
        prod_error: prod.error || null,
        dev_error: dev.error || null,
        verdict: 'ERROR',
      });
      continue;
    }

    const headingsMatch = JSON.stringify(prod.headings) === JSON.stringify(dev.headings);
    const downloadsMatch = JSON.stringify(prod.download_btns) === JSON.stringify(dev.download_btns);
    const heightDiffPct = Math.round(Math.abs(prod.page_height - dev.page_height) / Math.max(prod.page_height, dev.page_height) * 100);
    const imagesDiffPct = Math.round(Math.abs(prod.rendered_images - dev.rendered_images) / Math.max(prod.rendered_images, dev.rendered_images, 1) * 100);
    const sectionsDiff = prod.elementor_sections - dev.elementor_sections;

    // Verdict por gates da seção 13
    const fails = [];
    if (!headingsMatch)              fails.push('headings');
    if (!downloadsMatch)             fails.push('downloads');
    if (heightDiffPct > 20)          fails.push(`height-${heightDiffPct}%`);
    if (imagesDiffPct > 40)          fails.push(`images-${imagesDiffPct}%`);
    if (Math.abs(sectionsDiff) > 2)  fails.push(`sections-${sectionsDiff}`);
    // Fix 4 — Console errors e failed_resources sao por-ambiente.
    // PROD com console_errors > 0 ou failed_resources > 0 = FAIL.
    // DEV com errors mas PROD sem = WARN (dev pode ter SES lockdown, JQMIGRATE etc).
    if ((prod.console_error_count || 0) > 0)   fails.push(`console-prod=${prod.console_error_count}`);
    if ((prod.failed_resource_count || 0) > 0) fails.push(`assets-prod=${prod.failed_resource_count}`);
    if ((dev.console_error_count || 0) > (prod.console_error_count || 0))
                                               fails.push(`console-dev=${dev.console_error_count}`);
    // Gate dedicado CSP: erros de Content Security Policy nunca podem ser whitelistados.
    // Servidor envia header CSP, browser bloqueia, plugin nenhum reverte → sempre bug real.
    if ((prod.csp_error_count || 0) > 0) fails.push(`csp-prod=${prod.csp_error_count}`);
    if ((dev.csp_error_count || 0) > 0)  fails.push(`csp-dev=${dev.csp_error_count}`);

    results.push({
      path,
      prod_height: prod.page_height,
      dev_height: dev.page_height,
      height_diff_pct: heightDiffPct,
      prod_h: prod.heading_count,
      dev_h: dev.heading_count,
      headings_match: headingsMatch,
      prod_btns: prod.download_btn_count,
      dev_btns: dev.download_btn_count,
      downloads_match: downloadsMatch,
      prod_imgs: prod.rendered_images,
      dev_imgs: dev.rendered_images,
      images_diff_pct: imagesDiffPct,
      sections_diff: sectionsDiff,
      prod_console_errors: prod.console_errors || [],
      dev_console_errors: dev.console_errors || [],
      prod_csp_errors: prod.csp_errors || [],
      dev_csp_errors: dev.csp_errors || [],
      prod_failed_resources: prod.failed_resources || [],
      dev_failed_resources: dev.failed_resources || [],
      verdict: fails.length === 0 ? 'PASS' : `FAIL: ${fails.join(', ')}`,
    });
  }

  return {
    total_paths: paths.length,
    pass_count: results.filter(r => r.verdict === 'PASS').length,
    fail_count: results.filter(r => r.verdict.startsWith('FAIL')).length,
    error_count: results.filter(r => r.verdict === 'ERROR').length,
    results,
  };
}
```

### Apresentar matriz de paridade

Ordenar por verdict (FAIL e ERROR primeiro). Truncar paths longos a 40 chars.

```
| Path                                     | Status PROD/DEV | H prod/dev | Btns prod/dev | Imgs prod/dev | Δheight% | Δimg% | Δsec | Veredito |
|------------------------------------------|------------------|------------|---------------|---------------|---------:|------:|------|----------|
| /agenda-integradora/                     | 200/200          |   7/7      |     7/7       |    12/12      |    1%    |   0%  |   0  | ✅ PASS  |
| /conhecimento/espiral-de-conhecimento/   | 200/200          |   3/3      |     0/0       |    18/18      |    0%    |   0%  |   0  | ✅ PASS  |
| /atuacao/grupos-de-trabalho/             | 200/200          |   5/8      |     2/3       |     6/9       |   12%    |  33%  |  -2  | 🚨 FAIL: headings, images-33% |
| /en/activities/projetos-estruturantes/   | 404/200          |    -       |      -        |      -        |     -    |   -   |   -  | 🚨 ERROR: prod 404, dev OK (pendência de deploy) |
```

### Cobertura típica esperada

Após enumeração no DEV (Snippet 1, WP-CLI), espera-se **~68 paths** = TODAS as
`page` publicadas dos 2 blogs (não só as do menu):
- **blog1 (~55):** raiz `/`, `/sobre-nos/*`, `/atuacao/*`, `/conhecimento/*`,
  `/cultura/*`, `/amazoniapossivel/`, `/agenda-integradora/`, `/agenda-geral/`,
  `/amazonia-legal-em-dados/`, `/termos-e-condicoes/`, `/aviso-de-privacidade/`,
  páginas de release/relatório fora-de-menu, e os equivalentes EN (`/en/*`).
- **blog2 /cultura/ (~14):** `/cultura/`, `/cultura/linha-do-tempo/`,
  `/cultura/atlas-cultural-das-amazonias/`, `/cultura/galeria/`, `/cultura/porosidades/`,
  exposições, e EN (`/cultura/en/*`).

Se a enumeração trouxer < 50 paths, algo falhou (dev fora do ar / multisite mal
resolvido) — investigar antes de rodar o Snippet 2 (lista parcial → falsos
"pendência de deploy"). A contagem exata flutua conforme pages são criadas/despublicadas.

## Fase 7.6 — Gestão de cookies (Complianz)

Valida que o plugin Complianz GDPR está renderizando o banner de consent corretamente, que botões respondem, e que após "aceitar tudo" os scripts de tracking carregam (Google Analytics, GTM, YouTube embeds, RDStation).

### Snippet 0 — Multisite check (rodar PRIMEIRO)

Complianz é Network Active. O banner DEVE aparecer em ambos os blogs (raiz `/` e `/cultura/`). Se aparecer só em um, configuração do plugin foi feita por blog em vez de network.

```js
async (page) => {
  const audit = async (url) => {
    await page.context().clearCookies();
    await page.goto(url + '?cb=' + Date.now(), { waitUntil: 'networkidle', timeout: 30000 });
    await page.waitForTimeout(3500); // Complianz inicializa via JS — não DOMContentLoaded sync
    const data = await page.evaluate(() => {
      const banner = document.querySelector('.cmplz-cookiebanner, #cmplz-cookiebanner-container, [class*="cmplz-banner"]');
      const html = document.documentElement.outerHTML;
      const cmplzScripts = Array.from(document.querySelectorAll('script[src]'))
        .filter(s => /cmplz|complianz/i.test(s.src)).length;
      // css_file da config inline do Complianz (substituir {banner_id}/{type} pelo real)
      const m = html.match(/css_file"\s*:\s*"([^"]+)"/);
      let css_file = m ? m[1].replace(/\\\//g, '/') : null;
      // Custom = serve de uploads/complianz ou uploads/sites/N/complianz. Fallback = plugins/complianz-gdpr/.../defaults/
      const css_is_custom = !!css_file && /\/uploads\/(?:sites\/\d+\/)?complianz\//.test(css_file);
      const css_is_fallback = !!css_file && /\/plugins\/complianz-gdpr\//.test(css_file);
      return {
        url: location.href,
        banner_visible: banner ? getComputedStyle(banner).display !== 'none' : false,
        has_accept: !!document.querySelector('.cmplz-accept, [data-cmplz-action="accept"]'),
        has_deny:   !!document.querySelector('.cmplz-deny, [data-cmplz-action="deny"]'),
        cmplz_html_count: (html.match(/cmplz-banner|cmplz-cookiebanner|cmplz-accept|cmplz-deny/gi) || []).length,
        cmplz_scripts_loaded: cmplzScripts,
        window_complianz: typeof window.complianz !== 'undefined',
        css_file,
        css_is_custom,
        css_is_fallback,
      };
    });
    // Gate 52b: o CSS customizado do banner serve 200? (resolver {banner_id}->1, {type}->optin)
    if (data.css_file) {
      const realUrl = data.css_file.replace('{banner_id}', '1').replace('{type}', 'optin');
      try {
        const r = await page.evaluate(async (u) => {
          const resp = await fetch(u, { cache: 'no-store' });
          const ct = (resp.headers.get('content-type') || '').toLowerCase();
          const txt = await resp.text();
          return { status: resp.status, is_css: ct.includes('text/css'), has_cmplz_rules: /cmplz-cookiebanner/.test(txt) };
        }, realUrl);
        data.css_http = r.status;
        data.css_served_ok = r.status === 200 && r.is_css && r.has_cmplz_rules;
      } catch (e) { data.css_http = 0; data.css_served_ok = false; }
    }
    return data;
  };
  return {
    blog1_root:    await audit('https://concertacaoamazonia.com.br/'),
    blog2_cultura: await audit('https://concertacaoamazonia.com.br/cultura/'),
    blog2_atlas:   await audit('https://concertacaoamazonia.com.br/cultura/atlas-cultural-das-amazonias/'),
  };
}
```

### Apresentar matriz multisite

```
| Local              | banner | accept | deny | scripts | window.complianz | css custom | css 200 | Status |
|--------------------|--------|--------|------|--------:|------------------|-----------|---------|--------|
| Blog 1 / (raiz)    | ✅     | ✅     | ✅   |   N     | ✅               | ✅        | ✅      | ✅     |
| Blog 2 /cultura/   | ✅     | ✅     | ✅   |   N     | ✅               | ✅        | ✅      | ✅     |
| Blog 2 Atlas       | ✅     | ✅     | ✅   |   N     | ✅               | ✅        | ✅      | ✅     |
```

🚨 **FAIL multisite** se qualquer dos blogs mostra `banner_visible === false`. Ação: verificar `wp_options.cmplz_options` (blog 1) E `wp_2_options.cmplz_options` (blog 2). Multisite com Complianz Network Active geralmente exige config por subsite.

🚨 **Gate 52 — CSS customizado do banner (incidente blue-green 2026-06-22)**: o banner aparece VISÍVEL com botões mas SEM estilo (bullets vazios, layout cru) quando o CSS gerado do banner falta e o Complianz cai no fallback default do plugin. `banner_visible` sozinho NÃO pega isso (banner está lá). Sub-gates por blog:
- **52a** `css_is_fallback === true` (HIGH) — `css_file` aponta para `/plugins/complianz-gdpr/.../defaults/banner-{type}.css` em vez de `/uploads/(sites/N/)?complianz/css/`. O CSS gerado do banner não existe nesse blog. **MULTISITE:** cada blog tem seu CSS próprio — blog 1 em `uploads/complianz/css/`, blog 2 em `uploads/sites/2/complianz/css/`. Corrigir um blog NÃO corrige o outro.
- **52b** `css_served_ok === false` (HIGH) — o CSS customizado referenciado não serve 200/text/css com regras `.cmplz-cookiebanner`. No padrão CF-OAC valida via CloudFront (uploads vêm do S3; o nginx faz `return 444` p/ uploads do disco → ALB direto dá 502, ESPERADO).

**Fix:** regenerar o CSS no contexto de CADA blog + sync S3 + CF invalidate:
```bash
# blog 1
wp eval '$bs=cmplz_get_cookiebanners(); foreach($bs as $b){ (new cmplz_cookiebanner($b->ID))->generate_css(); }'
# blog 2 (--url do subsite; criar dir sites/2/complianz/css antes)
mkdir -p .../uploads/sites/2/complianz/css && chown www-data:www-data ...
wp --url=https://FQDN/cultura/ eval '$bs=cmplz_get_cookiebanners(); foreach($bs as $b){ (new cmplz_cookiebanner($b->ID))->generate_css(); }'
aws s3 sync .../uploads/complianz/css/ s3://BUCKET/green/uploads/complianz/css/ --content-type text/css
aws s3 sync .../uploads/sites/2/complianz/css/ s3://BUCKET/green/uploads/sites/2/complianz/css/ --content-type text/css
aws cloudfront create-invalidation --distribution-id E2F1QD7E7YOYEB --paths '/' '/cultura/' '/wp-content/uploads/*complianz/css/*'
```
Memória: [[project_complianz_banner_css_missing_green]].

### Snippet — Complianz cookie flow

```js
async (page) => {
  await page.context().clearCookies();
  await page.goto('https://concertacaoamazonia.com.br/?cb=' + Date.now(), { waitUntil: 'networkidle', timeout: 30000 });
  await page.waitForTimeout(2000);

  // 1. Banner deve estar visivel inicialmente
  const initialState = await page.evaluate(() => {
    const banner = document.querySelector('.cmplz-cookiebanner, #cmplz-cookiebanner-container, .cmplz-show, [class*="cmplz-banner"]');
    const acceptBtn = document.querySelector('.cmplz-accept, button.cmplz-accept-all, [data-cmplz-action="accept"]');
    const denyBtn = document.querySelector('.cmplz-deny, button.cmplz-deny-all, [data-cmplz-action="deny"]');
    const settingsBtn = document.querySelector('.cmplz-settings, button.cmplz-view-preferences, [data-cmplz-action="view-preferences"]');
    return {
      banner_visible: banner ? getComputedStyle(banner).display !== 'none' && getComputedStyle(banner).visibility !== 'hidden' : false,
      banner_text: banner ? banner.innerText.slice(0, 200) : null,
      has_accept: !!acceptBtn,
      accept_label: acceptBtn?.innerText?.trim() || null,
      has_deny: !!denyBtn,
      deny_label: denyBtn?.innerText?.trim() || null,
      has_settings: !!settingsBtn,
      cookies_set_pre_accept: document.cookie.split(';').filter(c => c.trim().startsWith('cmplz_')).length,
    };
  });

  // 2. Clicar em "aceitar tudo" e validar cookies + scripts carregam
  await page.evaluate(() => {
    const btn = document.querySelector('.cmplz-accept, button.cmplz-accept-all, [data-cmplz-action="accept"]');
    if (btn) btn.click();
  });
  await page.waitForTimeout(2500); // tempo para scripts assincronos carregarem

  const afterAccept = await page.evaluate(() => {
    const banner = document.querySelector('.cmplz-cookiebanner, #cmplz-cookiebanner-container, [class*="cmplz-banner"]');
    const cookies = document.cookie.split(';').map(c => c.trim());
    const cmplzCookies = cookies.filter(c => c.startsWith('cmplz_'));
    // Detectar scripts marketing carregados
    const scripts = Array.from(document.querySelectorAll('script[src]')).map(s => s.src);
    return {
      banner_hidden_after_accept: banner ? getComputedStyle(banner).display === 'none' || getComputedStyle(banner).visibility === 'hidden' : true,
      cmplz_cookies_set: cmplzCookies.length,
      cmplz_cookies_sample: cmplzCookies.slice(0, 5),
      gtm_loaded: scripts.some(s => /googletagmanager|gtag/.test(s)),
      ga_loaded: scripts.some(s => /google-analytics|ga\.js|analytics\.js/.test(s)),
      youtube_loaded: scripts.some(s => /youtube\.com\/iframe_api/.test(s)),
      rd_loaded: scripts.some(s => /d335luupugsy2\.cloudfront|rdstation/.test(s)),
    };
  });

  // 3. Reset → testar "negar tudo"
  await page.context().clearCookies();
  await page.goto('https://concertacaoamazonia.com.br/?cb=' + Date.now(), { waitUntil: 'networkidle', timeout: 30000 });
  await page.waitForTimeout(2000);
  await page.evaluate(() => {
    const btn = document.querySelector('.cmplz-deny, button.cmplz-deny-all, [data-cmplz-action="deny"]');
    if (btn) btn.click();
  });
  await page.waitForTimeout(2000);

  const afterDeny = await page.evaluate(() => {
    const banner = document.querySelector('.cmplz-cookiebanner, #cmplz-cookiebanner-container, [class*="cmplz-banner"]');
    const cookies = document.cookie.split(';').map(c => c.trim());
    const cmplzCookies = cookies.filter(c => c.startsWith('cmplz_'));
    const scripts = Array.from(document.querySelectorAll('script[src]')).map(s => s.src);
    return {
      banner_hidden_after_deny: banner ? getComputedStyle(banner).display === 'none' || getComputedStyle(banner).visibility === 'hidden' : true,
      cmplz_cookies_set: cmplzCookies.length,
      gtm_blocked: !scripts.some(s => /googletagmanager|gtag/.test(s)),
      ga_blocked: !scripts.some(s => /google-analytics|ga\.js|analytics\.js/.test(s)),
      youtube_blocked: !scripts.some(s => /youtube\.com\/iframe_api/.test(s)),
    };
  });

  return { initialState, afterAccept, afterDeny };
}
```

### Apresentar matriz Complianz

```
| Estado            | banner_visible | accept | deny | gtm | ga  | youtube | cookies | Status |
|-------------------|----------------|--------|------|-----|-----|---------|---------|--------|
| Pré-consent       | true           | OK     | OK   | -   | -   | -       | 0       | ✅     |
| Pós-aceitar tudo  | hidden         | -      | -    | ✅  | ✅  | ✅      | 4+      | ✅     |
| Pós-negar tudo    | hidden         | -      | -    | 🚫  | 🚫  | 🚫      | ?       | ✅     |
```

### Gates Complianz

🚨 **FAIL** se:
- `initialState.banner_visible === false` — banner não aparece (privacy compliance broken)
- `initialState.has_accept === false` ou `has_deny === false` — botões essenciais ausentes (LGPD/GDPR exigem ambos)
- `afterAccept.banner_hidden_after_accept === false` — banner não some após clicar
- `afterAccept.gtm_loaded === false` E `afterAccept.ga_loaded === false` — analytics não carrega após consent (perde tracking)
- `afterDeny.gtm_blocked === false` ou `afterDeny.ga_blocked === false` — tracking dispara mesmo após negar (violação LGPD)

## Fase 7.7 — Google Tag Manager (mu-plugin bit-gtm)

Valida que o mu-plugin `bit-gtm.php` (canonico em `docker-dev/common/mu-plugins/`) injeta o snippet GTM no `<head>` em produção. Plugin lê constante `GTM_CONTAINER_ID` do `wp-config.php` e só ativa quando `WP_ENVIRONMENT_TYPE = 'production'`.

### Snippet — GTM injection check

```js
async (page) => {
  await page.context().clearCookies();
  await page.goto('https://concertacaoamazonia.com.br/?cb=' + Date.now(), { waitUntil: 'networkidle', timeout: 30000 });
  await page.waitForTimeout(2000);

  return await page.evaluate(() => {
    const html = document.documentElement.outerHTML;

    // 1. Snippet inline no <head>: detectar via comentário "Google Tag Manager"
    const head_snippet = /<!-- Google Tag Manager -->/.test(html);
    const head_id_match = html.match(/googletagmanager\.com\/gtm\.js\?id=(GTM-[A-Z0-9]+)/);

    // 2. Noscript após <body>: detectar via iframe ns.html
    const body_noscript = /<iframe[^>]*googletagmanager\.com\/ns\.html\?id=GTM-/i.test(html);
    const body_id_match = html.match(/googletagmanager\.com\/ns\.html\?id=(GTM-[A-Z0-9]+)/);

    // 3. dataLayer inicializado
    const datalayer = typeof window.dataLayer !== 'undefined' && Array.isArray(window.dataLayer);
    const datalayer_events = datalayer ? window.dataLayer.length : 0;

    // 4. Script gtm.js carregado (rede)
    const scripts = Array.from(document.querySelectorAll('script[src]')).map(s => s.src);
    const gtm_script_loaded = scripts.some(s => /googletagmanager\.com\/gtm\.js/.test(s));

    // 5. Container ID consistente entre head e body
    const head_id = head_id_match ? head_id_match[1] : null;
    const body_id = body_id_match ? body_id_match[1] : null;
    const ids_match = head_id && body_id && head_id === body_id;

    return {
      head_snippet_present: head_snippet,
      head_container_id: head_id,
      body_noscript_present: body_noscript,
      body_container_id: body_id,
      ids_consistent: ids_match,
      datalayer_initialized: datalayer,
      datalayer_events_count: datalayer_events,
      gtm_script_loaded,
    };
  });
}
```

### Apresentar matriz GTM

```
| Verificacao                   | Esperado    | Real           | Status |
|-------------------------------|-------------|----------------|--------|
| <head> snippet                | true        | true           | ✅     |
| <head> container_id           | GTM-XXX     | GTM-PPHN5B6    | ✅     |
| <body> noscript               | true        | true           | ✅     |
| Container IDs consistentes    | true        | true           | ✅     |
| dataLayer inicializado        | true        | true           | ✅     |
| dataLayer events              | >= 1        | 5              | ✅     |
| gtm.js carregado (rede)       | true        | true           | ✅     |
```

### Gates GTM

🚨 **FAIL** se:
- `head_snippet_present === false` — snippet não injetado em prod (verificar `WP_ENVIRONMENT_TYPE='production'` no wp-config + `GTM_CONTAINER_ID` definido)
- `body_noscript_present === false` — fallback noscript ausente (acessibilidade + crawler tracking)
- `ids_consistent === false` — IDs do head e body divergem (configuração corrompida)
- `datalayer_initialized === false` E `gtm_script_loaded === false` — GTM não carrega no browser
- Após Complianz "Negar": GTM continua carregando — violação LGPD (testar em conjunto com Fase 7.6)

## Fase 7.8 — Saúde dos caches e Redis (prod)

Valida client-side que as 4 camadas de cache estão funcionais. Origem do gate: incidente
2026-05-02 (espiral 502 BAD GATEWAY) descobriu que **plugin redis-cache estava ativo mas
inerte** porque o drop-in `wp-content/object-cache.php` não existia, e `WP_REDIS_PREFIX='hml:'`
estava em produção (drift de HML). Sintoma: DBSIZE=0, listing JetEngine custava 13.7s cold
(vs 5.6s pós-fix, vs 5ms warm). Esta fase pega DROP-IN AUSENTE, CACHE INERTE e WARMUP VAZIO
sem precisar de SSH ou endpoint custom no servidor.

As 4 camadas validadas:

1. **Object cache (Redis via drop-in)** — bypass com cookie de logged-in deve ter HTML diferente
2. **Page cache (WP Rocket)** — 2ª visita consecutiva tem TTFB <100ms e cache header
3. **Edge cache (CloudFront)** — `x-cache: Hit from cloudfront` em pelo menos 50% dos hits
4. **Browser cache (assets estáticos)** — CSS/JS com `cache-control: max-age=...`

### Snippet — Cache health (rodar SOMENTE em PROD, sem header X-Test-Green)

```js
async (page) => {
  const ctx = page.context();
  await ctx.setExtraHTTPHeaders({});
  await ctx.clearCookies();

  const targetUrl = 'https://concertacaoamazonia.com.br/conhecimento/espiral-de-conhecimento/';

  // 1) Object cache (drop-in) — request HEAD ao arquivo. 200 = drop-in instalado.
  // Se o drop-in não existe, nginx retorna 404 (não cai no PHP porque é arquivo inexistente).
  // Resposta 403 indica que existe mas restringido — também conta como instalado.
  const dropInProbe = await page.evaluate(async () => {
    try {
      const r = await fetch('/wp-content/object-cache.php', { method: 'HEAD', cache: 'no-store' });
      return { status: r.status, content_length: r.headers.get('content-length') };
    } catch (e) { return { status: 0, error: (e.message || '?').slice(0, 80) }; }
  });

  // 2) Page cache (WP Rocket): 1ª request (warm-up) → 2ª request (medição)
  // 2ª deve ter TTFB <100ms server-side e/ou header de hit visível.
  const measurePage = async (cacheBust) => {
    const t0 = Date.now();
    const url = targetUrl + '?cb=' + cacheBust;
    const resp = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 30000 });
    const ttfb_ms = Date.now() - t0;
    const headers = resp ? resp.headers() : {};
    return {
      status: resp ? resp.status() : 0,
      ttfb_ms,
      cf_cache_status: headers['cf-cache-status'] || null,
      x_cache: headers['x-cache'] || null,
      wp_rocket_cache: headers['x-wp-rocket-cache'] || null,
      age: headers['age'] ? parseInt(headers['age'], 10) : null,
      cache_control: headers['cache-control'] || null,
    };
  };

  // Stable cb (mesmo valor 2x) — força CF a servir do cache se chave existir
  const stableCb = 'cache-health-' + Math.floor(Date.now() / 60000);
  const firstHit = await measurePage(stableCb);
  await page.waitForTimeout(800);
  const secondHit = await measurePage(stableCb);

  // 3) Edge cache (CloudFront): comparar Hit/Miss entre 1ª e 2ª request com mesma chave de cache
  const cfHitOnSecond = (secondHit.x_cache || '').toLowerCase().includes('hit')
                     || (secondHit.cf_cache_status || '').toLowerCase().includes('hit');

  // 4) Bypass com cookie de logged-in: WP Rocket DEVE bypassar cache → TTFB maior + sem
  // header x-cache: Hit. Se TTFB for ~igual entre cookie/no-cookie, drop-in/page cache estão off.
  const bypassUrl = 'https://concertacaoamazonia.com.br/conhecimento/espiral-de-conhecimento/?_bypass=' + Date.now();
  await ctx.addCookies([{
    name: 'wordpress_logged_in_smoke',
    value: 'fake-' + Date.now(),
    domain: '.concertacaoamazonia.com.br',
    path: '/',
  }]);
  const bypassMeasure = await page.evaluate(async (u) => {
    const t0 = performance.now();
    const r = await fetch(u, { cache: 'no-store', credentials: 'include' });
    const t1 = performance.now();
    return {
      status: r.status,
      ttfb_ms: Math.round(t1 - t0),
      x_cache: r.headers.get('x-cache') || null,
      cache_control: r.headers.get('cache-control') || null,
    };
  }, bypassUrl);
  await ctx.clearCookies();

  // 5) Browser cache (assets estáticos): pegar 1 CSS e 1 JS da listagem; verificar cache-control
  const assetsHealth = await page.evaluate(async () => {
    const links = Array.from(document.querySelectorAll('link[rel="stylesheet"]'))
      .map(l => l.href).filter(h => h.includes('concertacaoamazonia.com.br'));
    const scripts = Array.from(document.querySelectorAll('script[src]'))
      .map(s => s.src).filter(h => h.includes('concertacaoamazonia.com.br'));
    const sample = [links[0], scripts[0]].filter(Boolean).slice(0, 2);
    const out = [];
    for (const url of sample) {
      try {
        const r = await fetch(url, { method: 'HEAD', cache: 'no-store' });
        out.push({
          url: url.split('/').slice(-2).join('/').slice(0, 60),
          status: r.status,
          cache_control: r.headers.get('cache-control') || null,
          x_cache: r.headers.get('x-cache') || null,
          last_modified: !!r.headers.get('last-modified'),
        });
      } catch (e) { out.push({ url, status: 0, error: (e.message || '?').slice(0, 80) }); }
    }
    return out;
  });

  return {
    object_cache_dropin: {
      status: dropInProbe.status,
      installed: dropInProbe.status === 200 || dropInProbe.status === 403,
      reason: dropInProbe.status === 404 ? 'drop_in_missing' : null,
    },
    page_cache_wp_rocket: {
      first_ttfb_ms: firstHit.ttfb_ms,
      second_ttfb_ms: secondHit.ttfb_ms,
      improvement_pct: firstHit.ttfb_ms > 0
        ? Math.round((1 - secondHit.ttfb_ms / firstHit.ttfb_ms) * 100)
        : 0,
      first_status: firstHit.status,
      second_status: secondHit.status,
      first_x_cache: firstHit.x_cache,
      second_x_cache: secondHit.x_cache,
    },
    edge_cache_cloudfront: {
      first_hit: firstHit.x_cache,
      second_hit: secondHit.x_cache,
      cf_hit_on_second: cfHitOnSecond,
      age: secondHit.age,
    },
    object_cache_bypass_test: {
      no_cookie_ttfb_ms: secondHit.ttfb_ms,
      logged_in_cookie_ttfb_ms: bypassMeasure.ttfb_ms,
      no_cookie_x_cache: secondHit.x_cache,
      logged_in_x_cache: bypassMeasure.x_cache,
      bypass_works: bypassMeasure.ttfb_ms > secondHit.ttfb_ms * 2
                 || (bypassMeasure.x_cache && !bypassMeasure.x_cache.toLowerCase().includes('hit')),
    },
    browser_cache_assets: assetsHealth,
  };
}
```

### Apresentar matriz de saúde de cache

```
| Camada                     | Verificação                              | Esperado            | Real                | Status |
|----------------------------|------------------------------------------|---------------------|---------------------|--------|
| Object cache drop-in       | HEAD /wp-content/object-cache.php        | 200 ou 403          | 200                 | ✅     |
| Page cache (WP Rocket) 1ª  | TTFB request 1                           | (warm-up)           | 850ms               | —      |
| Page cache (WP Rocket) 2ª  | TTFB request 2                           | <100ms              | 35ms                | ✅     |
| Page cache improvement     | (1 - 2ª/1ª) × 100                        | >80%                | 96%                 | ✅     |
| Edge cache (CloudFront)    | x-cache na 2ª request                    | Hit                 | Hit from cloudfront | ✅     |
| Edge cache age             | header age                               | >0                  | 47s                 | ✅     |
| Object cache bypass        | logged-in cookie aumenta TTFB ≥2x        | true                | 3.5x mais lento     | ✅     |
| Browser cache CSS          | cache-control com max-age                | max-age=...         | max-age=31536000    | ✅     |
| Browser cache JS           | cache-control com max-age                | max-age=...         | max-age=31536000    | ✅     |
```

### Gates Cache Health

🚨 **FAIL** se:
- `object_cache_dropin.installed === false` — drop-in `wp-content/object-cache.php` ausente.
  Plugin redis-cache pode estar ativo mas WP NÃO está usando Redis. **Causa raiz #1 do incidente
  2026-05-02**. Fix: `cp wp-content/plugins/redis-cache/includes/object-cache.php
  wp-content/object-cache.php; chown www-data:www-data <arquivo>; systemctl reload php8.3-fpm`.
- `page_cache_wp_rocket.improvement_pct < 50` — 2ª request não é significativamente mais rápida que
  a 1ª. Page cache não está funcional. Possíveis causas: cookie de logged-in vazando para anônimos,
  query string fora de `cache_query_strings`, `$rocket_skip_reason` ativo. Investigar com
  `curl -sI URL | grep -i x-wp-rocket`.
- `edge_cache_cloudfront.cf_hit_on_second === false` — CloudFront não cacheou a página entre
  duas requests com mesma chave. Causa: response sem `Cache-Control` apropriado, cookie de sessão
  no response, ou path com behavior `Managed-CachingDisabled` aplicado erroneamente.
- `object_cache_bypass_test.bypass_works === false` — request com cookie de logged-in tem TTFB
  igual ao anônimo. WP Rocket não está identificando logged-in users → todos navegam servidos do
  cache (incluindo edição admin) ou nenhum é cacheado. Validar map `$rocket_is_logged_in` em
  nginx.conf e configuração `rocket_cache_logged_user`.
- Qualquer asset (CSS/JS) com `cache_control` contendo `no-store`, `no-cache`, ou `max-age=0`
  → assets estáticos não estão sendo cacheados pelo browser. Causa: header sobrescrevendo
  default em algum location nginx.

### Snippet — Sondagem Redis via WP REST (opcional, requer endpoint configurado)

Validação opt-in que confirma estado server-side do Redis. **Requer** mu-plugin
`bit-cache-health.php` exposto em `/wp-json/bit/v1/cache-health` retornando JSON com
DBSIZE, hit_rate, used_memory_human. Se endpoint não existir, snippet reporta `skipped: true`
em vez de falhar — não é gate obrigatório.

```js
async (page) => {
  await page.context().clearCookies();
  try {
    const data = await page.evaluate(async () => {
      const r = await fetch('/wp-json/bit/v1/cache-health', {
        cache: 'no-store',
        signal: AbortSignal.timeout(5000),
      });
      if (!r.ok) return { available: false, status: r.status };
      const json = await r.json();
      return { available: true, ...json };
    });
    return data;
  } catch (e) {
    return { available: false, skipped: true, error: (e.message || '?').slice(0, 80) };
  }
}
```

Quando disponível, esperar:

```
| Métrica Redis           | Esperado         | Real         | Status |
|-------------------------|------------------|--------------|--------|
| dbsize                  | >100             | 2.847        | ✅     |
| keyspace_hit_rate_pct   | >40              | 67%          | ✅     |
| used_memory_human       | <80% maxmemory   | 16M / 1.91G  | ✅     |
| connected_clients       | 1-50             | 5            | ✅     |
| evicted_keys            | 0 ou crescente lento | 0        | ✅     |
```

Gates Redis (quando endpoint disponível):
- `dbsize < 10` durante operação normal → cache vazio, drop-in pode estar quebrado
- `keyspace_hit_rate_pct < 20` sustentado → maioria das queries vai pra DB; investigar TTL agressivo ou prefix drift
- `evicted_keys > 1000/min` → memória pequena, aumentar `maxmemory` Redis

## Fase 7.9 — Referer block regression test (incidente 2026-05-06)

Valida que o map nginx `$deny_bot_referer` bloqueia o padrão de bot
SEM gerar falso-positivo em navegação browser real. Origem do gate:
incidente 2026-05-06 onde regex v1.15.0 incluiu `/?` no final tornando
o bloqueio inclusivo demais — 292 reqs LEGÍTIMAS (browsers reais
navegando da home com `Referer: https://host/`) foram bloqueadas com
444 em 2 dias antes da detecção.

**Premissa fundamental** (não obvia, daí precisa de gate):
- Bot envia: `Referer: https://host` (sem `/`, sem path)
- Browser real envia: `Referer: https://host/` (com `/`) OU com path
- Regex deve casar APENAS o primeiro padrão.

### Snippet — Referer block validation (rodar SOMENTE em PROD)

```js
async (page) => {
  const baseUrl = 'https://concertacaoamazonia.com.br';
  const tests = [
    // [referer, expected_blocked, description]
    [`${baseUrl}`,                               true,  'bot literal sem /'],
    [`http://concertacaoamazonia.com.br`,        true,  'bot http sem /'],
    [`HTTPS://CONCERTACAOAMAZONIA.COM.BR`,       true,  'bot UPPERCASE'],
    [`${baseUrl}/`,                              false, 'browser real com /'],
    [`${baseUrl}/conhecimento/`,                 false, 'browser com path'],
    [`${baseUrl}/?utm_source=x`,                 false, 'home com query string'],
    [`https://www.concertacaoamazonia.com.br`,   true,  'bot www sem /'],
    [`https://www.concertacaoamazonia.com.br/`,  false, 'www com / (legítimo)'],
    [`https://google.com/`,                      false, 'referer externo'],
  ];

  const results = [];
  for (const [referer, expected_blocked, desc] of tests) {
    const r = await page.evaluate(async (ref) => {
      try {
        // fetch com Referer customizado dispara CORS preflight, mas para
        // validar que NGINX bloqueia/passa, basta cair no lado servidor.
        // Como CORS bloqueia leitura cross-origin, usamos Image() que envia
        // Referer e mede via onerror/onload.
        const resp = await fetch(`${baseUrl}/check-ec2.php?cb=${Date.now()}`, {
          referrerPolicy: 'unsafe-url',
          // Nota: browser MAY override Referrer-Policy do servidor; smoke
          // detecta apenas o caminho END-TO-END.
        }).catch(e => ({ status: 0, error: e.message }));
        return { status: resp.status || 0 };
      } catch (e) { return { status: 0, error: (e.message || '?').slice(0, 80) }; }
    });

    // Para teste rigoroso de Referer, usar approach via curl ou playwright
    // setExtraHTTPHeaders. Como playwright limita headers nativos, este snippet
    // valida APENAS o resultado server-side. Para validar regex completa,
    // executar no servidor: curl -H "Referer: ..." http://127.0.0.1/
    results.push({
      desc, referer, expected_blocked,
      // Sem capacidade de injetar Referer real do client-side, marcamos como skip:
      validated_via: 'server-side-curl-required',
    });
  }

  return {
    note: 'Validação completa requer SSH+curl no servidor — playwright não permite injetar Referer arbitrário por padrão.',
    server_side_command: `ssh prod-sa "for r in 'https://host' 'https://host/' 'https://host/path/'; do curl -s -o /dev/null -w '%{http_code}\\n' -H \\"Referer: \$r\\" -H 'Host: concertacaoamazonia.com.br' http://127.0.0.1/; done"`,
    expected: 'Linha 1: 000 (bloqueado), Linhas 2-3: 200 (passa)',
    tests_planned: results,
  };
}
```

**Limitação documentada:** Playwright não permite injetar `Referer` arbitrário
de forma confiável (browsers ignoram se viola CORS/privacy policy). A validação
DEFINITIVA exige curl direto no servidor. Como compensação, a Fase 7.9 emite o
**comando exato a rodar manualmente** + critério de PASS/FAIL.

### Snippet alternativo — via SSH (executar fora do Playwright)

```bash
# Rodar manualmente após smoke Playwright para validar Referer block:
ssh concertacaoamazonia.com.br-prod-sa "
  for r in \\
    'https://concertacaoamazonia.com.br' \\
    'http://concertacaoamazonia.com.br' \\
    'HTTPS://CONCERTACAOAMAZONIA.COM.BR' \\
    'https://concertacaoamazonia.com.br/' \\
    'https://concertacaoamazonia.com.br/conhecimento/' \\
    'https://www.concertacaoamazonia.com.br' \\
    'https://www.concertacaoamazonia.com.br/'; do
    code=\$(curl -s -o /dev/null -w '%{http_code}' \\
      -H \"Referer: \$r\" \\
      -H 'Host: concertacaoamazonia.com.br' \\
      --max-time 5 http://127.0.0.1/)
    echo \"\$code  \$r\"
  done
"
```

**Esperado (PASS):**
```
000  https://concertacaoamazonia.com.br        ← bot, bloqueado
000  http://concertacaoamazonia.com.br         ← bot, bloqueado
000  HTTPS://CONCERTACAOAMAZONIA.COM.BR        ← bot UPPERCASE, bloqueado
200  https://concertacaoamazonia.com.br/       ← browser legítimo
200  https://concertacaoamazonia.com.br/...    ← com path
000  https://www.concertacaoamazonia.com.br    ← bot www, bloqueado
200  https://www.concertacaoamazonia.com.br/   ← browser www legítimo
```

**FAIL crítico** se qualquer linha COM `/` retornar 000 — regex está
inclusiva demais, replicando o bug do v1.15.0.

### Auditoria de FPs históricos no log (rodar 1x para checar regressão)

```bash
# Conta hits 444 com Referer COM `/` nas últimas 24h.
# Se > 5/dia, há FP residual — investigar.
ssh concertacaoamazonia.com.br-prod-sa "
  sudo awk '\$9==444' /var/log/nginx/access.log | \\
  grep '\\\"https://concertacaoamazonia.com.br/\\\"' | wc -l
"
```

**Esperado:** 0 hits após o fix v1.15.1 (2026-05-06). Se > 0, regex
voltou a bloquear navegação legítima.

## Fase 8 — Warm-up de cache do menu (prod e green)

Descobre as páginas do menu principal scrappeando a home, faz 2 visitas em sequência (1ª aquece, 2ª mede), e valida que cada item está sendo servido rápido a partir do cache.

### Snippet — Warm-up do menu (rodar 1x para PROD, 1x para GREEN)

```js
async (page) => {
  const ctx = page.context();
  const isGreen = HEADER_VAL && HEADER_VAL['X-Test-Green'] === 'true';
  await ctx.setExtraHTTPHeaders(HEADER_VAL || {});
  await ctx.clearCookies();

  // 1) Descobrir itens do menu na home
  await page.goto('https://concertacaoamazonia.com.br/?cb=' + Date.now(), { waitUntil: 'domcontentloaded', timeout: 30000 });
  const menuUrls = await page.evaluate(() => {
    const origin = location.origin;
    // Cobrir Elementor nav-menu, header padrão WordPress, e <nav> genérico no header
    const sels = [
      'header nav a[href]',
      '.elementor-nav-menu a[href]',
      '#site-navigation a[href]',
      '.main-navigation a[href]',
      'nav.elementor-nav-menu--main a[href]',
    ];
    const set = new Set();
    sels.forEach(s => document.querySelectorAll(s).forEach(a => {
      const href = a.href || '';
      if (!href || href.startsWith('javascript:') || href.startsWith('mailto:') || href.startsWith('tel:')) return;
      if (!href.startsWith(origin)) return;
      const clean = href.split('#')[0].split('?')[0];
      if (clean === origin || clean === origin + '/') return; // pula home
      set.add(clean);
    }));
    return [...set].slice(0, 20); // teto de segurança
  });

  // 2) Para cada URL: 1ª visita (warm-up) → 2ª visita (medição)
  const results = [];
  for (const url of menuUrls) {
    const measure = async () => {
      const t0 = Date.now();
      const resp = await page.goto(url + '?cb=warmup' + Date.now(), { waitUntil: 'domcontentloaded', timeout: 30000 });
      const ttfb = Date.now() - t0;
      const headers = resp ? resp.headers() : {};
      return {
        ttfb_ms: ttfb,
        status: resp ? resp.status() : 0,
        cf_cache_status: headers['cf-cache-status'] || null,
        wp_rocket_cache: headers['x-wp-rocket-cache'] || null,
        x_cache: headers['x-cache'] || null,
      };
    };

    try {
      await measure(); // warm-up (descartado)
      await page.waitForTimeout(500);
      const second = await measure(); // medição
      results.push({ url: url.replace('https://concertacaoamazonia.com.br', ''), ...second });
    } catch (e) {
      results.push({ url: url.replace('https://concertacaoamazonia.com.br', ''), error: (e.message || '?').slice(0, 120) });
    }
  }

  return {
    env: isGreen ? 'green' : 'prod',
    menu_count: menuUrls.length,
    items: results,
  };
}
```

### Apresentar matriz de menu (uma linha por item)

```
| Item de menu              | PROD ttfb | PROD cache | GREEN ttfb | GREEN cache | Status |
|---------------------------|----------:|------------|-----------:|-------------|--------|
| /quem-somos/              |    320ms  | HIT        |    410ms   | HIT         | ✅     |
| /conhecimento/            |    280ms  | HIT        |    900ms   | MISS        | ⚠️     |
| /cultura/atlas-cultural/  |    450ms  | HIT        |   2100ms   | MISS        | 🚨     |
| ...                       |           |            |            |             |        |
```

Cache column: prefere `cf_cache_status`, fallback para `wp_rocket_cache` ou `x_cache`. Se nenhum: "—".

## Fase 9 — Detecção de leaks e regressões silenciosas (PROD)

10 gates que cobrem incidentes recorrentes: URL de DEV vazando em CSS de prod, uploads em path `/green/` errado, Google Fonts externos, preloader Elementor vazio, banner Complianz não traduzido em `/en/`, CSP regression do Spotify embed em `/cultura/porosidades/` (2026-05-18), WPML orphan attachment leak em páginas EN do blog 2 (CU 86ahhtk2d, 2026-05-18), CSS WP Rocket min retornando 404+HTML (incidente 2026-05-18 21:30 BRT — home perdeu `post-2461.css` e `post-74762.css` por dessincronia entre HTML cached do CF e `cache/min/1/` regenerado parcialmente), stale s3-uploads path em _elementor_data quebrando ícones SVG inline (CU 86ahj85qk, 2026-05-18 — 567 ocorrências detectadas em prod após cleanup do uploads/s3/), e emails com `:porta` órfã em `_elementor_data` quebrando submit do Elementor Pro Forms silenciosamente (incidente 2026-05-18 21:56 BRT — Newsletter footer retornava `success:false` sem mensagem; 106 forms afetados em prod; fix automatizado em `09-importdatabase.sh::fix_form_email_ports`).

### Snippet — Leak detection composto (rodar 1x em PROD após Fase 7.5)

Combina gates 20–24 numa única passada para reduzir custo (5 checks numa só navegação por página). Gate 25 roda em página dedicada (`/cultura/porosidades/`) ao final do snippet. Gate 26 roda em snippet separado (4 páginas EN do blog 2).

```js
async (page) => {
  await page.context().clearCookies();
  await page.goto('https://concertacaoamazonia.com.br/?cb=' + Date.now(), { waitUntil: 'networkidle', timeout: 45000 });
  await page.waitForTimeout(1500);

  // Gate 23 — Google Fonts externos no DOM
  // Distingue 3 categorias com severidades distintas:
  //   - stylesheet: <link rel="stylesheet" href="fonts.googleapis.com/css...">
  //                 → HIGH (baixa CSS + fonte; viola self-host de PJS)
  //   - font_request: <link rel="preload" as="font" href="fonts.gstatic.com/...">
  //                   → HIGH (download direto de woff2 externo)
  //   - preconnect_only: <link rel="preconnect" href="fonts.gstatic.com">
  //                      → INFO (apenas TCP/TLS handshake; sem request de fonte)
  //                      Comum como resíduo do WP core wp_resource_hints filter.
  const externalFonts = await page.evaluate(() => {
    const links = Array.from(document.querySelectorAll('link[href]'))
      .filter(l => /fonts\.googleapis\.com|fonts\.gstatic\.com/.test(l.href));
    const byCategory = { stylesheet: [], font_request: [], preconnect_only: [] };
    for (const l of links) {
      const rel = (l.rel || '').toLowerCase();
      const asAttr = (l.getAttribute('as') || '').toLowerCase();
      const href = l.href.slice(0, 120);
      if (rel === 'stylesheet') byCategory.stylesheet.push(href);
      else if (rel === 'preload' && asAttr === 'font') byCategory.font_request.push(href);
      else if (rel === 'preconnect' || rel === 'dns-prefetch') byCategory.preconnect_only.push(href);
      else byCategory.font_request.push(href); // fallback conservador (rel desconhecido)
    }
    return byCategory;
  });

  // Gate 24 — uploads com path /green/ vazando em <img src>
  const greenLeaks = await page.evaluate(() => {
    const imgs = Array.from(document.querySelectorAll('img[src]'));
    return imgs.filter(i => /\/green\//.test(i.src))
      .map(i => i.src.split('/').slice(-3).join('/').slice(0, 80));
  });

  // Gate 21 — preloader Elementor: <e-page-transition> deve ter <svg> populado
  const preloader = await page.evaluate(() => {
    const el = document.querySelector('e-page-transition');
    if (!el) return { present: false, has_svg: null, svg_size: 0 };
    const svg = el.querySelector('svg');
    return {
      present: true,
      has_svg: !!svg,
      svg_size: svg ? svg.outerHTML.length : 0,
    };
  });

  // Gate 22 — Elementor CSS files contém URL de DEV (concertacao.bureau-it.com / cambrasmax.local)
  // Faz HEAD nos primeiros 5 CSS files referenciados; baixa conteúdo e procura URLs de dev.
  const cssLeaks = await page.evaluate(async () => {
    const cssLinks = Array.from(document.querySelectorAll('link[rel="stylesheet"]'))
      .filter(l => /\/uploads\/(?:sites\/\d+\/)?elementor\/|\/elementor-cache\//.test(l.href))
      .slice(0, 5);
    const leaks = [];
    for (const link of cssLinks) {
      try {
        const r = await fetch(link.href, { cache: 'no-store', signal: AbortSignal.timeout(5000) });
        if (!r.ok) continue;
        const txt = await r.text();
        const devRefs = txt.match(/concertacao\.bureau-it\.com|cambrasmax\.local|localhost:[0-9]+/g);
        if (devRefs) {
          leaks.push({
            css: link.href.split('/').slice(-2).join('/').slice(-60),
            ref_count: devRefs.length,
            sample: devRefs.slice(0, 3),
          });
        }
      } catch (e) { /* ignore */ }
    }
    return leaks;
  });

  // Gate 25 — Spotify embed em /cultura/porosidades/ (CSP regression, incidente 2026-05-18)
  // Valida 3 diretivas CSP + presença do iframe + ausência de console error de CSP block.
  // Navega em página separada porque o iframe Spotify só existe nessa rota.
  const spotifyCspErrors = [];
  const spotifyConsoleHandler = (msg) => {
    if (msg.type() === 'error' && /violates the following Content Security Policy directive/i.test(msg.text())
        && /open\.spotify\.com|spotify\.com|scdn\.co/i.test(msg.text())) {
      spotifyCspErrors.push(msg.text().slice(0, 240));
    }
  };
  page.on('console', spotifyConsoleHandler);
  let cspHeader = '';
  const responseHandler = (resp) => {
    const u = resp.url();
    if (u.startsWith('https://concertacaoamazonia.com.br/cultura/porosidades/') && !cspHeader) {
      cspHeader = resp.headers()['content-security-policy'] || '';
    }
  };
  page.on('response', responseHandler);

  let spotifyResult;
  try {
    await page.goto('https://concertacaoamazonia.com.br/cultura/porosidades/?cb=' + Date.now(),
      { waitUntil: 'networkidle', timeout: 45000 });
    await page.waitForTimeout(2500); // tempo para iframe Spotify tentar carregar + emitir CSP error

    const iframeInfo = await page.evaluate(() => {
      const iframe = document.querySelector('iframe[src*="open.spotify.com/embed"]');
      if (!iframe) return { present: false };
      // chrome-error://chromewebdata/ é a assinatura de bloqueio CSP do browser.
      // Não dá pra ler contentDocument cross-origin, mas dá pra checar dimensões e src.
      const rect = iframe.getBoundingClientRect();
      return {
        present: true,
        src_snippet: (iframe.getAttribute('src') || '').slice(0, 80),
        width: Math.round(rect.width),
        height: Math.round(rect.height),
      };
    });

    // Parse das 3 diretivas no header CSP capturado.
    const frameSrcMatch   = cspHeader.match(/frame-src([^;]+);/i);
    const connectSrcMatch = cspHeader.match(/connect-src([^;]+);/i);
    const mediaSrcMatch   = cspHeader.match(/media-src([^;]+);/i);
    const frameSrc   = frameSrcMatch   ? frameSrcMatch[1]   : '';
    const connectSrc = connectSrcMatch ? connectSrcMatch[1] : '';
    const mediaSrc   = mediaSrcMatch   ? mediaSrcMatch[1]   : '';

    spotifyResult = {
      csp_header_captured: cspHeader.length > 0,
      frame_src_has_spotify:   /open\.spotify\.com/.test(frameSrc),
      connect_src_has_spotify: /\*\.spotify\.com|open\.spotify\.com/.test(connectSrc),
      media_src_has_scdn:      /\*\.scdn\.co|scdn\.co/.test(mediaSrc),
      iframe_present: iframeInfo.present,
      iframe_dimensions: iframeInfo.present ? `${iframeInfo.width}x${iframeInfo.height}` : null,
      csp_console_errors: spotifyCspErrors,
      csp_error_count: spotifyCspErrors.length,
    };
  } catch (e) {
    spotifyResult = { error: (e.message || '?').slice(0, 120) };
  } finally {
    page.off('console', spotifyConsoleHandler);
    page.off('response', responseHandler);
  }

  return {
    gate_23_google_fonts_external: {
      // HIGH severity (real font/css download)
      stylesheet_count: externalFonts.stylesheet.length,
      stylesheet_leaks: externalFonts.stylesheet,
      font_request_count: externalFonts.font_request.length,
      font_request_leaks: externalFonts.font_request,
      // INFO severity (TCP/TLS hint apenas, sem request de fonte)
      preconnect_count: externalFonts.preconnect_only.length,
      preconnect_leaks: externalFonts.preconnect_only,
      // Compatibilidade com versões antigas do gate
      count: externalFonts.stylesheet.length + externalFonts.font_request.length,
    },
    gate_24_uploads_green_leak: {
      count: greenLeaks.length,
      leaks: greenLeaks,
    },
    gate_21_preloader_empty: {
      present: preloader.present,
      has_svg: preloader.has_svg,
      svg_size: preloader.svg_size,
    },
    gate_22_elementor_css_dev_leak: {
      count: cssLeaks.length,
      leaks: cssLeaks,
    },
    gate_25_spotify_embed: spotifyResult,
  };
}
```

### Gate 25b — CSP connect-src cobre RD Station (incidente 2026-05-28)

Verificação **estática** (curl, ~3s/path) do header `Content-Security-Policy`: o `connect-src`
DEVE conter `event-api.rdstation.com.br`. O JS `rd-js-integration.min.js` (CDN
`d335luupugsy2.cloudfront.net`) envia conversões de formulário via POST para
`https://event-api.rdstation.com.br/v2/form_integrations`. Sem esse domínio no `connect-src`,
o browser bloqueia ("Network Error" no console) e o lead nunca chega ao RD. Páginas com form
RD: home (`/`, footer), `/contato/`, `/atuacao/encontros/` e `/en/activities/news/` (footer
compartilhado).

```bash
python3 <<'PY'
import subprocess, re
BASE = "https://concertacaoamazonia.com.br"
# páginas com form RD Station (footer compartilhado, PT + EN)
PATHS = ["/", "/contato/", "/atuacao/encontros/", "/en/activities/news/"]
REQUIRED = ["event-api.rdstation.com.br", "popups.rdstation.com.br", "d335luupugsy2.cloudfront.net"]
fails = 0
for path in PATHS:
    hdrs = subprocess.check_output(["curl","-sI",f"{BASE}{path}"], timeout=30).decode("utf-8","ignore")
    m = re.search(r'content-security-policy:\s*(.+)', hdrs, re.I)
    if not m:
        print(f"FAIL csp_absent: path={path} (header CSP não presente)"); fails += 1; continue
    cs = re.search(r'connect-src([^;]+)', m.group(1), re.I)
    cs = cs.group(1) if cs else ""
    missing = [d for d in REQUIRED if d not in cs]
    if missing:
        print(f"FAIL csp_rdstation: path={path} faltando em connect-src: {missing}"); fails += 1
    else:
        print(f"OK csp_rdstation: path={path} connect-src cobre RD Station")
print(f"\n{fails} csp_rdstation_fails")
PY
```

**Esperado (PASS):** `0 csp_rdstation_fails`.

**Esperado (FAIL):**
```
FAIL csp_rdstation: path=/ faltando em connect-src: ['event-api.rdstation.com.br']
1 csp_rdstation_fails
```

**Limitação de cache (IMPORTANTE):** o header CSP vem no HTML, e CloudFront/WP Rocket cacheiam
o header junto. Após editar `03-nginx-sites.sh` + reload nginx, é OBRIGATÓRIO `cache-flush
--prod --post-id=N` (ou `--prod /` para a home) de **TODAS** as páginas com form RD — senão o
browser recebe a CSP antiga (curl direto na origin já mostra a nova). Este gate pega justamente
páginas esquecidas no flush (no fix de 2026-05-28, a home `/` e a EN ficaram stale após o flush
inicial só das páginas PT 97/1240). Fix da regression: `03-nginx-sites.sh` v1.20.0+
(adiciona `event-api.rdstation.com.br` em connect-src). Memória: [[csp-ga4-regional-endpoints]].

### Snippet — Gate 26: WPML orphan attachment leak em páginas EN do blog 2 (incidente 2026-05-18)

Detecta a assinatura do bug CU 86ahhtk2d: attachments EN duplicados pelo WPML
em `wp_2_posts` sem espelho em `wp_posts` blog 1. Quando `wp_get_attachment_image_src`
falha para esses IDs órfãos, o widget Slides do Elementor renderiza sem
background-image e `<img>` quebra com `naturalWidth=0`.

Cobertura: 4 páginas EN identificadas como afetadas no diagnóstico inicial
(porosidades, nós e os nós, colors of future, timeline). Snippet roda 1x e
loop por página — qualquer leak dispara FAIL com `path` específico.

Pré-requisitos para passar:
- mu-plugin `bit-crossblog-attachment-fix.php` v1.5.2+ ativo (Hooks 9-13)
- `elementor_css_print_method=external` em blog 2 (option)
- WPML media duplication OFF (`\WPML\Media\Option::setNewContentSettings`)

```js
async (page) => {
  const paths = [
    '/cultura/en/porosidades/',                  // widget Slides com 26 backgrounds
    '/cultura/en/porosidades/nos-e-os-nos/',     // gallery widget
    '/cultura/en/colors-of-the-future-exhibition/',
    '/cultura/en/timeline/',
  ];

  const base = 'https://concertacaoamazonia.com.br';
  const perPath = [];

  for (const p of paths) {
    try {
      await page.goto(base + p + '?cb=' + Date.now(), { waitUntil: 'networkidle', timeout: 45000 });
      await page.waitForTimeout(1200); // tempo para lazy-load decoding

      const result = await page.evaluate(() => {
        // Refs a /sites/2/uploads/ no HTML — assinatura direta do bug
        // (CSS path /sites/2/elementor/css/ é legítimo, não conta)
        const html = document.documentElement.outerHTML;
        const orphan_refs = (html.match(/\/sites\/2\/uploads\/[^"')]+/g) || []).length;

        // Widget Slides do Elementor com backgrounds vazios
        const slideBgs = Array.from(document.querySelectorAll('.swiper-slide-bg'));
        const slideBgsWithImage = slideBgs.filter(s => getComputedStyle(s).backgroundImage !== 'none').length;

        // <img> quebrados (naturalWidth=0 + src populada + complete)
        const allImgs = Array.from(document.querySelectorAll('img'));
        const brokenImgs = allImgs.filter(i => i.complete && i.naturalWidth === 0 && i.src);

        return {
          orphan_refs_count: orphan_refs,
          slide_bgs_total: slideBgs.length,
          slide_bgs_with_image: slideBgsWithImage,
          broken_imgs_count: brokenImgs.length,
          broken_imgs_samples: brokenImgs.slice(0, 3).map(i => i.src.slice(-80)),
          total_imgs: allImgs.length,
        };
      });

      // Bug acionado se: (a) qualquer ref /sites/2/uploads/, OU (b) widget Slides
      // existe mas tem 0 backgrounds (caso porosidades), OU (c) >2 imgs quebradas.
      const pageHasBug =
        result.orphan_refs_count > 0 ||
        (result.slide_bgs_total > 0 && result.slide_bgs_with_image === 0) ||
        result.broken_imgs_count > 2;

      perPath.push({ path: p, ...result, has_bug: pageHasBug });
    } catch (e) {
      perPath.push({ path: p, error: (e.message || '?').slice(0, 120) });
    }
  }

  const totalLeaks = perPath.reduce((sum, r) => sum + (r.orphan_refs_count || 0), 0);
  const totalBrokenImgs = perPath.reduce((sum, r) => sum + (r.broken_imgs_count || 0), 0);
  const pagesWithBug = perPath.filter(r => r.has_bug).length;

  return {
    gate_26_wpml_orphan_leak: {
      total_orphan_refs: totalLeaks,
      total_broken_imgs: totalBrokenImgs,
      pages_with_bug: pagesWithBug,
      per_path: perPath,
    },
  };
}
```

### Snippet — Gate 28: stale s3-uploads path em _elementor_data (incidente 2026-05-18)

Detecta paths legados do plugin s3-uploads ATIVO no `_elementor_data`. Após
migrar para `s3-uploads OFF` + CF-OAC, esses paths ficam STALE e quebram
ícones SVG inline do Elementor (jet-button "+", arrow icons, etc).

Padrão a detectar (URL ou path):
```
/wp-content/uploads/s3/concertacaoamazonia-com-br-wp-static-prd-sa/assets/
```

Sintoma direto observado: ícones "+" dos botões SAIBA MAIS sumiram em todo
o site prod após cleanup do diretório `uploads/s3/`. Causa: Elementor lê
SVG via `file_get_contents()` do path local resolvido a partir da URL
armazenada. Path stale → arquivo não existe → SVG vazio.

Cobertura: navega na homepage (`/`) e checa o HTML completo + sub-gate
diagnóstico de jet-button widgets que têm classe `--icon-right` mas SEM
svg child (sintoma direto do bug).

```js
async (page) => {
  await page.goto('https://concertacaoamazonia.com.br/?cb=' + Date.now(),
    { waitUntil: 'networkidle', timeout: 45000 });
  await page.waitForTimeout(1500);

  const result = await page.evaluate(() => {
    const html = document.documentElement.outerHTML;
    // Padrão 1: path-local `wp-content/uploads/s3/<bucket>/assets/` (prefix
    // s3-uploads legado, CF-OAC). Outras strings com `/s3/` NÃO devem casar.
    // Padrão 2 (ampliado 2026-06-23): URL S3 DIRETA `s3.<region>.amazonaws.com/
    // <bucket>/assets/` + bucket legacy us-east-1. O Gate era CEGO a esta forma
    // (auditoria 3-agentes: 776 refs em prod escaparam por anos). Ambos resolvem
    // a path morto sob CF-OAC → SVG/img/PDF quebrados. O fix correto reescreve
    // para `/wp-content/uploads/<resto>` (search_replace_legacy_s3_paths v2).
    const stale_local  = (html.match(/wp-content\/uploads\/s3\/[^"')]+/g) || []);
    const stale_direct = (html.match(/s3(?:\.[a-z0-9-]+)?\.amazonaws\.com\/[^"')]*?\/assets\/[^"')]+/g) || []);
    const stale_refs = [...stale_local, ...stale_direct];
    // Sub-gate diagnóstico: jet-buttons que TEM class --icon-right mas SEM
    // svg child → ícone quebrado (sintoma direto do bug)
    const jetButtons = Array.from(document.querySelectorAll('[data-widget_type="jet-button.default"]'));
    const btnsWithIconExpected = jetButtons.filter(w => {
      const a = w.querySelector('a.jet-button__instance');
      return a && a.classList.contains('jet-button__instance--icon-right');
    });
    const btnsWithSvg = btnsWithIconExpected.filter(w => !!w.querySelector('svg'));
    return {
      stale_refs_count: stale_refs.length,
      stale_refs_sample: stale_refs.slice(0, 5),
      jet_buttons_with_icon_class: btnsWithIconExpected.length,
      jet_buttons_with_svg: btnsWithSvg.length,
    };
  });

  return {
    gate_28_legacy_s3_path: {
      stale_refs_count: result.stale_refs_count,
      stale_refs_sample: result.stale_refs_sample,
      jet_buttons_icon_class: result.jet_buttons_with_icon_class,
      jet_buttons_svg_rendered: result.jet_buttons_with_svg,
      jet_buttons_missing_svg: result.jet_buttons_with_icon_class - result.jet_buttons_with_svg,
    },
  };
}
```

### Snippet — Gate 20: Complianz banner em /en/ multisite (extensão da Fase 7.6)

Estende a matriz multisite da Fase 7.6 para incluir blogs em inglês. Banner no blog EN deve mostrar texto em inglês.

**Estratégia (atualizada 2026-05-02):** parsear o objeto JS `complianz` injetado inline no `<head>` (server-side, sem precisar esperar JS executar). Esse objeto contém os campos traduzíveis: `placeholdertext`, `aria_label`, `categories.{statistics,marketing}`, `page_links.{cookie-statement,privacy-statement}.title`, `locale`. Cada campo é validado independentemente — banner pode estar parcialmente traduzido (caso real Concertação 2026-05-02: textos OK, mas `page_links.title` ainda em PT em /en/).

```js
async (page) => {
  const audit = async (url, expected_lang) => {
    await page.context().clearCookies();
    await page.goto(url + '?cb=' + Date.now(), { waitUntil: 'domcontentloaded', timeout: 30000 });
    await page.waitForTimeout(1500);

    return await page.evaluate((lang) => {
      // Objeto complianz é setado inline no <head> via wp_localize_script
      const cfg = window.complianz;
      if (!cfg) return { found: false, reason: 'window.complianz indefinido' };

      const expectedPt = lang === 'pt';
      const ptTerms = /aceitar|clique|necess[áa]rio|pol[ií]tica|aviso|estat[íi]sticas/i;
      const enTerms = /accept|click|required|privacy|notice|statistics/i;

      const isPt = (s) => typeof s === 'string' && ptTerms.test(s);
      const isEn = (s) => typeof s === 'string' && enTerms.test(s);

      // Campos a validar (cada um isolado)
      const fields = {
        placeholdertext:    cfg.placeholdertext || '',
        aria_label:         cfg.aria_label      || '',
        categories_stats:   cfg.categories?.statistics || '',
        categories_mkt:     cfg.categories?.marketing  || '',
        page_link_cookie:   cfg.page_links?.br?.['cookie-statement']?.title  || '',
        page_link_privacy:  cfg.page_links?.br?.['privacy-statement']?.title || '',
        locale:             cfg.locale || '',
      };

      const violations = [];
      for (const [k, v] of Object.entries(fields)) {
        if (!v || v.length < 2) continue; // campo vazio = ignora
        if (k === 'locale') {
          // locale: "lang=pt&locale=pt_BR" ou "lang=en&locale=en_US"
          const localeMatch = v.match(/locale=([a-z]{2})_/);
          const actualLocale = localeMatch ? localeMatch[1] : null;
          if (lang === 'en' && actualLocale !== 'en') violations.push(`locale=${actualLocale} (esperado: en)`);
          if (lang === 'pt' && actualLocale !== 'pt') violations.push(`locale=${actualLocale} (esperado: pt)`);
          continue;
        }
        if (lang === 'en' && isPt(v) && !isEn(v))  violations.push(`${k}: PT ("${v.slice(0, 50)}...")`);
        if (lang === 'pt' && isEn(v) && !isPt(v))  violations.push(`${k}: EN ("${v.slice(0, 50)}...")`);
      }

      return {
        found: true,
        url: location.href,
        expected_lang: lang,
        locale_actual: fields.locale,
        violations,
        violation_count: violations.length,
        sample_fields: {
          placeholdertext: fields.placeholdertext.slice(0, 80),
          page_link_privacy: fields.page_link_privacy,
        },
      };
    }, expected_lang);
  };
  return {
    blog1_pt:    await audit('https://concertacaoamazonia.com.br/',                                  'pt'),
    blog1_en:    await audit('https://concertacaoamazonia.com.br/en/',                               'en'),
    blog2_pt:    await audit('https://concertacaoamazonia.com.br/cultura/',                          'pt'),
    blog2_en:    await audit('https://concertacaoamazonia.com.br/cultura/en/',                       'en'),
  };
}
```

**Variante curl-only** (quando browser MCP indisponível): parsear o JSON inline via regex e Python.

```bash
curl -s "https://concertacaoamazonia.com.br/en/?cb=$(date +%s%N)" --max-time 30 -o /tmp/blog1_en.html
python3 <<'EOF'
import re, json
html = open('/tmp/blog1_en.html').read()
m = re.search(r'(?:var\s+|window\.)?complianz\s*=\s*(\{[^;]+\});', html, re.DOTALL)
cfg = json.loads(m.group(1)) if m else {}

def safe_page_link(cfg, slug):
    """page_links.br pode ser dict (blog 1) ou list (blog 2 — estrutura diferente do Complianz)."""
    pl = cfg.get('page_links', {})
    if not isinstance(pl, dict): return ''
    br = pl.get('br', {})
    if isinstance(br, dict):
        return br.get(slug, {}).get('title', '') if isinstance(br.get(slug), dict) else ''
    if isinstance(br, list):
        for item in br:
            if isinstance(item, dict) and item.get('slug') == slug:
                return item.get('title', '')
    return ''

checks = {
    'locale': cfg.get('locale', ''),
    'placeholdertext': cfg.get('placeholdertext', ''),
    'categories_stats': cfg.get('categories', {}).get('statistics', '') if isinstance(cfg.get('categories'), dict) else '',
    'page_link_privacy': safe_page_link(cfg, 'privacy-statement'),
    'page_link_cookie':  safe_page_link(cfg, 'cookie-statement'),
}
violations = []
for k, v in checks.items():
    if k == 'locale' and 'locale=en_' not in v:
        violations.append(f'{k}={v} (esperado en_*)')
    elif k != 'locale' and v and any(t in v.lower() for t in ['aceitar', 'aviso', 'estatística', 'política']):
        violations.append(f'{k}: PT ("{v[:50]}")')
print(f'violations: {violations}')
EOF
```

### Snippet — Gate 27: CSS stylesheets retornando MIME `text/html` (incidente 2026-05-18 21:30 BRT)

Detecta o padrão em que WP Rocket `/cache/min/1/wp-content/...post-N.css` referenciado no HTML do CloudFront aponta para arquivo que sumiu do FS. Nginx faz fallback ao WordPress e retorna **HTML 404** com `content-type: text/html`. Browser com strict MIME checking **bloqueia** o CSS e o layout da página quebra inteiro mesmo com origin sano.

**Sintoma observável:** header verde + menus carregam (CSS de plugin/tema vem direto de `/wp-content/themes/...`), mas hero e secções da página explodem em tamanho natural sem layout.

**Causa raiz típica:** save no Elementor editor de OUTRA página dispara `rocket_clean_minify('css')` ou regenera `Files\CSS\Post->update()` parcial — apaga o `post-N.css` minificado mas não invalida o HTML cached do CloudFront que ainda referencia o `?ver=` antigo apontando para o arquivo que sumiu.

**Cobertura:** rodar em home + /cultura/ (subsite). Qualquer outra página pode ser adicionada à lista. Snippet faz `fetch` no client checando o `Content-Type` real de cada `<link rel=stylesheet>` — bypassa preview do navegador.

> **REFORÇO 2026-06-12 (incidente CSS quebrado pós-fix menu mobile):** o `fetch`
> deste gate pode dar FALSO NEGATIVO — quando um `<link rel=stylesheet>` recebe 404+MIME
> `text/html`, o Chromium dispara `net::ERR_ABORTED` ANTES de virar response capturável, e
> um `fetch` manual pode reusar cache/pegar outro PoP. **Validação confiável usa 3 canais
> via `page.on(...)`:** (1) `response` com status≥400 ou MIME text/html; (2) `requestfailed`
> (ERR_ABORTED em `.css` = strict-MIME-abort, O CANAL CRÍTICO); (3) `console` error "Refused
> to apply style". O script `docker-dev/common/scripts/css-mime-check.sh` já implementa os 3
> canais e roda multi-página — **preferir ele**: `bash common/scripts/css-mime-check.sh --base
> https://concertacaoamazonia.com.br / /sobre-nos/ /atuacao/ /conhecimento/publicacoes/` (exit
> 1 se quebrado).
>
> **ESCOPO ATUALIZADO 2026-06-22 — rodar em TODAS as pages, não só amostra.** O Gate 27 agora
> deve cobrir as **~68 pages descobertas pelo Snippet 1 da Fase 7.5** (todas as `page` publicadas
> dos 2 blogs), não uma lista fixa de 6. Motivo: o efeito colateral de `rocket_clean_minify()` é
> SITE-WIDE — apaga/regenera TODOS os `cache/min/*.css`; qualquer page com HTML cacheado no CF
> apontando p/ min antigo quebra (incidente home + agenda + contato + relatório-anual 2026-06-22).
> Pages órfãs (fora de menu) eram o ponto cego. Forma rápida (curl, ~2-4 min p/ 68 pages, paralelo):
> para cada page, fetch do HTML + HEAD em cada `/cache/min/*.css` referenciado — FAIL se algum
> não servir `text/css`. Passar a lista do Snippet 1 ao `css-mime-check.sh` (ele aceita múltiplos
> paths) OU usar o sweep curl-paralelo. NÃO declarar OK testando só home/cultura. Ver
> [[feedback_gate27_multipop_blindspot]] e [[project_rucss_collapse_broke_timeline_css_postcutover]].

```js
async (page) => {
  const paths = [
    'https://concertacaoamazonia.com.br/',
    'https://concertacaoamazonia.com.br/cultura/',
  ];

  const perPath = [];

  for (const url of paths) {
    try {
      await page.context().clearCookies();
      await page.goto(url + '?cb=' + Date.now(), { waitUntil: 'domcontentloaded', timeout: 45000 });
      await page.waitForTimeout(800);

      const result = await page.evaluate(async () => {
        const links = Array.from(document.querySelectorAll('link[rel="stylesheet"][href]'));
        const bad = [];
        // Checa em paralelo, mas com cap para não saturar (até 30 stylesheets concorrentes)
        const checks = links.map(async (l) => {
          try {
            const r = await fetch(l.href, {
              method: 'GET', // HEAD pode não pegar 404→200 do WP; GET é seguro
              cache: 'no-store',
              signal: AbortSignal.timeout(8000),
            });
            const ct = (r.headers.get('content-type') || '').toLowerCase();
            // CSS válido tem que ter `text/css` no Content-Type. Variações `text/css; charset=utf-8` passam.
            if (!ct.includes('text/css')) {
              bad.push({
                href: l.href.slice(-100),
                status: r.status,
                content_type: ct.slice(0, 80),
              });
            }
          } catch (e) {
            // Network error (ERR_ABORTED, timeout) também conta — chrome cancela CSS rejeitado
            bad.push({
              href: l.href.slice(-100),
              status: 0,
              content_type: 'fetch_error: ' + (e.message || '?').slice(0, 60),
            });
          }
        });
        await Promise.all(checks);
        return { total: links.length, bad_count: bad.length, bad_samples: bad.slice(0, 5) };
      });

      perPath.push({ url, ...result });
    } catch (e) {
      perPath.push({ url, error: (e.message || '?').slice(0, 120) });
    }
  }

  const totalBad = perPath.reduce((s, r) => s + (r.bad_count || 0), 0);

  return {
    gate_27_css_mime_check: {
      total_pages: paths.length,
      total_bad_stylesheets: totalBad,
      per_path: perPath,
    },
  };
}
```

**Fix se gate 27 falhar — escolher pela extensão:**

**Caso A — 1-2 posts afetados (cirúrgico):**
```bash
WP='sudo -u www-data wp --path=/var/www/concertacaoamazonia.com.br --url=https://concertacaoamazonia.com.br'
$WP eval 'rocket_clean_post(<POST_ID>);'
$WP eval 'rocket_clean_minify("css");'
$WP eval '(new \Elementor\Core\Files\CSS\Post(<POST_ID>))->update();'
# CF invalidate do HTML + elementor-cache do post (NÃO só '/')
aws cloudfront create-invalidation --distribution-id E2F1QD7E7YOYEB --profile Concertação \
    --paths '/' "/wp-content/elementor-cache/elementor/css/post-<POST_ID>.css*"
```

**Caso B — MUITAS páginas afetadas / minify não regenera (incidente 2026-06-12):**
Se o `css-mime-check.sh` acusa quebra em várias páginas e o minify retorna 404 mesmo no ORIGIN
(`curl -H Host: ... http://127.0.0.1/wp-content/cache/min/1/...css` → 404 text/html), o WP Rocket
**parou de regenerar o minify**. NÃO adianta invalidar — o arquivo não existe. **Desligar minify_css:**
```bash
$WP eval '$o=get_option("wp_rocket_settings"); file_put_contents(WP_CONTENT_DIR."/uploads/coord-backups/wp_rocket_settings_pre_".gmdate("Ymd-His").".json",json_encode($o)); $o["minify_css"]=0; update_option("wp_rocket_settings",$o); echo "minify_css desligado\n";'
$WP eval 'rocket_clean_domain(); rocket_clean_minify();'
aws cloudfront create-invalidation --distribution-id E2F1QD7E7YOYEB --profile Concertação --paths '/*'
# Validar (3 canais, multi-página):
bash docker-dev/common/scripts/css-mime-check.sh --base https://concertacaoamazonia.com.br / /sobre-nos/ /atuacao/ /conhecimento/publicacoes/ /cultura/ /cultura/linha-do-tempo/
```
> **NUNCA `rm -rf wp-content/cache/min/*` em prod** — quebra a regeneração do minify (foi a
> causa-raiz do incidente). Usar só `rocket_clean_minify()` via API; se travar, desligar minify_css.

Sempre re-rodar o `css-mime-check.sh` (exit 0) ANTES de declarar resolvido.

### Snippet — Gate 29: emails com `:porta` órfã em `_elementor_data` (incidente 2026-05-18 21:56 BRT)

Detecta a assinatura DIRETA do bug Newsletter quebrada: campos `email_from`, `email_from_2`, `email_to`, `email_reply_to`, `email_subject` etc dentro de `_elementor_data` contendo padrão `@host:NNNN` (porta órfã sobrando de `wp search-replace` de hostname). Quando `is_email()` rejeita, action "email" do Elementor Pro Forms aborta com `{success:false, errors:[], data:[]}` — usuário vê "Erro do Servidor", lead é perdido silenciosamente.

**Por que existe além do gate 27 (MIME):** gate 27 só detecta CSS quebrado; este detecta forms quebrados que sequer enviam emails. Bug é INVISÍVEL no browser/render — só aparece no submit. Cobre a classe inteira de bugs introduzidos por search-replace cego em `_elementor_data` JSON.

**Por que existe além de fix automático no 09-importdatabase.sh:** o fix roda no deploy. Este gate roda no smoke pós-deploy como confirmação. Se gate 28 falhar, o fix do deploy não rodou ou tem regressão.

**Validação via SSH** (não pelo browser — precisa SQL query em `_elementor_data`):

```bash
ssh concertacaoamazonia.com.br-prod-sa "sudo -u www-data \
  wp --path=/var/www/concertacaoamazonia.com.br --url=https://concertacaoamazonia.com.br \
  eval '
global \$wpdb;
\$EMAIL_KEYS = [\"email_from\",\"email_from_2\",\"email_from_name\",\"email_from_name_2\",\"email_to\",\"email_to_2\",\"email_to_cc\",\"email_reply_to\",\"email_reply_to_2\",\"email_subject\",\"email_subject_2\"];
\$blogs = is_multisite() ? get_sites([\"fields\" => \"ids\"]) : [get_current_blog_id()];
\$bad = [];
foreach (\$blogs as \$bid) {
  if (is_multisite()) switch_to_blog(\$bid);
  \$tbl = \$wpdb->postmeta;
  \$rows = \$wpdb->get_results(\"SELECT pm.post_id, pm.meta_value FROM {\$tbl} pm INNER JOIN {\$wpdb->posts} p ON p.ID=pm.post_id WHERE pm.meta_key=\\\"_elementor_data\\\" AND pm.meta_value LIKE \\\"%widgetType%form%\\\" AND pm.meta_value LIKE \\\"%email_%\\\" AND p.post_status IN (\\\"publish\\\",\\\"draft\\\",\\\"private\\\",\\\"pending\\\") AND p.post_type NOT IN (\\\"revision\\\")\", ARRAY_A);
  foreach (\$rows as \$row) {
    \$d = json_decode(\$row[\"meta_value\"], true);
    if (!is_array(\$d)) continue;
    \$walk = function(array \$nodes) use (&\$walk, \$EMAIL_KEYS, \$row, \$bid, &\$bad) {
      foreach (\$nodes as \$node) {
        if ((\$node[\"widgetType\"] ?? null) === \"form\") {
          foreach (\$EMAIL_KEYS as \$k) {
            \$v = \$node[\"settings\"][\$k] ?? null;
            if (!is_string(\$v)) continue;
            if (preg_match(\"/@[A-Za-z0-9.\\\\-]+:\\\\d{1,5}/\", \$v)) {
              \$bad[] = sprintf(\"blog=%d post=%d field=%s value=%s\", \$bid, \$row[\"post_id\"], \$k, substr(\$v, 0, 60));
            }
          }
        }
        if (!empty(\$node[\"elements\"])) \$walk(\$node[\"elements\"]);
      }
    };
    \$walk(\$d);
  }
  if (is_multisite()) restore_current_blog();
}
echo count(\$bad) . \" bad\\n\";
foreach (array_slice(\$bad, 0, 5) as \$b) echo \"  \" . \$b . \"\\n\";
'" 2>&1 | grep -v Deprecated
```

**Esperado (PASS):**
```
0 bad
```

**Esperado (FAIL):**
```
N bad
  blog=1 post=72234 field=email_from_2 value=email@concertacaoamazonia.com.br:8484
  blog=1 post=91977 field=email_from value=email@concertacaoamazonia.com.br:8484
  ...
```

**Fix se gate 28 falhar:**

1. Executar `fix_form_email_ports()` do `09-importdatabase.sh` standalone, ou rodar `scripts/regularize-form-emails.php`:
   ```bash
   scp scripts/regularize-form-emails.php concertacaoamazonia.com.br-prod-sa:/tmp/
   ssh concertacaoamazonia.com.br-prod-sa "sudo -u www-data wp --path=/var/www/concertacaoamazonia.com.br --url=https://concertacaoamazonia.com.br eval-file /tmp/regularize-form-emails.php"
   ```
2. Limpar cache: `wp eval 'rocket_clean_post(<POST_ID>);'` para cada post afetado
3. Invalidar CloudFront se Newsletter está em template global do footer (afeta todas as páginas): `aws cloudfront create-invalidation --distribution-id E2F1QD7E7YOYEB --paths '/' --profile Concertação`
4. Re-rodar gate 28 — esperado: `0 bad`

### Apresentar matriz Fase 9

```
| Gate | Verificação                                            | Esperado     | Real         | Status |
|------|--------------------------------------------------------|--------------|--------------|--------|
| 23a  | <link rel=stylesheet> Google Fonts (HIGH)              | 0            | 0            | ✅     |
| 23a  | <link rel=preload as=font> Google Fonts (HIGH)         | 0            | 0            | ✅     |
| 23b  | <link rel=preconnect/dns-prefetch> Google Fonts (INFO) | 0 ideal      | 1            | ℹ️ INFO |
| 24   | <img src> com /green/ em prod (BLOCKER)                | 0            | 0            | ✅     |
| 21   | <e-page-transition> tem <svg> populado                 | true, ≥100b  | true, 12.2KB | ✅     |
| 22   | Elementor CSS contém URL de dev (BLOCKER)              | 0 leaks      | 0 leaks      | ✅     |
| 20   | Complianz banner /en/ em inglês                        | true         | false        | 🚨     |
| 25a  | CSP frame-src contém open.spotify.com (porosidades)    | true         | true         | ✅     |
| 25b  | CSP connect-src contém *.spotify.com (porosidades)     | true         | true         | ✅     |
| 25c  | CSP media-src contém *.scdn.co (porosidades)           | true         | true         | ✅     |
| 25d  | iframe Spotify presente em /cultura/porosidades/       | true         | true         | ✅     |
| 25e  | 0 console errors de CSP block do Spotify               | 0            | 0            | ✅     |
| 26a  | 0 refs /sites/2/uploads/ em páginas EN blog 2          | 0            | 0            | ✅     |
| 26b  | 0 páginas EN com bug WPML orphan (4 verificadas)       | 0            | 0            | ✅     |
| 26c  | 0 <img> quebrados (naturalWidth=0) em páginas EN       | 0            | 0            | ✅     |
| 27   | 0 <link rel=stylesheet> com Content-Type != text/css   | 0            | 0            | ✅     |
| 29   | 0 emails com `:porta` órfã em `_elementor_data`         | 0            | 0            | ✅     |
| 28a  | 0 refs /wp-content/uploads/s3/ em HTML rendered        | 0            | 0            | ✅     |
| 28b  | jet-buttons com --icon-right rendering com svg child   | all          | all          | ✅     |
```

**Notas:**
- Gate 23a (HIGH) é o que dispara FAIL — stylesheet OU preload de fonte
- Gate 23b (INFO) reporta no relatório mas **não falha o smoke**
- Para fix permanente do 23b: identificar plugin que mantém ref Google Fonts via `wp_resource_hints`

## Gates de FAIL (qualquer um falha o smoke)

🚨 **FAIL** se:
1. Atlas (PT ou EN) GREEN: `jet_max_found_posts < 100`
2. Qualquer página GREEN: `uploads_elementor_css > 0` (mu-plugin v2 deve garantir 0)
3. Qualquer página GREEN: 404s em `/elementor-cache/2026/*.jpg|jpeg|png|webp` (bug v2.0.4 — fix em v2.0.5)
4. Qualquer página GREEN: `hostname` sem `hml`
5. Qualquer página GREEN: `listing_items === 0` quando `jet_max_found_posts > 0`
6. Console errors com `ERR_CONNECTION_CLOSED` em qualquer página
7. **Form Newsletter (PROD)**: `form_count === 0` (form sumiu) OU `fields_count < 2` (campos perdidos) OU `submit_label !== "ENVIAR"`
8. **Form Contato (PROD)**: `form_count === 0` OU `fields_count < 3` (deve ter pelo menos nome, email, mensagem) OU `submit_visible === false`
9. **Newsletter submit (GREEN)**: `submit_ok === false` após retry. Reportar `submit_reason` e `submit_message`.
    - Se `submit_reason === 'green_offline'`: FAIL distinto — **não houve submit**, hostname não contém `hml` (CloudFront roteou para prod-blue, evitando poluir CRM/Newsletter de produção). Mensagem operacional: "GREEN OFFLINE — verificar se ALB rule de header `X-Test-Green: true` está aplicada e se o target group green tem instância saudável registrada."
    - Outros `submit_reason` (form_not_found / submit_btn_not_found / error_message / timeout_15s): FAIL de submit válido — green respondeu mas form quebrou.
10. **Contato submit (GREEN)**: `submit_ok === false` após retry. Reportar `submit_reason` e `submit_message`.
    - Se `submit_reason === 'green_offline'`: FAIL distinto — **não houve submit**, hostname não contém `hml` (CloudFront roteou para prod-blue, evitando poluir CRM com lead falso). Mensagem operacional: "GREEN OFFLINE — verificar se ALB rule de header `X-Test-Green: true` está aplicada e se o target group green tem instância saudável registrada."
    - Outros `submit_reason` (form_not_found / submit_btn_not_found / error_message / timeout_15s): FAIL de submit válido — green respondeu mas form quebrou.
11. **Menu warm-up (qualquer ambiente)**: `ttfb_ms > 1500` na 2ª visita de qualquer item do menu. Reportar URL, ambiente, ttfb, e header de cache observado. (Header `cf-cache-status: MISS`/`x-wp-rocket-cache: MISS` é informativo — só dispara gate se também ultrapassar 1500ms.)
12. **Menu warm-up — comparativo prod×green**: green com `ttfb_ms > 2x` o prod do mesmo item, mesmo se < 1500ms absoluto. Indica regressão de cache no green.
13. **Paridade prod/dev (Fase 7.5)** — DEV é source-of-truth. Para CADA `page` publicada descoberta em DEV (TODAS, ~68 — não só as do menu, atualizado 2026-06-22):
    - `prod_status !== 200` E `dev_status === 200` — página existe em DEV mas falha em PROD (pendência de deploy ou regressão)
    - `headings_match === false` — sequência de H1/H2/H3 diverge
    - `downloads_match === false` — botões de download (texto/quantidade) divergem
    - `height_diff_pct > 20` — altura da página difere mais de 20% (forte indício de seção faltando; tolerância +5% absorve banner Complianz e variação de lazy-load residual)
    - `images_diff_pct > 40` — quantidade de imagens renderizadas (naturalWidth ≥100) difere mais de 40% (tolerância +10% absorve lazy-load fora do viewport mesmo após auto-scroll)
    - `Math.abs(sections_diff) > 2` — diferença de mais de 2 sections Elementor
    - **`prod_console_errors.length > 0`** — qualquer JS error em PROD (TypeError, ReferenceError, MIME refused, CORS). Reportar `prod_console_errors[0..2]` no detalhe.
    - **`prod_csp_errors.length > 0` ou `dev_csp_errors.length > 0`** — qualquer CSP block em PROD ou DEV. Bucket separado, sem whitelist, gate dedicado. Reportar diretiva bloqueada e domínio (ex: `script-src bloqueou https://www.youtube.com/iframe_api`). CSP errors em DEV indicam que o ambiente está com CSP ativa que falta paridade (raro — dev normalmente sem CSP).
    - **`prod_failed_resources.length > 0`** — qualquer 4xx/5xx em assets do próprio domínio em PROD (CSS, JS, imagens). Reportar `prod_failed_resources[0..2]`.
    - `dev_console_errors.length > prod_console_errors.length` — DEV com mais erros que PROD = WARN não-bloqueante (reportar mas não falhar).

    **Falsos positivos esperados (NÃO contar como erro):**
    - `JQMIGRATE` warnings (info, não tipo `error`)
    - `SES Removing unpermitted intrinsics` (lockdown extension MetaMask do user — só aparece em browsers com extensão instalada)
    - YouTube `web-share` / `postMessage` warnings (de iframes embed, não controláveis)

    **NUNCA whitelistar:** mensagens contendo `violates the following Content Security Policy directive`
    (CSP block do servidor — sempre é bug real, irreversível pelo browser. Não confundir com bloqueio
    do Complianz que reescreve `script src` para `data-cmplz-src` antes do consent).

    Reportar cada path em FAIL/ERROR com motivo específico. Sumário final: `pass_count / total_paths` e contagem de FAIL vs ERROR.

49. **Menu MOBILE visual (Gate 49)** — viewport mobile 390px, toggle aberto, `getComputedStyle` do dropdown prod vs dev (snippet dedicado). Severidade **HIGH**. FAIL se:
    - `49a` — `prod.dropdownBg` é branco/transparente (fundo do menu mobile perdido — `background_color_dropdown_item` ausente no widget; incidente 2026-06-12). **Absoluto** (independe de dev).
    - `49b` — estado de underline de prod ≠ dev (underline do WP core vazando só em prod). **Relativo a dev.**
    - `49c` — `subFontSize`/`topFontSize` de prod divergem de dev (tipografia fora de paridade; DEV = source-of-truth)

    Cobre blog 1 (`/atuacao/encontros/`, header 39359) + blog 2 (`/cultura/linha-do-tempo/`, header 89307), widget `58b33f3`. Mede em páginas INTERNAS (homes têm toggle instável).
    **NÃO** comparar `dropdownBg` contra hex fixo: o verde de prod (#003A26) diverge do dev (#005A42, nova paleta) de propósito. Ver [[feedback_menu_mobile_bg_lost_css_to_widget_handoff]].

50. **Menu DESKTOP — cor dos itens no estado `.highlighted` (Gate 50)** — viewport ≥1024px, simula a classe residual `.highlighted` do SmartMenus nos itens top-level e mede a cor COMPUTADA. Severidade **HIGH**. FAIL se:
    - `50 offwhite_leak` — algum item do menu desktop fica **offwhite (#F8EAD9)** no estado `.highlighted` (regra header-menu.css §9.5 usando `--e-global-color-secondary` em vez de `--e-global-color-bcf690c` branco). Itens previamente clicados ficam ilegíveis sobre o header escuro ("Conhecimento sumindo"). Incidente 2026-06-22. Fix: regra §9.5 → `var(--e-global-color-bcf690c)` + rsync tema + reload fpm + rocket_clean_minify + CF invalidate.

    Cobre blog 1 (`/sobre-nos/`) + blog 2 (`/cultura/linha-do-tempo/`), `.elementor-nav-menu--main`. Validar pela cor COMPUTADA (não presença da regra); estado `.highlighted` transitório → simular via `classList.add`. Ver [[feedback_css_validation_computed_not_presence]].

51. **Paginação de eventos TEC (Gate 51)** — `fetch` HTTP de `/eventos/lista/?eventDisplay=past` (≥2 páginas garantidas). Severidade **HIGH**. **Prod-only** (dev não tem CloudFront). FAIL se:
    - `51 stale_cache` — origin gera paginação correta mas o **edge (CloudFront) serve `<button disabled>` / sem link `/página/2/`** (HTML dinâmico cacheado 24h sem diferenciar `eventDisplay`/`tribe-bar-date` na whitelist). Setas "Próximos/Anteriores" não navegam no browser. Fix imediato: invalidar CF `/eventos* /editais* /eventos-calendario* /`. Incidente 2026-06-22.
    - `51 origin_broken` — o próprio origin não gera paginação (permalink/rewrite `/eventos/lista/página/N/` ou config do widget). Snippet dedicado. Ver [[project_tec_pagination_cf_cache_stale]].

14. **Cache health (Fase 7.8) — Object cache drop-in**: `object_cache_dropin.installed === false`.
    Plugin redis-cache pode estar ativo mas drop-in `wp-content/object-cache.php` ausente
    → WP NÃO usa Redis. Reportar comando exato de fix no detalhe.

14b. **Cache health — Drop-in perdido em wp-content swap (incidente 2026-05-19)**.
    Validação cruzada via SSH para garantir que `wp-content/object-cache.php` existe
    fisicamente. Útil quando o gate 14 retorna `installed=false` pra entender se é
    realmente o drop-in ausente (vs problema de permissão de leitura via HTTP).

    Origem do gate: deploy blue-green 2026-05-16 instalou drop-in via `07-redis.sh`,
    mas `10-importwpcontent.sh` (rodando depois) fez swap atômico do wp-content
    com tarball que NÃO continha o drop-in → drop-in desapareceu silenciosamente.
    Detectado 3 dias depois pelo `/smoke` (CF mascarava porque HTML era servido
    cached). Fix arquitetural em `10-importwpcontent.sh` v1.4.0: restauração
    automática do drop-in pós-swap se `redis-cache` plugin presente.

    Comando de validação SSH (run no servidor):
    ```bash
    ssh prod-sa "sudo ls -la /var/www/<SITE>/wp-content/object-cache.php 2>&1 | head -2; \
      sudo -u www-data wp --path=/var/www/<SITE> --url=<URL> eval 'echo wp_using_ext_object_cache() ? \"YES\" : \"NO\";'"
    ```

    Sub-gates:
    - `ls` retorna `No such file or directory` → drop-in ausente do FS
    - `wp_using_ext_object_cache()` retorna `NO` → WordPress não usa Redis

    Fix:
    ```bash
    ssh prod-sa "sudo cp /var/www/<SITE>/wp-content/plugins/redis-cache/includes/object-cache.php \\
      /var/www/<SITE>/wp-content/object-cache.php && \\
      sudo chown www-data:www-data /var/www/<SITE>/wp-content/object-cache.php && \\
      sudo systemctl reload php8.3-fpm"
    ```

    Severidade: **BLOCKER** — Redis fica inerte, todo page hit miss vai direto ao
    MySQL Aurora. wp-admin lento (sem cache), forms degradados. CF mascara HTML
    estático mas falha em qualquer flow autenticado/POST.
15. **Cache health (Fase 7.8) — Page cache (WP Rocket)**: `page_cache_wp_rocket.improvement_pct < 50`.
    2ª request com mesma chave de cache não foi significativamente mais rápida que a 1ª.
    Reportar `first_ttfb_ms`, `second_ttfb_ms`, e header de cache observado nas duas.
16. **Cache health (Fase 7.8) — Edge cache (CloudFront)**: `edge_cache_cloudfront.cf_hit_on_second === false`.
    CloudFront não cacheou entre 2 requests sequenciais. Causa: response sem `Cache-Control`
    apropriado, cookie de sessão no response, ou behavior `Managed-CachingDisabled` aplicado erroneamente.
17. **Cache health (Fase 7.8) — Object cache bypass**: `object_cache_bypass_test.bypass_works === false`.
    Cookie de logged-in não está bypassando cache (TTFB ~igual ao anônimo). Risco: edição admin
    servindo cache stale OU cache desabilitado para todos. Validar map `$rocket_is_logged_in` em
    nginx.conf.
18. **Cache health (Fase 7.8) — Browser cache assets**: qualquer asset CSS/JS com `cache_control`
    contendo `no-store`, `no-cache`, ou `max-age=0`. Assets estáticos não estão sendo cacheados
    pelo browser → request inútil em cada page view.
19. **Redis health (Fase 7.8 opcional)**: quando endpoint `/wp-json/bit/v1/cache-health` está
    disponível: `dbsize < 10` (cache vazio) OU `keyspace_hit_rate_pct < 20` (drop-in inerte ou
    prefix drift) OU `evicted_keys > 1000/min` (memória pequena). Quando endpoint não existe
    (`available === false`), apenas reportar `skipped: true` — não dispara gate.

19b. **Referer block regression (Fase 7.9)** — incidente 2026-05-06.
    Validação executada via SSH (Playwright não permite injetar Referer arbitrário). Para
    cada par (referer, expected_code) listado no snippet da Fase 7.9, o `curl -H "Referer: ..."`
    deve retornar EXATAMENTE o esperado:
    - `Referer: https://host` (sem /, sem path) → **000** (444 close, bot bloqueado)
    - `Referer: https://host/` (com /) → **200** (browser real, NÃO bloquear)
    - `Referer: https://host/path/` → **200** (browser com path, NÃO bloquear)
    - `Referer: HTTPS://HOST` (UPPERCASE) → **000** (case-insensitive cobre bot)

    🚨 **FAIL crítico** se Referer COM `/` retornar 000 — regex está inclusiva demais
    e está bloqueando navegação browser legítima (bug v1.15.0 de 2026-05-06: 292 reqs
    legítimas perdidas em 2 dias antes da detecção).

    🚨 **FAIL secundário (auditoria de regressão)**: hits 444 com Referer
    `https://host/` (com /) > 5/dia em logs. Se houver, regex voltou a ser inclusivo.
    Comando: `sudo awk '$9==444' /var/log/nginx/access.log | grep '"https://host/"' | wc -l`

20. **Complianz banner traduzido em /en/ (Fase 9)**: `violation_count > 0` em `blog1_en` ou `blog2_en`.

20. **Complianz banner traduzido em /en/ (Fase 9)**: `violation_count > 0` em `blog1_en` ou `blog2_en`.
    Validação **field-by-field** (atualizada 2026-05-02): banner Complianz pode estar
    parcialmente traduzido. Caso real Concertação 2026-05-02 mostrou:
    - ✅ `placeholdertext` traduzido ("Click to accept" em /en/)
    - ✅ `aria_label` traduzido
    - ✅ `categories.statistics` ("statistics" em /en/, "estatísticas" em /pt/)
    - ✅ `locale` correto (`lang=en&locale=en_US`)
    - 🚨 `page_links.br.privacy-statement.title` permanece "Aviso de Privacidade" em /en/
      (deveria ser "Privacy Notice")

    Reportar cada violação no detalhe (campo + valor + idioma esperado/encontrado). WPML
    Network Active não traduz strings de menus/links Complianz via UI; solução é mu-plugin
    com filtro `wpml_translate_single_string`. Ref: memo `feedback_complianz_wpml_translation.md`.
    Severidade: **HIGH** — compliance LGPD/GDPR broken parcialmente para audiência internacional.

21. **Preloader Elementor vazio (Fase 9)**: `gate_21_preloader_empty.present === true && (has_svg === false || svg_size < 100)`.
    Tag `<e-page-transition>` está presente mas sem `<svg>` interno (ou SVG truncado <100 bytes).
    Causa: arquivo SVG do preloader ausente no FS local após cutover (Elementor lê via
    `get_attached_file()`, não URL pública). Fix: cópia manual do SVG OU phase7-cutover step 1e
    (v1.6.3+ sincroniza S3→FS). Ref: memo `feedback_preloader_filesystem_local.md`.
    Severidade: **HIGH** — page transition visualmente quebrada.

22. **Elementor CSS contém URL de DEV (Fase 9) — BLOCKER**: `gate_22_elementor_css_dev_leak.count > 0`.
    Pelo menos 1 arquivo CSS em `/uploads/elementor/` ou `/elementor-cache/` referencia
    `concertacao.bureau-it.com`, `cambrasmax.local` ou `localhost:NNNN` em prod. Causa: Elementor
    CSS files não foram regenerados após DB import e WP Rocket cacheia CSS poluído. Fix:
    `wp elementor flush_css` + `rocket_clean_post --post_id=X` + invalidação CF cirúrgica.
    Ref: memo `feedback_filesystem_cache_post_deploy.md`. Severidade: **BLOCKER** — site público
    em prod servindo URLs de dev (vazamento silencioso, sem 4xx).

23. **Google Fonts externos no DOM (Fase 9) — refinado em 2 sub-gates**:

    **Gate 23a (HIGH) — fonte/CSS externa real**:
    `gate_23_google_fonts_external.stylesheet_count > 0` OU `gate_23_google_fonts_external.font_request_count > 0`.
    Página carrega de fato CSS (`<link rel="stylesheet">`) ou fonte (`<link rel="preload" as="font">`)
    de `fonts.googleapis.com` / `fonts.gstatic.com`. Plus Jakarta Sans é self-hosted no tema child
    desde 2026-05-02 — refs como stylesheet/preload indicam plugin/widget enqueue não auditado
    (TEC, JetEngine, Elementor widget novo). Severidade: **HIGH** — viola decisão arquitetural
    (privacidade + performance + CSP risk).

    **Gate 23b (INFO) — preconnect órfão**:
    `gate_23_google_fonts_external.preconnect_count > 0` MAS `stylesheet_count === 0` E `font_request_count === 0`.
    `<link rel="preconnect">` ou `<link rel="dns-prefetch">` para domínio Google Fonts apenas
    aquece TCP/TLS handshake — **não baixa CSS nem fonte**. Resíduo benigno injetado pelo WP core
    via `wp_resource_hints` filter quando algum CSS antigo ainda lista família Google. Severidade:
    **INFO** — não dispara FAIL, apenas reporta. Para limpar: identificar plugin que mantém ref
    via `remove_filter('wp_resource_hints', ...)` ou `wp_dequeue_style` no CSS culpado.

24. **Uploads em path /green/ vazando (Fase 9) — BLOCKER**: `gate_24_uploads_green_leak.count > 0`.
    Pelo menos 1 `<img src>` em prod aponta para path com `/green/` — provável
    `S3_UPLOADS_BUCKET=concertacaoamazonia-com-br-wp-static-prd-sa/green` em wp-config (deveria
    ser `/assets`). Fix: `wp config set S3_UPLOADS_BUCKET ...prd-sa/assets --type=constant` +
    `systemctl reload php8.3-fpm` + `aws s3 sync green/uploads/ assets/uploads/` + invalidação CF.
    Ref: memo `feedback_cf_oac_green_to_assets_swap.md`. Severidade: **BLOCKER** — incidente
    silencioso recorrente que causou perda de preloader Elementor por 24h em 2026-05-02.

25. **Spotify embed em /cultura/porosidades/ (Fase 9) — HIGH**: incidente 2026-05-18.
    Página de exposição com widget HTML embedando playlist Spotify (`open.spotify.com/embed/...`).
    Sub-gates (todos devem passar):
    - `gate_25_spotify_embed.csp_header_captured === false` — não capturou CSP da response.
      Falha de instrumentação; revalidar manualmente via `curl -sI`.
    - `gate_25_spotify_embed.frame_src_has_spotify === false` — CSP `frame-src` sem
      `open.spotify.com`. **Browser bloqueia iframe inteiro** (renderiza `chrome-error://chromewebdata/`
      visível como "Este conteúdo está bloqueado").
    - `gate_25_spotify_embed.connect_src_has_spotify === false` — CSP `connect-src` sem
      `*.spotify.com`. Iframe carrega mas player não consegue chamar `api.spotify.com`/`spclient`
      → player aparece vazio ou trava em loading.
    - `gate_25_spotify_embed.media_src_has_scdn === false` — CSP `media-src` sem `*.scdn.co`.
      Player visível mas preview de 30s não toca (áudio servido pelo CDN scdn.co bloqueado).
    - `gate_25_spotify_embed.iframe_present === false` — iframe sumiu do DOM. Pode ser remoção
      acidental no Elementor ou bug de render do widget HTML.
    - `gate_25_spotify_embed.csp_error_count > 0` — console emitiu erro
      `Refused to frame 'https://open.spotify.com/' because it violates the following Content
      Security Policy directive` durante o carregamento. Confirmação direta de bloqueio (mesmo
      que header pareça ter os domínios — pode haver typo/encoding bug).

    Fix: editar `03-nginx-sites.sh` (template) + hotfix em
    `/etc/nginx/snippets/security-headers.conf` adicionando os 3 domínios; `sudo systemctl reload nginx`;
    invalidar CloudFront em `/cultura/porosidades/`. Ref: memo `feedback_csp_spotify_embeds.md`.
    Severidade: **HIGH** — quebra UX da exposição mas não derruba produto. Mesmo padrão que
    [[feedback_csp_youtube_embeds]] — cada novo provedor de embed exige domínios específicos.

26. **WPML orphan attachment leak (Fase 9) — BLOCKER**: incidente 2026-05-18 (CU 86ahhtk2d).
    WPML duplicate-on-translate criou 109 attachments EN em `wp_2_posts` sem espelho em `wp_posts`
    blog 1. Páginas EN do blog 2 renderizam widget Slides sem background-image e gallery thumbs
    quebradas. Sub-gates (qualquer um falha):
    - `gate_26_wpml_orphan_leak.total_orphan_refs > 0` — pelo menos 1 ref `/sites/2/uploads/` no
      HTML de alguma página EN. Indica que `wp_get_attachment_url` está retornando path do subsite
      em vez de resolver via sibling pt-br. **Causa provável**: mu-plugin
      `bit-crossblog-attachment-fix.php` ausente, desativado, ou versão < 1.5.2.
    - `gate_26_wpml_orphan_leak.pages_with_bug > 0` — qualquer página teve um dos 3 sintomas:
      orphan_refs > 0, widget Slides com 0 backgrounds quando total > 0, ou >2 imgs quebrados.
      Reportar `per_path[N].path` específico no relatório.
    - `gate_26_wpml_orphan_leak.total_broken_imgs > 8` — toleramos ≤2 por página (placeholder/loaded
      after timing). Acima disso indica regressão sistêmica.

    Fix: validar 3 frentes — (a) mu-plugin v1.5.2+ presente e ativo no servidor
    (`grep '* Version' /var/www/.../wp-content/mu-plugins/bit-crossblog-attachment-fix.php`),
    (b) `elementor_css_print_method=external` no blog 2
    (`wp --url=https://concertacaoamazonia.com.br/cultura/ option get elementor_css_print_method`),
    (c) WPML media duplication OFF nos 2 blogs
    (`wp eval 'print_r(\WPML\Media\Option::getNewContentSettings());'` deve retornar `[false, false, false]`).
    Se mu-plugin OK e settings OK, regenerar Elementor CSS das 4 páginas EN
    (`(new \Elementor\Core\Files\CSS\Post($id))->update_file()`) + invalidar CF cirúrgico em
    `/cultura/en/*`. Ref: memos `feedback_wpml_orphan_attachments_blog2.md` +
    `project_wpml_orphan_fix_deployed_prod.md`.

    Severidade: **BLOCKER** — bug é silencioso (`/smoke` sem este gate passa) e direto
    customer-facing (Fabricio reportou). Mu-plugin é estrutural; deployer deve verificar antes
    de cutover green.

27. **CSS MIME `text/html` (Fase 9) — BLOCKER**: incidente 2026-05-18 21:30 BRT (home concertação).
    Daniel reportou home sem CSS via screenshot — header verde OK mas hero e secções explodiram
    sem layout. Root cause: HTML cached no CloudFront referenciava
    `/wp-content/cache/min/1/wp-content/elementor-cache/elementor/css/post-2461.css?ver=1779102530`
    e `post-74762.css` (mesmo `?ver=`); os arquivos `.css` minificados não existiam no FS
    (regeneração parcial via Elementor save de outras páginas apagou os min files mas não
    invalidou o HTML). Nginx fallback ao WP retornou 404 com `content-type: text/html` →
    strict MIME do browser rejeitou o CSS → layout quebrado mesmo com origin sano.

    Sub-gate (BLOCKER):
    - `gate_27_css_mime_check.total_bad_stylesheets > 0` — pelo menos 1 `<link rel=stylesheet>`
      com `Content-Type != text/css` em qualquer página do snippet (home + /cultura/ por default).
      Reportar `per_path[N].bad_samples[0..4]` com `href`, `status` (geralmente 404), e
      `content_type` real (geralmente `text/html; charset=utf-8`).

    Fix conservador (sem flush global, sequência testada no incidente):
    1. Identificar `post_id` afetado pelo path do CSS (ex: `/cache/min/1/.../post-2461.css` → `2461`)
    2. `wp eval 'rocket_clean_post(<POST_ID>);'`
    3. `wp eval 'rocket_clean_minify("css");'` (regenera no próximo hit)
    4. `wp eval '(new \Elementor\Core\Files\CSS\Post(<POST_ID>))->update();'` (opcional, se Elementor CSS file também estiver fora)
    5. CF invalidate cirúrgico em `/` (NUNCA usar `/*`): `aws cloudfront create-invalidation --distribution-id E2F1QD7E7YOYEB --paths '/' --profile Concertação`
    6. Aguardar invalidation (1-3min) e re-rodar gate 27 — esperado: 0 bad stylesheets

    Severidade: **BLOCKER** — visual catastrófico, browser detecta mas WP-CLI/curl direto não
    (testar com `curl -sI .../post-N.css` revela o 404). Vetor sistêmico: `cache_*` invalidação
    não-coordenada entre WP Rocket / Elementor / CloudFront. Memos: `feedback_wprocket_min_stale_404_breaks_layout.md`,
    `feedback_filesystem_cache_post_deploy.md`, `feedback_elementor_flush_css_warmup.md`.

28. **Stale s3-uploads path em _elementor_data (Fase 9) — BLOCKER**: incidente 2026-05-18 (CU 86ahj85qk).
    URL legada do plugin s3-uploads ATIVO (`/wp-content/uploads/s3/<bucket>/assets/<path>`) sobrevive em
    `_elementor_data` após migrar para `s3-uploads OFF` + CF-OAC. Quando Elementor renderiza SVG inline
    (jet-button icons, Elementor button icon, dynamic tag image), faz `file_get_contents` no path local
    derivado da URL — path stale resolve para arquivo que NÃO existe no FS → SVG vazio. Sintoma direto:
    todos os ícones "+" verdes dos botões SAIBA MAIS sumiram em prod (567 ocorrências encontradas).

    Sub-gates:
    - `gate_28_legacy_s3_path.stale_refs_count > 0` (BLOCKER) — qualquer ocorrência do padrão
      `/wp-content/uploads/s3/` (path-local) **OU** `s3.<region>.amazonaws.com/<bucket>/assets/`
      (URL S3 direta) no HTML rendered. Reportar primeiros 5 samples. **AMPLIADO 2026-06-23**:
      o gate era CEGO à URL S3 direta — auditoria 3-agentes achou 776 refs em prod que passavam
      despercebidas (PDFs/imgs que davam 403 só no browser). Formas cobertas: path-local
      `wp-content/uploads/s3/<bucket>/assets/[uploads/]`, URL direta `s3.<region>.amazonaws.com/
      <bucket>/assets/[uploads/]`, bucket legacy us-east-1, bucket legacy `<fqdn>` path-style.
    - `gate_28_legacy_s3_path.jet_buttons_missing_svg > 0` (HIGH) — jet-button widgets com classe
      `--icon-right` (que indicam ícone configurado) mas SEM `<svg>` child no DOM. Sintoma direto
      do bug mesmo quando o gate principal não pega o padrão (caso URL stale tenha sido reescrita
      mas FS local ainda não tem o arquivo).

    Fix em 2 frentes:
    - **Dados (todas as formas)**: NÃO basta o path-local. Cobrir 2 passes ordenados (`.../assets/uploads/`
      ANTES de `.../assets/` p/ não duplicar `uploads/`), cada um em 2 escapings (single-slash em image
      widgets, `\/` escapado em link widgets), para CADA host/bucket: `s3.<region>.amazonaws.com/<bucket>`,
      `<fqdn>/wp-content/uploads/s3/<bucket>`, bucket legacy `s3.us-east-1.amazonaws.com/<fqdn>` e
      `<fqdn>/wp-content/uploads/s3/<fqdn>`. Destino sempre `https://<fqdn>/wp-content/uploads/`. A função
      `search_replace_legacy_s3_paths` (v2, 09-importdatabase.sh) já faz isso automaticamente — usar como
      referência. **Validar com PHP no conteúdo desescapado** (`str_replace(['\\/','\\\\/'],'/',$mv)` + regex
      `amazonaws\.com|/uploads/s3/ ... assets/`), NÃO com `grep` no LIKE cru (backslashes geram falso
      positivo/negativo). Áudio externo `audio_source:external` (ex: `s3.amazonaws.com/<fqdn>/*.mp3` na
      RAIZ do bucket, sem `/assets/`) NÃO conta — é link deliberado, não ref de mídia WP.
    - **Filesystem**: na maioria dos casos o arquivo JÁ existe no bucket prod sob `assets/uploads/<resto>`
      e serve 200 via CloudFront após o replace (validar com `aws s3 ls` antes). Só fazer `aws s3 cp` se
      o arquivo realmente faltar no bucket prod.

    Fix automatizado em `ec2-deploy/post-deploy/09-importdatabase.sh` v2.8.0+ via função
    `search_replace_legacy_s3_paths` (ampliada 2026-06-23 p/ cobrir URL direta + buckets legacy) que roda
    após `search_replace_tunnel_fqdn`. Independe de `S3_UPLOADS_BUCKET` para os buckets legacy. Memos
    relacionados: `feedback_s3_uploads_off_sync_required.md`, `feedback_preloader_filesystem_local.md`,
    `feedback_s3_bucket_concertacaoamazonia_us_east_decommission.md`.

    Severidade: **BLOCKER** — bug sistêmico (567 ocorrências detectadas) afetando todos os botões
    de call-to-action em prod. Visualmente catastrófico mas silencioso para HTTP probes.

29. **Emails com `:porta` órfã em `_elementor_data` (Fase 9) — BLOCKER**: incidente 2026-05-18 21:56 BRT.
    Newsletter footer de prod retornava `{success:false, errors:[], data:[]}` silencioso porque o
    `email_from_2` do form continha `email@concertacaoamazonia.com.br:8484` (porta DEV `:8484` órfã
    sobrando de `wp search-replace` que trocou hostname mas não a porta). `is_email()` rejeita →
    action "email" do Elementor Pro Forms aborta → submit falha sem mensagem clara ao usuário.
    Total detectado em prod: 106 forms afetados em 918 posts, distribuídos em blogs 1 + 2 do multisite.
    Bug silencioso há tempo indeterminado — todo lead via Newsletter footer foi perdido.

    Sub-gate (BLOCKER):
    - `bad > 0` no snippet SSH do gate 28 — qualquer ocorrência de `@host:NNNN` em 11 chaves
      email_* (`email_from`, `email_from_2`, `email_from_name`, `email_from_name_2`,
      `email_to`, `email_to_2`, `email_to_cc`, `email_reply_to`, `email_reply_to_2`,
      `email_subject`, `email_subject_2`) dentro de `_elementor_data` em posts publicados
      (revisions excluídas). Reportar `blog=N post=ID field=KEY value=...` para cada hit.

    Fix em 2 frentes:
    - **Dados (uma vez)**: rodar `scripts/regularize-form-emails.php` via SSH com `sudo -u www-data
      wp eval-file` (modo APPLY após DRY-RUN). Strippa `:porta` em todas as chaves email_*
      em `_elementor_data` filtrando revisions. Idempotente (rerun sem mudança = 0 fixes).
    - **Automatizado (pipeline deploy)**: função `fix_form_email_ports()` em
      `ec2-deploy/post-deploy/09-importdatabase.sh` roda após `search_replace_legacy_s3_paths`
      em todo deploy. Walker recursivo + regex `(@[A-Za-z0-9.\-]+):\d{1,5}\b` + `update_post_meta`
      com `wp_slash(wp_json_encode(... JSON_UNESCAPED_UNICODE))`. Não usar `JSON_UNESCAPED_SLASHES`
      (causa drift de `\/` vs `/` no Elementor data → não idempotente).

    Pós-fix: limpar cache do post afetado (`rocket_clean_post(<POST_ID>)`) + se for template
    global do footer, invalidar CloudFront em `/` (afeta todas as páginas).

    Severidade: **BLOCKER** — Newsletter/contato silenciosamente quebrados em prod, sem alarme
    em monitoring (HTTP 200 + JSON success:false sem trace de erro). Vetor sistêmico:
    `wp search-replace` cego em `_elementor_data` JSON. Memos: `feedback_form_email_port_drift.md`,
    `feedback_elementor_data_wp_slash_required.md`.

30. **wp_mail() funcional via SSH (Fase 7.8b) — BLOCKER**: incidente 2026-05-19.

    Valida que `wp_mail()` retorna `true` ao invés de `false`. Detecta:
    - Constantes SMTP_* ausentes em wp-config.php (incidente 2026-05-19: blue-green
      provisionamento como HML com `SMTP_HOST_HML` vazio no .env, cascata pra PROD
      não disparou, ses-mailer.php inerte, `wp_mail()` cai em PHP `mail()` →
      `/usr/sbin/sendmail not found` → returns false)
    - Credenciais SES revogadas/expiradas (SMTP auth fail)
    - Bloqueio outbound porta 587/SMTPS pelo SG ou WAF
    - mu-plugin `ses-mailer.php` ausente
    - PhpMailer.ErrorInfo populado mesmo retornando true (delivery deferido)

    Comando de validação SSH (rodar pós-deploy):
    ```bash
    ssh prod-sa "sudo -u www-data wp --path=/var/www/<SITE> --url=<URL> eval '
      \$ok = wp_mail(\"smoke-no-send@bureau-it.com\", \"smoke wp_mail test\", \"smoke\");
      echo \"wp_mail=\" . var_export(\$ok, true) . PHP_EOL;
      global \$phpmailer;
      if (isset(\$phpmailer) && is_object(\$phpmailer)) {
        echo \"host=\" . (\$phpmailer->Host ?? \"?\") . PHP_EOL;
        echo \"errorInfo=\" . (\$phpmailer->ErrorInfo ?: \"(none)\") . PHP_EOL;
      }
    '"
    ```

    Sub-gates (qualquer um falha):
    - `wp_mail=false` → submit pipeline silenciosamente quebrado
    - `phpmailer.Host` vazio ou diferente de `email-smtp.*` → SMTP_HOST não chegou ao PhpMailer
    - `phpmailer.ErrorInfo` populado (não vazio) → erro em runtime, mesmo se return true

    Fix:
    ```bash
    # 1. Verificar constantes SMTP em wp-config.php
    ssh prod-sa "sudo grep -E 'SMTP_(HOST|PORT|USERNAME|PASSWORD|FROM)' /var/www/<SITE>/wp-config.php"

    # 2. Se ausentes: re-rodar a1-wordpress-autoconfigure.sh
    ssh prod-sa "cd /home/ubuntu/post-deploy && sudo ENVIRONMENT=prod bash a1-wordpress-autoconfigure.sh /var/www/<SITE>"

    # 3. Reload FPM
    ssh prod-sa "sudo systemctl reload php8.3-fpm"

    # 4. Re-validar gate 30
    ```

    Severidade: **BLOCKER** — quando wp_mail falha, todo form Elementor Pro com
    action email retorna `{success:false, errors:[]}` silencioso. Bug invisível
    pra HTTP probes. Memo: `feedback_smtp_constants_missing_prod.md`.

## Fase 10 — Outline HTML semântico (acessibilidade + SEO)

Valida estrutura semântica das páginas para garantir acessibilidade (WCAG SC 2.4.6
"Headings and Labels") e SEO (Google ranking depende de hierarquia de headings + uso
correto de landmarks). Combo de 2 gates:

- **Gate 31 (estrutural)**: rodado em ~15 paths (12 menu + 3 singles de CPT). Valida
  exatamente 1 `<h1>`, hierarquia sem pular níveis (h2→h4), `<main>` presente, `<article>`
  em singles de CPT.
- **Gate 32 (snapshot home)**: captura outline completo da home e compara com snapshot
  versionado em `.claude/commands/smoke-snapshots/home-outline.json`. Detecta QUALQUER
  mudança estrutural — exige update consciente do snapshot quando layout muda.

### Snippet — Gate 31 (estrutural multi-página)

Lista de paths cobre menu principal + 1 single de cada CPT público (descoberta dinâmica
via `post-type list --public=1`). Custo ~30s para 15 paths via curl + Python.

```bash
ssh concertacaoamazonia.com.br-prod-sa "sudo -u www-data \
  wp --path=/var/www/concertacaoamazonia.com.br --url=https://concertacaoamazonia.com.br \
  eval '
\$paths = [\"/\"];
// Menu paths (estáticos — sincronizar com Snippet 1 da Fase 7.5)
foreach ([\"/sobre-nos/\",\"/sobre-nos/4-amazonias/\",\"/atuacao/\",\"/conhecimento/\",
          \"/conhecimento/espiral-de-conhecimento/\",\"/cultura/\",
          \"/cultura/atlas-cultural-das-amazonias/\",\"/agenda-integradora/\",
          \"/contato/\",\"/eventos/lista/\"] as \$p) \$paths[] = \$p;
// 1 single de cada CPT public (descoberta dinâmica)
foreach ([\"post\",\"tribe_events\",\"estudos\",\"releases\",\"100dias\",
          \"webinarios\",\"plenarias\"] as \$cpt) {
  \$ps = get_posts([\"post_type\"=>\$cpt,\"posts_per_page\"=>1,
                    \"post_status\"=>\"publish\",\"orderby\"=>\"date\",\"order\"=>\"DESC\"]);
  if (!empty(\$ps)) \$paths[] = parse_url(get_permalink(\$ps[0]->ID), PHP_URL_PATH);
}
foreach (\$paths as \$p) echo \$p . PHP_EOL;
'" 2>&1 | grep -v Deprecated | grep '^/' > /tmp/smoke-paths.txt

# Para cada path: curl + Python parse outline
python3 <<'PY' < /tmp/smoke-paths.txt
import re, sys, subprocess, urllib.parse
BASE = "https://concertacaoamazonia.com.br"
SINGLE_CPT_REGEX = re.compile(r"^/(event|estudos|releases|100dias|webinarios|plenaria|veiculo|plataforma)/[^/]+/?$")
results = []
for path in [l.strip() for l in sys.stdin if l.strip()]:
    url = BASE + path
    html = subprocess.check_output(["curl","-s",f"{url}?cb=smk"], timeout=30).decode()
    # Parse headings em ordem
    heads = [(m.group(1).lower(), re.sub(r"\s+"," ", re.sub(r"<[^>]+>"," ",m.group(2))).strip()[:60])
             for m in re.finditer(r"<(h[1-6])\b[^>]*>(.*?)</\1>", html, re.DOTALL|re.IGNORECASE)]
    h1_count = sum(1 for h in heads if h[0]=="h1")
    # Hierarchy: cada nível só pode aumentar 1 por vez
    skips = []
    last_level = 0
    for tag,_ in heads:
        lvl = int(tag[1])
        if last_level and lvl > last_level + 1:
            skips.append(f"h{last_level}→{tag}")
        last_level = lvl
    has_main = bool(re.search(r"<main\b", html, re.IGNORECASE))
    has_article = bool(re.search(r"<article\b", html, re.IGNORECASE))
    is_single = bool(SINGLE_CPT_REGEX.match(path))
    issues = []
    if h1_count != 1: issues.append(f"h1_count={h1_count}")
    if skips: issues.append(f"skips:{','.join(skips[:3])}")
    if not has_main: issues.append("no_main")
    if is_single and not has_article: issues.append("no_article_in_single")
    status = "OK" if not issues else "FAIL: "+", ".join(issues)
    print(f"{status:<60} {path}")
    results.append((path, issues))
print(f"\nTotal: {len(results)} paths, {sum(1 for _,i in results if not i)} pass, {sum(1 for _,i in results if i)} fail")
PY
```

**Esperado (PASS):** todos paths com `OK`. Singles de CPT (event/estudos/releases/100dias/webinarios/plenaria) devem ter `<main>` E `<article>`.

**Sub-gates (qualquer um falha — severidade HIGH, não BLOCKER):**
- `h1_count != 1` — múltiplos h1 (confuso pra screen reader + SEO penalty) OU 0 h1
- `skips` > 0 — hierarquia pulou nível (ex: h2→h4)
- `no_main` — landmark `<main>` ausente (acessibilidade WCAG)
- `no_article_in_single` — single de CPT sem `<article>` (SEO + a11y)

### Snippet — Gate 32 (snapshot home)

Captura outline completo da home + landmarks e compara com snapshot versionado.

```bash
# Captura outline atual
curl -s "https://concertacaoamazonia.com.br/?cb=$(date +%s)" 2>/dev/null > /tmp/home-now.html
python3 <<'PY' > /tmp/home-outline-now.json
import re, json
with open('/tmp/home-now.html') as f: doc = f.read()
outline = {
  "headings": [
    {"tag": m.group(1).lower(),
     "text": re.sub(r"\s+"," ", re.sub(r"<[^>]+>"," ", m.group(2))).strip()[:80]}
    for m in re.finditer(r"<(h[1-6])\b[^>]*>(.*?)</\1>", doc, re.DOTALL|re.IGNORECASE)
  ],
  "landmarks": {tag: len(re.findall(r"<"+tag+r"\b[^>]*>", doc, re.IGNORECASE))
                for tag in ['header','nav','main','article','aside','footer']}
}
print(json.dumps(outline, ensure_ascii=False, indent=2))
PY

# Comparar com snapshot versionado
SNAP=.claude/commands/smoke-snapshots/home-outline.json
if [[ ! -f "$SNAP" ]]; then
  mkdir -p "$(dirname "$SNAP")"
  cp /tmp/home-outline-now.json "$SNAP"
  echo "[INFO] snapshot inicial criado em $SNAP — re-rodar gate 32 para validar"
else
  diff -u "$SNAP" /tmp/home-outline-now.json && echo "✅ Gate 32 PASS — outline home idêntico ao snapshot" \
    || echo "🚨 Gate 32 FAIL — outline home divergiu. Inspecionar diff. Se intencional, atualizar snapshot: cp /tmp/home-outline-now.json $SNAP"
fi
```

**Esperado (PASS):** `diff` vazio (snapshot bate exatamente). Quando layout/conteúdo da home muda intencionalmente, atualizar snapshot com `cp` (decisão consciente).

**Severidade**: HIGH. Snapshot serve como **canário de mudanças estruturais não-intencionais** —
typo no template, plugin que injeta heading errado, regression de tradução, etc.

### Gates 31+32 — Gates de FAIL

31. **Outline estrutural (Fase 10) — HIGH**: incidente 2026-05-19.

    Sub-gates (qualquer um em qualquer path falha o smoke):
    - `h1_count != 1` em qualquer página (singles devem ter exatamente 1, home pode ter 1 do título do template; 0 ou >1 é bug)
    - `skips` (h-pula-nível) em qualquer página
    - `no_main` em qualquer página (landmark obrigatório WCAG SC 2.4.1)
    - `no_article_in_single` em single de CPT (SEO Schema.org)

    Bugs descobertos em concertação 2026-05-19 (deixados sem fix por decisão):
    - Home (`/`): H1 = "Eventos" do widget JetEngine; 9× h2 antes do h1
    - Single post: sem `<main>`, sem `<article>`
    - Single estudo: **0 h1**, sem `<main>`, sem `<article>` (crítico para SEO)
    - Single tribe_events: `<main>` presente (theme TEC sobrescreve), mas h1 duplicado

    Fix: child theme ajustes em single-{cpt}.php (adicionar `<main>` + `<article>` wrapper)
    e remover h1 acidental do widget JetEngine de eventos na home (trocar pra h2).
    Ref: `feedback_outline_html_bugs.md` (a criar quando for fixar).

    Severidade: **HIGH** (não BLOCKER porque site funciona, mas WCAG 2.4.6 falha + SEO penalty).

32. **Outline snapshot home (Fase 10) — MEDIUM**:

    `diff` entre snapshot versionado e outline atual da home != 0. Indica mudança
    estrutural não-validada. Pode ser:
    - Mudança intencional de layout/conteúdo (atualizar snapshot)
    - Plugin/widget novo injetando heading (auditar)
    - Tradução EN/PT divergente (snapshot pode precisar por-idioma futuramente)

    Fix: revisar diff. Se intencional, `cp /tmp/home-outline-now.json .claude/commands/smoke-snapshots/home-outline.json`.

### Gates 31d/31e/31g/31h — Detectores SEO (auditoria 2026-05-22)

Adicionados após auditoria 10 agentes (cycle 1+2) que validou o gate 31. Gates
secundários detectam classes de bug SEO que `outline_html` não cobre. Roda em
TODAS as páginas do array `OUTLINE_PATHS_HUB` + `OUTLINE_PATHS_SINGLES` da Fase 10.

31d. **noindex em hub indexável — BLOCKER**:

    `<meta name="robots" content="...noindex...">` em hub/single declarado público
    desindexa a página do Google. Falso positivo aceitável: páginas com auditoria de
    SEO declarada como "private" — listar exceção em snapshot. Origem: auditoria
    2026-05-22 levantou hipótese de noindex em `/conhecimento/` (confirmou: ausente,
    mas gate evita regressão futura).

    Sub-gate:
    - `meta_robots_noindex == true` em qualquer path do array `OUTLINE_PATHS_*`

    Fix imediato: identificar origem (Yoast UI / RankMath / mu-plugin / option
    `blog_public=0`) e remover `noindex`. Para hubs, Yoast deve ter
    "Permitir motores de busca: SIM" + "Configuração SEO: Padrão (indexável)".

31e. **soft 404 (HTTP 200 + body 404) — BLOCKER**:

    Página retorna `HTTP 200` mas `<title>` ou `<body class>` indicam página de
    erro. Google trata como soft 404 e desperdiça crawl budget. Origem: auditoria
    2026-05-22 detectou 5 URLs `/en/*` em prod com soft 404 vivo
    (sem WPML translation OU CPT permalink errado).

    Sub-gates (qualquer um falha):
    - HTTP status = 200 E `<title>` contém "Page not found"/"Página não encontrada"
    - HTTP status = 200 E `<body class>` contém `error404`

    Fix: criar redirect 301 no plugin Redirection (`wp_redirection_items`) para
    URL canônica equivalente. Cobertura mínima de testes: hubs PT + EN + 1 single
    por CPT.

31g. **Article JSON-LD ausente em singles — MEDIUM**:

    Singles de CPT públicos (`post`, `estudos`, `tribe_events`, `100dias`,
    `webinarios`, `releases`) devem ter `<script type="application/ld+json">` com
    `@type: Article` / `Event` / `BlogPosting`. Falta = perda direta de rich
    results no Google (~+35% CTR esperado em search snippets ricos vs simples).

    Sub-gate:
    - Em singles: `count(script[type="application/ld+json"][@type in (Article,BlogPosting,Event,NewsArticle)]) == 0`

    Fix: ativar/configurar SEO plugin (Yoast/RankMath) para emitir Article schema,
    OU adicionar emissão custom via `wp_head` no child theme (preferencial).

31h. **hreflang ausente em hubs multilíngues — MEDIUM**:

    Hubs com tradução WPML PT↔EN devem emitir `<link rel="alternate" hreflang="...">`
    para o par. Sem hreflang, Google pode indexar versão errada por idioma
    (cluster errado). Origem: auditoria 2026-05-22 detectou ausência em hubs PT+EN
    em prod concertação (WPML não emite hreflang por default no template Elementor
    dos hubs).

    Sub-gate (rodar só em hubs do array `OUTLINE_PATHS_HUB`):
    - `count(link[rel="alternate"][hreflang]) < 2` (espera-se mínimo pt-br + en)

    Fix: WPML Settings → Languages → "Add alternate URLs for the same content in
    different languages" → ON. Validar `<head>` da página depois.

33. **jet_download retorna 302 — HIGH**:

    `GET /?jet_download=<hash-amostra>` deve retornar **302** com header `Location:`
    apontando para `/wp-content/uploads/...`. Origem do gate: bug 2026-05-20 onde
    mu-plugin `bit-jet-s3-redirect.php` v1.0.0 falhava com CF-OAC + s3-uploads OFF
    (guard `strpos s3.|amazonaws.com` não matchava URL local) — handler caía em
    fallthrough, WordPress renderizava home page de 524KB em vez do PDF. Botões de
    download em todos os estudos retornavam HTML sem erro visível, sem que ninguém
    percebesse até usuário reportar.

    Pegadinha: usar `curl -X GET` (não HEAD). Bug nginx `$rocket_skip_reason`
    pre-v1.18.0 só cobria GET literal — HEAD com QS bypassava PHP servindo
    `index-https.html`. Gate 35 testa isso especificamente.

    Fix: validar mu-plugin v1.1.1+ ativo (`grep Version /var/www/.../mu-plugins/bit-jet-s3-redirect.php`),
    reload PHP-FPM, invalidar CF `/?jet_download=*`.

34. **jet_download target entrega binary via CF — HIGH**:

    Seguir o `Location:` do gate 33 e validar destino: **200** + content-type
    binário (`application/pdf|zip|...`) + `x-cache: ... cloudfront`. Origem do gate:
    auditoria 2026-05-20 detectou 2/2417 hashes (0.08%) com 403 do S3 — uploads
    duplicados ou deletados que ficaram órfãos no option `jet_elements_download_button_hashes`.
    Em produção real, esses 2 hashes retornavam 403 mas nenhum post atual os
    referenciava (eram fantasmas).

    Fix: validar que arquivo existe no S3 (`aws s3 ls bucket/assets/uploads/.../arquivo.pdf`).
    Se não existir: arquivo precisa ser re-uploaded, OU o post precisa ser atualizado
    para apontar para attachment diferente, OU o hash pode ser removido do option
    (se nenhum post atual referencia o ID).

35. **jet_download HEAD retorna 302 também — MEDIUM (probe regression detector)**:

    `HEAD /?jet_download=<hash>` deve retornar **302** (idêntico ao GET). Se vier
    200 com HTML 524KB, é regressão do bug nginx `$rocket_skip_reason` regex
    (deve cobrir `GET|HEAD`, não só `GET`). Origem do gate: 2026-05-20 descobri
    que HEAD com QS `jet_download` bypassava PHP via `try_files $rocket_root_cache`
    porque map nginx só capturava GET literal. Fix em `03-nginx-sites.sh` v1.18.0.

    Usuários reais (GET) não foram impactados. Mas probes Pingdom/UptimeRobot,
    monitoring scripts com `curl -I`, auditorias automatizadas com HEAD: todas
    veriam falsos positivos antes do fix.

    Fix: aplicar `03-nginx-sites.sh` v1.18.0+ ou patchar manualmente
    `/etc/nginx/nginx.conf` substituindo `"~^.:GET:.+"` por `"~^.:(GET|HEAD):.+"`,
    `nginx -t && systemctl reload nginx`.

36. **JetEngine Listing Grid Load More carrega cards — HIGH**:

    POST do botão "Carregar mais" do JetEngine ListingGrid deve ir para
    `/wp-admin/admin-ajax.php` (não para a URL da página) e retornar **200** +
    aumentar a contagem de `.jet-listing-grid__item` no DOM. Origem do gate:
    incidente 2026-05-20 onde o JetEngine envia POST para
    `<URL da página>?nocache=<ts>` por padrão (filtro `jet-engine/listings/ajax-listing-url`
    retornando `home_url()` em vez de `admin_url('admin-ajax.php')`) — CloudFront
    rejeita com 403 porque `DefaultCacheBehavior.AllowedMethods=[HEAD,GET]`.

    Afeta 8 páginas em prod (4 PT + 4 EN): Espiral, Mapa de Plataformas,
    Publicações, 4 Amazônias + versões EN. Sem o fix, todos os botões
    "Carregar mais" / "Load more" retornam silenciosamente sem erro JS visível.

    Fix: mu-plugin `bit-jet-loadmore-ajax-url.php` v1.0.0+ ativo
    (`grep Version /var/www/.../mu-plugins/bit-jet-loadmore-ajax-url.php`),
    reload PHP-FPM.

37. **Cross-blog `<img>`/srcset 4xx em /cultura/ (multisite NML) — HIGH**:

    Página blog 2 (`/cultura/*`) renderiza `<img srcset>` com URLs apontando
    para `/sites/N/uploads/...` que retornam 4xx (arquivos não existem nesse
    path — só em `/uploads/<YYYY>/<MM>/`). Origem: WP core `wp_calculate_image_srcset()`
    usa `wp_get_upload_dir()` do contexto blog atual (blog 2), ignorando
    `$image_src` já corrigido pelo Hook 9 do mu-plugin `bit-crossblog-attachment-fix.php`
    (NML/Hook 9 reescreve `src`, mas não srcset). Hook 13 só cobria órfãos
    WPML; Hook 14 v1.6.0+ cobre o caso default NML.

    Sub-gates (qualquer um falha):
    - `failed_4xx_count > 0` em qualquer página — pelo menos 1 `<img>` com
      `srcset` apontando para `/sites/N/uploads/` retornou 4xx ao browser
    - `sites_n_in_html_refs > 0` em qualquer página — DOM ainda contém refs
      `/sites/N/uploads/` (Hook 14 não está atuando — versão antiga, OPcache
      stale, ou plugin terceiro re-injeta path)
    - `broken_count > 0` (`naturalWidth === 0`) — sintoma direto de srcset
      quebrado. Filtrar imgs com `offsetParent !== null` para evitar falso
      positivo de elementos hidden/lazy-load não acionado.

    Cobertura: 6 páginas blog 2 (lista no snippet) + 1 página EN para validar
    Hooks 9-13 (caso WPML). Snippet em "### Snippet — Gate 37".

    Fix em sequência: validar mu-plugin v1.6.0+ ativo
    (`ssh prod-sa 'sudo grep "* Version" /var/www/.../mu-plugins/bit-crossblog-attachment-fix.php'`)
    + reload PHP-FPM + CF invalidate cirúrgico das páginas afetadas.
    Runbook: `docs/runbook-crossblog-403.md`.
    Memória: [[feedback_nml_crossblog_srcset_hook14]].

### Snippet — Gate 36 (JetEngine load-more end-to-end)

Após gates 33-35, antes do relatório. **Usa Playwright** (precisa de DOM real
e do JS do JetEngine para disparar o POST). Navega para `/espiral-de-conhecimento/`,
conta cards iniciais, clica `#espiralLoadMore`, espera o POST a `admin-ajax.php`
completar, conta cards de novo.

```javascript
// Snippet smoke (browser_*) — gate 36
const URL_TEST = "https://concertacaoamazonia.com.br/espiral-de-conhecimento/?eixo=eixo1&_label=governanca&jsf=jet-engine:estudos&tax=eixos:172#estudos";

await page.goto(URL_TEST, { waitUntil: "networkidle" });

const pre = await page.evaluate(() => ({
  items: document.querySelectorAll('.jet-listing-grid__item').length,
  ajaxlisting: (window.JetEngineSettings || {}).ajaxlisting,
}));

// Gate 36a: ajaxlisting deve ser admin-ajax.php (não URL da página)
const gate_36a = typeof pre.ajaxlisting === "string"
  && /\/wp-admin\/admin-ajax\.php$/.test(pre.ajaxlisting);

// Captura resposta do load-more
const responsePromise = page.waitForResponse(r =>
  /\/wp-admin\/admin-ajax\.php/.test(r.url()) && r.request().method() === "POST",
  { timeout: 10000 }
);

await page.click('#espiralLoadMore a.jet-button__instance');

let postStatus = null;
try {
  const resp = await responsePromise;
  postStatus = resp.status();
} catch (e) { postStatus = "TIMEOUT"; }

await page.waitForTimeout(2000);

const post = await page.evaluate(() => ({
  items: document.querySelectorAll('.jet-listing-grid__item').length,
}));

const gate_36b = postStatus === 200;
const gate_36c = post.items > pre.items;

console.log(JSON.stringify({
  gate_36a_ajaxlisting_is_admin_ajax: { pass: gate_36a, ajaxlisting: pre.ajaxlisting },
  gate_36b_post_200: { pass: gate_36b, status: postStatus },
  gate_36c_items_increased: { pass: gate_36c, pre: pre.items, post: post.items },
}, null, 2));
```

**Gates do snippet:**
- Gate 36a PASS: `JetEngineSettings.ajaxlisting` aponta para `admin-ajax.php`.
  Se FAIL com URL da página → mu-plugin `bit-jet-loadmore-ajax-url.php` não está
  ativo ou OPcache não foi recarregado.
- Gate 36b PASS: POST a `admin-ajax.php` retorna 200. Se FAIL com 403/timeout
  → CF bloqueando (path errado, mu-plugin inerte) ou origin caído.
- Gate 36c PASS: `items_post > items_pre`. Se FAIL com counts iguais → handler
  PHP `wp_ajax_jet_engine_ajax` não respondeu corretamente (verificar logs
  PHP-FPM, signature da query, post_status filter).

37. **Cross-blog `<img>`/srcset 4xx em /cultura/ (multisite NML) — HIGH**:

    Origem do gate: bug 2026-05-21 onde attachment 92371 ("Onde possamos sonhar, 2026")
    vivia só em `wp_posts` (blog 1) e era referenciado por página `/cultura/` (blog 2)
    via Elementor. WordPress core `wp_calculate_image_srcset()` reconstruía URLs
    usando `wp_get_upload_dir()` do contexto blog 2, gerando `srcset` com
    `/sites/2/uploads/...` que retornavam 403 do S3. Hook 13 do mu-plugin não
    cobria (só atuava em órfãos WPML). Hook 14 v1.6.0+ cobre o caso default
    do Network Media Library.

    Bug é **invisível ao curl simples** (basta inspecionar HTML, sem fazer HEAD
    nos assets) — daí o gate via Playwright que faz fetch real.

    Cobertura: 5 páginas representativas do blog 2 + página afetada conhecida.

    Sub-gates (qualquer um falha):
    - `gate_37_4xx_per_page > 0` em qualquer página — pelo menos 1 `<img>` com
      `srcset` apontando para `/sites/N/uploads/` retornou 4xx ao browser
    - `gate_37_sites_n_refs > 0` em qualquer página — DOM ainda contém refs
      `/sites/N/uploads/` (Hook 14 não está atuando)
    - `gate_37_broken_imgs > 2` em qualquer página — `naturalWidth === 0` em
      imagens completas é sintoma direto de srcset quebrado

    Fix em sequência: validar mu-plugin v1.6.0+ ativo + reload PHP-FPM +
    CF invalidate cirúrgico das páginas afetadas. Runbook completo:
    `docs/runbook-crossblog-403.md`. Memória: `feedback_nml_crossblog_srcset_hook14.md`.

38. **WP Rocket RUCSS queue collapse — HIGH**: incidente 2026-05-21.

    Quando `remove_unused_css=1` e SaaS RUCSS retorna 400 (license invalid,
    domínio banido pós-cutover, ou throttling), a tabela `wp_wpr_rucss_used_css`
    acumula jobs `failed` + `to-submit` infinitamente (cada hit na home enfileira
    novo job, cada retry falha de novo). Concertação tinha **9009 jobs travados,
    0 completed em 8 dias** quando o gate 27 começou a reincidir.

    Causa secundária: o ciclo `enfileira → falha → retry → WP Rocket regenera
    HTML cache` cria janela de race entre HTML cached (referencia `?ver=X`) e
    Elementor CSS file (regravado com `?ver=Y` por save de outro template) —
    sintoma observável é gate 27 (CSS MIME `text/html`) reincidindo após fix
    manual.

    **Validação via SSH** (não cabe em Playwright — precisa SQL):

    ```bash
    ssh prod-sa "sudo -u www-data wp --path=/var/www/<SITE> \
      db query 'SELECT status, COUNT(*) AS n FROM wp_wpr_rucss_used_css GROUP BY status'"
    ```

    Sub-gates (qualquer um falha):
    - `failed > 50` — RUCSS colapsando. Investigar resposta SaaS (license, ban
      em wp-rocket.me, throttling).
    - `failed > 500` — CRITICAL — desabilitar RUCSS imediatamente
      (`remove_unused_css=0`) para parar ciclo de regeneração de HTML cache.
    - `(to-submit + pending) > 200` E `completed == 0` há > 1h — pipeline parado,
      mesmo diagnóstico.
    - `completed > 0` E `failed < 50` — OK, RUCSS funcional.

    Fix rápido (banhar SaaS RUCSS sem investigar):
    ```bash
    wp option patch update wp_rocket_settings remove_unused_css 0
    wp db query 'DELETE FROM wp_wpr_rucss_used_css'
    wp db query 'DELETE FROM wp_actionscheduler_actions WHERE hook LIKE "%rocket_saas%"'
    wp eval 'rocket_clean_domain();'
    sudo find /var/www/<SITE>/wp-content/cache/min -type f -delete
    aws cloudfront create-invalidation --paths "/" "/wp-content/cache/min/*" "/wp-content/elementor-cache/*"
    ```

    Fix definitivo (se quiser RUCSS funcionando):
    1. wp-rocket.me → Account → Sites → encontrar domínio → Ban + Unban
       (força re-validação SaaS)
    2. Aguardar 2-5min, reabilitar `remove_unused_css=1`
    3. Inserir 1 job teste manual: `INSERT INTO wp_wpr_rucss_used_css ...`
    4. Disparar `wp action-scheduler run`
    5. Verificar se job_id retornou (não-vazio = SaaS aceitou)

    Memória: `feedback_wp_rocket_rucss_saas_collapse.md`.

39. **Espiral do Conhecimento — i18n term_ids — BLOCKER**: incidente 2026-05-22.

    Tema **sensível para o cliente**. O widget `bit-elementor-espiral-widget`
    sintetiza 21 links com `?eixo=eixoN&tax=eixos:<term_id>` apontando para o
    filtro JSF do JetEngine na página "Espiral de Conhecimento". WPML mantém
    term_ids separados por idioma na taxonomia `eixos` (PT 184 "Mudanças
    Climáticas" ≠ EN 1646 "Climate Change"). Antes do fix v2.2.0 do mu-plugin,
    todos os links em `/en/` usavam IDs PT → filtro retornava 0 cards.

    Gate valida que cada um dos 21 axes em PT e EN bate com o mapa canônico
    `SPIRAL_AXES_MAP` (snippet "Gate 39"). Sub-gates:
    - `wrong_en.length > 0` — algum eixo em `/en/` divergiu do mapa (ID PT
      vazando para EN, ou ID inválido). Investigar filtro `wpml_object_id`
      em `bit-elementor-espiral-widget.php` (bloco synth_links).
    - `wrong_pt.length > 0` — algum eixo em `/` (PT) divergiu — term
      renomeado/recriado no painel WPML, atualizar `SPIRAL_AXES_MAP`.
    - `pt.count !== 21` ou `en.count !== 21` — widget não renderizou todos os
      segmentos (Repeater quebrado ou widget removido da home).
    - `lang_ok === false` — WPML não setou `<html lang>` correto.

    Memória: validado em dev 2026-05-22 (PT 172→EN 1635, …).

### Snippet — Gate 38 (RUCSS health via SSH)

Após validações Playwright, antes do relatório. **Não é Playwright** — SQL via SSH.

```bash
# Em /smoke (Bash):
GATE_38_OUTPUT=$(ssh concertacaoamazonia.com.br-prod-sa "
  sudo -u www-data wp --path=/var/www/concertacaoamazonia.com.br \
    db query 'SELECT status, COUNT(*) AS n FROM wp_wpr_rucss_used_css GROUP BY status' \
    --skip-column-names 2>/dev/null
")

# Parsear contagens
FAILED=$(echo "$GATE_38_OUTPUT" | awk '$1=="failed"{print $2}')
PENDING=$(echo "$GATE_38_OUTPUT" | awk '$1=="pending"{print $2}')
TOSUBMIT=$(echo "$GATE_38_OUTPUT" | awk '$1=="to-submit"{print $2}')
COMPLETED=$(echo "$GATE_38_OUTPUT" | awk '$1=="completed"{print $2}')

# Defaults
: "${FAILED:=0}" "${PENDING:=0}" "${TOSUBMIT:=0}" "${COMPLETED:=0}"

# Verificar: RUCSS habilitado?
RUCSS_ENABLED=$(ssh concertacaoamazonia.com.br-prod-sa "
  sudo -u www-data wp --path=/var/www/concertacaoamazonia.com.br \
    eval 'echo (int)(get_option(\"wp_rocket_settings\", [])[\"remove_unused_css\"] ?? 0);'
")

echo "Gate 38 — RUCSS health:"
echo "  RUCSS habilitado: $RUCSS_ENABLED"
echo "  Counts: failed=$FAILED pending=$PENDING to-submit=$TOSUBMIT completed=$COMPLETED"

# Aplicar thresholds só se RUCSS habilitado
if [[ "$RUCSS_ENABLED" == "1" ]]; then
  if (( FAILED > 500 )); then
    echo "🚨 Gate 38 CRITICAL: failed=$FAILED > 500 — desabilitar RUCSS imediatamente"
    exit 1
  elif (( FAILED > 50 )); then
    echo "⚠️  Gate 38 FAIL: failed=$FAILED > 50 — investigar SaaS RUCSS"
    exit 1
  elif (( TOSUBMIT + PENDING > 200 && COMPLETED == 0 )); then
    echo "⚠️  Gate 38 FAIL: pipeline parado (to-submit+pending=$((TOSUBMIT+PENDING)), 0 completed)"
    exit 1
  else
    echo "✅ Gate 38 OK"
  fi
else
  echo "✅ Gate 38 OK (RUCSS desabilitado — sem risco)"
fi
```

**Gates do snippet:**
- Gate 38a PASS: `RUCSS_ENABLED=0` (desabilitado conforme decisão 2026-05-21)
- Gate 38b PASS: `failed < 50` E `(to-submit + pending) < 200 OR completed > 0`
- Gate 38c FAIL: `failed > 50` (RUCSS colapsando)
- Gate 38d CRITICAL: `failed > 500` (SaaS rejeita há dias)

### Snippet — Gate 37 (cross-blog srcset 4xx)

Roda em PROD (sem header X-Test-Green) ou GREEN (com header). Varre 5+ páginas
do blog 2 e detecta refs `/sites/N/uploads/` + 4xx em assets.

```javascript
// Snippet smoke (browser_run_code) — gate 37
async (page) => {
  const ctx = page.context();
  await ctx.setExtraHTTPHeaders(HEADER_VAL || {});
  await ctx.clearCookies();

  const BASE = 'https://concertacaoamazonia.com.br';
  const TARGET_BLOG = 2; // /cultura/
  const PAGES = [
    '/cultura/',
    '/cultura/atlas-cultural-das-amazonias/',
    '/cultura/poeticas-do-possivel/',
    '/cultura/exposicao-cores-do-futuro/',
    '/cultura/porosidades/',
    '/cultura/galeria/',
  ];

  const results = [];

  for (const path of PAGES) {
    const failed4xx = [];
    const responseHandler = (resp) => {
      const u = resp.url();
      const s = resp.status();
      if (s >= 400 && s < 500 && /\/sites\/\d+\/uploads\//.test(u)) {
        failed4xx.push({ status: s, url: u.slice(-100) });
      }
    };
    page.on('response', responseHandler);

    try {
      await page.goto(`${BASE}${path}?cb=${Date.now()}`, { waitUntil: 'networkidle', timeout: 45000 });
      // Lazy-load: scroll para forçar carregamento de imgs fora do viewport
      await page.evaluate(async () => {
        await new Promise(resolve => {
          const total = document.body.scrollHeight;
          let scrolled = 0;
          const step = 500;
          const timer = setInterval(() => {
            window.scrollBy(0, step);
            scrolled += step;
            if (scrolled >= total) { clearInterval(timer); window.scrollTo(0, 0); resolve(); }
          }, 100);
        });
      });
      await page.waitForTimeout(1500);

      const data = await page.evaluate((blog) => {
        const re = new RegExp(`/sites/${blog}/uploads/`, 'g');
        const html = document.documentElement.outerHTML;
        const sites_n_in_html = (html.match(re) || []).length;
        const imgs = Array.from(document.querySelectorAll('img'));
        const broken = imgs.filter(i => i.complete && i.naturalWidth === 0 && i.src);
        const srcset_with_sites_n = imgs.filter(i => re.test(i.srcset || '')).length;
        const src_with_sites_n = imgs.filter(i => re.test(i.src || '')).length;
        return {
          total_imgs: imgs.length,
          broken_count: broken.length,
          broken_samples: broken.slice(0, 3).map(i => (i.currentSrc || i.src).split('/').slice(-2).join('/')),
          srcset_with_sites_n,
          src_with_sites_n,
          sites_n_in_html_refs: sites_n_in_html,
        };
      }, TARGET_BLOG);

      results.push({
        path,
        ...data,
        failed_4xx_count: failed4xx.length,
        failed_4xx_samples: failed4xx.slice(0, 3),
      });
    } catch (e) {
      results.push({ path, error: (e.message || '?').slice(0, 120) });
    } finally {
      page.off('response', responseHandler);
    }
  }

  const fail_count = results.filter(r =>
    (r.failed_4xx_count || 0) > 0
    || (r.sites_n_in_html_refs || 0) > 0
    || (r.broken_count || 0) > 2
  ).length;

  return {
    total_pages: PAGES.length,
    fail_count,
    pass: fail_count === 0,
    per_page: results,
  };
}
```

**Gates do snippet:**

- Gate 37a PASS: `failed_4xx_count === 0` em todas as páginas. Se FAIL com 403/404
  em `/sites/N/uploads/` → Hook 14 não atuou (versão antiga? OPcache?).
- Gate 37b PASS: `sites_n_in_html_refs === 0`. Se FAIL → HTML ainda contém
  paths `/sites/N/` mesmo após render (Hook 14 corrige só `<img srcset>`,
  outros consumers podem renderizar errado).
- Gate 37c PASS: `broken_count <= 2` por página (tolerância de 2 para
  imagens fora do viewport ou lazy-load não acionado). Se FAIL com counts
  altos → bug ativo, abrir runbook `docs/runbook-crossblog-403.md`.

### Snippet — Gates 33-35 (jet_download integridade end-to-end)

Após gate 32, antes do relatório. **Não usa Playwright** — apenas `fetch()` ou
`curl` via `Bash`. Amostra 3 hashes de prod (extraídos do option) e testa cada
um nos 3 ângulos: GET 302, HEAD 302, target entrega binary via CF.

```javascript
// Snippet smoke (browser_run_code) — gates 33/34/35
const FQDN = "https://concertacaoamazonia.com.br";

// Lista de hashes conhecidos para amostragem (atualizar periodicamente):
// 1. bioeconomia (estudos) — ID 50691, PDF 2MB
// 2. tapajos-pesca (estudos) — ID 21901, PDF
// 3. covid-saude (estudos) — qualquer hash válido em prod
const SAMPLE_HASHES = [
  "6ee8392574e708633bb1fa4dcde0276585579216", // bioeconomia
  "9e08f20041254f32dd9c0c66eb0399878988f5a8", // tapajos-pesca
];

async function testHash(hash, method) {
  const url = `${FQDN}/?jet_download=${hash}`;
  // X-Test-Green com nanos garante CF cache miss (cache key permanente)
  const cacheBust = `smoke-jet-${Date.now()}-${Math.random().toString(36).slice(2,8)}`;
  const res = await fetch(url, {
    method,
    redirect: "manual",
    headers: { "X-Test-Green": cacheBust },
  });
  return {
    status: res.status,
    location: res.headers.get("location"),
    contentType: res.headers.get("content-type"),
    xCache: res.headers.get("x-cache"),
  };
}

async function testTarget(location) {
  const res = await fetch(location, {
    method: "HEAD",
    headers: { "X-Test-Green": `smoke-target-${Date.now()}` },
  });
  return {
    status: res.status,
    contentType: res.headers.get("content-type"),
    contentLength: res.headers.get("content-length"),
    xCache: res.headers.get("x-cache"),
  };
}

const results = { gate_33: [], gate_34: [], gate_35: [] };

for (const hash of SAMPLE_HASHES) {
  // Gate 33: GET -> 302 + Location uploads
  const get = await testHash(hash, "GET");
  const get_ok =
    get.status === 302 &&
    typeof get.location === "string" &&
    /\/wp-content\/uploads\//.test(get.location);
  results.gate_33.push({ hash, ...get, ok: get_ok });

  // Gate 35: HEAD -> 302 (não 200 HTML)
  const head = await testHash(hash, "HEAD");
  const head_ok = head.status === 302;
  results.gate_35.push({ hash, ...head, ok: head_ok });

  // Gate 34: target entrega binary via CF
  if (get.location) {
    const target = await testTarget(get.location);
    const target_ok =
      target.status === 200 &&
      typeof target.contentType === "string" &&
      /^(application\/(pdf|zip|octet-stream|msword|vnd\.|x-zip)|image\/|audio\/|video\/)/.test(target.contentType) &&
      typeof target.xCache === "string" &&
      /cloudfront/i.test(target.xCache);
    results.gate_34.push({ hash, location: get.location, ...target, ok: target_ok });
  } else {
    results.gate_34.push({ hash, ok: false, reason: "no Location from gate 33" });
  }
}

// Verdict
const gate_33_pass = results.gate_33.every((r) => r.ok);
const gate_34_pass = results.gate_34.every((r) => r.ok);
const gate_35_pass = results.gate_35.every((r) => r.ok);

console.log(JSON.stringify({
  gate_33_jet_get_redirect: { pass: gate_33_pass, details: results.gate_33 },
  gate_34_jet_target_binary_cf: { pass: gate_34_pass, details: results.gate_34 },
  gate_35_jet_head_redirect: { pass: gate_35_pass, details: results.gate_35 },
}, null, 2));
```

**Gates do snippet:**
- Gate 33 PASS: GET retorna 302 + Location apontando para `/wp-content/uploads/...`.
  Se FAIL com `status: 200, contentType: text/html` → mu-plugin não está ativo
  ou regressão da lógica `is_file($local_path)`.
- Gate 34 PASS: HEAD do `Location:` retorna 200 + content-type binário + `x-cache: cloudfront`.
  Se FAIL com `status: 403` → arquivo não existe no S3 (drift FS↔S3).
  Se FAIL com `xCache != cloudfront` → CF não está na frente (proxy errado).
- Gate 35 PASS: HEAD `/?jet_download=hash` retorna 302 igual ao GET.
  Se FAIL com `status: 200, contentType: text/html` → nginx `$rocket_skip_reason`
  regex não cobre HEAD (precisa `03-nginx-sites.sh` v1.18.0+).

### Snippet — Gate 39 (Espiral do Conhecimento — i18n term_ids)

Após gates 33-35, antes do relatório. **Tema sensível para o cliente** —
incidente 2026-05-22: links da Espiral em `/en/` apontavam para term_ids PT da
taxonomia `eixos`, resultando em **filtro JSF vazio** no JetEngine (PT 184
"Mudanças Climáticas" não casa com EN 1646 "Climate Change" porque WPML
mantém IDs separados por idioma).

**Não usa Playwright** — apenas `fetch()` no HTML da home PT e EN. Valida que
os 21 links da espiral em CADA idioma correspondem ao mapa canônico de term_ids
da taxonomia `eixos` (PT/EN). O mapa é **estável entre dev/HML/prod** porque
todos clonam do mesmo banco — qualquer divergência de ID indica regressão real
(widget pegou ID errado, term renomeado/recriado no painel, ou tradução WPML
quebrada).

```javascript
// Snippet smoke (browser_run_code) — gate 39
const FQDN = "https://concertacaoamazonia.com.br";

// Mapa canonico [pos, pt_term_id, en_term_id, label_pt] para os 21 eixos da
// taxonomia "eixos" (subtermos do termo "Espiral"). Estavel entre ambientes.
// Levantado via WPML em 2026-05-22 (dev). Se mudar em prod, ATUALIZAR aqui.
const SPIRAL_AXES_MAP = [
  [1,  172,  1635, "Governanca"],
  [2,  174,  1636, "Instrumentos de financiamento"],
  [3,  175,  1637, "Planos e politicas publicas"],
  [4,  176,  1638, "Negocios"],
  [5,  177,  1639, "Sociedade civil"],
  [6,  187,  1649, "Ciencia, tecnologia e inovacao"],
  [7,  178,  1640, "Cultura"],
  [8,  180,  1642, "Mudanca do uso do solo"],
  [9,  2013, 2488, "Ordenamento territorial e regularizacao fundiaria"],
  [10, 182,  1644, "Infraestrutura"],
  [11, 183,  1645, "Comunicacao e midia"],
  [12, 184,  1646, "Mudancas Climaticas"],
  [13, 185,  1647, "Agenda Internacional"],
  [14, 1819, 2387, "Educacao"],
  [15, 604,  1651, "Bioeconomia"],
  [16, 598,  1650, "Seguranca"],
  [17, 2479, 2489, "Saude"],
  [18, 2360, 2386, "Cidades"],
  [19, 2463, 2490, "Biodiversidade"],
  [20, 2401, 2491, "PIQCTs"],
  [21, 2464, 2492, "Direitos humanos"],
];

async function fetchSpiralAxes(path, useGreenHeader) {
  const headers = { "Cache-Control": "no-cache" };
  if (useGreenHeader) headers["X-Test-Green"] = `smoke-spiral-${Date.now()}`;
  const res = await fetch(`${FQDN}${path}?cb=${Date.now()}`, { headers });
  const html = await res.text();
  // Extrai TODOS os 21 links: id="Spiral26Text-N" ... href="...tax=eixos:M..."
  const regex = /<a[^>]+href="([^"]*tax=eixos:(\d+)[^"]*)"\s+id="Spiral26Text-(\d+)"/g;
  const axes = {};
  let m;
  while ((m = regex.exec(html)) !== null) {
    axes[parseInt(m[3], 10)] = parseInt(m[2], 10);
  }
  return {
    status: res.status,
    htmlLang: (html.match(/<html[^>]*\blang="([^"]+)"/) || [, ''])[1],
    axes,
    count: Object.keys(axes).length,
  };
}

// Buscar PT e EN (USE_GREEN = true se rodando contra green)
const USE_GREEN = false;
const pt = await fetchSpiralAxes("/", USE_GREEN);
const en = await fetchSpiralAxes("/en/", USE_GREEN);

const expected_count = SPIRAL_AXES_MAP.length; // 21
const both_have_21 = pt.count === expected_count && en.count === expected_count;

// Validacao posicao-a-posicao contra mapa canonico
const wrong_pt = [];
const wrong_en = [];
for (const [pos, expected_pt, expected_en, label] of SPIRAL_AXES_MAP) {
  if (pt.axes[pos] !== expected_pt) {
    wrong_pt.push({ pos, label, expected: expected_pt, got: pt.axes[pos] ?? null });
  }
  if (en.axes[pos] !== expected_en) {
    wrong_en.push({ pos, label, expected: expected_en, got: en.axes[pos] ?? null });
  }
}

const lang_ok = /^pt/i.test(pt.htmlLang) && /^en/i.test(en.htmlLang);
const gate_39_pass = both_have_21 && wrong_pt.length === 0 && wrong_en.length === 0 && lang_ok;

console.log(JSON.stringify({
  gate_39_spiral_axes_i18n: {
    pass: gate_39_pass,
    expected_count,
    pt: { count: pt.count, lang: pt.htmlLang },
    en: { count: en.count, lang: en.htmlLang },
    wrong_pt,           // posicoes onde tax=eixos:N divergiu do mapa em PT
    wrong_en,           // posicoes onde tax=eixos:N divergiu do mapa em EN
    lang_ok,
  },
}, null, 2));
```

**Gates do snippet:**

- **Gate 39 PASS:** PT e EN têm 21 axes cada, todos os term_ids correspondem ao mapa canônico `SPIRAL_AXES_MAP`, `<html lang>` correto em ambas.
- **Gate 39 FAIL — `wrong_en.length > 0`:** algum eixo em `/en/` tem `tax=eixos:<id>` que não bate com o mapa EN. Causa típica: regressão do filtro `wpml_object_id` em `bit-elementor-espiral-widget.php` — o widget está usando ID PT (que aparecerá em `got` enquanto o esperado está em `expected`). Severidade: **BLOCKER** — filtro JSF retorna vazio em `/en/`, listing de "Estudos" não aparece. Fix: garantir que `synth_links` ainda chama `apply_filters('wpml_object_id', $term_id, 'eixos', true, $current_lang)` antes de montar a URL.
- **Gate 39 FAIL — `wrong_pt.length > 0`:** algum eixo em `/` (PT) divergiu do mapa. Pode indicar: (a) Repeater editado no painel sem atualizar o mapa, (b) term `Espiral: X` deletado/recriado no painel WPML (novo ID), (c) widget regrediu para fallback que pega term errado. Comparar `got` com mapa atual via `wp term list eixos --parent=1148`.
- **Gate 39 FAIL — `pt.count != 21` ou `en.count != 21`:** widget Espiral não renderizou todos os 21 segmentos. Investigar `_elementor_data` da home (PT 2461, EN 2519) e estado do `axes_repeater`.
- **Gate 39 FAIL — `lang_ok === false`:** WPML não setou `<html lang>` correto.

**Manutenção do mapa:** se algum term `Espiral: X` for renomeado/recriado no
painel WPML, o ID muda. Atualizar `SPIRAL_AXES_MAP` neste snippet via:
```bash
ssh prod-sa "sudo -u www-data wp --path=/var/www/concertacaoamazonia.com.br eval '
  \$terms = get_terms([\"taxonomy\" => \"eixos\", \"parent\" => 1148, \"hide_empty\" => false]);
  foreach (\$terms as \$t) {
    \$en = apply_filters(\"wpml_object_id\", \$t->term_id, \"eixos\", false, \"en\");
    echo \$t->term_id . \" → EN \" . (\$en ?: 0) . \" (\" . \$t->name . \")\n\";
  }
'"
```

**Em green:** trocar `USE_GREEN = true`. O header `X-Test-Green` força
roteamento ALB para green target group + bypass CF cache.

40. **Paridade PT↔EN de páginas equivalentes — BLOCKER**: incidente 2026-05-25.

    O cliente reportou que `https://concertacaoamazonia.com.br/en/` estava
    redirecionando para `/en/blog/bid-emite-us-100-milhoes-...` (post de blog,
    não a home EN). Investigação descobriu que **CF cache estava servindo
    `.ics` (text/calendar)** para `/en/` por contaminação de cache:
    alguém acessou `/en/?ical=1`, o TEC gerou feed iCalendar, e o CF cacheou
    como key `/en/` (porque `ical` não estava na whitelist de query strings
    da Cache Policy `wp-cache-default-hostaware`).

    O bug era **invisível em probes HTTP normais** (HEAD curl pegou variante
    diferente do cache de outro PoP) e só apareceu em navegação real do
    browser, no PoP GRU3-P8.

    Gate compara cada par PT↔EN (via WPML `wpml_object_id` ou paths conhecidos)
    e valida que **ambos retornam mesmo Content-Type, sizes similares, e
    body class de mesmo template/post-type**. Detecta:
    - PT serve HTML, EN serve `text/calendar` (contaminação iCal)
    - PT 200, EN 301 (redirect cached)
    - PT body class `home page-id-X`, EN body class `single-post post-id-Y` (page errada cached)
    - PT 300KB+, EN <50KB (HTML truncado/erro)

    Sub-gates UNIVERSAIS (aplicam em PAIR + SOLO — validação do PT/single):
    - `pt_status_<code>` — status != 200 (redirect/4xx/5xx em página normal)
    - `pt_is_ics` — Content-Type contém `text/calendar` (smoking gun)
    - `pt_is_attachment` — `Content-Disposition: attachment` em página normal
    - `pt_redirects` — Location header em página normal (cache stale 301)
    - `pt_error404` — body class indica error404 (soft 404 silencioso)
    - `pt_size_small(Nb)` — body < 50KB (HTML truncado/corrompido)

    Sub-gates COMPARATIVOS (só PAIR — comparam PT vs EN):
    - `status_diff(P/E)` — códigos HTTP divergem
    - `ct_diff(P/E)` — Content-Type diverge
    - `size_diff(N%) > 70%` — tamanho diverge >70% (cache stale)
    - `bc_pt_home_en_not` / `bc_en_home_pt_not` — body class home vs não-home
    - `en_is_ics` / `en_is_attachment` / `en_error404` / `en_only_redirects` —
      mesmo conjunto do PT mas aplicado ao EN

    Origem do gate: 2026-05-25 16:00 BRT. Fix:
    1. CF invalidate cirúrgico `/en` `/en/` (resolve imediato).
    2. Atualizar Cache Policy `wp-cache-default-hostaware` (id `8e1062b8-291b-44d1-a8a1-fb7a1e4d6024`)
       incluindo `ical` + `outlook-ical` na whitelist de query strings — impede
       futura contaminação.

    Severidade: **BLOCKER** — bug customer-facing, descoberto pelo cliente,
    silencioso pra probes HEAD comuns.

### Snippet — Gate 40 (paridade PT↔EN)

Rodar via Bash/curl (não Playwright — precisa pegar Content-Type real do CF).

**Pares são descobertos DINAMICAMENTE via WPML** (`wpml_object_id`) — evita drift
de slugs hardcoded. Para cada page PT publish, resolve a tradução EN e usa o
permalink real. Inclui também home `/` ↔ `/en/` + roots de blog 2 (`/cultura/`).

```bash
export BASE="https://concertacaoamazonia.com.br"

# Descobrir pares dinamicamente via WPML (rodar 1x por execução do smoke).
# Cobertura em 3 modos:
#   PAIR  (PT↔EN comparativo): pages publish com tradução EN + 1 single de CPT
#         com tradução EN. Compara Content-Type, status, size, body class.
#   SOLO  (validação unilateral): 2 amostras de cada CPT public (incluindo CPTs
#         sem tradução WPML — tribe_events, estudos, tribe_venue, tribe_organizer).
#         Detecta render errado: 4xx/5xx, .ics em página normal, redirect inesperado,
#         body class error404, size <50KB.
#   ROOT  (homepage canonical): / + /en/ + /cultura/ + /cultura/en/
#
# Filtros pages PAIR:
#   - status=publish em PT E EN
#   - en_id != pt_id (pular pages sem tradução)
#   - SKIP page_on_front em PT E EN (evita canonical redirect /en/home/ → /en/
#     aparecer como FAIL falso; / e /en/ já são testados via root)
#
# Filtros singles SOLO:
#   - get_posts retorna até 2 publish mais recentes por CPT (orderby date DESC)
#   - CPT lista é dinâmica (get_post_types public=1) — pula internos: page,
#     attachment, elementor_library, e-floating-buttons, tribe_event_series
#
# Formato do output: `<path>||<kind>||<en_path_opcional>`
#   - `path||solo` — validação unilateral
#   - `pt_path||pair||en_path` — par comparativo
ssh concertacaoamazonia.com.br-prod-sa "sudo -u www-data \
  wp --path=/var/www/concertacaoamazonia.com.br --url=https://concertacaoamazonia.com.br \
  eval '
\$front_pt = (int) get_option(\"page_on_front\");
\$front_en = (int) apply_filters(\"wpml_object_id\", \$front_pt, \"page\", false, \"en\");
\$seen_pt_paths = []; // dedupe: SOLO não duplica path já coberto por PAIR

// PARTE 1 (PAIR): todas as pages com tradução EN
\$pages = get_pages([\"post_status\" => \"publish\"]);
foreach (\$pages as \$page) {
  if (\$page->ID === \$front_pt) continue;
  \$en_id = apply_filters(\"wpml_object_id\", \$page->ID, \"page\", false, \"en\");
  if (!\$en_id || \$en_id === \$page->ID) continue;
  if (\$en_id === \$front_en) continue;
  \$en_post = get_post(\$en_id);
  if (!\$en_post || \$en_post->post_status !== \"publish\") continue;
  \$pt_path = parse_url(get_permalink(\$page->ID), PHP_URL_PATH);
  \$en_path = parse_url(get_permalink(\$en_id), PHP_URL_PATH);
  echo \$pt_path . \"||pair||\" . \$en_path . PHP_EOL;
  \$seen_pt_paths[\$pt_path] = true;
}

// PARTE 2 (PAIR): 1 single de cada CPT com tradução EN
\$target_cpts = [\"post\", \"releases\", \"100dias\", \"webinarios\", \"plenarias\",
                \"tribe_events\", \"estudos\"];
foreach (\$target_cpts as \$cpt) {
  \$ids = get_posts([\"post_type\" => \$cpt, \"posts_per_page\" => -1,
                    \"post_status\" => \"publish\", \"fields\" => \"ids\"]);
  if (empty(\$ids)) continue;
  foreach (\$ids as \$pt_id) {
    \$en_id = apply_filters(\"wpml_object_id\", \$pt_id, \$cpt, false, \"en\");
    if (!\$en_id || \$en_id === \$pt_id) continue;
    \$en_post = get_post(\$en_id);
    if (!\$en_post || \$en_post->post_status !== \"publish\") continue;
    \$pt_path = parse_url(get_permalink(\$pt_id), PHP_URL_PATH);
    \$en_path = parse_url(get_permalink(\$en_id), PHP_URL_PATH);
    echo \$pt_path . \"||pair||\" . \$en_path . PHP_EOL;
    \$seen_pt_paths[\$pt_path] = true;
    break;
  }
}

// PARTE 3 (SOLO): 2 amostras de cada CPT public (mesmo sem tradução EN)
// — valida renderização básica de CPTs como tribe_events, tribe_venue,
// tribe_organizer, estudos que NÃO têm WPML configurado.
\$cpts_all = get_post_types([\"public\" => true], \"objects\");
\$cpt_skip = [\"attachment\", \"page\", \"elementor_library\",
             \"e-floating-buttons\", \"tribe_event_series\"];
foreach (\$cpts_all as \$cpt) {
  if (in_array(\$cpt->name, \$cpt_skip)) continue;
  \$ps = get_posts([\"post_type\" => \$cpt->name, \"posts_per_page\" => 2,
                   \"post_status\" => \"publish\", \"orderby\" => \"date\", \"order\" => \"DESC\"]);
  foreach (\$ps as \$p) {
    \$path = parse_url(get_permalink(\$p->ID), PHP_URL_PATH);
    if (isset(\$seen_pt_paths[\$path])) continue; // dedupe se já é PAIR
    echo \$path . \"||solo||\" . PHP_EOL;
  }
}
' 2>&1" 2>&1 | grep -vE 'Deprecated|Tribe__' | grep -E '^/[^|]*\|\|(pair|solo)\|\|' > /tmp/g40_pairs.txt

# Adicionar roots (home blog 1 + home blog 2 — testam canonical homepage):
echo "/||pair||/en/" >> /tmp/g40_pairs.txt
echo "/cultura/||pair||/cultura/en/" >> /tmp/g40_pairs.txt

echo "Total pares descobertos: $(wc -l < /tmp/g40_pairs.txt)"

check_pair() {
  # Aceita 2 formatos:
  #   "pt_path||pair||en_path" — par comparativo PT↔EN
  #   "path||solo||"           — validação unilateral (CPT sem tradução)
  local entry="$1"
  local pt_path="${entry%%||*}"
  local rest="${entry#*||}"
  local kind="${rest%%||*}"
  local en_path="${rest#*||}"

  # tr -d '\r' obrigatório — HTTP headers vêm com CRLF; sed substitui xargs
  # (xargs trata aspas/backticks como special chars do shell).
  local pt_info pt_status pt_ct pt_cd pt_loc
  pt_info=$(curl -sS -I --max-time 30 "${BASE}${pt_path}?cb=g40h$(date +%s%N)$$" 2>/dev/null | tr -d '\r')
  pt_status=$(echo "$pt_info" | head -1 | awk '{print $2}')
  pt_ct=$(echo "$pt_info" | sed -nE 's/^[Cc]ontent-[Tt]ype:[[:space:]]*([^;]+).*/\1/p' | head -1 | sed 's/[[:space:]]*$//')
  pt_cd=$(echo "$pt_info" | sed -nE 's/^[Cc]ontent-[Dd]isposition:[[:space:]]*(.*)/\1/p' | head -1)
  pt_loc=$(echo "$pt_info" | sed -nE 's/^[Ll]ocation:[[:space:]]*(.*)/\1/p' | head -1)

  local pt_tmp="/tmp/g40_pt_$$_$RANDOM.html"
  curl -sS --max-time 30 "${BASE}${pt_path}?cb=g40g$(date +%s%N)$$" -o "$pt_tmp"
  local pt_size=$(wc -c < "$pt_tmp" | tr -d ' ')
  local pt_bc=$(grep -oE '<body class="[^"]+"' "$pt_tmp" | head -1 | grep -oE 'page-id-[0-9]+|home|single-post|error404|page-template-[a-z-]+|single-[a-z_]+' | tr '\n' ',' | sed 's/,$//')
  rm -f "$pt_tmp"

  local issues=""

  # Sub-gates UNIVERSAIS (aplicam em pair E solo) — validam render do PT:
  [[ "$pt_status" != "200" ]] && issues="${issues}pt_status_${pt_status} "
  [[ "$pt_ct" == *calendar* ]] && issues="${issues}pt_is_ics "
  [[ -n "$pt_cd" && "$pt_cd" == *attachment* ]] && issues="${issues}pt_is_attachment "
  [[ -n "$pt_loc" ]] && issues="${issues}pt_redirects(${pt_loc}) "
  [[ "$pt_bc" == *error404* ]] && issues="${issues}pt_error404 "
  (( pt_size < 50000 )) && issues="${issues}pt_size_small(${pt_size}b) "

  if [[ "$kind" == "solo" ]]; then
    # SOLO: só valida PT. Output diferente.
    local verdict="✅"; [[ -n "$issues" ]] && verdict="🚨"
    printf '%s  %s (solo)\n' "$verdict" "$pt_path"
    [[ -n "$issues" ]] && printf '   status=%s ct=%s size=%dk bc=%s issues=%s\n' \
      "$pt_status" "$pt_ct" "$((pt_size/1024))" "$pt_bc" "$issues"
    return
  fi

  # PAIR: tem EN — fazer comparação completa
  local en_info en_status en_ct en_cd en_loc
  en_info=$(curl -sS -I --max-time 30 "${BASE}${en_path}?cb=g40h$(date +%s%N)$$" 2>/dev/null | tr -d '\r')
  en_status=$(echo "$en_info" | head -1 | awk '{print $2}')
  en_ct=$(echo "$en_info" | sed -nE 's/^[Cc]ontent-[Tt]ype:[[:space:]]*([^;]+).*/\1/p' | head -1 | sed 's/[[:space:]]*$//')
  en_cd=$(echo "$en_info" | sed -nE 's/^[Cc]ontent-[Dd]isposition:[[:space:]]*(.*)/\1/p' | head -1)
  en_loc=$(echo "$en_info" | sed -nE 's/^[Ll]ocation:[[:space:]]*(.*)/\1/p' | head -1)

  local en_tmp="/tmp/g40_en_$$_$RANDOM.html"
  curl -sS --max-time 30 "${BASE}${en_path}?cb=g40g$(date +%s%N)$$" -o "$en_tmp"
  local en_size=$(wc -c < "$en_tmp" | tr -d ' ')
  local en_bc=$(grep -oE '<body class="[^"]+"' "$en_tmp" | head -1 | grep -oE 'page-id-[0-9]+|home|single-post|error404|page-template-[a-z-]+|single-[a-z_]+' | tr '\n' ',' | sed 's/,$//')
  rm -f "$en_tmp"

  # Sub-gates COMPARATIVOS:
  [[ "$pt_status" != "$en_status" ]] && issues="${issues}status_diff(${pt_status}/${en_status}) "
  [[ "$pt_ct" != "$en_ct" ]] && issues="${issues}ct_diff(${pt_ct}/${en_ct}) "
  [[ "$en_ct" == *calendar* ]] && issues="${issues}en_is_ics "
  [[ -n "$en_cd" && "$en_cd" == *attachment* ]] && issues="${issues}en_is_attachment "
  [[ -n "$en_loc" && -z "$pt_loc" ]] && issues="${issues}en_only_redirects(${en_loc}) "
  [[ "$en_bc" == *error404* ]] && issues="${issues}en_error404 "

  local diff_pct=$(python3 -c "p=$pt_size; e=$en_size; m=max(p,e); print(int(abs(p-e)/m*100) if m else 0)")
  (( diff_pct > 70 )) && issues="${issues}size_diff(${diff_pct}%) "

  if [[ "$pt_bc" == *home* && "$en_bc" != *home* ]]; then issues="${issues}bc_pt_home_en_not "; fi
  if [[ "$pt_bc" != *home* && "$en_bc" == *home* ]]; then issues="${issues}bc_en_home_pt_not "; fi

  local verdict="✅"; [[ -n "$issues" ]] && verdict="🚨"
  printf '%s  %s ↔ %s\n' "$verdict" "$pt_path" "$en_path"
  [[ -n "$issues" ]] && printf '   status=%s/%s ct=%s/%s size=%dk/%dk bc=%s/%s issues=%s\n' \
    "$pt_status" "$en_status" "$pt_ct" "$en_ct" "$((pt_size/1024))" "$((en_size/1024))" "$pt_bc" "$en_bc" "$issues"
}
export -f check_pair

# Paralelismo via background jobs + wait + semáforo de 4 processos.
# Evita xargs (BSD/macOS xargs falha com "command line cannot be assembled,
# too long" em URLs com slugs grandes — testado: webinarios com 100+ chars
# pula silenciosamente, xargs -0 não resolve).
results_file=$(mktemp)
max_parallel=4
active=0
while IFS= read -r pair; do
  [[ -z "$pair" ]] && continue
  { check_pair "$pair" >> "$results_file"; } &
  active=$((active + 1))
  if (( active >= max_parallel )); then
    wait -n  # aguarda qualquer background terminar
    active=$((active - 1))
  fi
done < /tmp/g40_pairs.txt
wait  # último batch
sort "$results_file"
rm -f "$results_file"
```

**Gates do snippet:**

- **Gate 40 PASS:** todos os pares com mesmo Content-Type (`text/html`), status iguais, sizes próximos, body class compatível.
- **Gate 40 FAIL `en_is_ics`:** EN serve `text/calendar` → cache CF contaminado por `?ical=1` ou similar. Fix imediato: `aws cloudfront create-invalidation --paths '/en' '/en/...' --profile <P>`. Fix definitivo: garantir `ical`/`outlook-ical` na whitelist da Cache Policy.
- **Gate 40 FAIL `en_is_attachment`:** EN tem `Content-Disposition: attachment` → mesma classe do bug acima. Mesmo fix.
- **Gate 40 FAIL `en_only_redirects`:** EN retorna 301 mas PT retorna 200 → redirect emitido em algum momento ficou cached, ou WPML/Yoast/Redirection criou regra acidental.
- **Gate 40 FAIL `bc_pt_home_en_not` / `bc_en_home_pt_not`:** body class diverge entre home/single/page — cache pegou page errada (incidente original: `/en/` servia post BID).
- **Gate 40 FAIL `size_diff(N%)`:** HTML truncado ou .ics no lugar do HTML (7KB vs 530KB diferença diagnostica).
- **Gate 40 FAIL `error404`:** alguma página em 404 silencioso (status 200 mas body de erro).

**Cobertura típica:** 10 pares PT↔EN principais. Adicionar pares novos ao
array `PAIRS` quando WPML criar nova tradução. **Custo: ~15s para 10 pares**.

41. **Forms Elementor Pro sem reCAPTCHA v3 — HIGH**: incidente 2026-05-25.

    Detecta forms Elementor Pro publicados que não têm campo `field_type: recaptcha_v3`
    no `_elementor_data`. Sem reCAPTCHA + sem Honeypot do mu-plugin `bit-smoke-recaptcha-bypass.php`
    sendo acionado, o form aceita qualquer POST a `/wp-admin/admin-ajax.php` com payload
    válido — vetor de spam direto sem ratelimit.

    Origem do gate: 2026-05-25 — form `/contato/` (post 672, widget 65ce4a9) descoberto
    aceitando submit sem reCAPTCHA. Fix v1 aplicado: campo recaptcha_v3 injetado via
    script no `_elementor_data` (`scripts/add_recaptcha_to_form.php`).

    Sub-gate (BLOCKER):
    - `forms_without_recaptcha > 0` — pelo menos 1 widget `form` publicado em página
      pública (post_status=publish, post_type IN page/post/CPTs públicos) sem field
      recaptcha_v3 no array `form_fields`.

    Falsos positivos esperados (whitelist):
    - Forms internos de wp-admin (não acessíveis a anonymous)
    - Forms em templates/blocos não publicados (revision/draft)

    Validação via SSH (não cabe em Playwright — precisa walker JSON em `_elementor_data`):

    ```bash
    ssh prod-sa "sudo -u www-data wp --path=/var/www/<SITE> --url=<URL> eval '
    global \$wpdb;
    \$blogs = is_multisite() ? get_sites([\"fields\"=>\"ids\"]) : [get_current_blog_id()];
    \$bad = [];
    foreach (\$blogs as \$bid) {
      if (is_multisite()) switch_to_blog(\$bid);
      \$rows = \$wpdb->get_results(\"SELECT pm.post_id, p.post_title FROM {\$wpdb->postmeta} pm INNER JOIN {\$wpdb->posts} p ON p.ID=pm.post_id WHERE pm.meta_key=\\\"_elementor_data\\\" AND pm.meta_value LIKE \\\"%widgetType%form%\\\" AND p.post_status=\\\"publish\\\" AND p.post_type NOT IN (\\\"revision\\\", \\\"elementor_library\\\")\", ARRAY_A);
      foreach (\$rows as \$row) {
        \$d = json_decode(get_post_meta(\$row[\"post_id\"], \"_elementor_data\", true), true);
        if (!is_array(\$d)) continue;
        \$walk = function(\$nodes) use (&\$walk, \$bid, \$row, &\$bad) {
          foreach (\$nodes as \$node) {
            if ((\$node[\"widgetType\"] ?? null) === \"form\") {
              \$has_recaptcha = false;
              foreach ((\$node[\"settings\"][\"form_fields\"] ?? []) as \$f) {
                if ((\$f[\"field_type\"] ?? null) === \"recaptcha_v3\") { \$has_recaptcha = true; break; }
              }
              if (!\$has_recaptcha) {
                \$bad[] = sprintf(\"blog=%d post=%d title=%s widget=%s\",
                  \$bid, \$row[\"post_id\"], substr(\$row[\"post_title\"], 0, 30), \$node[\"id\"]);
              }
            }
            if (!empty(\$node[\"elements\"])) \$walk(\$node[\"elements\"]);
          }
        };
        \$walk(\$d);
      }
      if (is_multisite()) restore_current_blog();
    }
    echo count(\$bad) . \" forms_without_recaptcha\\n\";
    foreach (array_slice(\$bad, 0, 10) as \$b) echo \"  \" . \$b . \"\\n\";
    '"
    ```

    **Esperado (PASS):** `0 forms_without_recaptcha`.

    **Esperado (FAIL):**
    ```
    N forms_without_recaptcha
      blog=1 post=672 title=Contato widget=65ce4a9
      ...
    ```

    Fix: rodar script `scripts/add_recaptcha_to_form.php` apontando para o widget
    detectado (preserva todos os outros settings + idempotente).

    Severidade: **HIGH** — vetor de spam direto, não BLOCKER (site funciona) mas
    risco real de poluição CRM/email/banco.

42. **JetEngine Listing renderizado vazio (item colapsado) — HIGH**: incidente 2026-05-27.

    Detecta `jet-listing-grid` cujo item renderiza o **wrapper** (`jet-listing-dynamic-post-<ID>`)
    mas com **corpo vazio** — sem nenhum `jet-listing-dynamic-field`/`dynamic-image`/`dynamic-link`
    dentro. Sintoma visual: a seção "encolhe" para a altura do título e o próximo bloco da
    página (no caso de `/atuacao/encontros/`, o container `#footer_form_desktop` da newsletter)
    sobe e parece footer fora de lugar.

    Origem do gate: 2026-05-27 — seção **PLENÁRIAS** em `/atuacao/encontros/`. O listing 44298
    (template `jet-listing-items` slug `listagem-slider-banner-plenaria-2`) renderizava o
    wrapper `jet-listing-dynamic-post-92180` ("Jogando luz sobre as Amazônias") mas **sem nenhum
    dynamic-field**. Causa raiz: o `_elementor_data` do template 44298 em prod era uma versão
    obsoleta (post_modified 2026-03-09, 54663 bytes, **0** widgets `jet-listing-dynamic-field`,
    44 condições `jedv` que escondiam o conteúdo) divergente da versão de dev (9984 bytes,
    **2** dynamic-fields). O post da plenária (92180) existia e estava `publish` em ambos —
    não era conteúdo ausente, era **template drift** entre dev e prod.

    Sub-gate (HIGH):
    - `empty_grids > 0` — pelo menos 1 `jet-listing-grid--<ID>` cujo HTML interno (do
      container do grid até o próximo grid) não contém nenhum `jet-listing-dynamic-field`,
      `jet-listing-dynamic-image` nem `jet-listing-dynamic-link`. Escopar **por grid
      container** (não por wrapper de item) é essencial: o regex `jet-listing-dynamic-post-<ID>`
      sozinho casa também em seletores CSS (`<style>.jet-listing-dynamic-post-NN{...}`) e em
      variantes mobile/desktop duplicadas, gerando falsos positivos.

    Cobre hubs/páginas com listing JetEngine renderizado. Custo ~5s por path via curl + Python.

    **Limitação conhecida — cache CloudFront:** `?nowprocket=1` bypassa apenas WP Rocket
    (PHP), NÃO o CloudFront (CF ignora querystrings para cache key). O gate valida HTML
    edge cacheado (pode estar stale até 12-24h). Em FAIL **após** um fix recém-aplicado,
    invalidar o path com `std cache-flush --prod --cf-only <path>` e re-rodar o gate.
    Para validação 100% origin (bypass total do CF), curl direto no ALB com header
    `X-Test-Green: true` (memory `feedback_xtest_green_value_true`).

    ```bash
    python3 <<'PY'
    import re, subprocess
    BASE = "https://concertacaoamazonia.com.br"
    # Páginas com listing JetEngine destacado (posts_num:1 ou hubs). Adicionar quando
    # criar novos hubs que usem o padrão `jet-listing-grid--<ID>` com card de destaque.
    PATHS = [
        "/atuacao/encontros/",         # PLENÁRIAS (listing 44298, post destacado)
        "/conhecimento/",              # hub principal
        "/conhecimento/espiral-de-conhecimento/",
        "/conhecimento/publicacoes/",          # OUTRAS PUBLICAÇÕES (listing 28187, query 57)
        "/en/knowledge/publications/",         # idem EN (page 72926) — incidente lazy-load 2026-05-28
        "/cultura/",
        "/cultura/atlas-cultural-das-amazonias/",
        "/sobre-nos/",
        "/sobre-nos/4-amazonias/",
        "/agenda-integradora/",
    ]
    # Widgets dinâmicos de conteúdo. Ampliado além de field|image|link para evitar FP em
    # listings legítimos que usam só terms/meta/repeater/calendar/gallery.
    DF = re.compile(r"jet-listing-dynamic-(field|image|link|terms|meta|repeater|calendar|gallery)")
    total_empty = 0
    for path in PATHS:
        try:
            html = subprocess.check_output(
                ["curl", "-s", f"{BASE}{path}?nowprocket=1"], timeout=30).decode("utf-8", "ignore")
        except subprocess.CalledProcessError:
            print(f"SKIP curl_fail: path={path}")
            continue
        # Ancorar nos containers de grid REAIS (jet-listing-grid--ID seguido de data-queried-id),
        # não em seletores CSS nem wrappers de item.
        grids = list(re.finditer(r'jet-listing-grid--(\d+)"[^>]*data-queried-id', html))
        for i, g in enumerate(grids):
            gid = g.group(1)
            start = g.start()
            end = grids[i + 1].start() if i + 1 < len(grids) else len(html)
            body = html[start:end]
            first_post = re.search(r'jet-listing-dynamic-post-(\d+)', body)
            if first_post and not DF.search(body):
                total_empty += 1
                print(f"FAIL empty_grid: listing={gid} post={first_post.group(1)} path={path}")
    print(f"\n{total_empty} empty_grids")
    PY
    ```

    **Esperado (PASS):** `0 empty_grids`.

    **Esperado (FAIL):**
    ```
    FAIL empty_grid: listing=44298 post=92180 path=/atuacao/encontros/
    1 empty_grids
    ```

    Fix: o template do listing em prod está com `_elementor_data` divergente/obsoleto.
    Comparar tamanho e contagem de dynamic-fields dev↔prod:
    ```bash
    # DEV
    docker exec -u www-data concertacao-dev-wordpress wp db query \
      "SELECT LENGTH(meta_value) FROM wp_postmeta WHERE post_id=<LISTING_ID> AND meta_key='_elementor_data';" --skip-column-names
    # PROD
    ssh concertacaoamazonia.com.br-prod-sa "sudo -u www-data wp --path=/var/www/concertacaoamazonia.com.br \
      post meta get <LISTING_ID> _elementor_data | grep -oc 'jet-listing-dynamic-field'"
    ```
    Deploy do template correto (dev→prod) + regen Elementor CSS + `wp jet-engine listing clear-cache`
    + invalidate CF cirúrgico do path afetado.

    Severidade: **HIGH** — conteúdo de seção inteira some visualmente (UX quebrada),
    mas site funciona; não BLOCKER.

43. **Featured image não herdada em tradução WPML (thumbnail ausente em grid EN) — HIGH**: incidente 2026-05-28.

    Detecta traduções (EN) de CPTs renderizados em JetEngine Listing Grid que **NÃO têm
    `_thumbnail_id`** quando o original (PT) tem. Sintoma visual: no grid da versão EN, os
    cards aparecem com título/data/botão mas **sem a imagem** (painel de thumbnail vazio),
    porque o template do listing puxa a imagem via dynamic tag `post-featured-image` como
    `background-image` — e a tradução EN tem featured image vazia.

    **Por que o Gate 42 NÃO pega:** o Gate 42 detecta grid *colapsado* (wrapper de post sem
    nenhum dynamic-field). Aqui os cards renderizam normalmente (título, data, link presentes)
    — só falta a imagem. São falhas de classes diferentes: Gate 42 = template quebrado;
    Gate 43 = dado (featured image) ausente na tradução.

    Origem do gate: 2026-05-28 — página EN `/en/activities/news/` (grid 5679, CPT `plenarias`).
    13 de 40 traduções EN de plenárias estavam sem `_thumbnail_id` enquanto o original PT tinha.
    Causa raiz: config WPML `_wpml_media.new_content_settings.duplicate_featured = false`
    (mantida OFF de propósito — evita attachments órfãos, ver memory `feedback_nml_crossblog_srcset_hook14`).
    Efeito colateral: traduções nascem sem featured image herdada.

    Sub-gate (HIGH):
    - `en_missing_thumb > 0` — pelo menos 1 tradução EN `publish` de um CPT alvo cujo
      `_thumbnail_id` está vazio enquanto o original PT (mesmo `trid` WPML) tem `_thumbnail_id`.

    CPTs alvo: os renderizados em grids com card de imagem. Hoje: `plenarias`, `estudos`,
    `post`, `releases`, `100dias`, `webinarios`. Ampliar quando um novo CPT entrar num grid.

    Validação via SSH (server-side — não dá pra inferir do HTML qual CPT/tradução falha):

    ```bash
    ssh concertacaoamazonia.com.br-prod-sa "sudo -u www-data wp --path=/var/www/concertacaoamazonia.com.br eval '
    global \$wpdb;
    \$cpts = [\"plenarias\",\"estudos\",\"post\",\"releases\",\"100dias\",\"webinarios\"];
    \$bad = [];
    foreach (\$cpts as \$cpt) {
      \$rows = \$wpdb->get_results(\$wpdb->prepare(
        \"SELECT element_id, trid, language_code FROM {\$wpdb->prefix}icl_translations WHERE element_type=%s\",
        \"post_\" . \$cpt), ARRAY_A);
      \$by_trid = [];
      foreach (\$rows as \$r) { \$by_trid[\$r[\"trid\"]][\$r[\"language_code\"]] = (int) \$r[\"element_id\"]; }
      foreach (\$by_trid as \$trid => \$langs) {
        if (!isset(\$langs[\"en\"]) || !isset(\$langs[\"pt-br\"])) continue;
        \$en = \$langs[\"en\"]; \$pt = \$langs[\"pt-br\"];
        if (get_post_status(\$en) !== \"publish\") continue;
        \$en_thumb = get_post_meta(\$en, \"_thumbnail_id\", true);
        \$pt_thumb = get_post_meta(\$pt, \"_thumbnail_id\", true);
        // só conta quando PT TEM imagem mas EN não herdou (caso corrigível)
        if (empty(\$en_thumb) && !empty(\$pt_thumb) && get_post((int) \$pt_thumb)) {
          \$bad[] = sprintf(\"cpt=%s EN=%d (PT=%d thumb=%d) %s\", \$cpt, \$en, \$pt, \$pt_thumb, substr(get_the_title(\$en),0,35));
        }
      }
    }
    echo count(\$bad) . \" en_missing_thumb\n\";
    foreach (array_slice(\$bad, 0, 20) as \$b) echo \"  \" . \$b . \"\n\";
    '" 2>&1 | grep -v Deprecated
    ```

    **Esperado (PASS):** `0 en_missing_thumb`.

    **Esperado (FAIL):**
    ```
    13 en_missing_thumb
      cpt=plenarias EN=91946 (PT=91418 thumb=89983) Prioridades para as Amazônias
      ...
    ```

    Fix (idempotente — copia o `_thumbnail_id` do PT para a tradução EN; mídia é compartilhada
    via NML no blog 1, então o mesmo attachment ID serve ambos os idiomas sem criar órfão):
    ```bash
    ssh prod "sudo -u www-data wp --path=/var/www/<SITE> eval '
    global \$wpdb; \$cpts=[\"plenarias\",...];
    foreach (\$cpts as \$cpt) { /* mesmo walker; */ update_post_meta(\$en,\"_thumbnail_id\",(int)\$pt_thumb); clean_post_cache(\$en); }
    '"
    # depois: regen Elementor CSS do listing+página EN + rocket_clean_post + CF invalidate cirúrgico
    ```

    **Nota:** casos onde o PT TAMBÉM não tem thumb (`pt_thumb=none`) NÃO contam — não há o que
    herdar; esses usam o fallback do dynamic tag. Não tentar "consertar" copiando vazio.

    Severidade: **HIGH** — thumbnails somem só na versão traduzida (UX quebrada para público
    internacional), mas site funciona; não BLOCKER.

44. **JetSmartFilters quebrados (busca/paginação não filtram o grid) — HIGH**: incidente 2026-05-28.

    Detecta filtros JetSmartFilters (`jet-smart-filters-search`, `jet-smart-filters-pagination`,
    `jet-smart-filters-checkboxes`, etc.) cujo `query_id` **não casa** com nenhum grid JetEngine
    renderizável na mesma página. Sintoma: ao usar a busca/filtro, o controle entra em
    `jet-filters-single-loading` (spinner) e fica preso — o grid nunca atualiza.

    Origem do gate: 2026-05-28 — `/conhecimento/publicacoes/`. O filtro de busca tinha
    `query_id="estudos"` mas o grid (listing 28187, custom query 57) renderizava com
    `data-query-id="57"` (numérico da custom query) e **sem o CSS ID `estudos`**. O JSF resolve
    o vínculo filtro→grid via seletor `#<query_id> .jet-listing-grid.jet-listing` — sem um
    elemento `id="estudos"`, `$provider.length===0`, nenhuma requisição AJAX é disparada, spinner
    infinito. Fix: setar `_element_id="estudos"` (Avançado → CSS ID) no widget do grid, casando
    com o `query_id` do filtro. Corrigiu busca E paginação juntas (mesmo `query_id`).

    **Por que Gates 42/43 não pegam:** Gate 42 = grid colapsado (template); Gate 43 = thumbnail
    ausente; Gate 44 = vínculo filtro↔grid quebrado (o grid renderiza certo, mas os filtros não
    o controlam). Classes distintas.

    Sub-gates (HIGH):
    - `orphan_filters > 0` — filtro JSF com `data-query-id="X"` (X != "default") sem nenhum
      elemento `id="X"` contendo `.jet-listing-grid` na página. **Vínculo quebrado** → spinner.
    - `search_no_ajax` — (validação dinâmica, só Playwright) ao digitar ≥3 chars no
      `.jet-search-filter__input`, NENHUM POST a `admin-ajax.php?action=jet_smart_filters` é
      disparado em ~4s. Indica `$provider` não resolvido.

    **Parte estática (curl + Python) — roda sempre, barata (~5s/path):**

    ```bash
    python3 <<'PY'
    import re, subprocess
    BASE = "https://concertacaoamazonia.com.br"
    PATHS = ["/conhecimento/publicacoes/", "/en/knowledge/publications/"]  # páginas com filtros JSF
    orphans = 0
    for path in PATHS:
        html = subprocess.check_output(["curl","-s",f"{BASE}{path}?nowprocket=1"], timeout=30).decode("utf-8","ignore")
        # query_ids que os filtros JSF declaram (search/pagination/checkboxes/etc.)
        filter_qids = set(re.findall(r'jet-smart-filters-[a-z]+[^>]*data-query-id="([^"]+)"', html))
        filter_qids |= set(re.findall(r'data-query-id="([^"]+)"[^>]*jet-smart-filters', html))
        for qid in filter_qids:
            if qid == "default":
                continue
            # existe um elemento id="<qid>" que envolve um jet-listing-grid?
            # heurística: id="qid" aparece E há um jet-listing-grid após ele no mesmo container
            has_anchor = re.search(rf'id="{re.escape(qid)}"[^>]*>(?:(?!</?(?:section|div class="elementor-element)).)*?jet-listing-grid', html, re.DOTALL)
            # fallback robusto: id="qid" presente em qualquer lugar E grid presente na página
            id_present = bool(re.search(rf'\bid="{re.escape(qid)}"', html))
            grid_present = "jet-listing-grid" in html
            if not id_present and grid_present:
                orphans += 1
                print(f"FAIL orphan_filter: query_id={qid} sem id=\"{qid}\" no DOM | path={path}")
    print(f"\n{orphans} orphan_filters")
    PY
    ```

    **Parte dinâmica (Playwright) — confirma o ciclo AJAX completo:**

    ```js
    // Em cada path com busca: digitar e validar que filtra
    await page.goto(BASE + "/conhecimento/publicacoes/?cb=" + Date.now());
    await page.locator(".jet-search-filter__input").pressSequentially("agenda");
    // capturar requests admin-ajax + estado do grid após ~4s
    await page.waitForTimeout(4000);
    const r = await page.evaluate(() => {
      const wrap = document.querySelector(".jet-search-filter");
      const grid = document.querySelector("#estudos .jet-listing-grid, [data-listing-id]");
      const cards = grid ? grid.querySelectorAll('[class*="jet-listing-dynamic-post-"]').length : 0;
      return { still_loading: wrap ? [...wrap.classList].includes("jet-filters-single-loading") : null, cards };
    });
    // PASS: still_loading=false E cards mudou (filtrou). FAIL: still_loading=true (spinner preso).
    ```

    **Esperado (PASS):** `0 orphan_filters`; busca filtra (`still_loading=false`, contagem de cards muda).

    **Esperado (FAIL):**
    ```
    FAIL orphan_filter: query_id=estudos sem id="estudos" no DOM | path=/conhecimento/publicacoes/
    1 orphan_filters
    ```

    Fix: setar `_element_id` no widget do grid = `query_id` do filtro (ex: `estudos`), via patch
    do `_elementor_data` + clear Element Cache (`files_manager->clear_cache()`) + re-save do
    documento + CF invalidate cirúrgico. Replicar na versão EN (WPML).

    Severidade: **HIGH** — busca/paginação inutilizáveis (UX quebrada), mas o conteúdo
    aparece; não BLOCKER.

45. **Paginação JSF numerada não navega (offset quebra paged) — HIGH**: incidente 2026-05-28.

    Detecta grid JetEngine com paginação JetSmartFilters numerada que **renderiza, mas
    sempre retorna os mesmos posts** ao mudar de página. O controle de paginação atualiza
    o número da página, mas o conteúdo do grid não muda — o usuário clica "2", "3"... e vê
    sempre os mesmos cards.

    **Por que o Gate 44 NÃO pega:** Gate 44 valida o *vínculo* filtro↔grid (o AJAX dispara?).
    Aqui o vínculo está OK e o AJAX dispara e retorna **200** — mas o `WP_Query` ignora o
    `paged`, então a resposta traz a mesma página. É falha de classe diferente: Gate 44 =
    AJAX não dispara; Gate 45 = AJAX dispara mas não pagina.

    Origem do gate: 2026-05-28 — `/atuacao/encontros/` (grid 5679, custom query 58 do
    JetEngine Query Builder). A query 58 tinha `offset:1` (para pular o post destacado).
    Causa raiz: `offset` numa custom query do Query Builder **quebra a paginação** — o JSF,
    no caminho de custom query (`queries/posts.php`), só seta `paged`/`page` e nunca
    recalcula o offset; o `WP_Query` do core descarta o `paged` quando `offset` está
    presente. (O caminho de query *nativa* do widget tem `query_maybe_has_offset()` que
    reconcilia — custom query do QB não tem.) Fix: remover offset dos 2 lugares (query QB +
    override `posts_query` `order_offset` do widget) + excluir o destaque via `post__not_in`
    dinâmico com macro `%query_results|<sub-query>|ids%` na chave `__dynamic_posts`.
    Memória: [[feedback_jsf_offset_breaks_pagination]].

    Sub-gate (HIGH):
    - `frozen_pagination > 0` — pelo menos 1 grid com paginação JSF cuja página 2 (via AJAX)
      retorna o **mesmo conjunto de post-ids** da página 1.

    **Parte estática (curl + Python) — roda sempre, barata (~6s/path):**

    Compara os post-ids do render inicial (página 1) com a resposta AJAX da página 2.
    Reproduz o POST que o JSF faz para `admin-ajax.php` (`action=jet_smart_filters`,
    `provider=jet-engine/<query_id>`, `paged=2`).

    ```bash
    python3 <<'PY'
    import re, subprocess, json
    BASE = "https://concertacaoamazonia.com.br"
    # path | query_id (JSF _element_id) | custom_query_id | listing_id | lang
    # lang é passado no request AJAX (admin-ajax sem lang resolve em PT — para EN
    # o WPML precisa do parâmetro para retornar as traduções, senão p2 vem em PT).
    TARGETS = [
        ("/atuacao/encontros/",    "plenaria", "58", "5679", ""),
        ("/en/activities/news/",   "plenaria", "58", "5679", "en"),
    ]
    frozen = 0
    def ids_in(html, listing_id):
        m = re.search(rf'jet-listing-grid--{listing_id}.*?(?=jet-smart-filters|jet-listing-grid--(?!{listing_id})|\Z)', html, re.S)
        seg = m.group(0) if m else ""
        seen = []
        for x in re.findall(r'jet-listing-dynamic-post-(\d+)', seg):
            if x not in seen: seen.append(x)
        return seen
    for path, qid, cqid, lid, lang in TARGETS:
        html = subprocess.check_output(["curl","-s",f"{BASE}{path}?nowprocket=1"], timeout=30).decode("utf-8","ignore")
        p1 = ids_in(html, lid)
        # request AJAX página 2 (lang p/ WPML resolver traduções na versão EN)
        fields = [
            "action=jet_smart_filters", f"provider=jet-engine/{qid}",
            f"settings[lisitng_id]={lid}","settings[custom_query]=yes",
            f"settings[custom_query_id]={cqid}",f"settings[_element_id]={qid}",
            f"props[query_id]={cqid}","paged=2",
        ]
        if lang:
            fields.append(f"lang={lang}")
        data = "&".join(fields)
        out = subprocess.check_output(["curl","-s",f"{BASE}/wp-admin/admin-ajax.php",
            "-H","X-Requested-With: XMLHttpRequest","--data",data], timeout=30).decode("utf-8","ignore")
        p2 = []
        try:
            content = json.loads(out).get("content","")
            for x in re.findall(r'jet-listing-dynamic-post-(\d+)', content):
                if x not in p2: p2.append(x)
        except Exception:
            p2 = []
        same = bool(p1) and p1 == p2
        if same:
            frozen += 1
            print(f"FAIL frozen_pagination: path={path} p1==p2={p1[:3]}... (offset quebrando paged?)")
        else:
            print(f"OK pagina navega: path={path} p1={p1[:2]} p2={p2[:2]}")
    print(f"\n{frozen} frozen_pagination")
    PY
    ```

    **Esperado (PASS):** `0 frozen_pagination` — página 2 traz post-ids diferentes da página 1.

    **Esperado (FAIL):**
    ```
    FAIL frozen_pagination: path=/atuacao/encontros/ p1==p2=['91418', '89289', '82220']... (offset quebrando paged?)
    1 frozen_pagination
    ```

    **Nota EN/WPML:** o `p1` (render inicial via curl da página `/en/`) pode vir com IDs
    PT quando o CloudFront serve cache cruzado de idioma; o `p2` usa `lang=en` e retorna
    traduções. O gate compara `p1 != p2` (navegou?), que continua válido mesmo com IDs
    de idiomas distintos — o objetivo é detectar **paginação congelada**, não paridade
    de tradução (isso é o Gate 43). Não tratar divergência PT/EN aqui como falha.

    Fix: ver [[feedback_jsf_offset_breaks_pagination]]. Remover offset da query QB
    (coluna `args` de `wp_jet_post_types`, PHP-serializado) E do override `posts_query`
    do widget no `_elementor_data` das páginas (PT+EN) + sub-query de destaque +
    `post__not_in` dinâmico. Limpar `jet_cache` + Elementor CSS + WP Rocket/CF cirúrgico.

    Severidade: **HIGH** — paginação inutilizável (UX quebrada, usuário preso na página 1),
    mas o conteúdo da página 1 aparece; não BLOCKER.

46. **Redirect 3xx com `Location:` vazando host de DEV — HIGH**: incidente 2026-05-28.

    Detecta qualquer página dos menus cujo `Location:` (header de um redirect 3xx) aponte
    para um host de **desenvolvimento** (`cambrasmax.local`, `concertacao.bureau-it.com`,
    `localhost:NNNN`) em produção. O usuário/crawler que acessa uma URL alternativa do site
    é jogado para um host de dev inacessível publicamente (SSL inválido / connection refused).

    **Por que o Gate 22 NÃO pega:** Gate 22 baixa o **conteúdo dos arquivos CSS** do Elementor
    e procura URLs de dev *dentro do CSS*. Aqui o vazamento está no **header `Location:` de um
    redirect HTTP** — uma camada que nenhum outro gate inspeciona. **Por que o Gate 40 NÃO pega:**
    Gate 40 detecta *se* uma página emite redirect inesperado, mas não valida **para onde** o
    `Location` aponta (não distingue destino prod de destino dev) e roda só nos ~10 pares PT↔EN.

    Origem do gate: 2026-05-28 — investigando o deploy da Linha do Tempo, a URL WPML alternativa
    `/en/culture/timeline/` (slug EN traduzido, diretório `/en/`) retornava no **TUNNEL/DEV**
    (`concertacao.bureau-it.com`) **301 → `https://cambrasmax.local:8484/cultura/en/timeline/`**.
    No DEV isso é esperado (lá `home_url` É cambrasmax). **O bug só existe se aparecer em PROD REAL**
    (`concertacaoamazonia.com.br`) — verificado nesse caso: prod retorna 200, está limpo. O gate
    existe para **detectar regressão**: se um deploy/import vazar o `home_url` de dev para prod (já
    aconteceu em CSS — gate 22; em `_elementor_data` — gates 26/28), o `Location:` de um redirect WPML
    passaria a apontar para cambrasmax/tunnel em prod, e nenhum gate via.

    **ALVO: PROD REAL** (`https://concertacaoamazonia.com.br`), nunca o tunnel — o tunnel é dev e
    legitimamente aponta para cambrasmax nos redirects WPML de slug-traduzido.

    Sub-gate (HIGH):
    - `dev_leak_redirects > 0` — pelo menos 1 path **em prod** cujo redirect 3xx tem `Location:`
      com host de dev (`cambrasmax.local` / `concertacao.bureau-it.com` / `localhost:NNNN`).

    **Parte estática (curl) — roda sempre, barata (~10s para todos os paths):**

    Itera a lista de paths do Snippet 1 (TODOS os menus) + as variantes de slug-traduzido WPML
    de `/cultura/*` (`/en/culture/<slug-en>/`), que são as mais propensas a redirect WPML. Para cada
    uma, faz um `curl` SEM seguir redirect **contra prod real** e inspeciona o `Location:`.

    ```bash
    # PATHS = lista do Snippet 1 (substituir) + variantes WPML culture EN conhecidas.
    PATHS=$(cat /tmp/g46_paths.txt 2>/dev/null || cat <<'EOF'
    /cultura/linha-do-tempo/
    /en/cultura/linha-do-tempo/
    /en/culture/timeline/
    /en/culture/gallery/
    /en/culture/
    EOF
    )
    BASE="https://concertacaoamazonia.com.br"
    DEV_RE='cambrasmax\.local|concertacao\.bureau-it\.com|localhost:[0-9]+'
    leaks=0
    while IFS= read -r p; do
      [[ -z "$p" ]] && continue
      # -I não basta (alguns redirects só em GET); usar -s -o /dev/null -D -
      loc=$(curl -s -o /dev/null -D - "${BASE}${p}" 2>/dev/null | awk 'tolower($1)=="location:"{print $2}' | tr -d '\r')
      if [[ -n "$loc" ]] && echo "$loc" | grep -qiE "$DEV_RE"; then
        echo "FAIL dev_leak_redirect: ${p} -> ${loc}"
        leaks=$((leaks+1))
      fi
    done <<< "$PATHS"
    echo "${leaks} dev_leak_redirects"
    ```

    **Esperado (PASS):** `0 dev_leak_redirects` — nenhum `Location:` aponta para host de dev.

    **Esperado (FAIL):**
    ```
    FAIL dev_leak_redirect: /en/culture/timeline/ -> https://cambrasmax.local:8484/cultura/en/timeline/
    1 dev_leak_redirects
    ```

    Fix: rastrear a origem do `Location` (WPML language URL / canonical redirect / regra Redirection /
    `_elementor_data` hardcoded). Para o caso WPML `/en/culture/*`: verificar config de URL de idioma
    do WPML e o `home`/`siteurl` por blog; o redirect é gerado a partir do estado de dev — após corrigir,
    invalidar CF dos paths. Se for regra do plugin Redirection: `wp db query` em `wp_redirection_items`
    procurando `action_data LIKE '%cambrasmax%'`.

    Severidade: **HIGH** — vazamento de infraestrutura de dev em prod; URL alternativa do site leva a
    host inacessível. Não BLOCKER se a URL canônica do menu (200) for a divulgada, mas crawlers/links
    externos podem usar a variante.

47. **Imagem servida muito acima do tamanho de exibição (oversized thumbnail) — MEDIUM**: incidente 2026-05-28.

    Detecta `<img>`/`background-image` cuja **resolução natural** (ou tamanho de arquivo) é muito
    maior que a área onde é renderizada — desperdício de banda e LCP/loading lento. Pega o anti-padrão
    do Elementor Gallery / widgets com `thumbnail_image_size: "full"` servindo a imagem original
    (ex.: 1414×2000px, ~350 KB) num thumbnail de ~180px.

    **Por que nenhum gate pega:** não havia gate de *performance de imagem*. Gate 37 checa imagens
    **quebradas** (`naturalWidth === 0`), não imagens **gigantes**. O peso passa despercebido porque
    a imagem carrega corretamente — só devagar.

    Origem do gate: 2026-05-28 — galeria de quadrinhos em `/cultura/linha-do-tempo/` (widget Elementor
    Gallery `ef72346`, `thumbnail_image_size: full`) servia 5 imagens de 1414×2000px (~1,8 MB JPEG /
    1,2 MB AVIF) exibidas a 181×321px. Fix: `thumbnail_image_size` `full`→`large` (724×1024) nas pages
    26769 (PT) + 92057 (EN) → −60% de peso. Memória: [[feedback_elementor_gallery_thumbnail_full_oversized]].

    Sub-gates (MEDIUM):
    - `oversized_imgs > 0` — pelo menos 1 imagem com `naturalWidth >= 2 × (displayWidth × DPR)` E
      `naturalWidth >= 1000px` (ignora ícones/logos pequenos e o retina 2x legítimo).
    - Tolerância: imagens dentro de lightbox/modal (carregam full ao clicar) são ignoradas
      (`closest('.elementor-lightbox, [data-elementor-lightbox]')`).

    **Snippet Playwright — rodar nas páginas com galeria/grid de imagem (mín.: linha-do-tempo PT+EN):**

    ```js
    async (page) => {
      const PATHS = ['/cultura/linha-do-tempo/', '/cultura/en/timeline/'];
      const BASE = 'https://concertacaoamazonia.com.br';
      const DPR = 2; // assumir retina como pior caso aceitável
      const findings = [];
      for (const path of PATHS) {
        await page.goto(BASE + path + '?cb=' + Date.now(), { waitUntil: 'domcontentloaded', timeout: 30000 });
        // disparar lazy-load: rolar a página inteira
        await page.evaluate(async () => {
          const sleep = ms => new Promise(r => setTimeout(r, ms));
          for (let y = 0; y < document.body.scrollHeight; y += 500) { window.scrollTo(0, y); await sleep(120); }
          await sleep(1500);
        });
        const over = await page.evaluate((DPR) => {
          const out = [];
          const check = (naturalW, dispW, src, kind) => {
            if (naturalW >= 1000 && dispW > 0 && naturalW >= 2 * (dispW * DPR)) {
              out.push({ kind, src: src.split('/').pop().slice(0, 50), naturalW, dispW: Math.round(dispW), ratio: +(naturalW / (dispW * DPR)).toFixed(1) });
            }
          };
          document.querySelectorAll('img').forEach(i => {
            if (i.closest('.elementor-lightbox, [data-elementor-lightbox]')) return;
            const r = i.getBoundingClientRect();
            if (r.width < 40) return;
            check(i.naturalWidth, r.width, i.currentSrc || i.src || '', 'img');
          });
          // background-image: usar dimensão natural via Image() é async; aproximar pela URL do size
          // (se a URL não tem sufixo -WxH e o elemento é pequeno, é candidato a full oversized)
          document.querySelectorAll('.e-gallery-image, [style*="background-image"]').forEach(e => {
            const bg = getComputedStyle(e).backgroundImage;
            const m = bg.match(/url\(["']?([^"')]+\.(?:jpg|jpeg|png|webp|avif))/i);
            if (!m) return;
            const url = m[1];
            const r = e.getBoundingClientRect();
            if (r.width < 40) return;
            const sizeMatch = url.match(/-(\d+)x(\d+)\.(?:jpg|jpeg|png|webp|avif)$/i);
            // sem sufixo de size = imagem FULL como background → flag se elemento for pequeno
            if (!sizeMatch && r.width < 600) out.push({ kind: 'bg-full', src: url.split('/').pop().slice(0, 50), dispW: Math.round(r.width), note: 'background-image usando FULL (sem thumbnail)' });
            else if (sizeMatch && +sizeMatch[1] >= 2 * (r.width * DPR) && +sizeMatch[1] >= 1000) out.push({ kind: 'bg', src: url.split('/').pop().slice(0, 50), naturalW: +sizeMatch[1], dispW: Math.round(r.width), ratio: +(+sizeMatch[1] / (r.width * DPR)).toFixed(1) });
          });
          return out;
        }, DPR);
        over.forEach(o => findings.push({ path, ...o }));
      }
      return { oversized_imgs: findings.length, findings };
    }
    ```

    **Esperado (PASS):** `oversized_imgs: 0` — nenhuma imagem >2× o necessário para o display (retina já contado).

    **Esperado (FAIL):**
    ```
    oversized_imgs: 5
    findings: [{ path:'/cultura/linha-do-tempo/', kind:'bg-full', src:'hq-plenaria-1-1.jpg', dispW:181, note:'background-image usando FULL (sem thumbnail)' }, ...]
    ```

    Fix: no widget afetado, trocar `thumbnail_image_size`/`image_size` de `full` para um size recortado
    (`large` 724px, `medium_large` 768px, ou custom). Garantir que o thumbnail recortado exista
    (`wp media regenerate <id>` se 404). Limpar `_elementor_element_cache` + Elementor CSS + WP Rocket
    minify + CF. Ver [[feedback_elementor_gallery_thumbnail_full_oversized]].

    Severidade: **MEDIUM** — não quebra a página, mas degrada LCP/banda; relevante em mobile/3G.

### Snippet — Gate 48 (Atlas: popup do mapa por card + paginação next, PT+EN)

Após gates 40+, antes do relatório. **Usa Playwright** (clique de mouse real + JS do
JetEngine Maps). Origem: bug 2026-05-29 — após criar os 657 artistas EN e regenerar
coordenadas, os cards da listagem lateral do Atlas referenciam `open_map_listing_popup&id=<post>`,
mas o **widget de mapa só plota `posts_num` markers** (estava 500 < 645 artistas com
coordenada). Cards cujo `id` não estava entre os markers plotados **não abriam o popup**
ao clicar (sintoma: "clique não funciona, exceto Abraão"). Causa = `posts_num` do widget
de mapa menor que o total de artistas com coordenada + ordenação mapa (ID) ≠ listagem (título).

Roda em PT (`/cultura/atlas-cultural-das-amazonias/`) e EN (`/cultura/en/cultural-atlas-of-the-amazon/`).
Para cada idioma: (a) confere que os 4 primeiros cards têm `id` presente nos markers do mapa;
(b) clica com mouse real no `+` do 1º card e verifica que o `.leaflet-popup` abre;
(c) clica em "next" da paginação e confere que a 1ª linha muda.

```javascript
// Snippet smoke (browser_run_code_unsafe) — gate 48 — rodar por idioma
async (page) => {
  const URLS = {
    PT: "https://concertacaoamazonia.com.br/cultura/atlas-cultural-das-amazonias/",
    EN: "https://concertacaoamazonia.com.br/cultura/en/cultural-atlas-of-the-amazon/",
  };
  const out = {};
  for (const [lang, url] of Object.entries(URLS)) {
    await page.goto(url + "?cb=" + Date.now(), { waitUntil: "domcontentloaded" });
    await page.waitForSelector(".leaflet-marker-icon", { timeout: 15000 }).catch(()=>{});
    await page.waitForTimeout(3000);

    // (a) 4 primeiros cards têm id nos markers?
    const idmatch = await page.evaluate(() => {
      const map = document.querySelector(".jet-map-listing");
      let markers = []; try { markers = JSON.parse(map.getAttribute("data-markers")); } catch(e){}
      const set = new Set(markers.map(m => m.id));
      const cards = Array.from(document.querySelectorAll(".jet-listing-grid__item")).slice(0,4).map(c => {
        const id = (c.querySelector("a.jet-engine-listing-overlay-link")?.getAttribute("href")?.match(/id=(\d+)/)||[])[1];
        return { name: c.querySelector(".jet-listing-dynamic-field__content")?.textContent.trim(), id, in_markers: id ? set.has(parseInt(id)) : false };
      });
      return { total_markers: markers.length, cards };
    });
    const gate_48a = idmatch.cards.every(c => c.in_markers);

    // (b) clicar no + do 1º card abre popup
    const info = await page.evaluate(() => {
      const link = document.querySelector(".jet-listing-grid__item a.jet-engine-listing-overlay-link");
      if (!link) return null;
      const r = link.getBoundingClientRect();
      return { cx: r.x + r.width/2, cy: r.y + r.height/2 };
    });
    let gate_48b = false;
    if (info) {
      await page.mouse.click(info.cx, info.cy);
      await page.waitForTimeout(4000);
      gate_48b = await page.evaluate(() => document.querySelectorAll(".leaflet-popup, .leaflet-popup-content-wrapper").length > 0);
    }

    // (c) paginação next muda a 1ª linha
    const pag = await page.evaluate(() => {
      const before = document.querySelector(".jet-listing-grid__item .jet-listing-dynamic-field__content")?.textContent.trim();
      const nav = document.querySelector(".jet-filters-pagination__item.prev-next.next, .jet-filters-pagination .next");
      if (!nav) return { found:false, before };
      const r = nav.getBoundingClientRect();
      return { found:true, before, cx: r.x + r.width/2, cy: r.y + r.height/2 };
    });
    let gate_48c = false, firstAfter = null;
    if (pag.found) {
      await page.mouse.click(pag.cx, pag.cy);
      await page.waitForTimeout(3000);
      firstAfter = await page.evaluate(() => document.querySelector(".jet-listing-grid__item .jet-listing-dynamic-field__content")?.textContent.trim());
      gate_48c = firstAfter && firstAfter !== pag.before;
    }

    out[lang] = {
      total_markers: idmatch.total_markers,
      gate_48a_cards_in_markers: { pass: gate_48a, cards: idmatch.cards },
      gate_48b_popup_opens: { pass: gate_48b },
      gate_48c_pagination_next: { pass: gate_48c, before: pag.before, after: firstAfter },
    };
  }
  return out;
}
```

**Gates do snippet (rodar PT e EN):**
- Gate 48a PASS: os 4 primeiros cards têm `id` presente em `data-markers` do mapa.
  Se FAIL → `posts_num` do widget de mapa (`d0df2db`) é menor que o total de artistas
  com coordenada. Fix: aumentar `posts_num` (atualmente 700) acima do total.
  Validar: `total_markers` deve ser ≈ nº de artistas com coordenada (~645), não 500.
- Gate 48b PASS: clicar no `+` do 1º card abre `.leaflet-popup`. Se FAIL com 48a OK →
  handler do JetEngine Maps quebrado ou popup template (15372) sem conteúdo.
- Gate 48c PASS: clicar "next" troca a 1ª linha da listagem (paginação JSF funciona).
  Se FAIL → ver gate 36 (load-more/admin-ajax) — mesma família de POST JSF.

> **Nota:** o `+` (`jet-engine-listing-overlay-link`) é um overlay invisível; testes com
> `.click()` sintético / `dispatchEvent` NÃO disparam o handler — usar `page.mouse.click`
> nas coordenadas do elemento (clique de ponteiro real). Os IDs dos cards/markers são os
> dos posts do idioma corrente (EN tem posts próprios via WPML; ver [[feedback_atlas_filter_i18n_glossary_vs_taxonomy]]).

### Snippet — Gate 49 (Menu MOBILE: fundo + tipografia computados, prod vs dev)

Após gate 48, antes do relatório. **Usa Playwright** (viewport mobile 390px + clique real
no toggle hambúrguer). Origem: incidente 2026-06-12 — o fundo do menu mobile (lista suspensa
do widget Nav Menu) ficou **branco** em prod (texto branco sobre branco = invisível), e a
Fase 7.5 **não pegou** porque (a) só compara DOM/altura/headings/erros, nunca `getComputedStyle`
de cor/tipografia; (b) roda em viewport desktop e nunca abre o dropdown mobile; (c) o defeito
existia em prod E dev, então não havia divergência de conteúdo. Ver
[[feedback_menu_mobile_bg_lost_css_to_widget_handoff]].

Este gate fecha a tripla cegueira: mede **estilo computado** do dropdown mobile, em **viewport
mobile com o toggle aberto**. Cobre os 2 headers: blog 1 raiz (`/`, header 39359) e blog 2 cultura
(`/cultura/`, header 89307). O widget tem `id=58b33f3` nos dois (seletor `.elementor-element-58b33f3`).

**Duas réguas de comparação:**
1. **prod × dev** (mesmo blog): tipografia de prod deve espelhar dev (source-of-truth de paridade
   prod/dev, igual Fase 7.5). NÃO comparar `dropdownBg` contra hex fixo — o verde de prod (#003A26)
   é INTENCIONALMENTE diferente do dev (nova paleta da virada, #005A42); só `branco/transparente`
   é bug absoluto.
2. **blog1 × blog2** (mesmo ambiente): o **menu do `/` (blog1) é o SOURCE-OF-TRUTH de ESTILO** —
   `/cultura/` (blog2) deve seguir o `/` em underline + font-size. Se divergirem, ALERTAR o usuário
   (regra explícita Daniel 2026-06-12). Causa típica do drift: o CSS gerado do header blog2
   (`post-89307.css`) emite `.elementor-item{text-decoration:var(--accent)}` (Accent=underline) que
   vence `.elementor a{none}`; o header blog1 não emite essa regra. Fix: setar
   `dropdown_typography_text_decoration=none` explícito no widget do header 89307 (gera `none`
   literal que vence o Accent). Ver [[feedback_menu_mobile_bg_lost_css_to_widget_handoff]].

```javascript
// Snippet smoke (browser_run_code_unsafe) — gate 49 — rodar 1x (itera os 2 hosts × 2 blogs)
async (page) => {
  const HOSTS = { prod: "https://concertacaoamazonia.com.br", dev: "https://concertacao.bureau-it.com" };
  const BLOGS = [
    // Medir em PÁGINAS INTERNAS, não nas homes: na home o toggle do menu mobile
    // nem sempre abre de forma confiável (links ficam visible:false / estado colapsado),
    // tornando underline/font-size instáveis. Páginas internas dão leitura estável.
    { key: "blog1", path: "/atuacao/encontros/" },        // header 39359 (raiz)
    { key: "blog2", path: "/cultura/linha-do-tempo/" },   // header 89307 (cultura)
  ];

  // mede o dropdown mobile (fundo) + um sub-item e um top-item (font-size/decoration)
  const measure = async (host, path) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.context().clearCookies();
    await page.goto(host + path + "?cb=" + Date.now(), { waitUntil: "domcontentloaded", timeout: 30000 });
    await page.waitForTimeout(1500);
    // remove banner de cookies que cobre o toggle
    await page.evaluate(() => document.querySelectorAll('#cmplz-cookiebanner-container,[class*="cmplz-cookiebanner"]').forEach(e => e.remove()));
    // abre o menu mobile com clique REAL no toggle (JS do SmartMenus depende disso)
    await page.evaluate(() => {
      const t = document.querySelector('.elementor-element-58b33f3 .elementor-menu-toggle');
      if (t) t.click();
    });
    await page.waitForTimeout(800);
    return page.evaluate(() => {
      const root = document.querySelector('.elementor-element-58b33f3');
      if (!root) return { rootFound: false };
      const dd = root.querySelector('.elementor-nav-menu--dropdown');
      // enumera TODOS os links pela CLASSE correta (nunca `li>a` solto — gera falso underline)
      const links = [...root.querySelectorAll('a.elementor-sub-item, a.elementor-item')].map(a => {
        const cs = getComputedStyle(a);
        return { kind: a.classList.contains('elementor-sub-item') ? 'sub' : 'top', fontSize: cs.fontSize, decoration: cs.textDecorationLine };
      });
      const sub = links.find(l => l.kind === 'sub') || {};
      const top = links.find(l => l.kind === 'top') || {};
      return {
        rootFound: true,
        dropdownBg: dd ? getComputedStyle(dd).backgroundColor : null,
        topFontSize: top.fontSize || null,
        subFontSize: sub.fontSize || null,
        allDecorationsNone: links.length > 0 && links.every(l => l.decoration === 'none'),
        linkCount: links.length,
      };
    });
  };

  // helper: rgb string -> {r,g,b}; detecta branco/transparente
  const isWhiteOrTransparent = (rgb) => {
    if (!rgb) return true;
    const m = rgb.match(/rgba?\(([^)]+)\)/); if (!m) return true;
    const [r, g, b, a] = m[1].split(',').map(s => parseFloat(s.trim()));
    if (a === 0) return true;                        // transparente
    return (r > 240 && g > 240 && b > 240);          // ~branco
  };

  // mede os 2 blogs em cada ambiente
  const m = {
    prod: { blog1: await measure(HOSTS.prod, BLOGS[0].path), blog2: await measure(HOSTS.prod, BLOGS[1].path) },
    dev:  { blog1: await measure(HOSTS.dev,  BLOGS[0].path), blog2: await measure(HOSTS.dev,  BLOGS[1].path) },
  };

  const out = {};
  for (const env of ['prod', 'dev']) {
    for (const bk of ['blog1', 'blog2']) {
      const r = m[env][bk];
      const peer = m[env === 'prod' ? 'dev' : 'prod'][bk];   // mesmo blog, outro ambiente
      const b1 = m[env].blog1;                               // source-of-truth de estilo (mesmo ambiente)
      const fails = [];
      if (!r.rootFound) { out[`${env}.${bk}`] = { verdict: `FAIL: nav-menu-58b33f3-ausente` }; continue; }
      // 49a — fundo branco/transparente é bug ABSOLUTO (independe de comparação): menu ilegível.
      if (isWhiteOrTransparent(r.dropdownBg)) fails.push(`bg-branco/transparente(${r.dropdownBg})`);
      // 49b/49c — prod × dev (mesmo blog): tipografia espelha o outro ambiente.
      if (env === 'prod' && peer.rootFound) {
        if (r.allDecorationsNone !== peer.allDecorationsNone) fails.push(`underline prod=${!r.allDecorationsNone} dev=${!peer.allDecorationsNone}`);
        if (r.subFontSize && peer.subFontSize && r.subFontSize !== peer.subFontSize) fails.push(`subFont prod=${r.subFontSize} dev=${peer.subFontSize}`);
        if (r.topFontSize && peer.topFontSize && r.topFontSize !== peer.topFontSize) fails.push(`topFont prod=${r.topFontSize} dev=${peer.topFontSize}`);
      }
      // 49d — blog1 × blog2 (mesmo ambiente): /cultura/ DEVE seguir o estilo do / (source-of-truth).
      if (bk === 'blog2' && b1.rootFound) {
        if (r.allDecorationsNone !== b1.allDecorationsNone) fails.push(`STYLE-DRIFT underline /cultura/=${!r.allDecorationsNone} vs /=${!b1.allDecorationsNone}`);
        if (r.subFontSize && b1.subFontSize && r.subFontSize !== b1.subFontSize) fails.push(`STYLE-DRIFT subFont /cultura/=${r.subFontSize} vs /=${b1.subFontSize}`);
        if (r.topFontSize && b1.topFontSize && r.topFontSize !== b1.topFontSize) fails.push(`STYLE-DRIFT topFont /cultura/=${r.topFontSize} vs /=${b1.topFontSize}`);
      }
      out[`${env}.${bk}`] = { path: BLOGS[bk === 'blog1' ? 0 : 1].path, measured: r, verdict: fails.length === 0 ? 'PASS' : `FAIL: ${fails.join(', ')}` };
    }
  }
  return out;
}
```

**Gates do snippet (rodar blog1 + blog2):**
- Gate 49a PASS: `prod.dropdownBg` NÃO é branco/transparente. Se FAIL → `background_color_dropdown_item`
  ausente no widget Nav Menu (regressão da migração CSS→painel de 2026-05-18). Fix: setar via
  `__globals__` → Global Color do fundo + literal; regen Elementor CSS + `rm -rf cache/min/{1,2}/*`
  + `rocket_clean_domain` + CF invalidate `cache/min/*`. Ver [[feedback_menu_mobile_bg_lost_css_to_widget_handoff]].
- Gate 49b PASS: estado de underline de prod == dev (relativo). Se FAIL → prod tem underline e dev
  não (ou vice-versa) — regressão do widget (regra `text-decoration` com variável Accent vazia
  deixa o `a:where(){underline}` do WP core vazar). **Relativo a dev de propósito:** underline pode
  existir igualmente nos 2 ambientes em certos estados de DOM; só é bug se PROD divergir de DEV.
- Gate 49c PASS: `subFontSize`/`topFontSize` de prod == dev. Se FAIL → divergência de tipografia
  (`dropdown_typography_font_size_mobile`); alinhar prod ao dev (source-of-truth prod/dev).
- **Gate 49d PASS (STYLE-DRIFT): `/cultura/` (blog2) tem o MESMO estilo que `/` (blog1) no MESMO
  ambiente** — underline + font-size. **`/` é o source-of-truth de estilo de menu.** Se FAIL →
  ALERTAR o usuário: o menu do `/cultura/` divergiu do `/`. Causa típica: `post-89307.css` emite
  `text-decoration:var(--accent)` (underline) que o header blog1 não emite. Fix: setar
  `dropdown_typography_text_decoration=none` no widget do header 89307 + regen + flush.
  Incidente 2026-06-12 (corrigido em dev).

> **Severidade: HIGH.** Menu de navegação ilegível/divergente é regressão visível ao usuário.
> **GOTCHA de medição (não repetir o erro de 2026-06-12):** medir SEMPRE via
> `a.elementor-sub-item, a.elementor-item` enumerando TODOS os links — `li.menu-item > a` solto
> pega o 1º `<a>` num estado colapsado e reporta `underline` FALSO. Validar no host real do user
> (tunnel `concertacao.bureau-it.com` p/ dev), não `cambrasmax.local`. Clique REAL no toggle
> (`.click()` no DOM dispara o SmartMenus; manipular `display:block` manualmente quebra o layout).
>
### Snippet — Gate 50 (Menu DESKTOP: cor dos itens no estado .highlighted — sem vazamento offwhite)

Após gate 49, antes do gate 51. **Usa Playwright** (viewport desktop ≥1024px + simulação do
estado `.highlighted` do SmartMenus). Origem: incidente 2026-06-22 — itens do menu header
previamente clicados ficavam com a classe residual `.highlighted` (SmartMenus injeta no
hover/click rápido e não remove no mouseleave quando o submenu não abre). A regra do
`header-menu.css` §9.5 (fix stuck-pink) pintava `.highlighted:not(:hover):not(:focus):not(.elementor-item-active)`
com `var(--e-global-color-secondary)` = **#F8EAD9 (offwhite)** → sobre o header escuro os itens
ficavam quase ilegíveis ("Conhecimento sumindo"). Fix: trocar para `var(--e-global-color-bcf690c)`
= #FFFFFF ("header txt" branco). Ver [[feedback_css_validation_computed_not_presence]].

**Por que o Gate 49 NÃO pega:** o Gate 49 mede o menu MOBILE (viewport 390px, fundo/tipografia
do dropdown). Este mede o menu DESKTOP no estado `.highlighted` (cor dos itens). Classes distintas.

**Cobre:** blog 1 (`/sobre-nos/`, header 39359) + blog 2 (`/cultura/linha-do-tempo/`, header 89307),
menu `.elementor-nav-menu--main` (desktop). Mede a cor COMPUTADA (não a presença da regra — ver
gotcha de [[feedback_css_validation_computed_not_presence]]).

```js
async (page) => {
  const ALB_IP_HEADER = HEADER_VAL || {}; // {X-Test-Green:'true'} p/ green; {} p/ prod
  const targets = [
    { blog: 'blog1', url: 'https://concertacaoamazonia.com.br/sobre-nos/' },
    { blog: 'blog2', url: 'https://concertacaoamazonia.com.br/cultura/linha-do-tempo/' },
  ];
  const out = {};
  for (const t of targets) {
    await page.context().setExtraHTTPHeaders(ALB_IP_HEADER);
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.context().clearCookies();
    await page.goto(t.url + '?nocache=' + Date.now(), { waitUntil: 'networkidle', timeout: 35000 });
    await page.waitForTimeout(1500);
    out[t.blog] = await page.evaluate(() => {
      const items = Array.from(document.querySelectorAll('.elementor-location-header .elementor-nav-menu--main a.elementor-item'));
      if (!items.length) return { rootFound: false };
      // simular o resíduo .highlighted do SmartMenus em itens NÃO-ativos
      const measured = [];
      const isOff = (rgb) => { // #F8EAD9 ≈ rgb(248,234,217) — offwhite vazando
        const m = (rgb||'').match(/rgba?\(([^)]+)\)/); if (!m) return false;
        const [r,g,b] = m[1].split(',').map(x=>parseFloat(x));
        return r>235 && r<255 && g>225 && g<245 && b>205 && b<230; // faixa offwhite (não branco puro)
      };
      items.forEach(a => {
        if (a.classList.contains('elementor-item-active')) return; // ativo é rosa, ok
        a.classList.add('highlighted');
        const c = getComputedStyle(a).color;
        measured.push({ text: a.innerText.trim().slice(0,20), color: c, offwhite_leak: isOff(c) });
        a.classList.remove('highlighted');
      });
      return {
        rootFound: true,
        items: measured.slice(0, 8),
        leak_count: measured.filter(m => m.offwhite_leak).length,
      };
    });
  }
  const fails = [];
  for (const blog of ['blog1','blog2']) {
    const r = out[blog];
    if (!r || !r.rootFound) { fails.push(`${blog}:menu-desktop-ausente`); continue; }
    if (r.leak_count > 0) fails.push(`${blog}:offwhite_leak=${r.leak_count}`);
  }
  return { ...out, verdict: fails.length === 0 ? 'PASS' : `FAIL: ${fails.join(', ')}` };
}
```

**Gates do snippet (rodar blog1 + blog2; em green passar `HEADER_VAL={'X-Test-Green':'true'}`):**
- **Gate 50** PASS: nenhum item do menu desktop fica offwhite (#F8EAD9) no estado `.highlighted`.
  `leak_count === 0` em ambos os blogs.
- **Gate 50** FAIL `offwhite_leak=N`: a regra `.highlighted` do header-menu.css §9.5 voltou a usar
  `--e-global-color-secondary` (offwhite) em vez de `--e-global-color-bcf690c` (branco). Itens
  previamente clicados ficam ilegíveis sobre o header escuro. Fix: editar a regra §9.5 para
  `color: var(--e-global-color-bcf690c); fill: var(--e-global-color-bcf690c);` + rsync tema +
  reload php-fpm + rocket_clean_minify('css') + CF invalidate do CSS.

> **Severidade: HIGH.** Menu desktop com itens ilegíveis pós-clique é regressão visível ao usuário.
> **GOTCHA:** validar pela cor COMPUTADA (`getComputedStyle(a).color`), nunca pela presença da
> regra no CSS — ver [[feedback_css_validation_computed_not_presence]]. Estado `.highlighted` é
> transitório (SmartMenus); simular via `classList.add('highlighted')` em vez de tentar reproduzir
> o hover-race. Cache de disco do Chrome é teimoso (`Cache-Control: immutable`) — medir com
> `?nocache=Date.now()` + `clearCookies()`.

### Snippet — Gate 51 (Paginação de eventos TEC — Próximos/Anteriores navegam, prod)

Após gate 49, antes do relatório. **Usa fetch HTTP** (leve, determinístico). Origem: incidente
2026-06-22 — o widget de eventos TEC (List View) das páginas `/`, `/editais/`, `/eventos-calendario/`
parou de paginar em **prod** (setas "Próximos/Anteriores" não avançavam de página). Causa raiz:
**CloudFront cacheava o HTML dinâmico de `/eventos/lista/...` por 24h sem diferenciar as query
strings de navegação** (`eventDisplay`/`tribe-bar-date`/`tribe_paged` NÃO estão na whitelist da
cache policy `wp-cache-default-hostaware` `8e1062b8`) → colapsava todas as variantes numa entrada
e servia paginação stale. O **origin gera a paginação correta** (`<a data-js>` ativo para
`/eventos/lista/página/2/`); o edge servia uma versão velha com `<button disabled>` + links no
formato antigo `?tribe-bar-date=`. Dev nunca falha (sem CF na frente). Ver
[[project_tec_pagination_cf_cache_stale]] e [[project_tec_arrows_reload_not_ajax]].

O gate compara o HTML que o **browser recebe via CloudFront** (edge) com o que o **origin gera
fresco**, no caminho canônico de paginação `/eventos/lista/?eventDisplay=past` (que SEMPRE tem
≥2 páginas: há 150 eventos past / 20 por página = ~8 páginas). A discrepância edge≠origin no
botão "Próximos" é a assinatura exata do bug.

```javascript
// Snippet smoke (browser_run_code_unsafe) — gate 51 — paginação TEC via HTTP
async (page) => {
  const PAST = "https://concertacaoamazonia.com.br/eventos/lista/?eventDisplay=past";
  // Heurística: "Próximos" deve ser <a data-js="tribe-events-view-link"> (ativo),
  // e o HTML deve conter um link de paginação para /página/2/ (ou /page/2/).
  // O bug servia <button ... disabled> no lugar do <a> e SEM link /página/2/.
  const analyze = (html) => {
    const next_is_anchor   = /<a[^>]*tribe-events-c-nav__next/i.test(html);
    const next_is_disabled = /tribe-events-c-nav__next[^>]*\bdisabled\b/i.test(html);
    const has_page2_link   = /eventos\/lista\/(p%c3%a1gina|p[áa]gina|page)\/2\//i.test(html);
    const event_rows       = (html.match(/tribe-events-calendar-list__event-row/g) || []).length;
    return { next_is_anchor, next_is_disabled, has_page2_link, event_rows };
  };

  // (1) EDGE — o que o browser/CloudFront entrega (com header de cache)
  const edgeResp = await page.request.get(PAST, {
    headers: { "X-Forwarded-Proto": "https" },
  });
  const edgeHtml = await edgeResp.text();
  const edge = analyze(edgeHtml);
  edge.x_cache = edgeResp.headers()["x-cache"] || null;
  edge.age = edgeResp.headers()["age"] || null;

  // (2) ORIGIN — fresco, contornando o cache de página do WP Rocket via ?nowprocket=1
  //     (NÃO contorna o CloudFront — por isso comparamos os dois; se o CF servir stale,
  //      o edge diverge mesmo com nowprocket, pois o CF ignora a query string no cache key)
  const originResp = await page.request.get(PAST + "&nowprocket=1&cb=" + Date.now(), {
    headers: { "X-Forwarded-Proto": "https" },
  });
  const originHtml = await originResp.text();
  const origin = analyze(originHtml);

  // PASS: edge serve paginação saudável (Próximos = <a> ativo, link página/2 presente,
  //       e NÃO disabled). Origin é a referência (deve estar sempre saudável).
  const gate_51_edge_ok =
    edge.next_is_anchor && edge.has_page2_link && !edge.next_is_disabled && edge.event_rows > 0;
  const gate_51_origin_ok =
    origin.next_is_anchor && origin.has_page2_link && !origin.next_is_disabled;
  // Assinatura do bug de cache stale: origin saudável MAS edge quebrado.
  const gate_51_stale_cache = gate_51_origin_ok && !gate_51_edge_ok;

  // ── Gate 51b: link "Anteriores" das PÁGINAS com widget TEC não pode ter ?pagename= ──
  // O widget tec_elementor_widget_events_view em /editais e /eventos-calendario gerava
  // o link prev como /eventos/lista/?pagename=<slug>&... → 404 (conflito pagename vs
  // archive post_type=tribe_events). Fix: mu-plugin bit-tec-strip-pagename-nav-url.php.
  // Validamos que o link gerado NÃO tem pagename E que a URL resolve 200.
  const WIDGET_PAGES = [
    "https://concertacaoamazonia.com.br/editais/",
    "https://concertacaoamazonia.com.br/eventos-calendario/",
  ];
  const navurl = [];
  for (const purl of WIDGET_PAGES) {
    const r = await page.request.get(purl + "?cb=" + Date.now(), { headers: { "X-Forwarded-Proto": "https" } });
    const html = await r.text();
    const m = html.match(/href="([^"]*eventDisplay=past[^"]*)"/);
    const prevLink = m ? m[1].replace(/&#0?38;/g, "&") : null;
    const hasPagename = prevLink ? /pagename=/.test(prevLink) : false;
    let prevStatus = null;
    if (prevLink) {
      const pr = await page.request.get(prevLink, { headers: { "X-Forwarded-Proto": "https" }, maxRedirects: 0 }).catch(() => null);
      prevStatus = pr ? pr.status() : null;
    }
    navurl.push({ page: purl, prevLink, hasPagename, prevStatus, ok: !hasPagename && prevStatus === 200 });
  }
  const gate_51b_ok = navurl.every(n => n.ok);

  return {
    url: PAST,
    edge, origin,
    gate_51_edge_ok,
    gate_51_origin_ok,
    gate_51_stale_cache,
    gate_51b_navurl_no_pagename: { pass: gate_51b_ok, pages: navurl },
    pass: gate_51_edge_ok && gate_51b_ok,
  };
}
```

**Gates do snippet:**
- **Gate 51 PASS:** `edge.next_is_anchor === true` && `edge.has_page2_link === true` &&
  `edge.next_is_disabled === false` && `edge.event_rows > 0`. Paginação navega no browser real.
- **Gate 51 FAIL (`gate_51_stale_cache === true`) — CACHE STALE (causa conhecida):** o origin gera
  paginação correta mas o **edge serve `<button disabled>` / sem link `/página/2/`**. CloudFront
  está servindo HTML dinâmico velho. **Severidade: HIGH** (paginação de eventos/editais quebrada
  para o usuário). **Fix imediato:**
  `aws cloudfront create-invalidation --distribution-id E2F1QD7E7YOYEB --profile Concertação --paths '/eventos*' '/editais*' '/eventos-calendario*' '/'`
  **Fix definitivo JÁ APLICADO 2026-06-22:** behavior CF dedicado `eventos*`+`eventos-calendario*`+
  `editais*` com cache policy `WP-Events-ShortTTL-HostAware` (`f24028ef`, TTL 300/900, QS=all). Se
  este gate FALHAR por stale_cache mesmo com o behavior ativo, investigar: (a) o behavior foi removido
  num redeploy/drift da distribuição? (rodar `/audit-acl`); (b) multi-PoP transitório (re-rodar);
  (c) a home `/` (que NÃO tem behavior dedicado — fora do escopo) está sendo testada por engano.
  Ver [[project_tec_pagination_cf_cache_stale]].
- **Gate 51 FAIL (`gate_51_origin_ok === false`) — paginação quebrada NA ORIGEM:** o próprio
  servidor não gera paginação (não é cache). Investigar permalinks do TEC, rewrite rules de
  `/eventos/lista/página/N/`, ou config do widget. Multi-PoP do CF pode mascarar (curl pega 1 PoP);
  rodar o gate algumas vezes ou validar via `curl -H 'Host:' http://127.0.0.1` no origin direto
  (SSH) — ver [[feedback_gate27_multipop_blindspot]].
- **Gate 51b FAIL (`gate_51b_navurl_no_pagename.pass === false`) — link Anteriores com `?pagename=` → 404:**
  o widget TEC em `/editais/` ou `/eventos-calendario/` gera o link "Anteriores" como
  `/eventos/lista/?pagename=<slug>&...` que retorna **404** (conflito `pagename` vs archive
  `post_type=tribe_events` no WP_Query). **Severidade: HIGH** (navegação de eventos passados quebrada).
  **Causa:** mu-plugin `bit-tec-strip-pagename-nav-url.php` ausente/inativo, OU `_elementor_element_cache`
  das páginas 65139/80093 prendendo o link antigo (deletar via `delete_post_meta` + rocket_clean + CF
  invalidate). Cada item de `pages[]` mostra `prevLink`, `hasPagename`, `prevStatus`. Ver
  [[project_tec_pagename_404_navurl]] [[feedback_menu_item_header_element_cache]].

> **Nota:** este gate é **prod-only** (dev não tem CloudFront — sempre serve o origin fresco, então
> nunca reproduz o bug). Não comparar contra green/dev. O caminho `?eventDisplay=past` é escolhido
> de propósito por ter ≥2 páginas garantidas; a home `/` e `/editais/` mostram só upcoming (poucos
> eventos → "Próximos" legitimamente disabled), por isso não servem de canário de paginação.

### Snippet — Gate 53 (Imagens só-green servem via _oac-canary — validação de uploads da GREEN)

**GREEN-ONLY** (rodar só na validação da green, não em prod). Origem: incidente blue-green 2026-06-22.
No padrão CF-OAC, a green grava uploads em `s3://bucket/green/uploads/` mas o CloudFront serve
`/wp-content/uploads/*` de `assets/uploads/` (prod). Imagens NOVAS que existem só em `green/`
(entraram no dev recentemente) dão **HTTP 403** na validação da green via URL normal — heros/
backgrounds aparecem sem imagem. **Isto é by-design** (uploads só promovem para `assets/` no cutover
atômico da phase7); NÃO é bug a corrigir tocando prod. O mecanismo correto de validação é o
**`_oac-canary`**: a CF Function `uploads-oac-router` + behaviors `*/wp-content/uploads/_oac-canary/*`
→ origin `S3-uploads-green` permitem servir as imagens do `green/` sem tocar `assets/`.

**O que o gate valida:** as imagens só-green, quando servidas via `/wp-content/uploads/_oac-canary/<path>`,
retornam **200** (não 403). Confirma que (a) o `green/uploads/` foi populado pela phase3 (`--uploads-mode=s3-sync`),
(b) a CF Function + behaviors canary estão funcionais. Se o gate falhar, a validação visual da green
estará cega (imagens não aparecem nem via canary) — investigar o sync S3 e os behaviors.

**Pré-req:** computar o diff S3 `green/uploads` vs `assets/uploads` (a lista de paths só-green). Via SSH/aws:
```bash
# rodar antes do snippet — gera a lista de até N amostras de paths só-green
AWS_PROFILE=Concertação
B=concertacaoamazonia-com-br-wp-static-prd-sa
comm -23 \
  <(aws s3 ls "s3://$B/green/uploads/" --recursive | awk '{print $4}' | sed 's#^green/uploads/##' | sort) \
  <(aws s3 ls "s3://$B/assets/uploads/" --recursive | awk '{print $4}' | sed 's#^assets/uploads/##' | sort) \
  | grep -iE '\.(jpg|jpeg|png|webp)$' | head -20
```

**Snippet Playwright (recebe a lista `GREEN_ONLY_PATHS` do diff acima):**

```js
async (page) => {
  const FQDN = 'https://concertacaoamazonia.com.br';
  // substituir pela lista do diff S3 (paths relativos a uploads/, ex: '2026/06/Acai-Joao-Ramid1.jpg')
  const GREEN_ONLY_PATHS = GREEN_ONLY_PATHS_AQUI;
  if (!GREEN_ONLY_PATHS || !GREEN_ONLY_PATHS.length) {
    return { gate_53: { skipped: true, reason: 'nenhuma imagem só-green (green ⊆ assets) — nada a validar' } };
  }
  const results = [];
  for (const p of GREEN_ONLY_PATHS.slice(0, 15)) {
    // via path NORMAL (deve dar 403 — confirma que é só-green) e via CANARY (deve dar 200)
    const normal = await page.evaluate(async (u) => {
      try { const r = await fetch(u, { cache: 'no-store' }); return r.status; } catch(e){ return 0; }
    }, `${FQDN}/wp-content/uploads/${p}?cb=${Date.now()}`);
    const canary = await page.evaluate(async (u) => {
      try { const r = await fetch(u, { cache: 'no-store' }); return { status: r.status, ct: (r.headers.get('content-type')||'').slice(0,20) }; } catch(e){ return { status: 0 }; }
    }, `${FQDN}/wp-content/uploads/_oac-canary/${p}?cb=${Date.now()}`);
    results.push({ path: p.slice(-45), normal_status: normal, canary_status: canary.status, canary_ct: canary.ct });
  }
  const canary_ok = results.every(r => r.canary_status === 200);
  const normal_403 = results.filter(r => r.normal_status === 403).length;
  return {
    gate_53_green_uploads_canary: {
      pass: canary_ok,
      total_checked: results.length,
      canary_serving_200: results.filter(r => r.canary_status === 200).length,
      normal_path_403: normal_403,  // esperado > 0 (confirma que são só-green, by-design pré-cutover)
      samples: results.slice(0, 5),
    },
  };
}
```

**Gates do snippet:**
- **Gate 53 PASS:** todas as imagens só-green retornam **200 via `_oac-canary`** (`canary_status===200`).
  O `normal_path_403 > 0` é ESPERADO e benigno (confirma que são imagens só-green — resolverão no cutover).
- **Gate 53 FAIL (`canary_status !== 200`):** a imagem não serve nem via canary. A validação visual da
  green está cega. Causas: (a) `green/uploads/` não foi populado — a phase3 não rodou com `--uploads-mode=s3-sync`
  ou o `aws s3 sync` falhou; (b) a CF Function `uploads-oac-router` ou os behaviors `_oac-canary` foram
  removidos/quebrados (rodar `/audit-acl`); (c) o objeto realmente não existe no S3. **Severidade: MEDIUM**
  (não quebra prod — é gap de validação). Fix: re-rodar `phase3 --uploads-mode=s3-sync` e/ou validar a CF Function.
- **SKIP:** se `green ⊆ assets` (nenhuma imagem só-green), o gate reporta `skipped` — não há o que validar.

> **Nota:** o 403 na URL NORMAL das imagens só-green NÃO é um FAIL — é o comportamento correto do CF-OAC
> pré-cutover. As imagens "promovem" para `assets/` (e param de dar 403) no cutover atômico (phase7,
> swap green→assets). Para validação HUMANA ao vivo no browser, o procedimento é reescrever as URLs de
> uploads para `_oac-canary/` (regra Requestly "Replace String" `/wp-content/uploads/` →
> `/wp-content/uploads/_oac-canary/`, confiável pois o `s3-sync` faz `green ⊇ assets`), OU revisar os
> screenshots gerados por `testes/tests/99-green-visual.spec.js` (que faz o rewrite seletivo via diff S3).
> Ver [[project_oac_canary_strip_pattern]].

### Snippet — Gate 54 (Paridade `bureau_a11y_colors` multisite + prod↔green)

**Severidade: HIGH.** Origem: incidente 2026-06-22 (cores do painel a11y caíam no fallback verde-bandeira `#005A42` em prod). A `option bureau_a11y_colors` é **per-blog** em multisite — blog 1 (raiz) e blog 2 (`/cultura/`) têm chaves separadas, e o admin de cada blog precisa ser configurado independentemente. Regra operacional: **`/cultura/` deve ter sempre as MESMAS cores da raiz** (paleta canônica é a do blog 1) e **green deve refletir prod** (deploy blue-green).

**O que o gate valida** (via SSH+WP-CLI, sem Playwright):
- (a) `option bureau_a11y_colors` existe nos dois blogs (ausência = painel cai em fallbacks hardcoded).
- (b) **blog 1 == blog 2** no MESMO ambiente (paridade multisite).
- (c) **prod blog 1 == green blog 1** (paridade entre ambientes — detecta drift pós-blue-green).
- (d) Plugin versão ≥ 2.9.9 (versões anteriores tinham bug de escopo CSS `:root` vs `.elementor-kit-N`).

**Snippet (rodar em prod e green; comparar entre si):**

```bash
# Função reutilizável
fetch_a11y_state() {
  local SSH_ALIAS="$1"   # ex: concertacaoamazonia.com.br-prod-sa | -green-sa
  local LABEL="$2"
  local WPROOT="/var/www/concertacaoamazonia.com.br"
  local FQDN="https://concertacaoamazonia.com.br"
  ssh "$SSH_ALIAS" "sudo -u www-data wp --path=$WPROOT eval '
    echo \"label=$LABEL\n\";
    echo \"version=\", defined(\"BUREAU_A11Y_VERSION\") ? BUREAU_A11Y_VERSION : \"undefined\", \"\n\";
    echo \"blog1=\", (string) get_option(\"bureau_a11y_colors\", \"MISSING\"), \"\n\";
  ' --url=$FQDN 2>/dev/null
  ssh \"$SSH_ALIAS\" \"sudo -u www-data wp --path=$WPROOT option get bureau_a11y_colors --format=json --url=$FQDN/cultura/ 2>/dev/null\" | head -1 | sed 's/^/blog2_json=/'
  "
}

# Coletar estado
PROD=$(fetch_a11y_state "concertacaoamazonia.com.br-prod-sa" "prod")
GREEN=$(fetch_a11y_state "concertacaoamazonia.com.br-green-sa" "green")

# Extrair JSON serialized + serialized-php do blog 1 e converter pra JSON via wp eval (mais simples: pedir o JSON direto)
read_option_json() {
  ssh "$1" "sudo -u www-data wp --path=/var/www/concertacaoamazonia.com.br option get bureau_a11y_colors --format=json --url=$2 2>/dev/null"
}
PROD_B1=$(read_option_json "concertacaoamazonia.com.br-prod-sa"  "https://concertacaoamazonia.com.br")
PROD_B2=$(read_option_json "concertacaoamazonia.com.br-prod-sa"  "https://concertacaoamazonia.com.br/cultura/")
GREEN_B1=$(read_option_json "concertacaoamazonia.com.br-green-sa" "https://concertacaoamazonia.com.br")
GREEN_B2=$(read_option_json "concertacaoamazonia.com.br-green-sa" "https://concertacaoamazonia.com.br/cultura/")

VERSION_PROD=$(ssh concertacaoamazonia.com.br-prod-sa  "grep -E 'BUREAU_A11Y_VERSION' /var/www/concertacaoamazonia.com.br/wp-content/mu-plugins/bureau-a11y.php | head -1 | grep -oE \"[0-9]+\.[0-9]+\.[0-9]+\"")
VERSION_GREEN=$(ssh concertacaoamazonia.com.br-green-sa "grep -E 'BUREAU_A11Y_VERSION' /var/www/concertacaoamazonia.com.br/wp-content/mu-plugins/bureau-a11y.php | head -1 | grep -oE \"[0-9]+\.[0-9]+\.[0-9]+\"")

# Avaliação
[ -n "$PROD_B1"  ] && [ "$PROD_B1"  != "[]" ] && OK_PROD_B1=1  || OK_PROD_B1=0
[ -n "$PROD_B2"  ] && [ "$PROD_B2"  != "[]" ] && OK_PROD_B2=1  || OK_PROD_B2=0
[ -n "$GREEN_B1" ] && [ "$GREEN_B1" != "[]" ] && OK_GREEN_B1=1 || OK_GREEN_B1=0
[ -n "$GREEN_B2" ] && [ "$GREEN_B2" != "[]" ] && OK_GREEN_B2=1 || OK_GREEN_B2=0

[ "$PROD_B1"  = "$PROD_B2"  ] && PARITY_PROD_MULTISITE=1  || PARITY_PROD_MULTISITE=0
[ "$GREEN_B1" = "$GREEN_B2" ] && PARITY_GREEN_MULTISITE=1 || PARITY_GREEN_MULTISITE=0
[ "$PROD_B1"  = "$GREEN_B1" ] && PARITY_PROD_GREEN=1      || PARITY_PROD_GREEN=0

# Versão ≥ 2.9.9
ver_ge_299() { [ "$(printf '%s\n2.9.9\n' "$1" | sort -V | head -1)" = "2.9.9" ]; }
ver_ge_299 "$VERSION_PROD"  && VER_OK_PROD=1  || VER_OK_PROD=0
ver_ge_299 "$VERSION_GREEN" && VER_OK_GREEN=1 || VER_OK_GREEN=0

cat <<EOF
gate_54_a11y_colors_parity:
  option_present:
    prod_blog1:  $([ "$OK_PROD_B1"  = 1 ] && echo PASS || echo FAIL)
    prod_blog2:  $([ "$OK_PROD_B2"  = 1 ] && echo PASS || echo FAIL)
    green_blog1: $([ "$OK_GREEN_B1" = 1 ] && echo PASS || echo FAIL)
    green_blog2: $([ "$OK_GREEN_B2" = 1 ] && echo PASS || echo FAIL)
  parity_multisite:
    prod_blog1_eq_blog2:   $([ "$PARITY_PROD_MULTISITE"  = 1 ] && echo PASS || echo FAIL)
    green_blog1_eq_blog2:  $([ "$PARITY_GREEN_MULTISITE" = 1 ] && echo PASS || echo FAIL)
  parity_envs:
    prod_blog1_eq_green_blog1: $([ "$PARITY_PROD_GREEN" = 1 ] && echo PASS || echo FAIL)
  plugin_version:
    prod:  $VERSION_PROD  $([ "$VER_OK_PROD"  = 1 ] && echo "(≥2.9.9 ✓)" || echo "(<2.9.9 ✗)")
    green: $VERSION_GREEN $([ "$VER_OK_GREEN" = 1 ] && echo "(≥2.9.9 ✓)" || echo "(<2.9.9 ✗)")
EOF
```

**Gates do snippet:**

- **Gate 54a `option_missing` (FAIL):** `bureau_a11y_colors` ausente em algum dos 4 contextos (prod/green × blog1/blog2). Painel a11y cai nos **fallbacks hardcoded** em `BUREAU_A11Y_FALLBACK_COLORS` (verde-bandeira `#005A42`, magenta `#B12B79`). Fix: copiar option do blog/ambiente canônico:
  ```bash
  # Copiar prod blog 1 → green blog 2 (exemplo):
  ssh concertacaoamazonia.com.br-prod-sa "sudo -u www-data wp --path=/var/www/concertacaoamazonia.com.br option get bureau_a11y_colors --format=json" \
    | ssh concertacaoamazonia.com.br-green-sa "cat > /tmp/a11y.json && sudo -u www-data wp --path=/var/www/concertacaoamazonia.com.br option update bureau_a11y_colors --format=json --url=https://concertacaoamazonia.com.br/cultura/ < /tmp/a11y.json && rm /tmp/a11y.json"
  ```
  Depois flush: `sudo -u www-data wp eval 'delete_transient("bureau_a11y_elementor_globals"); rocket_clean_home();'`.

- **Gate 54b `parity_multisite` (FAIL):** blog 1 ≠ blog 2 no mesmo ambiente. Regra operacional: **/cultura é cópia da raiz**. Quem mudou um sem o outro? Auditar quem alterou (admin user de blog 2 que mexeu no painel `BIT A11y Acessibilidade`). Fix: re-copiar do blog 1 (canônico) para blog 2 (mesmo procedimento de 54a).

- **Gate 54c `parity_envs` (FAIL):** prod blog 1 ≠ green blog 1. Drift entre ambientes pós-blue-green. Auditar: o admin foi tocado em só um dos ambientes? Phase3 do blue-green não preserva options? Fix: copiar do prod (verdade pública) pra green. **Atenção:** se a divergência é intencional (em preparação de cutover com nova paleta), suprimir esse gate só na execução com `--allow-paleta-drift` ou similar.

- **Gate 54d `plugin_version_outdated` (FAIL):** versão < 2.9.9. Versões anteriores tinham bugs reais:
  - **2.9.6**: emit `var(--e-global-color-X, FALLBACK)` em `:root` → fallback ativa (verde) porque `--e-global-color-*` só existe em `.elementor-kit-N` no `<body>`, e `#bureau-a11y-trigger` é movido pra fora do `<body>` pelo JS (filho direto de `<html>`).
  - **2.9.7**: tentou consertar com seletor `:root, .elementor-kit-N` (não funciona — trigger está em `<html>` mesmo).
  - **2.9.8**: resolve var() no PHP (hex direto). MAS o `bureau-a11y.css` ainda tinha 70+ hardcodes `#BDF839` (verde-limão) e `#005A42` ignorando `var(--ba-*)`.
  - **2.9.9 (correto)**: PHP resolve hex + CSS migrado pra `var(--ba-*)` + `color-mix(in srgb, var(--ba-electric) X%, transparent)`.

  Fix: deploy v2.9.9 — scp do `bureau-a11y.php` + `bureau-a11y/bureau-a11y.css` da cópia canônica em `docker-dev/common/mu-plugins/` → reload php-fpm → flush WP Rocket.

Ver: [[project_a11y_multisite_parity_v299]] (memo desta sessão), `feedback_validate_via_real_browser_not_just_cssom.md`.

## Relatório Final Pragmático

Após executar todas as fases, gerar **bloco único** com formato decisível:

```
═══════════════════════════════════════════════════════════════════
SMOKE TEST REPORT — Concertação Amazônia
Executado: <timestamp ISO>
Duração: <Xmin>
═══════════════════════════════════════════════════════════════════

VEREDICTO: ✅ PASS  |  ⚠️ PASS_WITH_RESSALVAS  |  🚨 FAIL  |  ⛔ BLOCKER

Critério:
  • PASS                 = 0 gates falharam
  • PASS_WITH_RESSALVAS  = só MEDIUM/LOW falharam, nenhum HIGH/BLOCKER
  • FAIL                 = ≥1 gate HIGH falhou (não BLOCKER)
  • BLOCKER              = ≥1 gate BLOCKER falhou — NÃO PROMOVER PARA PROD

───────────────────────────────────────────────────────────────────
RESUMO POR FASE
───────────────────────────────────────────────────────────────────

Fase  Cobertura                    Gates testados   Pass   Fail
─────────────────────────────────────────────────────────────────
1-5   Páginas críticas (PROD+GREEN)   1-6              X      Y
6-7   Forms PROD                       7-8              X      Y
6-7   Forms GREEN submit               9-10             X      Y
7.5   Paridade DEV→PROD                13               X      Y
7.6   Complianz multisite              (sem gate #)     —      —
7.7   GTM injection                    (sem gate #)     —      —
7.8   Cache health (4 camadas)         14-19            X      Y
8     Menu warm-up                     11-12            X      Y
9     Leak detection                   20-25            X      Y
49    Menu mobile visual (prod×dev)    49a-49c          X      Y
54    A11y colors parity (multisite + prod↔green) 54a-d X      Y

───────────────────────────────────────────────────────────────────
GATES FALHARAM (ordenados por severidade)
───────────────────────────────────────────────────────────────────

⛔ BLOCKER (NÃO PROMOVER):
  • Gate 22 — CSS de prod referencia concertacao.bureau-it.com (3 leaks)
    → Fix: wp elementor flush_css + rocket_clean_post + CF invalidation
  • Gate 24 — <img src> com /green/ em prod (12 imagens vazando)
    → Fix: wp config set S3_UPLOADS_BUCKET .../assets + reload FPM + sync S3

🚨 HIGH (FAIL):
  • Gate 20 — Banner Complianz em /en/ está em PT
    → Fix: criar mu-plugin com filtro wpml_translate_single_string

⚠️ MEDIUM/LOW: <listar se houver>

───────────────────────────────────────────────────────────────────
GATES PASSARAM (sumário)
───────────────────────────────────────────────────────────────────

✅ Páginas 1-5 (PROD): hostname OK, listings populados, 0 uploads_elementor_css
✅ Forms PROD: form_count=2, submit_label="ENVIAR"
⏭️ Forms GREEN submit: SKIPPED (green offline — guard previne poluição CRM)
✅ Paridade DEV→PROD em 16/16 paths do menu
✅ Cache health: drop-in OK, page cache 96% improvement, CF hit, bypass works
✅ Menu warm-up: todos itens TTFB <1500ms

───────────────────────────────────────────────────────────────────
AÇÕES IMEDIATAS RECOMENDADAS
───────────────────────────────────────────────────────────────────

ANTES DE PROMOVER NOVA INSTÂNCIA OU CUTOVER:
  1. Corrigir gate 22 (CSS leak) — comando: wp elementor flush_css
  2. Corrigir gate 24 (S3 path) — verificar wp-config WP_UPLOADS_BUCKET
  3. Re-rodar /smoke após fixes para validar

PÓS-DEPLOY (24-48h):
  4. Corrigir gate 20 (Complianz EN) — criar mu-plugin
  5. Monitorar /var/log/php8.3-fpm.log por novos warnings

───────────────────────────────────────────────────────────────────
MÉTRICAS DE PERFORMANCE (PROD, sample 10 páginas)
───────────────────────────────────────────────────────────────────

  TTFB médio (cached):     XX ms
  TTFB p95 (cached):       XX ms
  TTFB médio (origin):     XX ms
  CF hit ratio:            XX%
  Console errors médio:    X / página

═══════════════════════════════════════════════════════════════════
```

**Regras do relatório:**
- **Sempre incluir veredicto único** no topo (4 estados possíveis)
- **Listar gates falhados em ordem de severidade** (BLOCKER → HIGH → MEDIUM/LOW)
- **Para cada gate falhado: incluir comando de fix** (1-line) ou referência a memo
- **Sumarizar gates passaram** em 1 linha cada (não detalhar)
- **Métricas de performance**: 5-7 números agregados, sem tabela por página
- **Ações imediatas**: máximo 5 itens, ordenados por prioridade
- **Sem HTML/markdown rico**: ASCII puro com `─` e `═` para legibilidade em terminal e logs

## Veredicto

✅ **SMOKE PASS** — todas as 5 páginas + 2 formulários verdes prontos para cutover.
🚨 **SMOKE FAIL** — listar gates disparados, sugerir fixes.

---

## Ver também

**`/perfometro`** — complementar, eixo ortogonal. O `/smoke` valida **regressão funcional** ao
vivo em prod/green (E2E via browser: páginas renderizam, formulários submetem, paginação navega).
O `/perfometro` mede **performance/qualidade de config** de um site (score 0-100: cache, plugins
redundantes, CSS Global Colors, memória) e prescreve correções via `std`.

Um site pode passar 100% no `/smoke` (funcional) e ainda ter score baixo no `/perfometro` (lento).
Sequência típica num ciclo de deploy: `/perfometro` (otimizar antes) → deploy → `/smoke` (validar
depois). Ponto de tangência: ambos tocam em cache — o `/perfometro` audita se está **configurado**
certo; o `/smoke` valida se está se **comportando** certo em produção (gates de cache-health,
edge vs origin).
