import { createHmac, randomUUID } from 'node:crypto';
import { expect, test, type APIRequestContext, type Page } from '@playwright/test';
import { addToCart, adminToken, magento, mockApi, sql, stubAdvisor } from './helpers';

// Discounts from bluebarry (magento-internal#6): coupon codes the module makes on bluebarry's command
// (a reward's, a popup's or a quiz's code for one person), a code put on the shopper's cart, and the
// signed offers of quiz results and recommendation blocks, which become one-time codes. A Magento cart
// takes one coupon code: the shopper's own is never pushed out.

const KEY = 'test-api-key';
const productId = (sku: string) => sql(`SELECT entity_id FROM catalog_product_entity WHERE sku = '${sku}'`);
const unique = (prefix: string) => `${prefix}-${randomUUID().slice(0, 8).toUpperCase()}`;

/** bluebarry's command, signed like DataApi's MagentoStoreClient. */
async function command(request: APIRequestContext, name: string, payload?: object) {
  const raw = JSON.stringify({ command: name, payload });
  const at = Math.floor(Date.now() / 1000);
  const response = await request.post('/bluebarry/command/', {
    headers: {
      'Content-Type': 'application/json',
      'X-Bluebarry-Timestamp': String(at),
      'X-Bluebarry-Signature': createHmac('sha256', KEY).update(`${at}.${raw}`).digest('hex'),
    },
    data: raw,
  });
  return { status: response.status(), body: await response.json() };
}

/** The SDK's cart bridge asking the module for a discount. */
async function discount(page: Page, fields: Record<string, string>, withFormKey = true) {
  return page.evaluate(async ({ fields, withFormKey }) => {
    const formKey = decodeURIComponent(document.cookie.match(/form_key=([^;]+)/)?.[1] ?? '');
    const response = await fetch((window as any).barry.magento.discountUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
      body: new URLSearchParams({ ...(withFormKey ? { form_key: formKey } : {}), ...fields }).toString(),
    });
    return { status: response.status, body: await response.json() };
  }, { fields, withFormKey });
}

/** A signed offer as the SDK holds it; the mock API reads it without checking the signature. */
const offer = (payload: object) => `${Buffer.from(JSON.stringify(payload)).toString('base64url')}.signature`;

/** The shopper's cart as Magento has it. */
async function quote(page: Page) {
  const cart = (await (await page.request.get('/customer/section/load/?sections=cart&force_new_section_timestamp=true')).json()).cart;
  const quoteId = sql(`SELECT quote_id FROM quote_item WHERE item_id = ${Number(cart.items?.[0]?.item_id ?? 0)}`);
  if (!quoteId) return { coupon: '', discount: 0, lines: {} as Record<string, number>, allLines: 0 };
  // The code last: an empty first column would be trimmed away.
  const [subtotal, withDiscount, coupon = ''] = sql(`SELECT base_subtotal, base_subtotal_with_discount, IFNULL(coupon_code, '') FROM quote WHERE entity_id = ${quoteId}`).split('\t');
  const lines = Object.fromEntries(sql(`SELECT sku, base_discount_amount FROM quote_item WHERE quote_id = ${quoteId} AND parent_item_id IS NULL`)
    .split('\n').filter(Boolean).map((row) => { const [sku, amount] = row.split('\t'); return [sku, Number(amount)]; }));
  // Every line, parts of a bundle included: nothing may come off a line that is not looked at above.
  const allLines = Number(sql(`SELECT IFNULL(SUM(base_discount_amount), 0) FROM quote_item WHERE quote_id = ${quoteId}`));
  return { coupon, discount: Math.round((Number(subtotal) - Number(withDiscount)) * 100) / 100, lines, allLines };
}

const rule = (code: string) => {
  const row = sql(`SELECT r.rule_id, r.name, r.simple_action, r.discount_amount, r.discount_qty, r.simple_free_shipping, r.stop_rules_processing, r.use_auto_generation, c.usage_limit, c.type
    FROM salesrule_coupon c JOIN salesrule r ON r.rule_id = c.rule_id WHERE c.code = '${code}'`).split('\t');
  return row.length < 10 ? null : {
    id: row[0], name: row[1], action: row[2], amount: Number(row[3]), qty: Number(row[4]), freeShipping: Number(row[5]),
    stops: Number(row[6]), generated: Number(row[7]), usageLimit: Number(row[8]), couponType: Number(row[9]),
  };
};

