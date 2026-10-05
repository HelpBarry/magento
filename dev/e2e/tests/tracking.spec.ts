import { expect, test, type APIRequestContext } from '@playwright/test';
import { addToCart, adminToken, BASE_URL, magento, newAdvisorIds, sql, stubAdvisor } from './helpers';

// The page as bluebarry's SDK tracks it (magento-internal#10): what each page says about itself (only
// what its address decides, so the full page cache is safe), and every add to the cart noted for the
// SDK to report, with the shopper's consent.

const productId = (sku: string) => sql(`SELECT entity_id FROM catalog_product_entity WHERE sku = '${sku}'`);
const pageContext = (page: import('@playwright/test').Page) => page.evaluate(() => (window as any).barry.pageContext);
const noted = async (page: import('@playwright/test').Page) => {
  const cookie = (await page.context().cookies()).find((c) => c.name === 'bb_cart_added');
  return cookie ? JSON.parse(decodeURIComponent(cookie.value)) : [];
};

async function rest(request: APIRequestContext, method: 'post' | 'delete', url: string, data?: unknown) {
  const response = await request[method](url, { headers: { Authorization: `Bearer ${await adminToken(request)}` }, data });
  expect(response.status(), await response.text()).toBe(200);
  return response.json();
}

test.describe('page tracking', () => {
  test.describe.configure({ mode: 'serial' });
  let categoryId = 0;

  test.beforeAll(async ({ request }) => {
    categoryId = (await rest(request, 'post', '/rest/V1/categories', {
      category: { parent_id: 2, name: 'BB Tracking', is_active: true, custom_attributes: [{ attribute_code: 'url_key', value: 'bb-tracking' }] },
    })).id;
    await request.post(`/rest/V1/categories/${categoryId}/products`, {
      headers: { Authorization: `Bearer ${await adminToken(request)}` },
      data: { productLink: { sku: 'bb-simple', position: 0, category_id: String(categoryId) } },
    });
    magento('indexer:reindex', 'catalog_category_product', 'catalog_product_category');
  });

  test.afterAll(async ({ request }) => {
    if (categoryId) await rest(request, 'delete', `/rest/V1/categories/${categoryId}`);
    sql("DELETE FROM core_config_data WHERE path = 'web/cookie/cookie_restriction'");
    magento('cache:flush');
  });

  test('a product page names the product and the variant it opens with, for its view and product chat', async ({ page }) => {
    await stubAdvisor(page, null, { visitor: false });
    await page.goto('/bb-configurable.html');
    const parent = productId('bb-configurable');
    // The first variant for sale, by id, as the catalog sync orders them.
    const first = [productId('bb-configurable-blue'), productId('bb-configurable-red')].sort((a, b) => Number(a) - Number(b))[0];
    expect(await pageContext(page)).toMatchObject({ type: 'product', productId: parent, productReference: first });
    expect(await page.evaluate(() => (window as any).barry.chat)).toMatchObject({
      groupReference: parent, variantReference: first, context: { pageType: 'product' },
    });
    expect(await page.evaluate(() => (window as any).barry.trackPageViews)).toBe(true);
  });

  test('an explicit chat opt-out keeps product chat disabled while the advisor SDK loads', async ({ page }) => {
    await page.addInitScript(() => { (window as any).barry = { chat: false }; });
    await stubAdvisor(page, null, { visitor: false });
    await page.goto('/bb-configurable.html');

    expect(await page.evaluate(() => (window as any).barry.chat)).toBe(false);
    await expect.poll(() => page.evaluate(() => (window as any).__bbAdvisorLoaded)).toBe(true);
    expect(await pageContext(page)).toMatchObject({ type: 'product', productId: productId('bb-configurable') });
    expect(await page.evaluate(() => (window as any).barry.trackPageViews)).toBe(true);
  });

  test('a category page names the category', async ({ page }) => {
    await stubAdvisor(page, null, { visitor: false });
    await page.goto('/bb-tracking.html');
    expect(await pageContext(page)).toMatchObject({ type: 'collection', collectionId: String(categoryId) });
    expect(await page.evaluate(() => (window as any).barry.chat.context)).toMatchObject({ pageType: 'collection', collection: 'BB Tracking', collectionId: String(categoryId) });
  });

  test('an add to the cart is noted for the SDK, as the variant the shopper picked', async ({ page }) => {
    await stubAdvisor(page, newAdvisorIds());
    await addToCart(page, 'bb-configurable', { color: 'BB Red' });
    const [add] = await noted(page);
    expect(add).toMatchObject({ r: productId('bb-configurable-red'), q: 1 });
    expect(add.i).toMatch(/^[a-z0-9]+$/);
  });

  test("editing a line in the cart is not an add", async ({ page }) => {
    await stubAdvisor(page, newAdvisorIds());
    await addToCart(page, 'bb-configurable', { color: 'BB Red' });
    expect(await noted(page)).toHaveLength(1);
    const parent = productId('bb-configurable'), red = productId('bb-configurable-red');
    const attribute = sql(`SELECT attribute_id FROM catalog_product_super_attribute WHERE product_id = ${parent} LIMIT 1`);
    const option = sql(`SELECT value FROM catalog_product_entity_int WHERE entity_id = ${red} AND attribute_id = ${attribute} AND store_id = 0`);
    const sections = await (await page.request.get('/customer/section/load/?sections=cart&force_new_section_timestamp=true')).json();
    const itemId = sections.cart.items.find((i: any) => String(i.product_id) === parent).item_id;
    const formKey = (await page.context().cookies()).find((c) => c.name === 'form_key')!.value;

    // The cart's Edit, then Update Cart with another quantity: Magento re-adds the line (Quote::updateItem).
    await page.request.post(`/checkout/cart/updateItemOptions/id/${itemId}/`, {
      form: { form_key: formKey, id: String(itemId), product: parent, qty: '3', [`super_attribute[${attribute}]`]: option },
    });

    const cart = await (await page.request.get('/customer/section/load/?sections=cart&force_new_section_timestamp=true')).json();
    expect(Number(cart.cart.summary_count)).toBe(3);
    expect(await noted(page)).toHaveLength(1);
  });

  test('every line of a kit bluebarry adds is noted, with the quantity it added', async ({ page }) => {
    await stubAdvisor(page, newAdvisorIds());
    await page.goto('/bb-simple.html');
    await expect.poll(() => page.evaluate(() => (window as any).barry?.magento?.addToCartUrl)).toBeTruthy();
    const items = [{ reference: productId('bb-simple'), quantity: 2 }, { reference: productId('bb-configurable-red'), quantity: 1 }];
    const status = await page.evaluate(async (items) => {
      const formKey = document.cookie.match(/form_key=([^;]+)/)?.[1] ?? '';
      const response = await fetch((window as any).barry.magento.addToCartUrl, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: new URLSearchParams({ form_key: formKey, items: JSON.stringify(items) }).toString(),
      });
      return response.status;
    }, items);
    expect(status).toBe(200);

    expect((await noted(page)).map((a: any) => [a.r, a.q])).toEqual([[productId('bb-simple'), 2], [productId('bb-configurable-red'), 1]]);
  });

  test("product chat gets the product's categories in this store's tree", async ({ page }) => {
    await stubAdvisor(page, null, { visitor: false });
    await page.goto('/bb-simple.html');
    expect(await page.evaluate(() => (window as any).barry.chat.context.productCollectionIds)).toContain(String(categoryId));
  });

  test("the theme's cart changes reach the SDK with the cart itself", async ({ page }) => {
    await page.addInitScript(() => {
      document.addEventListener('cart:updated', (e) => ((window as any).__cartEvents ||= []).push((e as CustomEvent).detail));
    });
    await stubAdvisor(page, null, { visitor: false });
    await addToCart(page, 'bb-simple');

    // Luma relays customer data's cart update, Hyvä its private-content-loaded (after its reload).
    await expect.poll(() => page.evaluate(() => ((window as any).__cartEvents ?? []).some((d: any) => Number(d?.cart?.summary_count) > 0))).toBe(true);

    // The next page, the cart unchanged: its cart is relayed too, for popup cart rules.
    await page.goto('/');
    await expect.poll(() => page.evaluate(() => ((window as any).__cartEvents ?? []).some((d: any) => Number(d?.cart?.summary_count) > 0))).toBe(true);
  });

  test('advanced search results are a search page', async ({ page }) => {
    await stubAdvisor(page, null, { visitor: false });
    await page.goto('/catalogsearch/advanced/result/?name=bluebarry');
    expect(await pageContext(page)).toMatchObject({ type: 'search' });
  });

  test('a variant with a page of its own is still its configurable product in product chat', async ({ page, request }) => {
    const setVisibility = (visibility: number) => rest(request, 'post', '/rest/all/V1/products', {
      product: { sku: 'bb-configurable-red', visibility, custom_attributes: [{ attribute_code: 'url_key', value: 'bb-configurable-red' }] },
    });
    await setVisibility(4);
    try {
      await stubAdvisor(page, null, { visitor: false });
      await page.goto('/bb-configurable-red.html');
      expect(await page.evaluate(() => (window as any).barry.chat)).toMatchObject({
        groupReference: productId('bb-configurable'), variantReference: productId('bb-configurable-red'),
      });
    } finally {
      await setVisibility(1);
    }
  });

  test('a shopper bluebarry never saw is not noted', async ({ page }) => {
    await stubAdvisor(page, null, { visitor: false });
    await addToCart(page, 'bb-simple');
    expect(await noted(page)).toEqual([]);
  });

  test("with Magento's cookie restriction mode on, nothing is tracked or noted until the shopper allows cookies", async ({ page }) => {
    magento('config:set', 'web/cookie/cookie_restriction', '1');
    magento('cache:flush');
    await stubAdvisor(page, newAdvisorIds());
    // A consent tool that allows everything does not override Magento's missing consent.
    await page.addInitScript(() => { (window as any).barry = { ...(window as any).barry, analyticsAllowed: () => true }; });
    await page.goto('/bb-simple.html');
    expect(await page.evaluate(() => (window as any).barry.analyticsAllowed())).toBe(false);
    await addToCart(page, 'bb-simple');
    expect(await noted(page)).toEqual([]);

    // The shopper allows cookies (Magento's own notice sets this).
    await page.context().addCookies([{ name: 'user_allowed_save_cookie', value: encodeURIComponent('{"1":1}'), url: BASE_URL }]);
    expect(await page.evaluate(() => (window as any).barry.analyticsAllowed())).toBe(true);
    await addToCart(page, 'bb-simple');
    expect(await noted(page)).toHaveLength(1);
  });
});
