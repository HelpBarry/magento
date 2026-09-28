// Stand-in for https://data.bluebarry.ai. The php container resolves data.bluebarry.ai to this
// service (docker network alias) and trusts its test CA, so the module's hardcoded URL is exercised as shipped.
//
// HTTPS :443  - records whatever the module sends and validates it against the Bluebarry data API's
//               request contract, answering 400 like the real API does for a payload it cannot accept.
// HTTP  :3000 - control API for tests:
//   GET    /__requests          recorded requests (oldest first)
//   DELETE /__requests          clear recorded requests and reset behaviour
//   PUT    /__behavior          {"status": 500, "delayMs": 0, "body": {...}} applied to every following request
import { createServer as createHttpsServer } from 'node:https';
import { createServer as createHttpServer } from 'node:http';
import { readFileSync } from 'node:fs';

const DEFAULT_BEHAVIOR = { status: 200, delayMs: 0, body: null };

let requests = [];
let behavior = { ...DEFAULT_BEHAVIOR };

// --- Contract of the Bluebarry data API -------------------------------------------------------------
// Property names are case-insensitive, ids are GUIDs ("xxxxxxxx-xxxx-..."), decimals may be numbers or
// numeric strings, string fields must be JSON strings, and unknown properties on a conversion are rejected.
const GUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

const types = {
  guid: (v) => typeof v === 'string' && GUID.test(v),
  nullableGuid: (v) => v === null || types.guid(v),
  nullableDecimal: (v) =>
    v === null || (typeof v === 'number' && Number.isFinite(v)) ||
    (typeof v === 'string' && (v.trim() === '' || Number.isFinite(Number(v)))),
  nullableString: (max) => (v) => v === null || (typeof v === 'string' && v.length <= max),
  currency: (v) => v === null || (typeof v === 'string' && v.length === 3),
  requiredEmail: (v) => typeof v === 'string' && v.length > 0 && v.length <= 256 && /^[^@\s]+@[^@\s]+$/.test(v),
  any: () => true,
};

const conversionItem = {
  disallowUnknown: true,
  fields: {
    itemId: types.nullableString(64),
    quantity: types.nullableDecimal,
    priceExclTax: types.nullableDecimal,
    priceInclTax: types.nullableDecimal,
    taxPercentage: types.nullableDecimal,
    value: types.nullableDecimal,
  },
};

const conversionEvent = {
  disallowUnknown: true,
  fields: {
    commerceSource: types.any,
    commerceStoreKey: types.nullableString(128),
    advisorId: types.guid,
    sessionId: types.nullableGuid,
    userId: types.nullableGuid,
    experimentContexts: types.any,
    occurredAtUtc: types.any,
    currencyIso: types.currency,
    conversionId: types.nullableString(64),
    orderProductTotal: types.nullableDecimal,
    orderTaxTotal: types.nullableDecimal,
    orderGrandTotal: types.nullableDecimal,
    value: types.nullableDecimal,
    items: (v, path, errors) => {
      if (v === null) return true;
      if (!Array.isArray(v)) return false;
      v.forEach((item, i) => validateObject(item, conversionItem, `${path}[${i}]`, errors));
      return true;
    },
  },
};

const identify = {
  disallowUnknown: false,
  required: ['email', 'userId'],
  fields: { email: types.requiredEmail, sessionId: types.nullableGuid, userId: types.guid },
};

function validateObject(obj, schema, path, errors) {
  if (obj === null || typeof obj !== 'object' || Array.isArray(obj)) {
    errors.push(`${path}: expected an object`);
    return;
  }
  const byLower = Object.fromEntries(Object.keys(schema.fields).map((k) => [k.toLowerCase(), k]));
  for (const [key, value] of Object.entries(obj)) {
    const field = byLower[key.toLowerCase()];
    if (!field) {
      if (schema.disallowUnknown) errors.push(`${path}.${key}: unknown property (rejected by the API)`);
      continue;
    }
    if (!schema.fields[field](value, `${path}.${key}`, errors)) {
      errors.push(`${path}.${key}: invalid value ${JSON.stringify(value)}`);
    }
  }
  for (const field of schema.required ?? []) {
    if (!Object.keys(obj).some((k) => k.toLowerCase() === field.toLowerCase())) errors.push(`${path}.${field}: required`);
  }
}

const magentoPing = {
  disallowUnknown: false,
  required: ['siteUrl'],
  fields: {
    tenantId: types.nullableString(64),
    siteUrl: (v) => typeof v === 'string' && /^https?:\/\//.test(v),
    siteName: types.nullableString(256),
    moduleVersion: types.nullableString(32),
    magentoVersion: types.nullableString(32),
    magentoEdition: types.nullableString(32),
    commandUrl: types.nullableString(512),
  },
};

const catalogProperty = {
  disallowUnknown: true,
  required: ['propertyName', 'type'],
  fields: {
    propertyName: (v) => typeof v === 'string' && v.length > 0 && v.length <= 254,
    value: types.any,
    type: (v) => ['text', 'numeric', 'boolean', 'collection', 'description'].includes(v),
  },
};

const catalogProduct = {
  disallowUnknown: true,
  required: ['reference'],
  fields: {
    reference: (v) => typeof v === 'string' && v.length > 0 && v.length <= 64,
    name: types.nullableString(512),
    groupName: types.nullableString(512),
    groupId: types.nullableString(128),
    url: types.nullableString(1024),
    imageUrl: types.nullableString(1024),
    secondaryImages: (v) => v === null || (Array.isArray(v) && v.every((s) => typeof s === 'string')),
    inactive: (v) => typeof v === 'boolean',
    properties: (v, path, errors) => {
      if (v === null) return true;
      if (!Array.isArray(v)) return false;
      v.forEach((p, i) => validateObject(p, catalogProperty, `${path}[${i}]`, errors));
      return true;
    },
  },
};

