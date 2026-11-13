#!/usr/bin/env python3
"""Blue/green deployment of the ALREADY homologated Opa MCP candidate.

prepare: create a second MCP on loopback port 3102; do not touch production.
activate: change only Nginx exact /mcp route, nginx -t and graceful reload.
rollback: restore exact Nginx backup; original MCP on 3100 never stops.

Requires root on ACS, Python 3, docker and nginx. No access tokens printed.
"""
import argparse
import hashlib
import json
import os
import pathlib
import re
import shutil
import socket
import subprocess
import sys
import tempfile
import time

ROOT=pathlib.Path("/opt/jrconect-mcp")
SERVICE="jrconect-mcp"
GREEN="jrconect-mcp-opa-ready"
IMAGE="jrconect-mcp:opa-candidate-20261113T195425Z"
PORT=3102
STATE_DIR=pathlib.Path("/root/jrc-opa-bluegreen")
STATE=STATE_DIR/"state.json"
BACKUP=STATE_DIR/"nginx-mcp-pre-switch.conf"
NGINX=pathlib.Path("/etc/nginx/sites-enabled/mcp.jrconect.com")
OPA_TOKEN=ROOT/"secrets/opa/api-token"
REQ_SUB="b33b099a-c5c8-499b-bc0d-4cd9d4d9a315"
NETWORKS={"jrconect-mcp_default", "jrconect-auth_auth-internal"}

def fail(message):
    raise RuntimeError(message)

def call(*command, capture=False):
    result=subprocess.run(command, text=True, stdout=subprocess.PIPE if capture else subprocess.DEVNULL,
                          stderr=subprocess.PIPE, check=False)
    if result.returncode:
        fail("falha no comando "+str(command[0])+" (codigo "+str(result.returncode)+")")
    return result.stdout.strip() if capture else ""

def inspect(name):
    return json.loads(call("docker","inspect",name,capture=True))[0]

def varmap(item):
    entries=item["Config"].get("Env",[])
    return dict(entry.split("=",1) for entry in entries if "=" in entry)

def sha(raw):
    return hashlib.sha256(raw).hexdigest()

