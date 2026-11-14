#!/usr/bin/env python3
"""OAuth Authorization Code + PKCE S256 smoke test, MCPv2 ACS HML only.

The browser uses the registered public-client loopback redirect. No listener is
created: after the browser cannot reach localhost, the operator privately pastes
its address bar callback URL into this terminal. No auth code, tokens or JWTs
are logged, saved or sent to the assistant. Only whitelisted local MCP ports.
"""

import argparse
import base64
import getpass
import os
import re
import subprocess
import tempfile
from pathlib import Path
import hashlib
import hmac
import json
import secrets
import sys
import time
from urllib.error import HTTPError, URLError
from urllib.parse import parse_qs, urlencode, urlsplit
from urllib.request import Request, urlopen

ISSUER = "https://auth.jrconect.com/realms/jrconect"
AUTH = ISSUER + "/protocol/openid-connect/auth"
TOKEN = ISSUER + "/protocol/openid-connect/token"
CLIENT = "chatgpt-mcp"
REDIRECT = "http://127.0.0.1:8765/callback"
PORTS = (3104, 3105, 3106)
PROTOCOL = "2025-06-18"
MAX_RESULT = 2_000_000
TEST_TOOLS = ("acs_recursos_host", "acs_status_integracoes")


class SafeFailure(Exception):
    pass


def b64url(raw):
    return base64.urlsafe_b64encode(raw).rstrip(b"=").decode("ascii")


def new_login(login_hint="mcp-teste"):
    verifier = b64url(secrets.token_bytes(48))
    state = b64url(secrets.token_bytes(32))
    challenge = b64url(hashlib.sha256(verifier.encode("ascii")).digest())
    params = {
        "client_id": CLIENT,
        "response_type": "code",
        "redirect_uri": REDIRECT,
        "scope": "openid",
        "code_challenge": challenge,
        "code_challenge_method": "S256",
        "state": state,
        "prompt": "login",
        "login_hint": login_hint,
    }
    return AUTH + "?" + urlencode(params), verifier, state


def parse_callback(callback, expected_state):
    if not isinstance(callback, str) or len(callback) > 8192:
        raise SafeFailure("RETORNO_INVALIDO")
    u = urlsplit(callback.strip())
    ref = urlsplit(REDIRECT)
    if (u.scheme, u.netloc, u.path) != (ref.scheme, ref.netloc, ref.path) or u.fragment:
        raise SafeFailure("RETORNO_DIFERENTE_DO_CADASTRADO")
    try:
        parsed = parse_qs(u.query, keep_blank_values=True, strict_parsing=True, max_num_fields=25)
    except ValueError:
        raise SafeFailure("RETORNO_PARAMETROS_INVALIDOS") from None
    if "error" in parsed:
        raise SafeFailure("LOGIN_OAUTH_RETORNOU_ERRO")
    states = parsed.get("state", [])
    codes = parsed.get("code", [])
    if len(states) != 1 or not hmac.compare_digest(states[0], expected_state):
        raise SafeFailure("ESTADO_OAUTH_INCORRETO")
    if len(codes) != 1 or not (8 <= len(codes[0]) <= 2048):
        raise SafeFailure("CODIGO_OAUTH_AUSENTE")
    return codes[0]


def http_json(request, *, timeout=15, max_bytes=MAX_RESULT):
    try:
        with urlopen(request, timeout=timeout) as response:
            raw = response.read(max_bytes + 1)
            if len(raw) > max_bytes:
                raise SafeFailure("RESPOSTA_ACIMA_DO_LIMITE")
            return json.loads(raw), response.status
    except HTTPError as e:
        raise SafeFailure("HTTP_" + str(e.code)) from None
    except (URLError, TimeoutError):
        raise SafeFailure("REDE_INDISPONIVEL") from None
    except (UnicodeError, ValueError):
        raise SafeFailure("RESPOSTA_JSON_INVALIDA") from None


