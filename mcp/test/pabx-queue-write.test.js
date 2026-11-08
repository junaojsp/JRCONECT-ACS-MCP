import test from 'node:test';
import assert from 'node:assert/strict';
import { createQueueWriteHandler, registerPabxQueueWriteTools } from '../integrations/pabx-queue-write.js';

const apiReply = (action) => ({
  ok: true,
  status: 200,
  headers: new Headers({ 'content-type': 'application/json' }),
  json: async () => ({ ok: true, action, result: ['accepted'] }),
});

test('requires explicit confirmation without any network request', async () => {
  let called = 0;
  const h = createQueueWriteHandler('queue_pause', {
    readToken: () => 'a'.repeat(64),
    fetchImpl: async () => { called++; throw new Error('unexpected'); },
  });
  const r = await h({ interface: 'PJSIP/101', queue: '601', confirmar: 'NAO' });
  assert.equal(r.isError, true);
  assert.equal(called, 0);
});

test('rejects unsafe interfaces, queue IDs and unsupported actions', async () => {
  assert.throws(() => createQueueWriteHandler('exec'), /unsupported_action/);
  const h = createQueueWriteHandler('queue_pause', {
    fetchImpl: () => { throw new Error('network should not be called'); },
  });
  assert.equal((await h({ interface: 'PJSIP/101;rm', queue: '601', confirmar: 'SIM' })).isError, true);
  assert.equal((await h({ interface: 'SIP/101', queue: '601;echo', confirmar: 'SIM' })).isError, true);
});

test('sends POST and confirmation to fixed Issabel endpoint only', async () => {
  let request;
  const h = createQueueWriteHandler('queue_unpause', {
    readToken: () => 'c'.repeat(64),
    fetchImpl: async (url, options) => {
      request = { url: String(url), ...options };
      return apiReply('queue_unpause');
    },
  });
  const r = await h({ interface: 'PJSIP/201', queue: '602', confirmar: 'SIM' });
  assert.equal(r.isError, undefined);
  assert.equal(request.method, 'POST');
  assert.equal(request.headers['X-JRC-Confirm'], 'yes');
  assert.equal(request.headers.Authorization, 'Bearer ' + 'c'.repeat(64));
  assert.equal(new URL(request.url).hostname, 'pabx.jrconect.com');
  assert.equal(new URL(request.url).searchParams.get('action'), 'queue_unpause');
  assert.deepEqual(JSON.parse(request.body), { interface: 'PJSIP/201', queue: '602' });
});

test('missing or invalid token always fails without sending', async () => {
  let calls = 0;
  const h = createQueueWriteHandler('queue_pause', {
    readToken: () => 'bad',
    fetchImpl: async () => { calls++; return apiReply('queue_pause'); },
  });
  const r = await h({ interface: 'PJSIP/201', queue: '602', confirmar: 'SIM' });
  assert.equal(r.isError, true);
  assert.equal(calls, 0);
});

test('rejects non-200 or wrong result and hides raw API errors', async () => {
  const h = createQueueWriteHandler('queue_pause', {
    readToken: () => 'b'.repeat(64),
    fetchImpl: async () => ({
      ok: false, status: 403,
      headers: new Headers({ 'content-type': 'application/json' }),
      json: async () => ({ error: 'secret-private-err' }),
    }),
  });
  const r = await h({ interface: 'PJSIP/201', queue: '602', confirmar: 'SIM' });
  assert.equal(r.isError, true);
  assert.doesNotMatch(JSON.stringify(r), /secret-private-err/);
});

test('registers only two explicit administrative tools', () => {
  const names = [];
  registerPabxQueueWriteTools({ registerTool: (name) => names.push(name) });
  assert.deepEqual(names, ['pabx_pausar_atendente', 'pabx_retomar_atendente']);
});
