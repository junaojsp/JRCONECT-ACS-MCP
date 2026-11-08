// JR Conect agent performance v2. Read-only, separate from existing queue_report.
import { readFileSync } from 'node:fs';
import { z } from 'zod';

const ENDPOINT = 'https://pabx.jrconect.com/api/mcp/index.php';
const VALID_DAYS = new Set(['7', '15', '30']);
const AGENTS = ['Maria', 'Emylly'];
const OUTCOMES = ['propria', 'colega', 'outro', 'encerrada_sem_connect_na_fila', 'indeterminado'];
const NUMBER_FIELDS = [
  'chamadas_unicas_conectadas', 'eventos_connect',
  'tentativas_sem_resposta_fora_almoco', 'chamadas_unicas_com_tentativa_sem_resposta',
  'tentativas_excluidas_almoco', 'amostras_tempo_toque', 'amostras_espera_fila'
];
const OPTIONAL_METRICS = [
  'media_tempo_toque_ate_connect_seg', 'media_espera_fila_ate_connect_seg',
  'disponibilidade_horas', 'chamadas_por_hora_disponivel',
  'ocupacao_durante_toques', 'resolucao_ixc', 'reincidencia'
];

function count(value) {
  if (!Number.isSafeInteger(value) || value < 0) throw new Error('invalid_count');
  return value;
}
function optionalNumber(value) {
  if (value === null || value === undefined) return null;
  if (typeof value !== 'number' || !Number.isFinite(value) || value < 0) {
    throw new Error('invalid_metric');
  }
  return value;
}
function timestamp(value) {
  if (value === null || value === undefined) return null;
  return count(value);
}
export function projectAgentPerformanceV2(data) {
  if (data?.versao !== 2 || !VALID_DAYS.has(String(data?.periodo_dias)) ||
      data?.fuso_horario !== 'America/Sao_Paulo' ||
      data?.cobertura_integral_confirmada !== false ||
      !data?.atendentes || typeof data.atendentes !== 'object') {
    throw new Error('invalid_schema');
  }
  const safe = {
    versao: 2,
    periodo_dias: count(data.periodo_dias),
    inicio_unix: count(data.inicio_unix),
    fim_unix: count(data.fim_unix),
    primeiro_evento_unix: timestamp(data.primeiro_evento_unix),
    ultimo_evento_unix: timestamp(data.ultimo_evento_unix),
    arquivos_considerados: count(data.arquivos_considerados),
    fuso_horario: 'America/Sao_Paulo',
    cobertura_integral_confirmada: false,
    chamadas_unicas_encerradas_sem_connect_na_fila:
      count(data.chamadas_unicas_encerradas_sem_connect_na_fila),
    atendentes: {},
    avisos: [
      'Logs podem ter cobertura parcial; verificar primeira e ultima ocorrencia.',
      'Toques repetidos nao sao chamadas perdidas.',
      'Nao atribuir abandono de fila como falha individual.',
      'Metricas nulas nao foram comprovadas; nao calcular nota ou ranking.',
    ],
  };
  if (safe.inicio_unix > safe.fim_unix ||
      (safe.primeiro_evento_unix !== null && safe.primeiro_evento_unix < safe.inicio_unix) ||
      (safe.ultimo_evento_unix !== null && safe.ultimo_evento_unix > safe.fim_unix)) {
    throw new Error('invalid_period');
  }
  for (const name of AGENTS) {
    const source = data.atendentes[name];
    if (!source || typeof source !== 'object') throw new Error('missing_agent');
    const agent = {};
    for (const key of NUMBER_FIELDS) agent[key] = count(source[key]);
    for (const key of OPTIONAL_METRICS) agent[key] = optionalNumber(source[key]);
    agent.destino_das_tentativas = {};
    for (const key of OUTCOMES) agent.destino_das_tentativas[key] =
      count(source.destino_das_tentativas?.[key]);
    const total = Object.values(agent.destino_das_tentativas).reduce((a, b) => a + b, 0);
    if (total !== agent.tentativas_sem_resposta_fora_almoco ||
        agent.chamadas_unicas_com_tentativa_sem_resposta > total ||
        agent.chamadas_unicas_conectadas > agent.eventos_connect) {
      throw new Error('inconsistent_counts');
    }
    safe.atendentes[name] = agent;
  }
  return safe;
}
export function createAgentPerformanceV2Handler({
  baseUrl = ENDPOINT,
  tokenFile = '/run/secrets/pabx-read-token',
  readToken = (path) => readFileSync(path, 'utf8').trim(),
  fetchImpl = fetch,
} = {}) {
  return async ({ dias }) => {
    try {
      if (!VALID_DAYS.has(String(dias))) throw new Error('invalid_days');
      const url = new URL(baseUrl);
      if (url.protocol !== 'https:' || url.hostname !== 'pabx.jrconect.com' ||
          url.pathname !== '/api/mcp/index.php' || url.search || url.hash ||
          url.username || url.password || url.port) {
        throw new Error('invalid_endpoint');
      }
      const token = readToken(tokenFile);
      if (!/^[a-f0-9]{64}$/i.test(token)) throw new Error('invalid_token');
      url.searchParams.set('action', 'queue_report_v2');
      url.searchParams.set('days', String(dias));
      const response = await fetchImpl(url, {
        method: 'GET', redirect: 'error', cache: 'no-store',
        signal: AbortSignal.timeout(12000),
        headers: { Authorization: 'Bearer ' + token, Accept: 'application/json' },
      });
      if (!response.ok || !(response.headers.get('content-type') || '').includes('application/json')) {
        throw new Error('upstream_failure');
      }
      const result = await response.json();
      if (result?.ok !== true || result.action !== 'queue_report_v2') {
        throw new Error('upstream_contract');
      }
      const safe = projectAgentPerformanceV2(result.result);
      if (safe.periodo_dias !== Number(dias)) throw new Error('period_mismatch');
      return { content: [{ type: 'text', text: JSON.stringify(safe) }] };
    } catch {
      return { isError: true, content: [{ type: 'text', text:
        'Relatorio v2 indisponivel. Validar endpoint queue_report_v2 do Issabel, logs e permissao READ antes do deploy.' }] };
    }
  };
}
export function registerAgentPerformanceV2(mcp, options = {}) {
  mcp.registerTool('pabx_desempenho_atendentes_v2', {
    description: 'Relatorio agregado de produtividade e desfechos de Maria e Emylly. Chamadas unicas, toques repetidos, tempo de toque quando registrado, almoço seg-sex e qualidade da cobertura. Nao produz ranking.',
    inputSchema: { dias: z.enum(['7', '15', '30']) },
  }, createAgentPerformanceV2Handler(options));
}
