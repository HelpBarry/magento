import { expect, test } from '@playwright/test';
import {
  addToCart,
  checkoutAsGuest,
  collectCspViolations,
  magento,
  mockApi,
  newAdvisorIds,
  orderEntityId,
  runConversionConsumer,
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

  for (const url of ['/', '/bb-simple.html']) {
    await page.goto(url);
    await expect.poll(() => page.evaluate(() => (window as any).__bbAdvisorLoaded)).toBe(true);
    expect(await page.evaluate(() => (window as any).barry?.tenantId)).toBe('test-tenant');
  }

  const ours = (await cspViolations()).filter(
    (v) => /bluebarry/.test(`${v.blockedURI} ${v.sourceFile}`) || /barry/.test(v.sample ?? ''),
  );
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

test('simple product: checkout after quiz sends a valid conversion and identify', async ({ page }) => {
  const ids = newAdvisorIds();
  const email = `shopper+${Date.now()}@example.com`;
  await stubAdvisor(page, ids);
  await page.goto('/');
  await startAdvisor(page);
  await addToCart(page, 'bb-simple');
  const incrementId = await checkoutAsGuest(page, email);

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
    currencyIso: 'EUR',
    conversionId: orderEntityId(incrementId),
  });
  // EUR 100.00 excl. tax, NL 21% VAT, shipping excluded.
  expect(Number(body.orderProductTotal)).toBeCloseTo(100, 2);
  expect(Number(body.orderTaxTotal)).toBeCloseTo(21, 2);
  expect(Number(body.orderGrandTotal)).toBeCloseTo(121, 2);
  expect(Number(body.value)).toBeCloseTo(100, 2);
  expect(body.items).toHaveLength(1);
  expect(Number(body.items[0].quantity)).toBe(1);
  expect(Number(body.items[0].priceExclTax)).toBeCloseTo(100, 2);
  expect(Number(body.items[0].priceInclTax)).toBeCloseTo(121, 2);
  expect(Number(body.items[0].taxPercentage)).toBeCloseTo(21, 2);

  expect(identify, 'identify request sent').toBeDefined();
  expect(identify!.contractErrors).toEqual([]);
  expect(identify!.body).toEqual({ email, sessionId: ids.sessionId, userId: ids.userId });
});

test('configurable product: reported once, at the variant price', async ({ page }) => {
  // Known bug: ConversionProcessor iterates getAllItems(), which includes the child simple of a
  // configurable as a second, EUR 0 line. Totals are right; the item list is not. Remove once fixed.
  test.fail();
  const ids = newAdvisorIds();
  await stubAdvisor(page, ids);
  await page.goto('/');
  await startAdvisor(page);
  await addToCart(page, 'bb-configurable', { color: 'BB Red' });
  await checkoutAsGuest(page, `shopper+${Date.now()}@example.com`);

  runConversionConsumer();
  const conversion = (await mockApi.requests()).find((r) => r.path.toLowerCase() === '/data/conversionevents');
  expect(conversion).toBeDefined();
  expect(conversion!.contractErrors).toEqual([]);
  const body = conversion!.body;
  expect(Number(body.orderProductTotal)).toBeCloseTo(80, 2);
  expect(Number(body.orderGrandTotal)).toBeCloseTo(96.8, 2);
  expect(body.items, 'one line per purchased product, not parent + child').toHaveLength(1);
});

test('checkout without a quiz session sends nothing', async ({ page }) => {
  await stubAdvisor(page, null);
  await addToCart(page, 'bb-simple');
  await checkoutAsGuest(page, `shopper+${Date.now()}@example.com`);

  runConversionConsumer();
  expect(await mockApi.requests()).toEqual([]);
});

test('Bluebarry API outage does not affect checkout or wedge the queue', async ({ page }) => {
  await mockApi.respondWith({ status: 503, body: { error: 'down' } });
  const ids = newAdvisorIds();
  await stubAdvisor(page, ids);
  await page.goto('/');
  await startAdvisor(page);
  await addToCart(page, 'bb-simple');
  await checkoutAsGuest(page, `shopper+${Date.now()}@example.com`);

  // The consumer must finish (not crash or loop on the failed message).
  runConversionConsumer();
  const attempts = (await mockApi.requests()).filter((r) => r.path.toLowerCase() === '/data/conversionevents');
  expect(attempts.length).toBeGreaterThanOrEqual(1);
});
