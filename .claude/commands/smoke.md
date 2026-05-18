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
- PROD: SSH `concertacaoamazonia.com.br-prod-sa` e `grep BIT_SMOKE_BYPASS_TOKEN /var/www/concertacaoamazonia.com.br/wp-config.php`

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
- `NOOP` — token errado, constante ausente, ou header não chegou ao PHP. Conferir constante via SSH: `grep BIT_SMOKE_BYPASS_TOKEN /var/www/concertacaoamazonia.com.br/wp-config.php`.
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

**DEV é a fonte da verdade**: a lista de páginas é descoberta varrendo o menu da home DEV. Páginas que existem em DEV mas não em PROD = pendência de deploy. Páginas que renderizam diferente = regressão.

### Snippet 1 — Descobrir páginas do menu em DEV (executar 1x)

```js
async (page) => {
  await page.context().clearCookies();
  await page.goto('https://concertacao.bureau-it.com/?cb=' + Date.now(), { waitUntil: 'domcontentloaded', timeout: 30000 });

  const urls = await page.evaluate(() => {
    const sels = [
      'header nav a[href]',
      '.elementor-nav-menu a[href]',
      '#site-navigation a[href]',
      '.main-navigation a[href]',
      'nav.elementor-nav-menu--main a[href]',
      'footer nav a[href]',          // inclui menu do footer
      '.elementor-location-footer a[href]',
    ];
    const set = new Set();
    sels.forEach(s => document.querySelectorAll(s).forEach(a => {
      const href = a.href || '';
      if (!href || href.startsWith('javascript:') || href.startsWith('mailto:') || href.startsWith('tel:')) return;
      // Aceitar apenas URLs do próprio domínio dev (tunnel)
      if (!href.startsWith('https://concertacao.bureau-it.com/')) return;
      const url = new URL(href);
      // Strip de query/hash, normalizar path com trailing slash
      let path = url.pathname;
      if (!path.endsWith('/')) path += '/';
      // Pular: home, feeds, comments, eventos individuais (event/...), wp-*
      if (path === '/' || path === '/en/') return;
      if (path.includes('/comments/feed') || path.includes('/feed/')) return;
      if (path.startsWith('/event/') || path.startsWith('/en/event/')) return;
      if (path.startsWith('/wp-')) return;
      set.add(path);
    }));
    return [...set].sort();
  });

  return { discovered_count: urls.length, urls };
}
```

Esperado: ~15-25 paths PT/EN únicos (`/atuacao/`, `/conhecimento/`, `/conhecimento/espiral-de-conhecimento/`, `/contato/`, `/en/activities/`, etc).

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

Após varredura no DEV (Snippet 1), espera-se ~15-25 paths:

**PT (~10):** `/atuacao/`, `/atuacao/iniciativas-estruturantes/`, `/atuacao/grupos-de-trabalho/`, `/atuacao/encontros/`, `/atuacao/atuacao-internacional/`, `/atuacao/faq/`, `/conhecimento/`, `/conhecimento/espiral-de-conhecimento/`, `/conhecimento/mapa-das-plataformas/`, `/conhecimento/publicacoes/`, `/conhecimento/entrevistas/`, `/agenda-integradora/`, `/contato/`, `/cultura/`, `/cultura/atlas-cultural-das-amazonias/`, `/aviso-de-privacidade/`

**EN (~10):** equivalentes WPML em `/en/`

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
    return await page.evaluate(() => {
      const banner = document.querySelector('.cmplz-cookiebanner, #cmplz-cookiebanner-container, [class*="cmplz-banner"]');
      const html = document.documentElement.outerHTML;
      const cmplzScripts = Array.from(document.querySelectorAll('script[src]'))
        .filter(s => /cmplz|complianz/i.test(s.src)).length;
      return {
        url: location.href,
        banner_visible: banner ? getComputedStyle(banner).display !== 'none' : false,
        has_accept: !!document.querySelector('.cmplz-accept, [data-cmplz-action="accept"]'),
        has_deny:   !!document.querySelector('.cmplz-deny, [data-cmplz-action="deny"]'),
        cmplz_html_count: (html.match(/cmplz-banner|cmplz-cookiebanner|cmplz-accept|cmplz-deny/gi) || []).length,
        cmplz_scripts_loaded: cmplzScripts,
        window_complianz: typeof window.complianz !== 'undefined',
      };
    });
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
| Local              | banner | accept | deny | scripts | window.complianz | Status |
|--------------------|--------|--------|------|--------:|------------------|--------|
| Blog 1 / (raiz)    | ✅     | ✅     | ✅   |   N     | ✅               | ✅     |
| Blog 2 /cultura/   | ✅     | ✅     | ✅   |   N     | ✅               | ✅     |
| Blog 2 Atlas       | ✅     | ✅     | ✅   |   N     | ✅               | ✅     |
```

🚨 **FAIL multisite** se qualquer dos blogs mostra `banner_visible === false`. Ação: verificar `wp_options.cmplz_options` (blog 1) E `wp_2_options.cmplz_options` (blog 2). Multisite com Complianz Network Active geralmente exige config por subsite.

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

5 gates novos que cobrem incidentes recorrentes desta sessão (2026-05-02): URL de DEV vazando em CSS de prod, uploads em path `/green/` errado, Google Fonts externos, preloader Elementor vazio, banner Complianz não traduzido em `/en/`.

### Snippet — Leak detection composto (rodar 1x em PROD após Fase 7.5)

Combina gates 20–24 numa única passada para reduzir custo (5 checks numa só navegação por página).

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
13. **Paridade prod/dev (Fase 7.5)** — DEV é source-of-truth. Para CADA path do menu descoberto em DEV:
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

14. **Cache health (Fase 7.8) — Object cache drop-in**: `object_cache_dropin.installed === false`.
    Plugin redis-cache pode estar ativo mas drop-in `wp-content/object-cache.php` ausente
    → WP NÃO usa Redis. Reportar comando exato de fix no detalhe.
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
9     Leak detection                   20-24            X      Y

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
