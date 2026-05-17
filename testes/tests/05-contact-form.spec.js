'use strict';

const { test, expect } = require('@playwright/test');
const path = require('path');
const fs = require('fs');

try { require('dotenv').config({ path: path.join(__dirname, '..', '.env.local') }); } catch {}

const screenshotsDir = path.join(__dirname, '..', 'screenshots', 'contact-form');

test.beforeAll(() => {
  fs.mkdirSync(screenshotsDir, { recursive: true });
});

test('formulário de contato: fill + submit', async ({ page }) => {
  if (process.env.SKIP_CONTACT_FORM === 'true') {
    test.skip(true, 'SKIP_CONTACT_FORM=true — envio ignorado para evitar spam em produção');
    return;
  }

  await page.goto('/contato/', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1500);

  // Screenshot antes do preenchimento
  await page.screenshot({ path: path.join(screenshotsDir, 'before-submit.png'), fullPage: true });

  // Seletores com fallback (CF7 + genérico)
  const nameSelector = 'input[name="your-name"], input[name*="name"][type="text"]';
  const emailSelector = 'input[type="email"]';
  const messageSelector = 'textarea';
  const submitSelector = 'input[type="submit"], button[type="submit"]';

  const nameEl = page.locator(nameSelector).first();
  const emailEl = page.locator(emailSelector).first();
  const messageEl = page.locator(messageSelector).first();
  const submitEl = page.locator(submitSelector).first();

  expect(await nameEl.count(), 'Campo de nome não encontrado').toBeGreaterThan(0);
  expect(await emailEl.count(), 'Campo de email não encontrado').toBeGreaterThan(0);
  expect(await messageEl.count(), 'Campo de mensagem não encontrado').toBeGreaterThan(0);
  expect(await submitEl.count(), 'Botão de submit não encontrado').toBeGreaterThan(0);

  await nameEl.fill('Teste Automatizado Bureau IT');
  await emailEl.fill('teste@bureaudetecnologia.com.br');
  await messageEl.fill('Mensagem de teste automatizado — Playwright Suite. Por favor, ignore.');

  await submitEl.click();

  // CF7 é AJAX — aguarda resposta
  await page.waitForTimeout(3000);

  // Screenshot após submit
  await page.screenshot({ path: path.join(screenshotsDir, 'after-submit.png'), fullPage: true });

  // Verificação de sucesso com múltiplos seletores possíveis
  const successSelectors = [
    '.wpcf7-mail-sent-ok',
    '.gform_confirmation_message',
    '.wpcf7-response-output',
  ];

  let successFound = false;

  for (const sel of successSelectors) {
    const el = page.locator(sel);
    if (await el.count() > 0 && await el.isVisible()) {
      successFound = true;
      break;
    }
  }

  if (!successFound) {
    // Verificação por texto
    const bodyText = await page.textContent('body');
    const successPatterns = /obrigado|enviada com sucesso|thank you|mensagem enviada/i;
    successFound = successPatterns.test(bodyText || '');
  }

  expect(
    successFound,
    'Formulário submetido mas mensagem de sucesso não encontrada — verificar CF7, reCAPTCHA ou logs PHP'
  ).toBe(true);
});
