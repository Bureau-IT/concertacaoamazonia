'use strict';

/**
 * Smoke green-only pré-cutover do Concertação.
 * Equivalente ao /smoke skill, mas GREEN-ONLY (não polui prod blue que vai sair de rotação).
 *
 * Cobre Fases 1-5 (páginas críticas) + Fases 6-7 (formulários com submit real)
 * Snippet base do skill /smoke adaptado para Playwright spec.
 */

const { test, expect } = require('@playwright/test');

const BASE = process.env.BASE_URL || 'https://concertacaoamazonia.com.br';

const SMOKE_TOKEN = '4e074088d7fa67a97c086b906fa2afc14bb987f6a295bce898a2e5ada9d6f865';

test.use({ viewport: { width: 1440, height: 900 } });

test.beforeEach(async ({ page }) => {
  const baseUrl = new URL(BASE);
  await page.route('**/*', async (route) => {
    const req = route.request();
    const reqUrl = new URL(req.url());
    if (reqUrl.hostname !== baseUrl.hostname) return route.continue();
    const headers = { ...req.headers(), 'x-test-green': 'true' };
    await route.continue({ headers });
  });
});

// === FASE 1-5: PÁGINAS CRÍTICAS ===

const PAGES = [
  { name: 'home',        path: '/',                                              expect_listing: false },
  { name: 'atlas-pt',    path: '/cultura/atlas-cultural-das-amazonias/',         expect_listing: true,  min_jet: 100 },
  { name: 'atlas-en',    path: '/cultura/en/cultural-atlas-of-the-amazon/',      expect_listing: true,  min_jet: 100 },
  { name: 'espiral',     path: '/conhecimento/espiral-de-conhecimento/',         expect_listing: true,  min_jet: 10  },
  { name: 'eventos',     path: '/eventos-calendario/',                           expect_listing: false },
];

for (const cfg of PAGES) {
  test(`smoke green: ${cfg.name}`, async ({ page }) => {
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
        title: document.title,
        html_size: document.documentElement.outerHTML.length,
        uploads_elementor_css: cssLinks.filter(l => /\/uploads\/(?:sites\/\d+\/)?elementor\//.test(l.href)).length,
        jet_max_found_posts: jetMax,
        listing_items: document.querySelectorAll('.jet-listing-grid__item').length,
      };
    });

    console.log(`\n=== ${cfg.name} ===\n${JSON.stringify(data, null, 2)}`);

    expect(resp?.status(), `HTTP status`).toBe(200);
    expect(data.hostname.toLowerCase(), 'hostname deve conter "hml" (green) ou "i-" (ec2)').toMatch(/hml|i-0|green/i);
    expect(data.uploads_elementor_css, 'uploads_elementor_css deve ser 0').toBe(0);
    if (cfg.expect_listing) {
      expect(data.jet_max_found_posts, `jet_max_found_posts esperado >= ${cfg.min_jet}`).toBeGreaterThanOrEqual(cfg.min_jet);
    }
  });
}

// === FASE 6-7: FORMULÁRIOS GREEN ===

const FORMS = [
  { name: 'newsletter-footer',  url: BASE + '/',           is_footer: true },
  { name: 'contato',            url: BASE + '/contato/',   is_footer: false },
];

for (const cfg of FORMS) {
  test(`smoke green form: ${cfg.name}`, async ({ page }, testInfo) => {
    // Adiciona header de smoke token (já tem X-Test-Green do beforeEach)
    await page.context().setExtraHTTPHeaders({ 'X-BIT-Smoke-Token': SMOKE_TOKEN });

    let bypass_header = null;
    page.on('response', (resp) => {
      if (resp.url().startsWith(cfg.url) && bypass_header === null) {
        bypass_header = resp.headers()['x-bit-smoke-bypass'] || 'absent';
      }
    });

    // Cache-buster forte para forçar CF MISS no GET inicial. Sem isso, CF serve
    // do cache populado SEM o token e o header X-BIT-Smoke-Bypass não aparece.
    // O POST do submit sempre vai pra origem (CF não cacheia POST), então o
    // bypass do reCAPTCHA funciona — só não conseguimos detectar via header
    // de GET pré-submit. Solução: tentamos detectar, mas se "absent", apenas
    // logamos warning e prosseguimos para o submit.
    await page.goto(`${cfg.url}?bit_smoke_cb=${Date.now()}_${Math.random()}`, { waitUntil: 'networkidle', timeout: 45000 });
    await page.waitForTimeout(1500);

    // Aceitar banner Complianz (que aplica pointer-events:none em todo o body
    // enquanto banner-active) — sem isso o click no submit é silenciosamente
    // ignorado e o teste cai em timeout_25s sem submit real.
    await page.evaluate(() => {
      const acceptBtn = document.querySelector('.cmplz-accept, button.cmplz-accept-all, [data-cmplz-action="accept"]');
      if (acceptBtn) acceptBtn.click();
    });
    await page.waitForTimeout(1500);

    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
    await page.waitForTimeout(1500);

    console.log(`\n=== ${cfg.name} bypass_header=${bypass_header} ===`);

    // Gate relaxado: header é "best effort" — se "absent" é cache CF servindo
    // resposta cacheada sem token. Submit POST vai pra origem direta, então
    // teste verdadeiro é o submit_ok.
    if (bypass_header !== 'OK') {
      testInfo.annotations.push({ type: 'bypass_header_warning', description: `bypass_header=${bypass_header} — CF cache hit no GET, mas POST vai direto pra origem` });
    }

    const ts = Date.now();
    const marker = {
      email: `smoke+${ts}@bureau-it.com`,
      nome: `SMOKE TEST ${ts}`,
      msg: 'Automated smoke test from /smoke command — safe to delete.',
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
      if (sel && sel.options.length > 1) {
        sel.selectedIndex = 1;
        sel.dispatchEvent(new Event('change', { bubbles: true }));
      }
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

    expect(result.ok, `Form submit em ${cfg.name}: ${result.reason || ''} ${result.message || ''}`).toBe(true);
  });
}
