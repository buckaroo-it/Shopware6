const { test, expect } = require('@playwright/test');

/**
 * Cheap smoke checks. These catch a broken plugin bundle or a fatal in a
 * storefront subscriber before the heavier checkout specs run.
 */
test.describe('storefront smoke', () => {
  test('home page renders', async ({ page }) => {
    const res = await page.goto('/');
    expect(res.status()).toBe(200);
    await expect(page.locator('body')).toBeVisible();
  });

  test('buckaroo storefront bundle is served', async ({ page }) => {
    await page.goto('/');
    const srcs = await page.locator('script[src]').evaluateAll(
      (els) => els.map((e) => e.getAttribute('src'))
    );
    const bk = srcs.filter((s) => s && s.includes('buckaroo'));
    expect(bk.length, 'no buckaroo js bundle referenced on the page').toBeGreaterThan(0);

    // The asset must actually resolve; a stale theme build returns 404 here.
    const res = await page.request.get(bk[0]);
    expect(res.status(), `buckaroo bundle ${bk[0]} not served`).toBe(200);
  });

  test('no php fatals surfaced in the page', async ({ page }) => {
    await page.goto('/');
    const html = await page.content();
    expect(html).not.toContain('Fatal error');
    expect(html).not.toContain('Uncaught');
  });
});
