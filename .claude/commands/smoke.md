---
description: Bateria smoke completa Concertação em prod vs green — home, atlas PT+EN, espiral, eventos, formularios. Use "/smoke" pós-deploy ou "valida tudo no concertacao".
allowed-tools: mcp__MCP_DOCKER__browser_close mcp__MCP_DOCKER__browser_run_code
disable-model-invocation: true
---

Bateria smoke pós-deploy. Testa 5 páginas críticas + 2 formulários em prod e green:

| # | Página | URL |
|---|--------|-----|
| 1 | Home | `https://concertacaoamazonia.com.br/` |
| 2 | Atlas PT | `https://concertacaoamazonia.com.br/cultura/atlas-cultural-das-amazonias/` |
| 3 | Atlas EN | `https://concertacaoamazonia.com.br/cultura/en/cultural-atlas-of-the-amazon/` |
| 4 | Espiral | `https://concertacaoamazonia.com.br/conhecimento/espiral-de-conhecimento/` |
| 5 | Eventos | `https://concertacaoamazonia.com.br/eventos-calendario/` |
| 6 | **Newsletter footer (na home)** | seletor `form[name=Newsletter]` ou `<form>` com `<input placeholder*="email">` no footer |
| 7 | **Contato** | `https://concertacaoamazonia.com.br/contato/` |

## Workflow

Para cada página, faça:
1. `browser_close`
2. `browser_run_code` com **prod** (sem header)
3. `browser_close`
4. `browser_run_code` com **green** (X-Test-Green:true via context)

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

### Snippet validação de formulário (páginas 6 e 7)

Detecta presença e renderização correta. **NÃO submete** o form.

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
| Página              | PROD form_count | PROD fields | PROD submit | GREEN form_count | GREEN fields | GREEN submit | Status |
|---------------------|----------------:|------------:|-------------|-----------------:|-------------:|--------------|--------|
| Newsletter (home)   |             1   |   2 (email, Região) | "ENVIAR" |               1  |    2          | "ENVIAR"     | ✅     |
| Contato             |             1   |   N (nome, email, mensagem...) | "ENVIAR" |  1  |    N          | "ENVIAR"     | ✅     |
```

## Gates de FAIL (qualquer um falha o smoke)

🚨 **FAIL** se:
1. Atlas (PT ou EN) GREEN: `jet_max_found_posts < 100`
2. Qualquer página GREEN: `uploads_elementor_css > 0` (mu-plugin v2 deve garantir 0)
3. Qualquer página GREEN: 404s em `/elementor-cache/2026/*.jpg|jpeg|png|webp` (bug v2.0.4 — fix em v2.0.5)
4. Qualquer página GREEN: `hostname` sem `hml`
5. Qualquer página GREEN: `listing_items === 0` quando `jet_max_found_posts > 0`
6. Console errors com `ERR_CONNECTION_CLOSED` em qualquer página
7. **Form Newsletter**: `form_count === 0` (form sumiu) OU `fields_count < 2` (campos perdidos) OU `submit_label !== "ENVIAR"`
8. **Form Contato**: `form_count === 0` OU `fields_count < 3` (deve ter pelo menos nome, email, mensagem) OU `submit_visible === false`

## Veredicto

✅ **SMOKE PASS** — todas as 5 páginas + 2 formulários verdes prontos para cutover.
🚨 **SMOKE FAIL** — listar gates disparados, sugerir fixes.
