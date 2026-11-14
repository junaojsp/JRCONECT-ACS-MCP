#!/usr/bin/env python3
"""Safe cutover of ONLY Nginx exact /mcp: current 3104 -> ACS V2 3106.

plan/status are read-only. activate requires explicit operator acknowledgement,
saves original Nginx config with checksum, performs syntax and health checks,
then gracefully reloads Nginx. On errors it restores the exact backed-up file.
rollback restores ONLY the original config if the active checksum matches.
No containers, keys, env flags, OAuth settings, plugins, data or other routes
are ever modified.
"""
import argparse
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile
import time

NGINX_LINK = Path("/etc/nginx/sites-enabled/mcp.jrconect.com")
STATEDIR = Path("/root/jrc-acs-v2-cutover-3106")
STATE = STATEDIR / "state.json"
BACKUP = STATEDIR / "nginx-mcp-pre-acs-v2.conf"
NEGATIVE_GATE = STATEDIR / "negative-auth-gate-3106.json"
LOCK = STATEDIR / ".lock"
OLD = "jrconect-mcp-opa-ready"
NEW = "jrconect-mcp-acs-hml-v2"
OLD_PORT = 3104
NEW_PORT = 3106
CANDIDATE_IMAGE = "jrconect-mcp:acs-per-handler-stage-20261114T031338Z"
PRESERVED_PORTS = (3100, 3103, 3104, 3105)
COMPARE_PREFIXES = ("FINANCE_MCP_", "IXC_WS_", "OPA_API_", "SHIELD_MCP_", "KEYCLOAK_")
SENSITIVE = "NONE"  # Never display environment values, tokens, subjects or secrets.

class Blocked(Exception):
    pass

def fail(msg):
    raise Blocked(msg)

def run(argv, *, output=False, timeout=15):
    try:
        cp = subprocess.run(argv, check=False, capture_output=True,
                            text=True, timeout=timeout)
    except (OSError, subprocess.TimeoutExpired):
        fail("COMANDO_NAO_DISPONIVEL_OU_TIMEOUT")
    if cp.returncode:
        fail("COMANDO_" + argv[0] + "_CODIGO_" + str(cp.returncode))
    return cp.stdout.strip() if output else None

def inspect(name):
    raw = run(["docker", "inspect", name], output=True)
    try:
        return json.loads(raw)[0]
    except (ValueError, KeyError, IndexError):
        fail("DOCKER_INSPECT_INVALIDO")

def sha(data):
    return hashlib.sha256(data).hexdigest()

def health(port):
    if port not in PRESERVED_PORTS + (NEW_PORT,):
        fail("PORTA_INVALIDA")
    run(["curl", "--fail", "--silent", "--show-error", "--max-time", "5",
         "http://127.0.0.1:" + str(port) + "/health"], timeout=8)

def unauthenticated_401(port):
    if port not in (OLD_PORT, NEW_PORT):
        fail("PORTA_INVALIDA")
    response = run([
        "curl", "--silent", "--show-error", "--max-time", "5",
        "-o", "/dev/null", "-w", "%{http_code}", "-X", "POST",
        "-H", "Content-Type: application/json",
        "-H", "Accept: application/json, text/event-stream",
        "--data-binary", '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}',
        "http://127.0.0.1:" + str(port) + "/mcp"
    ], output=True, timeout=8)
    if response != "401":
        fail("AUTH_SEM_TOKEN_NAO_FOI_401_PORTA_" + str(port))

def live_nginx():
    file = Path(os.path.realpath(NGINX_LINK))
    if not NGINX_LINK.exists() or not file.is_file():
        fail("CONFIG_NGINX_NAO_ENCONTRADA")
    return file

