import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { EventEmitter } from 'node:events';
import { PassThrough } from 'node:stream';
import { financeFullMcpAuthorized } from '../integrations/finance-full-auth.js';
import { createFinanceFullClient, RESOURCES, ACTIONS } from '../integrations/finance-full-client.js';
import { createFinanceHostClient } from '../integrations/finance-host-client.js';
import { registerFinanceFullSuite } from '../integrations/finance-full-tools.js';

const owner = '11111111-1111-1111-1111-111111111111';
const payload = { sub: owner, azp: 'chatgpt-mcp', realm_access: { roles: ['finance-mcp-owner'] } };
const env = { FINANCE_MCP_FULL_ENABLED: 'true', FINANCE_MCP_FULL_ALLOWED_SUB: owner };
test('full authorization requires a separate owner role, explicit allowlist and enablement', () => {
  assert.equal(financeFullMcpAuthorized(payload, env), true);
  for (const p of [null, {}, { ...payload, azp: 'opa-suite' }, { ...payload, sub: '22222222-2222-2222-2222-222222222222' }, { ...payload, realm_access: { roles: ['finance-mcp-admin'] } }]) assert.equal(financeFullMcpAuthorized(p, env), false);
  for (const e of [{}, { ...env, FINANCE_MCP_FULL_ENABLED: 'false' }, { ...env, FINANCE_MCP_FULL_ALLOWED_SUB: '' }]) assert.equal(financeFullMcpAuthorized(payload, e), false);
});

test('API routes are fixed; personal scope, filters, pagination and confirmation are preserved', async () => {
  const dir = mkdtempSync(join(tmpdir(), 'finance-test-'));
  try {
    const tokenFile = join(dir, 'token'); writeFileSync(tokenFile, 't'.repeat(64));
    const calls = [];
    const client = createFinanceFullClient({ tokenFile, fetchImpl: async (url, options) => { calls.push({ url, options }); return new Response('{"ok":true}', { headers: { 'Content-Type': 'application/json' } }); } });
    await client.read('accounts', { scope: 'personal', due_from: '2006-01-01', page: 2, limit: 100, command: 'id' });
    assert.equal(calls[0].url.pathname, '/internal/mcp/finance/full/accounts');
    assert.equal(calls[0].url.searchParams.get('scope'), 'personal');
    assert.equal(calls[0].url.searchParams.get('page'), '2');
    assert.equal(calls[0].url.searchParams.has('command'), false);
    assert.equal(calls[0].options.redirect, 'manual');
    await client.execute('account.update', 'personal', 32, { amount: 20 });
    assert.deepEqual(JSON.parse(calls[1].options.body), { action: 'account.update', scope: 'personal', id: 32, payload: { amount: 20 }, confirm: 'CONFIRMAR_OPERACAO_FINANCEIRA' });
    assert.throws(() => client.read('../.env'));
    assert.throws(() => client.execute('shell.exec', 'company', 1, {}));
    for (const baseUrl of ['http://financeiro.jrconect.com', 'https://user:password@financeiro.jrconect.com', 'https://financeiro.jrconect.com/x']) assert.throws(() => createFinanceFullClient({ baseUrl }));
  } finally { rmSync(dir, { recursive: true, force: true }); }
});

test('API errors never disclose response bodies or tokens', async () => {
  const dir = mkdtempSync(join(tmpdir(), 'finance-test-')); const tokenFile = join(dir, 'token'); writeFileSync(tokenFile, 't'.repeat(64));
  try {
    const client = createFinanceFullClient({ tokenFile, fetchImpl: async () => new Response('sensitive SQL password', { status: 500 }) });
    await assert.rejects(client.read('summary'), { message: 'Finance full API HTTP 500' });
    const large = createFinanceFullClient({ tokenFile, fetchImpl: async () => new Response('x'.repeat(9 * 1024 * 1024), { headers: { 'Content-Type': 'application/json' } }) });
    await assert.rejects(large.read('summary'), /too large/);
  } finally { rmSync(dir, { recursive: true, force: true }); }
});

test('full suite registers independently with explicit write confirmation and binary resources', async () => {
  const registry = new Map(); const calls = [];
  registerFinanceFullSuite({ registerTool(name, meta, callback) { registry.set(name, { meta, callback }); } }, {
    client: { read: async (...args) => { calls.push(args); return { base64: 'eA==', mime_type: 'image/png' }; }, execute: async (...args) => calls.push(args) },
    host: { run: async (...args) => calls.push(args) },
  });
  assert.equal(registry.size, 4);
  const read = registry.get('financeiro_acesso_total_consultar');
  assert.equal(read.meta.annotations.readOnlyHint, true);
  const receipt = await read.callback({ resource: 'receipt_file', id: 5, scope: 'personal' });
  assert.equal(receipt.content[0].resource.blob, 'eA==');
  const execute = registry.get('financeiro_acesso_total_executar');
  assert.equal((await execute.callback({ action: 'account.delete', scope: 'company', id: 1, payload: {} })).isError, true);
  assert.equal(calls.length, 1);
  assert.equal(execute.meta.annotations.destructiveHint, true);
  assert.ok(RESOURCES.includes('receipts')); assert.ok(ACTIONS.includes('account.delete_batch'));
});

test('host SSH pins keys and sends JSON on stdin; arbitrary commands cannot run', async () => {
  let command, args, input;
  const spawnImpl = (c, a) => {
    command = c; args = a;
    const child = new EventEmitter(); child.stdout = new PassThrough(); child.stderr = new PassThrough(); child.stdin = new PassThrough(); child.kill = () => {};
    child.stdin.on('data', chunk => { input = JSON.parse(chunk.toString()); });
    child.stdin.on('finish', () => { child.stdout.write('{"ok":true,"result":{}}'); child.emit('close', 0); });
    return child;
  };
  const host = createFinanceHostClient({ host: 'wiki.jrconect.com', spawnImpl });
  await host.run('git'); assert.equal(command, 'ssh');
  assert.ok(args.includes('StrictHostKeyChecking=yes')); assert.ok(args.includes('IdentitiesOnly=yes'));
  assert.equal(args.at(-1), 'finance-host-agent'); assert.equal(input.action, 'git');
  assert.throws(() => host.run('restart'), /confirmation/);
  assert.throws(() => host.run('exec', { command: 'id' }), /not allowed/);
  assert.throws(() => createFinanceHostClient({ host: '-oProxyCommand=id' }), /Invalid/);
});
