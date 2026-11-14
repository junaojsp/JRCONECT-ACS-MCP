#!/usr/bin/env python3
"""ACS MCPv2 isolated HML on 127.0.0.1:3105; no production routing changes.

Commands: plan (read-only preflight), start (create only new HML container),
status (read-only). Does NOT edit Nginx, production images, secrets or data.
Does NOT expose admin tools, a shell, or a Docker socket to the candidate.
"""
import argparse
import json
import os
from pathlib import Path
import socket
import subprocess
import sys
import tempfile
import time

LIVE="jrconect-mcp-opa-ready"        # currently serving Nginx /mcp on 3104
LEGACY="jrconect-mcp"               # original on 3100, preserved
MONITOR="jrconect-acs-monitor-teste" # old dedicated ACS MCP on 3103
HML="jrconect-mcp-acs-hml"           # NEW container, only ever used here
IMAGE="jrconect-mcp:acs-readonly-stage-20261114T022110Z"
IMAGE_PORT="3100/tcp"
HML_PORT=3105
ROOT=Path("/root/jrc-acs-hml-3105")
EXPECTED_NETWORKS={"jrconect-mcp_default","jrconect-auth_auth-internal"}

def blocked(msg):
    raise RuntimeError(msg)

def run(args, *, output=False, input_data=None, timeout=20):
    p=subprocess.run(args, input=input_data, capture_output=True,
                     text=True, timeout=timeout, check=False)
    if p.returncode:
        blocked("Falha " + str(args[0]) + " (codigo " + str(p.returncode) + ")")
    return p.stdout.strip() if output else None

def inspect(name):
    return json.loads(run(["docker","inspect",name],output=True))[0]

def health(port):
    if port not in (3100,3103,3104,3105):
        blocked("Porta nao permitida")
    try:
        run(["curl","-fsS","--max-time","4",
             "http://127.0.0.1:"+str(port)+"/health"],timeout=6)
        return True
    except Exception:
        return False

def hml_exists():
    p=subprocess.run(["docker","container","inspect",HML],stdout=subprocess.DEVNULL,
                     stderr=subprocess.DEVNULL,check=False,timeout=8)
    return p.returncode==0

def free_port():
    with socket.socket() as s:
        try:
            s.bind(("127.0.0.1",HML_PORT))
            return True
        except OSError:
            return False

def envmap(info):
    return dict(s.split("=",1) for s in info["Config"].get("Env",[]) if "=" in s)

def exact_sub(env):
    # Follow the EXISTING all-scope route from the stage-ACS overlay.
    # Both allowlists must contain the SAME SINGLE Keycloak UUID; fail closed.
    opa={x.strip() for x in env.get("OPA_API_ALLOWED_SUB","").split(",") if x.strip()}
    shield=env.get("SHIELD_MCP_ALLOWED_SUB","").strip()
    if len(opa)!=1 or shield not in opa or not shield:
        blocked("Allowlist Opa/SHIELD nao corresponde a um unico usuario; revisar antes de prosseguir")
    if len(shield)!=36 or shield.count("-")!=4:
        blocked("Allowlist nao parece um subject UUID")
    return shield

