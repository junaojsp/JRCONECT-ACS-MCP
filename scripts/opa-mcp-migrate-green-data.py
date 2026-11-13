#!/usr/bin/env python3
"""Safely migrate only the already prepared GREEN MCP /data from tmpfs to bind.

plan: validations only, no changes.
apply: backup /data/connections.json, stop and recreate ONLY green on port 3104,
       with private persistent /data. Never touch MCP production, Nginx, or ports.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import time

LIVE="jrconect-mcp"
GREEN="jrconect-mcp-opa-ready"
PORT=3104
AUTH_NET="jrconect-auth_auth-internal"
BASE_NET="jrconect-mcp_default"
STATE_DIR=Path("/root/jrc-opa-bluegreen")
STATE_FILE=STATE_DIR/"state.json"
DATA_DIR=STATE_DIR/"persistent-data"
SNAPSHOT=STATE_DIR/"connections-before-green-migration.json"
NGINX=Path("/etc/nginx/sites-enabled/mcp.jrconect.com")

def stop(message):
    raise RuntimeError(message)

def cmd(*argv, capture=False, stdin=None):
    p=subprocess.run(argv,input=stdin,stdout=subprocess.PIPE if capture else subprocess.DEVNULL,
                     stderr=subprocess.DEVNULL,check=False)
    if p.returncode:
        stop("Falha no comando "+str(argv[0])+" (exit "+str(p.returncode)+")")
    return p.stdout if capture else b""

def inspect(name):
    return json.loads(cmd("docker","inspect",name,capture=True))[0]

def envvars(container):
    return dict(item.split("=",1) for item in container["Config"].get("Env",[]) if "=" in item)

def healthy(port):
    return subprocess.run(["curl","-fsS","--max-time","4",
          "http://127.0.0.1:"+str(port)+"/health"],stdout=subprocess.DEVNULL,
          stderr=subprocess.DEVNULL).returncode==0

def digest(data):
    return hashlib.sha256(data).hexdigest()

def load_state():
    if not STATE_FILE.is_file(): stop("Estado blue/green ausente")
    return json.loads(STATE_FILE.read_text())

def write_state(state):
    fd,temp=tempfile.mkstemp(dir=str(STATE_DIR),prefix=".state-")
    try:
        os.fchmod(fd,0o600)
        with os.fdopen(fd,"w") as f:
            json.dump(state,f,indent=2,sort_keys=True)
            f.flush()
            os.fsync(f.fileno())
        os.replace(temp,STATE_FILE)
    finally:
        if os.path.exists(temp): os.unlink(temp)

def nginx_is_live():
    text=NGINX.read_text()
    import re
    blocks=re.findall(r"location\s+=\s+/mcp\s*\{([^{}]+)\}",text,re.M|re.S)
    return len(blocks)==1 and "proxy_pass http://127.0.0.1:3100;" in blocks[0]

def validate():
    if os.geteuid()!=0: stop("Execute como root no ACS")
    state=load_state()
    if state.get("activated") or not nginx_is_live():
        stop("Nginx /mcp nao esta na producao 3100; migracao nao permitida")
    live=inspect(LIVE)
    green=inspect(GREEN)
    if not live["State"]["Running"] or not healthy(3100):
        stop("MCP producao indisponivel")
    if not green["State"]["Running"] or not healthy(PORT):
        stop("Candidata green indisponivel")
    if state.get("production_image")!=live["Image"]:
        stop("Imagem MCP principal mudou")
    if state.get("green_id")!=green["Id"] or state.get("candidate_image")!=green["Image"]:
        stop("Container green mudou desde prepare")
    networks=set(green["NetworkSettings"]["Networks"].keys())
    if networks!={BASE_NET,AUTH_NET}:
        stop("Redes candidatas divergentes")
    if str(green["HostConfig"].get("ReadonlyRootfs")).lower()!="true":
        stop("Rootfs da candidata nao e read-only")
    mounts={m["Destination"]:m for m in green.get("Mounts",[])}
    data=mounts.get("/data")
    # Docker may expose tmpfs only in HostConfig.Tmpfs, not in Mounts.
    tmpfs_paths=green.get("HostConfig",{}).get("Tmpfs") or {}
    if not (data and data.get("Type")=="tmpfs") and "/data" not in tmpfs_paths:
        stop("Green nao usa /data tmpfs; nao recriar")
    if data and data.get("Type")=="bind":
        stop("Green ja usa bind /data; nao substituir")
    if green.get("HostConfig",{}).get("NetworkMode")!=BASE_NET:
        stop("NetworkMode da candidata divergente")
    port_binding=green["HostConfig"]["PortBindings"].get("3100/tcp",[])
    if len(port_binding)!=1 or port_binding[0].get("HostIp")!="127.0.0.1" or port_binding[0].get("HostPort")!="3104":
        stop("Port binding 3104 divergente")
    for dest,m in mounts.items():
        if dest=="/data": continue
        if not dest.startswith("/run/secrets/") or m["Type"]!="bind" or m["RW"]:
            stop("Montagem inesperada ou gravavel: "+dest)
    if not mounts.get("/run/secrets/opa-api-token"):
        stop("Token API Opa nao montado")
    if state.get("persistent_data"):
        stop("Estado ja indica persistencia; nao repetir")
    if DATA_DIR.exists() or SNAPSHOT.exists():
        stop("Caminho de persistencia/backup ja existe. Verificar sem sobrescrever")
    return state,live,green,mounts

def read_green():
    # Never print the contents (may contain client metadata).
    raw=cmd("docker","exec","-u","node",GREEN,"cat","/data/connections.json",capture=True)
    if not raw or len(raw)>1024*1024:
        stop("connections.json ausente ou tamanho fora do limite")
    try: json.loads(raw)
    except ValueError: stop("connections.json nao e JSON valido")
    return raw

def run_green(green,mounts):
    env=envvars(green)
    env_path=None
    try:
        fd,env_path=tempfile.mkstemp(dir=str(STATE_DIR),prefix=".env-green-")
        os.fchmod(fd,0o600)
        with os.fdopen(fd,"w") as f:
            for key,value in env.items():
                if not key or "\n" in key or "=" in key or "\n" in value:
                    stop("Variavel de ambiente invalida")
                f.write(key+"="+value+"\n")
        args=["docker","run","-d","--name",GREEN,"--restart","no",
              "--network",BASE_NET,"-p","127.0.0.1:3104:3100",
              "--read-only","--cap-drop","ALL","--security-opt","no-new-privileges",
              "--memory","384m","--cpus","0.5","--pids-limit","64",
              "--tmpfs","/tmp:rw,nosuid,nodev,size=32m,mode=1777",
              "--mount","type=bind,src="+str(DATA_DIR)+",dst=/data",
              "--env-file",env_path]
        for dest,m in mounts.items():
            if dest=="/data": continue
            args += ["--mount","type=bind,src="+m["Source"]+",dst="+dest+",readonly"]
        args.append(green["Image"])
        cmd(*args)
        cmd("docker","network","connect",AUTH_NET,GREEN)
    finally:
        if env_path and os.path.exists(env_path): os.unlink(env_path)

def wait_health():
    for _ in range(25):
        if healthy(PORT): return
        time.sleep(1)
    stop("Novo green nao respondeu health em 3104")

def apply():
    if os.environ.get("JRC_APPROVE_GREEN_MIGRATION")!="YES":
        stop("Para migrar, use JRC_APPROVE_GREEN_MIGRATION=YES")
    state,live,green,mounts=validate()
    raw=read_green()
    STATE_DIR.mkdir(mode=0o700,parents=True,exist_ok=True)
    # Copy first, then recreate GREEN. Only root can read snapshots.
    with open(SNAPSHOT,"xb") as f:
        os.fchmod(f.fileno(),0o600)
        f.write(raw)
        f.flush()
        os.fsync(f.fileno())
    DATA_DIR.mkdir(mode=0o700)
    os.chown(DATA_DIR,1000,1000)
    with open(DATA_DIR/"connections.json","xb") as f:
        os.fchmod(f.fileno(),0o600)
        f.write(raw)
        f.flush()
        os.fsync(f.fileno())
    os.chown(DATA_DIR/"connections.json",1000,1000)
    before_env=envvars(green)
    before_id=green["Id"]
    backup_name=GREEN+"-tmpfs-backup-"+time.strftime("%Y%m%dT%H%M%SZ",time.gmtime())
    print("BACKUP JSON: valido; copia protegida e preparada no volume independente")
    print("Recriando SOMENTE a instancia de homologacao na porta 3104...")
    cmd("docker","stop","--time","10",GREEN)
    cmd("docker","rename",GREEN,backup_name)
    try:
        run_green(green,mounts)
        wait_health()
        replacement=inspect(GREEN)
        if set(replacement["NetworkSettings"]["Networks"])!={BASE_NET,AUTH_NET}:
            stop("Redes do novo green divergentes")
        if envvars(replacement)!=before_env:
            stop("Variaveis do novo green divergentes")
        if replacement["Image"]!=green["Image"]:
            stop("Imagem candidata mudou")
        newmount={m["Destination"]:m for m in replacement["Mounts"]}
        if newmount.get("/data",{}).get("Source")!=str(DATA_DIR) or not newmount["/data"]["RW"]:
            stop("Montagem persistente /data nao confirmada")
        for dest,m in mounts.items():
            if dest=="/data": continue
            n=newmount.get(dest)
            if not n or n["RW"] or n["Source"]!=m["Source"]:
                stop("Montagem de token alterada")
        actual=read_green()
        if digest(actual)!=digest(raw):
            stop("Arquivo de conexoes diverge da copia")
        if not healthy(3100) or inspect(LIVE)["Id"]!=live["Id"]:
            stop("MCP principal mudou ou health falhou")
        state["green_id"]=replacement["Id"]
        state["persistent_data"]=str(DATA_DIR)
        state["previous_green_id"]=before_id
        state["previous_green_name"]=backup_name
        state["connections_sha256"]=digest(raw)
        write_state(state)
        print("MIGRACAO OK: somente a homologacao green foi recriada")
        print("DATA PERSISTENTE: /root/jrc-opa-bluegreen/persistent-data")
        print("MCP PRODUCAO 3100: PRESERVADO; NGINX: SEM ALTERACOES")
        print("PENDENTE: validar OAuth real e todas as ferramentas antes de activate")
    except Exception:
        print("ALERTA: recriacao green falhou; recuperando container de teste anterior",file=sys.stderr)
        try:
            try:
                inspect(GREEN)
                cmd("docker","stop","--time","5",GREEN)
                cmd("docker","rename",GREEN,GREEN+"-failed-"+time.strftime("%Y%m%dT%H%M%SZ",time.gmtime()))
            except RuntimeError:
                pass
            cmd("docker","rename",backup_name,GREEN)
            cmd("docker","start",GREEN)
            for _ in range(20):
                if healthy(PORT): break
                time.sleep(1)
            print("HOMOLOGACAO RESTAURADA:",healthy(PORT),file=sys.stderr)
            print("Producao 3100 nao foi alterada.",file=sys.stderr)
        except Exception:
            print("Nao foi possivel recuperar homologacao automaticamente. Producao intacta.",file=sys.stderr)
        raise

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument("action",choices=("plan","apply"))
    args=parser.parse_args()
    if args.action=="plan":
        validate()
        raw=read_green()
        print("PLANO OK: green 3104 pode ser migrado; JSON valido; tamanho:",len(raw),"bytes")
        print("PRODUCAO 3100 E NGINX INTACTOS; nenhuma modificacao realizada")
    else:
        apply()

if __name__=="__main__":
    try:main()
    except Exception as exc:
        print("BLOQUEADO:",exc,file=sys.stderr)
        sys.exit(2)