async function shopper(page: Page) {
  await stubAdvisor(page, null);
  await page.goto('/bb-simple.html');
  await expect.poll(() => page.evaluate(() => (window as any).barry?.magento?.discountUrl)).toContain('/bluebarry/cart/discount');
  // The theme's own script makes the form key once it has loaded; a shopper acts after that (and
  // bluebarry's SDK makes it the same way when it is first).
  await expect.poll(async () => (await page.context().cookies()).some((cookie) => cookie.name === 'form_key')).toBe(true);
}

test.describe('discounts from bluebarry', () => {
  test.describe.configure({ mode: 'serial' });
  let simple = '';
  let red = '';
  let ownRuleId = 0;
  const own = unique('OWN');

  test.beforeAll(async ({ request }) => {
    magento('config:set', 'bluebarry_module/general/api_key', KEY);
    magento('cache:flush');
    simple = productId('bb-simple');
    red = productId('bb-configurable-red');
    // The merchant's own coupon: 5.00 off the cart.
    const headers = { Authorization: `Bearer ${await adminToken(request)}` };
    const created = await request.post('/rest/V1/salesRules', {
      headers,
      data: { rule: { name: 'Merchant coupon', website_ids: [1], customer_group_ids: [0, 1, 2, 3], is_active: true, coupon_type: 'SPECIFIC_COUPON',
        simple_action: 'cart_fixed', discount_amount: 5, uses_per_coupon: 0, uses_per_customer: 0 } },
    });
    expect(created.status(), await created.text()).toBe(200);
    ownRuleId = (await created.json()).rule_id;
    const coupon = await request.post('/rest/V1/coupons', { headers, data: { coupon: { rule_id: ownRuleId, code: own, type: 0, is_primary: true } } });
    expect(coupon.status(), await coupon.text()).toBe(200);
  });

  test.beforeEach(async () => {
    await mockApi.reset();
  });

  test.afterAll(async ({ request }) => {
    await mockApi.reset();
    if (ownRuleId) await request.delete(`/rest/V1/salesRules/${ownRuleId}`, { headers: { Authorization: `Bearer ${await adminToken(request)}` } });
    sql("DELETE FROM salesrule WHERE rule_id IN (SELECT rule_id FROM bluebarry_discount_rule)");
    sql('DELETE FROM bluebarry_discount_rule');
    sql('DELETE FROM bluebarry_discount_code');
    sql("DELETE FROM core_config_data WHERE path = 'bluebarry_module/general/api_key'");
    magento('cache:flush');
  });

  test('bluebarry asks for a code: single-use, under one cart price rule per set of terms', async ({ request }) => {
    expect((await command(request, 'store.info')).body).toEqual({ currency: 'EUR', couponsEnabled: true });

    const first = unique('WELCOME');
    const spec = { code: first, discountType: 'percent', amount: 10, productIds: [], expiresAt: new Date(Date.now() + 14 * 86400000).toISOString(),
      minimumSpend: null, freeShipping: false, email: null, description: 'bluebarry popup code', individualUse: false };
    const made = await command(request, 'coupon.create', spec);
    expect(made.status).toBe(200);
    expect(made.body).toMatchObject({ exists: true, status: 'publish', usageCount: 0, id: expect.any(Number) });
    // Asked again (bluebarry never heard the answer): the same code, not a second one.
    expect((await command(request, 'coupon.create', spec)).body.id).toBe(made.body.id);
    expect(rule(first)).toMatchObject({ name: 'bluebarry: 10% off', action: 'by_percent', amount: 10, freeShipping: 0, stops: 0, generated: 1, usageLimit: 1, couponType: 1 });

    // Another person's code with the same terms lives under the same rule.
    const second = unique('WELCOME');
    expect((await command(request, 'coupon.create', { ...spec, code: second })).status).toBe(200);
    expect(rule(second)!.id).toBe(rule(first)!.id);

    // Other terms, another rule: an amount off the cart with a minimum, free shipping, and one that
    // keeps other cart price rules from applying after it.
    const fixed = unique('REWARD');
    expect((await command(request, 'coupon.create', { ...spec, code: fixed, discountType: 'fixed_cart', amount: 5, minimumSpend: 50, individualUse: true })).status).toBe(200);
    expect(rule(fixed)).toMatchObject({ name: 'bluebarry: 5 off, from 50', action: 'cart_fixed', amount: 5, stops: 1 });
    expect(rule(fixed)!.id).not.toBe(rule(first)!.id);
    const shipping = unique('SHIP');
    expect((await command(request, 'coupon.create', { ...spec, code: shipping, discountType: 'fixed_cart', amount: 0, freeShipping: true })).status).toBe(200);
    expect(rule(shipping)).toMatchObject({ name: 'bluebarry: free shipping', amount: 0, freeShipping: 2 });

    expect((await command(request, 'coupon.get', { code: first })).body).toMatchObject({ exists: true, status: 'publish', usageCount: 0 });
    // Unused, it is taken back, so it cannot be spent; asking again changes nothing.
    expect((await command(request, 'coupon.revoke', { code: first })).body).toEqual({ exists: false, usageCount: 0 });
    expect((await command(request, 'coupon.revoke', { code: first })).body).toEqual({ exists: false, usageCount: 0 });
    expect((await command(request, 'coupon.get', { code: first })).body).toEqual({ exists: false, usageCount: 0 });
    // Not deleted but spent: a checkout that holds the code right now is refused by Magento's own
    // count, where a deleted coupon would let its order through with the discount.
    expect(sql(`SELECT CONCAT(times_used, '/', usage_limit) FROM salesrule_coupon WHERE code = '${first}'`)).toBe('1/1');
    expect(sql(`SELECT revoked FROM bluebarry_discount_code WHERE coupon_id = (SELECT coupon_id FROM salesrule_coupon WHERE code = '${first}')`)).toBe('1');
    // The cron removes it a little later.
    sql(`UPDATE bluebarry_discount_code SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE revoked = 1`);
    expect(magento('bluebarry:discounts:clean')).toContain('1 expired codes');
    // The rule is this bluebarry account's.
    expect(sql(`SELECT tenant_id FROM bluebarry_discount_rule WHERE rule_id = ${rule(second)!.id}`)).not.toBe('');
    expect(rule(first)).toBeNull();
    expect(rule(second)).not.toBeNull();
  });

  test('a code that was taken back no longer goes on a cart', async ({ page, request }) => {
    const code = unique('REWARD');
    expect((await command(request, 'coupon.create', { code, discountType: 'percent', amount: 10, productIds: [] })).status).toBe(200);
    await shopper(page);
    await addToCart(page, 'bb-simple');
    expect((await discount(page, { code })).body).toMatchObject({ applied: true, code: 'applied' });

    // The shopper has it on the cart when bluebarry takes it back (the reward was cancelled).
    expect((await command(request, 'coupon.revoke', { code })).body).toEqual({ exists: false, usageCount: 0 });
    // The next look at the cart drops it, and it cannot be put on again.
    await addToCart(page, 'bb-simple');
    expect(await quote(page)).toMatchObject({ coupon: '', discount: 0 });
    expect((await discount(page, { code })).body).toMatchObject({ applied: false, code: 'waiting' });
    expect(await quote(page)).toMatchObject({ coupon: '' });
  });

  test('a code that cannot be made is refused, and the merchant\'s own coupons are never touched', async ({ request }) => {
    const spec = { code: unique('BAD'), discountType: 'percent', amount: 10, productIds: [] as string[] };
    // The merchant has a coupon with this code already.
    expect((await command(request, 'coupon.create', { ...spec, code: own })).status).toBe(422);
    expect((await command(request, 'coupon.get', { code: own })).body).toEqual({ exists: false, usageCount: 0 });
    expect((await command(request, 'coupon.revoke', { code: own })).body).toEqual({ exists: false, usageCount: 0 });
    expect(sql(`SELECT COUNT(*) FROM salesrule_coupon WHERE code = '${own}'`)).toBe('1');

    // Products that are not in this store must not become a discount on everything.
    expect((await command(request, 'coupon.create', { ...spec, productIds: ['99999991'] })).status).toBe(422);
    expect((await command(request, 'coupon.create', { ...spec, discountType: 'buy_one_get_one' })).status).toBe(422);
    expect((await command(request, 'coupon.create', { ...spec, amount: 0 })).status).toBe(422);
    expect((await command(request, 'coupon.create', { ...spec, code: 'no spaces allowed' })).status).toBe(422);
    expect(rule(spec.code)).toBeNull();
  });

  test('a code goes on the cart, and one given before the first product waits for it', async ({ page, request }) => {
    const code = unique('QUIZ');
    expect((await command(request, 'coupon.create', { code, discountType: 'percent', amount: 10, productIds: [] })).status).toBe(200);
    await shopper(page);

    // An empty cart cannot hold a code in Magento. The module keeps nothing: the SDK holds the code
    // and gives it again once the cart changed.
    expect((await discount(page, { code })).body).toEqual({ applied: false, code: 'waiting', drop: [] });
    // The add to the cart itself is Magento's own, untouched.
    await addToCart(page, 'bb-simple');
    expect(await quote(page)).toMatchObject({ coupon: '' });
    expect((await discount(page, { code })).body).toEqual({ applied: true, code: 'applied', drop: [] });
    expect(await quote(page)).toMatchObject({ coupon: code, discount: 10 });
    // The shopper's cart data names the code: one taken off or put on is a change of the cart for the SDK.
    const section = await page.evaluate(async () => {
      const response = await fetch('/customer/section/load/?sections=cart&force_new_section_timestamp=true', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      return (await response.json()).cart;
    });
    expect(section.bluebarry_coupon).toBe(code);

    // Asked again with the code on the cart: nothing changes.
    expect((await discount(page, { code })).body).toMatchObject({ applied: true, code: 'applied' });
    // A code this store does not have.
    expect((await discount(page, { code: 'NO-SUCH-CODE' })).body).toMatchObject({ applied: false, code: 'unknown' });
    // Without the form key nothing happens.
    expect((await discount(page, { code }, false)).status).toBe(403);
  });

  test('the shopper\'s own code stays; a newer bluebarry code replaces an older one', async ({ page, request }) => {
    const older = unique('POPUP');
    const newer = unique('REWARD');
    expect((await command(request, 'coupon.create', { code: older, discountType: 'percent', amount: 10, productIds: [] })).status).toBe(200);
    expect((await command(request, 'coupon.create', { code: newer, discountType: 'percent', amount: 20, productIds: [] })).status).toBe(200);
    await shopper(page);
    await addToCart(page, 'bb-simple');

    expect((await discount(page, { code: older })).body).toMatchObject({ applied: true, code: 'applied' });
    expect((await discount(page, { code: newer })).body).toMatchObject({ applied: true, code: 'applied' });
    expect(await quote(page)).toMatchObject({ coupon: newer, discount: 20 });

    // The shopper enters their own code in the cart, as the cart page's coupon field does.
    await page.goto('/checkout/cart/');
    await page.evaluate(async (own) => {
      const formKey = decodeURIComponent(document.cookie.match(/form_key=([^;]+)/)?.[1] ?? '');
      await fetch('/checkout/cart/couponPost/', {
        method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ form_key: formKey, coupon_code: own, remove: '0' }).toString(),
      });
    }, own);
    expect(await quote(page)).toMatchObject({ coupon: own, discount: 5 });

    await page.goto('/bb-simple.html');
    expect((await discount(page, { code: older })).body).toMatchObject({ applied: false, code: 'other_code' });
    expect(await quote(page)).toMatchObject({ coupon: own, discount: 5 });
  });

  test('a code below its minimum waits, and the bluebarry code the cart had stays meanwhile', async ({ page, request }) => {
    const small = unique('POPUP');
    const big = unique('REWARD');
    expect((await command(request, 'coupon.create', { code: small, discountType: 'percent', amount: 10, productIds: [] })).status).toBe(200);
    expect((await command(request, 'coupon.create', { code: big, discountType: 'fixed_cart', amount: 50, productIds: [], minimumSpend: 100000 })).status).toBe(200);
    await shopper(page);
    await addToCart(page, 'bb-simple');
    expect((await discount(page, { code: small })).body).toMatchObject({ applied: true });

    expect((await discount(page, { code: big })).body).toMatchObject({ applied: false, code: 'waiting' });
    expect(await quote(page)).toMatchObject({ coupon: small, discount: 10 });
    // The cart changes and still cannot take it: the older code stays on.
    expect((await discount(page, { code: big })).body).toMatchObject({ applied: false, code: 'waiting' });
    expect(await quote(page)).toMatchObject({ coupon: small, discount: 10 });
  });

  test('a code for a product does not come off a bundle that only holds it as a part', async ({ page }) => {
    await shopper(page);
    const part = productId('bb-part-a');
    const grant = offer({ variantIds: [part], rules: [{ scope: 'Single', type: 'Percentage', value: 50 }], nonce: randomUUID() });
    // The part is sold on its own too (30.00), and is a part of both bundles.
    sql(`UPDATE catalog_product_entity_int SET value = 4 WHERE entity_id = ${part} AND attribute_id = (SELECT attribute_id FROM eav_attribute WHERE attribute_code = 'visibility' AND entity_type_id = 4)`);
    try {
      await addToCart(page, 'bb-bundle-fixed', { bundle: true });
      await addToCart(page, 'bb-bundle-dynamic', { bundle: true });
      // Only the bundles hold it: the offer covers nothing in this cart, and waits in the browser.
      expect((await discount(page, { grants: JSON.stringify([grant]) })).body).toEqual({ applied: false, drop: [] });

      const added = await page.evaluate(async (reference) => {
        const formKey = decodeURIComponent(document.cookie.match(/form_key=([^;]+)/)?.[1] ?? '');
        const response = await fetch((window as any).barry.magento.addToCartUrl, {
          method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
          body: new URLSearchParams({ form_key: formKey, items: JSON.stringify([{ reference, quantity: 1 }]) }).toString(),
        });
        return response.json();
      }, part);
      expect(added).toMatchObject({ success: true });
      expect((await discount(page, { grants: JSON.stringify([grant]) })).body).toMatchObject({ applied: true, drop: [grant] });

      const cart = await quote(page);
      // Half of the product bought as itself, and nothing off either bundle or their parts.
      expect(cart.lines['bb-part-a']).toBe(15);
      expect(cart.allLines).toBe(15);
    } finally {
      sql(`UPDATE catalog_product_entity_int SET value = 1 WHERE entity_id = ${part} AND attribute_id = (SELECT attribute_id FROM eav_attribute WHERE attribute_code = 'visibility' AND entity_type_id = 4)`);
    }
  });

  test('an offer becomes a one-time code once the cart holds what it covers, off those products only', async ({ page }) => {
    await shopper(page);
    const grant = offer({ variantIds: [red], rules: [{ scope: 'Single', type: 'Percentage', value: 25 }], nonce: randomUUID() });

    // Nothing of the offer in the cart yet: it waits in the browser, and bluebarry is not asked.
    await addToCart(page, 'bb-simple');
    expect((await discount(page, { grants: JSON.stringify([grant]) })).body).toEqual({ applied: false, drop: [] });
    expect((await mockApi.requests()).filter((r) => r.path === '/data/magento/offers/coupon')).toHaveLength(0);

    // The shopper adds the offered product; the SDK gives its offers again after the cart changed.
    // An older offer for the same product is passed over for good.
    const older = offer({ variantIds: [red], rules: [{ scope: 'Single', type: 'Percentage', value: 5 }], nonce: randomUUID() });
    await addToCart(page, 'bb-configurable', { color: 'BB Red' });
    const redeemed = (await discount(page, { grants: JSON.stringify([older, grant]) })).body;
    // The answer names the offer that is on the cart and its code: the SDK does not ask about it again while it is.
    expect(redeemed).toEqual({ applied: true, drop: [grant, older], offer: grant, coupon: expect.stringMatching(/^BB-[0-9A-F]{12}$/) });
    const asked = (await mockApi.requests()).filter((r) => r.path === '/data/magento/offers/coupon');
    expect(asked).toHaveLength(1);
    expect(asked[0].headers.authorization).toBe(KEY);
    // The shopper is the one the browser's bluebarry cookie names, and part of the set gets the single rule.
    const uid = (await page.context().cookies()).find((c) => c.name === 'bb_uid')!.value;
    expect(asked[0].body).toEqual({ grant, userId: uid, wholeSet: true });

    const cart = await quote(page);
    expect(cart.coupon).toMatch(/^BB-[0-9A-F]{12}$/);
    // 25% of the offered product (80.00), nothing off the other one.
    expect(cart.lines).toEqual({ 'bb-simple': 0, 'bb-configurable-red': 20 });
    expect(rule(cart.coupon)).toMatchObject({ name: 'bluebarry: 25% off, 1 product', action: 'by_percent', usageLimit: 1 });
    expect(sql(`SELECT kind FROM bluebarry_discount_rule WHERE rule_id = ${rule(cart.coupon)!.id}`)).toBe('offer');

    expect(redeemed.coupon).toBe(cart.coupon);

    // The same offer handed in again (a browser that lost what it knew): its code is on the cart
    // already, and the cart is left as it is.
    expect((await discount(page, { grants: JSON.stringify([grant]) })).body).toEqual({ applied: false, drop: [grant], offer: grant, coupon: cart.coupon });
    expect(await quote(page)).toMatchObject({ coupon: cart.coupon });
  });

  test('an amount off comes off each offered product once, and an offer built around a product needs it in the cart', async ({ page }) => {
    await shopper(page);
    // 5.00 off the simple product, for a shopper who also buys the red variant.
    const grant = offer({ variantIds: [simple], requiredVariantIds: [red], rules: [{ scope: 'Single', type: 'FixedAmount', value: 5 }], nonce: randomUUID() });
    await addToCart(page, 'bb-simple');
    await addToCart(page, 'bb-simple'); // two of it
    expect((await discount(page, { grants: JSON.stringify([grant]) })).body).toEqual({ applied: false, drop: [] });

    await addToCart(page, 'bb-configurable', { color: 'BB Red' });
    expect((await discount(page, { grants: JSON.stringify([grant]) })).body).toMatchObject({ applied: true, drop: [grant] });
    const cart = await quote(page);
    // Once for the product, whatever its quantity.
    expect(cart.lines).toEqual({ 'bb-simple': 5, 'bb-configurable-red': 0 });
    expect(rule(cart.coupon)).toMatchObject({ name: 'bluebarry: 5 off per product, 1 product', action: 'by_fixed', qty: 1 });
  });

  test('an offer bluebarry refuses is dropped, one it could not check stays for the next cart change, and the shopper\'s own code keeps it out', async ({ page }) => {
    await shopper(page);
    await addToCart(page, 'bb-simple');
    const expired = offer({ variantIds: [simple], expired: true, rules: [{ scope: 'Single', type: 'Percentage', value: 10 }], nonce: randomUUID() });
    expect((await discount(page, { grants: JSON.stringify([expired, 'not-an-offer']) })).body).toEqual({ applied: false, drop: ['not-an-offer', expired] });

    const good = offer({ variantIds: [simple], rules: [{ scope: 'Single', type: 'Percentage', value: 10 }], nonce: randomUUID() });
    await mockApi.respondWith({ status: 503 });
    expect((await discount(page, { grants: JSON.stringify([good]) })).body).toEqual({ applied: false, drop: [] });
    await mockApi.reset();
    // A bluebarry that did not answer is left alone for half a minute, by every shopper of the store.
    expect((await discount(page, { grants: JSON.stringify([good]) })).body).toEqual({ applied: false, drop: [] });
    expect((await mockApi.requests()).filter((r) => r.path === '/data/magento/offers/coupon')).toHaveLength(0);
    magento('cache:flush'); // half a minute later

    // The shopper's own code is on the cart: the offer waits, and bluebarry is not asked.
    await page.goto('/checkout/cart/');
    await page.evaluate(async (own) => {
      const formKey = decodeURIComponent(document.cookie.match(/form_key=([^;]+)/)?.[1] ?? '');
      await fetch('/checkout/cart/couponPost/', {
        method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ form_key: formKey, coupon_code: own, remove: '0' }).toString(),
      });
    }, own);
    await page.goto('/bb-simple.html');
    expect((await discount(page, { grants: JSON.stringify([good]) })).body).toEqual({ applied: false, drop: [] });
    expect((await mockApi.requests()).filter((r) => r.path === '/data/magento/offers/coupon')).toHaveLength(0);
    expect(await quote(page)).toMatchObject({ coupon: own });
  });

  test('the shopper\'s other requests do not wait while bluebarry is asked about an offer', async ({ page }) => {
    await shopper(page);
    await addToCart(page, 'bb-simple');
    const grant = offer({ variantIds: [simple], rules: [{ scope: 'Single', type: 'Percentage', value: 10 }], nonce: randomUUID() });
    // A slow bluebarry: its answer about the offer takes a second and a half.
    await mockApi.respondWith({ delayMs: 1500 });

    const timing = await page.evaluate(async (grant) => {
      const formKey = decodeURIComponent(document.cookie.match(/form_key=([^;]+)/)?.[1] ?? '');
      const started = performance.now();
      const redeemed = fetch((window as any).barry.magento.discountUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: new URLSearchParams({ form_key: formKey, grants: JSON.stringify([grant]) }).toString(),
      }).then(async (response) => ({ body: await response.json(), ms: performance.now() - started }));
      // The module is waiting for bluebarry now. The shopper's mini-cart reads their session meanwhile.
      await new Promise((resolve) => setTimeout(resolve, 400));
      const asked = performance.now();
      await fetch('/customer/section/load/?sections=cart&force_new_section_timestamp=true', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      const sectionMs = performance.now() - asked;
      return { ...(await redeemed), sectionMs };
    }, grant);

    // The session was let go before bluebarry was asked: the mini-cart did not wait for its answer.
    expect(timing.ms).toBeGreaterThan(1500);
    expect(timing.sectionMs).toBeLessThan(900);
    expect(timing.body).toMatchObject({ applied: true, drop: [grant] });
    expect(await quote(page)).toMatchObject({ discount: 10 });
  });

  test('a customer group the merchant makes later gets bluebarry\'s codes too', async ({ request }) => {
    const code = unique('GROUPS');
    expect((await command(request, 'coupon.create', { code, discountType: 'percent', amount: 10, productIds: [] })).status).toBe(200);
    const headers = { Authorization: `Bearer ${await adminToken(request)}` };
    const created = await request.post('/rest/V1/customerGroups', { headers, data: { group: { code: `Wholesale ${randomUUID().slice(0, 8)}`, tax_class_id: 3 } } });
    expect(created.status(), await created.text()).toBe(200);
    const groupId = (await created.json()).id;
    try {
      // A cart price rule holds for the customer groups it has: the new one is not among them yet.
      const holds = () => sql(`SELECT COUNT(*) FROM salesrule_customer_group WHERE rule_id = ${rule(code)!.id} AND customer_group_id = ${groupId}`);
      expect(holds()).toBe('0');
      magento('bluebarry:discounts:clean');
      expect(holds()).toBe('1');
      // The merchant's own rule is left as they made it.
      expect(sql(`SELECT COUNT(*) FROM salesrule_customer_group WHERE rule_id = ${ownRuleId} AND customer_group_id = ${groupId}`)).toBe('0');
    } finally {
      await request.delete(`/rest/V1/customerGroups/${groupId}`, { headers });
    }
  });

  test('a code ends by being removed once its last moment has passed', async ({ request }) => {
    const ended = unique('ENDED');
    const lasting = unique('LASTS');
    const spec = { discountType: 'percent', amount: 15, productIds: [] as string[] };
    expect((await command(request, 'coupon.create', { ...spec, code: ended, expiresAt: new Date(Date.now() + 2000).toISOString() })).status).toBe(200);
    expect((await command(request, 'coupon.create', { ...spec, code: lasting, expiresAt: new Date(Date.now() + 86400000).toISOString() })).status).toBe(200);
    expect(sql(`SELECT COUNT(*) FROM bluebarry_discount_code WHERE coupon_id IN (SELECT coupon_id FROM salesrule_coupon WHERE code IN ('${ended}', '${lasting}'))`)).toBe('2');

    await new Promise((resolve) => setTimeout(resolve, 3000));
    // Past its moment and not removed yet, bluebarry already hears it is no longer usable.
    expect((await command(request, 'coupon.get', { code: ended })).body).toMatchObject({ exists: true, status: 'disabled' });
    expect(magento('bluebarry:discounts:clean')).toContain('1 expired codes');

    expect((await command(request, 'coupon.get', { code: ended })).body).toEqual({ exists: false, usageCount: 0 });
    expect((await command(request, 'coupon.get', { code: lasting })).body).toMatchObject({ exists: true, status: 'publish' });
  });
});

