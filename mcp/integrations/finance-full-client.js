import { readFileSync } from 'node:fs';

export const RESOURCES = Object.freeze(['capabilities', 'accounts', 'account', 'summary', 'suppliers', 'categories', 'cost_centers', 'receipts', 'manual_expenses', 'receipt_file', 'alert_settings', 'whatsapp_status']);
export const ACTIONS = Object.freeze(['account.create', 'account.update', 'account.paid', 'account.delete', 'account.delete_batch', 'supplier.create', 'supplier.update', 'supplier.delete', 'category.create', 'category.update', 'category.delete', 'cost_center.create', 'cost_center.update', 'cost_center.delete', 'receipt.upload', 'receipt.update', 'receipt.analyze', 'receipt.keep_duplicate', 'receipt.delete', 'manual_expense.create', 'manual_expense.update', 'manual_expense.delete', 'alert_settings.update', 'whatsapp.connect']);

async function jsonResponse(response) {
  if (!response.ok) {
    // Validation keys help fix a request without returning submitted values or SQL details.
    if (response.status === 422 && (response.headers.get('content-type') || '').includes('application/json')) {
      const text = await response.text();
      if (text.length < 16384) {
        try {
          const fields = Object.keys(JSON.parse(text).errors || {}).filter(k => /^[a-zA-Z0-9_.]+$/.test(k)).slice(0, 30);
          if (fields.length) throw new Error('Finance validation fields: ' + fields.join(', '));
        } catch (error) { if (error.message.startsWith('Finance validation fields:')) throw error; }
      }
    }
    throw new Error(`Finance full API HTTP ${response.status}`);
  }
  if (!(response.headers.get('content-type') || '').includes('application/json')) throw new Error('Finance full API returned non-JSON');
  const reader = response.body.getReader();
  const chunks = [];
  let size = 0;
  try {
    while (true) {
      const { done, value } = await reader.read();
      if (done) break;
      size += value.length;
      if (size > 8 * 1024 * 1024) throw new Error('Finance response too large');
      chunks.push(value);
    }
    return JSON.parse(Buffer.concat(chunks).toString('utf8'));
  } finally { await reader.cancel(); }
}

export function createFinanceFullClient({ baseUrl = 'https://financeiro.jrconect.com', tokenFile = '/run/secrets/finance-full-token', fetchImpl = globalThis.fetch } = {}) {
  const base = new URL(baseUrl);
  if (base.protocol !== 'https:' || base.username || base.password || base.search || base.hash || base.pathname !== '/') throw new Error('Invalid finance URL');
  async function request(path, body, filters = {}) {
    const token = readFileSync(tokenFile, 'utf8').trim();
    if (token.length < 32) throw new Error('Full finance token missing');
    const url = new URL('/internal/mcp/finance/full/' + path, base);
    for (const key of ['scope', 'id', 'status', 'q', 'due_from', 'due_to', 'page', 'limit']) {
      if (filters[key] !== undefined && filters[key] !== null) url.searchParams.set(key, String(filters[key]));
    }
    return jsonResponse(await fetchImpl(url, {
      method: body ? 'POST' : 'GET', redirect: 'manual', signal: AbortSignal.timeout(60000),
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/json', 'Content-Type': 'application/json' },
      ...(body ? { body: JSON.stringify(body) } : {}),
    }));
  }
  return {
    read(resource, filters = {}) {
      if (!RESOURCES.includes(resource)) throw new Error('Finance resource not allowed');
      return request(resource, null, filters);
    },
    execute(action, scope, id, payload) {
      if (!ACTIONS.includes(action) || !['company', 'personal'].includes(scope)) throw new Error('Finance action or scope not allowed');
      return request('execute', { action, scope, id, payload, confirm: 'CONFIRMAR_OPERACAO_FINANCEIRA' });
    },
  };
}
