import { createHmac, randomUUID } from 'node:crypto';
import { expect, test, type APIRequestContext } from '@playwright/test';
import { addToCart, adminToken, collectCspViolations, magento, MOCK_API, mockApi, sql, stubAdvisor } from './helpers';

// The bluebarry elements on the store's pages (magento-internal#9): the product check button, product
// chat and recommendation blocks, switched on in Studio and printed from the settings the website
// keeps, and the same elements as widgets. Only what the page's address decides is printed, so a page
// from the full page cache is right for every shopper: the cart comes from the shopper's own cart data.

const KEY = 'test-api-key';
const productId = (sku: string) => sql(`SELECT entity_id FROM catalog_product_entity WHERE sku = '${sku}'`);

async function setStorefront(settings: { placements?: object; productChecks?: Record<string, string> }) {
  await fetch(`${MOCK_API}/__storefront`, { method: 'PUT', body: JSON.stringify({ search: null, ...settings }) });
}

/** bluebarry's command, signed like DataApi's MagentoStoreClient. */
async function refresh(request: APIRequestContext) {
  const raw = JSON.stringify({ command: 'settings.refresh' });
  const at = Math.floor(Date.now() / 1000);
  const response = await request.post('/bluebarry/command/', {
    headers: {
      'Content-Type': 'application/json',
      'X-Bluebarry-Timestamp': String(at),
      'X-Bluebarry-Signature': createHmac('sha256', KEY).update(`${at}.${raw}`).digest('hex'),
    },
    data: raw,
  });
  expect(await response.json()).toMatchObject({ ok: true });
}

