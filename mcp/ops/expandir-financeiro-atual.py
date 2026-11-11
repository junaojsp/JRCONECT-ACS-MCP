#!/usr/bin/env python3
# Modules from reviewed commit 38e8dac3a1496ebbae117c07a423d9e9bd2f138d.
import json
FILES=json.loads('{"finance-suite.js": "import { createFinanceClient } from \'./finance-client.js\';\\nimport { registerFinanceTools } from \'./finance-tools.js\';\\nimport { createFinanceAdminClient } from \'./finance-admin-client.js\';\\nimport { registerFinanceAdminTools } from \'./finance-admin-tools.js\';\\nimport { registerFinanceFullSuite } from \'./finance-full-tools.js\';\\n\\n/**\\n * Register only in the existing OAuth-gated /mcp-admin handler.\\n * NEVER register these tools in the shared /mcp handler used by Opa Suite.\\n * Secret files are read on tool invocation, not on server startup.\\n */\\nexport function registerFinanceSuite(adminMcp, options = {}) {\\n  registerFinanceTools(adminMcp, createFinanceClient(options.read));\\n  registerFinanceAdminTools(adminMcp, createFinanceAdminClient(options.admin));\\n  registerFinanceFullSuite(adminMcp, {\\n    api: { ...options.admin, tokenFile: options.admin?.tokenFile || \'/run/secrets/finance-admin-token\' },\\n    includeHost: false,\\n  });\\n}\\n\\n", "finance-full-tools.js": "import { z } from \'zod\';\\nimport { createFinanceFullClient, RESOURCES, ACTIONS } from \'./finance-full-client.js\';\\nimport { createFinanceHostClient, HOST_READS, HOST_WRITES } from \'./finance-host-client.js\';\\n\\nconst confirmation = \'CONFIRMAR_OPERACAO_FINANCEIRA\';\\nconst maintenance = \'CONFIRMAR_MANUTENCAO_FINANCEIRO\';\\nconst record = z.record(z.string(), z.unknown());\\nconst scope = z.enum([\'company\', \'personal\']);\\nconst date = z.string().regex(/^\\\\d{4}-\\\\d{2}-\\\\d{2}$/);\\nconst annotations = (readOnlyHint) => ({ readOnlyHint, destructiveHint: !readOnlyHint, idempotentHint: false, openWorldHint: true });\\nfunction handler(fn) {\\n  return async (args) => {\\n    try {\\n      const data = await fn(args);\\n      if (args.resource === \'receipt_file\') return { content: [{ type: \'resource\', resource: { uri: `finance-receipt://${args.id}`, mimeType: data.mime_type, blob: data.base64 } }] };\\n      return { content: [{ type: \'text\', text: JSON.stringify(data) }] };\\n    } catch (e) { return { isError: true, content: [{ type: \'text\', text: String(e.message || e) }] }; }\\n  };\\n}\\n\\n/** Register only in an authenticated Financeiro/admin factory; never the shared Opa handler. */\\nexport function registerFinanceFullSuite(mcp, options = {}) {\\n  const client = options.client || createFinanceFullClient(options.api);\\n  mcp.registerTool(\'financeiro_acesso_total_consultar\', {\\n    description: \'Consulta administrativa do Financeiro: empresa, pessoal do administrador configurado, fornecedores, categorias, centros de custo, comprovantes, gastos manuais, alertas e estado WhatsApp. Consulte capabilities para a lista. Datas due_from/due_to filtram vencimento das contas ou data das despesas. account requer id e retorna detalhes e codigo de pagamento. Listagens sao paginadas. Nao executa pagamentos bancarios.\',\\n    annotations: annotations(true),\\n    inputSchema: { resource: z.enum(RESOURCES), scope: scope.default(\'company\'), id: z.number().int().positive().optional(), status: z.string().max(40).optional(), q: z.string().max(255).optional(), due_from: date.optional(), due_to: date.optional(), page: z.number().int().positive().optional(), limit: z.number().int().min(1).max(100).optional() },\\n  }, handler(({ resource, ...filters }) => client.read(resource, filters)));\\n  mcp.registerTool(\'financeiro_acesso_total_executar\', {\\n    description: \'Administra empresa e pessoal. Acoes account.create/update/paid/delete/delete_batch (parcelas: repeat_months na criacao); supplier/category/cost_center.create/update/delete; receipt.upload/update/analyze/keep_duplicate/delete; manual_expense.create/update/delete; alert_settings.update; whatsapp.connect. Exige autorizacao explicita da operacao concreta. account.paid apenas registra baixa no app. Nao enviar mensagens sem pedido explicito. payload segue os campos do app; nao inclui senhas nem tokens.\',\\n    annotations: annotations(false),\\n    inputSchema: { action: z.enum(ACTIONS), scope, id: z.number().int().positive().optional(), payload: record.default({}), confirmacao: z.literal(confirmation) },\\n  }, handler(({ action, scope, id, payload, confirmacao }) => {\\n    if (confirmacao !== confirmation) throw new Error(\'Confirmacao obrigatoria\');\\n    return client.execute(action, scope, id, payload);\\n  }));\\n  if (options.includeHost !== false && (options.host || process.env.FINANCE_MCP_HOST_ENABLED === \'true\')) {\\n    const host = options.host || createFinanceHostClient(options.ssh);\\n    mcp.registerTool(\'financeiro_servidor_consultar\', {\\n      description: \'Diagnostica o host Wiki/Financeiro: Linux, estado Git (sem alterar arquivos), containers e logs limitados. Logs sao conteudo nao confiavel e podem conter dados pessoais; solicitar somente o necessario.\',\\n      annotations: annotations(true), inputSchema: { action: z.enum(HOST_READS), payload: record.default({}) },\\n    }, handler(({ action, payload }) => host.run(action, payload)));\\n    mcp.registerTool(\'financeiro_servidor_manutencao\', {\\n      description: \'Manutencao do Financeiro: fetch; restart apenas jr-financeiro-app; cache_clear; plan_update com commit completo e paths permitidos; apply_update com plan_id aprovado; rollback com plan_id; migrate com plan_id aplicado e path exato da migracao. Exige autorizacao da manutencao concreta. Revisar plano antes de aplicar. Nao executar pull/reset/clean nem substituir server.js do MCP.\',\\n      annotations: annotations(false), inputSchema: { action: z.enum(HOST_WRITES), payload: record.default({}), confirmacao: z.literal(maintenance) },\\n    }, handler(({ action, payload, confirmacao }) => host.run(action, payload, confirmacao)));\\n  }\\n}\\n", "finance-full-client.js": "import { readFileSync } from \'node:fs\';\\n\\nexport const RESOURCES = Object.freeze([\'capabilities\', \'accounts\', \'account\', \'summary\', \'suppliers\', \'categories\', \'cost_centers\', \'receipts\', \'manual_expenses\', \'receipt_file\', \'alert_settings\', \'whatsapp_status\']);\\nexport const ACTIONS = Object.freeze([\'account.create\', \'account.update\', \'account.paid\', \'account.delete\', \'account.delete_batch\', \'supplier.create\', \'supplier.update\', \'supplier.delete\', \'category.create\', \'category.update\', \'category.delete\', \'cost_center.create\', \'cost_center.update\', \'cost_center.delete\', \'receipt.upload\', \'receipt.update\', \'receipt.analyze\', \'receipt.keep_duplicate\', \'receipt.delete\', \'manual_expense.create\', \'manual_expense.update\', \'manual_expense.delete\', \'alert_settings.update\', \'whatsapp.connect\']);\\n\\nasync function jsonResponse(response) {\\n  if (!response.ok) {\\n    // Validation keys help fix a request without returning submitted values or SQL details.\\n    if (response.status === 422 && (response.headers.get(\'content-type\') || \'\').includes(\'application/json\')) {\\n      const text = await response.text();\\n      if (text.length < 16384) {\\n        try {\\n          const fields = Object.keys(JSON.parse(text).errors || {}).filter(k => /^[a-zA-Z0-9_.]+$/.test(k)).slice(0, 30);\\n          if (fields.length) throw new Error(\'Finance validation fields: \' + fields.join(\', \'));\\n        } catch (error) { if (error.message.startsWith(\'Finance validation fields:\')) throw error; }\\n      }\\n    }\\n    throw new Error(`Finance full API HTTP ${response.status}`);\\n  }\\n  if (!(response.headers.get(\'content-type\') || \'\').includes(\'application/json\')) throw new Error(\'Finance full API returned non-JSON\');\\n  const reader = response.body.getReader();\\n  const chunks = [];\\n  let size = 0;\\n  try {\\n    while (true) {\\n      const { done, value } = await reader.read();\\n      if (done) break;\\n      size += value.length;\\n      if (size > 8 * 1024 * 1024) throw new Error(\'Finance response too large\');\\n      chunks.push(value);\\n    }\\n    return JSON.parse(Buffer.concat(chunks).toString(\'utf8\'));\\n  } finally { await reader.cancel(); }\\n}\\n\\nexport function createFinanceFullClient({ baseUrl = \'https://financeiro.jrconect.com\', tokenFile = \'/run/secrets/finance-full-token\', fetchImpl = globalThis.fetch } = {}) {\\n  const base = new URL(baseUrl);\\n  if (base.protocol !== \'https:\' || base.username || base.password || base.search || base.hash || base.pathname !== \'/\') throw new Error(\'Invalid finance URL\');\\n  async function request(path, body, filters = {}) {\\n    const token = readFileSync(tokenFile, \'utf8\').trim();\\n    if (token.length < 32) throw new Error(\'Full finance token missing\');\\n    const url = new URL(\'/internal/mcp/finance/full/\' + path, base);\\n    for (const key of [\'scope\', \'id\', \'status\', \'q\', \'due_from\', \'due_to\', \'page\', \'limit\']) {\\n      if (filters[key] !== undefined && filters[key] !== null) url.searchParams.set(key, String(filters[key]));\\n    }\\n    return jsonResponse(await fetchImpl(url, {\\n      method: body ? \'POST\' : \'GET\', redirect: \'manual\', signal: AbortSignal.timeout(60000),\\n      headers: { Authorization: `Bearer ${token}`, Accept: \'application/json\', \'Content-Type\': \'application/json\' },\\n      ...(body ? { body: JSON.stringify(body) } : {}),\\n    }));\\n  }\\n  return {\\n    read(resource, filters = {}) {\\n      if (!RESOURCES.includes(resource)) throw new Error(\'Finance resource not allowed\');\\n      return request(resource, null, filters);\\n    },\\n    execute(action, scope, id, payload) {\\n      if (!ACTIONS.includes(action) || ![\'company\', \'personal\'].includes(scope)) throw new Error(\'Finance action or scope not allowed\');\\n      return request(\'execute\', { action, scope, id, payload, confirm: \'CONFIRMAR_OPERACAO_FINANCEIRA\' });\\n    },\\n  };\\n}\\n", "finance-host-client.js": "import { spawn } from \'node:child_process\';\\n\\nexport const HOST_READS = Object.freeze([\'status\', \'git\', \'containers\', \'logs\']);\\nexport const HOST_WRITES = Object.freeze([\'fetch\', \'restart\', \'cache_clear\', \'plan_update\', \'apply_update\', \'rollback\', \'migrate\']);\\n\\nexport function createFinanceHostClient({ host = process.env.FINANCE_HOST_ADDRESS, user = \'mcp-finance\', keyFile = \'/run/secrets/finance-host-key\', knownHostsFile = \'/run/secrets/finance-host-known-hosts\', spawnImpl = spawn } = {}) {\\n  if (!host || !/^[a-zA-Z0-9][a-zA-Z0-9.-]*$/.test(host) || !/^[a-z_][a-z0-9_-]*$/.test(user)) throw new Error(\'Invalid finance host\');\\n  return {\\n    run(action, payload = {}, confirm) {\\n      if (![...HOST_READS, ...HOST_WRITES].includes(action)) throw new Error(\'Host action not allowed\');\\n      if (HOST_WRITES.includes(action) && confirm !== \'CONFIRMAR_MANUTENCAO_FINANCEIRO\') throw new Error(\'Maintenance confirmation required\');\\n      return new Promise((resolve, reject) => {\\n        // The SSH key is bound to a root-owned forced command on the Wiki host.\\n        const child = spawnImpl(\'ssh\', [\'-T\', \'-o\', \'BatchMode=yes\', \'-o\', \'StrictHostKeyChecking=yes\', \'-o\', \'ConnectTimeout=5\', \'-o\', \'IdentitiesOnly=yes\', \'-o\', \'ClearAllForwardings=yes\', \'-i\', keyFile, \'-o\', `UserKnownHostsFile=${knownHostsFile}`, `${user}@${host}`, \'finance-host-agent\'], { stdio: [\'pipe\', \'pipe\', \'pipe\'] });\\n        const chunks = []; let size = 0; let settled = false;\\n        const finish = (error, value) => { if (settled) return; settled = true; clearTimeout(timer); error ? reject(error) : resolve(value); };\\n        const timer = setTimeout(() => { child.kill(\'SIGKILL\'); finish(new Error(\'Finance host timed out\')); }, 90000);\\n        child.on(\'error\', () => finish(new Error(\'Finance host unavailable\')));\\n        child.stdin.on(\'error\', () => {});\\n        child.stdout.on(\'data\', chunk => {\\n          size += chunk.length;\\n          if (size > 1024 * 1024) { child.kill(\'SIGKILL\'); finish(new Error(\'Finance host response too large\')); }\\n          else chunks.push(chunk);\\n        });\\n        // Drain stderr, but never return SSH diagnostics or command output containing credentials.\\n        child.stderr.resume();\\n        child.on(\'close\', code => {\\n          if (code !== 0) return finish(new Error(\'Finance host request failed\'));\\n          try { const data = JSON.parse(Buffer.concat(chunks).toString(\'utf8\')); if (!data.ok) throw new Error(); finish(null, data); }\\n          catch { finish(new Error(\'Finance host rejected request\')); }\\n        });\\n        child.stdin.end(JSON.stringify({ action, payload, confirm }));\\n      });\\n    },\\n  };\\n}\\n"}')
BASE="import { createFinanceClient } from './finance-client.js';\nimport { registerFinanceTools } from './finance-tools.js';\nimport { createFinanceAdminClient } from './finance-admin-client.js';\nimport { registerFinanceAdminTools } from './finance-admin-tools.js';\n\n/**\n * Register only in the existing OAuth-gated /mcp-admin handler.\n * NEVER register these tools in the shared /mcp handler used by Opa Suite.\n * Secret files are read on tool invocation, not on server startup.\n */\nexport function registerFinanceSuite(adminMcp, options = {}) {\n  registerFinanceTools(adminMcp, createFinanceClient(options.read));\n  registerFinanceAdminTools(adminMcp, createFinanceAdminClient(options.admin));\n}\n"
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import time
import urllib.request
from datetime import datetime

