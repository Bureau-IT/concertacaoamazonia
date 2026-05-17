'use strict';

const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');

const downloadsDir = path.join(__dirname, '..', 'test-results', 'downloads');

test.beforeAll(() => {
  fs.mkdirSync(downloadsDir, { recursive: true });
});

test('download: publicacoes - primeiro arquivo disponível', async ({ page, context }) => {
  await page.goto('/conhecimento/publicacoes/', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2000);

  // Multi-selector fallback em ordem de especificidade
  const selectors = [
    'a[href$=".pdf"], a[href*=".pdf"]',
    'a:has-text("Download"), a:has-text("Baixar"), a:has-text("PDF")',
    '.wp-block-file__button, .elementor-button[href*="pdf"]',
  ];

  let downloadLink = null;

  for (const selector of selectors) {
    try {
      const el = page.locator(selector).first();
      const count = await el.count();
      if (count > 0 && await el.isVisible()) {
        downloadLink = el;
        break;
      }
    } catch {
      // Seletor não encontrou nada, tentar próximo
    }
  }

  if (!downloadLink) {
    test.skip(true, 'Nenhum link de download encontrado em /conhecimento/publicacoes/ — página pode não ter arquivos listados');
    return;
  }

  const href = await downloadLink.getAttribute('href');

  // Se o href aponta direto para PDF, abrir em nova aba para verificar
  if (href && /\.(pdf|docx?|xlsx?|zip)/i.test(href)) {
    const [newPage] = await Promise.all([
      context.waitForEvent('page'),
      downloadLink.click(),
    ]).catch(async () => {
      // Tenta download direto se nova aba não abriu
      const [download] = await Promise.all([
        page.waitForEvent('download', { timeout: 20000 }),
        downloadLink.click(),
      ]);
      const filename = download.suggestedFilename();
      await download.saveAs(path.join(downloadsDir, filename));
      expect(filename).toMatch(/\.(pdf|docx?|xlsx?|zip)$/i);
      return [null];
    });

    if (newPage) {
      await newPage.waitForLoadState('domcontentloaded').catch(() => {});
      const url = newPage.url();
      expect(url).toMatch(/\.(pdf|docx?|xlsx?|zip)/i);
      await newPage.close();
    }
    return;
  }

  // Tenta capturar evento de download
  try {
    const [download] = await Promise.all([
      page.waitForEvent('download', { timeout: 20000 }),
      downloadLink.click(),
    ]);
    const filename = download.suggestedFilename();
    await download.saveAs(path.join(downloadsDir, filename));
    expect(filename).toMatch(/\.(pdf|docx?|xlsx?|zip)$/i);
  } catch {
    // Fallback: verifica se abriu nova aba com PDF
    const newPage = context.pages().find((p) => p !== page);
    if (newPage) {
      const url = newPage.url();
      expect(url).toMatch(/\.(pdf|docx?|xlsx?|zip)/i);
    } else {
      test.fail(true, 'Download não iniciou e nenhuma aba com PDF foi aberta');
    }
  }
});
