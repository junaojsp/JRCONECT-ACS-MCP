import test from 'node:test';
import assert from 'node:assert/strict';
import { createPabxQueueReportHandler } from './pabx-queue-report.js';
const token = 'a'.repeat(64);
const base = {
  readToken: () => token,
  fetchImpl: async (url, options) => {
    assert.equal(url.searchParams.get('action'), 'queue_report');
    assert.equal(url.searchParams.get('days'), '30');
    assert.equal(options.headers.Authorization, 'Bearer ' + token);
    return {
      ok: true,
      headers: { get: () => 'application/json' },
      json: async () => ({ ok: true, action: 'queue_report', result: {
        periodo_dias: 30, atendentes: {
          Maria: { conectadas: 52, toques_sem_resposta: 30 },
          Emylly: { conectadas: 34, toques_sem_resposta: 39 },
        }, filas: { '601': { abandono_cliente: 2 } },
        segredo_interno: 'NAO_PODE_VAZAR',
      } }),
    };
  },
};
test('returns only allowlisted aggregate fields', async () => {
  const response = await createPabxQueueReportHandler(base)({ dias: '30' });
  assert.equal(response.isError, undefined);
  const text = response.content[0].text;
  assert.equal(text.includes('NAO_PODE_VAZAR'), false);
  const data = JSON.parse(text);
  assert.equal(data.atendentes.Maria.conectadas, 52);
  assert.equal(data.atendentes.Emylly.toques_sem_resposta, 39);
  assert.equal(data.atendentes.Ryan.conectadas, 0);
  assert.equal(data.filas['601'].abandono_cliente, 2);
});
test('rejects arbitrary periods before fetch', async () => {
  let called = false;
  const response = await createPabxQueueReportHandler({
    ...base,
    fetchImpl: async () => { called = true; throw Error('unexpected'); },
  })({ dias: '365' });
  assert.equal(response.isError, true);
  assert.equal(called, false);
});
test('masks upstream failures', async () => {
  const response = await createPabxQueueReportHandler({
    ...base,
    fetchImpl: async () => { throw Error('secret debug token'); },
  })({ dias: '30' });
  assert.equal(response.isError, true);
  assert.equal(response.content[0].text.includes('secret debug token'), false);
});