def exchange_code(code, verifier):
    data = urlencode({
        "grant_type": "authorization_code",
        "client_id": CLIENT,
        "redirect_uri": REDIRECT,
        "code": code,
        "code_verifier": verifier,
    }).encode("ascii")
    request = Request(TOKEN, data=data,
                      headers={"Content-Type": "application/x-www-form-urlencoded",
                               "Accept": "application/json"}, method="POST")
    doc, status = http_json(request, timeout=15, max_bytes=65536)
    token = doc.get("access_token") if isinstance(doc, dict) else None
    if status != 200 or not isinstance(token, str) or len(token) > 16000:
        raise SafeFailure("TOKEN_OAUTH_NAO_CONFIRMADO")
    return token


def read_rpc_response(response, expected_id):
    content_type = response.headers.get("Content-Type", "").lower()
    if response.status not in (200, 201, 202):
        raise SafeFailure("MCP_HTTP_" + str(response.status))
    if response.status == 202:
        return None
    if "text/event-stream" in content_type:
        budget = 0
        parts = []
        while True:
            line = response.readline(65537)
            budget += len(line)
            if budget > MAX_RESULT or len(line) > 65536:
                raise SafeFailure("MCP_RESPOSTA_ACIMA_DO_LIMITE")
            if not line:
                raise SafeFailure("MCP_EVENTO_AUSENTE")
            decoded = line.decode("utf-8", errors="strict").rstrip("\r\n")
            if decoded.startswith("data:"):
                parts.append(decoded[5:].lstrip())
            elif not decoded and parts:
                try:
                    msg = json.loads("\n".join(parts))
                except ValueError:
                    raise SafeFailure("MCP_EVENTO_JSON_INVALIDO") from None
                if msg.get("id") == expected_id:
                    return msg
                parts = []
    elif "application/json" in content_type:
        raw = response.read(MAX_RESULT + 1)
        if len(raw) > MAX_RESULT:
            raise SafeFailure("MCP_RESPOSTA_ACIMA_DO_LIMITE")
        try:
            msg = json.loads(raw)
        except ValueError:
            raise SafeFailure("MCP_RESPOSTA_JSON_INVALIDA") from None
        if msg.get("id") != expected_id:
            raise SafeFailure("MCP_ID_DIVERGENTE")
        return msg
    else:
        raise SafeFailure("MCP_CONTENT_TYPE_INESPERADO")


