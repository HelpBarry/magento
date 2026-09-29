import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test, type APIRequestContext } from '@playwright/test';
import {
  addToCart,
  adminLogin,
  adminToken,
  checkoutAsGuest,
  magento,
  mockApi,
  openBluebarrySettings,
  sql,
  stubAdvisor,
  type RecordedRequest,
} from './helpers';

// Catalog sync (magento-internal#7): the whole catalog when a website connects, then every change:
// a sale once Magento indexed its price, a deleted product, stock an order took, and a bluebarry outage.

const SKU = 'bb-catalog-e2e';
// 1x1 PNG
const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';
const BIN = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../bin');

/** bin/magento bluebarry:catalog:sync, which exits 1 while products wait on an error. */
function syncCatalog(...args: string[]): { code: number; output: string } {
  try {
    return { code: 0, output: execFileSync(path.join(BIN, 'magento'), ['bluebarry:catalog:sync', ...args], { encoding: 'utf8' }) };
  } catch (e: any) {
    return { code: e.status, output: `${e.stdout ?? ''}${e.stderr ?? ''}` };
  }
}

const syncs = async (): Promise<RecordedRequest[]> =>
  (await mockApi.requests()).filter((r) => r.path === '/data/magento/products/sync');

const sent = async (reference: string) =>
  (await syncs()).flatMap((r) => r.body.products).filter((p: any) => p.reference === reference);

const property = (product: any, name: string) => product.properties.find((p: any) => p.propertyName === name)?.value;

const productId = (sku: string) => sql(`SELECT entity_id FROM catalog_product_entity WHERE sku = '${sku}'`);
const queued = (id: string) => Number(sql(`SELECT COUNT(*) FROM bluebarry_product_sync WHERE product_id = ${Number(id)}`));

/** Runs PHP in Magento, as the admin's mass actions and their queue consumer do. */
function inMagento(code: string): string {
  const script = `<?php require 'app/bootstrap.php';
    $om = \\Magento\\Framework\\App\\Bootstrap::create(BP, $_SERVER)->getObjectManager();
    $om->get(\\Magento\\Framework\\App\\State::class)->setAreaCode('adminhtml');
    ${code}`;
  return execFileSync(path.join(BIN, 'shell'), ['-c', 'cd /var/www/html && php'], { input: script, encoding: 'utf8' });
}

async function rest(request: APIRequestContext, method: 'post' | 'put' | 'delete', url: string, data?: unknown) {
  const response = await request[method](url, { headers: { Authorization: `Bearer ${await adminToken(request)}` }, data });
  expect(response.status(), await response.text()).toBe(200);
  return response.json();
}

