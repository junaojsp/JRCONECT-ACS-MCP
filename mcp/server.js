import { createServer } from 'node:http';
import { readFileSync } from 'node:fs';
import { timingSafeEqual } from 'node:crypto';
import { AsyncLocalStorage } from 'node:async_hooks';
import { createMcpHandler, McpServer } from '@modelcontextprotocol/server';
import { toNodeHandler } from '@modelcontextprotocol/node';
import { validarToken } from './auth.js';
import { obterRecursosAcs, verificarIntegracoesAcs } from './acs-monitor.js';

const opaToken = readFileSync('/run/secrets/opa-token', 'utf8').trim();
if (!/^[a-f0-9]{64}$/i.test(opaToken)) {
  throw new Error('Segredo Opa ausente ou formato inesperado');
}
const expected = Buffer.from(opaToken, 'utf8');
const requestIdentity = new AsyncLocalStorage();
const allowedSubjects = new Set((process.env.ACS_MONITOR_ALLOWED_SUBS || '').split(',').map(s => s.trim()).filter(Boolean));
function autorizadoAcs() {
  const identity = requestIdentity.getStore();
  return process.env.ACS_MONITOR_ENABLED === 'true' &&
    identity?.client === 'chatgpt-mcp' &&
    allowedSubjects.size > 0 &&
    allowedSubjects.has(identity.sub);
}
function acsTool(action) {
  return async () => {
    if (!autorizadoAcs()) {
      return { isError: true, content: [{ type: 'text', text: 'Acesso nao autorizado ao monitoramento ACS.' }] };
    }
    try {
      const data = await action();
      return { content: [{ type: 'text', text: JSON.stringify(data) }] };
    } catch {
      return { isError: true, content: [{ type: 'text', text: 'Nao foi possivel coletar as metricas do ACS.' }] };
    }
  };
}
const resourceMetadataUrl = 'https://mcp.jrconect.com/.well-known/oauth-protected-resource';
const resourceMetadata = Object.freeze({
  resource: 'https://mcp.jrconect.com/mcp',
  authorization_servers: ['https://auth.jrconect.com/realms/jrconect'],
});
function validarTokenOpa(token) {
  // Tokens arbitrariamente grandes nao devem consumir memoria sem limite.
  if (typeof token !== 'string' || token.length !== opaToken.length) return false;
  const received = Buffer.from(token, 'utf8');
  return received.length === expected.length && timingSafeEqual(received, expected);
}

const handler = createMcpHandler(() => {
  const mcp = new McpServer({ name: 'jrconect-opa-mcp', version: '1.0.0' });
  mcp.registerTool('status_servidor', {
    description: 'Verifica o funcionamento do MCP JR Conect',
  }, async () => ({
    content: [{ type: 'text', text: 'Servidor MCP JR Conect funcionando.' }],
  }));
  if (process.env.ACS_MONITOR_ENABLED === 'true') {
    mcp.registerTool('acs_recursos_host', {
      description: 'Consulta uptime, carga e memoria do host ACS, somente leitura e com autorizacao por usuario.',
    }, acsTool(obterRecursosAcs));
    mcp.registerTool('acs_status_integracoes', {
      description: 'Verifica disponibilidade HTTP dos endpoints fixos MCP e Keycloak, sem comandos administrativos.',
    }, acsTool(verificarIntegracoesAcs));
  }
  return mcp;
});
const mcpHandler = toNodeHandler(handler);

function unauthorized(res, reason = 'unauthorized') {
  if (res.headersSent) return;
  res.writeHead(401, {
    'Content-Type': 'application/json',
    'WWW-Authenticate': `Bearer realm="jrconect-mcp", resource_metadata="${resourceMetadataUrl}"`,
    'Cache-Control': 'no-store',
  });
  res.end(JSON.stringify({ error: reason }));
}

createServer(async (req, res) => {
  if (req.method === 'GET' && req.url?.split('?')[0] === '/.well-known/oauth-protected-resource') {
    res.writeHead(200, { 'Content-Type': 'application/json', 'Cache-Control': 'public, max-age=300' });
    res.end(JSON.stringify(resourceMetadata));
    return;
  }
  if (req.method === 'GET' && req.url === '/health') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ status: 'ok' }));
    return;
  }
  if (req.url?.split('?')[0] !== '/mcp') {
    res.writeHead(404); res.end('Not Found'); return;
  }
  const match = /^Bearer ([^\s]+)$/i.exec(req.headers.authorization || '');
  if (!match) return unauthorized(res);
  if (validarTokenOpa(match[1])) {
    req.mcpUser = { sub: 'opa-suite', client: 'opa-suite' };
    return requestIdentity.run(req.mcpUser, () => mcpHandler(req, res));
  }
  try {
    const payload = await validarToken(match[1]);
    req.mcpUser = { sub: payload.sub, client: payload.azp };
    return await requestIdentity.run(req.mcpUser, () => mcpHandler(req, res));
  } catch {
    return unauthorized(res, 'invalid_token');
  }
}).listen(3100, '0.0.0.0', () => {
  console.log('JR Conect MCP listening on 3100');
});
