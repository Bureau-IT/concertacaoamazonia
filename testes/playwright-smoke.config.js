// Config Playwright isolada SÓ para smoke (não compartilha userDataDir com MCP)
const { defineConfig, devices } = require('@playwright/test');

module.exports = defineConfig({
  testDir: './tests',
  testMatch: '_smoke-green.spec.js',
  timeout: 90 * 1000,
  expect: { timeout: 15000 },
  retries: 1,
  workers: 1,
  reporter: [['list']],
  use: {
    actionTimeout: 15000,
    navigationTimeout: 60000,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    // Playwright browser() já é isolado por default (não usa userDataDir
    // persistente como MCP Chrome). Sem flag custom -- conflito evitado.
  },
  projects: [{ name: 'chromium-isolated', use: { ...devices['Desktop Chrome'] } }],
});
