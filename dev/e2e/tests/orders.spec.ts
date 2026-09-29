import { createHmac } from 'node:crypto';
import { expect, test, type APIRequestContext } from '@playwright/test';
import {
  addToCart,
  adminToken,
  checkoutAsGuest,
  magento,
  MOCK_API,
  mockApi,
  orderEntityId,
  payForOrder,
  sql,
  stubAdvisor,
  type RecordedRequest,
} from './helpers';

// The store's orders in bluebarry (magento-internal#12): a paid order is a new purchase, changes
// follow, bluebarry can ask for the last year, and a checkout's email starts the abandoned checkout
// flow. All sent by the cron (bin/magento bluebarry:orders:sync here), never while a shopper waits.

const KEY = 'test-api-key';
const productId = (sku: string) => sql(`SELECT entity_id FROM catalog_product_entity WHERE sku = '${sku}'`);
const syncOrders = () => magento('bluebarry:orders:sync');

async function sent(path: string): Promise<RecordedRequest[]> {
  return (await mockApi.requests()).filter((r) => r.path.split('?')[0] === path);
}
const sentOrders = async () => (await sent('/data/magento/orders/sync')).flatMap((r) => r.body.orders);

async function command(request: APIRequestContext, body: object) {
  const raw = JSON.stringify(body);
  const at = Math.floor(Date.now() / 1000);
  return request.post('/bluebarry/command/', {
    headers: { 'Content-Type': 'application/json', 'X-Bluebarry-Timestamp': String(at), 'X-Bluebarry-Signature': createHmac('sha256', KEY).update(`${at}.${raw}`).digest('hex') },
    data: raw,
  });
}