def conditions():
    for p in (3100,3103,3104):
        if not health(p):
            blocked("Servico da porta "+str(p)+" nao esta healthy; nao iniciar outra instancia")
    if hml_exists():
        blocked("Container de homologacao ja existe; use status, sem recriar")
    if not free_port():
        blocked("Porta 3105 ocupada; nao criar outro container")
    live=inspect(LIVE)
    if not live["State"].get("Running") or not live["HostConfig"].get("ReadonlyRootfs"):
        blocked("MCPv2 atual nao corresponde a instancia protegida")
    if set(live["NetworkSettings"]["Networks"])!=EXPECTED_NETWORKS:
        blocked("Redes do MCPv2 mudaram; revisar antes de iniciar")
    image=run(["docker","image","inspect","-f","{{.Id}}",IMAGE],output=True)
    if not image.startswith("sha256:"):
        blocked("Imagem candidata nao encontrada")
    if len([m for m in live["Mounts"] if m["Destination"]=="/data"])!=1:
        blocked("Dados persistentes atuais inesperados")
    data=[m for m in live["Mounts"] if m["Destination"]=="/data"][0]
    if data["Type"]!="bind" or not data["RW"]:
        blocked("Tipo de /data original inesperado")
    mount_secrets=[]
    for m in live["Mounts"]:
        dest=m["Destination"]
        if dest=="/data":
            continue
        if not dest.startswith("/run/secrets/") or m["Type"]!="bind" or m["RW"]:
            blocked("Montagem desconhecida ou gravavel: "+dest)
        if not os.path.isfile(m["Source"]):
            blocked("Segredo montado nao existe (conteudo nunca exibido)")
        mount_secrets.append((m["Source"],dest))
    env=envmap(live)
    if env.get("OPA_API_MCP_ENABLED")!="true" or env.get("OPA_API_ADMIN_ENABLED","false").lower()=="true":
        blocked("Estado do Opa difere do homologado")
    subject=exact_sub(env)
    # No ACS admin or remote Docker privileges, only two read-only tools.
    env["ACS_MONITOR_MCP_ENABLED"]="true"
    env["ACS_MONITOR_ALLOWED_SUB"]=subject
    env["ACS_ADMIN_MCP_ENABLED"]="false"
    # Don't modify any existing environment values except previously absent ACS keys.
    if "ACS_MONITOR_MCP_ENABLED" in envmap(live) or "ACS_ADMIN_MCP_ENABLED" in envmap(live):
        blocked("ACS ja habilitado na instancia ativa; auditar divergencia")
    for path in ("/proc/uptime","/proc/loadavg","/proc/meminfo"):
        if not Path(path).is_file():
            blocked("Arquivo de metricas ausente: "+path)
    return {"image":image,"live":live,"env":env,"secrets":mount_secrets,
            "data_source":data["Source"]}

def prepare_private_state():
    # Isolate ALL new writes and a snapshot of connections.json; no shared rw data.
    if ROOT.exists():
        blocked("Diretorio HML ja existe; revisar antes de recriar")
    ROOT.mkdir(mode=0o700,parents=False)
    os.chmod(ROOT,0o700)
    data=ROOT/"data"
    data.mkdir(mode=0o700)
    # The user in the current node image is UID:GID 1000:1000.
    os.chown(data,1000,1000)
    snapshot=data/"connections.json"
    run(["docker","cp",LIVE+":/data/connections.json",str(snapshot)],timeout=15)
    os.chown(snapshot,1000,1000)
    os.chmod(snapshot,0o600)
    return data

