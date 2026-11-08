import test from 'node:test';
import assert from 'node:assert/strict';
import {
  projectAgentPerformanceV2, createAgentPerformanceV2Handler,
  registerAgentPerformanceV2
} from './pabx-agent-performance-v2.js';

const makeAgent = (connections = 2) => ({
  chamadas_unicas_conectadas: connections,
  eventos_connect: connections,
  tentativas_sem_resposta_fora_almoco: 3,
  chamadas_unicas_com_tentativa_sem_resposta: 2,
  tentativas_excluidas_almoco: 1,
  destino_das_tentativas: {
    propria: 1, colega: 1, outro: 0,
    encerrada_sem_connect_na_fila: 0, indeterminado: 1,
  },
  media_tempo_toque_ate_connect_seg: null,
  amostras_tempo_toque: 0,
  media_espera_fila_ate_connect_seg: 5.5,
  amostras_espera_fila: 2,
  media_tempo_conversa_seg: 45,
  amostras_conversa: 2,
  conexoes_por_hora_local: Object.fromEntries(Array.from({length: 24}, (_, i) => [String(i).padStart(2, '0'), i === 12 ? connections : 0])),
  disponibilidade_horas: null,
  chamadas_por_hora_disponivel: null,
  ocupacao_durante_toques: null,
  resolucao_ixc: null,
  reincidencia: null,
  caller_id: 'SHOULD_NOT_LEAK'
});
const fixture = () => ({
  versao: 2, periodo_dias: 30, inicio_unix: 100, fim_unix: 10000,
  primeiro_evento_unix: 200, ultimo_evento_unix: 9000,
  arquivos_considerados: 2, cobertura_integral_confirmada: false,
  fuso_horario: 'America/Sao_Paulo',
  chamadas_unicas_encerradas_sem_connect_na_fila: 1,
  atendentes: { Maria: makeAgent(52), Emylly: makeAgent(34) },
  caller_id: 'SECRET', avisos: ['raw client secret'],
});
const options = (payload = fixture()) => ({
  readToken: () => 'a'.repeat(64),
  fetchImpl: async (url, request) => {
    assert.equal(url.searchParams.get('action'), 'queue_report_v2');
    assert.equal(url.searchParams.get('days'), '30');
    assert.equal(request.method, 'GET');
    assert.equal(request.headers.Authorization, 'Bearer ' + 'a'.repeat(64));
    return {
      ok: true, headers: { get: () => 'application/json' },
      json: async () => ({ ok: true, action: 'queue_report_v2', result: payload }),
    };
  },
});
test('returns sanitized v2 report and only two allowlisted agents', async () => {
  const result = await createAgentPerformanceV2Handler(options())({ dias: '30' });
  assert.equal(result.isError, undefined);
  const output = result.content[0].text;
  assert.doesNotMatch(output, /SECRET|SHOULD_NOT_LEAK|raw client/);
  const data = JSON.parse(output);
  assert.equal(data.atendentes.Maria.chamadas_unicas_conectadas, 52);
  assert.equal(data.atendentes.Emylly.media_tempo_toque_ate_connect_seg, null);
  assert.equal(data.cobertura_integral_confirmada, false);
});
test('rejects invalid periods before sending any request', async () => {
  let called = 0;
  const response = await createAgentPerformanceV2Handler({
    ...options(), fetchImpl: async () => { called++; throw Error('no'); },
  })({ dias: '365' });
  assert.equal(response.isError, true);
  assert.equal(called, 0);
});
test('rejects inconsistent counts and does not coerce unknown values to zero', () => {
  const bad = fixture();
  bad.atendentes.Maria.destino_das_tentativas.propria = 5;
  assert.throws(() => projectAgentPerformanceV2(bad), /inconsistent_counts/);
  const invalid = fixture();
  invalid.atendentes.Emylly.chamadas_unicas_conectadas = null;
  assert.throws(() => projectAgentPerformanceV2(invalid), /invalid_count/);
});
test('rejects upstream failures and never reveals upstream error detail', async () => {
  const result = await createAgentPerformanceV2Handler({
    ...options(), fetchImpl: async () => { throw Error('TOKEN_secret_customer'); },
  })({ dias: '30' });
  assert.equal(result.isError, true);
  assert.doesNotMatch(result.content[0].text, /TOKEN_secret_customer/);
});
test('registers a new tool without changing old queue report', () => {
  const registered = [];
  registerAgentPerformanceV2({ registerTool: (name) => registered.push(name) });
  assert.deepEqual(registered, ['pabx_desempenho_atendentes_v2']);
});