test.describe('orders', () => {
  test.describe.configure({ mode: 'serial' });

  test.beforeAll(() => {
    magento('config:set', 'bluebarry_module/general/api_key', KEY);
    // Earlier specs' orders are no concern of this one.
    sql('DELETE FROM bluebarry_order_sync');
    sql('DELETE FROM bluebarry_checkout');
    sql("DELETE FROM flag WHERE flag_code LIKE 'bluebarry\\_order\\_import%' OR flag_code = 'bluebarry_order_sync'");
  });

  test.beforeEach(async () => {
    await mockApi.reset();
  });

  test.afterAll(async () => {
    await mockApi.reset();
    sql("DELETE FROM core_config_data WHERE path = 'bluebarry_module/general/api_key'");
    sql("DELETE FROM flag WHERE flag_code LIKE 'bluebarry\\_order\\_import%' OR flag_code IN ('bluebarry_order_sync', 'bluebarry_catalog')");
    sql("DELETE FROM core_config_data WHERE path = 'web/cookie/cookie_restriction'");
    sql('DELETE FROM bluebarry_product_sync');
    magento('cache:flush');
  });

  let orderId = ''; // the entity id, for Magento's REST API
  let orderNumber = ''; // what bluebarry keys the order by

  test('a checkout that gives an email starts the abandoned checkout flow, and ends it as an order', async ({ page }) => {
    await stubAdvisor(page, null, { visitor: false });
    await addToCart(page, 'bb-configurable', { color: 'BB Red' });
    await page.goto('/checkout/');
    const email = page.locator('#customer-email');
    await expect(email).toBeVisible({ timeout: 60_000 });
    await email.fill('checkout@example.com');
    await expect.poll(() => sql("SELECT email FROM bluebarry_checkout WHERE email = 'checkout@example.com'"), { timeout: 10_000 }).toBe('checkout@example.com');

    // Still typing: nothing goes yet. Settled (a minute later, moved back here): it goes.
    syncOrders();
    expect(await sent('/data/magento/checkout-started')).toEqual([]);
    sql("UPDATE bluebarry_checkout SET noted_at = UTC_TIMESTAMP() - INTERVAL 2 MINUTE WHERE email = 'checkout@example.com'");
    syncOrders();
    const [started] = await sent('/data/magento/checkout-started');
    expect(started.contractErrors).toEqual([]);
    expect(started.body).toMatchObject({ storeKey: 'localhost', email: 'checkout@example.com', completed: false, lines: [{ reference: productId('bb-configurable-red'), quantity: 1 }] });

    // Placed: the checkout is completed (no reminder), and the unpaid order is not sent yet.
    await mockApi.reset();
    const incrementId = await checkoutAsGuest(page, 'checkout@example.com');
    orderId = orderEntityId(incrementId);
    orderNumber = incrementId;
    syncOrders();
    const [completed] = await sent('/data/magento/checkout-started');
    expect(completed.body).toMatchObject({ token: started.body.token, completed: true });
    expect(await sent('/data/magento/orders/sync')).toEqual([]);

    // Paid: a new purchase.
    await payForOrder(page, incrementId);
    await mockApi.reset();
    syncOrders();
    const [paid] = await sentOrders();
    expect(paid).toMatchObject({
      id: orderNumber, live: true, financialStatus: 'PAID', email: 'checkout@example.com', checkoutToken: started.body.token,
      lines: [{ reference: productId('bb-configurable-red'), quantity: 1 }],
    });
    const [request] = await sent('/data/magento/orders/sync');
    expect(request.contractErrors).toEqual([]);
    expect(request.headers.authorization).toBe(KEY);
    expect(request.body.storeKey).toBe('localhost');
  });

  test('a refund follows the order, and is no new purchase', async ({ request }) => {
    const refund = await request.post(`/rest/V1/order/${orderId}/refund`, {
      headers: { Authorization: `Bearer ${await adminToken(request)}` },
      data: { arguments: { shipping_amount: 0, adjustment_positive: 0, adjustment_negative: 0 }, items: [] , notify: false },
    });
    expect(refund.status(), await refund.text()).toBe(200);
    syncOrders();
    const [refunded] = await sentOrders();
    expect(refunded).toMatchObject({ id: orderNumber, live: false });
    expect(['REFUNDED', 'PARTIALLY_REFUNDED']).toContain(refunded.financialStatus);
  });

  test('an order bluebarry refuses is tried again on a later run, not ten times in this one', async () => {
    sql(`UPDATE bluebarry_order_sync SET queued_at = UTC_TIMESTAMP(), attempts = 0 WHERE order_id = ${orderId}`); // changed
    await mockApi.respondWith({ status: 400, body: { error: 'refused' } });

    syncOrders();

    expect(await sent('/data/magento/orders/sync')).toHaveLength(1);
    expect(sql(`SELECT attempts FROM bluebarry_order_sync WHERE order_id = ${orderId}`)).toBe('1');
    await mockApi.reset();
    syncOrders(); // delivered now
  });

  let imported = 0;

  test("bluebarry's Orders page gets the last year, as history, with its progress", async ({ request }) => {
    expect(await (await command(request, { command: 'orders.import', payload: { since: new Date(Date.now() - 365 * 86400000).toISOString() } })).json()).toEqual({ started: true });
    syncOrders();

    const orders = await sentOrders();
    expect(orders.length).toBeGreaterThan(0);
    expect(orders.every((o: any) => o.live === false)).toBe(true);
    expect(orders.map((o: any) => o.id)).toContain(orderNumber);
    const progress = (await sent('/data/magento/orders/sync')).map((r) => r.body.import).filter(Boolean);
    expect(progress.at(-1)).toBe('Completed');
    imported = (await sent('/data/magento/orders/sync')).at(-1)!.body.importedCount;
    expect(imported).toBe(new Set(orders.map((o: any) => o.id)).size);
    // Imported orders are known now: a later change is not a new purchase.
    expect(sql(`SELECT COUNT(*) FROM bluebarry_order_sync WHERE synced_at IS NOT NULL`)).not.toBe('0');
  });

  test("bluebarry's tasks start the import for a store it could not reach", async () => {
    await fetch(`${MOCK_API}/__tasks`, { method: 'PUT', body: JSON.stringify({ importOrders: true }) });
    sql("DELETE FROM flag WHERE flag_code LIKE 'bluebarry\\_order\\_import%'");
    syncOrders(); // asks for the tasks first, as the 10-minute cron does

    const [tasks] = await sent('/data/magento/tasks');
    expect(tasks.path).toContain('storeKey=localhost');
    expect((await sent('/data/magento/orders/sync')).map((r) => r.body.import).filter(Boolean).at(-1)).toBe('Completed');
  });

  test('a batch bluebarry did not take is sent again, not skipped', async ({ request }) => {
    sql("DELETE FROM flag WHERE flag_code LIKE 'bluebarry\\_order\\_import%'");
    expect(await (await command(request, { command: 'orders.import', payload: { since: new Date(Date.now() - 365 * 86400000).toISOString() } })).json()).toEqual({ started: true });
    await mockApi.respondWith({ status: 503, body: { error: 'down' } });
    syncOrders();

    await mockApi.reset();
    sql("DELETE FROM flag WHERE flag_code = 'bluebarry_order_sync'"); // no outage pause: the next run is the retry
    syncOrders();
    const last = (await sent('/data/magento/orders/sync')).at(-1)!.body;
    expect([last.import, last.importedCount]).toEqual(['Completed', imported]);
  });

  test("a website whose key belongs to another company than its Tenant ID sends no orders, and keeps them", async () => {
    const order = sql('SELECT MAX(entity_id) FROM sales_order');
    sql(`INSERT INTO bluebarry_order_sync (order_id, queued_at, live) VALUES (${order}, UTC_TIMESTAMP(), 0) ON DUPLICATE KEY UPDATE queued_at = UTC_TIMESTAMP(), claim = NULL`);
    // The heartbeat found the key to be another company's (bluebarry answered 409).
    const heartbeat = sql("SELECT flag_data FROM flag WHERE flag_code = 'bluebarry_heartbeat'");
    sql("DELETE FROM flag WHERE flag_code = 'bluebarry_heartbeat'");
    sql(`INSERT INTO flag (flag_code, state, flag_data) VALUES ('bluebarry_heartbeat', 0, '{"websites":{"1":{"at":1,"site":"","ok":false,"status":409,"error":"x"}}}')`);
    try {
      syncOrders();
      expect(await sentOrders()).toEqual([]);
      expect(sql(`SELECT queued_at IS NOT NULL FROM bluebarry_order_sync WHERE order_id = ${order}`)).toBe('1');
    } finally {
      sql("DELETE FROM flag WHERE flag_code = 'bluebarry_heartbeat'");
      if (heartbeat) sql(`INSERT INTO flag (flag_code, state, flag_data) VALUES ('bluebarry_heartbeat', 0, '${heartbeat.replace(/'/g, "''")}')`);
    }

    syncOrders(); // fixed: it goes
    expect((await sentOrders()).length).toBeGreaterThan(0);
  });

  test("without the shopper's cookie consent the checkout's email is not kept", async ({ page }) => {
    magento('config:set', 'web/cookie/cookie_restriction', '1');
    magento('cache:flush');
    await stubAdvisor(page, null, { visitor: false });
    await addToCart(page, 'bb-simple');
    const noted = await page.evaluate(async () => {
      const formKey = document.cookie.match(/form_key=([^;]+)/)?.[1] ?? '';
      const response = await fetch('/bluebarry/checkout/email/', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: new URLSearchParams({ form_key: formKey, email: 'no-consent@example.com' }).toString(),
      });
      return (await response.json()).noted;
    });
    sql("DELETE FROM core_config_data WHERE path = 'web/cookie/cookie_restriction'");
    magento('cache:flush');
    expect(noted).toBe(false);
    expect(sql("SELECT COUNT(*) FROM bluebarry_checkout WHERE email = 'no-consent@example.com'")).toBe('0');
  });

  test('a checkout whose cart changed after it was sent goes again', async ({ page }) => {
    await stubAdvisor(page, null, { visitor: false });
    await addToCart(page, 'bb-simple');
    const note = () => page.evaluate(async () => {
      const formKey = document.cookie.match(/form_key=([^;]+)/)?.[1] ?? '';
      const response = await fetch('/bluebarry/checkout/email/', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: new URLSearchParams({ form_key: formKey, email: 'cart-changed@example.com' }).toString(),
      });
      return (await response.json()).noted;
    });
    expect(await note()).toBe(true);
    sql("UPDATE bluebarry_checkout SET sent_at = UTC_TIMESTAMP() WHERE email = 'cart-changed@example.com'");

    expect(await note()).toBe(true); // the same cart: nothing new to send
    expect(sql("SELECT sent_at IS NULL FROM bluebarry_checkout WHERE email = 'cart-changed@example.com'")).toBe('0');

    await addToCart(page, 'bb-configurable', { color: 'BB Red' });
    expect(await note()).toBe(true);
    expect(sql("SELECT sent_at IS NULL FROM bluebarry_checkout WHERE email = 'cart-changed@example.com'")).toBe('1');
  });

  test("a returning guest's email the checkout filled in itself is noted too", async ({ page }) => {
    test.setTimeout(60_000);
    await stubAdvisor(page, null, { visitor: false });
    await addToCart(page, 'bb-simple');
    await page.goto('/checkout/');
    // Luma's checkout restores the email a guest gave before (its checkout-data) without an input or
    // change event: the field is filled in the same way here.
    await page.locator('#customer-email').waitFor({ timeout: 20_000 });
    await page.evaluate(() => { (document.getElementById('customer-email') as HTMLInputElement).value = 'restored@example.com'; });

    await expect
      .poll(() => sql("SELECT COUNT(*) FROM bluebarry_checkout WHERE email = 'restored@example.com'"), { timeout: 30_000 })
      .toBe('1');
  });

  test("a guest's first name typed after the email still goes with the checkout", async ({ page }) => {
    await stubAdvisor(page, null, { visitor: false });
    await addToCart(page, 'bb-simple');
    const noted = await page.evaluate(async () => {
      const formKey = document.cookie.match(/form_key=([^;]+)/)?.[1] ?? '';
      const response = await fetch('/bluebarry/checkout/email/', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: new URLSearchParams({ form_key: formKey, email: 'named-later@example.com' }).toString(),
      });
      return (await response.json()).noted;
    });
    expect(noted).toBe(true);
    const quoteId = sql("SELECT quote_id FROM bluebarry_checkout WHERE email = 'named-later@example.com'");
    // The shopper goes on to the shipping address.
    sql(`UPDATE quote_address SET firstname = 'Robin' WHERE quote_id = ${quoteId} AND address_type = 'shipping'`);
    sql(`UPDATE bluebarry_checkout SET noted_at = UTC_TIMESTAMP() - INTERVAL 2 MINUTE WHERE quote_id = ${quoteId}`);
    await mockApi.reset();

    syncOrders();

    const started = (await sent('/data/magento/checkout-started')).find((r) => r.body.email === 'named-later@example.com');
    expect(started?.body.firstName).toBe('Robin');
  });

  test('a checkout whose note arrived after its order was placed goes as completed', async ({ page }) => {
    await stubAdvisor(page, null, { visitor: false });
    await addToCart(page, 'bb-simple');
    const noted = await page.evaluate(async () => {
      const formKey = document.cookie.match(/form_key=([^;]+)/)?.[1] ?? '';
      const response = await fetch('/bluebarry/checkout/email/', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: new URLSearchParams({ form_key: formKey, email: 'placed-first@example.com' }).toString(),
      });
      return (await response.json()).noted;
    });
    expect(noted).toBe(true);
    const quoteId = sql("SELECT quote_id FROM bluebarry_checkout WHERE email = 'placed-first@example.com'");
    // The order took the quote while the note was on its way; its completion found no note to mark.
    sql(`UPDATE quote SET is_active = 0 WHERE entity_id = ${quoteId}`);
    sql(`UPDATE bluebarry_checkout SET noted_at = UTC_TIMESTAMP() - INTERVAL 2 MINUTE WHERE quote_id = ${quoteId}`);
    await mockApi.reset();

    syncOrders();

    const started = (await sent('/data/magento/checkout-started')).find((r) => r.body.email === 'placed-first@example.com');
    expect(started?.body.completed).toBe(true);
  });
});
