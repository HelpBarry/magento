import { execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { expect, type Page } from '@playwright/test';

const BIN = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../bin');
export const MOCK_API = process.env.MOCK_API_URL ?? 'http://localhost:8099';
export const WAF_URL = process.env.WAF_URL ?? 'http://localhost:8081';

// --- Mock data.bluebarry.ai ---------------------------------------------------------------------------

export type RecordedRequest = {
  method: string;
  path: string;
  headers: Record<string, string>;
  rawBody: string;
  body: any;
  contractErrors: string[];
  responseStatus: number;
};

export const mockApi = {
  async reset() {
    await fetch(`${MOCK_API}/__requests`, { method: 'DELETE' });
  },
  async requests(): Promise<RecordedRequest[]> {
    return (await fetch(`${MOCK_API}/__requests`)).json();
  },
  async respondWith(behavior: { status?: number; delayMs?: number; body?: unknown }) {
    await fetch(`${MOCK_API}/__behavior`, { method: 'PUT', body: JSON.stringify(behavior) });
  },
};

// --- Running things inside the stack --------------------------------------------------------------------

export function magento(...args: string[]): string {
  return execFileSync(path.join(BIN, 'magento'), args, { encoding: 'utf8', timeout: 120_000 });
}

export function sql(query: string): string {
  return execFileSync(
    path.join(BIN, 'compose'),
    ['exec', '-T', 'db', 'mariadb', '-umagento', '-pmagento', 'magento', '-N', '-B', '-e', query],
    { encoding: 'utf8' },
  ).trim();
}

/** Drains the conversion queue the way a cron-spawned consumer would, then returns. */
export function runConversionConsumer(): string {
  return magento('queue:consumers:start', 'BluebarryConversionProcess', '--max-messages=50');
}

// --- The Bluebarry advisor, stubbed at its real origins ---------------------------------------------------

export type AdvisorIds = { advisorId: string; sessionId: string; userId: string };

export const newAdvisorIds = (): AdvisorIds => ({
  advisorId: randomUUID(),
  sessionId: randomUUID(),
  userId: randomUUID(),
});

/**
 * Serves advisor.js from cdn.bluebarry.ai and the advisor iframe from advisor.bluebarry.ai via request
 * interception. The iframe therefore has the real origin, so the storefront's postMessage origin check
 * runs unmodified. Nothing reaches the real Bluebarry services.
 */
export async function stubAdvisor(page: Page, ids: AdvisorIds | null) {
  await page.route('https://cdn.bluebarry.ai/**', (route) =>
    route.fulfill({
      contentType: 'application/javascript',
      body: `
        window.__bbAdvisorLoaded = true;
        (function mount() {
          if (!document.body) return document.addEventListener('DOMContentLoaded', mount);
          var f = document.createElement('iframe');
          f.id = 'bb-test-advisor';
          f.src = 'https://advisor.bluebarry.ai/test-stub';
          document.body.appendChild(f);
        })();`,
    }),
  );
  await page.route('https://advisor.bluebarry.ai/**', (route) =>
    route.fulfill({
      contentType: 'text/html',
      body: ids
        ? `<script>
             window.addEventListener('message', function (e) {
               if (e.data !== 'bb-test-start') return;
               parent.postMessage({ event: 'barry-analytics', value: { name: 'bluebarry_start_advisor', data: {
                 bluebarry_advisor_id: ${JSON.stringify(ids.advisorId)},
                 bluebarry_session_id: ${JSON.stringify(ids.sessionId)},
                 bluebarry_user_id: ${JSON.stringify(ids.userId)}
               } } }, '*');
             });
           </script>`
        : '<p>advisor</p>',
    }),
  );
  await page.route(/https:\/\/(?!cdn\.|advisor\.)[^/]*bluebarry\.ai\//, (route) => route.abort());
}

/** Simulates the shopper starting the quiz and waits for the storefront to store the session. */
export async function startAdvisor(page: Page) {
  const stored = page.waitForResponse(
    (r) => r.url().includes('/bluebarry/session/update') && r.request().method() === 'POST',
  );
  const frame = page.frameLocator('#bb-test-advisor');
  await expect(frame.locator('script')).toHaveCount(1);
  await page.evaluate(() =>
    (document.getElementById('bb-test-advisor') as HTMLIFrameElement).contentWindow!.postMessage('bb-test-start', '*'),
  );
  const response = await stored;
  expect(response.status()).toBe(200);
  expect(await response.json()).toMatchObject({ success: true });
}

// --- CSP ----------------------------------------------------------------------------------------------

/** Collects CSP violations (enforced and report-only) raised on the page. */
export async function collectCspViolations(page: Page) {
  await page.addInitScript(() => {
    (window as any).__csp = [];
    document.addEventListener('securitypolicyviolation', (e) =>
      (window as any).__csp.push({
        directive: e.effectiveDirective,
        blockedURI: e.blockedURI,
        disposition: e.disposition,
        sample: e.sample,
        sourceFile: e.sourceFile,
      }),
    );
  });
  return async () => page.evaluate(() => (window as any).__csp as any[]);
}

// --- Storefront checkout (Luma) -----------------------------------------------------------------------

export async function addToCart(page: Page, urlKey: string, options: { color?: string; bundle?: boolean } = {}) {
  await page.goto(`/${urlKey}.html`);
  if (options.bundle) {
    // Luma shows bundle options after "Customize and Add to Cart"; the fixtures preselect every part.
    await page.locator('#bundle-slide').click();
    await expect(page.locator('#product-addtocart-button')).toBeVisible();
  }
  if (options.color) {
    await page.locator('.swatch-attribute.color .swatch-option, select.super-attribute-select').first().waitFor();
    const swatch = page.locator(`.swatch-attribute.color .swatch-option[data-option-label="${options.color}"]`);
    if (await swatch.count()) await swatch.click();
    else await page.locator('select.super-attribute-select').selectOption({ label: options.color });
  }
  const added = page.waitForResponse((r) => r.url().includes('/checkout/cart/add') && r.request().method() === 'POST');
  await page.locator('#product-addtocart-button').click();
  await added;
  await expect(page.locator('.page.messages .message-success')).toBeVisible();
}

export async function checkoutAsGuest(page: Page, email: string): Promise<string> {
  await page.goto('/checkout/');
  const shipping = page.locator('#shipping');
  await expect(page.locator('#customer-email')).toBeVisible({ timeout: 60_000 });
  await page.locator('#customer-email').fill(email);
  await shipping.locator('input[name="firstname"]').fill('Test');
  await shipping.locator('input[name="lastname"]').fill('Shopper');
  await shipping.locator('select[name="country_id"]').selectOption('NL');
  await shipping.locator('input[name="street[0]"]').fill('Teststraat 1');
  await shipping.locator('input[name="city"]').fill('Amsterdam');
  await shipping.locator('input[name="postcode"]').fill('1011 AB');
  await shipping.locator('input[name="telephone"]').fill('0612345678');

  const flatRate = page.locator('input[value="flatrate_flatrate"]');
  await expect(flatRate).toBeVisible();
  await flatRate.check();
  await page.locator('button[data-role="opc-continue"]').click();

  // With a single payment method Luma hides its radio button and preselects it.
  const checkmo = page.locator('.payment-method').filter({ has: page.locator('input#checkmo') });
  await expect(checkmo).toBeVisible({ timeout: 60_000 });
  const radio = checkmo.locator('input#checkmo');
  if (await radio.isVisible()) await radio.check();
  await checkmo.locator('button.action.primary.checkout').click();

  await page.waitForURL('**/checkout/onepage/success/**', { timeout: 60_000 });
  const incrementId = (await page.locator('.checkout-success .order-number strong, .checkout-success p span').first().innerText()).trim();
  expect(incrementId).toMatch(/^\d+$/);
  return incrementId;
}

export function orderEntityId(incrementId: string): string {
  return sql(`SELECT entity_id FROM sales_order WHERE increment_id = '${incrementId.replace(/\D/g, '')}'`);
}
