#!/usr/bin/env python3
"""Start ONLY a new ACS MCP V2 HML localhost:3106, cloning HML 3105 safely.

plan/status: read-only. start: create independent /data, and a NEW container;
no production restart, no Nginx edit, no Keycloak edit, no shared writable data,
no external port, no ACS admin; never remove an existing container.
"""
import argparse
import json
import os
from pathlib import Path
import re
import socket
import subprocess
import sys
import tempfile
import time

SOURCE="jrconect-mcp-acs-hml"  # V1 HML on port 3105 (NOT production)
OLD_IMAGE="jrconect-mcp:acs-readonly-stage-20261114T022110Z"
NEW="jrconect-mcp-acs-hml-v2"
IMAGE="jrconect-mcp:acs-per-handler-stage-20261114T031338Z"
HOST_PORT=3106
CONTAINER_PORT="3100/tcp"
ROOT=Path("/root/jrc-acs-hml-3106")
NETWORKS={"jrconect-mcp_default","jrconect-auth_auth-internal"}
HEALTH_PORTS=(3100,3103,3104,3105)

class Blocked(Exception):
    pass

def fail(msg):
    raise Blocked(msg)

def call(argv, *, stdout=False, timeout=25):
    try:
        cp=subprocess.run(argv,check=False,capture_output=True,text=True,
                          timeout=timeout)
    except (OSError,subprocess.TimeoutExpired):
        fail("COMANDO_FALHOU_OU_TIMEOUT")
    if cp.returncode:
        fail("COMANDO_FALHOU_"+argv[0]+"_"+str(cp.returncode))
    return cp.stdout.strip() if stdout else None

def inspect(name):
    return json.loads(call(["docker","inspect",name],stdout=True))[0]

def available_port():
    with socket.socket() as s:
        try:
            s.bind(("127.0.0.1",HOST_PORT))
            return True
        except OSError:
            return False

def new_exists():
    return subprocess.run(
        ["docker","container","inspect",NEW],
        stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,
        timeout=8,check=False
    ).returncode == 0

def health(port):
    if port not in HEALTH_PORTS+(HOST_PORT,):
        fail("PORTA_INVALIDA")
    try:
        call(["curl","-fsS","--max-time","4",
              "http://127.0.0.1:"+str(port)+"/health"],timeout=7)
        return True
    except Blocked:
        return False

def environment(info):
    return dict(item.split("=",1) for item in info["Config"].get("Env",[])
                if "=" in item)

def expected_mounts(info):
    secrets=[]
    proc=[]
    data=[]
    for m in info.get("Mounts",[]):
        dest=m["Destination"]
        if dest=="/data":
            if m["Type"]!="bind" or not m["RW"]:
                fail("ORIGEM_DATA_INVALIDA")
            data.append(m["Source"])
        elif dest.startswith("/run/secrets/"):
            if m["Type"]!="bind" or m["RW"] or not os.path.isfile(m["Source"]):
                fail("MONTAGEM_SEGREDO_INVALIDA")
            secrets.append((m["Source"],dest))
        elif dest.startswith("/host/proc/"):
            item=dest.split("/")[-1]
            if (item not in ("uptime","loadavg","meminfo")
                or m["Source"]!="/proc/"+item
                or m["Type"]!="bind" or m["RW"]):
                fail("MONTAGEM_HOST_PROC_INVALIDA")
            proc.append((m["Source"],dest))
        else:
            fail("MONTAGEM_DESCONHECIDA_NA_HML")
    if len(data)!=1 or len(secrets)<1 or len(proc)!=3:
        fail("MONTAGENS_DA_HML_INCOMPLETAS")
    if len(set(dest for _,dest in secrets+proc))!=len(secrets+proc):
        fail("MONTAGEM_REPETIDA")
    return data[0],secrets,proc

