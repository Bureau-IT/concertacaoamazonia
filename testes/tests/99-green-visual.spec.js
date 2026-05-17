'use strict';

/**
 * Validação visual da green pre-cutover.
 * Acessa páginas-chave com X-Test-Green: true (ALB rule priority 5).
 * Valida:
 *   - HTTP 200 da página principal
 *   - Title preenchido e não-404
 *   - Zero hostnames de dev no HTML (cambrasmax.local, *.bureau-it.com)
 *   - Zero erros de console (CSS/JS 404, MIME wrong)
 *   - Zero subresources 4xx/5xx (CSS Elementor, imagens, etc)
 *   - Screenshot fullPage de cada página
 *
 * Uso:
 *   cd <site>/testes
 *   BASE_URL=https://<fqdn> npx playwright test 99-green-visual.spec.js
 *
 * PRÉ-REQUISITO: NordVPN ativo (CIDRs 185.153.176.0/24 ou 45.11.82.0/24) —
 * ALB libera 443 só para esses CIDRs quando X-Test-Green é enviado direto.
 * Acesso normal via CloudFront stripa o header.
 */

const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');
const { execFileSync } = require('child_process');

const today = new Date().toISOString().slice(0, 10);
const screenshotsDir = path.join(__dirname, '..', 'screenshots', `green-${today}`);

// NÃO usar extraHTTPHeaders global — ele aplica X-Test-Green a TODOS requests,
// incluindo cross-origin (Google reCAPTCHA, gtag, fonts), causando CORS preflight
// failure. Em vez disso, usar route() para injetar header SÓ em requests para
// o BASE_URL do site (mesma origem).

// Pré-computado em beforeAll: arquivos que vivem em /green/uploads/ mas não
// em /assets/uploads/ no S3. Para esses, reescrevemos a URL em vôo para usar
// o path /wp-content/uploads/_oac-canary/<rest>, que o CF behavior canary
// roteia para o origin S3-uploads-green com a CF Function fazendo strip extra
// do segmento _oac-canary/ (após mudança publicada em uploads-oac-router.js).
const greenOnlyUploads = new Set();
const STAGE_BUCKET = process.env.STAGE_BUCKET || 'concertacaoamazonia-com-br-wp-static-prd-sa';
const AWS_PROFILE_NAME = process.env.AWS_PROFILE || 'Concertação';

function listS3Keys(prefix) {
  try {
    const out = execFileSync(
      'aws',
      ['s3', 'ls', `s3://${STAGE_BUCKET}/${prefix}/`, '--recursive', '--profile', AWS_PROFILE_NAME],
      { encoding: 'utf8', maxBuffer: 50 * 1024 * 1024 }
    );
    return out
      .split('\n')
      .map((l) => l.trim().split(/\s+/).slice(3).join(' '))
      .filter(Boolean)
      .map((k) => k.replace(new RegExp(`^${prefix}/`), ''));
  } catch (e) {
    console.warn(`[stage-rewrite] aws s3 ls failed for ${prefix}: ${e.message}`);
    return [];
  }
}

test.beforeAll(() => {
  fs.mkdirSync(screenshotsDir, { recursive: true });

  const greenKeys = listS3Keys('green/uploads');
  const assetsKeys = new Set(listS3Keys('assets/uploads'));
  for (const k of greenKeys) {
    if (!assetsKeys.has(k)) greenOnlyUploads.add(k);
  }
  console.log(`[stage-rewrite] ${greenOnlyUploads.size} uploads only in /green/ (will be rewritten to _oac-canary path)`);
});

