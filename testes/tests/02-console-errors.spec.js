'use strict';

const { test, expect } = require('@playwright/test');
const { MENU_PAGES, CONSOLE_NOISE_PATTERNS, gotoAndWait } = require('./helpers');

for (const pagePath of MENU_PAGES) {
  test(`console errors: ${pagePath}`, async ({ page }) => {
    const errors = [];

    page.on('console', (msg) => {
      if (msg.type() !== 'error') return;
      const text = msg.text();
      const isNoise = CONSOLE_NOISE_PATTERNS.some((re) => re.test(text));
      if (!isNoise) {
        errors.push(`[console.error] ${text}`);
      }
    });

    page.on('pageerror', (err) => {
      const text = err.message || String(err);
      const isNoise = CONSOLE_NOISE_PATTERNS.some((re) => re.test(text));
      if (!isNoise) {
        errors.push(`[pageerror] ${text}`);
      }
    });

    await gotoAndWait(page, pagePath);

    // Aguarda scripts assíncronos dispararem
    await page.waitForTimeout(2000);

    expect(
      errors,
      `Erros de console em ${pagePath}:\n${errors.join('\n')}`
    ).toHaveLength(0);
  });
}
