'use strict';

/**
 * generate-report.js
 * Lê test-results/results.json (Playwright JSON reporter) e gera
 * relatório HTML no padrão Bureau IT.
 *
 * Saída: /Users/dcambria/scripts/reports/03 - Web/concertacao/relatorio-testes-playwright-YYYY-MM-DD.html
 */

const fs = require('fs');
const path = require('path');

const RESULTS_FILE = path.join(__dirname, 'test-results', 'results.json');
const REPORTS_DIR = '/Users/dcambria/scripts/reports/03 - Web/concertacao';

const today = new Date().toISOString().slice(0, 10);
const now = new Date().toLocaleString('pt-BR', { timeZone: 'America/Sao_Paulo' });
const OUTPUT_FILE = path.join(REPORTS_DIR, `relatorio-testes-playwright-${today}.html`);

// ─── Leitura dos resultados ───────────────────────────────────────────────────

if (!fs.existsSync(RESULTS_FILE)) {
  console.error(`[generate-report] Arquivo não encontrado: ${RESULTS_FILE}`);
  console.error('Execute os testes primeiro: npm test');
  process.exit(1);
}

const raw = JSON.parse(fs.readFileSync(RESULTS_FILE, 'utf8'));

// ─── Processamento ────────────────────────────────────────────────────────────

/** @type {{ title: string, status: string, error: string|null, file: string, duration: number }[]} */
const allTests = [];
let totalPassed = 0;
let totalFailed = 0;
let totalSkipped = 0;

for (const suite of (raw.suites || [])) {
  processSuite(suite, suite.file || suite.title || '');
}

function processSuite(suite, file) {
  for (const spec of (suite.specs || [])) {
    for (const test of (spec.tests || [])) {
      const status = test.status || 'unknown';
      const error = test.results?.[0]?.error?.message || null;
      const duration = test.results?.[0]?.duration || 0;

      allTests.push({
        title: spec.title,
        status,
        error,
        file: path.basename(file),
        duration,
      });

      if (status === 'expected') totalPassed++;
      else if (status === 'unexpected') totalFailed++;
      else if (status === 'skipped') totalSkipped++;
    }
  }
  for (const child of (suite.suites || [])) {
    processSuite(child, file);
  }
}

// Agrupa por arquivo de teste
const byFile = {};
for (const t of allTests) {
  if (!byFile[t.file]) byFile[t.file] = [];
  byFile[t.file].push(t);
}

// ─── Mapeamento de mitigações ─────────────────────────────────────────────────

