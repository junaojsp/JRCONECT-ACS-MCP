#!/usr/bin/env python3
"""Stage-only ACS monitoring overlay on EXACT live MCPv2 image.

Reads the running /app/server.js, checks fixed structural anchors and
produces a PRIVATE local candidate image. Does not start/restart containers,
touch /data, alter production, Nginx, Keycloak, tokens or connected plugins.

Only READ-ONLY ACS tools are staged. Admin actions remain disabled until
a separate authorized, audited local executor exists.
"""
import argparse
import hashlib
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile
import time

GREEN="jrconect-mcp-opa-ready"
LIVE="jrconect-mcp"
DIR=Path("/root/jrc-acs-central-stage")
REPO_URL="https://raw.githubusercontent.com/junaojsp/JRCONECT-ACS-MCP"
# PR #150 merged revision, immutable; do NOT download mutable main.
MONITOR_REV="c793e949a74675b274b31b7b75c40aaf631d4627"
ACS_MODULE="acs-monitor-central-tools.js"
SERVER_PATH="/app/server.js"

def fail(message):
    raise RuntimeError(message)

def call(*args, capture=False, timeout=45):
    p=subprocess.run(args, stdout=subprocess.PIPE if capture else subprocess.DEVNULL,
                     stderr=subprocess.PIPE, check=False, timeout=timeout)
    if p.returncode:
        fail("Falha no comando permitido: "+str(args[0])+" (exit "+str(p.returncode)+")")
    return p.stdout if capture else b""

def one_substitute(text, pattern, replacement, title, flags=0):
    found=list(re.finditer(pattern,text,flags))
    if len(found)!=1:
        fail("ESTRUTURA_DIVERGENTE: "+title+" ("+str(len(found))+" encontrados)")
    m=found[0]
    return text[:m.start()]+(replacement(m) if callable(replacement) else replacement)+text[m.end():]

