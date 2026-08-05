'use strict';

/**
 * Smoke test da página de Estudos (Publicações) — Concertação.
 *
 * Cobre a listagem JetEngine de estudos em /conhecimento/publicacoes/ (PT)
 * e /en/knowledge/publications/ (EN): carga do grid, contagem de cards,
 * paginação JSF, integridade dos cards (título + link para single /estudos/),
 * e um single de estudo. Também valida ausência de erros JS bloqueadores.
 *
 * Uso:
 *   BASE_URL=https://concertacao.bureau-it.com npx playwright test 10-estudos-publicacoes.spec.js
 *   BASE_URL=https://cambrasmax.local:8484     npx playwright test 10-estudos-publicacoes.spec.js
 *
 * Autor: Daniel Cambría — Bureau de Tecnologia
 */

const { test, expect } = require('@playwright/test');

const BASE = process.env.BASE_URL || 'https://concertacao.bureau-it.com';

test.use({ viewport: { width: 1440, height: 900 }, ignoreHTTPSErrors: true });

// Páginas de listagem de estudos (PT e EN). WPML: EN usa prefixo /en/ + slugs traduzidos.
const LISTINGS = [
  { name: 'publicacoes-pt',  path: '/conhecimento/publicacoes/',     single: '/estudos/' },
  { name: 'publications-en', path: '/en/knowledge/publications/',    single: '/estudos/' },
];

// Erros de rede que NÃO devem bloquear (cache min stale conhecido em dev)
const IGNORABLE = [/\/cache\/min\//, /language-switchers/, /elementor-cache/, /jquery-migrate/];

async function gotoListing(page, url) {
  // domcontentloaded (networkidle nunca assenta nesta página) + espera explícita do grid
  await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 45000 });
  await page.locator('.jet-listing-grid .jet-listing-grid__item').first().waitFor({ state: 'visible', timeout: 20000 });
}

for (const cfg of LISTINGS) {
  test.describe(`Estudos · ${cfg.name}`, () => {

    test(`${cfg.name}: grid carrega com cards e sem erro JS bloqueador`, async ({ page }) => {
      const jsErrors = [];
      page.on('console', (m) => {
        if (m.type() !== 'error') return;
        const t = m.text();
        if (IGNORABLE.some((re) => re.test(t))) return;
        if (/Uncaught|TypeError|is not a function|is not defined/.test(t)) jsErrors.push(t);
      });

      const resp = await page.goto(BASE + cfg.path, { waitUntil: 'domcontentloaded', timeout: 45000 });
      expect(resp.status(), 'HTTP status da página').toBeLessThan(400);

      // Gate 1: listing grid JetEngine presente e visível
      const grid = page.locator('.jet-listing-grid').first();
      await expect(grid, 'grid JetEngine presente').toBeVisible({ timeout: 20000 });

      // Gate 2: cards de estudo carregados (página inicial = 12)
      const cards = grid.locator('.jet-listing-grid__item');
      await cards.first().waitFor({ state: 'visible', timeout: 20000 });
      const count = await cards.count();
      expect(count, 'cards na primeira página').toBeGreaterThanOrEqual(6);

      // Gate 3: primeiro card tem título não-vazio
      const firstTitle = (await cards.first().innerText()).trim();
      expect(firstTitle.length, 'primeiro card tem texto').toBeGreaterThan(3);

      // Gate 4: pelo menos um link para single de estudo
      const studyLinks = await page.locator(`a[href*="${cfg.single}"]`).count();
      expect(studyLinks, 'links para single de estudo').toBeGreaterThan(0);

      // Gate 5: sem erro JS bloqueador
      expect(jsErrors, `erros JS bloqueadores: ${jsErrors.join(' | ')}`).toEqual([]);
    });

    test(`${cfg.name}: paginação navega e troca os cards`, async ({ page }) => {
      await gotoListing(page, BASE + cfg.path);
      const grid = page.locator('.jet-listing-grid').first();
      const firstBefore = (await grid.locator('.jet-listing-grid__item').first().innerText()).trim();

      // controle de paginação: link "2" da paginação JetEngine (ou próxima)
      const nextPage = page.locator(
        '.jet-filters-pagination__link, .jet-pagination a, .jet-listing-grid__loadmore, .jet-smart-filters-pagination a'
      ).filter({ hasText: /^(2|»|›|Próx|Next|Carregar|Load)/i }).first();

      const hasPag = await nextPage.count();
      test.skip(hasPag === 0, 'sem controle de paginação nesta página');

      await nextPage.scrollIntoViewIfNeeded();
      await nextPage.click();

      // aguardar troca via AJAX: o primeiro card muda
      await expect(async () => {
        const firstAfter = (await grid.locator('.jet-listing-grid__item').first().innerText()).trim();
        expect(firstAfter).not.toBe(firstBefore);
      }).toPass({ timeout: 12000 });
    });
  });
}

test('single de estudo abre e tem conteúdo', async ({ page }) => {
  await gotoListing(page, BASE + '/conhecimento/publicacoes/');

  // pegar href do primeiro estudo (normalizar para o host de teste)
  const raw = await page.locator('a[href*="/estudos/"]').first().getAttribute('href');
  const url = new URL(raw, BASE);
  url.protocol = new URL(BASE).protocol;
  url.host = new URL(BASE).host;

  const resp = await page.goto(url.toString(), { waitUntil: 'domcontentloaded', timeout: 45000 });
  expect(resp.status(), 'HTTP single de estudo').toBeLessThan(400);

  const h1 = page.locator('h1').first();
  await expect(h1).toBeVisible({ timeout: 15000 });
  expect((await h1.innerText()).trim().length, 'título do estudo').toBeGreaterThan(3);
  expect(page.url(), 'URL de single estudo').toContain('/estudos/');
});