ROOT=Path('/opt/jrconect-mcp')
NAME='jrconect-mcp'

def run(args, data=None, timeout=180):
    r=subprocess.run(args,cwd=ROOT,input=data,stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=timeout)
    if r.returncode: raise RuntimeError('Falha em '+args[0]+' (codigo '+str(r.returncode)+')')
    return r.stdout

def reconcile(old,new):
    if old.strip() in (BASE.encode().strip(),new.strip()): return new
    with tempfile.TemporaryDirectory() as tmp:
        paths=[Path(tmp)/str(i) for i in range(3)]
        for p,b in zip(paths,[old,BASE.encode(),new]): p.write_bytes(b)
        r=subprocess.run(['git','merge-file','-p',*map(str,paths)],stdout=subprocess.PIPE,stderr=subprocess.PIPE)
        if r.returncode: raise RuntimeError('Customizacao conflitante em finance-suite.js; arquivos preservados')
        return r.stdout

def atomic(path,data,meta=None):
    fd,tmp=tempfile.mkstemp(dir=path.parent)
    try:
        with os.fdopen(fd,'wb') as f:
            f.write(data);f.flush();os.fsync(f.fileno())
            os.fchmod(f.fileno(),meta.st_mode & 0o777 if meta else 0o644)
            if meta: os.fchown(f.fileno(),meta.st_uid,meta.st_gid)
        os.replace(tmp,path)
    finally:
        if os.path.exists(tmp):os.unlink(tmp)

