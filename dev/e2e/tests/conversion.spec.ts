import { expect, test } from '@playwright/test';
import {
  addToCart,
  checkoutAsGuest,
  collectCspViolations,
  magento,
  mockApi,
  newAdvisorIds,
  orderEntityId,
  orderGrandTotal,
  payForOrder,
  runConversionConsumer,
  sql,
  startAdvisor,
  stubAdvisor,
} from './helpers';

test.beforeEach(async () => {
  // Leftovers from an earlier failed run must not be attributed to this test. A consumer that can't
  // start at all is reported by the tests themselves, not here.
  try {
    runConversionConsumer();
  } catch {}
  await mockApi.reset();
});

test('the conversion consumer is registered', () => {
  expect(magento('queue:consumers:list')).toContain('BluebarryConversionProcess');
});

test('advisor bootstrap loads on storefront pages without CSP violations', async ({ page }) => {
  await stubAdvisor(page, null);
  const cspViolations = await collectCspViolations(page);
  const consoleErrors: string[] = [];
  page.on('console', (m) => m.type() === 'error' && consoleErrors.push(m.text()));

  const ours: any[] = [];
  for (const url of ['/', '/bb-simple.html']) {
    await page.goto(url);
    await expect.poll(() => page.evaluate(() => (window as any).__bbAdvisorLoaded)).toBe(true);
    expect(await page.evaluate(() => (window as any).barry?.tenantId)).toBe('test-tenant');
    // Collected per document, so read them before navigating away.
    for (const v of await cspViolations()) {
      if (/bluebarry/.test(`${v.blockedURI} ${v.sourceFile}`) || /barry/.test(v.sample ?? '')) ours.push({ url, ...v });
    }
  }
  expect(ours).toEqual([]);
  expect(consoleErrors.filter((e) => /bluebarry|barry/i.test(e))).toEqual([]);
});

test('quiz session is stored server-side and returned by GET', async ({ page }) => {
  const ids = newAdvisorIds();
  await stubAdvisor(page, ids);
  await page.goto('/');
  await startAdvisor(page);

  const stored = await (await page.request.get('/bluebarry/session/update')).json();
  expect(stored.session).toEqual({
    bluebarry: { session_id: ids.sessionId, advisor_id: ids.advisorId, user_id: ids.userId },
  });
});

const conversionsSent = async () =>
  (await mockApi.requests()).filter((r) => r.path.toLowerCase() === '/data/conversionevents');

const productId = (sku: string) => sql(`SELECT entity_id FROM catalog_product_entity WHERE sku = '${sku}'`);

test('simple product: checkout after quiz sends a valid conversion and identify once paid', async ({ page }) => {
  const ids = newAdvisorIds();
  const email = `shopper+${Date.now()}@example.com`;
  await stubAdvisor(page, ids);
  await page.goto('/');
  await startAdvisor(page);
  await addToCart(page, 'bb-simple');
  const incrementId = await checkoutAsGuest(page, email);

  // Check / money order: pending until the merchant invoices it, and not a sale before that.
  runConversionConsumer();
  expect(await conversionsSent(), 'nothing sent for an unpaid order').toEqual([]);

  await payForOrder(page, incrementId);
  runConversionConsumer();
  const requests = await mockApi.requests();
  const conversion = requests.find((r) => r.path.toLowerCase() === '/data/conversionevents');
  const identify = requests.find((r) => r.path.toLowerCase() === '/data/identify');

  expect(conversion, 'conversion request sent').toBeDefined();
  expect(conversion!.contractErrors).toEqual([]);
  expect(conversion!.responseStatus).toBe(201);
  expect(conversion!.headers['bb-tenant-id']).toBe('test-tenant');

  const body = conversion!.body;
  expect(body).toMatchObject({
    advisorId: ids.advisorId,
    sessionId: ids.sessionId,
    userId: ids.userId,
    commerceSource: 'Magento',
    commerceStoreKey: 'localhost',
    currencyIso: 'EUR',
    conversionId: incrementId,
  });
  expect(Date.parse(body.occurredAtUtc)).toBeGreaterThan(Date.now() - 10 * 60_000);
  // EUR 100.00 excl. tax, NL 21% VAT; the grand total is the order's, shipping included.
  expect(Number(body.orderProductTotal)).toBeCloseTo(100, 2);
  expect(Number(body.orderTaxTotal)).toBeCloseTo(21, 2);
  expect(Number(body.orderGrandTotal)).toBeCloseTo(orderGrandTotal(incrementId), 2);
  expect(Number(body.orderGrandTotal)).toBeGreaterThan(121);
  expect(Number(body.value)).toBeCloseTo(Number(body.orderGrandTotal), 2);
  expect(body.items).toHaveLength(1);
  expect(body.items[0].itemId).toBe(productId('bb-simple'));
  expect(Number(body.items[0].quantity)).toBe(1);
  expect(Number(body.items[0].priceExclTax)).toBeCloseTo(100, 2);
  expect(Number(body.items[0].priceInclTax)).toBeCloseTo(121, 2);
  expect(Number(body.items[0].taxPercentage)).toBeCloseTo(21, 2);

  expect(identify, 'identify request sent').toBeDefined();
  expect(identify!.contractErrors).toEqual([]);
  expect(identify!.body).toEqual({ email, sessionId: ids.sessionId, userId: ids.userId });
});

