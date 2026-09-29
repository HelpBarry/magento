import { createHmac, randomUUID } from 'node:crypto';
import { expect, test, type APIRequestContext } from '@playwright/test';
import { magento, MOCK_API, mockApi, sql, stubAdvisor } from './helpers';

// Search from Studio (magento-internal#5): the website reads the choice from bluebarry, when bluebarry
// asks (a signed command) or on its schedule, prints window.barry.search, and takes the catalog search
// results page over when that is on. Its pages leave the page cache when the choice changes.

const KEY = 'test-api-key';

async function setStorefront(search: { profileId: string; resultsPage: boolean } | null) {
  await fetch(`${MOCK_API}/__storefront`, { method: 'PUT', body: JSON.stringify({ search }) });
}

/** bluebarry's command, signed like DataApi's MagentoStoreClient. */
async function command(request: APIRequestContext, body: object, { key = KEY, at = Math.floor(Date.now() / 1000) } = {}) {
  const raw = JSON.stringify(body);
  const signature = createHmac('sha256', key).update(`${at}.${raw}`).digest('hex');
  return request.post('/bluebarry/command/', {
    headers: { 'Content-Type': 'application/json', 'X-Bluebarry-Timestamp': String(at), 'X-Bluebarry-Signature': signature },
    data: raw,
  });
}

test.describe('search from Studio', () => {
  test.describe.configure({ mode: 'serial' });
  const profileId = randomUUID();

  test.beforeAll(() => {
    magento('config:set', 'bluebarry_module/general/api_key', KEY);
  });

  test.beforeEach(async () => {
    await mockApi.reset();
  });

  test.afterAll(async () => {
    await mockApi.reset();
    sql("DELETE FROM core_config_data WHERE path = 'bluebarry_module/general/api_key'");
    sql("DELETE FROM flag WHERE flag_code IN ('bluebarry_storefront', 'bluebarry_catalog')");
    magento('cache:flush');
  });

  test('only a command signed with the website\'s key is taken', async ({ request }) => {
    expect((await command(request, { command: 'ping' }, { key: 'not-the-key' })).status()).toBe(401);
    expect((await command(request, { command: 'ping' }, { at: Math.floor(Date.now() / 1000) - 3600 })).status()).toBe(401);
    const unsigned = await request.post('/bluebarry/command/', { data: { command: 'ping' } });
    expect(unsigned.status()).toBe(401);

    const ping = await command(request, { command: 'ping' });
    expect(ping.status()).toBe(200);
    expect(await ping.json()).toMatchObject({ ok: true, version: expect.stringMatching(/^\d+\.\d+\.\d+/) });
  });

  test('search chosen in Studio reaches the pages, including ones already cached', async ({ page, request }) => {
    await stubAdvisor(page, null, { visitor: false });
    await page.goto('/bb-simple.html'); // cached without search
    expect(await page.evaluate(() => (window as any).barry.search)).toBeUndefined();

    await setStorefront({ profileId, resultsPage: false });
    const refresh = await command(request, { command: 'settings.refresh' });
    expect(await refresh.json()).toMatchObject({ ok: true });
    const reads = (await mockApi.requests()).filter((r) => r.path === '/data/magento/storefront');
    expect(reads).toHaveLength(1);
    expect(reads[0].headers.authorization).toBe(KEY);

    await page.goto('/bb-simple.html');
    expect(await page.evaluate(() => (window as any).barry.search)).toEqual({
      searchProfileId: profileId,
      searchSelector: 'input#search, input[name="q"]',
    });
  });

  test('with the results page on, the catalog search results page is bluebarry\'s', async ({ page, request }) => {
    await setStorefront({ profileId, resultsPage: true });
    expect((await command(request, { command: 'settings.refresh' })).status()).toBe(200);
    await stubAdvisor(page, null, { visitor: false });

    await page.goto('/bb-simple.html');
    expect(await page.evaluate(() => (window as any).barry.search.serpPage)).toEqual({
      enabled: true, path: 'http://localhost:8080/catalogsearch/result/', here: false, hostSelector: '#maincontent .columns',
    });

    await page.goto('/catalogsearch/result/?q=bluebarry');
    expect(await page.evaluate(() => (window as any).barry.search.serpPage.here)).toBe(true);
    // Magento's own results are hidden until the SDK mounts; the stub SDK never does, so they come back.
    await expect(page.locator('#bb-serp-guard')).toHaveCount(0, { timeout: 5000 });
    await expect(page.locator('#maincontent .columns')).toBeVisible();
  });

  test('turning search off in Studio takes it off the pages again', async ({ page, request }) => {
    await setStorefront(null);
    expect((await command(request, { command: 'settings.refresh' })).status()).toBe(200);
    await stubAdvisor(page, null, { visitor: false });

    await page.goto('/bb-simple.html');
    expect(await page.evaluate(() => (window as any).barry.search)).toBeUndefined();
  });
});
