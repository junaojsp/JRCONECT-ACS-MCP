#!/usr/bin/env python3
"""Repair duplicate CSRF declaration in the existing firmware bench JS.

Checks against the single canonical declaration and validates JS syntax using
Node, with the PHP expression substituted in a temporary copy for syntax check.
No change to backend, CSS, images, or other declarations.

Usage:
  python3 scripts/fix-firmware-duplicate-csrf.py plan
  sudo python3 scripts/fix-firmware-duplicate-csrf.py apply
"""
from pathlib import Path
from datetime import datetime, timezone
import os
import shutil
import subprocess
import sys
import tempfile

TARGET = Path("/var/www/gacs/firmware-homologation.php")
REFERENCE = Path(__file__).resolve().parent.parent / "firmware-homologation.php"
START = "<script>\n(function(){"
END = "</script>"
DECL = "  const csrf=<?=json_encode($_SESSION['jrc_fwb_csrf'], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;"
PHP_EXPR = "<?=json_encode($_SESSION['jrc_fwb_csrf'], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>"

def stop(msg):
    raise SystemExit("ABORTADO: " + msg)

def script(content):
    if content.count(START) != 1 or content.count(END) != 1:
        stop("script de homologacao inesperado")
    a = content.index(START)
    b = content.index(END, a) + len(END)
    return content[a:b]

def js_lint(php_source):
    # PHP interpolates a JSON token at runtime; replace only this known
    # expression for syntax validation, without modifying deployed PHP.
    raw = script(php_source)
    if raw.count(PHP_EXPR) != 1:
        stop("token CSRF no JavaScript inesperado")
    raw = raw.removeprefix("<script>").removesuffix("</script>").replace(PHP_EXPR, '"preflight-token"')
    if shutil.which("node") is None:
        print("AVISO: node indisponivel; sintaxe JS nao validada por parser. Verificadas declaracoes conhecidas.")
        return
    with tempfile.NamedTemporaryFile(mode="w", suffix=".js", encoding="utf-8") as f:
        f.write(raw)
        f.flush()
        p = subprocess.run(["node", "--check", f.name], text=True, capture_output=True)
        if p.returncode:
            stop("JS invalido: " + p.stderr.strip())

def main():
    if len(sys.argv) != 2 or sys.argv[1] not in ("plan", "apply"):
        stop("uso: plan|apply")
    mode = sys.argv[1]
    if not TARGET.is_file() or TARGET.is_symlink() or not REFERENCE.is_file():
        stop("arquivo de producao/referencia ausente")
    before = TARGET.read_bytes()
    try:
        live = before.decode("utf-8")
        reference = REFERENCE.read_text(encoding="utf-8")
    except UnicodeDecodeError:
        stop("codificacao inesperada")
    if script(reference).count(DECL) != 1:
        stop("referencia GitHub sem declaracao canonica unica")
    if script(live).count(DECL) != 2:
        stop("esperadas duas declaracoes CSRF identicas na pagina real; encontrado " + str(script(live).count(DECL)))
    if script(live).count("const confirm=get('fw-simple-confirm')") != 1:
        stop("declaracao confirm ainda incorreta; nao prosseguir")
    # Remove only the second identical line, preserving every other byte.
    lines = live.splitlines(keepends=True)
    indices = [i for i, line in enumerate(lines) if line.rstrip("\r\n") == DECL]
    if len(indices) != 2:
        stop("declaracoes CSRF nao correspondem a linhas exatas")
    del lines[indices[1]]
    fixed = "".join(lines)
    if script(fixed).count(DECL) != 1:
        stop("verificacao CSRF falhou")
    for name in ("csrf", "confirm", "catalogueOptions", "current"):
        import re
        count = len(re.findall(r"^\s*(?:const|let)\s+" + name + r"\s*=", script(fixed), re.M))
        if count != 1:
            stop("declaracao de " + name + " repetida ou ausente: " + str(count))
    js_lint(fixed)
    print("ERRO: declaracao CSRF duplicada no script da homologacao")
    print("CORRECAO: remover somente segunda declaracao CSRF")
    print("VALIDACAO: declaracoes criticas unicas; JS checado com node quando disponivel")
    print("PRESERVADOS: layout, catalogo, APIs, GenieACS e registros")
    if mode == "plan":
        print("PLAN_OK: nenhuma alteracao feita")
        return
    if os.geteuid() != 0:
        stop("apply requer root")
    stamp = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    backup = TARGET.with_name(TARGET.name + ".before-csrf-fix-" + stamp + ".bak")
    tmp = TARGET.with_name(".firmware-homologation.csrf." + str(os.getpid()) + ".tmp")
    if backup.exists():
        stop("backup ja existente")
    try:
        st = TARGET.stat()
        tmp.write_text(fixed, encoding="utf-8")
        os.chown(tmp, st.st_uid, st.st_gid)
        os.chmod(tmp, st.st_mode & 0o777)
        p = subprocess.run(["php", "-l", str(tmp)], text=True, capture_output=True)
        if p.returncode:
            stop("PHP lint falhou: " + p.stderr.strip())
        if TARGET.read_bytes() != before:
            stop("arquivo mudou durante preparacao")
        shutil.copy2(TARGET, backup)
        os.replace(tmp, TARGET)
        print("APLICADO_OK")
        print("BACKUP:", backup)
    finally:
        tmp.unlink(missing_ok=True)

if __name__ == "__main__":
    main()
