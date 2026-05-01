---
description: Testa Atlas Cultural (PT + EN, blog 2 /cultura/) em prod vs green. Atalho do Concertação Amazônia. Use quando o usuário disser "/atlas", "testa atlas", "valida atlas cultural".
allowed-tools: mcp__MCP_DOCKER__browser_close mcp__MCP_DOCKER__browser_run_code
disable-model-invocation: true
---

Validar Atlas Cultural das Amazônias em ambos os idiomas, ambos os ambientes.

URLs:
- PT: `https://concertacaoamazonia.com.br/cultura/atlas-cultural-das-amazonias/`
- EN: `https://concertacaoamazonia.com.br/cultura/en/cultural-atlas-of-the-amazon/`

## Step 1 — Fechar contexto
Chame `mcp__MCP_DOCKER__browser_close`.

## Step 2 — Coletar 4 estados (PROD-PT, PROD-EN, GREEN-PT, GREEN-EN)

Para cada estado, sequencie: `browser_close` → `browser_run_code` com este snippet (substituindo `URL_AQUI` e `HEADER_VAL`):

```js
async (page) => {
  const ctx = page.context();
  await ctx.setExtraHTTPHeaders(HEADER_VAL); // {} para prod, {'X-Test-Green':'true'} para green
  await ctx.clearCookies();

  await page.goto('URL_AQUI?cb=' + Date.now(), { waitUntil: 'domcontentloaded', timeout: 30000 });

  return await page.evaluate(async () => {
    const r = await fetch('/check-ec2.php?cb=' + Date.now(), { cache: 'no-store' });
    const text = await r.text();
    const hostname = (text.match(/Hostname:\s*([^\n<]+)/) || [])[1]?.trim() || 'unknown';

    const artistaMapa = window.JetSmartFilterSettings?.props?.['jet-engine']?.['artista-mapa']?.found_posts;
    const mapLista = window.JetSmartFilterSettings?.props?.['jet-engine-maps']?.['map-lista']?.found_posts;

    return {
      hostname,
      page_title: document.title,
      artista_mapa_found_posts: artistaMapa,
      map_lista_found_posts: mapLista,
      listing_items: document.querySelectorAll('.jet-listing-grid__item').length,
      mostrando: (document.body.innerText.match(/(?:Mostrando|Showing)[^.]*\d+/) || [])[0] || null,
    };
  });
}
```

## Step 3 — Apresentar tabela 4-quadrante

```
| Estado    | Hostname           | found_posts | items | "Mostrando" | Status |
|-----------|--------------------|-----------:|------:|-------------|--------|
| PROD-PT   | auto-blueprod-...  |          1 |     1 | Mostrando 1-1 de 1 | (estado atual público) |
| PROD-EN   | auto-blueprod-...  |          1 |     1 | Mostrando 1-1 de 1 |        |
| GREEN-PT  | autoconcert...-hml |        656 |     4 | Mostrando 1-4 de 656 | ✅ |
| GREEN-EN  | autoconcert...-hml |        656 |     4 | Mostrando 1-4 de 656 | ✅ |
```

## Step 4 — Veredicto

🚨 **FAIL** se:
- Algum estado GREEN tiver `found_posts < 100` (Atlas tem ~656 artistas; <100 indica query quebrada)
- Algum estado GREEN tiver `hostname` que não contém `hml`
- PT e EN com `found_posts` muito divergentes na green (deveria ser idêntico após WPML)

✅ **PASS** se prod mostra 1 (bug conhecido) e green mostra 656 nos 2 idiomas.

Termine com 1 linha de status.