class MCP:
    def __init__(self, port, token):
        if port not in PORTS:
            raise SafeFailure("PORTA_NAO_AUTORIZADA")
        self.url = "http://127.0.0.1:" + str(port) + "/mcp"
        self.token = token
        self.session = None
        self.req_id = 0

    def rpc(self, method, params=None, *, notification=False):
        if method not in ("initialize", "notifications/initialized", "tools/list", "tools/call"):
            raise SafeFailure("METODO_MCP_NAO_PERMITIDO")
        payload = {"jsonrpc": "2.0", "method": method}
        if not notification:
            self.req_id += 1
            payload["id"] = self.req_id
        if params is not None:
            payload["params"] = params
        headers = {
            "Content-Type": "application/json",
            "Accept": "application/json, text/event-stream",
            "Authorization": "Bearer " + self.token,
            "MCP-Protocol-Version": PROTOCOL,
        }
        if self.session:
            headers["Mcp-Session-Id"] = self.session
        req = Request(self.url, data=json.dumps(payload, separators=(",", ":")).encode("utf8"),
                      headers=headers, method="POST")
        try:
            with urlopen(req, timeout=18) as response:
                if not self.session:
                    session = response.headers.get("Mcp-Session-Id")
                    if session:
                        if len(session) > 256 or "\n" in session or "\r" in session:
                            raise SafeFailure("MCP_SESSION_INVALIDA")
                        self.session = session
                answer = read_rpc_response(response, self.req_id) if not notification else None
        except HTTPError as e:
            raise SafeFailure("MCP_HTTP_" + str(e.code)) from None
        except (URLError, TimeoutError):
            raise SafeFailure("MCP_REDE_INDISPONIVEL") from None
        if notification:
            return None
        if not isinstance(answer, dict):
            raise SafeFailure("MCP_RESPOSTA_AUSENTE")
        if "error" in answer:
            error = answer.get("error", {})
            # Error code only, never include raw message or data.
            code = error.get("code", "desconhecido") if isinstance(error, dict) else "desconhecido"
            raise SafeFailure("MCP_JSONRPC_ERRO_" + str(code))
        result = answer.get("result")
        if not isinstance(result, dict):
            raise SafeFailure("MCP_RESULTADO_INVALIDO")
        return result

    def initialize(self):
        self.rpc("initialize", {
            "protocolVersion": PROTOCOL,
            "capabilities": {},
            "clientInfo": {"name": "acs-homologacao-local", "version": "1.0"},
        })
        self.rpc("notifications/initialized", notification=True)

    def tools(self):
        items = []
        cursor = None
        seen = set()
        for _ in range(16):
            params = {"cursor": cursor} if cursor else {}
            data = self.rpc("tools/list", params)
            page = data.get("tools", [])
            if not isinstance(page, list) or len(page) > 500:
                raise SafeFailure("MCP_CATALOGO_INVALIDO")
            for item in page:
                if not isinstance(item, dict) or not isinstance(item.get("name"), str):
                    raise SafeFailure("MCP_CATALOGO_ITEM_INVALIDO")
                items.append(item["name"])
            cursor = data.get("nextCursor")
            if not cursor:
                return set(items)
            if not isinstance(cursor, str) or cursor in seen:
                raise SafeFailure("MCP_CURSOR_INVALIDO")
            seen.add(cursor)
        raise SafeFailure("MCP_PAGINACAO_INCOMPLETA")

    def call_readonly(self, tool):
        if tool not in TEST_TOOLS:
            raise SafeFailure("TOOL_NAO_PERMITIDA")
        result = self.rpc("tools/call", {"name": tool, "arguments": {}})
        if result.get("isError") is True:
            return False
        content = result.get("content")
        if not isinstance(content, list) or not content:
            return False
        # Force valid JSON without displaying metrics or potentially sensitive data.
        try:
            payloads = [json.loads(item["text"]) for item in content
                        if isinstance(item, dict) and item.get("type") == "text"
                        and isinstance(item.get("text"), str)]
        except (ValueError, KeyError):
            return False
        return bool(payloads) and all(isinstance(x, (dict, list)) for x in payloads)


def run_smoke(token, hml_port=3105):
    if hml_port not in (3105, 3106):
        raise SafeFailure("PORTA_HML_NAO_AUTORIZADA")
    print("=== HOMOLOGACAO REAL MCP (SEM MOSTRAR TOKEN) ===")
    main = MCP(3104, token)
    hml = MCP(hml_port, token)
    for port, client in ((3104, main), (hml_port, hml)):
        client.initialize()
        print("PORTA", port, "OAuth + initialize: OK")
    current = main.tools()
    candidate = hml.tools()
    missing = sorted(current - candidate)
    acs = sorted(set(TEST_TOOLS) & candidate)
    print("FERRAMENTAS 3104:", len(current))
    print("FERRAMENTAS "+str(hml_port)+":", len(candidate))
    print("FERRAMENTAS ACS ENCONTRADAS:", ", ".join(acs) if acs else "NENHUMA")
    print("FERRAMENTAS EXISTENTES AUSENTES NA "+str(hml_port)+":", len(missing))
    if missing:
        print("RESULTADO: BLOQUEADO - o catalogo da HML perdeu ferramentas antigas")
        return 2
    if set(TEST_TOOLS) - candidate:
        print("RESULTADO: BLOQUEADO - duas ferramentas ACS nao apareceram")
        return 2
    statuses = [(tool, hml.call_readonly(tool)) for tool in TEST_TOOLS]
    for tool, ok in statuses:
        print(tool + ":", "CONSULTA OK" if ok else "CONSULTA NEGADA OU INDISPONIVEL")
    if not all(ok for _, ok in statuses):
        print("RESULTADO: BLOQUEADO - nao ativar MCPv2 ACS em producao")
        return 2
    print("RESULTADO: HOMOLOGACAO OAUTH E LEITURA ACS OK")
    print("ADMINISTRACAO ACS: NAO TESTADA E NAO HABILITADA")
    return 0


