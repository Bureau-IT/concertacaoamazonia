const { chromium } = require('playwright');
(async () => {
  const b = await chromium.launch();
  const ctx = await b.newContext({ ignoreHTTPSErrors: true, viewport:{width:1600,height:1000} });
  const page = await ctx.newPage();
  await page.goto(process.argv[2], { waitUntil: 'networkidle', timeout: 60000 });
  const out = await page.evaluate(() => {
    const map = {};
    document.querySelectorAll('body *').forEach(el => {
      // apenas elementos com TEXTO DIRETO visivel
      const direct = [...el.childNodes].filter(n=>n.nodeType===3 && n.textContent.trim()).map(n=>n.textContent.trim()).join(' ');
      if (!direct) return;
      const r = el.getBoundingClientRect();
      if (r.width === 0 || r.height === 0) return;
      if (el.closest('.cmplz-cookiebanner, #cmplz-cookiebanner-container, .bureau-a11y, #wpadminbar')) return;
      const first = getComputedStyle(el).fontFamily.split(',')[0].replace(/["']/g,'').trim();
      map[first] = map[first] || {count:0, samples:[]};
      map[first].count++;
      if (map[first].samples.length < 6) map[first].samples.push(el.tagName+'['+(el.className||'').toString().split(' ').slice(0,2).join('.')+'] :: '+direct.slice(0,50));
    });
    return map;
  });
  console.log(JSON.stringify(out, null, 2));
  await b.close();
})();
