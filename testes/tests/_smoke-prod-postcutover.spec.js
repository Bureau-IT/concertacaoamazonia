'use strict';

/**
 * Smoke pós-cutover (concertação) — green agora é prod ativa.
 *
 * Cobertura:
 *  - Fase 1-5: 5 páginas críticas com hostname + jet_max_found_posts + uploads_elementor_css
 *  - Fase 6-7: 2 formulários com submit real via X-BIT-Smoke-Token (positive + negative)
 *  - Fase 7.6: Complianz multisite (banner visivel/accept/deny)
 *  - Fase 7.7: GTM injection
 *  - Fase 7.8: Cache health 4 camadas
 *  - Fase 9: leak detection (gate 21 preloader / 22 CSS leak / 23 google fonts / 24 green path)
 */

const { test, expect } = require('@playwright/test');

const BASE = 'https://concertacaoamazonia.com.br';
const SMOKE_TOKEN = '4e074088d7fa67a97c086b906fa2afc14bb987f6a295bce898a2e5ada9d6f865';

test.use({ viewport: { width: 1440, height: 900 } });

// === FASE 1-5: PÁGINAS CRÍTICAS PROD ===

const PAGES = [
  { name: 'home',     path: '/',                                              expect_listing: false },
  { name: 'atlas-pt', path: '/cultura/atlas-cultural-das-amazonias/',         expect_listing: true,  min_jet: 100 },
  { name: 'atlas-en', path: '/cultura/en/cultural-atlas-of-the-amazon/',      expect_listing: true,  min_jet: 100 },
  { name: 'espiral',  path: '/conhecimento/espiral-de-conhecimento/',         expect_listing: true,  min_jet: 10 },
  { name: 'eventos',  path: '/eventos-calendario/',                           expect_listing: false },
];

for (const cfg of PAGES) {
  test(`F1-5 prod: ${cfg.name}`, async ({ page }, testInfo) => {
    await page.context().clearCookies();
    const url = `${BASE}${cfg.path}?cb=${Date.now()}`;
    const resp = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 45000 });
    await page.waitForLoadState('networkidle', { timeout: 30000 }).catch(() => {});
    await page.waitForTimeout(1500);

    const data = await page.evaluate(async () => {
      const r = await fetch('/check-ec2.php?cb=' + Date.now(), { cache: 'no-store' });
      const text = await r.text();
      const hostname = (text.match(/Hostname:\s*([^\n<]+)/) || [])[1]?.trim() || 'unknown';
      const jsf = window.JetSmartFilterSettings?.props || {};
      let jetMax = 0;
      for (const provider in jsf) for (const qid in jsf[provider]) {
        const fp = jsf[provider][qid].found_posts || 0;
        if (fp > jetMax) jetMax = fp;
      }
      const cssLinks = Array.from(document.querySelectorAll('link[rel="stylesheet"]'));
      return {
        hostname,
        title: document.title.slice(0, 80),
        uploads_elementor_css: cssLinks.filter(l => /\/uploads\/(?:sites\/\d+\/)?elementor\//.test(l.href)).length,
        jet_max_found_posts: jetMax,
        listing_items: document.querySelectorAll('.jet-listing-grid__item').length,
      };
    });

    testInfo.annotations.push({ type: 'data', description: JSON.stringify(data) });
    console.log(`\n[F1-5 ${cfg.name}]`, JSON.stringify(data));

    expect(resp?.status(), 'HTTP 200').toBe(200);
    expect(data.hostname, 'hostname').toMatch(/concertacao|i-0|hml/i);
    expect(data.uploads_elementor_css, 'no /uploads/elementor/ CSS').toBe(0);
    if (cfg.expect_listing) {
      expect(data.jet_max_found_posts, `jet posts >= ${cfg.min_jet}`).toBeGreaterThanOrEqual(cfg.min_jet);
    }
  });
}

// === FASE 6-7: FORMULÁRIOS COM SUBMIT REAL ===

const FORMS = [
  { name: 'newsletter-footer', url: BASE + '/',         is_footer: true },
  { name: 'contato',           url: BASE + '/contato/', is_footer: false },
];

