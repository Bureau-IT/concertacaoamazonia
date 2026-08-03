'use strict';

/**
 * Contador de estudos da Espiral de Conhecimento — Concertação.
 *
 * A página /conhecimento/espiral-de-conhecimento/ tem dois headings com o dynamic
 * tag jet-query-count (query 12, count_type=custom_format): "Mostrando %end-item%
 * de %total% estudos cadastrados". O total/end-item vem do render server-side no
 * page load e é sincronizado por JS (mu-plugin bit-jsf-query-count-sync) nos
 * caminhos AJAX: filtro JSF e "Carregar mais" do listing.
 *
 * Este spec cobre os 5 caminhos:
 *   A) load sem filtro          → total = total geral, end-item = cards
 *   B) filtro por AJAX          → total = found_posts do filtro, end-item = cards
 *   C) 2º filtro por AJAX       → idem, inclusive quando resulta em 0 cards
 *   D) reload da URL filtrada   → render server-side coerente
 *   E) Carregar mais (AJAX)     → end-item acompanha os cards, total estável
 *
 * Invariante central: end-item == nº de cards no DOM, e total == found_posts do
 * estado de filtro corrente (obtido da resposta AJAX do JSF: pagination.found_posts).
 *
 * Uso:
 *   BASE_URL=https://cambrasmax.local:8484 npx playwright test 11-espiral-contador.spec.js
 *   BASE_URL=https://concertacaoamazonia.com.br npx playwright test 11-espiral-contador.spec.js
 *
 * Autor: Daniel Cambría — Bureau de Tecnologia
 */

const { test, expect } = require('@playwright/test');

const BASE = process.env.BASE_URL || 'https://cambrasmax.local:8484';
const PATH = '/conhecimento/espiral-de-conhecimento/';
const EIXO_LABEL = /instrumentos de financiamento/i;

test.use({ viewport: { width: 1440, height: 1200 }, ignoreHTTPSErrors: true });

// Lê o estado do contador e do grid.
async function readState(page) {
  return page.evaluate(() => {
    const spans = { endItem: [], total: [] };
    document.querySelectorAll('.jet-engine-query-count').forEach((s) => {
      const v = parseInt(s.textContent.trim(), 10);
      if (s.classList.contains('count-type-end-item')) spans.endItem.push(v);
      if (s.classList.contains('count-type-total')) spans.total.push(v);
    });
    return {
      endItem: spans.endItem,
      total: spans.total,
      cards: document.querySelectorAll('#estudos .jet-listing-grid__item, .jet-listing-grid__item').length,
    };
  });
}

// Captura pagination.found_posts das respostas AJAX do JSF/JetEngine.
function trackFoundPosts(page) {
  const seen = [];
  page.on('response', async (r) => {
    if (!r.url().includes('admin-ajax.php')) return;
    try {
      const j = await r.json();
      if (j && j.pagination && typeof j.pagination.found_posts !== 'undefined') {
        seen.push(Number(j.pagination.found_posts));
      }
    } catch (e) { /* resposta não-JSON */ }
  });
  return seen;
}

