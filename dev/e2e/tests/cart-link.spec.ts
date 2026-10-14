import { expect, test, type Page } from '@playwright/test';
import { randomUUID } from 'node:crypto';
import { addToCart, adminToken, BASE_URL, sql, stubAdvisor, THEME } from './helpers';

// A cart link (magento-internal#25): a quiz that is not on the store (a landing page, a standalone
// quiz link) sends the shopper to bluebarry/cart/link, which adds the lines the way bluebarry/cart/add
// does and opens the cart page, like Shopify's cart permalink.

const productId = (sku: string) => sql(`SELECT entity_id FROM catalog_product_entity WHERE sku = '${sku}'`);
const link = (items: string, extra = '') => `/bluebarry/cart/link/?items=${encodeURIComponent(items)}${extra}`;
// Where the shopper comes from: another site.
const LANDING_PAGE = 'https://landing.example/quiz';

async function cart(page: Page) {
  const sections = await (await page.request.get('/customer/section/load/?sections=cart&force_new_section_timestamp=true')).json();
  return sections.cart;
}

/** What the theme shows in the mini-cart: the cart data it keeps in the browser. */
const miniCartCount = (page: Page) =>
  page.evaluate(() => JSON.parse(localStorage.getItem('mage-cache-storage') || '{}').cart?.summary_count ?? 0);

const coupon = async (page: Page) => {
  const { items } = await cart(page);
  const quoteId = sql(`SELECT quote_id FROM quote_item WHERE item_id = ${Number(items?.[0]?.item_id ?? 0)}`);
  return quoteId ? sql(`SELECT IFNULL(coupon_code, '') FROM quote WHERE entity_id = ${quoteId}`) : '';
};

