// ACS monitoring tools for the *existing central* JR Conect MCP.
// This module is not wired into the actual ACS server.js by this PR.
// The validated Keycloak JWT payload MUST be supplied from the existing
// per-request OAuth context; NEVER trust a user-provided payload.
import { readFileSync } from 'node:fs';

export function acsMonitorAuthorized(payload, env = process.env) {
  if (env.ACS_MONITOR_MCP_ENABLED !== 'true') return false;
  if (!payload || payload.azp !== 'chatgpt-mcp' || typeof payload.sub !== 'string') return false;
  const allow = (env.ACS_MONITOR_ALLOWED_SUB || '')
    .split(',').map(x => x.trim()).filter(Boolean);
  if (!allow.length || !allow.includes(payload.sub)) return false;
  const roles = Array.isArray(payload.realm_access?.roles)
    ? payload.realm_access.roles : [];
  return roles.includes('acs-mcp-admin');
}

export function collectAcsResources(readFile = readFileSync) {
  // The container must mount ONLY these 3 host proc files readonly under
  // /host/proc. Do not mount /proc as a directory or use Docker socket.
  const text = name => readFile('/host/proc/' + name, 'utf8');
  const uptime = Number(text('uptime').trim().split(/\s+/)[0]);
  const loads = text('loadavg').trim().split(/\s+/).slice(0, 3).map(Number);
  const mem = Object.fromEntries([...text('meminfo')
    .matchAll(/^(MemTotal|MemAvailable|SwapTotal|SwapFree):\s+(\d+) kB/gm)]
    .map(m => [m[1], Number(m[2])]));
  if (!Number.isFinite(uptime) || uptime < 0 ||
      loads.length !== 3 || loads.some(n => !Number.isFinite(n) || n < 0) ||
      !Number.isFinite(mem.MemTotal) || mem.MemTotal <= 0 ||
      !Number.isFinite(mem.MemAvailable) ||
      mem.MemAvailable < 0 || mem.MemAvailable > mem.MemTotal) {
    throw new Error('METRICAS_ACS_INVALIDAS');
  }
  return {
    uptime_horas: Math.floor(uptime / 3600),
    carga_media: {'1min': loads[0], '5min': loads[1], '15min': loads[2]},
    memoria: {
      total_mib: Math.round(mem.MemTotal / 1024),
      disponivel_mib: Math.round(mem.MemAvailable / 1024),
      uso_percentual_estimado: Math.round((1 - mem.MemAvailable / mem.MemTotal) * 1000) / 10,
    },
    swap: Number.isFinite(mem.SwapTotal) && Number.isFinite(mem.SwapFree)
      ? {total_mib: Math.round(mem.SwapTotal / 1024), livre_mib: Math.round(mem.SwapFree / 1024)}
      : null,
  };
}

const CHECKS = Object.freeze([
  {nome:'MCP JR Conect',url:'https://mcp.jrconect.com/.well-known/oauth-protected-resource'},
  {nome:'Keycloak JR Conect',url:'https://auth.jrconect.com/realms/jrconect/.well-known/openid-configuration'},
]);

export async function collectAcsIntegrationStatuses(fetchFn = fetch) {
  const result = [];
  for (const target of CHECKS) {
    try {
      const response = await fetchFn(target.url, {
        method:'GET', redirect:'error',
        signal:AbortSignal.timeout(4000),
        headers:{Accept:'application/json'}
      });
      result.push({servico:target.nome,status_http:response.status,acessivel:response.ok});
      await response.body?.cancel();
    } catch {
      result.push({servico:target.nome,status_http:null,acessivel:false});
    }
  }
  return result;
}

// identityForCurrentRequest MUST return already-verified Keycloak JWT payload,
// with azp, sub and realm_access.roles. It must be request-scoped
// (e.g. AsyncLocalStorage, not a mutable global or request supplied argument).
export function registerAcsMonitorTools(mcp, identityForCurrentRequest, opts = {}) {
  if (typeof identityForCurrentRequest !== 'function') {
    throw new Error('ACS_IDENTITY_PROVIDER_REQUIRED');
  }
  const env = opts.env ?? process.env;
  if (env.ACS_MONITOR_MCP_ENABLED !== 'true') return 0;
  const resources = opts.resources ?? collectAcsResources;
  const statuses = opts.statuses ?? collectAcsIntegrationStatuses;
  const wrap = task => async () => {
    // Check at call time. Static bearer of Opa never authorizes this access.
    if (!acsMonitorAuthorized(identityForCurrentRequest(), env)) {
      return {isError:true,content:[{type:'text',text:'ACS_ACESSO_NEGADO'}]};
    }
    try {
      const value = await task();
      return {content:[{type:'text',text:JSON.stringify(value)}]};
    } catch {
      // No paths, credentials, system command output or internal details.
      return {isError:true,content:[{type:'text',text:'ACS_MONITOR_CONSULTA_INDISPONIVEL'}]};
    }
  };
  mcp.registerTool('acs_recursos_host',{
    description:'Monitoramento somente leitura de uptime, carga, RAM e swap do host ACS. Requer OAuth Keycloak e permissao acs-mcp-admin.',
  },wrap(resources));
  mcp.registerTool('acs_status_integracoes',{
    description:'Disponibilidade HTTP agregada dos endpoints fixos MCP JR Conect e Keycloak. Requer OAuth Keycloak e permissao acs-mcp-admin.',
  },wrap(statuses));
  return 2;
}
