import { expect, test } from '@playwright/test';
import { adminLogin, magento, mockApi, openBluebarrySettings, sql } from './helpers';

// Connecting the store (magento-internal#8): the API key is saved encrypted, the website sends a
// heartbeat with it, and the settings page says whether bluebarry accepted it.

const pings = async () => (await mockApi.requests()).filter((r) => r.path === '/data/magento/ping');

test.describe('connect with an API key', () => {
  test.describe.configure({ mode: 'serial' });

  test.beforeEach(async () => {
    await mockApi.reset();
  });

  test.afterAll(() => {
    sql("DELETE FROM core_config_data WHERE path = 'bluebarry_module/general/api_key'");
    magento('cache:flush');
  });

  test('saving a valid key connects the website and shows it', async ({ page }) => {
    await adminLogin(page);
    await openBluebarrySettings(page);
    await page.locator('#bluebarry_module_general_api_key').fill('test-api-key');
    await page.locator('#save').click();

    await expect(page.locator('.message-success', { hasText: 'Main Website is connected to bluebarry.' })).toBeVisible();
    await expect(page.locator('#row_bluebarry_module_general_connection')).toContainText('Connected, last heartbeat');

    const [ping] = await pings();
    expect(ping.responseStatus).toBe(200);
    expect(ping.headers.authorization).toBe('test-api-key');
    expect(ping.headers['bb-tenant-id'], 'a key-authenticated call must not name the tenant').toBeUndefined();
    expect(ping.body).toMatchObject({ siteUrl: 'http://localhost:8080', siteName: 'Main Website' });
    expect(ping.body.moduleVersion).toMatch(/^\d+\.\d+\.\d+/);
    expect(ping.body.magentoVersion).toMatch(/^2\.4\./);

    // Stored encrypted, never in plain text.
    const stored = magento('config:show', 'bluebarry_module/general/api_key').trim();
    expect(stored).not.toContain('test-api-key');
  });

  test('a refused key is reported on save', async ({ page }) => {
    await adminLogin(page);
    await openBluebarrySettings(page);
    await page.locator('#bluebarry_module_general_api_key').fill('not-the-key');
    await page.locator('#save').click();

    await expect(page.locator('.message-error', { hasText: 'bluebarry refused the API key' })).toBeVisible();
    await expect(page.locator('#row_bluebarry_module_general_connection')).toContainText('Not connected: bluebarry refused the API key.');
  });
});