test.describe('cart link', () => {
  test.beforeEach(async ({ page }) => {
    await stubAdvisor(page, null, { visitor: false });
  });

  test('fills the cart from another site and opens it, the mini-cart included', async ({ page }) => {
    // The shopper was on the store before: the theme keeps their cart, one product, in the browser.
    await addToCart(page, 'bb-simple');
    await page.goto('/bb-simple.html');
    await expect.poll(() => miniCartCount(page)).toBe(1);

    const simple = productId('bb-simple');
    const red = productId('bb-configurable-red');
    const bundle = productId('bb-bundle-fixed');
    await page.goto(link(`${simple}:2,${red},${bundle}:1`), { referer: LANDING_PAGE });
    expect(page.url()).toBe(`${BASE_URL}/checkout/cart/`);

    const { summary_count, items } = await cart(page);
    expect(summary_count).toBe(5);
    const bySku = Object.fromEntries(items.map((i: any) => [i.product_sku, i]));
    expect(bySku['bb-simple'].qty).toBe(3);
    // A variant goes in through its configurable product with its options, a bundle with its defaults.
    expect(bySku['bb-configurable-red'].options).toEqual([expect.objectContaining({ value: 'BB Red' })]);
    expect(bySku['bb-bundle-fixed-bb-part-a-bb-part-b'].qty).toBe(1);
    // The mini-cart shows them, not what the browser kept from before.
    await expect.poll(() => miniCartCount(page)).toBe(5);
    await expect(page.getByText('could not be added')).toHaveCount(0);
  });

  test('what cannot be added is left out, and the cart page says so', async ({ page }) => {
    const simple = productId('bb-simple');
    // No such product, no number, a quantity that is none, too many.
    await page.goto(link(`999999,abc,${simple}:x,${productId('bb-configurable-red')}:1000,${simple}`), { referer: LANDING_PAGE });
    expect(page.url()).toBe(`${BASE_URL}/checkout/cart/`);
    await expect(page.getByText('Some of the products you chose could not be added to your cart.')).toBeVisible();
    const { items } = await cart(page);
    expect(items.map((i: any) => [i.product_sku, i.qty])).toEqual([['bb-simple', 1]]);
  });

  test('when nothing can be added the shopper still lands on the cart, told why', async ({ page }) => {
    const id = productId('bb-simple');
    sql(`UPDATE cataloginventory_stock_item SET is_in_stock = 0 WHERE product_id = ${id}`);
    sql(`UPDATE cataloginventory_stock_status SET stock_status = 0 WHERE product_id = ${id}`);
    try {
      await page.goto(link(id), { referer: LANDING_PAGE });
      expect(page.url()).toBe(`${BASE_URL}/checkout/cart/`);
      await expect(page.getByText('Sorry, the products you chose are sold out.')).toBeVisible();
    } finally {
      sql(`UPDATE cataloginventory_stock_item SET is_in_stock = 1 WHERE product_id = ${id}`);
      sql(`UPDATE cataloginventory_stock_status SET stock_status = 1 WHERE product_id = ${id}`);
    }
    await page.goto(link('999999'), { referer: LANDING_PAGE });
    await expect(page.getByText('Sorry, the products you chose could not be added to your cart.')).toBeVisible();
    expect((await cart(page)).summary_count ?? 0).toBe(0);

    // No lines at all: just the cart.
    const response = await page.request.get(link(''), { maxRedirects: 0 });
    expect(response.status()).toBe(302);
    expect(response.headers().location).toBe(`${BASE_URL}/checkout/cart/`);
  });

  test('is never cached: each shopper who opens it fills their own cart', async ({ page, browser }) => {
    const simple = productId('bb-simple');
    const url = link(`${simple}:1`);
    const response = await page.request.get(url, { maxRedirects: 0 });
    expect(response.status()).toBe(302);
    expect(response.headers()['cache-control']).toContain('no-store');
    // Crawlers following a quiz's link on a landing page leave it out and go no further.
    expect(response.headers()['x-robots-tag']).toBe('noindex, nofollow');
    expect((await cart(page)).summary_count).toBe(1);

    // The same link again, by another shopper: their cart, not an answer kept from the first.
    const other = await browser.newContext({ baseURL: BASE_URL });
    try {
      const otherPage = await other.newPage();
      await stubAdvisor(otherPage, null, { visitor: false });
      await otherPage.goto(url, { referer: LANDING_PAGE });
      expect((await cart(otherPage)).summary_count).toBe(1);
      // A shopper new to the store sees it in the mini-cart too.
      await expect.poll(() => miniCartCount(otherPage)).toBe(1);
      // Luma clears what asked it to reload, so the next page does not reload again. Hyvä never reads
      // that cookie (it reloads on private_content_version) and leaves it to expire; Hyvä's Luma
      // checkout fallback reloads once from it and clears it like Luma.
      if (THEME === 'luma') {
        await expect.poll(async () => (await other.cookies()).find((c) => c.name === 'section_data_clean')?.value ?? '').toBe('');
      }
    } finally {
      await other.close();
    }
    expect((await cart(page)).summary_count).toBe(1);
  });

  test("a quiz's code goes on the cart with the products; an unknown one is passed over", async ({ page, request, browser }) => {
    // A coupon of this store (a quiz's code is the merchant's coupon, or one bluebarry made here).
    const code = `QUIZ-${randomUUID().slice(0, 8).toUpperCase()}`;
    const headers = { Authorization: `Bearer ${await adminToken(request)}` };
    const created = await request.post('/rest/V1/salesRules', {
      headers,
      data: { rule: { name: 'Quiz code', website_ids: [1], customer_group_ids: [0, 1, 2, 3], is_active: true, coupon_type: 'SPECIFIC_COUPON',
        simple_action: 'by_percent', discount_amount: 10, uses_per_coupon: 0, uses_per_customer: 0 } },
    });
    expect(created.status(), await created.text()).toBe(200);
    const ruleId = (await created.json()).rule_id;
    try {
      const added = await request.post('/rest/V1/coupons', { headers, data: { coupon: { rule_id: ruleId, code, type: 0, is_primary: true } } });
      expect(added.status(), await added.text()).toBe(200);

      await page.goto(link(productId('bb-simple'), `&code=${encodeURIComponent(code)}`), { referer: LANDING_PAGE });
      expect(page.url()).toBe(`${BASE_URL}/checkout/cart/`);
      expect(await coupon(page)).toBe(code);

      // Another shopper: a context of their own (clearing this one's cookies races the cart page's own
      // requests, which can bring the first session back).
      const other = await browser.newContext({ baseURL: BASE_URL });
      try {
        const otherPage = await other.newPage();
        await stubAdvisor(otherPage, null, { visitor: false });
        await otherPage.goto(link(productId('bb-simple'), '&code=NO-SUCH-CODE'), { referer: LANDING_PAGE });
        expect((await cart(otherPage)).summary_count).toBe(1);
        expect(await coupon(otherPage)).toBe('');
      } finally {
        await other.close();
      }
    } finally {
      await request.delete(`/rest/V1/salesRules/${ruleId}`, { headers });
    }
  });
});