test.beforeEach(async ({ page }) => {
  const baseUrl = new URL(process.env.BASE_URL || 'https://concertacaoamazonia.com.br');
  await page.route('**/*', async (route) => {
    const req = route.request();
    const reqUrl = new URL(req.url());

    if (reqUrl.hostname !== baseUrl.hostname) {
      // Cross-origin (Google, fonts, etc): passar sem modificar
      return route.continue();
    }

    const headers = { ...req.headers(), 'x-test-green': 'true' };

    // Reescreve uploads que só existem em /green/ → /_oac-canary/<path>
    // CF Function uploads-oac-router faz strip do _oac-canary/ antes de bater
    // no origin S3-uploads-green (OriginPath=/green/uploads).
    const uploadsMatch = reqUrl.pathname.match(/^\/wp-content\/uploads\/(.+)$/);
    if (uploadsMatch && greenOnlyUploads.has(uploadsMatch[1])) {
      const rewritten = `${reqUrl.origin}/wp-content/uploads/_oac-canary/${uploadsMatch[1]}${reqUrl.search}`;
      return route.continue({ url: rewritten, headers });
    }

    await route.continue({ headers });
  });
});

const PAGES_TO_VALIDATE = [
  { name: 'home', path: '/' },
  { name: 'sobre-nos', path: '/sobre-nos/' },
  { name: 'atuacao', path: '/atuacao/' },
  { name: 'conhecimento-espiral', path: '/conhecimento/espiral-de-conhecimento/' },
  { name: 'cultura', path: '/cultura/' },
  { name: 'cultura-atlas', path: '/cultura/atlas-cultural-das-amazonias/' },
  { name: 'contato', path: '/contato/' },
];

// Lista de paths/extensões a IGNORAR em failed-requests check
// (third-party que pode falhar legitimamente; warning mas não falha o teste)
const IGNORE_FAILED_REQUEST_PATTERNS = [
  /google-analytics\.com/,
  /googletagmanager\.com/,
  /facebook\.com/,
  /facebook\.net/,
  /linkedin\.com/,
  /twitter\.com/,
  /youtube\.com\/api\/stats/,
  /doubleclick\.net/,
  /clarity\.ms/,
  /hotjar\.com/,
  /favicon\.ico$/,
  /sw\.js$/,
];

const IGNORE_CONSOLE_PATTERNS = [
  /JQMIGRATE/,
  /preloaded using link preload but not used/,
  /third-party cookie/i,
  /Failed to load resource: net::ERR_BLOCKED_BY_CLIENT/,
  /DevTools/,
  // Stage: 403 de uploads novos via CF (esperado pré-cutover; já filtrado em
  // page.on('response') com diferenciação por origin S3 + path /uploads/).
  // Browser duplica como console error genérico — ignorar para não falsificar fail.
  /Failed to load resource: the server responded with a status of 403/,
];