def token_claims_for_negative_gate(token):
    # Token from Keycloak over HTTPS; the MCP servers validate its signature.
    try:
        parts = token.split(".")
        if len(parts) != 3 or len(parts[1]) > 30000:
            raise ValueError()
        raw = base64.urlsafe_b64decode(parts[1] + "=" * (-len(parts[1]) % 4))
        claims = json.loads(raw)
    except (ValueError, UnicodeError, TypeError):
        raise SafeFailure("NEGATIVO_JWT_FORMATO_INVALIDO") from None
    roles = claims.get("realm_access", {}).get("roles", [])
    if (claims.get("iss") != ISSUER or claims.get("azp") != CLIENT
        or not isinstance(claims.get("sub"), str)
        or not isinstance(roles, list)):
        raise SafeFailure("NEGATIVO_JWT_ISSUER_OU_ROLE_INVALIDA")
    if "acs-mcp-admin" in roles:
        raise SafeFailure("NEGATIVO_CONTA_POSSUI_ROLE_ACS")
    return True


def run_negative(token, hml_port=3106):
    if hml_port != 3106:
        raise SafeFailure("NEGATIVO_SOMENTE_HML_3106")
    token_claims_for_negative_gate(token)
    print("=== ACS V2 - TESTE NEGATIVO COM JWT REAL SEM ROLE ===")
    prod = MCP(3104, token)
    hml = MCP(3106, token)
    prod.initialize()
    hml.initialize()
    old = prod.tools()
    new = hml.tools()
    missing = old - new
    acs = set(TEST_TOOLS) & new
    print("OAUTH REAL NAS PORTAS 3104 E 3106: OK")
    print("JWT REAL SEM acs-mcp-admin: CONFIRMADO")
    print("TOOLS LEGADAS AUSENTES NA 3106:", len(missing))
    print("TOOLS ACS EXPOSTAS SEM ROLE:", len(acs))
    if missing or acs:
        print("NEGATIVO: BLOQUEADO - catalogo indevido")
        return 2
    print("NEGATIVO: OK - nenhuma ferramenta ACS exposta sem role")
    return 0


def save_negative_gate():
    # Only a non-secret validation receipt, tied to the image being promoted.
    if os.geteuid() != 0:
        raise SafeFailure("NEGATIVO_RECIBO_EXIGE_ROOT")
    try:
        cp = subprocess.run(
            ["docker", "inspect", "-f", "{{.Image}}", "jrconect-mcp-acs-hml-v2"],
            check=True, capture_output=True, text=True, timeout=8,
        )
        image = cp.stdout.strip()
        if not image.startswith("sha256:"):
            raise ValueError()
        dirname = Path("/root/jrc-acs-v2-cutover-3106")
        if dirname.is_symlink():
            raise ValueError()
        dirname.mkdir(parents=True, mode=0o700, exist_ok=True)
        os.chmod(dirname, 0o700)
        receipt = dirname / "negative-auth-gate-3106.json"
        if receipt.is_symlink():
            raise ValueError()
        data = {
            "result": "verified-denied",
            "mode": "oauth-valid-token-without-acs-role",
            "container_image": image,
            "verified_at": int(time.time()),
            "port": 3106,
        }
        fd, temp = tempfile.mkstemp(prefix=".negative-", dir=str(dirname))
        try:
            with os.fdopen(fd, "w", encoding="utf8") as file:
                os.fchmod(file.fileno(), 0o600)
                json.dump(data, file, sort_keys=True)
                file.flush()
                os.fsync(file.fileno())
            os.replace(temp, receipt)
        finally:
            if os.path.exists(temp):
                os.unlink(temp)
    except (OSError, subprocess.SubprocessError, ValueError):
        raise SafeFailure("NEGATIVO_NAO_FOI_POSSIVEL_GRAVAR_RECIBO") from None
    print("RECIBO NEGATIVO: GRAVADO SEM TOKEN, SEM USUARIO E SEM SEGREDOS")