def route_span(text):
    starts = list(re.finditer(
        r"(?m)^[ \t]*location[ \t]+=[ \t]+/mcp[ \t]*\{", text))
    if len(starts) != 1:
        fail("BLOCO_EXATO_MCP_NAO_UNICO")
    a = starts[0].start()
    i = starts[0].end()
    count = 1
    # Supported deployed nginx location format has no nested braces.
    while i < len(text) and count:
        if text[i] == "{":
            count += 1
        elif text[i] == "}":
            count -= 1
        i += 1
    if count:
        fail("CHAVES_NGINX_INCONSISTENTES")
    return a, i

def selected_upstream(text):
    a, b = route_span(text)
    block = text[a:b]
    matches = re.findall(
        r"(?m)^[ \t]*proxy_pass[ \t]+http://127[.]0[.]0[.]1:(\d+);[ \t]*$",
        block)
    if len(matches) != 1:
        fail("UPSTREAM_MCP_NAO_UNICO")
    return int(matches[0])

def new_config(original):
    try:
        text = original.decode("utf8")
    except UnicodeDecodeError:
        fail("NGINX_NAO_E_UTF8")
    if selected_upstream(text) != OLD_PORT:
        fail("UPSTREAM_MCP_NAO_APONTA_3104")
    a, b = route_span(text)
    block = text[a:b]
    pattern = r"(?m)^([ \t]*proxy_pass[ \t]+http://127[.]0[.]0[.]1:)3104(;[ \t]*)$"
    updated, changed = re.subn(pattern, r"\g<1>3106\2", block)
    if changed != 1:
        fail("SUBSTITUICAO_NAO_EXATA")
    result = (text[:a] + updated + text[b:]).encode("utf8")
    if selected_upstream(result.decode("utf8")) != NEW_PORT:
        fail("UPSTREAM_NOVO_DIVERGENTE")
    return result

def environment(container):
    return dict(x.split("=", 1) for x in container["Config"].get("Env", []) if "=" in x)

def mount_policy(item):
    policy={}
    for m in item.get("Mounts", []):
        dest = m["Destination"]
        if dest.startswith("/run/secrets/"):
            if m["Type"] != "bind" or m["RW"]:
                fail("SEGREDO_GRAVAVEL")
            policy[dest] = m["Source"]
    return policy

def compare_containers():
    old = inspect(OLD)
    new = inspect(NEW)
    if not old["State"].get("Running") or not new["State"].get("Running"):
        fail("CONTAINER_3104_OU_3106_PARADO")
    image = run(["docker", "image", "inspect", "-f", "{{.Id}}",
                 CANDIDATE_IMAGE], output=True)
    if new["Image"] != image:
        fail("IMAGEM_CANDIDATA_DIVERGENTE")
    old_image = old["Image"]
    if new["Image"] == old_image:
        fail("IMAGEM_NOVA_IGUAL_ANTIGA")
    if not new["HostConfig"].get("ReadonlyRootfs"):
        fail("ROOTFS_CANDIDATA_GRAVAVEL")
    binding = new["HostConfig"].get("PortBindings", {}).get("3100/tcp", [])
    if len(binding) != 1 or binding[0].get("HostIp") != "127.0.0.1" or binding[0].get("HostPort") != "3106":
        fail("PORTA_CANDIDATA_NAO_ISOLADA")
    if old["HostConfig"].get("PortBindings", {}).get("3100/tcp", [{}])[0].get("HostPort") != "3104":
        fail("PORTA_ANTIGA_DIVERGENTE")
    if set(new["NetworkSettings"]["Networks"]) != set(old["NetworkSettings"]["Networks"]):
        fail("REDES_CANDIDATA_DIVERGENTES")
    from_old = environment(old)
    from_new = environment(new)
    for key,value in from_old.items():
        if key.startswith(COMPARE_PREFIXES) and from_new.get(key) != value:
            # Name only; never print config values.
            fail("VARIAVEL_LEGADA_AUSENTE_OU_DIVERGENTE_" + key)
    old_secrets = mount_policy(old)
    new_secrets = mount_policy(new)
    for destination, source in old_secrets.items():
        if new_secrets.get(destination) != source:
            fail("SEGREDO_LEGADO_NAO_PRESERVADO_" + destination.split("/")[-1])
    if from_new.get("ACS_MONITOR_MCP_ENABLED") != "true":
        fail("ACS_MONITOR_NAO_HABILITADO")
    if from_new.get("ACS_ADMIN_MCP_ENABLED", "false") != "false":
        fail("ACS_ADMIN_NAO_DESABILITADO")
    if from_new.get("OPA_API_ADMIN_ENABLED", "false") == "true":
        fail("OPA_ADMIN_NAO_DESABILITADO")
    old_data = [m["Source"] for m in old.get("Mounts", []) if m["Destination"] == "/data"]
    new_data = [m["Source"] for m in new.get("Mounts", []) if m["Destination"] == "/data"]
    if len(old_data) != 1 or len(new_data) != 1 or old_data[0] == new_data[0]:
        fail("DATA_NAO_ISOLADA")
    for port in PRESERVED_PORTS + (NEW_PORT,):
        health(port)
    for port in (OLD_PORT, NEW_PORT):
        unauthenticated_401(port)
    return old_image, image