test.describe('the rewards panel on a store with customer accounts', () => {
  const email = `rewards-${randomUUID().slice(0, 8)}@example.test`;
  const password = 'Sh0pper!Pass123';

  test.beforeAll(async ({ request }) => {
    magento('config:set', 'bluebarry_module/general/api_key', KEY);
    magento('cache:flush');
    const created = await request.post('/rest/V1/customers', { data: { customer: { email, firstname: 'Sam', lastname: 'Shopper' }, password } });
    expect(created.status(), await created.text()).toBe(200);
  });

  test.afterAll(async () => {
    await mockApi.reset();
    sql(`DELETE FROM customer_entity WHERE email = '${email}'`);
    sql("DELETE FROM core_config_data WHERE path = 'bluebarry_module/general/api_key'");
    magento('cache:flush');
  });

  test('the store vouches for a signed-in customer, and only to its own pages', async ({ page }) => {
    await mockApi.reset();
    await stubAdvisor(page, null);
    await page.goto('/bb-simple.html');
    const url = await page.evaluate(() => (window as any).barry.loyaltySessionUrl as string);
    expect(url).toContain('/bluebarry/loyalty/session');
    const ask = (query: string, header: boolean) => page.evaluate(async ({ url, query, header }) => {
      const response = await fetch(url + query, { credentials: 'same-origin', headers: header ? { 'X-Bluebarry-Loyalty': '1' } : {} });
      return { status: response.status, body: await response.json(), cache: response.headers.get('cache-control') };
    }, { url, query, header });

    // Nobody signed in: nobody to vouch for, and bluebarry is not asked.
    expect(await ask('', true)).toMatchObject({ status: 200, body: { customerId: null } });
    expect((await mockApi.requests()).filter((r) => r.path === '/data/magento/loyalty/customer-session')).toHaveLength(0);

    await page.goto('/customer/account/login/');
    await page.locator('#email').fill(email);
    await page.locator('input[name="login[password]"]').first().fill(password);
    await Promise.all([page.waitForURL(/customer\/account\/?$/), page.locator('button#send2, button[name="send"]').first().click()]);
    const customerId = sql(`SELECT entity_id FROM customer_entity WHERE email = '${email}'`);

    // Which customer is signed in, for the panel to end a session the store no longer backs.
    expect(await ask('?check=1', false)).toMatchObject({ status: 200, body: { customerId } });
    // A link or form from another site cannot send the header: no session for it.
    expect((await ask('', false)).status).toBe(403);

    const session = await ask('?ref=FRIEND1', true);
    expect(session.body).toMatchObject({ token: `mock-loyalty-${email}`, customerId });
    expect(session.cache).toContain('no-store');
    const vouched = (await mockApi.requests()).filter((r) => r.path === '/data/magento/loyalty/customer-session');
    expect(vouched).toHaveLength(1);
    expect(vouched[0].headers.authorization).toBe(KEY);
    expect(vouched[0].body).toEqual({ email, referralCode: 'FRIEND1' });

    // A store view given another Tenant ID shows another bluebarry account's rewards panel. The
    // website's key is not that account's: its pages name no session address, and nobody is vouched for.
    await mockApi.reset();
    try {
      magento('config:set', '--scope=stores', '--scope-code=default', 'bluebarry_module/general/tenantid', randomUUID());
      magento('cache:flush');
      await page.goto('/bb-simple.html');
      expect(await page.evaluate(() => (window as any).barry.loyaltySessionUrl)).toBeUndefined();
      expect(await ask('', true)).toMatchObject({ status: 200, body: { customerId: null } });
      expect((await mockApi.requests()).filter((r) => r.path === '/data/magento/loyalty/customer-session')).toHaveLength(0);
    } finally {
      sql("DELETE FROM core_config_data WHERE path = 'bluebarry_module/general/tenantid' AND scope = 'stores'");
      magento('cache:flush');
    }
  });
});