def patch_server(source):
    if any(x in source for x in ("JRC_ACS_READONLY_OVERLAY_V1","acsCentralReadHandler",
                                 "registerAcsMonitorTools", "acsRequestContext")):
        fail("ESTRUTURA_DIVERGENTE: ACS ja esta presente; reconciliar, sem duplicar")
    for anchor in ("makeMcpHandler", "createMcpHandler", "registerOpaApiTools",
                   "opaApiAuthorized", "pabxAdminAuthorized", "financeMcpAuthorized",
                   "ixcWebserviceAuthorized", "pabxReadAuthorized", "SHIELD_MCP_ALLOWED_SUB",
                   "const mcpHandler = toNodeHandler(handler);"):
        if anchor not in source:
            fail("ESTRUTURA_DIVERGENTE: falta ancora "+anchor)

    pattern=r'(?m)^((?:export\s+)?(?:function\s+makeMcpHandler\s*\(|const\s+makeMcpHandler\s*=\s*\())([^)]*)(\)\s*(?:=>\s*)?\{)'
    found=list(re.finditer(pattern,source))
    if len(found)!=1:
        fail("ESTRUTURA_DIVERGENTE: assinatura makeMcpHandler")
    m=found[0]
    args=[a.strip() for a in m.group(2).split(",")]
    if len(args)!=6:
        fail("ESTRUTURA_DIVERGENTE: assinatura de makeMcpHandler tem "+str(len(args))+" argumentos, esperado 6")
    source=source[:m.start()]+m.group(1)+m.group(2)+", includeAcs = false"+m.group(3)+source[m.end():]

    # The factory must contain exactly one server return before its existing
    # generic handler. This does not change any of the old handler selections.
    mcp_handler_marker="const mcpHandler = toNodeHandler(handler);"
    factory_region=source.split(mcp_handler_marker,1)[0]
    if factory_region.count("return mcp;")!=1:
        fail("ESTRUTURA_DIVERGENTE: return mcp da factory")
    prefix,suffix=source.split(mcp_handler_marker,1)
    prefix=prefix.replace("return mcp;", """// JRC_ACS_READONLY_OVERLAY_V1: call-time role enforcement, no admin actions.
    if (includeAcs) registerAcsMonitorTools(mcp, () => acsRequestContext.getStore());
    return mcp;""",1)
    source=prefix+mcp_handler_marker+suffix

    # ACS overlay is selected ONLY if ALL existing permissions are positively
    # verified: the 6 booleans in makeMcpHandler correspond to these scopes.
    # Least-privilege, fail-closed: if the operator lacks any one, ACS stays
    # in the existing separate 3103 service. No new endpoint is exposed.
    source=one_substitute(
        source,
        r'(?m)^([ \t]*)const mcpHandler = toNodeHandler\(handler\);[ \t]*$',
        lambda m: m.group(0)+"\n"+m.group(1)+
        "const acsCentralReadHandler = toNodeHandler(makeMcpHandler(true, true, true, true, true, true, true));",
        "MCP generic handler"
    )

    payload_anchor=re.compile(
        r'(?m)^([ \t]*)const payload = await validarToken\(match\[1\]\);[ \t]*\n'
        r'(?=[ \t]*if\s*\(\s*process\.env\.SHIELD_MCP_ALLOWED_SUB)'
    )
    found=list(payload_anchor.finditer(source))
    if len(found)!=1:
        fail("ESTRUTURA_DIVERGENTE: bloco OAuth central nao encontrado unicamente")
    m=found[0]
    space=m.group(1)
    route=(
        space+"// JRC_ACS_READONLY_OVERLAY_V1: only all-scopes admins; strict per-request OAuth context.\n"
        +space+"if (acsMonitorAuthorized(payload) &&\n"
        +space+"    financeMcpAuthorized(payload) && opaApiAuthorized(payload) &&\n"
        +space+"    ixcWebserviceAuthorized(payload) && pabxReadAuthorized(payload) &&\n"\n        +space+"    pabxAdminAuthorized(payload) &&\n"
        +space+"    process.env.SHIELD_MCP_ALLOWED_SUB === payload.sub) {\n"
        +space+"  req.mcpUser = { sub: payload.sub, client: payload.azp };\n"
        +space+"  return acsRequestContext.run(payload, () => acsCentralReadHandler(req, res));\n"
        +space+"}\n"
    )
    source=source[:m.end()]+route+source[m.end():]

    imports=(
        "import { AsyncLocalStorage } from 'node:async_hooks';\n"
        "import { registerAcsMonitorTools, acsMonitorAuthorized } from './integrations/acs-monitor-central-tools.js';\n"
        "const acsRequestContext = new AsyncLocalStorage();\n"
    )
    # Insert only after the existing import block, not in the middle of a multiline import.
    lines=source.splitlines(keepends=True)
    indices=[i for i,l in enumerate(lines[:70]) if l.startswith("import ") and l.rstrip().endswith(";")]
    if not indices:
        fail("ESTRUTURA_DIVERGENTE: imports ESM")
    insert_at=indices[-1]+1
    lines.insert(insert_at,imports)
    source="".join(lines)
    if source.count("JRC_ACS_READONLY_OVERLAY_V1")!=2:
        fail("ESTRUTURA_DIVERGENTE: contador do overlay")
    return source

