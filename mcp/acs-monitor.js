import { readFileSync } from 'node:fs';

// /host/proc e um bind mount SOMENTE LEITURA do /proc do host ACS.
// Sem acesso ao Docker socket, SSH, shell, ou ao filesystem do host.
const base = '/host/proc';
const read = path => readFileSync(`${base}/${path}`, 'utf8');

export function obterRecursosAcs() {
  const uptime = Number(read('uptime').split(/\s+/)[0]);
  const [load1, load5, load15] = read('loadavg').trim().split(/\s+/).slice(0, 3).map(Number);
  const values = Object.fromEntries([...read('meminfo').matchAll(/^(MemTotal|MemAvailable|SwapTotal|SwapFree):\s+(\d+) kB/gm)].map(m => [m[1], Number(m[2])]));
  const total = values.MemTotal;
  const available = values.MemAvailable;
  if (![uptime, load1, load5, load15, total, available].every(Number.isFinite) || total <= 0) {
    throw new Error('METRICAS_INVALIDAS');
  }
  return {
    uptime_horas: Math.floor(uptime / 3600),
    carga_media: { '1min': load1, '5min': load5, '15min': load15 },
    memoria: {
      total_mib: Math.round(total / 1024),
      disponivel_mib: Math.round(available / 1024),
      uso_percentual_estimado: Math.round((1 - available / total) * 1000) / 10,
    },
    swap: Number.isFinite(values.SwapTotal) && Number.isFinite(values.SwapFree)
      ? { total_mib: Math.round(values.SwapTotal / 1024), livre_mib: Math.round(values.SwapFree / 1024) }
      : null,
  };
}

// Apenas endpoints FIXOS, sem parametro de URL fornecido pelo modelo.
const checks = Object.freeze([
  { nome: 'MCP JR Conect', url: 'https://mcp.jrconect.com/.well-known/oauth-protected-resource' },
  { nome: 'Keycloak JR Conect', url: 'https://auth.jrconect.com/realms/jrconect/.well-known/openid-configuration' },
]);

export async function verificarIntegracoesAcs() {
  const result = [];
  for (const c of checks) {
    try {
      const response = await fetch(c.url, {
        method: 'GET', redirect: 'error', signal: AbortSignal.timeout(4000),
        headers: { Accept: 'application/json' },
      });
      result.push({ servico: c.nome, status_http: response.status, acessivel: response.ok });
      await response.body?.cancel();
    } catch {
      result.push({ servico: c.nome, status_http: null, acessivel: false });
    }
  }
  return result;
}
