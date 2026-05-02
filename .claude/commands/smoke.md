---
description: Bateria smoke completa Concertação em prod vs green — home, atlas PT+EN, espiral, eventos, formularios. Use "/smoke" pós-deploy ou "valida tudo no concertacao".
allowed-tools: mcp__MCP_DOCKER__browser_close mcp__MCP_DOCKER__browser_run_code
disable-model-invocation: true
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

**Páginas 6 e 7 (formulários):**
- Estado **prod**: usar snippet "validação de formulário — PROD" (apenas presença, sem submit)
- Estado **green**: usar snippet "submit real — GREEN" (preenche com marcador, submete, valida resposta visual, retry 1x)

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

### Snippet validação de formulário — PROD (páginas 6 e 7, sem header)

Detecta presença e renderização correta. **NÃO submete** o form em prod.

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
    try {
      const resp = await page.goto(url + '?cb=' + Date.now(), { waitUntil: 'networkidle', timeout: 45000 });
      await page.waitForTimeout(1500);
      return await page.evaluate((status) => {
        const headings = Array.from(document.querySelectorAll('h1, h2, h3'))
          .map(h => h.innerText.trim().slice(0, 80))
          .filter(Boolean);
        const downloadBtns = Array.from(document.querySelectorAll('a, button'))
          .filter(b => /download/i.test(b.innerText || ''))
          .map(b => b.innerText.trim().slice(0, 40));
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
    } catch (e) {
      return { error: (e.message || '?').slice(0, 120), status: 0 };
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
    if (heightDiffPct > 15)          fails.push(`height-${heightDiffPct}%`);
    if (imagesDiffPct > 30)          fails.push(`images-${imagesDiffPct}%`);
    if (Math.abs(sectionsDiff) > 2)  fails.push(`sections-${sectionsDiff}`);

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
    - `height_diff_pct > 15` — altura da página difere mais de 15% (forte indício de seção faltando)
    - `images_diff_pct > 30` — quantidade de imagens renderizadas (naturalWidth ≥100) difere mais de 30%
    - `Math.abs(sections_diff) > 2` — diferença de mais de 2 sections Elementor

    Reportar cada path em FAIL/ERROR com motivo específico. Sumário final: `pass_count / total_paths` e contagem de FAIL vs ERROR.

## Veredicto

✅ **SMOKE PASS** — todas as 5 páginas + 2 formulários verdes prontos para cutover.
🚨 **SMOKE FAIL** — listar gates disparados, sugerir fixes.
