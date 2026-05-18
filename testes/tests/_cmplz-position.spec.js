'use strict';

/**
 * Test rápido: posição do banner Complianz na green.
 * Esperado: bottom-right (não centralizado).
 *
 * Uso: BASE_URL=https://concertacaoamazonia.com.br npx playwright test _cmplz-position.spec.js --reporter=list
 */

const { test, expect } = require('@playwright/test');

test.use({
  viewport: { width: 1440, height: 900 },
});

test.beforeEach(async ({ page }) => {
  const baseUrl = new URL(process.env.BASE_URL || 'https://concertacaoamazonia.com.br');
  await page.route('**/*', async (route) => {
    const req = route.request();
    const reqUrl = new URL(req.url());
    if (reqUrl.hostname !== baseUrl.hostname) return route.continue();
    const headers = { ...req.headers(), 'x-test-green': 'true' };
    await route.continue({ headers });
  });
});

const PAGES = [
  { name: 'home', path: '/' },
  { name: 'cultura', path: '/cultura/' },
];

for (const { name, path: urlPath } of PAGES) {
  test(`cmplz banner position: ${name}`, async ({ page }, testInfo) => {
    const url = `${process.env.BASE_URL || 'https://concertacaoamazonia.com.br'}${urlPath}?cb=${Date.now()}`;
    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 45000 });
    // Aguarda banner aparecer
    await page.waitForSelector('#cmplz-cookiebanner-container, .cmplz-cookiebanner', { timeout: 15000 }).catch(() => {});
    await page.waitForTimeout(2000);

    const result = await page.evaluate(() => {
      const banner = document.querySelector('.cmplz-cookiebanner') || document.querySelector('#cmplz-cookiebanner-container .cmplz-cookiebanner');
      if (!banner) return { found: false };
      const r = banner.getBoundingClientRect();
      const cs = window.getComputedStyle(banner);
      const vw = window.innerWidth;
      const vh = window.innerHeight;
      // Encontrar classes que indicam posição
      const positionClasses = Array.from(banner.classList).filter(c => c.includes('center') || c.includes('bottom') || c.includes('top') || c.includes('left') || c.includes('right'));
      return {
        found: true,
        viewport: { width: vw, height: vh },
        rect: { left: r.left, top: r.top, right: r.right, bottom: r.bottom, width: r.width, height: r.height },
        center_x: r.left + r.width / 2,
        center_y: r.top + r.height / 2,
        vp_center_x: vw / 2,
        vp_center_y: vh / 2,
        classes: positionClasses,
        position_style: { position: cs.position, top: cs.top, right: cs.right, bottom: cs.bottom, left: cs.left },
        // Verifica se há link Aviso de Privacidade
        avisoLinks: Array.from(banner.querySelectorAll('a'))
          .filter(a => a.textContent.includes('Aviso'))
          .map(a => ({ text: a.textContent.trim(), href: a.href })),
      };
    });

    console.log(`\n=== ${name} (${urlPath}) ===`);
    console.log(JSON.stringify(result, null, 2));

    expect(result.found, `Banner Complianz não encontrado em ${urlPath}`).toBe(true);

    // Screenshot fullPage
    await page.screenshot({ path: `screenshots/cmplz-${name}.png`, fullPage: false });

    // Validações de posição
    if (result.found) {
      const { rect, center_x, center_y, vp_center_x, vp_center_y } = result;

      // Tolerância: banner está "centralizado" se o centro X dele está perto do centro X do viewport (±150px)
      const isHorizCentered = Math.abs(center_x - vp_center_x) < 150;
      const isVertCentered = Math.abs(center_y - vp_center_y) < 150;

      // Banner deveria estar bottom-right: rect.right perto do viewport.right (vw - rect.right < 100)
      const isBottomRight = (result.viewport.width - rect.right < 100) && (result.viewport.height - rect.bottom < 200);

      testInfo.annotations.push({ type: 'isHorizCentered', description: String(isHorizCentered) });
      testInfo.annotations.push({ type: 'isVertCentered', description: String(isVertCentered) });
      testInfo.annotations.push({ type: 'isBottomRight', description: String(isBottomRight) });
      testInfo.annotations.push({ type: 'avisoLinkCount', description: String(result.avisoLinks.length) });

      if (isHorizCentered && isVertCentered) {
        throw new Error(`FAIL: Banner centralizado (esperado bottom-right). center=(${Math.round(center_x)},${Math.round(center_y)}) vp_center=(${Math.round(vp_center_x)},${Math.round(vp_center_y)})`);
      }
      if (!isBottomRight) {
        throw new Error(`FAIL: Banner NÃO está bottom-right. rect.right=${Math.round(rect.right)} vw=${result.viewport.width} (delta=${Math.round(result.viewport.width - rect.right)}); rect.bottom=${Math.round(rect.bottom)} vh=${result.viewport.height} (delta=${Math.round(result.viewport.height - rect.bottom)})`);
      }
      if (result.avisoLinks.length > 1) {
        throw new Error(`FAIL: ${result.avisoLinks.length} links "Aviso de Privacidade" no banner (esperado 1)`);
      }
    }
  });
}
