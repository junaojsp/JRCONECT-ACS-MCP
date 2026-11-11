#!/usr/bin/env python3
"""Stage a new owner-only endpoint against the live server, never overwrite it."""
from pathlib import Path
import hashlib
import json
import subprocess
import sys


def stage(root):
    server = (root / 'server.js').read_text()
    if 'financeFullTransportHandler' in server or 'financeFullMcpAuthorized' in server:
        raise ValueError('Full endpoint already present; reconcile the live version')
    for anchor in ('const mcpHandler = toNodeHandler(handler);', 'createServer(async (req, res) => {'):
        if server.count(anchor) != 1: raise ValueError('Unexpected live server anchor: '+anchor)
    for file in ('finance-full-auth.js', 'finance-full-client.js', 'finance-full-tools.js', 'finance-host-client.js'):
        if not (root / 'integrations' / file).is_file(): raise ValueError('Missing module: '+file)
    imports = """import { financeFullMcpAuthorized } from './integrations/finance-full-auth.js';
import { registerFinanceFullSuite } from './integrations/finance-full-tools.js';
"""
    factory = """
const financeFullTransportHandler = toNodeHandler(createMcpHandler(() => {
  const full = new McpServer({ name: 'jrconect-finance-full-mcp', version: '2.0.0' });
  registerFinanceFullSuite(full);
  return full;
}));
"""
    handler = """
  if (req.method === 'GET' && req.url?.split('?')[0] === '/.well-known/oauth-protected-resource/mcp-finance-full') {
    res.writeHead(200, { 'Content-Type': 'application/json', 'Cache-Control': 'public, max-age=300' });
    res.end(JSON.stringify({ resource: 'https://mcp.jrconect.com/mcp-finance-full', authorization_servers: ['https://auth.jrconect.com/realms/jrconect'] })); return;
  }
  if (req.url?.split('?')[0] === '/mcp-finance-full') {
    const bearer = /^Bearer ([^\\s]+)$/i.exec(req.headers.authorization || '');
    if (!bearer) {
      res.writeHead(401, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store', 'WWW-Authenticate': 'Bearer resource_metadata="https://mcp.jrconect.com/.well-known/oauth-protected-resource/mcp-finance-full"' });
      res.end(JSON.stringify({ error: 'unauthorized' })); return;
    }
    let payload;
    try { payload = await validarToken(bearer[1]); }
    catch {
      res.writeHead(401, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store', 'WWW-Authenticate': 'Bearer resource_metadata="https://mcp.jrconect.com/.well-known/oauth-protected-resource/mcp-finance-full"' });
      res.end(JSON.stringify({ error: 'invalid_token' })); return;
    }
    if (!financeFullMcpAuthorized(payload)) {
      res.writeHead(403, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' });
      res.end(JSON.stringify({ error: 'access_denied' })); return;
    }
    req.mcpUser = { sub: payload.sub, client: payload.azp };
    req.url = req.url.replace(/^\\/mcp-finance-full(?=\\?|$)/, '/mcp');
    return financeFullTransportHandler(req, res);
  }
"""
    result = imports + server.replace('const mcpHandler = toNodeHandler(handler);', 'const mcpHandler = toNodeHandler(handler);\n'+factory, 1)
    result = result.replace('createServer(async (req, res) => {', 'createServer(async (req, res) => {\n'+handler, 1)
    out = root / '.finance-full-stage'
    out.mkdir(mode=0o700, exist_ok=True)
    (out / 'server.js').write_text(result)
    (out / 'manifest.json').write_text(json.dumps({'source_sha256': hashlib.sha256(server.encode()).hexdigest(), 'staged_sha256': hashlib.sha256(result.encode()).hexdigest()}))
    subprocess.run(['node', '--check', str(out / 'server.js')], check=True)
    return out


if __name__ == '__main__':
    try:
        out = stage(Path(sys.argv[1]) if len(sys.argv) == 2 else Path('/opt/jrconect-mcp'))
        print('STAGED_OK: '+str(out)+'; live files unchanged. Configure secret, owner authorization and host key before activation.')
    except Exception as e:
        sys.exit('STOP: '+str(e))
