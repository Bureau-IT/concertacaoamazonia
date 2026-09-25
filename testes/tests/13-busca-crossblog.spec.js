'use strict';

/**
 * Contrato da busca cross-blog (mu-plugin bit-crossblog-search.php) × JetSearch.
 *
 * A integração pendura o conteúdo do Atlas Cultural (blog 2) no Ajax Search do
 * JetSearch por pontos internos do plugin: a classe Search_Sources\Base, o hook
 * jet-search/sources/register, o filtro de localize-data e o markup do dropdown
 * (slide, lista, rodapé). Um update do JetSearch pode mudar qualquer um deles.
 *
 * RODAR DEPOIS DE TODO UPDATE DO JETSEARCH (e do JetBlocks, dono do painel):
 *   cd testes && BASE_URL=https://cambrasmax.local:8484 npx playwright test 13-busca-crossblog.spec.js
 *
 * Antes, a checagem de assinatura contra updates simulados:
 *   scripts/busca-crossblog-compat-fixtures.php (instruções no cabeçalho dele)
 *
 * Se passar, atualizar BIT_CROSSBLOG_SEARCH_JETSEARCH_TESTED no mu-plugin (o
 * aviso amarelo do admin some). Se o teste A falhar com "sources" vazio, a
 * checagem por Reflection desligou as fontes — o motivo está no aviso vermelho
 * do admin e no error_log ("[bit-crossblog-search]").
 *
 *   A) REST do JetSearch devolve os seis blocos adicionais (notícias, eventos,
 *      participantes, Atlas: páginas/artistas/exposições) no markup de item
 *   B) no dropdown, os blocos entram no slide de posts: uma rolagem só
 *   C) "Ver mais resultados" leva a /busca/ com as três seções
 *   D) /busca/ nunca é cacheável; /?s= continua redirecionando (armadilha de crawler),
 *      mas o /?s= do JetSearch (HTML antigo em cache) vai para /busca/
 *   E) no /cultura/ o painel existe e a busca vai para o REST do blog 1
 *   F) resultado de artista abre o popup dele no mapa do Atlas — contrato com a
 *      API pública do JetEngine Maps (window.JetEngineMaps.openMapListingPopup);
 *      rodar também depois de update do JetEngine
 */

const { test, expect } = require('@playwright/test');
const { BASE_URL } = require('./helpers');

// Termo que existe nos três conjuntos: estudos/páginas do blog 1, Linha das
// Artes e artista do Atlas. Parametrizável para o caso de o conteúdo mudar.
const TERMO = process.env.BUSCA_TERMO || 'Hadna';

test.use({ viewport: { width: 1440, height: 900 }, ignoreHTTPSErrors: true });
test.describe.configure({ timeout: 60000 });

async function abrirPainelEBuscar(page, caminho) {
  await page.goto(BASE_URL + caminho, { waitUntil: 'load' });
  await page.locator('.jet-hamburger-panel__toggle').first().click();

  const campo = page.locator('.jet-ajax-search__field:visible').first();
  await expect(campo, 'painel de busca do header não abriu').toBeVisible();

  const resposta = page.waitForResponse((r) => r.url().includes('/jet-search/v1/search-posts'));
  await campo.fill(TERMO);
  return { campo, resposta: await resposta };
}