test('search-only shopper: the order is recorded with the visitor id alone (#13)', async ({ page }) => {
  const uid = newAdvisorIds().userId;
  const email = `shopper+${Date.now()}@example.com`;
  await stubAdvisor(page, { ...newAdvisorIds(), userId: uid }); // bb_uid from the SDK, no quiz started
  await page.goto('/');
  await addToCart(page, 'bb-simple');
  const incrementId = await checkoutAsGuest(page, email);
  await payForOrder(page, incrementId);
  runConversionConsumer();

  const requests = await mockApi.requests();
  const conversion = requests.find((r) => r.path.toLowerCase() === '/data/conversionevents');
  expect(conversion).toBeDefined();
  expect(conversion!.contractErrors).toEqual([]);
  expect(conversion!.body.userId).toBe(uid);
  expect(conversion!.body.sessionId).toBeUndefined();
  expect(conversion!.body.advisorId).toBeUndefined();
  const identify = requests.find((r) => r.path.toLowerCase() === '/data/identify');
  expect(identify!.body).toEqual({ email, userId: uid });
});

// Composite products must be reported as the line the shopper bought: once, at the price paid (#20),
// named by the catalog's reference (the variant for a configurable product).
for (const [name, urlKey, options, net, sku] of [
  ['configurable product', 'bb-configurable', { color: 'BB Red' }, 80, 'bb-configurable-red'],
  ['dynamic-price bundle', 'bb-bundle-dynamic', { bundle: true }, 50, 'bb-bundle-dynamic'],
  ['fixed-price bundle', 'bb-bundle-fixed', { bundle: true }, 45, 'bb-bundle-fixed'],
] as const) {
  test(`${name}: reported once, at the price paid`, async ({ page }) => {
    const ids = newAdvisorIds();
    await stubAdvisor(page, ids);
    await page.goto('/');
    await startAdvisor(page);
    await addToCart(page, urlKey, options);
    const incrementId = await checkoutAsGuest(page, `shopper+${Date.now()}@example.com`);
    await payForOrder(page, incrementId);

    runConversionConsumer();
    const conversion = (await conversionsSent())[0];
    expect(conversion).toBeDefined();
    expect(conversion.contractErrors).toEqual([]);
    const body = conversion.body;
    const gross = Math.round(net * 121) / 100; // NL 21% VAT
    expect(Number(body.orderProductTotal)).toBeCloseTo(net, 2);
    expect(Number(body.orderTaxTotal)).toBeCloseTo(gross - net, 2);
    expect(Number(body.orderGrandTotal)).toBeCloseTo(orderGrandTotal(incrementId), 2);
    expect(body.items, 'one line per purchased product').toHaveLength(1);
    expect(body.items[0].itemId).toBe(productId(sku));
    expect(Number(body.items[0].quantity)).toBe(1);
    expect(Number(body.items[0].priceExclTax)).toBeCloseTo(net, 2);
    expect(Number(body.items[0].priceInclTax)).toBeCloseTo(gross, 2);
    expect(Number(body.items[0].taxPercentage)).toBeCloseTo(21, 2);
  });
}

test('a shopper bluebarry never saw sends nothing, even when paid', async ({ page }) => {
  await stubAdvisor(page, null, { visitor: false });
  await addToCart(page, 'bb-simple');
  const incrementId = await checkoutAsGuest(page, `shopper+${Date.now()}@example.com`);
  await payForOrder(page, incrementId);

  runConversionConsumer();
  expect(await mockApi.requests()).toEqual([]);
});

test.describe('cookie restriction mode', () => {
  test.afterAll(() => {
    magento('config:set', 'web/cookie/cookie_restriction', '0');
    magento('cache:flush');
  });

  test('without the shopper\'s cookie consent the order is not linked', async ({ page }) => {
    magento('config:set', 'web/cookie/cookie_restriction', '1');
    magento('cache:flush');
    await stubAdvisor(page, newAdvisorIds());
    await page.goto('/');
    await addToCart(page, 'bb-simple');
    const incrementId = await checkoutAsGuest(page, `shopper+${Date.now()}@example.com`);
    await payForOrder(page, incrementId);

    runConversionConsumer();
    expect(await mockApi.requests()).toEqual([]);
  });
});

test('Bluebarry API outage: the conversion is retried later and arrives once', async ({ page }) => {
  await mockApi.respondWith({ status: 503, body: { error: 'down' } });
  const ids = newAdvisorIds();
  await stubAdvisor(page, ids);
  await page.goto('/');
  await startAdvisor(page);
  await addToCart(page, 'bb-simple');
  const incrementId = await checkoutAsGuest(page, `shopper+${Date.now()}@example.com`);
  await payForOrder(page, incrementId);

  // The consumer finishes (no crash, no requeue loop) and schedules a retry.
  runConversionConsumer();
  expect(await conversionsSent()).toHaveLength(1);
  const orderId = orderEntityId(incrementId);
  expect(sql(`SELECT CONCAT(status, '/', attempts) FROM bluebarry_order_visitor WHERE order_id = ${orderId}`)).toBe('1/1');

  // bluebarry is back and the retry is due: the cron's job sends it.
  await mockApi.reset();
  sql(`UPDATE bluebarry_order_visitor SET next_attempt_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE order_id = ${orderId}`);
  magento('bluebarry:conversions:send-due');
  const retried = await conversionsSent();
  expect(retried).toHaveLength(1);
  expect(retried[0].responseStatus).toBe(201);
  expect(sql(`SELECT status FROM bluebarry_order_visitor WHERE order_id = ${orderId}`)).toBe('2');

  // Nothing left to send.
  magento('bluebarry:conversions:send-due');
  expect(await conversionsSent()).toHaveLength(1);
});
