const { defineConfig, devices } = require('@playwright/test');

try { require('dotenv').config({ path: '.env.local' }); } catch {}

const REPORTS_DIR = '/Users/dcambria/scripts/reports/03 - Web/concertacao';

module.exports = defineConfig({
  testDir: './tests',
  fullyParallel: false,
  retries: 1,
  workers: 1,
  reporter: [
    ['list'],
    ['json', { outputFile: 'test-results/results.json' }],
    ['html', { open: 'never', outputFolder: `${REPORTS_DIR}/playwright-html-report` }],
  ],
  use: {
    baseURL: process.env.BASE_URL || 'https://concertacaoamazonia.com.br',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    actionTimeout: 15000,
    navigationTimeout: 45000,
    ignoreHTTPSErrors: false,
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
  ],
  timeout: 60000,
  expect: { timeout: 15000 },
});