test.describe('placements from Studio', () => {
  test.describe.configure({ mode: 'serial' });
  const quiz = randomUUID();
  const productBlock = randomUUID();
  const cartBlock = randomUUID();
  let simple = '';
  let configurable = '';
  let variants: string[] = [];
  let cmsPageId = 0;

  test.beforeAll(() => {
    magento('config:set', 'bluebarry_module/general/api_key', KEY);
    simple = productId('bb-simple');
    configurable = productId('bb-configurable');
    variants = [productId('bb-configurable-blue'), productId('bb-configurable-red')].sort((a, b) => Number(a) - Number(b));
  });

  test.beforeEach(async () => {
    await mockApi.reset();
  });

  test.afterAll(async ({ request }) => {
    await mockApi.reset();
    if (cmsPageId) {
      await request.delete(`/rest/V1/cmsPage/${cmsPageId}`, { headers: { Authorization: `Bearer ${await adminToken(request)}` } });
    }
    sql("DELETE FROM core_config_data WHERE path = 'bluebarry_module/general/api_key'");
    sql("DELETE FROM flag WHERE flag_code IN ('bluebarry_storefront', 'bluebarry_product_checks', 'bluebarry_catalog')");
    magento('cache:flush');
  });

  test('nothing shows until Studio switches it on', async ({ page, request }) => {
    await setStorefront({});
    await refresh(request);
    await stubAdvisor(page, null, { visitor: false });

    await page.goto('/bb-simple.html'); // cached without any element
    await expect(page.locator('#product-addtocart-button')).toBeVisible();
    await expect(page.locator('.bluebarry-product-check, [data-bluebarry-product-chat-embed], [data-bluebarry-recommendations]')).toHaveCount(0);
  });

  test('switched on in Studio, the elements reach product pages, including ones already cached', async ({ page, request }) => {
    const violations = await collectCspViolations(page);
    await setStorefront({
      placements: {
        productCheck: { enabled: true, buttonText: 'Is this my size?' },
        productChat: { enabled: true },
        productRecommendations: { enabled: true, recommendationId: productBlock.toUpperCase() },
        // Off, and one bluebarry does not know: neither is printed.
        cartRecommendations: { enabled: false, recommendationId: cartBlock },
        somethingNewer: { enabled: true },
      },
      // The quiz is assigned to the product as bluebarry groups it: the configurable product, not its variants.
      productChecks: { [configurable]: quiz },
    });
    await refresh(request);
    await stubAdvisor(page, null, { visitor: false });

    // A configurable product: the button opens the quiz for the variant the page opens with.
    await page.goto('/bb-configurable.html');
    const button = page.locator('.bluebarry-product-check__btn');
    await expect(button).toHaveText('Is this my size?');
    await expect(button).toHaveAttribute('href', `#bluebarry:${quiz}/${variants[0]}`);
    await expect(page.locator('[data-bluebarry-product-chat-embed]')).toHaveCount(1);
    const recommendations = page.locator('[data-bluebarry-recommendations]');
    await expect(recommendations).toHaveAttribute('data-bluebarry-recommendations', productBlock);
    // From the product: all its variants, leading with the one the page opens with.
    await expect(recommendations).toHaveAttribute('data-product-ids', variants.join(','));
    await expect(recommendations).toHaveAttribute('data-anchor-id', variants[0]);
    await expect(recommendations).not.toHaveAttribute('data-cart-anchored', /.*/);

    // A product without a product check quiz has no button; the rest shows.
    await page.goto('/bb-simple.html');
    await expect(page.locator('#product-addtocart-button')).toBeVisible();
    await expect(page.locator('.bluebarry-product-check')).toHaveCount(0);
    await expect(page.locator('[data-bluebarry-product-chat-embed]')).toHaveCount(1);
    await expect(page.locator('[data-bluebarry-recommendations]')).toHaveAttribute('data-product-ids', simple);

    // Nothing of this is on the cart page: its recommendations are off.
    await page.goto('/checkout/cart/');
    await expect(page.locator('[data-bluebarry-recommendations]')).toHaveCount(0);
    expect(await violations()).toEqual([]);
  });

  test('a quiz taken off a product takes its button off the page', async ({ page, request }) => {
    await setStorefront({ placements: { productCheck: { enabled: true } }, productChecks: { [simple]: quiz } });
    await refresh(request);
    await stubAdvisor(page, null, { visitor: false });

    await page.goto('/bb-simple.html');
    // Without a text from Studio the button says the default.
    await expect(page.locator('.bluebarry-product-check__btn')).toHaveText('Is this right for me?');
    await expect(page.locator('.bluebarry-product-check__btn')).toHaveAttribute('href', `#bluebarry:${quiz}/${simple}`);
    await page.goto('/bb-configurable.html');
    await expect(page.locator('#product-addtocart-button')).toBeVisible();
    await expect(page.locator('.bluebarry-product-check')).toHaveCount(0);

    await setStorefront({ placements: { productCheck: { enabled: true } }, productChecks: {} });
    await refresh(request);
    await page.goto('/bb-simple.html');
    await expect(page.locator('#product-addtocart-button')).toBeVisible();
    await expect(page.locator('.bluebarry-product-check')).toHaveCount(0);
  });

  test('the cart block waits for the cart, which the shopper\'s own cart data names by variant', async ({ page, request }) => {
    await setStorefront({ placements: { cartRecommendations: { enabled: true, recommendationId: cartBlock } } });
    await refresh(request);
    await stubAdvisor(page, null);

    await addToCart(page, 'bb-configurable', { color: 'BB Red' });
    const cart = await page.evaluate(async () => {
      const response = await fetch('/customer/section/load/?sections=cart&force_new_section_timestamp=true', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      return (await response.json()).cart;
    });
    // The variant, as the catalog sync names it: not the configurable product the line shows.
    expect(cart.bluebarry_references).toEqual([productId('bb-configurable-red')]);

    await page.goto('/checkout/cart/');
    const block = page.locator('[data-bluebarry-recommendations]');
    await expect(block).toHaveAttribute('data-bluebarry-recommendations', cartBlock);
    await expect(block).toHaveAttribute('data-cart-anchored', 'true');
    // Hidden until the SDK has filled in the cart (the stub SDK never does).
    await expect(block).toBeHidden();
  });

  test('widgets place the same elements anywhere', async ({ page, request }) => {
    const violations = await collectCspViolations(page);
    await setStorefront({});
    await refresh(request);
    const widget = (type: string, parameters: Record<string, string>) =>
      `{{widget type="Bluebarry\\Bluebarry\\Block\\Widget\\${type}" ${Object.entries(parameters).map(([name, value]) => `${name}="${value}"`).join(' ')}}}`;
    const created = await request.post('/rest/V1/cmsPage', {
      headers: { Authorization: `Bearer ${await adminToken(request)}` },
      data: {
        page: {
          identifier: `bb-widgets-${Date.now()}`,
          title: 'bluebarry widgets',
          page_layout: '1column',
          active: true,
          content: [
            widget('QuizButton', { quiz: quiz.toUpperCase(), text: 'Find my bike' }),
            widget('QuizButton', { quiz: 'not-a-quiz', text: 'Never shown' }),
            widget('QuizPopup', { quiz, trigger: 'delay', delay: '0', cooldown: '7' }),
            widget('Recommendations', { block_id: cartBlock }),
            widget('ProductChat', {}),
            // Not a product page: no product, no button.
            widget('ProductCheck', { text: 'Never shown' }),
          ].join('\n'),
        },
      },
    });
    expect(created.status(), await created.text()).toBe(200);
    const cmsPage = await created.json();
    cmsPageId = cmsPage.id;

    await stubAdvisor(page, null, { visitor: false });
    // The SDK's own opener, to see what the popup widget asks for.
    await page.addInitScript(() => {
      (window as any).__opened = [];
      (window as any).barry = { openAdvisor: (...args: unknown[]) => (window as any).__opened.push(args) };
    });
    await page.goto(`/${cmsPage.identifier}`);

    const button = page.locator('.bluebarry-simple-quiz-button__btn');
    await expect(button).toHaveCount(1);
    await expect(button).toHaveText('Find my bike');
    await expect(button).toHaveAttribute('href', `#bluebarry:${quiz}`);
    await expect(page.locator('[data-bluebarry-recommendations]')).toHaveAttribute('data-cart-anchored', 'true');
    await expect(page.locator('[data-bluebarry-product-chat-embed]')).toHaveCount(1);
    await expect(page.locator('.bluebarry-product-check')).toHaveCount(0);
    await expect.poll(() => page.evaluate(() => (window as any).__opened)).toEqual([
      [quiz, undefined, { displayType: 'Dialog', pageContext: { source: 'quiz-popup-widget' } }],
    ]);
    // Once per cooldown: not again on the next page view.
    await page.reload();
    await expect(button).toHaveCount(1);
    await page.waitForTimeout(1500);
    expect(await page.evaluate(() => (window as any).__opened)).toEqual([]);
    expect(await violations()).toEqual([]);
  });
});
