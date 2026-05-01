---
description: Testa Espiral de Conhecimento em prod vs green, sem filtro e com filtro JSF (eixo10/tax=180 = Mudança do Uso do Solo). Atalho Concertação. Use "/espiral" ou "testa espiral".
allowed-tools: mcp__MCP_DOCKER__browser_close mcp__MCP_DOCKER__browser_run_code
disable-model-invocation: true
---

Validar Espiral de Conhecimento (filtro JSF tem histórico de bugs).

URLs:
- Sem filtro: `https://concertacaoamazonia.com.br/conhecimento/espiral-de-conhecimento/`
- Com filtro eixo10 (Mudança do Uso do Solo, tax=180, ~13 estudos): `https://concertacaoamazonia.com.br/conhecimento/espiral-de-conhecimento/?eixo=eixo10&jsf=jet-engine:estudos&tax=eixos:180`

## Step 1 — Fechar contexto
Chame `mcp__MCP_DOCKER__browser_close`.

## Step 2 — Coletar 4 estados (sem filtro × com filtro × prod × green)

Para cada estado, sequencie: `browser_close` → `browser_run_code`:

```js
async (page) => {
  const ctx = page.context();
  await ctx.setExtraHTTPHeaders(HEADER_VAL); // {} ou {'X-Test-Green':'true'}
  await ctx.clearCookies();

  await page.goto('URL_AQUI&cb=' + Date.now(), { waitUntil: 'domcontentloaded', timeout: 30000 });

  return await page.evaluate(async () => {
    const r = await fetch('/check-ec2.php?cb=' + Date.now(), { cache: 'no-store' });
    const text = await r.text();
    const hostname = (text.match(/Hostname:\s*([^\n<]+)/) || [])[1]?.trim() || 'unknown';

    const studyLinks = Array.from(document.querySelectorAll('a[href*="/estudos/"]'))
      .map(a => a.getAttribute('href').match(/\/estudos\/([a-z0-9-]+)\//))
      .filter(m => m && m[1] !== 'energia-as-amazonias-na-agenda-de-transicao')
      .map(m => m[1]);
    const uniqueStudies = [...new Set(studyLinks)];

    const filterActive = (document.querySelector('.jet-active-filters, .jet-filters-tag')?.textContent || '').trim();

    return {
      hostname,
      url: location.href,
      page_title: document.title,
      unique_studies: uniqueStudies.length,
      first_5_studies: uniqueStudies.slice(0, 5),
      filter_active_text: filterActive,
      listing_items: document.querySelectorAll('.jet-listing-grid__item').length,
    };
  });
}
```

## Step 3 — Tabela 4-quadrante

```
| Estado            | Hostname  | studies | items | filtro_ativo | Status |
|-------------------|-----------|--------:|------:|--------------|--------|
| PROD sem filtro   | blue...   |     12+ |    12 | (vazio)      |        |
| PROD com filtro   | blue...   |      13 |    12 | "Mudança Uso Solo" | esperado |
| GREEN sem filtro  | hml...    |     12+ |    12 | (vazio)      |        |
| GREEN com filtro  | hml...    |      13 |    12 | "Mudança Uso Solo" | ✅ |
```

## Step 4 — Veredicto

🚨 **FAIL** se:
- GREEN com filtro retorna `unique_studies < 5` ou os mesmos do "sem filtro" (filtro não aplicou)
- `filter_active_text` vazio quando filtro foi passado na URL
- GREEN tem mais de 14 studies (filtro ignorado)

✅ **PASS** se filtro aplica em ambos prod e green com lista coerente do eixo "Mudança do Uso do Solo".

Termine com 1 linha de status.