def preflight():
    if os.geteuid() != 0:
        fail("EXECUTE_COM_SUDO")
    path = live_nginx()
    source = path.read_bytes()
    if selected_upstream(source.decode("utf8")) != OLD_PORT:
        fail("ROTA_MCP_NAO_ESTA_3104")
    changed = new_config(source)
    run(["nginx", "-t"])
    old_id, new_id = compare_containers()
    return path, source, changed, old_id, new_id

def verify_negative_gate(new_image):
    if NEGATIVE_GATE.is_symlink() or not NEGATIVE_GATE.is_file():
        fail("TESTE_NEGATIVO_OAUTH_REAL_PENDENTE")
    try:
        receipt = json.loads(NEGATIVE_GATE.read_text("utf8"))
    except (OSError, ValueError):
        fail("RECIBO_NEGATIVO_INVALIDO")
    if (receipt.get("result") != "verified-denied"
        or receipt.get("mode") != "oauth-valid-token-without-acs-role"
        or receipt.get("port") != NEW_PORT
        or receipt.get("container_image") != new_image):
        fail("RECIBO_NEGATIVO_NAO_CORRESPONDE_A_IMAGEM")
    stamp = receipt.get("verified_at")
    if not isinstance(stamp, int) or not (0 <= time.time()-stamp <= 43200):
        fail("TESTE_NEGATIVO_EXPIRADO")
    return True


def secure_dir():
    if STATEDIR.is_symlink():
        fail("STATE_DIR_LINK_NAO_PERMITIDO")
    STATEDIR.mkdir(mode=0o700, parents=True, exist_ok=True)
    os.chmod(STATEDIR, 0o700)

def atomic_write(path, content, mode=None, owner=None):
    fd, tmp = tempfile.mkstemp(prefix=".jrc-acs-cutover-", dir=str(path.parent))
    try:
        with os.fdopen(fd, "wb") as out:
            out.write(content)
            out.flush()
            os.fsync(out.fileno())
        if owner is not None:
            os.chown(tmp, owner.st_uid, owner.st_gid)
        os.chmod(tmp, mode if mode is not None else 0o600)
        os.replace(tmp, path)
    finally:
        if os.path.exists(tmp):
            os.unlink(tmp)

def load_state():
    if not STATE.is_file() or not BACKUP.is_file():
        fail("BACKUP_OU_ESTADO_NAO_ENCONTRADO")
    try:
        info = json.loads(STATE.read_text("utf8"))
    except ValueError:
        fail("ESTADO_INVALIDO")
    saved = BACKUP.read_bytes()
    if sha(saved) != info.get("nginx_original_sha256"):
        fail("BACKUP_ORIGINAL_DIVERGENTE")
    return info, saved

def guarded_lock():
    secure_dir()
    fd = os.open(LOCK, os.O_CREAT | os.O_RDWR, 0o600)
    fcntl.flock(fd, fcntl.LOCK_EX)
    return fd