def prereqs():
    if new_exists():
        fail("HML_3106_JA_EXISTE_USE_STATUS")
    if not available_port():
        fail("PORTA_3106_OCUPADA")
    if ROOT.exists():
        fail("DIRETORIO_3106_EXISTE_REVISAR")
    for p in HEALTH_PORTS:
        if not health(p):
            fail("SERVICO_EXISTENTE_INDISPONIVEL_"+str(p))
    src=inspect(SOURCE)
    if not src["State"].get("Running"):
        fail("HML_3105_NAO_RUNNING")
    if src["Image"]!=call(["docker","image","inspect","-f","{{.Id}}",
                           OLD_IMAGE],stdout=True):
        fail("HML_3105_IMAGEM_DIVERGENTE")
    candidate=call(["docker","image","inspect","-f","{{.Id}}",IMAGE],stdout=True)
    if not candidate.startswith("sha256:") or candidate==src["Image"]:
        fail("IMAGEM_V2_NAO_CONFIRMADA")
    if set(src["NetworkSettings"]["Networks"])!=NETWORKS:
        fail("REDES_DA_HML_DIVERGENTES")
    if not src["HostConfig"].get("ReadonlyRootfs"):
        fail("HML_3105_ROOTFS_NAO_PROTEGIDO")
    if src["Config"].get("User") not in ("1000","1000:1000","node"):
        fail("USUARIO_3105_INESPERADO")
    published=src["HostConfig"].get("PortBindings",{}).get(CONTAINER_PORT,[])
    if len(published)!=1 or published[0].get("HostIp")!="127.0.0.1" or published[0].get("HostPort")!="3105":
        fail("PUBLICACAO_3105_INESPERADA")
    data_src,secrets,proc=expected_mounts(src)
    e=environment(src)
    if (e.get("ACS_MONITOR_MCP_ENABLED")!="true" or
        e.get("ACS_ADMIN_MCP_ENABLED")!="false" or
        e.get("OPA_API_ADMIN_ENABLED","false")=="true"):
        fail("FLAGS_ACS_HML_INESPERADAS")
    subjects=[x.strip() for x in e.get("ACS_MONITOR_ALLOWED_SUB","").split(",") if x.strip()]
    if len(subjects)!=1 or not re.fullmatch(r"[0-9a-fA-F-]{36}",subjects[0]):
        fail("ALLOWLIST_ACS_HML_INVALIDA")
    if subjects[0] not in [x.strip() for x in e.get("OPA_API_ALLOWED_SUB","").split(",")]:
        fail("OPA_ALLOWLIST_NAO_CORRESPONDE")
    if subjects[0]!=e.get("SHIELD_MCP_ALLOWED_SUB"):
        fail("SHIELD_ALLOWLIST_NAO_CORRESPONDE")
    if os.path.realpath(data_src)==os.path.realpath(str(ROOT/"data")):
        fail("DATA_V2_NAO_PODE_COMPARTILHAR_VOLUME")
    return {"image_id":candidate,"data_source":data_src,"secrets":secrets,
            "proc":proc,"env":e}

def create_state():
    ROOT.mkdir(mode=0o700)
    os.chmod(ROOT,0o700)
    dest=ROOT/"data"
    dest.mkdir(mode=0o700)
    os.chown(dest,1000,1000)
    file=dest/"connections.json"
    call(["docker","cp",SOURCE+":/data/connections.json",str(file)],timeout=15)
    if file.stat().st_size>2_000_000:
        fail("ESTADO_HML_ACIMA_DO_LIMITE")
    with file.open("r",encoding="utf8") as stream:
        json.load(stream)
    os.chown(file,1000,1000)
    os.chmod(file,0o600)
    return dest

def no_bearer_401():
    p=call(["curl","-sS","--max-time","5","-o","/dev/null",
            "-w","%{http_code}","-X","POST",
            "http://127.0.0.1:3106/mcp"],stdout=True,timeout=8)
    if p!="401":
        fail("AUTH_SEM_TOKEN_NAO_REJEITADA")

