const { defineConfig, devices } = require('@playwright/test');

/**
 * Shop under test. Locally this is the ddev URL; in CI it is the dockware
 * container. Self-signed certs are expected in both, hence ignoreHTTPSErrors.
 */
const baseURL = process.env.SHOP_BASE_URL || 'https://my-shopware-site.ddev.site';

module.exports = defineConfig({
  testDir: './specs',
  // Shopware cold caches are slow on first hit in CI.
  timeout: 180_000,
  expect: { timeout: 15_000 },
  fullyParallel: false,
  workers: 1,
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : [['list']],
  use: {
    baseURL,
    ignoreHTTPSErrors: true,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
