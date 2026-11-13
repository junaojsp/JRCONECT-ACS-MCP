#!/usr/bin/env python3
"""Safe, read-only ACS Opa API compatibility probe. Never prints record values or tokens."""
import json
import sys
import urllib.error
import urllib.request

BASE = "https://opa.jrconect.com"
TOKEN_FILE = "/opt/jrconect-mcp/secrets/opa/api-token"
TESTS = (
    ("canais", "/api/v1/canal-comunicacao/", {"status": "A"}),
    ("usuarios", "/api/v1/usuario/", {"status": "A"}),
    ("templates", "/api/v1/template", {}),
    ("contatos", "/api/v1/contato/", {}),
)

class RejectRedirects(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        raise ValueError("REDIRECT_BLOCKED")

def query(opener, token, path, filt, skip):
    body = json.dumps({"filter": filt, "options": {"limit": 1, "skip": skip}}).encode()
    req = urllib.request.Request(
        BASE + path,
        data=body,
        method="GET",
        headers={
            "Authorization": "Bearer " + token,
            "Content-Type": "application/json",
            "Accept": "application/json",
        },
    )
    try:
        with opener.open(req, timeout=12) as response:
            chunk = response.read(262145)
            code = response.status
        if len(chunk) > 262144:
            return {"http": code, "error": "RESPONSE_TOO_BIG"}
        payload = json.loads(chunk)
        if not isinstance(payload, dict) or payload.get("status") != "success" or not isinstance(payload.get("data"), list):
            return {"http": code, "error": "BAD_SCHEMA"}
        rows = payload["data"]
        return {
            "http": code,
            "returned": len(rows),
            "status_all_match": (
                all(isinstance(row, dict) and row.get("status") == filt["status"] for row in rows)
                if "status" in filt else None
            ),
        }
    except urllib.error.HTTPError as error:
        return {"http": error.code, "error": "HTTP_ERROR"}
    except urllib.error.URLError:
        return {"error": "NETWORK_ERROR"}
    except (ValueError, TimeoutError, json.JSONDecodeError):
        return {"error": "INVALID_RESPONSE_OR_REDIRECT"}

def main():
    print("=== OPA API: DIAGNOSTICO DE PAGINACAO, SOMENTE LEITURA ===")
    try:
        with open(TOKEN_FILE, encoding="utf-8") as secret:
            token = secret.read().strip()
        if not token or any(ch.isspace() for ch in token):
            raise ValueError("invalid token")
    except (OSError, ValueError):
        print("TOKEN: AUSENTE OU INVALIDO (sem detalhes)")
        return 2
    opener = urllib.request.build_opener(RejectRedirects)
    for name, path, filt in TESTS:
        first = query(opener, token, path, filt, 0)
        print(name + " primeira pagina:", json.dumps(first, sort_keys=True))
        if first.get("http") != 200 or first.get("error") or not first.get("returned"):
            continue
        n = first["returned"]
        second = query(opener, token, path, filt, n)
        print(name + " apos skip(" + str(n) + "):", json.dumps(second, sort_keys=True))
        if second.get("http") == 200 and not second.get("error"):
            print(name + " terminal:", "CONFIRMADO" if second["returned"] == 0 else "NAO CONFIRMADO")
    print("=== FIM: SEM ESCRITA, SEM ALTERACOES EM SERVICOS ===")
    return 0

if __name__ == "__main__":
    sys.exit(main())
