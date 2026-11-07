// Read-only, aggregate PABX queue metrics. No raw queue_log or subscriber data.
import { readFileSync } from 'node:fs';
import { z } from 'zod';

export function createPabxQueueReportHandler({
  baseUrl = 'https://pabx.jrconect.com/api/mcp/index.php',
  tokenFile = '/run/secrets/pabx-read-token',
  fetchImpl = fetch,
  readToken = (path) => readFileSync(path, 'utf8').trim(),
} = {}) {
  return async ({ dias }) => {
    try {
      // Never accept remote URLs, commands, file paths or arbitrary periods.
      if (!['7', '15', '30'].includes(String(dias))) throw new Error('periodo_invalido');
      const url = new URL(baseUrl);
      if (url.protocol !== 'https:' || url.username || url.password || url.search || url.hash ||
          url.hostname !== 'pabx.jrconect.com' || url.pathname !== '/api/mcp/index.php') {
        throw new Error('endpoint_invalido');
      }
      url.searchParams.set('action', 'queue_report');
      url.searchParams.set('days', String(dias));
      const token = readToken(tokenFile);
      if (!/^[a-f0-9]{64}$/i.test(token)) throw new Error('token_invalido');
      const response = await fetchImpl(url, {
        method: 'GET',
        redirect: 'error',
        cache: 'no-store',
        signal: AbortSignal.timeout(10000),
        headers: { Authorization: 'Bearer ' + token, Accept: 'application/json' },
      });
      if (!response.ok) throw new Error('upstream_http_error');
      if (!(response.headers.get('content-type') || '').includes('application/json')) {
        throw new Error('resposta_invalida');
      }
      const payload = await response.json();
      if (payload?.ok !== true || payload?.action !== 'queue_report' ||
          !payload.result || !payload.result.atendentes) throw new Error('contrato_invalido');
      const { periodo_dias, inicio_unix, fim_unix, primeiro_evento_unix,
        ultimo_evento_unix, arquivos_lidos, atendentes, filas, observacao, almocos, fuso_horario } = payload.result;
      // Explicit schema projection: never forward arbitrary upstream fields.
      const safe = { periodo_dias, inicio_unix, fim_unix, primeiro_evento_unix,
        ultimo_evento_unix, arquivos_lidos, atendentes: {}, filas: {}, observacao, almocos, fuso_horario };
      for (const name of ['Maria', 'Emylly', 'Ryan']) {
        safe.atendentes[name] = {
          conectadas: Number(atendentes[name]?.conectadas || 0),
          toques_sem_resposta: Number(atendentes[name]?.toques_sem_resposta || 0),
          toques_no_almoco: Number(atendentes[name]?.toques_no_almoco || 0),
          toques_fora_almoco: Number(atendentes[name]?.toques_fora_almoco || 0),
        };
      }
      for (const queue of ['601', '602', '603']) {
        safe.filas[queue] = { abandono_cliente: Number(filas?.[queue]?.abandono_cliente || 0) };
      }
      return { content: [{ type: 'text', text: JSON.stringify(safe) }] };
    } catch {
      return { isError: true, content: [{ type: 'text',
        text: 'Relatorio indisponivel. Verifique implantacao do endpoint, acesso de leitura ao queue_log e token existente.' }] };
    }
  };
}
export function registerPabxQueueReport(mcp, options = {}) {
  mcp.registerTool('pabx_relatorio_atendentes', {
    description: 'Consulta agregados anonimos de chamadas conectadas, tentativas sem resposta por agente e abandono por fila nos ultimos 7, 15 ou 30 dias.',
    inputSchema: { dias: z.enum(['7', '15', '30']) },
  }, createPabxQueueReportHandler(options));
}
