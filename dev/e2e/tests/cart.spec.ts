import { expect, test, type Page } from '@playwright/test';
import { sql, stubAdvisor } from './helpers';

// Add to cart from bluebarry (magento-internal#4): the module's endpoint the SDK's Magento cart bridge
// posts to, called here exactly as the bridge calls it (form key from the cookie, the lines as JSON).

const productId = (sku: string) => sql(`SELECT entity_id FROM catalog_product_entity WHERE sku = '${sku}'`);

async function add(page: Page, items: { reference: string; quantity: number }[], withFormKey = true) {
  return page.evaluate(
    async ({ items, withFormKey }) => {
      const config = (window as any).barry.magento;
      const formKey = document.cookie.match(/form_key=([^;]+)/)?.[1] ?? '';
      const response = await fetch(config.addToCartUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: new URLSearchParams({ ...(withFormKey ? { form_key: formKey } : {}), items: JSON.stringify(items) }).toString(),
      });
      return { status: response.status, body: await response.json() };
    },
    { items, withFormKey },
  );
}

async function cart(page: Page) {
  const sections = await (await page.request.get('/customer/section/load/?sections=cart&force_new_section_timestamp=true')).json();
  return sections.cart;
}

test.describe('add to cart from bluebarry', () => {
  test.beforeEach(async ({ page }) => {
    await stubAdvisor(page, null, { visitor: false });
    await page.goto('/bb-simple.html');
    await expect.poll(() => page.evaluate(() => (window as any).barry?.magento?.addToCartUrl)).toContain('/bluebarry/cart/add');
  });

  test('the storefront tells the SDK where to add and where the cart is', async ({ page }) => {
    const config = await page.evaluate(() => (window as any).barry.magento);
    expect(config).toEqual({ addToCartUrl: 'http://localhost:8080/bluebarry/cart/add/', cartUrl: 'http://localhost:8080/checkout/cart/' });
  });

  test('a configurable product variant lands with its options, as the product page adds it', async ({ page }) => {
    const result = await add(page, [{ reference: productId('bb-configurable-red'), quantity: 2 }]);
    expect(result).toEqual({ status: 200, body: { success: true, skipped: [] } });

    const { summary_count, items } = await cart(page);
    expect(summary_count).toBe(2);
    expect(items[0].product_sku).toBe('bb-configurable-red');
    expect(items[0].options).toEqual([expect.objectContaining({ value: 'BB Red' })]);
  });

  test('a kit lands line by line and reports what could not be added', async ({ page }) => {
    const bundle = productId('bb-bundle-fixed');
    const result = await add(page, [
      { reference: productId('bb-simple'), quantity: 1 },
      { reference: bundle, quantity: 1 }, // with its default selections
      { reference: '999999', quantity: 1 }, // no such product
      { reference: { id: 1 } as any, quantity: 1 }, // malformed
      'not a line' as any,
    ]);
    expect(result.body).toEqual({ success: true, skipped: ['999999', '', ''] });

    const { items } = await cart(page);
    // A bundle's line carries its parts in its SKU.
    expect(items.map((i: any) => i.product_sku).sort()).toEqual(['bb-bundle-fixed-bb-part-a-bb-part-b', 'bb-simple']);
  });

  test('lines beyond the limits are reported as not added, not dropped', async ({ page }) => {
    const simple = productId('bb-simple');
    const lines = [{ reference: productId('bb-configurable-red'), quantity: 1000 }, ...Array.from({ length: 20 }, () => ({ reference: simple, quantity: 1 }))];
    const result = await add(page, lines);
    // Over 999 of one line, and the 21st line.
    expect(result.body).toEqual({ success: true, skipped: [productId('bb-configurable-red'), simple] });
  });

  test('a line that cannot be added leaves the cart as it was', async ({ page }) => {
    const simple = productId('bb-simple');
    expect((await add(page, [{ reference: simple, quantity: 2 }])).body.success).toBe(true);

    // More than there is: Magento refuses it, and takes the product's line out of its cart in memory.
    const result = await add(page, [{ reference: productId('bb-configurable-red'), quantity: 1 }, { reference: simple, quantity: 999 }]);
    expect(result.body).toEqual({ success: true, skipped: [simple] });

    const { items } = await cart(page);
    expect(Object.fromEntries(items.map((i: any) => [i.product_sku, i.qty]))).toEqual({ 'bb-simple': 2, 'bb-configurable-red': 1 });
  });

  test('a fraction is added only for a product sold in decimal quantities', async ({ page }) => {
    const simple = productId('bb-simple');
    expect((await add(page, [{ reference: simple, quantity: 1.5 }])).body).toEqual({ success: false, skipped: [simple], failure: 'error' });

    sql(`UPDATE cataloginventory_stock_item SET is_qty_decimal = 1 WHERE product_id = ${simple}`);
    try {
      expect((await add(page, [{ reference: simple, quantity: 1.5 }])).body).toEqual({ success: true, skipped: [] });
      expect((await cart(page)).items[0].qty).toBe(1.5);
    } finally {
      sql(`UPDATE cataloginventory_stock_item SET is_qty_decimal = 0 WHERE product_id = ${simple}`);
    }
  });

  test('a sold-out product is reported as sold out', async ({ page }) => {
    const id = productId('bb-simple');
    sql(`UPDATE cataloginventory_stock_item SET is_in_stock = 0 WHERE product_id = ${id}`);
    sql(`UPDATE cataloginventory_stock_status SET stock_status = 0 WHERE product_id = ${id}`);
    try {
      const result = await add(page, [{ reference: id, quantity: 1 }]);
      expect(result.body).toEqual({ success: false, skipped: [id], failure: 'soldOut' });
      // With a line that failed for another reason too, it is not only a matter of stock.
      const mixed = await add(page, [{ reference: id, quantity: 1 }, { reference: '999999', quantity: 1 }]);
      expect(mixed.body).toEqual({ success: false, skipped: [id, '999999'], failure: 'error' });
    } finally {
      sql(`UPDATE cataloginventory_stock_item SET is_in_stock = 1 WHERE product_id = ${id}`);
      sql(`UPDATE cataloginventory_stock_status SET stock_status = 1 WHERE product_id = ${id}`);
    }
  });

  test('items not sent as JSON are a bad request', async ({ page }) => {
    const status = await page.evaluate(async () => {
      const formKey = document.cookie.match(/form_key=([^;]+)/)?.[1] ?? '';
      const response = await fetch((window as any).barry.magento.addToCartUrl, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: `form_key=${formKey}&items[]=x`,
      });
      return response.status;
    });
    expect(status).toBe(400);
  });

  test('without the form key nothing is added', async ({ page }) => {
    const result = await add(page, [{ reference: productId('bb-simple'), quantity: 1 }], false);
    expect(result.status).toBe(403);
    expect(result.body.success).toBe(false);
    expect((await cart(page)).summary_count ?? 0).toBe(0);
  });
});