test.describe('Busca cross-blog × JetSearch', () => {

  test('A) o REST do JetSearch devolve os blocos do Atlas', async ({ request }) => {
    const r = await request.get(BASE_URL + '/wp-json/jet-search/v1/search-posts', {
      params: {
        'data[value]': TERMO,
        'data[search_source][]': 'estudos',
        'data[limit_query_in_result_area]': '10',
        'data[thumbnail_visible]': 'yes',
        'data[post_content_length]': '20',
        'data[search_source_bit_atlas_pages]': 'true',
        'data[search_source_bit_atlas_artists]': 'true',
        'data[search_source_bit_noticias]': 'true',
        'data[search_source_bit_eventos]': 'true',
        'data[search_source_bit_participantes]': 'true',
        'data[search_source_bit_exposicoes]': 'true',
        lang: 'pt-br',
      },
    });
    expect(r.status()).toBe(200);

    const corpo = await r.json();
    const dados = corpo.data || corpo;
    const tipos = (dados.sources || []).map((s) => s.type);

    // A rota devolve toda fonte ligada, mesmo sem resultado (content vazio).
    expect(tipos, 'fontes adicionais ausentes — ver aviso no admin / error_log').toEqual(
      expect.arrayContaining(['bit_noticias', 'bit_eventos', 'bit_participantes', 'bit_atlas_pages', 'bit_atlas_artists', 'bit_exposicoes'])
    );

    const paginas = dados.sources.find((s) => s.type === 'bit_atlas_pages').content;
    expect(paginas).toContain('jet-ajax-search__results-item');
    expect(paginas).toContain('jet-ajax-search__item-content');
    expect(paginas).toMatch(/href="[^"]*\/cultura\//);
  });

  test('B) os blocos do Atlas entram no slide: uma rolagem só', async ({ page }) => {
    await abrirPainelEBuscar(page, '/');

    const bloco = page.locator('[class*="jet-ajax-search__source-results-holder_bit_"]').first();
    await expect(bloco).toBeVisible();

    const estado = await page.evaluate(() => {
      const blocos = [...document.querySelectorAll('[class*="jet-ajax-search__source-results-holder_bit_"]')];
      const rolaveis = [...document.querySelectorAll('.jet-ajax-search__results-holder, .jet-ajax-search__results-holder *')]
        .filter((e) => /(auto|scroll)/.test(getComputedStyle(e).overflowY) && e.scrollHeight > e.clientHeight + 1);
      // O rodapé ("Ver mais resultados") tem de estar de fato visível: o
      // painel do header corta o conteúdo (overflow:hidden) acima do fim da
      // janela, então "cabe na janela" não basta — conferir o que está no
      // ponto do botão.
      const botao = document.querySelector('.jet-ajax-search__full-results');
      const r = botao.getBoundingClientRect();
      const noPonto = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
      return {
        dentroDoSlide: blocos.every((b) => b.closest('.jet-ajax-search__results-slide')),
        rolaveis: rolaveis.length,
        rodapeVisivel: !!noPonto && (noPonto === botao || botao.contains(noPonto)),
      };
    });

    expect(estado.dentroDoSlide, 'blocos fora do slide: o markup do dropdown mudou').toBe(true);
    expect(estado.rolaveis, 'mais de uma área rolável no dropdown').toBeLessThanOrEqual(1);
    expect(estado.rodapeVisivel, 'botão "Ver mais resultados" cortado ou encoberto').toBe(true);
  });

  test('C) "Ver mais resultados" leva à página /busca/', async ({ page }) => {
    await abrirPainelEBuscar(page, '/');

    // A resposta do REST chega antes de o dropdown renderizar; clicar nesse
    // intervalo não navega. Espera o que o visitante espera: os resultados.
    await expect(page.locator('[class*="jet-ajax-search__source-results-holder_bit_"]').first()).toBeVisible();

    await Promise.all([
      page.waitForURL(/\/busca\/\?/),
      page.locator('.jet-ajax-search__full-results:visible').first().click(),
    ]);

    await expect(page.locator('.bit-busca__title')).toBeVisible();
    await expect(page.locator('.bit-busca__section--main .bit-busca__item').first()).toBeVisible();
    await expect(page.locator('.bit-busca__section--atlas-pages .bit-busca__item').first()).toBeVisible();
    await expect(page.locator('.bit-busca__section--atlas-artists .bit-busca__item').first()).toBeVisible();
  });

  test('D) /busca/ não é cacheável e /?s= segue redirecionando', async ({ request }) => {
    for (const caminho of ['/busca/?s=' + encodeURIComponent(TERMO), '/en/busca/?s=' + encodeURIComponent(TERMO), '/busca/']) {
      const r = await request.get(BASE_URL + caminho, { maxRedirects: 0 });
      expect(r.status(), caminho).toBe(200);
      expect(r.headers()['cache-control'] || '', caminho).toContain('no-store');
    }

    const nativa = await request.get(BASE_URL + '/?s=' + encodeURIComponent(TERMO), { maxRedirects: 0 });
    expect(nativa.status(), 'busca nativa deixou de redirecionar').toBe(301);

    // HTML em cache de antes da página /busca/ ainda manda o "Ver mais" para /?s=…
    const legado = await request.get(BASE_URL + '/?s=' + encodeURIComponent(TERMO) + '&jet_ajax_search_settings=%7B%7D', { maxRedirects: 0 });
    expect(legado.status()).toBe(302);
    expect(legado.headers()['location'] || '').toContain('/busca/?s=');
  });

  test('E) no /cultura/ o painel existe e busca no REST do blog 1', async ({ page }) => {
    const { resposta } = await abrirPainelEBuscar(page, '/cultura/');

    expect(resposta.url(), 'a busca do blog 2 deveria ir ao REST do blog 1').not.toContain('/cultura/wp-json/');
    await expect(page.locator('[class*="jet-ajax-search__source-results-holder_bit_"]').first()).toBeVisible();
  });

  test('F) resultado de artista abre o popup dele no mapa do Atlas', async ({ page, context }) => {
    await abrirPainelEBuscar(page, '/');

    const link = page.locator('.jet-ajax-search__source-results-holder_bit_atlas_artists a').first();
    await expect(link).toBeVisible();

    const href = await link.getAttribute('href');
    expect(href, 'link do artista sem o fragmento do popup').toMatch(/#atlas-artista-\d+$/);

    const nome = (await link.locator('.jet-ajax-search__item-title').textContent()).trim();

    // O widget abre resultados em nova aba (show_result_new_tab); seguir as duas formas.
    let atlas = page;
    if ((await link.getAttribute('target')) === '_blank') {
      [atlas] = await Promise.all([context.waitForEvent('page'), link.click()]);
    } else {
      await link.click();
    }
    await atlas.waitForLoadState('load');

    // O conteúdo do popup chega por um REST separado (get-map-marker-info).
    await expect(atlas.locator('.leaflet-popup-content'), 'popup do artista não abriu — API do JetEngine Maps mudou?')
      .toContainText(nome, { timeout: 30000 });
  });
});