// DataApi's MagentoProductSyncRequest (MaxProducts 500).
const catalogSync = {
  disallowUnknown: true,
  fields: {
    products: (v, path, errors) => {
      if (!Array.isArray(v) || v.length > 500) return false;
      v.forEach((p, i) => validateObject(p, catalogProduct, `${path}[${i}]`, errors));
      return true;
    },
    reconcileGroupIds: (v) => v === null || (Array.isArray(v) && v.every((s) => typeof s === 'string')),
  },
};

// The one API key the mock accepts, and the company it belongs to.
export const API_KEY = 'test-api-key';
const API_KEY_TENANT = 'test-tenant';

const routes = {
  '/data/conversionevents': { schema: conversionEvent, ok: (n) => [201, { id: `mock-${n}` }] },
  '/data/identify': { schema: identify, ok: () => [204, null] },
  // Like DataApi, a key from another company than the module's Tenant ID registers nothing.
  '/data/magento/ping': {
    schema: magentoPing, auth: 'apiKey',
    ok: (n, body) => body?.tenantId && body.tenantId.toLowerCase() !== API_KEY_TENANT
      ? [409, { tenantId: API_KEY_TENANT }]
      : [200, { success: true, tenantId: API_KEY_TENANT }],
  },
  '/data/magento/products/sync': { schema: catalogSync, auth: 'apiKey', ok: () => [200, { synced: 0, errors: 0 }] },
  '/data/magento/deactivate': { schema: { disallowUnknown: false, required: ['siteUrl'], fields: { siteUrl: types.any } }, auth: 'apiKey', ok: () => [200, { success: true }] },
};

function validateRequest(req, json, raw) {
  const route = routes[req.url.toLowerCase()];
  if (!route) return { status: 404, errors: [`unknown endpoint ${req.url}`] };
  const errors = [];
  if (req.method !== 'POST') errors.push(`method ${req.method} not allowed`);
  if (route.auth === 'apiKey') {
    // DataApi authenticates a request that names a tenant as that tenant's storefront and ignores the
    // key, so a key-authenticated call must not send BB-Tenant-Id.
    if (req.headers['bb-tenant-id'] || req.headers.authorization !== API_KEY) return { status: 401, errors: ['API key missing or refused'] };
  } else if (!req.headers['bb-tenant-id']) errors.push('missing BB-Tenant-Id header');
  if (!(req.headers['content-type'] ?? '').startsWith('application/json')) errors.push('content-type must be application/json');
  if (raw && json === null) errors.push('body is not valid JSON');
  else validateObject(json, route.schema, '$', errors);
  return { route, errors };
}

const readBody = (req) =>
  new Promise((resolve) => {
    const chunks = [];
    req.on('data', (c) => chunks.push(c));
    req.on('end', () => resolve(Buffer.concat(chunks).toString('utf8')));
  });

const sendJson = (res, status, data) => {
  res.writeHead(status, { 'Content-Type': 'application/json' });
  res.end(JSON.stringify(data));
};

const api = createHttpsServer(
  { key: readFileSync('/certs/server.key'), cert: readFileSync('/certs/server.crt') },
  async (req, res) => {
    const raw = await readBody(req);
    let json = null;
    try {
      json = raw ? JSON.parse(raw) : null;
    } catch {}

    const { route, errors, status: routeStatus } = validateRequest(req, json, raw);
    let [status, body] = routeStatus
      ? [routeStatus, { errors }]
      : errors.length
        ? [400, { title: 'One or more validation errors occurred.', errors }]
        : route.ok(requests.length + 1, json);
    if (behavior.status !== 200 || behavior.body) {
      status = behavior.status;
      body = behavior.body;
    }

    requests.push({
      time: new Date().toISOString(),
      method: req.method,
      path: req.url,
      headers: req.headers,
      rawBody: raw,
      body: json,
      contractErrors: errors,
      responseStatus: status,
    });
    console.log(`${req.method} ${req.url} -> ${status} ${raw}${errors.length ? `\n  contract errors: ${errors.join('; ')}` : ''}`);

    if (behavior.delayMs) await new Promise((r) => setTimeout(r, behavior.delayMs));
    if (status === 204 || body === null) {
      res.writeHead(status);
      return res.end();
    }
    sendJson(res, status, body);
  },
);

const control = createHttpServer(async (req, res) => {
  if (req.url === '/__requests' && req.method === 'GET') return sendJson(res, 200, requests);
  if (req.url === '/__requests' && req.method === 'DELETE') {
    requests = [];
    behavior = { ...DEFAULT_BEHAVIOR };
    return sendJson(res, 200, { ok: true });
  }
  if (req.url === '/__behavior' && req.method === 'PUT') {
    behavior = { ...DEFAULT_BEHAVIOR, ...JSON.parse((await readBody(req)) || '{}') };
    return sendJson(res, 200, behavior);
  }
  if (req.url === '/health') return sendJson(res, 200, { ok: true });
  sendJson(res, 404, { error: 'not found' });
});

api.listen(443, () => console.log('mock data API listening on :443'));
control.listen(3000, () => console.log('mock control API listening on :3000'));