def start():
    cfg=prereqs()
    dest=create_state()
    fd,envpath=tempfile.mkstemp(prefix=".env-hml-",dir=str(ROOT))
    try:
        os.fchmod(fd,0o600)
        with os.fdopen(fd,"w",encoding="utf8") as f:
            for key,value in cfg["env"].items():
                if not key.isidentifier() or "\n" in value or "\r" in value:
                    fail("VARIAVEL_DE_AMBIENTE_INVALIDA")
                f.write(key+"="+value+"\n")
        argv=[
            "docker","run","-d","--name",NEW,"--restart","no",
            "--network","jrconect-mcp_default",
            "-p","127.0.0.1:3106:3100",
            "--read-only","--cap-drop","ALL",
            "--security-opt","no-new-privileges",
            "--memory","384m","--cpus","0.5","--pids-limit","64",
            "--user","1000:1000",
            "--tmpfs","/tmp:rw,nosuid,nodev,size=32m,mode=1777",
            "--mount","type=bind,src="+str(dest)+",dst=/data",
            "--env-file",envpath,
        ]
        for src,mount in cfg["secrets"]+cfg["proc"]:
            argv+=["--mount","type=bind,src="+src+",dst="+mount+",readonly"]
        argv.append(IMAGE)
        call(argv,timeout=35)
    finally:
        if os.path.exists(envpath):
            os.unlink(envpath)
    try:
        call(["docker","network","connect","jrconect-auth_auth-internal",NEW])
        created=inspect(NEW)
        if created["Image"]!=cfg["image_id"]:
            fail("IMAGEM_HML_V2_INCORRETA")
        if set(created["NetworkSettings"]["Networks"])!=NETWORKS:
            fail("REDES_HML_V2_DIVERGENTES")
        if not created["HostConfig"].get("ReadonlyRootfs"):
            fail("ROOTFS_V2_INSEGURO")
        if created["Config"].get("User")!="1000:1000":
            fail("USER_V2_INSEGURO")
        bind=created["HostConfig"].get("PortBindings",{}).get(CONTAINER_PORT,[])
        if len(bind)!=1 or bind[0].get("HostIp")!="127.0.0.1" or bind[0].get("HostPort")!="3106":
            fail("PORTA_V2_NAO_ISOLADA")
        data_mount=[m for m in created["Mounts"] if m["Destination"]=="/data"]
        if (len(data_mount)!=1 or
            data_mount[0]["Source"]!=str(dest) or
            data_mount[0]["Source"]==cfg["data_source"]):
            fail("VOLUME_V2_COMPARTILHADO")
        required={dst for _,dst in cfg["secrets"]+cfg["proc"]}
        actual={m["Destination"] for m in created["Mounts"] if not m["RW"]}
        if not required.issubset(actual):
            fail("SEGREDOS_OU_PROC_NAO_SAO_READONLY")
        flags=environment(created)
        if (flags.get("ACS_ADMIN_MCP_ENABLED")!="false" or
            flags.get("ACS_MONITOR_MCP_ENABLED")!="true"):
            fail("FLAGS_ACS_V2_INVALIDAS")
        for _ in range(15):
            if health(3106):
                break
            time.sleep(1)
        if not health(3106):
            fail("HML_3106_HEALTH_FALHOU")
        no_bearer_401()
        for p in HEALTH_PORTS:
            if not health(p):
                fail("SERVICO_ANTIGO_HEALTH_FALHOU_"+str(p))
    except Exception:
        # Only the new 3106 HML may be removed. Production and 3105 stay intact.
        subprocess.run(["docker","rm","-f",NEW],stdout=subprocess.DEVNULL,
                       stderr=subprocess.DEVNULL,check=False,timeout=20)
        raise
    print("=== ACS V2 HML 3106 ===")
    print("PORTA 3106: HTTP 200")
    print("SEM TOKEN: HTTP 401")
    print("DADOS: INDEPENDENTES DA 3105 E DA PRODUCAO")
    print("ACS ADMIN: DESABILITADA")
    print("SEGREDOS: SOMENTE LEITURA")
    print("PORTAS ANTIGAS 3100/3103/3104/3105: OK")
    print("NGINX E CONTAINERS ANTERIORES: SEM ALTERACAO")
    print("PENDENTE: LOGIN OAUTH REAL, LISTAGEM E TESTES DE TOOLS ACS")

def status():
    if not new_exists():
        print("HML 3106: NAO CRIADA")
        return
    item=inspect(NEW)
    print("=== ACS HML V2 ===")
    print("CONTAINER RUNNING:",bool(item["State"].get("Running")))
    print("HTTP 3106:",200 if health(3106) else "INDISPONIVEL")
    print("SEM TOKEN: testar com 'start' ou em validacao separada")
    print("PORTAS ANTIGAS:",{p:health(p) for p in HEALTH_PORTS})
    print("ROOTFS READONLY:",bool(item["HostConfig"].get("ReadonlyRootfs")))
    print("ACS ADMIN DESABILITADA:",environment(item).get("ACS_ADMIN_MCP_ENABLED")=="false")
    print("NENHUMA ALTERACAO")

def main():
    ap=argparse.ArgumentParser()
    ap.add_argument("action",choices=("plan","start","status"))
    args=ap.parse_args()
    if os.geteuid()!=0:
        fail("EXECUTE_COM_SUDO")
    if args.action=="status":
        status()
        return
    cfg=prereqs()
    print("=== PRECHECK V2 HML 3106 ===")
    print("IMAGEM CANDIDATA: VALIDADA, DIFERENTE DA 3105")
    print("PORTAS 3100/3103/3104/3105: OK; 3106 LIVRE")
    print("SECRET MOUNTS READONLY:",len(cfg["secrets"]))
    print("PROC HOST READONLY:",len(cfg["proc"]))
    print("FLAGS ACS: MONITOR TRUE; ADMIN FALSE")
    if args.action=="plan":
        print("PLANO OK - NENHUMA ALTERACAO")
        return
    start()

if __name__=="__main__":
    try:
        main()
    except Blocked as exc:
        print("BLOQUEADO:",str(exc),file=sys.stderr)
        sys.exit(2)
    except Exception:
        print("BLOQUEADO: ERRO_NAO_PREVISTO (detalhes protegidos)",file=sys.stderr)
        sys.exit(2)