def activate():
    if os.environ.get("JRC_APPROVE_ACS_V2_CUTOVER") != "YES":
        fail("APROVACAO_OPERADOR_AUSENTE")
    if STATE.exists() or BACKUP.exists():
        fail("BACKUP_ANTERIOR_EXISTE_USAR_STATUS_OU_ROLLBACK")
    path, old, changed, old_image, new_image = preflight()
    verify_negative_gate(new_image)
    fd = guarded_lock()
    try:
        if STATE.exists() or BACKUP.exists() or path.read_bytes() != old:
            fail("ALTERACAO_CONCORRENTE_DETECTADA")
        st = path.stat()
        # Backup is created BEFORE any Nginx mutation.
        atomic_write(BACKUP, old, mode=0o600)
        info = {
            "phase": "prepared",
            "created_at": int(time.time()),
            "nginx_path": str(path),
            "nginx_original_sha256": sha(old),
            "nginx_active_sha256": sha(changed),
            "old_image": old_image,
            "new_image": new_image,
            "old_port": OLD_PORT,
            "new_port": NEW_PORT,
        }
        atomic_write(STATE, json.dumps(info, sort_keys=True).encode(), mode=0o600)
        try:
            # Read after prepared state, fail if anyone else touched config.
            if path.read_bytes() != old:
                fail("NGINX_MUDOU_APOS_BACKUP")
            atomic_write(path, changed, mode=st.st_mode & 0o777,
                         owner=st)
            run(["nginx", "-t"])
            run(["nginx", "-s", "reload"])
            if path.read_bytes() != changed:
                fail("NGINX_MUDOU_DURANTE_ATIVACAO")
            for port in (OLD_PORT, NEW_PORT):
                health(port)
            # Verify public TLS virtual host still enforces Bearer auth.
            status = run([
                "curl", "--silent", "--show-error", "--max-time", "8",
                "--noproxy", "*",
                "--resolve", "mcp.jrconect.com:443:127.0.0.1",
                "-o", "/dev/null", "-w", "%{http_code}",
                "-X", "POST",
                "-H", "Content-Type: application/json",
                "-H", "Accept: application/json, text/event-stream",
                "--data-binary", '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}',
                "https://mcp.jrconect.com/mcp"
            ], output=True, timeout=12)
            if status != "401":
                fail("ROTA_PUBLICA_NAO_401")
        except Exception:
            # Fail back only if file is exactly our newly written config;
            # never overwrite unexpected third-party modifications.
            if path.read_bytes() == changed:
                atomic_write(path, old, mode=st.st_mode & 0o777, owner=st)
                run(["nginx", "-t"])
                run(["nginx", "-s", "reload"])
            raise
        info["phase"] = "active"
        atomic_write(STATE, json.dumps(info, sort_keys=True).encode(), mode=0o600)
        print("ATIVACAO_OK: NGINX LOCATION = /mcp APONTA PARA 3106")
        print("MCP ANTIGO 3104: PRESERVADO PARA ROLLBACK")
        print("NGINX PUBLICO SEM TOKEN: HTTP 401")
        print("OUTRAS ROTAS, CONTAINERS, KEYCLOAK E SEGREDOS: NAO ALTERADOS")
        print("PENDENTE: atualizar o catalogo do plugin JR Conect MCPv2 no ChatGPT")
        print("EM CASO DE FALHA DE FERRAMENTA, EXECUTE rollback")
    finally:
        os.close(fd)

