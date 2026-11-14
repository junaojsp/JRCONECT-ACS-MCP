#!/usr/bin/env python3
"""Read-only structural audit of the LIVE JR Conect MCPv2 and ACS monitor.

Only outputs code markers/line numbers and Docker configuration metadata.
Never outputs JavaScript source, JWTs, tokens, env values, user IDs, customers,
container logs, /data contents or secret source paths.
NO changes to Docker, Nginx, Keycloak, files, networks, or service settings.

Purpose: safely plan the future ACS read + guarded-admin integration using
the actual running image, not the historical mcp/server.js in the repo.
"""
import json
import os
import pathlib
import subprocess
import sys

PRIMARY = "jrconect-mcp-opa-ready"
ACS_MON = "jrconect-acs-monitor-teste"
ROLLBACK = "jrconect-mcp"
ALLOWED = {PRIMARY, ACS_MON, ROLLBACK}

NODE_AUDIT = r'''
"use strict";
const fs=require("node:fs");
const modules={
  "server.js":"/app/server.js",
  "auth.js":"/app/auth.js",
  "opa-api-auth.js":"/app/integrations/opa-api-auth.js",
  "acs-monitor-central-tools.js":"/app/integrations/acs-monitor-central-tools.js",
  "acs-admin-guarded-tools.js":"/app/integrations/acs-admin-guarded-tools.js"
};
const patterns={
  "mcp_handler":/\bcreateMcpHandler\b/g,
  "mcp_tool_registration":/\bregisterTool\s*\(/g,
  "oauth_token_validator":/\bvalidarToken\s*\(/g,
  "opa_permission_gate":/\bopaApiAuthorized\b/g,
  "opa_tool_registration":/\bregisterOpaApiTools\b/g,
  "request_identity":/\breq\s*\.\s*mcpUser\b/g,
  "async_local_storage":/\bAsyncLocalStorage\b/g,
  "identity_context_accessor":/\bgetStore\s*\(/g,
  "jwt_roles":/\brealm_access\b/g,
  "jwt_payload_reference":/\bpayload\b/g,
  "token_client_azp":/\bazp\b/g,
  "mcp_node_handler":/\btoNodeHandler\b/g,
  "server_listen":/\bcreateServer\b/g
};
function linesFor(text,re) {
 const lines=[];
 for(const match of text.matchAll(re))
   lines.push(1+text.slice(0,match.index).split("\n").length-1);
 return lines.slice(0,35);
}
const files={};
for(const [name,path] of Object.entries(modules)) {
 if(!fs.existsSync(path)){
   files[name]={present:false};
   continue;
 }
 const stat=fs.statSync(path);
 if(!stat.isFile()||stat.size>1000000) {
   files[name]={present:true,too_large:true};
   continue;
 }
 const code=fs.readFileSync(path,"utf8");
 const markers={};
 for(const [name,regex] of Object.entries(patterns)){
   const lines=linesFor(code,regex);
   if(lines.length) markers[name]=lines;
 }
 files[name]={present:true,lines:code.split("\n").length,markers};
}
const toolNames=new Set();
const app="/app";
const locations=[app+"/server.js",app+"/integrations/acs-monitor-central-tools.js",app+"/integrations/acs-admin-guarded-tools.js"];
for(const location of locations) {
 if(!fs.existsSync(location))continue;
 const code=fs.readFileSync(location,"utf8");
 for(const re of [/\bregisterTool\s*\(\s*["']([a-zA-Z0-9_-]{1,96})["']/g,
                  /\btool\s*\(\s*["']([a-zA-Z0-9_-]{1,96})["']/g]) {
   for(const m of code.matchAll(re))toolNames.add(m[1]);
 }
}
const flags=["ACS_ADMIN_MCP_ENABLED","ACS_MONITOR_MCP_ENABLED","OPA_API_MCP_ENABLED"];
const envFlagNames=Object.fromEntries(flags.map(k=>[k,Object.hasOwn(process.env,k)]));
console.log(JSON.stringify({files,staticToolNames:[...toolNames].sort(),flagNamesPresent:envFlagNames}));
'''.strip()


def command(argv, *, input_data=None):
    proc = subprocess.run(
        argv, input=input_data, capture_output=True, text=True,
        check=False, timeout=15
    )
    if proc.returncode:
        raise RuntimeError("Falha somente na consulta: " + argv[0] +
                           " (codigo " + str(proc.returncode) + ")")
    return proc.stdout.strip()


def container_info(name):
    if name not in ALLOWED:
        raise RuntimeError("Container nao permitido")
    raw = command(["docker", "inspect", name])
    info = json.loads(raw)[0]
    return {
        "container": name,
        "running": bool(info["State"]["Running"]),
        "image_id_prefix": str(info["Image"])[:19],
        "user": info["Config"].get("User") or "default",
        "read_only_rootfs": bool(info["HostConfig"].get("ReadonlyRootfs")),
        "network_names": sorted(info["NetworkSettings"]["Networks"].keys()),
        "mounts": [
            {"destination": m["Destination"], "kind": m["Type"],
             "read_only": not m["RW"]}
            for m in info.get("Mounts", [])
        ],
        "tmpfs_destinations": sorted((info["HostConfig"].get("Tmpfs") or {}).keys()),
        "host_port_bindings": {
            port: [
                {"bind": p.get("HostIp"), "port": p.get("HostPort")}
                for p in bindings
            ]
            for port, bindings in info["HostConfig"].get("PortBindings", {}).items()
            if bindings
        },
    }


def health(port):
    if port not in (3100, 3103, 3104):
        raise RuntimeError("Porta nao permitida")
    try:
        command(["curl", "-fsS", "--max-time", "4",
                 "http://127.0.0.1:" + str(port) + "/health"])
        return "HTTP_OK"
    except Exception:
        return "INDISPONIVEL"


def main():
    if os.geteuid() != 0:
        raise RuntimeError("Execute com sudo no ACS")
    report = {
        "scope": "consulta estrutural read-only; sem capturar tokens ou codigo fonte",
        "container_config": [container_info(n) for n in
                             (PRIMARY, ACS_MON, ROLLBACK)],
        "health": {str(p): health(p) for p in (3100, 3103, 3104)},
        "mcpv2_code_structure": json.loads(command(
            ["docker", "exec", "-i", PRIMARY, "node", "-"],
            input_data=NODE_AUDIT + "\n",
        )),
    }
    # No report file created: do not persist sensitive metadata unnecessarily.
    print("=== ACS MCPV2 RUNTIME AUDIT (SOMENTE LEITURA) ===")
    print(json.dumps(report, indent=2, ensure_ascii=False))
    print("=== FIM: NENHUMA ALTERACAO ===")


if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print("DIAGNOSTICO_BLOQUEADO: " + str(exc), file=sys.stderr)
        sys.exit(2)