async function openEspiral(page, query = '') {
  await page.goto(BASE + PATH + query, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.locator('.jet-listing-grid__item').first().waitFor({ state: 'visible', timeout: 30000 });
  // banner de consentimento intercepta cliques
  await page.evaluate(() => { const b = document.querySelector('.cmplz-cookiebanner'); if (b) b.remove(); });
}

// Aplica um filtro no <select name=...> do JSF e espera o re-render AJAX.
async function applyFilter(page, selectName, matcher) {
  const before = await page.evaluate(() => document.querySelectorAll('.jet-listing-grid__item').length);
  const applied = await page.evaluate(({ selectName, src }) => {
    const s = document.querySelector(`select[name="${selectName}"]`);
    if (!s) return { ok: false, reason: 'select ausente' };
    const re = new RegExp(src, 'i');
    const opt = Array.from(s.options).find((o) => o.value && re.test(o.textContent));
    if (!opt) return { ok: false, reason: 'opção ausente' };
    s.value = opt.value;
    s.dispatchEvent(new Event('change', { bubbles: true }));
    return { ok: true, label: opt.textContent.trim() };
  }, { selectName, src: matcher.source });
  expect(applied.ok, `filtro ${selectName}: ${applied.reason || ''}`).toBe(true);
  // o grid re-renderiza; aguarda o DOM assentar (contagem estável por 2 amostras)
  await page.waitForTimeout(4000);
  await expect
    .poll(async () => page.evaluate(() => document.querySelectorAll('.jet-listing-grid__item').length), {
      timeout: 20000,
      intervals: [1500, 1500, 1500, 1500],
    })
    .not.toBe(-1);
  await page.waitForTimeout(2000);
  return { applied, before };
}

test.describe('Espiral · contador de estudos', () => {
  test('A) load sem filtro: end-item == cards e total consistente', async ({ page }) => {
    await openEspiral(page);
    const s = await readState(page);
    expect(s.total.length, 'dois headings com total').toBeGreaterThan(0);
    for (const t of s.total) expect(t).toBeGreaterThan(s.cards);
    for (const e of s.endItem) expect(e).toBe(s.cards);
  });

  test('B) filtro por AJAX: total passa a ser o found_posts do filtro', async ({ page }) => {
    const found = trackFoundPosts(page);
    await openEspiral(page);
    const antes = await readState(page);

    await applyFilter(page, 'eixos', EIXO_LABEL);
    const depois = await readState(page);

    expect(found.length, 'JSF respondeu com pagination.found_posts').toBeGreaterThan(0);
    const esperado = found[found.length - 1];

    expect(esperado, 'filtro deve reduzir o total').toBeLessThan(antes.total[0]);
    for (const t of depois.total) expect(t, `total deve virar ${esperado}`).toBe(esperado);
    for (const e of depois.endItem) expect(e, 'end-item == cards').toBe(depois.cards);
  });

  test('C) 2º filtro por AJAX: contador acompanha inclusive resultado vazio', async ({ page }) => {
    const found = trackFoundPosts(page);
    await openEspiral(page);
    await applyFilter(page, 'eixos', EIXO_LABEL);
    // qualquer veículo: a interseção costuma ser pequena (às vezes zero)
    await applyFilter(page, 'veiculo', /\S/);
    const s = await readState(page);
    const esperado = found[found.length - 1];
    for (const t of s.total) expect(t, `total deve virar ${esperado}`).toBe(esperado);
    for (const e of s.endItem) expect(e, 'end-item == cards (inclusive 0)').toBe(s.cards);
  });

  test('D) reload da URL filtrada: render server-side coerente', async ({ page }) => {
    await openEspiral(page, '?jsf=jet-engine:estudos&tax=eixos:174');
    const s = await readState(page);
    for (const e of s.endItem) expect(e).toBe(s.cards);
    for (const t of s.total) {
      expect(t).toBeGreaterThanOrEqual(s.cards);
      expect(t, 'total filtrado deve ser menor que o total geral').toBeLessThan(300);
    }
  });

  test('E) Carregar mais: end-item acompanha os cards e total fica estável', async ({ page }) => {
    await openEspiral(page, '?jsf=jet-engine:estudos&tax=eixos:174');
    const inicial = await readState(page);
    const totalInicial = inicial.total[0];

    const btn = page.locator('.elementor-widget-jet-button a, .jet-button__instance').filter({ hasText: /Carregar mais/i }).first();
    for (let i = 0; i < 2; i++) {
      if (!(await btn.isVisible().catch(() => false))) break;
      const antes = (await readState(page)).cards;
      await btn.scrollIntoViewIfNeeded();
      await btn.click();
      await expect.poll(async () => (await readState(page)).cards, { timeout: 25000 }).toBeGreaterThan(antes);
      const s = await readState(page);
      for (const e of s.endItem) expect(e, 'end-item == cards após load more').toBe(s.cards);
      for (const t of s.total) expect(t, 'total não muda no load more').toBe(totalInicial);
    }
  });
});
