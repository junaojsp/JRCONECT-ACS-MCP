import { spawn } from 'node:child_process';

export const HOST_READS = Object.freeze(['status', 'git', 'containers', 'logs']);
export const HOST_WRITES = Object.freeze(['fetch', 'restart', 'cache_clear', 'plan_update', 'apply_update', 'rollback', 'migrate']);

export function createFinanceHostClient({ host = process.env.FINANCE_HOST_ADDRESS, user = 'mcp-finance', keyFile = '/run/secrets/finance-host-key', knownHostsFile = '/run/secrets/finance-host-known-hosts', spawnImpl = spawn } = {}) {
  if (!host || !/^[a-zA-Z0-9][a-zA-Z0-9.-]*$/.test(host) || !/^[a-z_][a-z0-9_-]*$/.test(user)) throw new Error('Invalid finance host');
  return {
    run(action, payload = {}, confirm) {
      if (![...HOST_READS, ...HOST_WRITES].includes(action)) throw new Error('Host action not allowed');
      if (HOST_WRITES.includes(action) && confirm !== 'CONFIRMAR_MANUTENCAO_FINANCEIRO') throw new Error('Maintenance confirmation required');
      return new Promise((resolve, reject) => {
        // The SSH key is bound to a root-owned forced command on the Wiki host.
        const child = spawnImpl('ssh', ['-T', '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=yes', '-o', 'ConnectTimeout=5', '-o', 'IdentitiesOnly=yes', '-o', 'ClearAllForwardings=yes', '-i', keyFile, '-o', `UserKnownHostsFile=${knownHostsFile}`, `${user}@${host}`, 'finance-host-agent'], { stdio: ['pipe', 'pipe', 'pipe'] });
        const chunks = []; let size = 0; let settled = false;
        const finish = (error, value) => { if (settled) return; settled = true; clearTimeout(timer); error ? reject(error) : resolve(value); };
        const timer = setTimeout(() => { child.kill('SIGKILL'); finish(new Error('Finance host timed out')); }, 90000);
        child.on('error', () => finish(new Error('Finance host unavailable')));
        child.stdin.on('error', () => {});
        child.stdout.on('data', chunk => {
          size += chunk.length;
          if (size > 1024 * 1024) { child.kill('SIGKILL'); finish(new Error('Finance host response too large')); }
          else chunks.push(chunk);
        });
        // Drain stderr, but never return SSH diagnostics or command output containing credentials.
        child.stderr.resume();
        child.on('close', code => {
          if (code !== 0) return finish(new Error('Finance host request failed'));
          try { const data = JSON.parse(Buffer.concat(chunks).toString('utf8')); if (!data.ok) throw new Error(); finish(null, data); }
          catch { finish(new Error('Finance host rejected request')); }
        });
        child.stdin.end(JSON.stringify({ action, payload, confirm }));
      });
    },
  };
}
