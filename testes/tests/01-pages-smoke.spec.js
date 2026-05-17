'use strict';

const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const { MENU_PAGES, slugify, gotoAndWait, isErrorPage } = require('./helpers');

const today = new Date().toISOString().slice(0, 10);
const screenshotsDir = path.join(__dirname, '..', 'screenshots', `smoke-${today}`);

test.beforeAll(() => {
  fs.mkdirSync(screenshotsDir, { recursive: true });
});

for (const pagePath of MENU_PAGES) {
  test(`smoke: ${pagePath}`, async ({ page }) => {
    const { response, anchor } = await gotoAndWait(page, pagePath);

    // HTTP 200
    expect(response, `Nenhuma resposta HTTP para ${pagePath}`).not.toBeNull();
    expect(response.status(), `HTTP status diferente de 200 em ${pagePath}`).toBe(200);

    // Conteúdo não é página de erro
    const html = await page.content();
    expect(isErrorPage(html), `Página de erro detectada em ${pagePath}`).toBe(false);

    // Título não vazio e não é "Page not found"
    const title = await page.title();
    expect(title, `Título vazio em ${pagePath}`).toBeTruthy();
    expect(title.toLowerCase(), `Título indica 404 em ${pagePath}`).not.toContain('page not found');
    expect(title.toLowerCase(), `Título indica 404 em ${pagePath}`).not.toContain('not found');

    // Verificação suave de anchor
    if (anchor) {
      const anchorEl = page.locator(`#${anchor}`);
      const exists = await anchorEl.count();
      if (exists === 0) {
        console.warn(`[WARN] Anchor #${anchor} não encontrado em ${pagePath}`);
      }
    }

    // Screenshot fullPage
    const slug = slugify(pagePath);
    await page.screenshot({
      path: path.join(screenshotsDir, `${slug}.png`),
      fullPage: true,
    });
  });
}