def health(port):
    try:
        result=subprocess.run(["curl","-fsS","--max-time","4",
                               "http://127.0.0.1:"+str(port)+"/health"],
                              stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        return result.returncode == 0
    except OSError:
        return False

def ensure_live():
    live=inspect(SERVICE)
    if not live["State"]["Running"] or not health(3100):
        fail("MCP original nao esta running/health=200; nenhum passo aplicado")
    return live

def config_path():
    path=pathlib.Path(os.path.realpath(NGINX))
    if not path.is_file():
        fail("arquivo efetivo do Nginx nao encontrado")
    return path

def nginx_block(source):
    # Extract only the *exact* MCP location, not finance/admin/ACS.
    starts=list(re.finditer(r"(?m)^[ \t]*location[ \t]+=[ \t]+/mcp[ \t]*\{",source))
    if len(starts)!=1:
        fail("location exata /mcp nao encontrada unicamente no Nginx")
    start=starts[0].start()
    pos=starts[0].end()
    depth=1
    while depth and pos<len(source):
        if source[pos]=="{": depth+=1
        if source[pos]=="}": depth-=1
        pos+=1
    if depth:
        fail("bloco Nginx /mcp com chaves invalidas")
    return start,pos,source[start:pos]

def upstream(source):
    _,_,block=nginx_block(source)
    found=re.findall(r"(?m)^[ \t]*proxy_pass[ \t]+http://127[.]0[.]0[.]1:(\d+);",block)
    if len(found)!=1:
        fail("proxy_pass /mcp nao unico ou diferente do modelo homologado")
    return int(found[0])

def file_write_atomic(path,raw):
    fd,name=tempfile.mkstemp(prefix=".jrc-opa-nginx-",dir=str(path.parent))
    try:
        with os.fdopen(fd,"wb") as stream:
            stream.write(raw)
            stream.flush()
            os.fsync(stream.fileno())
        st=path.stat()
        os.chown(name,st.st_uid,st.st_gid)
        os.chmod(name,st.st_mode & 0o777)
        os.replace(name,path)
    finally:
        if os.path.exists(name): os.unlink(name)

def store_state(data):
    STATE_DIR.mkdir(parents=True,exist_ok=True,mode=0o700)
    os.chmod(STATE_DIR,0o700)
    fd,tmp=tempfile.mkstemp(dir=str(STATE_DIR),prefix=".state-")
    try:
        os.fchmod(fd,0o600)
        with os.fdopen(fd,"w") as stream:
            json.dump(data,stream,indent=2,sort_keys=True)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(tmp,STATE)
    finally:
        if os.path.exists(tmp): os.unlink(tmp)

def load_state():
    if not STATE.is_file(): fail("sem estado preparado; execute prepare")
    return json.loads(STATE.read_text())

def api_config():
    command=["docker","compose","-f",str(ROOT/"docker-compose.yml"),
             "-f",str(ROOT/"docker-compose.ixc.yml"),
             "-f",str(ROOT/"docker-compose.opa.yml"),
             "config","--format","json"]
    svc=json.loads(call(*command,capture=True))["services"]["jrconect-mcp"]
    values=svc.get("environment",{})
    if isinstance(values,list):
        values=dict(entry.split("=",1) for entry in values if "=" in entry)
    return values

def prepare():
    if STATE.exists():
        fail("preparacao anterior ja existe; nao criar outra instancia automaticamente")
    live=ensure_live()
    if not OPA_TOKEN.is_file() or OPA_TOKEN.stat().st_size==0:
        fail("token Opa no host ausente ou vazio")
    if os.geteuid()!=0: fail("requer root")
    nginx_file=config_path()
    original=nginx_file.read_bytes()
    if upstream(original.decode())!=3100:
        fail("Nginx /mcp ja nao esta na producao 3100")
    try:
        inspect(GREEN)
        fail("container de destino ja existe; parar para evitar sobrescrita")
    except RuntimeError as e:
        if "falha no comando docker" not in str(e): raise
    call("docker","image","inspect",IMAGE)
    candidate=call("docker","image","inspect","-f","{{.Id}}",IMAGE,capture=True)
    with socket.socket() as s:
        try: s.bind(("127.0.0.1",PORT))
        except OSError: fail("porta 3102 ocupada")
    current=varmap(live)
    hml=varmap(inspect("jrconect-mcp-opa-hml"))
    compose=api_config()
    for key in ("OPA_API_BASE_URL","OPA_API_MCP_ENABLED","OPA_API_ALLOWED_SUB","OPA_API_ADMIN_ENABLED"):
        if key not in compose:
            fail("Compose Opa sem chave "+key)
    if compose["OPA_API_MCP_ENABLED"]!="true" or compose["OPA_API_BASE_URL"]!="https://opa.jrconect.com":
        fail("OPA URL/flag inesperadas no Compose")
    if REQ_SUB not in compose["OPA_API_ALLOWED_SUB"].split(","):
        fail("mcp-teste nao consta da allowlist Opa")
    if str(compose["OPA_API_ADMIN_ENABLED"]).lower()=="true":
        fail("escrita administrativa Opa habilitada; nao preparar")
    env=dict(current)
    # Keep only approved Opa extension and any Keycloak compatibility keys from HML.
    for key in ("OPA_API_BASE_URL","OPA_API_MCP_ENABLED","OPA_API_ALLOWED_SUB","OPA_API_ADMIN_ENABLED"):
        env[key]=str(compose[key])
    for key in hml:
        if key.startswith("KEYCLOAK_") and key not in env:
            env[key]=hml[key]
    env["OPA_API_ADMIN_ENABLED"]="false"
    expected_env={k:v for k,v in current.items()}
    if any(env.get(k)!=v for k,v in expected_env.items()):
        fail("uma variavel de producao seria modificada")

    mounts=[]
    expected_secret_targets=set()
    for mount in live["Mounts"]:
        dest=mount["Destination"]
        if dest=="/data": continue
        if not dest.startswith("/run/secrets/") or mount["Type"]!="bind" or mount["RW"]:
            fail("montagem inesperada/gravavel em producao: "+dest)
        if not os.path.exists(mount["Source"]):
            fail("origem de montagem ausente: "+dest)
        mounts.extend(["--mount","type=bind,src="+mount["Source"]+",dst="+dest+",readonly"])
        expected_secret_targets.add(dest)
    mounts.extend(["--mount","type=bind,src="+str(OPA_TOKEN)+",dst=/run/secrets/opa-api-token,readonly"])
    expected_secret_targets.add("/run/secrets/opa-api-token")
    # /data is private to green. Never share writable production storage.
    mounts.extend(["--tmpfs","/data:rw,nosuid,nodev,size=32m,uid=1000,gid=1000,mode=0700"])

    STATE_DIR.mkdir(parents=True,exist_ok=True,mode=0o700)
    os.chmod(STATE_DIR,0o700)
    fd,tmp=tempfile.mkstemp(prefix=".env-",dir=str(STATE_DIR))
    try:
        os.fchmod(fd,0o600)
        with os.fdopen(fd,"w") as stream:
            for key,value in env.items():
                if "\n" in key or "\n" in value or "=" in key:
                    fail("formato de variavel de ambiente invalido")
                stream.write(key+"="+value+"\n")
        run=["docker","run","-d","--name",GREEN,"--restart","no",
             "--network","jrconect-mcp_default","-p","127.0.0.1:3102:3100",
             "--read-only","--cap-drop","ALL","--security-opt","no-new-privileges",
             "--memory","384m","--cpus","0.5","--pids-limit","64",
             "--tmpfs","/tmp:rw,nosuid,nodev,size=32m,mode=1777",
             *mounts,"--env-file",tmp,IMAGE]
        call(*run)
    finally:
        if os.path.exists(tmp): os.unlink(tmp)
    call("docker","network","connect","jrconect-auth_auth-internal",GREEN)
    for _ in range(20):
        if health(PORT): break
        time.sleep(1)
    if not health(PORT):
        fail("candidata nao respondeu health. Producao mantida; inspecionar logs apenas da candidata")
    green=inspect(GREEN)
    if not green["State"]["Running"] or green["Image"]!=candidate:
        fail("container candidato incorreto")
    if set(green["NetworkSettings"]["Networks"])!=NETWORKS:
        fail("redes do container candidato nao conferem")
    greenenv=varmap(green)
    if any(greenenv.get(k)!=v for k,v in expected_env.items()):
        fail("uma configuracao de producao esta ausente/divergente na candidata")
    seen={m["Destination"]:m for m in green["Mounts"]}
    if any(d not in seen or seen[d]["RW"] for d in expected_secret_targets):
        fail("credencial de producao nao esta montada read-only na candidata")
    if seen.get("/data",{}).get("Source")==next((m["Source"] for m in live["Mounts"] if m["Destination"]=="/data"),None):
        fail("candidata compartilha /data com producao")
    if not health(3100): fail("MCP original parou de responder")
    if inspect(SERVICE)["Image"]!=live["Image"]:
        fail("imagem da producao mudou inesperadamente")

    # Real, read-only API probe with the candidate's configured token.
    testjs=("import {createOpaQueueClient} from '/app/integrations/opa-queue-summary.js';"
            "const r=await createOpaQueueClient().resumo();"
            "if(!r.auditoria?.paginacao_completa)process.exit(9);"
            "console.log('OPA_FILA_OK',r.aguardando);")
    result=call("docker","exec",GREEN,"node","--input-type=module","-e",testjs,capture=True)
    print(result)
    # Keycloak reachability: do not output JWKS contents.
    keyjs=("const r=await fetch(process.env.KEYCLOAK_JWKS_URL,{signal:AbortSignal.timeout(5000)});"
           "const j=await r.json();if(!r.ok||!Array.isArray(j.keys)||!j.keys.length)process.exit(10);"
           "console.log('KEYCLOAK_JWKS_OK');")
    print(call("docker","exec",GREEN,"node","-e",keyjs,capture=True))

    state={"prepared_at":time.time(),"green_id":green["Id"],"candidate_image":candidate,
           "production_image":live["Image"],"nginx_original_sha256":sha(original),
           "nginx_path":str(nginx_file),"activated":False}
    store_state(state)
    print("PREPARACAO OK - nova instancia em 127.0.0.1:3102, todas as montagens protegidas")
    print("PRODUCAO 3100 PRESERVADA; NGINX SEM ALTERACAO")
    print("PENDENTE: ativacao seletiva da rota /mcp, com rollback automatico")

def activate():
    if os.geteuid()!=0: fail("requer root")
    state=load_state()
    live=ensure_live()
    green=inspect(GREEN)
    if not green["State"]["Running"] or not health(PORT):
        fail("candidata nao esta saudavel")
    if green["Id"]!=state["green_id"] or green["Image"]!=state["candidate_image"]:
        fail("a candidata mudou depois do preparo")
    if live["Image"]!=state["production_image"]:
        fail("imagem atual da producao mudou; abortando")
    path=config_path()
    if str(path)!=state["nginx_path"]:
        fail("arquivo alvo Nginx mudou")
    current=path.read_bytes()
    if sha(current)!=state["nginx_original_sha256"]:
        fail("Nginx modificado desde a preparacao; abortando")
    text=current.decode()
    if upstream(text)!=3100: fail("Nginx ja nao usa porta 3100")
    a,b,block=nginx_block(text)
    changed=block.replace("proxy_pass http://127.0.0.1:3100;","proxy_pass http://127.0.0.1:3102;")
    if changed==block or upstream(changed)!=3102:
        fail("nao foi possivel alterar exclusivamente o bloco /mcp")
    new=text[:a]+changed+text[b:]
    if new.replace(changed,block,1)!=text:
        fail("alteracao proposta nao e exclusivamente no bloco /mcp")
    STATE_DIR.mkdir(mode=0o700,parents=True,exist_ok=True)
    if BACKUP.exists(): fail("backup Nginx anterior existente; revisar antes de ativar")
    shutil.copy2(path,BACKUP)
    os.chmod(BACKUP,0o600)
    try:
        file_write_atomic(path,new.encode())
        call("nginx","-t")
        call("nginx","-s","reload")
        if not health(PORT) or not health(3100):
            fail("health caiu depois da mudanca")
    except Exception:
        file_write_atomic(path,BACKUP.read_bytes())
        call("nginx","-t")
        call("nginx","-s","reload")
        raise
    state["activated"]=True
    state["nginx_active_sha256"]=sha(new.encode())
    store_state(state)
    print("ATIVADO: somente o endpoint Nginx /mcp aponta para a candidata na porta 3102")
    print("PRODUCAO ANTIGA SEGUE ATIVA NA PORTA 3100")
    print("ROTAS /mcp-finance, /mcp-admin e /mcp-acs: NAO ALTERADAS")
    print("SE O CHATGPT FALHAR: execute rollback IMEDIATAMENTE")
    print("ATENCAO: sessoes MCP abertas podem precisar reconectar; nao ha garantia de zero reconexoes")

def rollback():
    if os.geteuid()!=0: fail("requer root")
    state=load_state()
    if not BACKUP.is_file(): fail("backup Nginx nao encontrado")
    path=config_path()
    text=path.read_bytes()
    if state.get("nginx_active_sha256")!=sha(text):
        fail("Nginx mudou desde a ativacao; nao sobrescrever mudancas de terceiros")
    if upstream(text.decode())!=3102:
        fail("Nginx /mcp ja nao aponta para candidata; revisar")
    file_write_atomic(path,BACKUP.read_bytes())
    call("nginx","-t")
    call("nginx","-s","reload")
    state["activated"]=False
    store_state(state)
    print("ROLLBACK OK: /mcp voltou para 3100, MCP anterior permaneceu ativo")
    print("A candidata continua disponivel em 3102 para auditoria")

def status():
    live=ensure_live()
    if STATE.exists():
        state=load_state()
        green=inspect(GREEN)
        print("GREEN:",green["State"]["Status"],"porta 3102 health:",health(PORT))
        print("NGINX /mcp porta:",upstream(config_path().read_text()))
        print("IMAGEM PRODUCAO ORIGINAL:",live["Image"]==state["production_image"])
    else:
        print("SEM PREPARACAO BLUEGREEN")
    print("MCP PRODUCAO: HEALTH OK")

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument("acao",choices=["prepare","activate","rollback","status"])
    args=parser.parse_args()
    if os.geteuid()!=0: fail("execute como root no ACS")
    {"prepare":prepare,"activate":activate,"rollback":rollback,"status":status}[args.acao]()

if __name__=="__main__":
    try: main()
    except Exception as error:
        print("BLOQUEADO:",str(error),file=sys.stderr)
        sys.exit(2)
