import { expect, test, type APIRequestContext } from '@playwright/test';
import { adminToken, magento, MOCK_API, mockApi, sql, stubAdvisor } from './helpers';

// Collection pages on a category's own address: bluebarry renders them and the module keeps them, so
// what the shopper's request reads is only the store's own. The page's HTML here stands in for what
// ReactLandingPages renders; that it matches the SDK's is held in the bluebarry repository.

const KEY = 'test-api-key';
const GRID = '<div class="bluebarry-collection-grid" data-bluebarry-collection-grid data-marker="from-bluebarry">the page</div>';

async function rest(request: APIRequestContext, method: 'post' | 'put', path: string, data: unknown) {
  const response = await request[method](path, { headers: { Authorization: `Bearer ${await adminToken(request)}` }, data });
  expect(response.ok(), await response.text()).toBeTruthy();
  return response.json();
}

async function setPages(categories: Record<string, object>, statuses: { status?: number; listStatus?: number; listTenant?: string } = {}) {
  await fetch(`${MOCK_API}/__collection-pages`, { method: 'PUT', body: JSON.stringify({ categories, status: 200, listStatus: 200, ...statuses }) });
}
const refresh = () => magento('bluebarry:collection-pages:refresh');

test.describe('collection pages on the store', () => {
  test.describe.configure({ mode: 'serial' });
  let category = '';
  let path = '';

  test.beforeAll(async ({ request }) => {
    magento('config:set', 'bluebarry_module/general/api_key', KEY);
    const urlKey = `bb-collection-${Date.now()}`;
    category = String((await rest(request, 'post', '/rest/V1/categories', {
      category: { parent_id: 2, name: 'BB Collection', is_active: true, custom_attributes: [{ attribute_code: 'url_key', value: urlKey }] },
    })).id);
    await rest(request, 'post', `/rest/V1/categories/${category}/products`, { productLink: { sku: 'bb-simple', position: 0, category_id: category } });
    magento('indexer:reindex', 'catalog_category_product', 'catalog_product_category');
    path = `${urlKey}.html`;
  });

  test.afterAll(async ({ request }) => {
    await request.delete(`/rest/V1/categories/${category}`, { headers: { Authorization: `Bearer ${await adminToken(request)}` } });
  });

  test.beforeEach(async ({ page }) => {
    await mockApi.reset();
    await stubAdvisor(page, null);
  });

  test.afterEach(async () => {
    await setPages({});
    refresh();
    // Gone rows wait for their second purge; a later test starts without them.
    sql('DELETE FROM bluebarry_collection_page');
    magento('cache:clean', 'config', 'full_page');
  });

  test('a category cached as Magento\'s page shows its collection page once it has one, and Magento\'s again without', async ({ page }) => {
    // Cached as Magento's own page first: this is what a merchant's live store has.
    await page.goto(`/${path}`);
    await expect(page.locator('.products.wrapper, #product-list, .products-grid').first()).toBeVisible();
    await page.goto(`/${path}`);

    await setPages({ [category]: { html: GRID, css: '.x{color:red}', replaces: { title: true, description: true } } });
    refresh();

    await page.goto(`/${path}`);
    await expect(page.locator('[data-marker="from-bluebarry"]')).toBeVisible();
    await expect(page.locator('.products.wrapper, #product-list, .products-grid')).toHaveCount(0);
    await expect(page.locator('.filter-options, #layered-filter-block')).toHaveCount(0);
    await expect(page.locator('.page-title')).toHaveCount(0);
    await expect(page.locator('body')).toHaveClass(/page-layout-1column/);

    await setPages({});
    refresh();
    await page.goto(`/${path}`);
    await expect(page.locator('[data-marker="from-bluebarry"]')).toHaveCount(0);
    await expect(page.locator('.products.wrapper, #product-list, .products-grid').first()).toBeVisible();
  });

  test('Magento\'s title stays when the page has no header of its own', async ({ page }) => {
    await setPages({ [category]: { html: GRID, replaces: { title: false, description: false } } });
    refresh();
    await page.goto(`/${path}`);
    await expect(page.locator('[data-marker="from-bluebarry"]')).toBeVisible();
    await expect(page.locator('.page-title')).toBeVisible();
  });

  test('a page from the page cache needs nothing from bluebarry', async ({ page, request }) => {
    await setPages({ [category]: { html: GRID } });
    refresh();
    await request.get(`/${path}`); // fills the cache
    await mockApi.reset();
    await mockApi.respondWith({ status: 500 });
    const started = Date.now();
    await page.goto(`/${path}`);
    await expect(page.locator('[data-marker="from-bluebarry"]')).toBeVisible();
    expect(Date.now() - started).toBeLessThan(10000);
    const fromStore = (await mockApi.requests()).filter((r) => r.path.includes('collection') || r.path.includes('__store-page'));
    expect(fromStore).toEqual([]);
  });

  test('an update reaches a cached page', async ({ page }) => {
    await setPages({ [category]: { html: GRID } });
    refresh();
    await page.goto(`/${path}`);
    await setPages({ [category]: { html: GRID.replace('the page', 'the new page') } });
    refresh();
    await page.goto(`/${path}`);
    await expect(page.locator('[data-marker="from-bluebarry"]')).toHaveText('the new page');
  });

  test('a bad answer keeps what the store has', async ({ page }) => {
    await setPages({ [category]: { html: GRID } });
    refresh();

    await setPages({}, { listStatus: 500 });
    refresh();
    await page.goto(`/${path}`);
    await expect(page.locator('[data-marker="from-bluebarry"]')).toBeVisible();

    await setPages({ [category]: { html: GRID } }, { status: 500 });
    refresh();
    await page.goto(`/${path}`);
    await expect(page.locator('[data-marker="from-bluebarry"]')).toBeVisible();
  });

  test('a list of another company is not kept', async ({ page }) => {
    await setPages({ [category]: { html: GRID } }, { listTenant: 'cccccccc-cccc-4ccc-8ccc-cccccccccccc' });
    refresh();
    await page.goto(`/${path}`);
    await expect(page.locator('[data-marker="from-bluebarry"]')).toHaveCount(0);
  });

  test('a fragment that is not a page is not kept', async ({ page }) => {
    await setPages({ [category]: { html: '<div>no grid</div>' } });
    refresh();
    await page.goto(`/${path}`);
    await expect(page.locator('text=no grid')).toHaveCount(0);
    await expect(page.locator('.products.wrapper, #product-list, .products-grid').first()).toBeVisible();
  });

  test('a product in a subcategory also names the anchor category above it, as that category\'s page lists it', async ({ request }) => {
    const child = String((await rest(request, 'post', '/rest/V1/categories', {
      category: { parent_id: Number(category), name: `BB Collection child ${Date.now()}`, is_active: true },
    })).id);
    await rest(request, 'post', `/rest/V1/categories/${child}/products`, { productLink: { sku: 'bb-configurable', position: 0, category_id: child } });

    magento('bluebarry:catalog:sync', '--all');
    const synced = (await mockApi.requests()).filter((r) => r.path === '/data/magento/products/sync').flatMap((r) => (r.body as any).products ?? []);
    // The configurable's variants: only the subcategory has them, the parent only has bb-simple itself.
    const named = synced.filter((p: any) => p.groupId && p.properties?.some((x: any) => x.propertyName === 'categories' && x.value.includes('BB Collection')));
    expect(named.length).toBeGreaterThan(0);
    await request.delete(`/rest/V1/categories/${child}`, { headers: { Authorization: `Bearer ${await adminToken(request)}` } });
  });

  test('the module sends its API key to the list and not to the page', async () => {
    await setPages({ [category]: { html: GRID } });
    refresh();
    const requests = await mockApi.requests();
    expect(requests.find((r) => r.path === '/data/magento/collection-pages')?.headers.authorization).toBe(KEY);
    const fetched = requests.find((r) => r.path.startsWith('/__store-page/'));
    expect(fetched).toBeTruthy();
    expect(fetched!.headers.authorization).toBeUndefined();
  });
});