const MITIGACOES = [
  { pattern: /is not defined/i, suggestion: 'Verificar carregamento de script JS — possível dependência ausente ou erro de plugin' },
  { pattern: /Failed to load resource/i, suggestion: 'Verificar URL do asset; possível problema de CDN ou caminho quebrado' },
  { pattern: /HTTP.*404|status.*404/i, suggestion: 'Verificar permalink no WP Admin; possível slug alterado ou página não publicada' },
  { pattern: /lang=.*pt-BR.*\/en\//i, suggestion: 'WPML não está traduzindo esta página — verificar se há tradução cadastrada no painel WPML' },
  { pattern: /hreflang.*ausente/i, suggestion: 'Verificar configuração de SEO multilingual no WPML → Languages → SEO' },
  { pattern: /download.*timeout|Download não iniciou/i, suggestion: 'Verificar se o arquivo existe no servidor; possível problema de S3/CDN' },
  { pattern: /formulário.*sucesso|form.*success/i, suggestion: 'Verificar plugin de formulário; possível reCAPTCHA bloqueando; verificar logs PHP' },
  { pattern: /GTM.*não encontrado/i, suggestion: 'Verificar se WP_ENVIRONMENT_TYPE=production está setado; verificar mu-plugin bit-gtm.php ativo' },
  { pattern: /link.*local|localhost|tunnel/i, suggestion: 'URL de ambiente local/tunnel encontrada em produção — verificar search-replace após deploy' },
];

function getMitigacao(errorMsg) {
  if (!errorMsg) return null;
  for (const { pattern, suggestion } of MITIGACOES) {
    if (pattern.test(errorMsg)) return suggestion;
  }
  return null;
}

// ─── Seção HTML por arquivo ───────────────────────────────────────────────────

const TEST_FILE_LABELS = {
  '01-pages-smoke.spec.js': '01 — Smoke (HTTP 200 + conteúdo)',
  '02-console-errors.spec.js': '02 — Erros de Console JS',
  '03-gtm.spec.js': '03 — GTM GTM-PPHN5B6',
  '04-downloads.spec.js': '04 — Downloads',
  '05-contact-form.spec.js': '05 — Formulário de Contato',
  '06-wpml-en.spec.js': '06 — WPML EN (páginas em inglês)',
  '07-local-links.spec.js': '07 — Links Locais / Tunnel',
};

function statusIcon(status) {
  if (status === 'expected') return '<span class="pass">✓</span>';
  if (status === 'unexpected') return '<span class="fail">✗</span>';
  if (status === 'skipped') return '<span class="skip">⊝</span>';
  return '<span class="unknown">?</span>';
}

function renderSection(file, tests) {
  const label = TEST_FILE_LABELS[file] || file;
  const passed = tests.filter((t) => t.status === 'expected').length;
  const failed = tests.filter((t) => t.status === 'unexpected').length;
  const skipped = tests.filter((t) => t.status === 'skipped').length;

  const rows = tests.map((t) => {
    const mitigation = getMitigacao(t.error || t.title);
    const errorCell = t.error
      ? `<td class="error-msg">${escHtml(t.error.slice(0, 300))}${mitigation ? `<br><span class="mitigation">💡 ${escHtml(mitigation)}</span>` : ''}</td>`
      : '<td>—</td>';

    return `
      <tr class="${t.status === 'unexpected' ? 'row-fail' : t.status === 'skipped' ? 'row-skip' : ''}">
        <td>${statusIcon(t.status)}</td>
        <td class="test-title">${escHtml(t.title)}</td>
        <td class="duration">${(t.duration / 1000).toFixed(1)}s</td>
        ${errorCell}
      </tr>`;
  }).join('');

  return `
    <section class="test-section">
      <h2>${escHtml(label)} <span class="badge pass">${passed} ✓</span> ${failed > 0 ? `<span class="badge fail">${failed} ✗</span>` : ''} ${skipped > 0 ? `<span class="badge skip">${skipped} ⊝</span>` : ''}</h2>
      <table>
        <thead>
          <tr><th>Status</th><th>Teste</th><th>Duração</th><th>Erro / Observação</th></tr>
        </thead>
        <tbody>${rows}</tbody>
      </table>
    </section>`;
}

// ─── Consolidado de erros ─────────────────────────────────────────────────────

const failedTests = allTests.filter((t) => t.status === 'unexpected');
const consolidadoHTML = failedTests.length === 0
  ? '<p class="no-errors">Nenhuma falha detectada. ✓</p>'
  : failedTests.map((t) => {
      const mitigation = getMitigacao(t.error || t.title);
      return `
        <div class="error-item">
          <strong>${escHtml(t.file)} — ${escHtml(t.title)}</strong>
          ${t.error ? `<pre>${escHtml(t.error.slice(0, 500))}</pre>` : ''}
          ${mitigation ? `<p class="mitigation">💡 ${escHtml(mitigation)}</p>` : ''}
        </div>`;
    }).join('');

// ─── Sugestões de melhoria ────────────────────────────────────────────────────

const skippedTests = allTests.filter((t) => t.status === 'skipped');
const sugestoesHTML = skippedTests.length === 0
  ? '<p>Sem itens pulados.</p>'
  : skippedTests.map((t) => `<li><strong>${escHtml(t.file)}</strong> — ${escHtml(t.title)}</li>`).join('');

// ─── Seções por arquivo ───────────────────────────────────────────────────────

const sectionsHTML = Object.entries(byFile)
  .sort(([a], [b]) => a.localeCompare(b))
  .map(([file, tests]) => renderSection(file, tests))
  .join('\n');

// ─── HTML final ───────────────────────────────────────────────────────────────

function escHtml(str) {
  return String(str || '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

const totalTests = totalPassed + totalFailed + totalSkipped;
const overallStatus = totalFailed === 0 ? 'PASSOU' : 'FALHOU';
const overallClass = totalFailed === 0 ? 'pass' : 'fail';

const html = `<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Relatório Playwright — concertacaoamazonia.com.br — ${today}</title>
  <style>
    :root {
      --bit-green: #3dba7a;
      --bit-red: #e05260;
      --bit-blue: #3c7ec8;
      --bit-orange: #f5a623;
      --bit-gray: #2e3440;
      --bit-light: #eceff4;
      --bit-skip: #888;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Segoe UI', system-ui, sans-serif; background: #f0f2f5; color: #333; font-size: 14px; }
    header { background: var(--bit-gray); color: #fff; padding: 24px 32px; }
    header h1 { font-size: 1.5rem; font-weight: 700; }
    header .meta { margin-top: 6px; opacity: 0.75; font-size: 0.85rem; }
    .status-bar { display: flex; gap: 16px; padding: 16px 32px; background: #fff; border-bottom: 3px solid var(--bit-gray); flex-wrap: wrap; align-items: center; }
    .status-badge { font-size: 1.1rem; font-weight: 700; padding: 8px 20px; border-radius: 6px; }
    .status-badge.pass { background: var(--bit-green); color: #fff; }
    .status-badge.fail { background: var(--bit-red); color: #fff; }
    .stat { font-size: 0.9rem; }
    .stat span { font-weight: bold; }
    main { max-width: 1200px; margin: 24px auto; padding: 0 24px; }
    .test-section { background: #fff; border-radius: 8px; padding: 20px; margin-bottom: 24px; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
    .test-section h2 { font-size: 1rem; margin-bottom: 12px; border-bottom: 1px solid #e5e5e5; padding-bottom: 8px; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #f7f8fa; text-align: left; padding: 8px 10px; font-size: 0.8rem; text-transform: uppercase; letter-spacing: .5px; color: #666; }
    td { padding: 8px 10px; border-bottom: 1px solid #f0f0f0; vertical-align: top; }
    tr.row-fail { background: #fff5f5; }
    tr.row-skip { background: #fafafa; color: #888; }
    .test-title { font-size: 0.85rem; word-break: break-word; }
    .duration { color: #999; white-space: nowrap; }
    .error-msg { font-size: 0.8rem; color: var(--bit-red); word-break: break-word; }
    .mitigation { color: var(--bit-blue); font-style: italic; margin-top: 4px; display: block; font-size: 0.8rem; }
    .badge { display: inline-block; border-radius: 12px; padding: 2px 10px; font-size: 0.75rem; margin-left: 6px; }
    .badge.pass { background: #e6f9f0; color: #1a7a4a; }
    .badge.fail { background: #fde8ea; color: #b0293a; }
    .badge.skip { background: #f0f0f0; color: #888; }
    .pass { color: var(--bit-green); }
    .fail { color: var(--bit-red); }
    .skip { color: var(--bit-skip); }
    .unknown { color: var(--bit-orange); }
    section.errors { background: #fff; border-radius: 8px; padding: 20px; margin-bottom: 24px; box-shadow: 0 1px 4px rgba(0,0,0,.08); border-left: 4px solid var(--bit-red); }
    section.errors h2 { color: var(--bit-red); margin-bottom: 12px; }
    .error-item { padding: 10px 0; border-bottom: 1px solid #f0f0f0; }
    .error-item:last-child { border-bottom: none; }
    .error-item pre { background: #f7f8fa; padding: 8px; border-radius: 4px; font-size: 0.75rem; white-space: pre-wrap; word-break: break-word; margin-top: 6px; }
    .no-errors { color: var(--bit-green); font-weight: 600; }
    section.suggestions { background: #fff; border-radius: 8px; padding: 20px; margin-bottom: 24px; box-shadow: 0 1px 4px rgba(0,0,0,.08); border-left: 4px solid var(--bit-orange); }
    section.suggestions h2 { color: var(--bit-orange); margin-bottom: 12px; }
    section.suggestions li { margin-left: 20px; margin-bottom: 6px; font-size: 0.85rem; }
    footer { text-align: center; padding: 24px; color: #999; font-size: 0.8rem; }
  </style>
</head>
<body>
  <header>
    <h1>Relatório de Testes Playwright — concertacaoamazonia.com.br</h1>
    <div class="meta">
      Gerado em: ${escHtml(now)} &nbsp;|&nbsp;
      Site: <a href="https://concertacaoamazonia.com.br" style="color:#8fbcdb">https://concertacaoamazonia.com.br</a> &nbsp;|&nbsp;
      Browser: Chromium (Desktop)
    </div>
  </header>

  <div class="status-bar">
    <div class="status-badge ${overallClass}">${overallStatus}</div>
    <div class="stat">Total: <span>${totalTests}</span></div>
    <div class="stat" style="color:var(--bit-green)">Passou: <span>${totalPassed}</span></div>
    <div class="stat" style="color:var(--bit-red)">Falhou: <span>${totalFailed}</span></div>
    <div class="stat" style="color:var(--bit-skip)">Pulados: <span>${totalSkipped}</span></div>
  </div>

  <main>
    ${sectionsHTML}

    <section class="errors">
      <h2>✗ Erros Encontrados (${failedTests.length})</h2>
      ${consolidadoHTML}
    </section>

    <section class="suggestions">
      <h2>⊝ Testes Pulados / Sugestões de Melhoria</h2>
      ${skippedTests.length === 0 ? '<p>Nenhum teste pulado.</p>' : `<ul>${sugestoesHTML}</ul>`}
    </section>
  </main>

  <footer>
    Bureau de Tecnologia Ltda. &mdash; Relatório gerado automaticamente por Playwright Suite
  </footer>
</body>
</html>`;

// ─── Salvar ───────────────────────────────────────────────────────────────────

fs.mkdirSync(REPORTS_DIR, { recursive: true });
fs.writeFileSync(OUTPUT_FILE, html, 'utf8');

console.log(`\n✓ Relatório salvo em:\n  ${OUTPUT_FILE}\n`);
console.log(`  Total: ${totalTests} | Passou: ${totalPassed} | Falhou: ${totalFailed} | Pulados: ${totalSkipped}`);
