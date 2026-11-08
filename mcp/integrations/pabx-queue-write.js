// Explicitly authorized queue pause/unpause on Issabel. Never used by read-only catalog.
import { readFileSync } from 'node:fs';
import { z } from 'zod';

const PABX_ENDPOINT = 'https://pabx.jrconect.com/api/mcp/index.php';
const TOKEN_FILE = '/run/secrets/pabx-admin-token';
const INTERFACE_RE = /^(?:PJSIP|SIP)\/[A-Za-z0-9_-]{1,64}$/;
const QUEUE_RE = /^[A-Za-z0-9_-]{1,64}$/;

export function createQueueWriteHandler(action, {
  fetchImpl = fetch,
  readToken = (path) => readFileSync(path, 'utf8').trim(),
} = {}) {
  if (!['queue_pause', 'queue_unpause'].includes(action)) {
    throw new Error('unsupported_action');
  }
  return async ({ interface: agentInterface, queue, confirmar }) => {
    if (confirmar !== 'SIM' || !INTERFACE_RE.test(agentInterface || '') ||
        !QUEUE_RE.test(queue || '')) {
      return { isError: true, content: [{ type: 'text',
        text: 'Operacao nao executada. Informe interface SIP valida, fila valida e confirmar=SIM apos aprovacao explicita.' }] };
    }
    try {
      const token = readToken(TOKEN_FILE);
      if (!/^[a-f0-9]{64}$/i.test(token)) throw new Error('invalid_admin_secret');
      const url = new URL(PABX_ENDPOINT);
      url.searchParams.set('action', action);
      const response = await fetchImpl(url, {
        method: 'POST',
        redirect: 'error',
        cache: 'no-store',
        headers: {
          Authorization: 'Bearer ' + token,
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-JRC-Confirm': 'yes',
        },
        body: JSON.stringify({ interface: agentInterface, queue }),
        signal: AbortSignal.timeout(12000),
      });
      if (!response.ok || !(response.headers.get('content-type') || '').includes('application/json')) {
        throw new Error('admin_http_error');
      }
      const payload = await response.json();
      if (payload?.ok !== true || payload.action !== action || !('result' in payload)) {
        throw new Error('admin_response_invalid');
      }
      // Only expose acknowledgment, never raw Asterisk output or arbitrary API fields.
      return { content: [{ type: 'text', text: JSON.stringify({
        executado: true, acao: action, interface: agentInterface, fila: queue,
        mensagem: 'Operacao confirmada pela API Issabel. Verifique o estado atual pela consulta da fila.',
      }) }] };
    } catch {
      return { isError: true, content: [{ type: 'text',
        text: 'Nao foi possivel confirmar a alteracao no PABX. Consulte o estado da fila e os registros de auditoria antes de repetir.' }] };
    }
  };
}

export function registerPabxQueueWriteTools(mcp, options = {}) {
  // Registration belongs exclusively to the PABX ADMIN OAuth handler.
  // NEVER call this method from includePabxRead / read-only variants.
  const inputSchema = {
    interface: z.string().regex(INTERFACE_RE),
    queue: z.string().regex(QUEUE_RE),
    confirmar: z.literal('SIM').describe('Somente apos confirmacao humana especifica para esta alteracao.'),
  };
  mcp.registerTool('pabx_pausar_atendente', {
    description: 'ALTERACAO REAL: pausa atendente numa fila Issabel. Exige role administrativa, token admin, confirmacao explicita e validar fila/ramal antes da chamada.',
    inputSchema,
  }, createQueueWriteHandler('queue_pause', options));
  mcp.registerTool('pabx_retomar_atendente', {
    description: 'ALTERACAO REAL: retira pausa de atendente na fila Issabel. Exige role administrativa, token admin e confirmacao explicita.',
    inputSchema,
  }, createQueueWriteHandler('queue_unpause', options));
}