test.describe('catalog sync', () => {
  test.describe.configure({ mode: 'serial' });
  let categoryId = 0;

  test.beforeAll(async ({ request }) => {
    magento('config:set', 'bluebarry_module/general/api_key', 'test-api-key');
    sql("DELETE FROM flag WHERE flag_code = 'bluebarry_catalog'");
    categoryId = (await rest(request, 'post', '/rest/V1/categories', { category: { parent_id: 2, name: 'BB Catalog', is_active: true } })).id;
    const image = (name: string, types: string[]) => ({
      media_type: 'image', label: name, position: types.length ? 1 : 2, disabled: false, types,
      content: { base64_encoded_data: PNG, type: 'image/png', name: `${name}.png` },
    });
    await rest(request, 'post', '/rest/all/V1/products', {
      product: {
        sku: SKU, name: 'Bluebarry Catalog Product', attribute_set_id: 4, price: 30, status: 1, visibility: 4, type_id: 'simple', weight: 1,
        extension_attributes: { website_ids: [1], category_links: [{ position: 0, category_id: String(categoryId) }], stock_item: { qty: 50, is_in_stock: true } },
        custom_attributes: [{ attribute_code: 'url_key', value: SKU }, { attribute_code: 'tax_class_id', value: '2' }],
        media_gallery_entries: [image('bb-front', ['image', 'small_image', 'thumbnail']), image('bb-back', [])],
      },
    });
    // As the indexer cron would: in stock, then priced (a product the stock index has not seen is left out of the price index).
    magento('indexer:reindex', 'cataloginventory_stock', 'inventory', 'catalog_product_price');
  });

  test.beforeEach(async () => {
    await mockApi.reset();
  });

  test.afterAll(async ({ request }) => {
    await mockApi.reset();
    const token = await adminToken(request);
    await request.delete(`/rest/V1/products/${SKU}`, { headers: { Authorization: `Bearer ${token}` } });
    if (categoryId) await request.delete(`/rest/V1/categories/${categoryId}`, { headers: { Authorization: `Bearer ${token}` } });
    sql("DELETE FROM core_config_data WHERE path = 'bluebarry_module/general/api_key'");
    sql("DELETE FROM flag WHERE flag_code = 'bluebarry_catalog'");
    sql('DELETE FROM bluebarry_product_sync');
    magento('cache:flush');
  });

  test('a newly connected website sends the whole catalog', async () => {
    const run = syncCatalog();
    expect(run.output).toContain('0 waiting');

    const requests = await syncs();
    expect(requests.length).toBeGreaterThan(0);
    for (const r of requests) {
      expect(r.contractErrors).toEqual([]);
      expect(r.headers.authorization).toBe('test-api-key');
      expect(r.headers['bb-tenant-id'], 'a key-authenticated call must not name the tenant').toBeUndefined();
    }

    const [product] = await sent(productId(SKU));
    expect(product).toMatchObject({
      name: 'Bluebarry Catalog Product',
      url: `http://localhost:8080/${SKU}.html`,
      inactive: false,
    });
    expect(product.imageUrl).toMatch(/\/media\/catalog\/product\/.*bb-front.*\.png$/);
    expect(product.secondaryImages).toHaveLength(1);
    expect(property(product, 'price')).toBe(30);
    expect(property(product, 'compare_at_price')).toBeNull();
    expect(property(product, 'categories')).toEqual(['BB Catalog']);
    expect(property(product, 'stock_status')).toBe('instock');
    expect(property(product, 'stock_quantity')).toBe(50);
    expect(property(product, 'currency')).toBe('EUR');

    // A configurable product's children are the products, grouped under it; it has none of its own.
    const parent = productId('bb-configurable');
    const [red] = await sent(productId('bb-configurable-red'));
    expect(red).toMatchObject({ groupId: parent, name: 'Bluebarry Configurable Product', url: 'http://localhost:8080/bb-configurable.html' });
    expect(property(red, 'attr_color')).toBe('BB Red');
    expect(await sent(parent)).toEqual([]);
    expect(requests.flatMap((r) => r.body.reconcileGroupIds)).toContain(parent);

    // A bundle part without a page of its own is no product in bluebarry.
    expect(await sent(productId('bb-part-a'))).toEqual([{ reference: productId('bb-part-a'), inactive: true }]);
  });

  test('a sale goes out once Magento indexed its price', async ({ request }) => {
    const id = productId(SKU);
    await rest(request, 'put', `/rest/all/V1/products/${SKU}`, {
      product: { sku: SKU, custom_attributes: [{ attribute_code: 'special_price', value: '25' }] },
    });
    expect(queued(id)).toBe(1);

    syncCatalog(); // the price index ("Update by Schedule") has not run: it waits
    expect(await sent(id)).toEqual([]);
    expect(queued(id)).toBe(1);

    magento('indexer:reindex', 'catalog_product_price');
    syncCatalog();
    const [product] = await sent(id);
    expect(property(product, 'price')).toBe(25);
    expect(property(product, 'compare_at_price')).toBe(30);
    expect(queued(id)).toBe(0);
  });

  test('an order sends the stock it left', async ({ page }) => {
    const id = productId('bb-simple');
    syncCatalog();
    await mockApi.reset();

    await stubAdvisor(page, null, { visitor: false });
    await addToCart(page, 'bb-simple');
    await checkoutAsGuest(page, 'catalog-stock@example.com');
    expect(queued(id)).toBe(1);

    syncCatalog();
    const [product] = await sent(id);
    // Reserved by the order, so no longer for sale, although the quantity only drops when it ships.
    const onHand = Number(sql(`SELECT qty FROM cataloginventory_stock_item WHERE product_id = ${id}`));
    const reserved = Number(sql(`SELECT COALESCE(SUM(quantity), 0) FROM inventory_reservation WHERE sku = 'bb-simple'`));
    expect(reserved).toBeLessThan(0);
    expect(property(product, 'stock_quantity')).toBe(Math.floor(onHand + reserved));
  });

  test('prices are sent as the catalog shows them, with tax when it shows tax', async () => {
    const id = productId('bb-simple'); // 100 without tax, NL 21%
    magento('config:set', 'tax/display/type', '2');
    try {
      syncCatalog('--all');
      expect(property((await sent(id))[0], 'price')).toBe(121);
    } finally {
      sql("DELETE FROM core_config_data WHERE path = 'tax/display/type'");
      magento('cache:flush', 'config');
    }
  });

  test("a bundle's part changing resends the bundles it is in", async ({ request }) => {
    syncCatalog();
    await mockApi.reset();
    const setPrice = async (price: number) => {
      await rest(request, 'put', '/rest/all/V1/products/bb-part-a', { product: { sku: 'bb-part-a', price } });
      magento('indexer:reindex', 'catalog_product_price');
    };
    await setPrice(26);
    try {
      syncCatalog();
      const references = (await syncs()).flatMap((r) => r.body.products).map((p: any) => p.reference);
      expect(references).toEqual(expect.arrayContaining([productId('bb-part-a'), productId('bb-bundle-dynamic'), productId('bb-bundle-fixed')]));
    } finally {
      await setPrice(30); // the fixtures' price, which the order specs' bundles are built from
    }
  });

  test('a bundle whose part sold out is sold out, once Magento indexed the stock', async ({ request }) => {
    const reindex = () => magento('indexer:reindex', 'cataloginventory_stock', 'inventory', 'catalog_product_price');
    const stock = async (inStock: boolean) => {
      await rest(request, 'put', '/rest/all/V1/products/bb-part-a', {
        product: { sku: 'bb-part-a', extension_attributes: { stock_item: { qty: inStock ? 1000 : 0, is_in_stock: inStock } } },
      });
    };
    await stock(false);
    try {
      // The stock index ("Update by Schedule") has not run: the bundle would still read in stock, so it waits.
      syncCatalog();
      expect(await sent(productId('bb-bundle-fixed'))).toEqual([]);
      expect(queued(productId('bb-part-a'))).toBe(1);

      reindex(); // as the indexer cron would
      syncCatalog();
      const [bundle] = await sent(productId('bb-bundle-fixed'));
      expect(property(bundle, 'stock_status')).toBe('outofstock');
    } finally {
      await stock(true);
      reindex();
    }
  });

  test("a configurable product's order queues the variant sold, not every variant", async ({ page }) => {
    syncCatalog();
    sql('DELETE FROM bluebarry_product_sync');
    await stubAdvisor(page, null, { visitor: false });
    await addToCart(page, 'bb-configurable', { color: 'BB Red' });
    await checkoutAsGuest(page, 'catalog-variant@example.com');

    expect(queued(productId('bb-configurable-red'))).toBe(1);
    expect(queued(productId('bb-configurable'))).toBe(0);
  });

  test('stock saved through multi-source inventory is queued', async ({ request }) => {
    sql('DELETE FROM bluebarry_product_sync');
    const quantity = Number(sql("SELECT quantity FROM inventory_source_item WHERE sku = 'bb-simple' AND source_code = 'default'"));
    await rest(request, 'post', '/rest/V1/inventory/source-items', {
      sourceItems: [{ sku: 'bb-simple', source_code: 'default', quantity: quantity - 1, status: 1 }],
    });
    try {
      expect(queued(productId('bb-simple'))).toBe(1);
    } finally {
      await rest(request, 'post', '/rest/V1/inventory/source-items', {
        sourceItems: [{ sku: 'bb-simple', source_code: 'default', quantity, status: 1 }],
      });
      magento('indexer:reindex', 'cataloginventory_stock', 'inventory', 'catalog_product_price'); // as the indexer cron would
    }
  });

  test('a mass attribute update is sent with its new values', async () => {
    const id = productId(SKU);
    sql('DELETE FROM bluebarry_product_sync');
    inMagento(`$om->get(\\Magento\\Catalog\\Model\\Product\\Action::class)->updateAttributes([${id}], ['name' => 'Bluebarry Catalog Product Renamed'], 0);`);
    expect(queued(id)).toBe(1);

    syncCatalog();
    expect((await sent(id))[0].name).toBe('Bluebarry Catalog Product Renamed');
  });

  test("deleting a bundle's part resends the bundle", async ({ request }) => {
    const bundle = productId('bb-bundle-dynamic');
    await rest(request, 'post', '/rest/all/V1/products', {
      product: { sku: 'bb-part-gone', name: 'BB Part Gone', attribute_set_id: 4, price: 5, status: 1, visibility: 1, type_id: 'simple',
        extension_attributes: { website_ids: [1], stock_item: { qty: 10, is_in_stock: true } } },
    });
    // One more selection in the bundle's first option, as the admin would add it.
    sql(`INSERT INTO catalog_product_bundle_selection (option_id, parent_product_id, product_id, position, is_default, selection_price_type, selection_price_value, selection_qty, selection_can_change_qty)
      SELECT option_id, parent_product_id, ${productId('bb-part-gone')}, 99, 0, 0, 0, 1, 1 FROM catalog_product_bundle_selection WHERE parent_product_id = ${bundle} LIMIT 1`);
    sql('DELETE FROM bluebarry_product_sync');

    await rest(request, 'delete', '/rest/V1/products/bb-part-gone');
    expect(queued(bundle)).toBe(1);
  });

  test('a deleted product is switched off', async ({ request }) => {
    const id = productId(SKU);
    await rest(request, 'delete', `/rest/V1/products/${SKU}`);

    syncCatalog();
    expect(await sent(id)).toEqual([{ reference: id, inactive: true }]);
  });

  test('while bluebarry is unreachable the products wait, and the settings say so', async ({ page }) => {
    await mockApi.respondWith({ status: 503 });
    const failed = syncCatalog('--all');
    expect(failed.code).toBe(1);
    expect(failed.output).toContain('bluebarry answered HTTP 503.');

    await adminLogin(page);
    await openBluebarrySettings(page);
    await expect(page.locator('#row_bluebarry_module_general_catalog')).toContainText('products waiting: bluebarry answered HTTP 503.');

    // Resending the catalog does not wait for the retry.
    await mockApi.reset();
    expect(syncCatalog('--all').code).toBe(0);
    await openBluebarrySettings(page);
    await expect(page.locator('#row_bluebarry_module_general_catalog')).toContainText('Up to date, last sent');
  });
});