for (const { name, path: urlPath } of PAGES_TO_VALIDATE) {
  test(`green visual: ${name}`, async ({ page }, testInfo) => {
    const url = `${process.env.BASE_URL || 'https://concertacaoamazonia.com.br'}${urlPath}?cb=${Date.now()}`;

    const consoleErrors = [];
    const consoleWarnings = [];
    const failedRequests = [];

    page.on('console', (msg) => {
      const text = msg.text();
      const type = msg.type();
      if (IGNORE_CONSOLE_PATTERNS.some((p) => p.test(text))) return;
      if (type === 'error') {
        consoleErrors.push({ text, location: msg.location() });
      } else if (type === 'warning') {
        consoleWarnings.push({ text });
      }
    });

    page.on('pageerror', (err) => {
      consoleErrors.push({ text: `pageerror: ${err.message}`, location: null });
    });

    page.on('requestfailed', (req) => {
      const failureUrl = req.url();
      if (IGNORE_FAILED_REQUEST_PATTERNS.some((p) => p.test(failureUrl))) return;
      failedRequests.push({
        url: failureUrl,
        failure: req.failure()?.errorText || 'unknown',
        type: req.resourceType(),
      });
    });

    page.on('response', (resp) => {
      const status = resp.status();
      const respUrl = resp.url();
      if (status < 400) return;
      if (IGNORE_FAILED_REQUEST_PATTERNS.some((p) => p.test(respUrl))) return;
      // Só falha pra mesma origem (subresources servidos pela green)
      const isSameOrigin = respUrl.includes(new URL(url).hostname);
      if (!isSameOrigin && status === 403) return;
      // Durante stage, uploads novos vivem em s3://bucket/green/uploads/ enquanto
      // o CF behavior wp-content/uploads/* ainda aponta para /assets/uploads (prod).
      // 403 com server=AmazonS3 é ESPERADO até phase7-cutover.sh fazer o swap
      // green→assets. Validação foca em CSS/JS/HTML servidos pela green via ALB.
      const headers = resp.headers();
      const isS3Origin = (headers['server'] || '').toLowerCase().includes('amazons3');
      const isUploads = respUrl.includes('/wp-content/uploads/');
      if (status === 403 && isS3Origin && isUploads) {
        testInfo.annotations.push({
          type: 'stage-expected-403',
          description: `Upload via CF (esperado pré-cutover): ${respUrl.split('/').pop()}`,
        });
        return;
      }
      failedRequests.push({
        url: respUrl,
        status,
        type: resp.request().resourceType(),
      });
    });

    const response = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 45000 });
    expect(response, `Sem resposta para ${urlPath}`).not.toBeNull();
    expect(response.status(), `HTTP status != 200 em ${urlPath}`).toBe(200);

    // Espera assets carregarem
    await page.waitForLoadState('networkidle', { timeout: 30000 }).catch(() => {});
    await page.waitForTimeout(2000);

    const title = await page.title();
    expect(title, `Título vazio em ${urlPath}`).toBeTruthy();
    expect(title.toLowerCase()).not.toContain('not found');
    expect(title.toLowerCase()).not.toContain('error');

    const html = await page.content();

    // ZERO ocorrências de cambrasmax.local
    const cambrasMatches = (html.match(/cambrasmax\.local/g) || []).length;
    expect(cambrasMatches, `${cambrasMatches} ocorrências de cambrasmax.local em ${urlPath}`).toBe(0);

    // ZERO ocorrências de tunnel concertacao.bureau-it.com
    const tunnelMatches = (html.match(/concertacao\.bureau-it\.com/g) || []).length;
    expect(tunnelMatches, `${tunnelMatches} ocorrências de concertacao.bureau-it.com em ${urlPath}`).toBe(0);

    // Screenshot fullPage SEMPRE (sucesso ou falha)
    const screenshotPath = path.join(screenshotsDir, `${name}.png`);
    await page.screenshot({ path: screenshotPath, fullPage: true });

    // Console errors e failed requests viram falhas DUROS do teste
    if (consoleErrors.length > 0) {
      const summary = consoleErrors.slice(0, 10).map((e) => `  - ${e.text}`).join('\n');
      throw new Error(
        `${consoleErrors.length} console errors em ${urlPath}:\n${summary}` +
          (consoleErrors.length > 10 ? `\n  ... e mais ${consoleErrors.length - 10}` : '')
      );
    }
    if (failedRequests.length > 0) {
      const summary = failedRequests.slice(0, 10).map((r) => `  - [${r.status || r.failure}] ${r.type} ${r.url}`).join('\n');
      throw new Error(
        `${failedRequests.length} subresources com erro em ${urlPath}:\n${summary}` +
          (failedRequests.length > 10 ? `\n  ... e mais ${failedRequests.length - 10}` : '')
      );
    }

    if (consoleWarnings.length > 0) {
      testInfo.annotations.push({
        type: 'warning',
        description: `${consoleWarnings.length} console warnings (não-fatal)`,
      });
    }
  });
}

test('green hostname check via check-ec2.php', async ({ page }) => {
  const url = `${process.env.BASE_URL || 'https://concertacaoamazonia.com.br'}/check-ec2.php?cb=${Date.now()}`;
  const response = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 30000 });
  expect(response.status()).toBe(200);
  const body = await page.content();

  // check-ec2.php imprime hostname/instance-id. Esperado: contém "hml" ou nome da green
  expect(body.toLowerCase()).toMatch(/hml|green|i-0f1e6e093d31aa9c5/);
});