def reactivate():
    if os.environ.get("JRC_APPROVE_ACS_V2_CUTOVER") != "YES":
        fail("APROVACAO_OPERADOR_AUSENTE")
    if not STATE.is_file() or not BACKUP.is_file():
        fail("ESTADO_ANTERIOR_NAO_ENCONTRADO")
    path, old, changed, old_image, new_image = preflight()
    verify_negative_gate(new_image)
    fd = guarded_lock()
    try:
        previous, saved = load_state()
        if previous.get("phase") != "rolled_back":
            fail("FASE_NAO_E_ROLLED_BACK")
        if saved != old or sha(old) != previous.get("nginx_original_sha256"):
            fail("BACKUP_NAO_CORRESPONDE_A_CONFIG_ATUAL")
        if sha(changed) != previous.get("nginx_active_sha256"):
            fail("CANDIDATA_DIVERGE_DO_BACKUP")
        if previous.get("old_image") != old_image or previous.get("new_image") != new_image:
            fail("IMAGENS_MUDARAM_DESDE_ROLLBACK")
        if path.read_bytes() != old:
            fail("ALTERACAO_CONCORRENTE_DETECTADA")
        st = path.stat()
        info = {
            "phase": "prepared",
            "created_at": int(time.time()),
            "nginx_path": str(path),
            "nginx_original_sha256": sha(old),
            "nginx_active_sha256": sha(changed),
            "old_image": old_image,
            "new_image": new_image,
            "old_port": OLD_PORT,
            "new_port": NEW_PORT,
        }
        info["reactivated_from_rollback"] = True
        atomic_write(STATE, json.dumps(info, sort_keys=True).encode(), mode=0o600)
        try:
            # Read after prepared state, fail if anyone else touched config.
            if path.read_bytes() != old:
                fail("NGINX_MUDOU_APOS_BACKUP")
            atomic_write(path, changed, mode=st.st_mode & 0o777,
                         owner=st)
            run(["nginx", "-t"])
            run(["nginx", "-s", "reload"])
            if path.read_bytes() != changed:
                fail("NGINX_MUDOU_DURANTE_ATIVACAO")
            for port in (OLD_PORT, NEW_PORT):
                health(port)
            # Verify public TLS virtual host still enforces Bearer auth.
            status = run([
                "curl", "--silent", "--show-error", "--max-time", "8",
                "--noproxy", "*",
                "--resolve", "mcp.jrconect.com:443:127.0.0.1",
                "-o", "/dev/null", "-w", "%{http_code}",
                "-X", "POST",
                "-H", "Content-Type: application/json",
                "-H", "Accept: application/json, text/event-stream",
                "--data-binary", '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}',
                "https://mcp.jrconect.com/mcp"
            ], output=True, timeout=12)
            if status != "401":
                fail("ROTA_PUBLICA_NAO_401")
        except Exception:
            # Fail back only if file is exactly our newly written config;
            # never overwrite unexpected third-party modifications.
            if path.read_bytes() == changed:
                atomic_write(path, old, mode=st.st_mode & 0o777, owner=st)
                run(["nginx", "-t"])
                run(["nginx", "-s", "reload"])
            raise
        info["phase"] = "active"
        atomic_write(STATE, json.dumps(info, sort_keys=True).encode(), mode=0o600)
        print("ATIVACAO_OK: NGINX LOCATION = /mcp APONTA PARA 3106")
        print("MCP ANTIGO 3104: PRESERVADO PARA ROLLBACK")
        print("NGINX PUBLICO SEM TOKEN: HTTP 401")
        print("OUTRAS ROTAS, CONTAINERS, KEYCLOAK E SEGREDOS: NAO ALTERADOS")
        print("PENDENTE: atualizar o catalogo do plugin JR Conect MCPv2 no ChatGPT")
        print("EM CASO DE FALHA DE FERRAMENTA, EXECUTE rollback")
    finally:
        os.close(fd)

