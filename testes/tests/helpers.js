'use strict';

const BASE_URL = process.env.BASE_URL || 'https://concertacaoamazonia.com.br';

const GTM_ID = 'GTM-PPHN5B6';

/** 25 páginas do menu principal PT */
const MENU_PAGES = [
  '/',
  '/sobre-nos/',
  '/sobre-nos/#nucleogovernanca',
  '/sobre-nos/5-pilares/',
  '/agenda-integradora/',
  '/sobre-nos/4-amazonias/',
  '/atuacao/',
  '/atuacao/encontros/',
  '/atuacao/grupos-de-trabalho/',
  '/atuacao/iniciativas-estruturantes/',
  '/atuacao/atuacao-internacional/',
  '/atuacao/faq/',
  '/conhecimento/',
  '/conhecimento/publicacoes/',
  '/conhecimento/espiral-de-conhecimento/',
  '/conhecimento/mapa-das-plataformas/',
  '/conhecimento/entrevistas/',
  '/cultura/',
  '/cultura/linha-do-tempo/',
  '/cultura/atlas-cultural-das-amazonias/',
  '/cultura/galeria/',
  '/cultura/porosidades/',
  '/cultura/exposicao-cores-do-futuro/',
  '/cultura/poeticas-do-possivel/',
  '/contato/',
];

/** 21 páginas do menu EN (WPML) */
const MENU_PAGES_EN = [
  '/en/what-we-are/',
  '/en/what-we-are/#nucleogovernanca',
  '/en/what-we-are/5-pillars/',
  '/en/what-we-are/4-amazons/',
  '/en/agenda-integradora/',
  '/en/activities/',
  '/en/activities/news/',
  '/en/activities/workgroups/',
  '/en/activities/projetos-estruturantes/',
  '/en/activities/international-activities/',
  '/en/activities/faq/',
  '/en/cultura/',
  '/en/cultura/linha-do-tempo/',
  '/en/cultura/atlas-cultural-das-amazonias/',
  '/en/cultura/galeria/',
  '/en/cultura/porosidades/',
  '/en/knowledge/',
  '/en/knowledge/publications/',
  '/en/knowledge/spiral-of-knowledge/',
  '/en/knowledge/platform-map/',
  '/en/contact_us/',
];

/**
 * Padrões de ruído de terceiros — erros de console que não são bugs do site.
 * @type {RegExp[]}
 */
const CONSOLE_NOISE_PATTERNS = [
  /googletagmanager\.com/i,
  /google-analytics\.com/i,
  /analytics\.js/i,
  /gtag/i,
  /wp-rocket/i,
  /JQMIGRATE/i,
  /jquery\.migrate/i,
  /facebook\.net/i,
  /doubleclick\.net/i,
  /cdn\.jsdelivr\.net/i,
  /recaptcha/i,
  /gstatic\.com/i,
  /clarity\.ms/i,
];

/**
 * Converte um path de URL em slug para nome de arquivo.
 * @param {string} path
 * @returns {string}
 */
function slugify(path) {
  return path
    .replace(/^\/|\/$/g, '')
    .replace(/[/#?&=]/g, '-')
    .replace(/-+/g, '-')
    .replace(/^-|-$/g, '') || 'home';
}

/**
 * Navega para path e aguarda lazy load do Elementor.
 * Separa anchor (#) do path de navegação.
 * @param {import('@playwright/test').Page} page
 * @param {string} path - path com possível #anchor
 * @returns {{ response: import('@playwright/test').Response|null, anchor: string|null }}
 */
async function gotoAndWait(page, path) {
  const [cleanPath, anchor] = path.split('#');
  const navPath = cleanPath || '/';

  const response = await page.goto(navPath, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1500);

  return { response, anchor: anchor || null };
}

/**
 * Detecta se o HTML contém indicadores de página de erro WP/404.
 * @param {string} html
 * @returns {boolean}
 */
function isErrorPage(html) {
  const errorPatterns = [
    /page not found/i,
    /404 not found/i,
    /nada encontrado/i,
    /página não encontrada/i,
    /pagina nao encontrada/i,
    /error 404/i,
    /<title[^>]*>.*404.*<\/title>/i,
    /class="error-404"/i,
    /id="error-page"/i,
  ];
  return errorPatterns.some((re) => re.test(html));
}

module.exports = {
  BASE_URL,
  GTM_ID,
  MENU_PAGES,
  MENU_PAGES_EN,
  CONSOLE_NOISE_PATTERNS,
  slugify,
  gotoAndWait,
  isErrorPage,
};