def start():
    d=conditions()
    data=prepare_private_state()
    # Keep secrets ONLY in a root-private ephemeral env-file removed after docker run.
    fd,path=tempfile.mkstemp(prefix=".acs-env-",dir=str(ROOT))
    try:
        os.fchmod(fd,0o600)
        with os.fdopen(fd,"w") as f:
            for key,value in d["env"].items():
                if not key.isidentifier() or "\n" in value or "\r" in value:
                    blocked("Variavel de ambiente insegura")
                f.write(key+"="+value+"\n")
        argv=[
            "docker","run","-d","--name",HML,"--restart","no",
            "--network","jrconect-mcp_default",
            "-p","127.0.0.1:3105:3100",
            "--read-only","--cap-drop","ALL",
            "--security-opt","no-new-privileges",
            "--memory","384m","--cpus","0.5","--pids-limit","64",
            "--user","1000:1000",
            "--tmpfs","/tmp:rw,nosuid,nodev,size=32m,mode=1777",
            "--mount","type=bind,src="+str(data)+",dst=/data",
            "--env-file",path,
        ]
        for src,dst in d["secrets"]:
            argv+=["--mount","type=bind,src="+src+",dst="+dst+",readonly"]
        for item in ("uptime","loadavg","meminfo"):
            argv+=["--mount","type=bind,src=/proc/"+item+
                   ",dst=/host/proc/"+item+",readonly"]
        argv.append(IMAGE)
        run(argv,timeout=30)
    finally:
        if os.path.exists(path):
            os.unlink(path)
    try:
        run(["docker","network","connect","jrconect-auth_auth-internal",HML])
        current=inspect(HML)
        if current["Image"]!=d["image"]:
            blocked("Imagem HML nao corresponde ao stage aprovado")
        if set(current["NetworkSettings"]["Networks"])!=EXPECTED_NETWORKS:
            blocked("Redes HML incorretas")
        if any(m["Destination"]=="/data" and m["Source"]==d["data_source"]
               for m in current["Mounts"]):
            blocked("/data compartilhado com producao")
        expected={x[1] for x in d["secrets"]}
        present={m["Destination"] for m in current["Mounts"] if not m["RW"]}
        if not expected.issubset(present):
            blocked("Montagens readonly do MCP atual nao foram preservadas")
        for _ in range(15):
            if health(3105):break
            time.sleep(1)
        if not health(3105):
            blocked("Porta 3105 nao respondeu /health")
        if any(not health(p) for p in (3100,3103,3104)):
            blocked("Um servico preexistente ficou indisponivel; inspecionar imediatamente")
    except Exception:
        # Only new HML can be removed; NEVER touch any existing container.
        subprocess.run(["docker","rm","-f",HML],stdout=subprocess.DEVNULL,
                       stderr=subprocess.DEVNULL,check=False,timeout=20)
        raise
    print("=== ACS HOMOLOGACAO ISOLADA INICIADA ===")
    print("PORTA 3105: HTTP 200")
    print("NETWORKS: redes originais preservadas na HML")
    print("SEGREDOS: montados somente leitura; valores NAO exibidos")
    print("DADOS: diretorio HML privado separado de /data atual")
    print("ADMINISTRACAO: DESABILITADA")
    print("NGINX E MCP 3104: NAO ALTERADOS")
    print("PENDENTE: OAuth real, catalogo tools/list e regressao de ferramentas")

def status():
    if not hml_exists():
        print("HML: nao iniciada")
        return
    hml=inspect(HML)
    m=envmap(hml)
    print("=== ACS HML 3105 ===")
    print("Container em execucao:",bool(hml["State"]["Running"]))
    print("HTTP 3105:",200 if health(3105) else "INDISPONIVEL")
    print("ACS leitura habilitada:",m.get("ACS_MONITOR_MCP_ENABLED")=="true")
    print("ACS admin desabilitada:",m.get("ACS_ADMIN_MCP_ENABLED")=="false")
    print("Porta publicada:",hml["HostConfig"]["PortBindings"].get(IMAGE_PORT))
    print("Redes:",sorted(hml["NetworkSettings"]["Networks"]))
    print("HTTP 3104:",200 if health(3104) else "INDISPONIVEL")
    print("Sem alteracao de Nginx, Docker existente ou plugin ChatGPT.")

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument("action",choices=("plan","start","status"))
    args=parser.parse_args()
    if os.geteuid()!=0:blocked("Execute com sudo no ACS")
    if args.action=="status":
        status()
        return
    d=conditions()
    if args.action=="plan":
        print("=== HML ACS 3105 ===")
        print("PLANO OK: candidata e MCP atual saudaveis; 3105 livre")
        print("MONTAGENS RO A PRESERVAR:",len(d["secrets"]))
        print("ALLOWED_SUB: compativel com Opa e SHIELD (identificador oculto)")
        print("ACESSO: ACS leitura permitida apenas apos role Keycloak validada")
        print("ADMIN: desabilitada")
        print("NENHUMA ALTERACAO")
        return
    start()

if __name__=="__main__":
    try:main()
    except Exception as e:
        print("BLOQUEADO:",str(e),file=sys.stderr)
        sys.exit(2)
