import { expect, test } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  addToCart,
  checkoutAsGuest,
  magento,
  mockApi,
  newAdvisorIds,
  payForOrder,
  runConversionConsumer,
  startAdvisor,
  stubAdvisor,
} from './helpers';

// The admin debug setting on a production-mode shop (#21): Magento suppresses debug.log there, so the
// module logs to its own var/log/bluebarry.log.

const BIN = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../bin');
const moduleLog = () =>
  execFileSync(path.join(BIN, 'shell'), ['-c', 'cat var/log/bluebarry.log 2>/dev/null || true'], { encoding: 'utf8' });

const setDebugLog = (enabled: boolean) => {
  magento('config:set', 'bluebarry_module/general/write_to_debug_file', enabled ? '1' : '0');
  magento('cache:flush');
};

async function quizCheckout(page: import('@playwright/test').Page) {
  await stubAdvisor(page, newAdvisorIds());
  await page.goto('/');
  await startAdvisor(page);
  await addToCart(page, 'bb-simple');
  const incrementId = await checkoutAsGuest(page, `shopper+${Date.now()}@example.com`);
  await payForOrder(page, incrementId);
  runConversionConsumer();
  return incrementId; // the conversion is keyed by the order number
}

test.describe('debug log', () => {
  test.beforeEach(async () => {
    try {
      runConversionConsumer();
    } catch {}
    await mockApi.reset();
  });

  // dev/bin/setup turns the setting on; leave it that way.
  test.afterAll(() => setDebugLog(true));

  test('with the setting on, request and response are logged in production mode', async ({ page }) => {
    expect(magento('deploy:mode:show')).toContain('production');
    setDebugLog(true);
    const before = moduleLog().length;

    const orderId = await quizCheckout(page);

    const added = moduleLog().slice(before);
    expect(added).toContain('--- Bluebarry request ---');
    expect(added).toContain(`"conversionId":"${orderId}"`);
    expect(added).toContain('HTTP Status: 201');
  });

  test('with the setting off, nothing is logged', async ({ page }) => {
    setDebugLog(false);
    const before = moduleLog().length;

    await quizCheckout(page);

    expect(moduleLog().slice(before)).toBe('');
  });
});
