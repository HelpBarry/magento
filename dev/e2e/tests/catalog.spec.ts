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

/** Runs PHP against the same installed Magento/module code as the storefront and consumers. */
function inMagento(code: string, area: 'adminhtml' | 'frontend' = 'adminhtml'): string {
  const script = `<?php require 'app/bootstrap.php';
    $om = \\Magento\\Framework\\App\\Bootstrap::create(BP, $_SERVER)->getObjectManager();
    $om->get(\\Magento\\Framework\\App\\State::class)->setAreaCode('${area}');
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

    // While the price index ("Update by Schedule") has not run, it waits. Where the index already
    // caught up (older Magento reindexes this save itself) it goes now; never with the old price.
    syncCatalog();
    if ((await sent(id)).length === 0) {
      expect(queued(id)).toBe(1);
      magento('indexer:reindex', 'catalog_product_price');
      syncCatalog();
    }
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
    const bundle = productId('bb-bundle-dynamic');
    // A dynamically priced bundle has no tax class of its own: Magento taxes its parts.
    const taxClass = `(SELECT attribute_id FROM eav_attribute WHERE attribute_code = 'tax_class_id' AND entity_type_id = 4)`;
    sql(`UPDATE catalog_product_entity_int SET value = 0 WHERE entity_id = ${bundle} AND attribute_id = ${taxClass} AND store_id = 0`);
    magento('config:set', 'tax/display/type', '2');
    try {
      syncCatalog('--all');
      expect(property((await sent(id))[0], 'price')).toBe(121);
      const minPrice = Number(sql(`SELECT min_price FROM catalog_product_index_price WHERE entity_id = ${bundle} AND customer_group_id = 0 AND website_id = 1`));
      expect(property((await sent(bundle))[0], 'price')).toBeCloseTo(Math.round(minPrice * 121) / 100, 2);

      // Parts taxed differently: each part by its own rate, as Magento shows the bundle.
      const partB = productId('bb-part-b');
      const partPrice = (part: string) =>
        Number(sql(`SELECT final_price FROM catalog_product_index_price WHERE entity_id = ${part} AND customer_group_id = 0 AND website_id = 1`));
      sql(`UPDATE catalog_product_entity_int SET value = 0 WHERE entity_id = ${partB} AND attribute_id = ${taxClass} AND store_id = 0`);
      try {
        await mockApi.reset();
        syncCatalog('--all');
        const expected = Math.round(partPrice(productId('bb-part-a')) * 121 + partPrice(partB) * 100) / 100;
        expect(property((await sent(bundle))[0], 'price')).toBeCloseTo(expected, 2);
      } finally {
        sql(`UPDATE catalog_product_entity_int SET value = 2 WHERE entity_id = ${partB} AND attribute_id = ${taxClass} AND store_id = 0`);
      }
    } finally {
      sql(`UPDATE catalog_product_entity_int SET value = 2 WHERE entity_id = ${bundle} AND attribute_id = ${taxClass} AND store_id = 0`);
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
      // While the stock index ("Update by Schedule") has not run, the bundle would still read in stock, so
      // it waits. Where the index already caught up (older Magento) it goes now; never as in stock.
      syncCatalog();
      if ((await sent(productId('bb-bundle-fixed'))).length === 0) {
        expect(queued(productId('bb-part-a'))).toBe(1);
        reindex(); // as the indexer cron would
        syncCatalog();
      }
      const [bundle] = await sent(productId('bb-bundle-fixed'));
      expect(property(bundle, 'stock_status')).toBe('outofstock');
    } finally {
      await stock(true);
      reindex();
    }
  });

  test('the variants of a configurable product marked out of stock are out of stock', async ({ request }) => {
    const stock = (inStock: boolean) => rest(request, 'put', '/rest/all/V1/products/bb-configurable', {
      product: { sku: 'bb-configurable', extension_attributes: { stock_item: { is_in_stock: inStock } } },
    });
    await stock(false);
    try {
      magento('indexer:reindex', 'cataloginventory_stock', 'inventory', 'catalog_product_price');
      syncCatalog();
      const [red] = await sent(productId('bb-configurable-red'));
      expect(property(red, 'stock_status')).toBe('outofstock');
    } finally {
      await stock(true);
      magento('indexer:reindex', 'cataloginventory_stock', 'inventory', 'catalog_product_price');
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

  test('a variant taken off its configurable product is queued as a product of its own', async ({ request }) => {
    const blue = productId('bb-configurable-blue');
    sql('DELETE FROM bluebarry_product_sync');
    await rest(request, 'delete', '/rest/V1/configurable-products/bb-configurable/children/bb-configurable-blue');
    try {
      expect(queued(productId('bb-configurable'))).toBe(1);
      expect(queued(blue)).toBe(1);
    } finally {
      await rest(request, 'post', '/rest/V1/configurable-products/bb-configurable/child', { childSku: 'bb-configurable-blue' });
    }
  });

  test('stock saved through multi-source inventory is queued', async ({ request }) => {
    sql('DELETE FROM bluebarry_product_sync');
    const quantity = Number(sql("SELECT quantity FROM inventory_source_item WHERE sku = 'bb-simple' AND source_code = 'default'"));
    await rest(request, 'post', '/rest/V1/inventory/source-items', {
      sourceItems: [{ sku: 'bb-simple', source_code: 'default', quantity: quantity - 1, status: 1 }],
    });
    try {
      expect(queued(productId('bb-simple'))).toBe(1);

      // Taken off its source: queued too, and it waits for the inventory index like a save does. Where the
      // index already caught up (older Magento) it goes now; never as in stock.
      magento('indexer:reindex', 'cataloginventory_stock', 'inventory', 'catalog_product_price');
      sql('DELETE FROM bluebarry_product_sync');
      await rest(request, 'post', '/rest/V1/inventory/source-items-delete', { sourceItems: [{ sku: 'bb-simple', source_code: 'default' }] });
      expect(queued(productId('bb-simple'))).toBe(1);
      syncCatalog();
      const [early] = await sent(productId('bb-simple'));
      if (early) expect(property(early, 'stock_status')).toBe('outofstock');
      else expect(queued(productId('bb-simple'))).toBe(1);
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

// Each PHP process has fresh pricing caches; all fixture changes roll back on the same connection.
test.describe('bundle catalog prices', () => {
  for (const fixture of [
    { name: 'fixed selection quantity excludes an insufficient-stock alternative', prices: [10, 30], taxes: [0, 2], stock: [1, 1000], quantities: [2, 1], oneOption: true, tier: null, indexedMinimum: 20, expected: 36.30 },
    { name: 'a child tier price applies at the fixed selection quantity', prices: [20, 10], taxes: [2, 0], stock: [1000, 1000], quantities: [2, 1], oneOption: false, tier: 5, indexedMinimum: 50, expected: 22.10 },
    { name: 'unit-based tax rounding happens before multiplying selection quantity', prices: [0.03, 0.01], taxes: [2, 0], stock: [1000, 1000], quantities: [100, 1], oneOption: false, tier: null, indexedMinimum: 3.01, expected: 4.01 },
    { name: 'fractional parent discounts match Magento rounding', prices: [0.03, 0.01], taxes: [2, 0], stock: [1000, 1000], quantities: [100, 1], oneOption: false, tier: null, special: 16.665, indexedMinimum: 3.01, expected: null },
    { name: 'a parent tier of 100 percent keeps the special-price fallback', prices: [20, 10], taxes: [2, 0], stock: [1000, 1000], quantities: [2, 1], oneOption: false, tier: 5, parentTier: 100, special: 80, indexedMinimum: 50, expected: 17.68 },
  ]) {
    test(fixture.name, () => {
      const result = JSON.parse(inMagento(String.raw`
        $om->configure($om->get(\Magento\Framework\ObjectManager\ConfigLoaderInterface::class)->load('frontend'));
        $stores = $om->get(\Magento\Store\Model\StoreManagerInterface::class);
        $stores->setCurrentStore(1);
        $store = $stores->getStore();
        $context = $om->get(\Magento\Framework\App\Http\Context::class);
        $context->setValue(\Magento\Customer\Model\Context::CONTEXT_GROUP, 0, 0);
        $context->setValue(\Magento\Customer\Model\Context::CONTEXT_AUTH, false, false);
        $context->setValue(\Magento\Framework\App\Http\Context::CONTEXT_CURRENCY, 'EUR', 'EUR');
        $om->get(\Magento\Customer\Model\Session::class)->setCustomerGroupId(0);

        // Override tax settings only in this process, without changing config or shared caches.
        $scope = new class($om->get(\Magento\Framework\App\Config\ScopeConfigInterface::class))
            implements \Magento\Framework\App\Config\ScopeConfigInterface {
            private $inner;
            public function __construct($inner) { $this->inner = $inner; }
            public function getValue($path = null, $scopeType = 'default', $scopeCode = null) {
                if ($path === 'tax/display/type') return 2;
                if ($path === 'tax/calculation/algorithm') return \Magento\Tax\Api\TaxCalculationInterface::CALC_UNIT_BASE;
                return $this->inner->getValue($path, $scopeType, $scopeCode);
            }
            public function isSetFlag($path, $scopeType = 'default', $scopeCode = null) {
                return (bool) $this->getValue($path, $scopeType, $scopeCode);
            }
        };
        (new \ReflectionProperty(\Magento\Tax\Model\Config::class, '_scopeConfig'))
            ->setValue($om->get(\Magento\Tax\Model\Config::class), $scope);

        $fixture = json_decode('${JSON.stringify(fixture)}', true);
        $connection = $om->get(\Magento\Framework\App\ResourceConnection::class)->getConnection();
        $ids = $connection->fetchPairs("SELECT sku, entity_id FROM catalog_product_entity
            WHERE sku IN ('bb-bundle-dynamic', 'bb-part-a', 'bb-part-b')");
        $bundle = (int) $ids['bb-bundle-dynamic'];
        $parts = [(int) $ids['bb-part-a'], (int) $ids['bb-part-b']];
        $attributes = $connection->fetchPairs("SELECT attribute_code, attribute_id FROM eav_attribute
            WHERE entity_type_id = 4 AND attribute_code IN ('price', 'tax_class_id')");
        $options = $connection->fetchPairs($connection->select()
            ->from('catalog_product_bundle_selection', ['product_id', 'option_id'])
            ->where('parent_product_id = ?', $bundle)->where('product_id IN (?)', $parts));
        $connection->beginTransaction();
        try {
            $connection->delete('inventory_reservation', ['sku IN (?)' => ['bb-part-a', 'bb-part-b']]);
            $connection->delete('catalog_product_entity_tier_price', ['entity_id IN (?)' => $parts]);
            $connection->update('catalog_product_entity_int', ['value' => 0],
                ['entity_id = ?' => $bundle, 'attribute_id = ?' => $attributes['tax_class_id'], 'store_id = ?' => 0]);
            foreach ($parts as $i => $id) {
                $price = $fixture['prices'][$i];
                $qty = $fixture['stock'][$i];
                $connection->update('catalog_product_entity_decimal', ['value' => $price],
                    ['entity_id = ?' => $id, 'attribute_id = ?' => $attributes['price'], 'store_id = ?' => 0]);
                $connection->update('catalog_product_entity_int', ['value' => $fixture['taxes'][$i]],
                    ['entity_id = ?' => $id, 'attribute_id = ?' => $attributes['tax_class_id'], 'store_id = ?' => 0]);
                $connection->update('catalog_product_index_price',
                    ['price' => $price, 'final_price' => $price, 'min_price' => $price, 'max_price' => $price, 'tier_price' => null,
                     'tax_class_id' => $fixture['taxes'][$i]], ['entity_id = ?' => $id]);
                $connection->update('cataloginventory_stock_item', ['qty' => $qty, 'is_in_stock' => 1], ['product_id = ?' => $id]);
                $connection->update('cataloginventory_stock_status', ['qty' => $qty, 'stock_status' => 1], ['product_id = ?' => $id]);
                $connection->update('inventory_source_item', ['quantity' => $qty, 'status' => 1], ['sku = ?' => $i === 0 ? 'bb-part-a' : 'bb-part-b']);
                $connection->update('catalog_product_bundle_selection',
                    ['option_id' => $fixture['oneOption'] ? $options[$parts[0]] : $options[$id],
                     'selection_qty' => $fixture['quantities'][$i], 'selection_can_change_qty' => 0],
                    ['parent_product_id = ?' => $bundle, 'product_id = ?' => $id]);
                $connection->update('catalog_product_bundle_option',
                    ['required' => $fixture['oneOption'] && $i === 1 ? 0 : 1], ['option_id = ?' => $options[$id]]);
            }
            if ($fixture['tier'] !== null) {
                $connection->insert('catalog_product_entity_tier_price',
                    ['entity_id' => $parts[0], 'all_groups' => 0, 'customer_group_id' => 0,
                     'qty' => 2, 'value' => $fixture['tier'], 'website_id' => 0]);
            }
            if (isset($fixture['special'])) {
                $special = (int) $connection->fetchOne("SELECT attribute_id FROM eav_attribute WHERE entity_type_id = 4 AND attribute_code = 'special_price'");
                $connection->insertOnDuplicate('catalog_product_entity_decimal',
                    ['entity_id' => $bundle, 'attribute_id' => $special, 'store_id' => 0, 'value' => $fixture['special']], ['value']);
            }
            if (isset($fixture['parentTier'])) {
                $connection->insert('catalog_product_entity_tier_price',
                    ['entity_id' => $bundle, 'all_groups' => 0, 'customer_group_id' => 0, 'qty' => 1,
                     'value' => 0, 'percentage_value' => $fixture['parentTier'], 'website_id' => 0]);
            }
            // The bundle index is quantity-one; frontend pricing must account for fixed child quantities.
            $connection->update('catalog_product_index_price',
                ['min_price' => $fixture['indexedMinimum'], 'max_price' => $fixture['indexedMinimum']], ['entity_id = ?' => $bundle]);
            $export = $om->create(\Bluebarry\Bluebarry\Model\Catalog\ProductBuilder::class)->build([$bundle], $store);
            $product = $om->get(\Magento\Catalog\Api\ProductRepositoryInterface::class)->getById($bundle, false, 1, true);
            $minimum = $product->getPriceInfo()->getPrice('final_price')->getMinimalPrice()->getValue();
            echo json_encode(['minimum' => $minimum, 'failed' => $export['failed'],
                'properties' => array_column($export['products'][0]['properties'], 'value', 'propertyName')]);
        } finally {
            $connection->rollBack();
        }
      `, 'frontend'));
      expect(result.failed).toEqual([]);
      if (fixture.expected !== null) expect(result.minimum, 'Magento storefront minimum').toBeCloseTo(fixture.expected, 2);
      expect(result.properties.price, 'exported catalog minimum').toBeCloseTo(result.minimum, 2);
      expect(result.properties.currency).toBe('EUR');
    });
  }
});
