'use strict';

/**
 * Contrato BIT A11y × VLibras — Concertação.
 *
 * Em 25/08/2026 o portal VLibras publicou a v7.8.0 e trocou o widget por um
 * componente em shadow DOM, abandonando o markup [vw]/[vw-access-button] que a
 * nossa integração controlava. O resultado foi um botão azul solto no canto
 * direito, colado embaixo do gatilho do painel, e um toggle "Libras" que marcava
 * aria-pressed=true sem abrir nada.
 *
 * Este spec trava as duas pontas:
 *   A) o contrato do terceiro — se o VLibras mudar de novo, falha AQUI e não em produção
 *   B) a nossa integração — botão nativo escondido, painel abre pelo card do A11y
 */

const { test, expect } = require('@playwright/test');
const { BASE_URL } = require('./helpers');

// Página onde o painel A11y aparece. Parametrizável porque o mesmo contrato
// vale para os outros sites BIT que embutem VLibras — o www-concertacao não
// tem /contato/ e sua raiz redireciona para o apex.
const PAGINA = process.env.VLIBRAS_PATH || '/contato/';

// Viewport fixo: o deslocamento do gatilho tem uma variante ≤640px (o gatilho
// some em vez de deslocar), então o teste D só é determinístico com a largura
// declarada. ignoreHTTPSErrors por causa do certificado local do dev.
test.use({ viewport: { width: 1440, height: 900 }, ignoreHTTPSErrors: true });

// Os waitForFunction internos esperam até 30s. Com o timeout de teste no padrão
// (30s também) o de fora sempre vence, e a falha chega como timeout genérico em
// vez da mensagem que diz o que quebrou.
test.describe.configure({ timeout: 60000 });

test.describe('VLibras × BIT A11y', () => {

  test('A) o contrato do VLibras v7.8.0 continua valendo', async ({ page }) => {
    await page.goto(BASE_URL + PAGINA, { waitUntil: 'domcontentloaded' });

    // o host do botão nativo é criado no light DOM e tem shadow root
    await page.waitForFunction(
      () => !!document.getElementById('vlibras-access-wrapper'),
      null,
      { timeout: 20000 }
    );

    const contrato = await page.evaluate(() => {
      const host = document.getElementById('vlibras-access-wrapper');
      return {
        temShadow: !!host.shadowRoot,
        temBotao: !!(host.shadowRoot && host.shadowRoot.getElementById('vlibras-button')),
        api: typeof (window.VLibrasWidget || {}).open,
      };
    });

    expect(contrato.temShadow, 'VLibras deixou de usar shadow root').toBe(true);
    expect(contrato.temBotao, '#vlibras-button sumiu do shadow').toBe(true);
    expect(contrato.api, 'window.VLibrasWidget.open() sumiu — a integração perdeu o gatilho').toBe('function');
  });

  test('B) o botão nativo do VLibras não aparece na página', async ({ page }) => {
    await page.goto(BASE_URL + PAGINA, { waitUntil: 'domcontentloaded' });
    await page.waitForFunction(
      () => !!document.getElementById('vlibras-access-wrapper'),
      null,
      { timeout: 20000 }
    );
    // dá tempo do CSS e do shadow assentarem antes de medir
    await page.waitForTimeout(1500);

    const visivel = await page.evaluate(() => {
      const host = document.getElementById('vlibras-access-wrapper');
      if (getComputedStyle(host).display === 'none') return false;
      const el = host.shadowRoot && host.shadowRoot.getElementById('vlibras-access');
      if (!el) return false;
      const r = el.getBoundingClientRect();
      return r.width > 0 && r.height > 0 && r.right > 0 && r.left < innerWidth;
    });

    expect(visivel, 'o botão azul do VLibras está visível na página — o host não foi escondido').toBe(false);
  });

  test('C) o card Libras do painel abre o widget', async ({ page }) => {
    await page.goto(BASE_URL + PAGINA, { waitUntil: 'domcontentloaded' });
    await page.waitForFunction(
      () => typeof (window.VLibrasWidget || {}).open === 'function',
      null,
      { timeout: 20000 }
    );

    await page.locator('#bureau-a11y-trigger').click();
    // O card Libras vive na aba "Leitura"; a aba ativa por padrão é "Visual".
    // Sem este clique o card existe mas está hidden, e o teste falha antes de
    // chegar no que ele mede.
    await page.locator('#ba-tab-btn-leitura').click();
    const card = page.locator('#ba-toggle-libras');
    await expect(card).toBeVisible();
    await expect(card, 'o card está desabilitado — a API do VLibras não ficou disponível').toBeEnabled();
    await card.click();

    // o widget monta #vlibras-app-root e o painel fica visível dentro do viewport
    await page.waitForFunction(
      () => {
        const root = document.getElementById('vlibras-app-root');
        const app = root && root.shadowRoot && root.shadowRoot.getElementById('vlibras-app');
        if (!app) return false;
        const r = app.getBoundingClientRect();
        return getComputedStyle(app).opacity === '1' && r.left < innerWidth && r.right > 0;
      },
      null,
      { timeout: 30000 }
    );
  });

  test('D) o gatilho do A11y não fica coberto pelo widget aberto', async ({ page }) => {
    await page.goto(BASE_URL + PAGINA, { waitUntil: 'domcontentloaded' });
    await page.waitForFunction(
      () => typeof (window.VLibrasWidget || {}).open === 'function',
      null,
      { timeout: 20000 }
    );
    // Abre pelo card, e não por window.VLibrasWidget.open() direto: com o botão
    // nativo escondido, o card é a única porta que o visitante tem, e é o
    // handler dele que aplica html.ba-vlibras-open. Chamar a API por fora mede
    // um caminho que ninguém percorre.
    await page.locator('#bureau-a11y-trigger').click();
    await page.locator('#ba-tab-btn-leitura').click();
    await page.locator('#ba-toggle-libras').click();
    await page.waitForFunction(
      () => {
        const root = document.getElementById('vlibras-app-root');
        const app = root && root.shadowRoot && root.shadowRoot.getElementById('vlibras-app');
        return app && getComputedStyle(app).opacity === '1';
      },
      null,
      { timeout: 30000 }
    );
    await page.waitForTimeout(800); // deixa a transição do nosso gatilho terminar

    const colide = await page.evaluate(() => {
      const t = document.getElementById('bureau-a11y-trigger').getBoundingClientRect();
      const root = document.getElementById('vlibras-app-root');
      const w = root.shadowRoot.getElementById('vlibras-app').getBoundingClientRect();
      return !(w.right < t.left || w.left > t.right || w.bottom < t.top || w.top > t.bottom);
    });

    expect(colide, 'o widget do VLibras cobre o gatilho do painel de acessibilidade').toBe(false);
  });
});