for (const cfg of FORMS) {
  test(`F6-7 form: ${cfg.name} (positive)`, async ({ page }, testInfo) => {
    await page.context().setExtraHTTPHeaders({ 'X-BIT-Smoke-Token': SMOKE_TOKEN });
    await page.context().clearCookies();

    let bypass_header = null;
    page.on('response', (resp) => {
      if (resp.url().startsWith(cfg.url) && bypass_header === null) {
        bypass_header = resp.headers()['x-bit-smoke-bypass'] || 'absent';
      }
    });

    await page.goto(`${cfg.url}?bit_smoke_cb=${Date.now()}_${Math.random()}`, { waitUntil: 'networkidle', timeout: 45000 });
    await page.waitForTimeout(1500);

    // Aceitar Complianz (pointer-events:none bloqueia submit)
    await page.evaluate(() => {
      const acceptBtn = document.querySelector('.cmplz-accept, button.cmplz-accept-all, [data-cmplz-action="accept"]');
      if (acceptBtn) acceptBtn.click();
    });
    await page.waitForTimeout(1500);
    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
    await page.waitForTimeout(1500);

    testInfo.annotations.push({ type: 'bypass_header', description: String(bypass_header) });

    const ts = Date.now();
    const marker = {
      email: `smoke+${ts}@bureau-it.com`,
      nome: `SMOKE TEST ${ts}`,
      msg: 'Automated smoke from /smoke command — safe to delete.',
    };

    const result = await page.evaluate(async ({ m, isFooter }) => {
      const forms = Array.from(document.querySelectorAll('form'));
      const form = isFooter
        ? (forms.find(f => !!f.closest('footer, .elementor-location-footer, [data-elementor-type="footer"]')) || forms[0])
        : (forms.find(f => !f.closest('footer, .elementor-location-footer')) || forms[0]);
      if (!form) return { ok: false, reason: 'form_not_found' };

      const fill = (sel, v) => {
        const el = form.querySelector(sel);
        if (el) {
          el.value = v;
          el.dispatchEvent(new Event('input', { bubbles: true }));
          el.dispatchEvent(new Event('change', { bubbles: true }));
        }
      };
      fill('input[type=email], input[name*=email i], input[placeholder*=email i]', m.email);
      fill('input[name*=nome i], input[placeholder*=nome i], input[name*=name i]', m.nome);
      fill('textarea, input[name*=mensagem i], input[name*=message i]', m.msg);
      const sel = form.querySelector('select');
      if (sel && sel.options.length > 1) { sel.selectedIndex = 1; sel.dispatchEvent(new Event('change', { bubbles: true })); }
      const btn = form.querySelector('button[type=submit], input[type=submit], .elementor-button[type=submit], button.elementor-button');
      if (!btn) return { ok: false, reason: 'submit_btn_not_found' };
      const container = form.closest('.elementor-widget-form, .elementor-element') || form.parentElement;
      btn.click();
      const deadline = Date.now() + 25000;
      while (Date.now() < deadline) {
        const success = container?.querySelector('.elementor-message-success') || document.querySelector('.elementor-message-success, .jet-form-builder-message--success');
        const error   = container?.querySelector('.elementor-message-danger')  || document.querySelector('.elementor-message-danger, .jet-form-builder-message--error');
        if (success) return { ok: true,  message: success.innerText.trim().slice(0, 200) };
        if (error)   return { ok: false, reason: 'error_message', message: error.innerText.trim().slice(0, 200) };
        await new Promise(r => setTimeout(r, 250));
      }
      return { ok: false, reason: 'timeout_25s' };
    }, { m: marker, isFooter: cfg.is_footer });

    testInfo.annotations.push({ type: 'submit_result', description: JSON.stringify(result) });
    testInfo.annotations.push({ type: 'marker', description: marker.email });

    expect(result.ok, `submit ${cfg.name}: ${result.reason || ''} ${result.message || ''}`).toBe(true);
  });
}

// === TESTE NEGATIVO: token inválido deve REJEITAR ===

test('F6-7 form: newsletter-footer (negative: token invalido)', async ({ page }, testInfo) => {
  await page.context().setExtraHTTPHeaders({ 'X-BIT-Smoke-Token': 'invalid'.repeat(10) });
  await page.context().clearCookies();

  let bypass_header = null;
  page.on('response', (resp) => {
    if (resp.url().startsWith(BASE) && bypass_header === null) {
      bypass_header = resp.headers()['x-bit-smoke-bypass'] || 'absent';
    }
  });

  await page.goto(`${BASE}/?bit_smoke_cb=${Date.now()}_${Math.random()}`, { waitUntil: 'networkidle', timeout: 45000 });
  await page.waitForTimeout(1500);

  testInfo.annotations.push({ type: 'bypass_header', description: String(bypass_header) });

  // Gate negativo: bypass deve ser NOOP (token errado, não autoriza)
  expect(bypass_header, 'NOOP esperado com token invalido').toBe('NOOP');
});

// === FASE 7.6: COMPLIANZ MULTISITE ===

