import { expect, test } from '@playwright/test';
import { randomUUID } from 'node:crypto';
import { newAdvisorIds, startAdvisor, stubAdvisor, WAF_URL } from './helpers';

// The storefront behind OWASP CRS (dev/docker-compose.yml "waf"), the rule set behind Cloud Armor's
// preconfigured WAF rules and most hosting WAFs. Covers the 1.0.2 incident: a body field named
// session_id tripped rule 943120 and every session update was answered with 403.
// The WAF is part of the stack, so it is required; SKIP_WAF=1 opts out for local runs without it.

const post = (form: Record<string, string>, headers: Record<string, string> = {}) =>
  fetch(`${WAF_URL}/bluebarry/session/update`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', ...headers },
    body: new URLSearchParams(form),
    redirect: 'manual',
  });

test.skip(process.env.SKIP_WAF === '1', 'SKIP_WAF=1');

test.beforeAll(async () => {
  const response = await fetch(WAF_URL, { redirect: 'manual' }).catch((e) => e as Error);
  expect(response instanceof Response ? response.status : String(response), `WAF must be up at ${WAF_URL}`).toBe(200);
});

test('storefront session update succeeds through the WAF (real browser request)', async ({ page }) => {
  // The page stays on the store's base URL (a page served from the WAF port would load its scripts
  // cross-origin and never get a form key); only the module's own request is sent through the WAF,
  // with the cookies, form key, headers and body exactly as the browser built them.
  let viaWaf = false;
  await page.route('**/bluebarry/session/update', (route) => {
    viaWaf = true;
    return route.continue({ url: `${WAF_URL}/bluebarry/session/update` });
  });
  const ids = newAdvisorIds();
  await stubAdvisor(page, ids);
  await page.goto('/');
  await startAdvisor(page);
  expect(viaWaf).toBe(true);

  await page.unrouteAll();
  const stored = await (await page.request.get('/bluebarry/session/update')).json();
  expect(stored.session.bluebarry.session_id).toBe(ids.sessionId);
});

test('control: the WAF blocks the pre-1.0.2 session_id field', async () => {
  const response = await post({ form_key: 'abc', session_id: randomUUID(), advisor_id: randomUUID(), user_id: randomUUID() });
  expect(response.status).toBe(403);
});

test('session update without a Referer header still reaches Magento', async () => {
  // Some privacy tools strip Referer; CRS scores that differently. Magento answers the bogus form key
  // with a redirect, which proves the request got past the WAF (a WAF error would be 403 or 5xx).
  const response = await post({ form_key: 'abc', bb_session_id: randomUUID(), advisor_id: randomUUID(), user_id: randomUUID() });
  expect(response.status).toBe(302);
});
