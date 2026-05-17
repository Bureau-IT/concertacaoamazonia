'use strict';

const { test, expect } = require('@playwright/test');
const { MENU_PAGES, GTM_ID, gotoAndWait } = require('./helpers');

for (const pagePath of MENU_PAGES) {
  test(`gtm presente: ${pagePath}`, async ({ page }) => {
    let gtmRequestFired = false;

    page.on('request', (req) => {
      const url = req.url();
      if (url.includes('googletagmanager.com') && url.includes(GTM_ID)) {
        gtmRequestFired = true;
      }
    });

    await gotoAndWait(page, pagePath);

    // Aguarda requisições assíncronas
    await page.waitForTimeout(1500);

    // Verificação no DOM (HTML cacheado pode ter GTM inline)
    const gtmInDOM = await page.evaluate(
      (id) => document.documentElement.innerHTML.includes(id),
      GTM_ID
    );

    // OR robusto: GTM no DOM ou requisição disparada
    expect(
      gtmInDOM || gtmRequestFired,
      `GTM ${GTM_ID} não encontrado em ${pagePath} (nem no DOM nem em requisição de rede)`
    ).toBe(true);
  });
}
