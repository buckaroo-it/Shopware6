const { test, expect } = require('@playwright/test');
const { registerCustomer, addToCartAndConfirm } = require('../helpers/shop');

/**
 * Guards the payment step, which is where Shopware template drift has broken
 * this plugin before:
 *
 *  - 6.7 removed component/payment/payment-fields.html.twig and its
 *    `component_payment_method` block. The override moved to
 *    payment-form.html.twig / `component_payment_form_list`.
 *  - The [data-bk-required-message] holder and window.buckaroo_back_link are
 *    emitted by that override. When it dies they vanish silently and the JS
 *    falls back to a hardcoded English string.
 *
 * These assertions fail loudly if that override stops being applied.
 */
// Serial + a single shared page: registering a customer and filling a cart is
// the slow part, so do it once for the whole group instead of per test.
test.describe.configure({ mode: 'serial' });

test.describe('checkout payment step', () => {
  let page;

  test.beforeAll(async ({ browser }) => {
    // Registration + cart on a cold 6.5/6.6 shop can take minutes on first
    // hit (theme cache, container warm-up), well past the per-test timeout.
    test.setTimeout(360_000);
    page = await browser.newPage();
    // Warm the storefront so the first real interaction is not paying for
    // cache generation.
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await registerCustomer(page);
    await addToCartAndConfirm(page);
  });

  test.afterAll(async () => {
    await page?.close();
  });

  test('payment methods render and buckaroo methods are present', async () => {
    await expect(page).toHaveURL(/\/checkout\/confirm/);
    const radios = page.locator('.payment-method-input');
    await expect(radios.first()).toBeAttached();

    // Buckaroo's payment-method override tags each radio's wrapper with
    // bk-<key>, e.g. bk-ideal. Shopware's own methods (invoice, prepayment,
    // cash on delivery) go through the same override and come out with a bare
    // `bk-` and no key, so presence of the prefix proves nothing: a page-wide
    // [class*="bk-"] counted all 33 methods on a real shop. Require a
    // non-empty key, which only a rendered buckaroo method produces.
    const buckarooKeys = await radios.evaluateAll((els) =>
      els
        .map((el) => {
          const wrap = el.parentElement;
          const match = (wrap && wrap.className || '').match(/(?:^|\s)bk-([A-Za-z0-9_]+)(?:\s|$)/);
          return match ? match[1] : null;
        })
        .filter(Boolean)
    );
    expect(
      buckarooKeys.length,
      'no buckaroo payment method rendered (payment-method override not applied)'
    ).toBeGreaterThan(0);
  });

  test('buckaroo required-message holder is rendered (payment-form override alive)', async () => {
    const holder = page.locator('[data-bk-required-message]');
    await expect(
      holder,
      'payment-form.html.twig override is not applied - the translated validation message is lost'
    ).toBeAttached();

    const msg = await holder.first().getAttribute('data-bk-required-message');
    expect(msg && msg.trim().length, 'required-message attribute is empty').toBeTruthy();
  });

  test('selected payment method is preselected and listed first', async () => {
    const inputs = page.locator('.payment-method-input');
    // Guard the sample itself: with no radios the index check below is vacuous.
    expect(await inputs.count(), 'no payment method radios rendered').toBeGreaterThan(0);

    const checkedIndex = await inputs.evaluateAll((els) => els.findIndex((e) => e.checked));
    // Shopware always preselects the sales channel default, so -1 means the
    // payment list failed to render its selection - that is a failure, not an
    // acceptable outcome, and accepting it made this test unfalsifiable.
    expect(checkedIndex, 'no payment method is preselected').toBeGreaterThanOrEqual(0);
    expect(checkedIndex, 'preselected payment method is not listed first').toBe(0);
  });
});
