---
description: Bateria smoke completa Concertação em prod vs green — home, atlas PT+EN, espiral, eventos. Use "/smoke" pós-deploy ou "valida tudo no concertacao".
allowed-tools: mcp__MCP_DOCKER__browser_close mcp__MCP_DOCKER__browser_run_code
disable-model-invocation: true
---

Bateria smoke pós-deploy. Testa 5 páginas críticas em prod e green:

| # | Página | URL |
|---|--------|-----|
| 1 | Home | `https://concertacaoamazonia.com.br/` |
| 2 | Atlas PT | `https://concertacaoamazonia.com.br/cultura/atlas-cultural-das-amazonias/` |
| 3 | Atlas EN | `https://concertacaoamazonia.com.br/cultura/en/cultural-atlas-of-the-amazon/` |
| 4 | Espiral | `https://concertacaoamazonia.com.br/conhecimento/espiral-de-conhecimento/` |
| 5 | Eventos | `https://concertacaoamazonia.com.br/eventos-calendario/` |

## Workflow

Para cada página, faça:
1. `browser_close`
2. `browser_run_code` com **prod** (sem header)
3. `browser_close`
4. `browser_run_code` com **green** (X-Test-Green:true via context)

Snippet base por estado:
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
      jet_max_found_posts: Math.max(0, ...allFoundPosts.map(o => o.v || 0)),
      listing_items: document.querySelectorAll('.jet-listing-grid__item').length,
    };
  });
}
```

## Apresentar matriz

```
| Página      | PROD hostname | PROD items | PROD jet_max | GREEN hostname | GREEN items | GREEN jet_max | Status |
|-------------|---------------|-----------:|-------------:|----------------|------------:|--------------:|--------|
| Home        | blue          |        N   |          N   | hml            |         N   |          N    | ✅     |
| Atlas PT    | blue          |        1   |          1   | hml            |         4   |        656    | ✅     |
| Atlas EN    | blue          |        1   |          1   | hml            |         4   |        656    | ✅     |
| Espiral     | blue          |       12   |         12   | hml            |        12   |         12    | ✅     |
| Eventos     | blue          |        N   |          N   | hml            |         N   |          N    | ✅     |
```

## Gates de FAIL (qualquer um falha o smoke)

🚨 **FAIL** se:
1. Atlas (PT ou EN) GREEN: `jet_max_found_posts < 100`
2. Qualquer página GREEN: `uploads_elementor_css > 0` (mu-plugin v2 deve garantir 0)
3. Qualquer página GREEN: `hostname` sem `hml`
4. Qualquer página GREEN: `listing_items === 0` quando `jet_max_found_posts > 0`
5. Console errors com `ERR_CONNECTION_CLOSED` em qualquer página

## Veredicto

✅ **SMOKE PASS** — todas as 5 páginas verdes prontas para cutover.
🚨 **SMOKE FAIL** — listar gates disparados, sugerir fixes.
