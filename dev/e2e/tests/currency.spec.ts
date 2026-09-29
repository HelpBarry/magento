import { expect, test } from '@playwright/test';
import {
  addToCart,
  checkoutAsGuest,
  orderGrandTotal,
  payForOrder,
  magento,
  mockApi,
  newAdvisorIds,
  runConversionConsumer,
  sql,
  startAdvisor,
  stubAdvisor,
} from './helpers';

// A store that sells in another currency than its EUR base currency (#6): the conversion must carry
// the order's currency, and its amounts must be in that same currency.

// Magento validates the display currency against the allowed list, so the order of these matters.
const useUsd = () => {
  magento('config:set', 'currency/options/allow', 'EUR,USD');
  magento('config:set', 'currency/options/default', 'USD');
  magento('cache:flush');
};
const useEurOnly = () => {
  magento('config:set', 'currency/options/default', 'EUR');
  magento('config:set', 'currency/options/allow', 'EUR');
  magento('cache:flush');
};

test.describe('non-EUR order currency', () => {
  test.beforeAll(() => {
    sql("INSERT INTO directory_currency_rate (currency_from, currency_to, rate) VALUES ('EUR', 'USD', 1.1) ON DUPLICATE KEY UPDATE rate = 1.1");
    useUsd();
  });

  test.afterAll(useEurOnly);

  test.beforeEach(async () => {
    try {
      runConversionConsumer();
    } catch {}
    await mockApi.reset();
  });

  test('conversion is sent in the order currency', async ({ page }) => {
    const ids = newAdvisorIds();
    await stubAdvisor(page, ids);
    await page.goto('/');
    await startAdvisor(page);
    await addToCart(page, 'bb-simple');
    const incrementId = await checkoutAsGuest(page, `shopper+${Date.now()}@example.com`);
    await payForOrder(page, incrementId);

    runConversionConsumer();
    const conversion = (await mockApi.requests()).find((r) => r.path.toLowerCase() === '/data/conversionevents');
    expect(conversion).toBeDefined();
    expect(conversion!.contractErrors).toEqual([]);
    const body = conversion!.body;
    // EUR 100.00 at 1.1 is USD 110.00, plus 21% VAT; the grand total is the order's, in USD.
    expect(body.currencyIso).toBe('USD');
    expect(Number(body.orderProductTotal)).toBeCloseTo(110, 2);
    expect(Number(body.orderTaxTotal)).toBeCloseTo(23.1, 2);
    expect(Number(body.orderGrandTotal)).toBeCloseTo(orderGrandTotal(incrementId), 2);
    expect(Number(body.items[0].priceExclTax)).toBeCloseTo(110, 2);
  });
});