def health(port):
    try:
        call("curl","-fsS","--max-time","4",
             "http://127.0.0.1:"+str(port)+"/health", timeout=7)
        return True
    except Exception:
        return False

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument("action",choices=("plan","stage"))
    args=parser.parse_args()
    if os.geteuid()!=0:fail("Execute com sudo no ACS")
    if not health(3104) or not health(3100) or not health(3103):
        fail("HEALTH: portas 3100/3103/3104 devem responder antes de preparar")
    image_id=call("docker","inspect","-f","{{.Image}}",GREEN,capture=True).decode().strip()
    if not image_id.startswith("sha256:"):fail("Imagem base desconhecida")
    status=call("docker","inspect","-f","{{.State.Running}}",GREEN,capture=True).decode().strip()
    if status!="true": fail("MCP central nao esta running")
    # No full JS source ever printed in the terminal or sent to GitHub.
    with tempfile.TemporaryDirectory(prefix="jrc-acs-source-",dir="/root") as work:
        stage=Path(work)
        source=stage/"server.js"
        call("docker","cp",GREEN+":"+SERVER_PATH,str(source))
        original=source.read_text()
        candidate=patch_server(original)
        patch_file=stage/"server-candidate.js"
        patch_file.write_text(candidate)
        os.chmod(patch_file,0o600)
        call("docker","run","--rm","--network","none","--read-only",
             "--cap-drop","ALL","--entrypoint","node",
             "--mount","type=bind,src="+str(patch_file)+",dst=/tmp/server.js,readonly",
             image_id,"--check","/tmp/server.js")
        old_sha=hashlib.sha256(original.encode()).hexdigest()
        new_sha=hashlib.sha256(candidate.encode()).hexdigest()
        print("=== ACS MCPV2 CANDIDATE ===")
        print("SOURCE_HASH:",old_sha[:16],"PATCH_HASH:",new_sha[:16])
        print("PATCH: controles e imports adicionados; script original nao alterado")
        print("PROTECAO: todas as autorizacoes existentes obrigatorias; flag de monitoramento desativada por padrao")
        if args.action=="plan":
            print("PLANO OK: nenhuma imagem ou container modificado")
            return
        # Stage an image only, no new running service, Nginx or restart.
        module_file=stage/ACS_MODULE
        url=REPO_URL+"/"+MONITOR_REV+"/mcp/integrations/"+ACS_MODULE
        call("curl","-fsSL","--connect-timeout","10","--max-time","30",url,
             "-o",str(module_file))
        if "registerAcsMonitorTools" not in module_file.read_text():
            fail("Modulo ACS diferente do aprovado")
        stamp=time.strftime("%Y%m%dT%H%M%SZ",time.gmtime())
        base_tag="jrconect-mcp:acs-base-"+stamp
        tag="jrconect-mcp:acs-readonly-stage-"+stamp
        call("docker","image","tag",image_id,base_tag)
        df=stage/"Dockerfile"
        df.write_text(
            "FROM "+base_tag+"\n"
            "USER root\n"
            "RUN mkdir -p /host/proc && touch /host/proc/uptime /host/proc/loadavg /host/proc/meminfo\n"
            "COPY --chmod=0644 server-candidate.js /app/server.js\n"
            "COPY --chmod=0644 "+ACS_MODULE+" /app/integrations/"+ACS_MODULE+"\n"
            "USER node\n"
        )
        call("docker","build","--pull=false","--network","none","-f",
             str(df),"-t",tag,str(stage),timeout=120)
        call("docker","run","--rm","--network","none","--read-only",
             "--cap-drop","ALL","--entrypoint","node",tag,"--check",SERVER_PATH)
        if not health(3104) or not health(3100) or not health(3103):
            fail("HEALTH APOS STAGE: alguma porta falhou; imagem apenas criada, nao implantada")
        newlive=call("docker","inspect","-f","{{.Image}}",GREEN,capture=True).decode().strip()
        if newlive!=image_id:fail("Imagem do MCP ativo mudou durante staging")
        print("IMAGEM CANDIDATA:",tag)
        print("CONTAINERS ATIVOS: NENHUM ALTERADO")
        print("ADMINISTRACAO: DESABILITADA; nao ha agente local homologado")
        print("PENDENTE: container isolado, montagem /proc readonly, OAuth real e regressao de ferramentas")
        print("NAO EXECUTAR activate NEM alterar Nginx")

if __name__=="__main__":
    try:main()
    except Exception as exc:
        print("BLOQUEADO:",str(exc),file=sys.stderr)
        sys.exit(2)
