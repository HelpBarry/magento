import { expect, test } from '@playwright/test';
import { adminLogin } from './helpers';

test('Stores > Configuration opens on General > General, with the Bluebarry tab further down', async ({ page }) => {
  await adminLogin(page);
  await page.goto('/admin/admin/system_config/');

  await expect(page.locator('#general_country-head')).toBeVisible({ timeout: 60_000 });
  const tabs = page.locator('#system_config_tabs .config-nav-block .title');
  await expect(tabs.first()).toHaveText('General');
  await expect(tabs.filter({ hasText: 'Bluebarry' })).toHaveCount(1);
});
