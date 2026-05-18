'use strict';

/**
 * Validação pós-cutover: tráfego normal (sem X-Test-Green) deve servir a
 * green (agora ativa como prod). Hostname tem que mostrar 'hml' (label
 * temporário pré-rename pela phase 8).
 */

const { test, expect } = require('@playwright/test');

const BASE = process.env.BASE_URL || 'https://concertacaoamazonia.com.br';

test.use({ viewport: { width: 1440, height: 900 } });

const PAGES = [
  { name: 'home',       path: '/' },
  { name: 'sobre-nos',  path: '/sobre-nos/' },
  { name: 'cultura',    path: '/cultura/' },
  { name: 'atlas-pt',   path: '/cultura/atlas-cultural-das-amazonias/' },
  { name: 'espiral',    path: '/conhecimento/espiral-de-conhecimento/' },
];

for (const cfg of PAGES) {
  test(`post-cutover: ${cfg.name}`, async ({ page }, testInfo) => {
    const consoleErrors = [];
    const failedReqs = [];
    page.on('console', m => {
      if (m.type() === 'error' && !/JQMIGRATE|preloaded.*not used|Failed to load.*403|third-party cookie/i.test(m.text())) {
        consoleErrors.push(m.text().slice(0, 200));
      }
    });
    page.on('response', r => {
      if (r.status() >= 400 && r.url().includes('concertacaoamazonia.com.br') && !/favicon\.ico/.test(r.url())) {
        failedReqs.push(`${r.status()} ${r.url().split('/').pop().slice(0, 60)}`);
      }
    });

    const url = `${BASE}${cfg.path}?cb=${Date.now()}`;
    const resp = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 45000 });
    await page.waitForLoadState('networkidle', { timeout: 30000 }).catch(() => {});
    await page.waitForTimeout(1500);

    const data = await page.evaluate(async () => {
      const r = await fetch('/check-ec2.php?cb=' + Date.now(), { cache: 'no-store' });
      const text = await r.text();
      const hostname = (text.match(/Hostname:\s*([^\n<]+)/) || [])[1]?.trim() || 'unknown';
      const env_label = /Produção|Production/.test(text) ? 'prod' : /Homologação|Staging/.test(text) ? 'staging' : 'unknown';
      return {
        hostname,
        env_label,
        title: document.title,
        has_complianz: !!document.querySelector('.cmplz-cookiebanner, #cmplz-cookiebanner-container'),
        cmplz_classes: Array.from(document.querySelector('.cmplz-cookiebanner')?.classList || []).filter(c => /cmplz-/.test(c)),
      };
    });

    console.log(`\n=== ${cfg.name} ===`);
    console.log(JSON.stringify(data, null, 2));

    expect(resp?.status(), 'HTTP status').toBe(200);
    expect(data.hostname, 'hostname deve indicar green active').toMatch(/hml|green|i-0/i);
    expect(failedReqs, `failed requests:\n${failedReqs.slice(0,5).join('\n')}`).toEqual([]);
    expect(consoleErrors, `console errors:\n${consoleErrors.slice(0,5).join('\n')}`).toEqual([]);
  });
}
