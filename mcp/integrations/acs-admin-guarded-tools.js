/**
 * Administration control-plane for ACS, built to be mounted in the existing
 * central MCPv2 only after its actual request-scoped JWT context is audited.
 *
 * This module is NOT an OS command executor. It exposes one fixed, bounded
 * operation and expects a SEPARATE LOCAL HELPER to perform it and record a
 * durable host-side audit. It never gets Docker socket, root, ssh or shell.
 */
import { randomBytes } from 'node:crypto';
import { z } from 'zod';

const RESTART_MONITOR = 'reiniciar_monitor_acs';
const PHRASE = 'CONFIRMO REINICIAR MONITOR ACS';
const TARGET = 'jrconect-acs-monitor-teste';
const TTL_MS = 2 * 60 * 1000;
const MAX_PENDING = 32;

// The test monitor is NOT the MCPv2, its Keycloak, its proxy or the ACS database.
// No arbitrary container/service name is accepted from a caller.
export const ACS_ADMIN_ACTIONS = Object.freeze({
  [RESTART_MONITOR]: Object.freeze({
    descricao: 'Reiniciar somente o monitor independente do ACS (porta 3103)',
    destino: TARGET,
    impacto: 'Interrompe temporariamente as consultas de monitoramento ACS na porta 3103',
    confirmacao: PHRASE,
  }),
});

export function acsAdminAuthorized(payload, env = process.env) {
  if (env.ACS_ADMIN_MCP_ENABLED !== 'true') return false;
  if (!payload || payload.azp !== 'chatgpt-mcp' || typeof payload.sub !== 'string') return false;
  const allowed = (env.ACS_ADMIN_ALLOWED_SUB || '')
    .split(',').map(s => s.trim()).filter(Boolean);
  if (!allowed.length || !allowed.includes(payload.sub)) return false;
  const roles = Array.isArray(payload.realm_access?.roles)
    ? payload.realm_access.roles : [];
  return roles.includes('acs-mcp-admin');
}

const pending = new Map();
function prune(now) {
  for (const [ticket, item] of pending) {
    if (now >= item.expires) pending.delete(ticket);
  }
}
function denied() {
  return {isError: true, content: [{type:'text', text:'ACS_ADMIN_ACESSO_NEGADO'}]};
}
function error(msg) {
  return {isError: true, content: [{type:'text', text:msg}]};
}
function result(data) {
  return {content: [{type:'text', text:JSON.stringify(data)}]};
}

/**
 * @param mcp Actual MCP server instance.
 * @param identityForCurrentRequest Returns a VERIFIED JWT payload, per request.
 * @param helper Strictly allowlisted host helper, signature async ({action,target,subject})=>...
 *               Helper MUST keep its own durable audit and allowlist, and fail closed.
 * @param audit Pre-action durable audit callback, async evt=>...; must throw on error.
 * @param options Optional env/clock for tests.
 */
export function registerAcsAdminTools(mcp, identityForCurrentRequest, helper, audit, options = {}) {
  const env = options.env ?? process.env;
  if (env.ACS_ADMIN_MCP_ENABLED !== 'true') return 0;
  if (typeof identityForCurrentRequest !== 'function' ||
      typeof helper !== 'function' || typeof audit !== 'function') {
    throw new Error('ACS_ADMIN_HELPER_E_AUDITORIA_OBRIGATORIOS');
  }
  const now = options.now ?? Date.now;

  mcp.registerTool('acs_admin_planejar', {
    description: 'Cria proposta de reinicio SOMENTE do monitor ACS. Requer Keycloak acs-mcp-admin e confirmacao humana explicita posterior; nao executa nada.',
    inputSchema: {acao: z.literal(RESTART_MONITOR)},
  }, async ({acao}) => {
    const identity = identityForCurrentRequest();
    if (!acsAdminAuthorized(identity, env)) return denied();
    if (acao !== RESTART_MONITOR) return error('ACS_ADMIN_ACAO_NAO_PERMITIDA');
    prune(now());
    if (pending.size >= MAX_PENDING) return error('ACS_ADMIN_LIMITE_PLANOS');
    const ticket = randomBytes(24).toString('hex');
    const expires = now() + TTL_MS;
    pending.set(ticket, {acao, sub:identity.sub, azp:identity.azp, expires});
    return result({acao, ticket, expires_em: new Date(expires).toISOString(),
      ...ACS_ADMIN_ACTIONS[acao], executado:false,
      aviso:'NENHUMA ACAO EXECUTADA. Solicite confirmacao EXPLICITA do usuario em nova mensagem.'});
  });

  mcp.registerTool('acs_admin_executar', {
    description: 'Executa SOMENTE um plano previamente criado, autorizado, nao expirado e confirmado explicitamente pelo usuario. Apenas monitor ACS na 3103.',
    inputSchema: {
      ticket: z.string().regex(/^[a-f0-9]{48}$/),
      confirmacao: z.literal(PHRASE),
    },
  }, async ({ticket, confirmacao}) => {
    const identity = identityForCurrentRequest();
    if (!acsAdminAuthorized(identity, env)) return denied();
    prune(now());
    const plan = pending.get(ticket);
    if (!plan || now() >= plan.expires) return error('ACS_ADMIN_PLANO_AUSENTE_OU_EXPIRADO');
    if (plan.sub !== identity.sub || plan.azp !== identity.azp)
      return denied();
    if (confirmacao !== PHRASE) return error('ACS_ADMIN_CONFIRMACAO_INVALIDA');
    // Consume ticket before ANY side effect to prevent replay.
    pending.delete(ticket);
    const action = ACS_ADMIN_ACTIONS[plan.acao];
    if (!action) return error('ACS_ADMIN_ACAO_NAO_PERMITIDA');
    const request = {
      action:plan.acao, target:action.destino,
      // The helper must only accept this exact action+target. Do not trust
      // subject to grant privileges: the helper must be narrowly allowlisted.
      subject:identity.sub,
    };
    try {
      // Durable pre-action audit is mandatory. Fail CLOSED if audit unavailable.
      await audit({fase:'solicitada',acao:request.action,alvo:request.target,subject:request.subject});
    } catch {
      return error('ACS_ADMIN_AUDITORIA_INDISPONIVEL');
    }
    try {
      const execution = await helper(request);
      // Never relay arbitrary helper stdout, tokens or sensitive paths.
      const ok = execution?.ok === true;
      try {
        await audit({fase:ok?'sucesso':'falha',acao:request.action,
          alvo:request.target,subject:request.subject});
      } catch {
        return error('ACS_ADMIN_RESULTADO_SEM_AUDITORIA: verificar host antes de repetir');
      }
      return ok ? result({acao:request.action,alvo:request.target,executado:true,
        resultado:'ACEITO_PELO_AGENTE_RESTRITO'}) : error('ACS_ADMIN_AGENTE_FALHOU');
    } catch {
      try { await audit({fase:'falha',acao:request.action,alvo:request.target,subject:request.subject}); }
      catch { return error('ACS_ADMIN_RESULTADO_SEM_AUDITORIA: verificar host antes de repetir'); }
      return error('ACS_ADMIN_AGENTE_INDISPONIVEL');
    }
  });
  return 2;
}
