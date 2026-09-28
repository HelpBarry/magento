import { expect, test } from '@playwright/test';
import { randomUUID } from 'node:crypto';
import { WAF_URL } from './helpers';

// The storefront behind OWASP CRS (dev/docker-compose.yml "waf"), the rule set behind Cloud Armor's
// preconfigured WAF rules and most hosting WAFs. Covers the 1.0.2 incident: a body field named
// session_id tripped rule 943120 and every session update was answered with 403.

const post = (form: Record<string, string>, headers: Record<string, string> = {}) =>
  fetch(`${WAF_URL}/bluebarry/session/update`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', ...headers },
    body: new URLSearchParams(form),
    redirect: 'manual',
  });

test.beforeAll(async () => {
  const reachable = await fetch(WAF_URL, { redirect: 'manual' }).then(() => true, () => false);
  test.skip(!reachable, `WAF not running at ${WAF_URL}`);
});

test('control: the WAF blocks the pre-1.0.2 session_id field', async () => {
  const response = await post({ form_key: 'abc', session_id: randomUUID(), advisor_id: randomUUID(), user_id: randomUUID() });
  expect(response.status).toBe(403);
});

for (const [name, headers] of [
  ['browser-like request', { Referer: 'http://localhost:8081/', Origin: 'http://localhost:8081' }],
  ['request without Referer', {}],
] as const) {
  test(`session update passes the WAF (${name})`, async () => {
    const response = await post(
      { form_key: 'abc', bb_session_id: randomUUID(), advisor_id: randomUUID(), user_id: randomUUID() },
      headers,
    );
    expect(response.status).not.toBe(403);
  });
}
