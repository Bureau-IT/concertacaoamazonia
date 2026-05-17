'use strict';

const { test, expect } = require('@playwright/test');
const { MENU_PAGES, gotoAndWait } = require('./helpers');

/**
 * Padrões que indicam URLs locais, de desenvolvimento ou de tunnel
 * que não deveriam aparecer em produção.
 */
const LOCAL_PATTERNS = [
  /localhost/i,
  /127\.0\.0\.\d+/i,
  /192\.168\.\d+\.\d+/i,
  /10\.\d+\.\d+\.\d+/i,
  /172\.(1[6-9]|2\d|3[01])\.\d+\.\d+/i,
  /\.local(:\d+)?/i,
  /\.dev(:\d+)?(?=[/"']|$)/i,
  /\.test(:\d+)?(?=[/"']|$)/i,
  /\.loca\.lt/i,           // localtunnel
  /ngrok\.io/i,            // ngrok
  /ngrok-free\.app/i,
  /serveo\.net/i,          // serveo
  /lhr\.rocks/i,           // localhero
  /trycloudflare\.com/i,   // cloudflare tunnel temporário
  /\.tunnel\./i,
  /:\d{4,5}(?=[/"']|$)/,  // porta explícita (ex: :8080, :3000) — suspeito em prod
];

/**
 * Verifica se uma URL é suspeita de ser local/tunnel.
 * Ignora URLs de assets de CDN legítimos (fonts, googleapis, etc.).
 */
function isLocalOrTunnel(href) {
  if (!href) return false;
  // Ignora data URIs, ancora pura, mailto, tel
  if (/^(data:|#|mailto:|tel:|javascript:)/i.test(href)) return false;
  return LOCAL_PATTERNS.some((re) => re.test(href));
}

test.describe('Links locais/tunnel em produção', () => {
  for (const pagePath of MENU_PAGES) {
    if (pagePath.includes('#')) continue; // hash-only: mesma página

    test(`sem links locais: ${pagePath}`, async ({ page }) => {
      await gotoAndWait(page, pagePath);

      // Coleta todos os hrefs da página
      const localLinks = await page.evaluate((patterns) => {
        const anchors = Array.from(document.querySelectorAll('a[href]'));
        const found = [];

        anchors.forEach((a) => {
          const href = a.getAttribute('href') || '';
          const absoluteHref = a.href || '';
          const text = (a.textContent || '').trim().slice(0, 80);

          // Testa href relativo e absoluto
          const patternsRe = patterns.map((p) => new RegExp(p.source, p.flags));
          const isLocal = patternsRe.some((re) => re.test(href) || re.test(absoluteHref));

          if (isLocal) {
            found.push({ href, absolute: absoluteHref, text });
          }
        });

        return found;
      }, LOCAL_PATTERNS.map((p) => ({ source: p.source, flags: p.flags })));

      // Também verifica src de iframes, scripts, imagens
      const localAssets = await page.evaluate((patterns) => {
        const elements = [
          ...Array.from(document.querySelectorAll('img[src], script[src], iframe[src], link[href]')),
        ];
        const found = [];
        const patternsRe = patterns.map((p) => new RegExp(p.source, p.flags));

        elements.forEach((el) => {
          const attr = el.getAttribute('src') || el.getAttribute('href') || '';
          const abs = el.src || el.href || '';
          const isLocal = patternsRe.some((re) => re.test(attr) || re.test(abs));
          if (isLocal) {
            found.push({
              tag: el.tagName.toLowerCase(),
              attr,
              absolute: abs,
            });
          }
        });

        return found;
      }, LOCAL_PATTERNS.map((p) => ({ source: p.source, flags: p.flags })));

      const allIssues = [
        ...localLinks.map((l) => `[link] "${l.text}" → ${l.href || l.absolute}`),
        ...localAssets.map((a) => `[${a.tag}] ${a.attr || a.absolute}`),
      ];

      if (allIssues.length > 0) {
        console.error(`Links locais/tunnel encontrados em ${pagePath}:\n${allIssues.join('\n')}`);
      }

      expect(
        allIssues,
        `Links locais/tunnel em ${pagePath}:\n${allIssues.join('\n')}`
      ).toHaveLength(0);
    });
  }
});