def healthy():
    for _ in range(20):
        try:
            with urllib.request.urlopen('http://127.0.0.1:3100/health',timeout=2) as response:
                if response.status==200:return
        except Exception:pass
        time.sleep(1)
    raise RuntimeError('MCP nao respondeu ao health check')

def main():
    os.umask(0o077)
    meta=json.loads(run(['docker','inspect',NAME]))[0]
    image=meta['Image']; tag=meta['Config']['Image']; work=meta['Config']['WorkingDir']
    service=meta['Config'].get('Labels',{}).get('com.docker.compose.service')
    if service != NAME or not re.fullmatch(r'/[A-Za-z0-9_./-]+',work or ''):raise RuntimeError('Instalacao diferente da identificada')
    if '@' in tag or tag.startswith('sha256:'):raise RuntimeError('Imagem fixada por digest; requer atualizacao especifica')
    if any(m['Destination']==work or m['Destination'].startswith(work+'/integrations') for m in meta['Mounts']):raise RuntimeError('Codigo montado externamente; preservar montagem')
    healthy()
    oldsuite=run(['docker','exec',NAME,'node','-e',"process.stdout.write(require('node:fs').readFileSync('integrations/finance-suite.js'))"])
    source=ROOT/'integrations/finance-suite.js'
    if source.read_bytes().strip()!=oldsuite.strip():raise RuntimeError('Modulo local difere do ativo; precisa reconciliar antes')
    contents={name:content.encode() for name,content in FILES.items()}
    contents['finance-suite.js']=reconcile(oldsuite,contents['finance-suite.js'])
    timestamp=datetime.now().strftime('%Y%m%d-%H%M%S-%f')
    backup=Path('/root/backups/jrconect-mcp')/('financeiro-'+timestamp)
    backup.mkdir(parents=True,mode=0o700)
    originals={}
    for name,data in contents.items():
        path=ROOT/'integrations'/name
        if path.is_symlink() or path.parent.is_symlink():raise RuntimeError('Link simbolico inesperado')
        old=path.read_bytes() if path.exists() else None
        if name!='finance-suite.js' and old is not None and old!=data:raise RuntimeError('Modulo adicional ja tem alteracoes: '+name)
        originals[name]=(old,path.stat() if path.exists() else None)
        if old is not None:shutil.copy2(path,backup/name)
    (backup/'image-before.txt').write_text(image+'\n'+tag+'\n')
    newtag='jrconect-mcp-financeiro:'+timestamp.lower()
    oldtag='jrconect-mcp-backup:'+timestamp.lower()
    run(['docker','tag',image,oldtag])
    with tempfile.TemporaryDirectory(prefix='financeiro-build-') as tmp:
        stage=Path(tmp)
        for name,data in contents.items():
            (stage/name).write_bytes(data)
            (stage/name).chmod(0o644)
        lines=['FROM '+oldtag]+['COPY '+json.dumps([name,work+'/integrations/'+name]) for name in contents]
        (stage/'Dockerfile').write_text('\n'.join(lines)+'\n')
        run(['docker','build','--network=none','-t',newtag,str(stage)],timeout=300)
    smoke="import {registerFinanceSuite} from './integrations/finance-suite.js';const names=[];registerFinanceSuite({registerTool(n){names.push(n)}});if(names.length!==14||names.some(n=>n.startsWith('financeiro_servidor')))throw Error('catalogo inesperado');console.log('14 ferramentas Financeiro OK');"
    run(['docker','run','--rm','--network=none','--entrypoint','node',newtag,'--input-type=module','-e',smoke])
    touched=[]
    activation=False
    try:
        for name,data in contents.items():
            path=ROOT/'integrations'/name;old,st=originals[name]
            if (path.read_bytes() if path.exists() else None)!=old:raise RuntimeError('Arquivo alterado durante atualizacao')
            touched.append(name);atomic(path,data,st)
        activation=True
        run(['docker','tag',newtag,tag])
        run(['docker','compose','up','-d','--no-deps','--no-build','--force-recreate',service])
        healthy()
        run(['docker','exec',NAME,'node','--input-type=module','-e',smoke])
        apicheck="import {createFinanceFullClient} from './integrations/finance-full-client.js';const c=createFinanceFullClient({tokenFile:'/run/secrets/finance-admin-token'});const r=await c.read('capabilities');if(!r.scopes?.includes('personal'))throw Error('API ampliada indisponivel');console.log('API empresa e pessoal OK');"
        run(['docker','exec',NAME,'node','--input-type=module','-e',apicheck])
    except Exception:
        for name in reversed(touched):
            old,st=originals[name];path=ROOT/'integrations'/name
            if old is None:path.unlink(missing_ok=True)
            else:atomic(path,old,st)
        if activation:
            run(['docker','tag',oldtag,tag])
            run(['docker','compose','up','-d','--no-deps','--no-build','--force-recreate',service])
            healthy()
        print('Versao anterior restaurada. Backup:',backup)
        raise
    print('MCP_FINANCEIRO_EXPANDIDO_OK')
    print('14 ferramentas | Empresa e pessoal: OK | Conexao e credenciais atuais mantidas')
    print('Backup:',backup)
    print('Atualize o catalogo da conexao JR Conect Financeiro no ChatGPT.')

if __name__=='__main__':
    try:main()
    except Exception as e:
        print('PARADO:',str(e));raise SystemExit(1)