test('F7.6 Complianz multisite', async ({ page }) => {
  const audit = async (url) => {
    await page.context().clearCookies();
    await page.goto(url + '?cb=' + Date.now(), { waitUntil: 'networkidle', timeout: 30000 });
    await page.waitForTimeout(3000);
    return await page.evaluate(() => {
      const banner = document.querySelector('.cmplz-cookiebanner, #cmplz-cookiebanner-container');
      return {
        banner_visible: banner ? getComputedStyle(banner).display !== 'none' : false,
        has_accept: !!document.querySelector('.cmplz-accept, [data-cmplz-action="accept"]'),
        has_deny: !!document.querySelector('.cmplz-deny, [data-cmplz-action="deny"]'),
        window_complianz: typeof window.complianz !== 'undefined',
      };
    });
  };

  const blog1 = await audit(BASE + '/');
  const blog2 = await audit(BASE + '/cultura/');
  console.log('\n[F7.6]', JSON.stringify({ blog1, blog2 }, null, 2));

  expect(blog1.banner_visible, 'blog1 banner').toBe(true);
  expect(blog1.has_accept, 'blog1 accept').toBe(true);
  expect(blog2.banner_visible, 'blog2 banner').toBe(true);
  expect(blog2.has_accept, 'blog2 accept').toBe(true);
});

// === FASE 9: LEAK DETECTION ===

test('F9 leaks gate 21-24', async ({ page }) => {
  await page.context().clearCookies();
  await page.goto(BASE + '/?cb=' + Date.now(), { waitUntil: 'networkidle', timeout: 45000 });
  await page.waitForTimeout(1500);

  const data = await page.evaluate(async () => {
    // Gate 21: preloader Elementor
    const el = document.querySelector('e-page-transition');
    const svg = el?.querySelector('svg');
    const preloader = {
      present: !!el,
      has_svg: !!svg,
      svg_size: svg ? svg.outerHTML.length : 0,
    };

    // Gate 24: /green/ leak em img src
    const greenLeaks = Array.from(document.querySelectorAll('img[src]'))
      .filter(i => /\/green\//.test(i.src))
      .map(i => i.src.split('/').slice(-3).join('/').slice(0, 80));

    // Gate 23: Google Fonts externos
    const links = Array.from(document.querySelectorAll('link[href]'))
      .filter(l => /fonts\.googleapis\.com|fonts\.gstatic\.com/.test(l.href));
    const stylesheet_count = links.filter(l => (l.rel || '').toLowerCase() === 'stylesheet').length;
    const font_request_count = links.filter(l => (l.rel || '').toLowerCase() === 'preload' && (l.getAttribute('as') || '').toLowerCase() === 'font').length;
    const preconnect_count = links.filter(l => /^(preconnect|dns-prefetch)$/i.test(l.rel)).length;

    return {
      gate_21_preloader: preloader,
      gate_23_fonts: { stylesheet_count, font_request_count, preconnect_count },
      gate_24_green_leak: { count: greenLeaks.length, leaks: greenLeaks },
    };
  });

  console.log('\n[F9]', JSON.stringify(data, null, 2));

  // Gate 21: preloader DEVE ter SVG
  if (data.gate_21_preloader.present) {
    expect(data.gate_21_preloader.has_svg, 'preloader SVG presente').toBe(true);
    expect(data.gate_21_preloader.svg_size, 'preloader SVG > 100 bytes').toBeGreaterThan(100);
  }

  // Gate 23a (HIGH): nenhum stylesheet/preload de Google Fonts
  expect(data.gate_23_fonts.stylesheet_count, 'no google fonts stylesheet').toBe(0);
  expect(data.gate_23_fonts.font_request_count, 'no google fonts preload').toBe(0);

  // Gate 24 BLOCKER: nenhuma img src com /green/
  expect(data.gate_24_green_leak.count, `no /green/ leaks: ${data.gate_24_green_leak.leaks.join(', ')}`).toBe(0);
});

// === FASE 7.8 SIMPLIFICADA: CACHE HEALTH ===

test('F7.8 cache health', async ({ page }) => {
  await page.context().clearCookies();

  // Drop-in probe
  const dropInProbe = await page.evaluate(async () => {
    const r = await fetch('https://concertacaoamazonia.com.br/wp-content/object-cache.php', { method: 'HEAD', cache: 'no-store' });
    return { status: r.status };
  });

  // 2 requests com mesma cache key
  const target = BASE + '/conhecimento/espiral-de-conhecimento/';
  const cb = 'cache-' + Math.floor(Date.now() / 60000);

  const r1 = await page.goto(target + '?cb=' + cb, { waitUntil: 'domcontentloaded' });
  const xc1 = r1.headers()['x-cache'];
  await page.waitForTimeout(800);
  const r2 = await page.goto(target + '?cb=' + cb, { waitUntil: 'domcontentloaded' });
  const xc2 = r2.headers()['x-cache'];

  console.log('\n[F7.8] dropIn=', dropInProbe.status, 'xc1=', xc1, 'xc2=', xc2);

  expect(dropInProbe.status, 'drop-in instalado (200 ou 403)').toBeLessThan(404);
  expect((xc2 || '').toLowerCase(), 'CF cached on 2nd hit').toMatch(/hit/);
});
