'use strict';

const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const { MENU_PAGES, MENU_PAGES_EN, slugify, gotoAndWait } = require('./helpers');

const today = new Date().toISOString().slice(0, 10);
const screenshotsDir = path.join(__dirname, '..', 'screenshots', `wpml-en-${today}`);

// Palavras exclusivamente portuguesas para heurística de título
const PT_ONLY_WORDS = ['sobre', 'atuação', 'atuacao', 'conhecimento', 'publicações', 'publicacoes', 'cultura', 'contato'];

test.beforeAll(() => {
  fs.mkdirSync(screenshotsDir, { recursive: true });
});

test.describe('WPML EN — páginas em inglês', () => {
  for (const pagePath of MENU_PAGES_EN) {
    test(`en page: ${pagePath}`, async ({ page }) => {
      const { response, anchor } = await gotoAndWait(page, pagePath);

      // HTTP 200
      expect(response, `Nenhuma resposta para ${pagePath}`).not.toBeNull();
      expect(response.status(), `HTTP ${response.status()} em ${pagePath}`).toBe(200);

      // lang="en-US" no elemento html raiz
      const lang = await page.getAttribute('html', 'lang');
      expect(
        lang,
        `Atributo lang="${lang}" incorreto em ${pagePath} — esperado "en-US"`
      ).toMatch(/^en/i);

      // hreflang PT presente (WPML expõe)
      const hreflangPT = await page.evaluate(() => {
        const links = Array.from(document.querySelectorAll('link[rel="alternate"][hreflang]'));
        return links.some((l) => /pt/i.test(l.getAttribute('hreflang') || ''));
      });
      expect(
        hreflangPT,
        `hreflang PT ausente em ${pagePath} — verificar configuração WPML → Languages → SEO`
      ).toBe(true);

      // Heurística: título não contém palavras PT exclusivas (soft check)
      const title = (await page.title()).toLowerCase();
      const ptWordFound = PT_ONLY_WORDS.find((w) => title.includes(w));
      if (ptWordFound) {
        console.warn(`[WARN] Título "${title}" pode estar em português (palavra: "${ptWordFound}") em ${pagePath}`);
      }

      // Screenshot fullPage
      const slug = slugify(pagePath);
      await page.screenshot({
        path: path.join(screenshotsDir, `${slug}.png`),
        fullPage: true,
      });

      // Anchor suave
      if (anchor) {
        const anchorEl = page.locator(`#${anchor}`);
        if (await anchorEl.count() === 0) {
          console.warn(`[WARN] Anchor #${anchor} não encontrado em ${pagePath}`);
        }
      }
    });
  }
});

test.describe('PT pages have EN switcher link', () => {
  for (const pagePath of MENU_PAGES) {
    // Pula páginas com # (não têm URL própria para verificar switcher)
    if (pagePath.includes('#')) continue;

    test(`switcher EN presente: ${pagePath}`, async ({ page }) => {
      await gotoAndWait(page, pagePath);

      // Verifica link de language switcher apontando para EN
      const hasEnLink = await page.evaluate(() => {
        const links = Array.from(document.querySelectorAll('a[hreflang="en"], a[href*="/en/"]'));
        return links.length > 0;
      });

      // Verifica também hreflang alternate no head
      const hasHreflangEN = await page.evaluate(() => {
        const links = Array.from(document.querySelectorAll('link[rel="alternate"][hreflang]'));
        return links.some((l) => /^en/i.test(l.getAttribute('hreflang') || ''));
      });

      expect(
        hasEnLink || hasHreflangEN,
        `Switcher de idioma EN ausente em ${pagePath} — verificar WPML ou tradução da página`
      ).toBe(true);
    });
  }
});
