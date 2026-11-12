#!/usr/bin/env python3
"""Stage an additive, bounded OAuth diagnostic. Never writes production."""
import argparse
import hashlib
import json
from pathlib import Path
import subprocess
import tempfile

MARKER = '// JRC_IXC_AUTH_DIAGNOSTIC_V1'
ANCHOR = "    const payload = await validarToken(match[1]);\n    if (process.env.SHIELD_MCP_ALLOWED_SUB === payload.sub &&"
BLOCK = r'''    // JRC_IXC_AUTH_DIAGNOSTIC_V1
    // Only verified OAuth claims; no JWT, subject, email or environment values.
    try {
      const diagnostic = {
        enabled: process.env.IXC_WS_MCP_ENABLED === 'true',
        client_matches: payload?.azp === 'chatgpt-mcp',
        subject_matches: String(process.env.IXC_WS_ALLOWED_SUB || '')
          .split(',').map(value => value.trim()).filter(Boolean).includes(payload?.sub),
        role_admin: Array.isArray(payload?.realm_access?.roles) &&
          payload.realm_access.roles.includes('ixc-mcp-admin'),
        role_read: Array.isArray(payload?.realm_access?.roles) &&
          payload.realm_access.roles.includes('ixc-mcp-read'),
        authorized: Boolean(ixcWebserviceAuthorized(payload)),
        shield_branch: process.env.SHIELD_MCP_ALLOWED_SUB === payload?.sub &&
          /^[a-f0-9-]{36}$/i.test(process.env.SHIELD_MCP_ALLOWED_SUB || ''),
      };
      const signature = JSON.stringify(diagnostic);
      // At most one entry per boolean combination (128 total per process).
      if (!jrcIxcDiagnosticSeen.has(signature)) {
        jrcIxcDiagnosticSeen.add(signature);
        console.info('JRC_IXC_AUTH_DIAGNOSTIC_V1 ' + signature);
      }
    } catch {
      // Diagnostics must never change authorization or request handling.
    }
'''
DECLARATION = 'const jrcIxcDiagnosticSeen = new Set();\n'

def patch(source):
    if MARKER in source:
        raise ValueError('diagnostico ja presente')
    if source.count(ANCHOR) != 1:
        raise ValueError('roteamento diferente da versao revisada; nao modificar')
    if source.count('const connections = new ConnectionRegistry();') != 1:
        raise ValueError('ancora de inicializacao inesperada; nao modificar')
    return source.replace('const connections = new ConnectionRegistry();',
                          DECLARATION + 'const connections = new ConnectionRegistry();', 1).replace(
        ANCHOR, '    const payload = await validarToken(match[1]);\n' + BLOCK +
        '    if (process.env.SHIELD_MCP_ALLOWED_SUB === payload.sub &&', 1)

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', type=Path, default=Path('/opt/jrconect-mcp'))
    args = parser.parse_args()
    source = args.root / 'server.js'
    original = source.read_bytes()
    candidate = patch(original.decode('utf-8'))
    directory = Path(tempfile.mkdtemp(prefix='jrc-ixc-diagnostic-'))
    directory.chmod(0o700)
    (directory / 'server.before.js').write_bytes(original)
    (directory / 'server.before.js').chmod(0o600)
    output = directory / 'server.candidate.js'
    output.write_text(candidate)
    output.chmod(0o600)
    subprocess.run(['node', '--input-type=module', '--check'], input=candidate,
                   text=True, check=True, capture_output=True)
    if source.read_bytes() != original:
        raise ValueError('origem mudou durante a leitura; descartar candidato')
    manifest = {'source_sha256': hashlib.sha256(original).hexdigest(),
                'candidate_sha256': hashlib.sha256(output.read_bytes()).hexdigest()}
    (directory / 'manifest.json').write_text(json.dumps(manifest, indent=2) + '\n')
    print('STAGED_OK: producao intacta; candidato e copia para reversao em ' + str(directory))
    print('Nenhum container, permissao, segredo ou arquivo ativo foi alterado.')

if __name__ == '__main__':
    try:
        main()
    except (OSError, ValueError, subprocess.CalledProcessError) as error:
        raise SystemExit('STOP: ' + type(error).__name__ + '; candidato nao aprovado, producao intacta')