def rollback():
    if os.geteuid() != 0:
        fail("EXECUTE_COM_SUDO")
    fd = guarded_lock()
    try:
        state, original = load_state()
        path = live_nginx()
        if str(path) != state.get("nginx_path"):
            fail("CAMINHO_NGINX_MUDOU")
        current = path.read_bytes()
        if sha(current) == state["nginx_original_sha256"]:
            print("ROLLBACK_DESNECESSARIO: /mcp JA ESTA NA CONFIG ORIGINAL")
            return
        if sha(current) != state.get("nginx_active_sha256"):
            fail("NGINX_MODIFICADO_POR_TERCEIROS_NAO_SOBRESCREVER")
        health(OLD_PORT)
        st = path.stat()
        atomic_write(path, original, mode=st.st_mode & 0o777, owner=st)
        run(["nginx", "-t"])
        run(["nginx", "-s", "reload"])
        if selected_upstream(path.read_text("utf8")) != OLD_PORT:
            fail("ROLLBACK_ROTA_DIVERGENTE")
        state["phase"] = "rolled_back"
        atomic_write(STATE, json.dumps(state, sort_keys=True).encode(), mode=0o600)
        print("ROLLBACK_OK: /mcp RETORNOU PARA 3104")
        print("MCP ACS V2 3106 CONTINUA DISPONIVEL PARA ANALISE")
    finally:
        os.close(fd)

def status():
    if os.geteuid() != 0:
        fail("EXECUTE_COM_SUDO")
    path = live_nginx()
    print("=== STATUS MCP ACS V2 ===")
    print("NGINX /mcp PORTA:", selected_upstream(path.read_text("utf8")))
    print("PORTAS SAUDAVEIS:", [
        port for port in PRESERVED_PORTS + (NEW_PORT,)
        if subprocess.run(["curl", "-fsS", "--max-time", "3",
                           "http://127.0.0.1:" + str(port) + "/health"],
                          stdout=subprocess.DEVNULL,
                          stderr=subprocess.DEVNULL, check=False).returncode == 0
    ])
    print("BACKUP EXISTE:", BACKUP.is_file())
    if STATE.is_file() and BACKUP.is_file():
        state, _ = load_state()
        print("FASE:", state.get("phase", "DESCONHECIDA"))
        print("CONFIG NGINX ORIGINAL:",
              sha(path.read_bytes()) == state.get("nginx_original_sha256"))
        print("CONFIG NGINX ACS V2:",
              sha(path.read_bytes()) == state.get("nginx_active_sha256"))
    print("NENHUMA ALTERACAO")

def plan():
    path, orig, updated, old_image, new_image = preflight()
    print("=== ACS V2 - PLANO SEGURO DE MIGRACAO ===")
    print("MCP ATUAL NGINX:", selected_upstream(orig.decode("utf8")))
    print("MCP CANDIDATO:", selected_upstream(updated.decode("utf8")))
    print("IMAGEM ANTIGA E NOVA DISTINTAS:", old_image != new_image)
    print("PORTAS 3100/3103/3104/3105/3106: HEALTH OK")
    print("PORTAS 3104 E 3106: REJEITAM SEM TOKEN (401)")
    print("PERMISSOES LEGADAS/SEGREDOS/MONTAGENS: COMPATIVEIS")
    print("ADMINISTRACAO ACS: DESABILITADA")
    print("BLOCO ALTERADO: APENAS location = /mcp")
    print("BACKUP STATE:", "EXISTENTE - BLOQUEIA ATIVACAO" if STATE.exists() or BACKUP.exists() else "LIVRE")
    try:
        verify_negative_gate(new_image)
        print("TESTE NEGATIVO OAUTH REAL: OK")
    except Blocked:
        print("TESTE NEGATIVO OAUTH REAL: PENDENTE OU INVALIDO - NAO ATIVAR")
    print("PLANO OK - NENHUMA ALTERACAO")

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("action", choices=("plan", "activate", "reactivate", "rollback", "status"))
    args = parser.parse_args()
    {"plan": plan, "activate": activate, "reactivate": reactivate,
     "rollback": rollback, "status": status}[args.action]()

if __name__ == "__main__":
    try:
        main()
    except Blocked as e:
        print("BLOQUEADO:", str(e), file=sys.stderr)
        sys.exit(2)
    except Exception:
        print("BLOQUEADO: FALHA_NAO_PREVISTA (sem imprimir segredos)",
              file=sys.stderr)
        sys.exit(2)