def main():
    parser = argparse.ArgumentParser(description="Smoke test OAuth PKCE + MCP ACS HML")
    parser.add_argument("--check", action="store_true", help="Verify localhost services only; no OAuth")
    parser.add_argument("--hml-port", type=int, choices=(3105, 3106), default=3105,
                        help="ACS homologacao alvo (padrao 3105; V2 3106)")
    parser.add_argument("--expect-no-acs", action="store_true",
                        help="Real OAuth negative test with an account WITHOUT ACS role")
    parser.add_argument("--login-hint", default="mcp-teste",
                        help="Keycloak username hint (never a password)")
    args = parser.parse_args()
    if not re.fullmatch(r"[A-Za-z0-9_.-]{1,64}", args.login_hint):
        raise SafeFailure("LOGIN_HINT_INVALIDO")
    if args.expect_no_acs and args.hml_port != 3106:
        raise SafeFailure("NEGATIVO_SOMENTE_HML_3106")
    if args.expect_no_acs and args.login_hint == "mcp-teste":
        raise SafeFailure("NEGATIVO_NAO_USAR_CONTA_MCP_TESTE")
    # This script only runs on the ACS host, never opens ports.
    for port in (3104, args.hml_port):
        req = Request("http://127.0.0.1:"+str(port)+"/health", method="GET")
        _, status = http_json(req, timeout=5, max_bytes=1024)
        if status != 200:
            raise SafeFailure("MCP_HEALTH_INDISPONIVEL_PORTA_"+str(port))
    print("PORTAS 3104/"+str(args.hml_port)+": HEALTH OK")
    if args.check:
        print("DRY_RUN: sem login, sem token, sem alteracoes")
        return 0
    url, verifier, state = new_login(args.login_hint)
    print("\n1) Abra no seu navegador o endereco OAuth abaixo e faca login como "+args.login_hint+":")
    print(url)
    print("\n2) O navegador pode mostrar 'site indisponivel' ao abrir 127.0.0.1:8765.")
    print("   Isso e esperado: copie o URL COMPLETO da barra de enderecos (com code e state).")
    print("   NUNCA envie esse endereco para o ChatGPT ou por mensagem.")
    print("3) Cole o URL no campo oculto do PuTTY. Os caracteres nao vao aparecer.")
    start = time.monotonic()
    callback = getpass.getpass("Cole aqui a URL completa do retorno OAuth (entrada oculta): ")
    if time.monotonic() - start > 240:
        raise SafeFailure("LOGIN_EXPIROU_REINICIE_O_TESTE")
    code = parse_callback(callback, state)
    # Drop callback/code references as soon as possible.
    del callback
    token = exchange_code(code, verifier)
    del code, verifier
    try:
        if args.expect_no_acs:
            outcome = run_negative(token, args.hml_port)
            if outcome == 0:
                save_negative_gate()
            return outcome
        return run_smoke(token, args.hml_port)
    finally:
        del token


if __name__ == "__main__":
    try:
        sys.exit(main())
    except KeyboardInterrupt:
        print("\nCANCELADO: nenhuma configuracao alterada")
        sys.exit(130)
    except SafeFailure as e:
        print("BLOQUEADO:", e)
        print("Nao altere MCP, Nginx nem Keycloak; informe somente o CODIGO de erro exibido.")
        sys.exit(2)
    except Exception:
        print("BLOQUEADO: FALHA_INESPERADA (detalhes ocultados para nao expor credenciais)")
        sys.exit(2)
