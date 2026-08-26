const { expect } = require('@playwright/test');

/** Pick the first option of a <select> that has a non-empty value. */
async function selectFirstReal(scope, name) {
  const sel = scope.locator(`select[name="${name}"]`);
  if (!(await sel.count())) return;
  const value = await sel.locator('option').evaluateAll(
    (opts) => (opts.find((o) => o.value && o.value.trim() !== '') || {}).value
  );
  if (value) await sel.selectOption(value);
}

/** Prefer a country without mandatory state selection. */
async function selectPreferredCountry(scope) {
  const sel = scope.locator('select[name="billingAddress[countryId]"]');
  if (!(await sel.count())) return;
  const wanted = ['Netherlands', 'Niederlande', 'Germany', 'Deutschland'];
  const value = await sel.locator('option').evaluateAll((opts, names) => {
    const hit = opts.find((o) => o.value && names.some((n) => o.textContent.trim().includes(n)));
    const any = opts.find((o) => o.value && o.value.trim() !== '');
    return (hit || any || {}).value;
  }, wanted);
  if (value) await sel.selectOption(value);
}


/**
 * Fills whichever of the candidate field names this Shopware version renders.
 * 6.5 uses top-level firstName/lastName; 6.6+ moved them under
 * billingAddress[...]. Returns the name that matched.
 */
async function fillAny(scope, names, value) {
  for (const name of names) {
    const el = scope.locator(`input[name="${name}"]`);
    if (await el.count()) {
      await el.first().fill(value);
      return name;
    }
  }
  throw new Error(`none of these fields exist on the register form: ${names.join(', ')}`);
}

function uniqueEmail() {
  return `bk-e2e-${Date.now()}-${Math.floor(Math.random() * 10000)}@example.com`;
}

/**
 * Registers a fresh customer and leaves the browser logged in.
 *
 * Every field is scoped to the registration form: /account/register also
 * renders the login form, whose `password` input would otherwise be filled
 * instead, leaving registration's own password empty.
 *
 * Field names follow Shopware 6.5-6.7, where the personal block reuses the
 * billingAddress[...] names. `shopware_surname_confirm` is a honeypot and is
 * deliberately left untouched.
 */
async function registerCustomer(page) {
  const email = uniqueEmail();
  await page.goto('/account/register');
  const form = page.locator('form[action*="/account/register"]').first();
  await expect(form, 'registration form not found').toBeVisible();

  await selectFirstReal(form, 'salutationId');
  await fillAny(form, ['billingAddress[firstName]', 'firstName'], 'Buckaroo');
  await fillAny(form, ['billingAddress[lastName]', 'lastName'], 'E2E');
  await form.locator('input[name="email"]').fill(email);
  await form.locator('input[name="password"]').fill('BuckarooE2E!2026');
  await form.locator('input[name="billingAddress[street]"]').fill('Teststraat 1');
  const zip = form.locator('input[name="billingAddress[zipcode]"]');
  if (await zip.count()) await zip.fill('1234AB');
  await form.locator('input[name="billingAddress[city]"]').fill('Amsterdam');
  await selectPreferredCountry(form);

  await form.locator('button[type="submit"]').first().click();
  await page.waitForLoadState('domcontentloaded');

  if (/\/account\/register/.test(page.url())) {
    const alerts = await page.locator('.alert, .invalid-feedback, .form-field-error')
      .evaluateAll((els) => els.map((e) => e.textContent.trim()).filter(Boolean));
    const invalid = await page.locator('.is-invalid, [aria-invalid="true"]')
      .evaluateAll((els) => els.map((e) => e.getAttribute('name') || e.id).filter(Boolean));
    throw new Error(
      `customer registration failed. invalid=${JSON.stringify(invalid)} messages=${alerts.join(' | ') || '(none)'}`
    );
  }
  return email;
}

/**
 * Finds a product detail page without relying on clickable navigation, which
 * is fragile across themes and viewports. Set PRODUCT_PATH to skip discovery.
 */
async function gotoProduct(page) {
  if (process.env.PRODUCT_PATH) {
    await page.goto(process.env.PRODUCT_PATH);
    await expect(page.locator('button.btn-buy').first()).toBeAttached();
    return;
  }

  await page.goto('/');
  const base = new URL(page.url()).origin;
  const skip = /\.(png|jpe?g|svg|webp|css|js|ico|woff2?)|\/media\/|\/theme\/|\/account|\/checkout|\/widgets|#/i;

  const hrefs = await page.locator('a[href]').evaluateAll((els) =>
    els.map((e) => e.getAttribute('href')).filter(Boolean)
  );
  const candidates = [...new Set(hrefs)]
    .map((h) => (h.startsWith('http') ? h : base + (h.startsWith('/') ? h : '/' + h)))
    .filter((h) => h.startsWith(base) && !skip.test(h));

  // A product link may already be on the home page; otherwise walk listings.
  for (const url of candidates.slice(0, 6)) {
    await page.goto(url);
    const buy = page.locator('button.btn-buy');
    if (await buy.count()) return; // already a product detail page

    const product = await page
      .locator('.product-box a[href], a.product-name, a.product-image-link')
      .evaluateAll((els) => els.map((e) => e.href).filter(Boolean));
    if (product.length) {
      await page.goto(product[0]);
      await expect(page.locator('button.btn-buy').first()).toBeAttached();
      return;
    }
  }
  throw new Error('could not locate a product detail page; set PRODUCT_PATH');
}

async function addToCartAndConfirm(page) {
  await gotoProduct(page);
  await page.locator('button.btn-buy').first().click();
  await page.waitForLoadState('domcontentloaded');
  await page.goto('/checkout/confirm');
  await page.waitForLoadState('domcontentloaded');
}

module.exports = { registerCustomer, gotoProduct, addToCartAndConfirm, uniqueEmail };
