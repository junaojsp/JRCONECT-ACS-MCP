#!/usr/bin/env python3
"""Hide two redundant ACS sidebar entries without changing their routes or backend.

Usage:
  sudo python3 scripts/acs-hide-redundant-menu.py plan
  sudo python3 scripts/acs-hide-redundant-menu.py apply

Production has a customized header that differs from this Git repository.
This script edits only an isolated CSS block, preserves all other bytes and
backs up the original before an atomic replacement.
"""
import argparse
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
from datetime import datetime, timezone

HEADER = Path("/var/www/gacs/views/layouts/header.php")
START = "/* JRC_ACS_HIDE_REDUNDANT_MENU_V1_START */"
END = "/* JRC_ACS_HIDE_REDUNDANT_MENU_V1_END */"
CSS = """<style>
/* JRC_ACS_HIDE_REDUNDANT_MENU_V1_START */
/* UI only: retain diagnostics in device-detail and preserve ONU provisioning. */
li:has(> a#nav-diagnostico),
li:has(> a[href="/provisionamento-onu.php"]) {
    display: none !important;
}
/* Fallback for browsers without :has(): suppress links themselves. */
a#nav-diagnostico,
a[href="/provisionamento-onu.php"] {
    display: none !important;
}
/* JRC_ACS_HIDE_REDUNDANT_MENU_V1_END */
</style>
"""

def fail(message):
    raise SystemExit("ABORTADO (nenhuma alteracao): " + message)

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("action", choices=("plan", "apply"))
    args = parser.parse_args()
    if not HEADER.is_file():
        fail("header de producao nao encontrado")
    original = HEADER.read_bytes()
    try:
        source = original.decode("utf-8")
    except UnicodeDecodeError:
        fail("header nao e UTF-8")
    if source.count(START) or source.count(END):
        if source.count(START) == 1 and source.count(END) == 1:
            print("JA_APLICADO: bloco de ocultacao existente. Nenhuma alteracao.")
            return
        fail("marcadores de ocultacao inconsistentes")
    # Fail closed instead of modifying an unexpected version of the sidebar.
    diag = len(re.findall(r'<a\b[^>]*\bid=["\']nav-diagnostico["\']', source, re.I))
    onu = len(re.findall(r'<a\b[^>]*\bhref=["\']/provisionamento-onu\.php["\']', source, re.I))
    if diag != 1 or onu != 1:
        fail("menu diverge (diagnostico=%d, provisionamento=%d); revisar manualmente" % (diag, onu))
    if source.count("</head>") != 1:
        fail("fechamento head nao e unico")
    changed = source.replace("</head>", CSS + "</head>", 1)
    print("PREVIEW: ocultar apenas menu Diagnostico e Provisionamento ONU.")
    print("DISPOSITIVOS, TAREFAS ACS, PRESETS, SCRIPTS, API E MCP: intocados.")
    if args.action == "plan":
        print("PLAN_OK: pronto para aplicar, nenhuma escrita feita.")
        return
    if os.geteuid() != 0:
        fail("execute apply com sudo")
    backup = HEADER.with_name("header.php.before-hide-menu-" + datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ") + ".bak")
    if backup.exists():
        fail("backup ja existe")
    temp = HEADER.with_name(".header.php.hide-menu.%d" % os.getpid())
    try:
        shutil.copy2(HEADER, backup)
        temp.write_bytes(changed.encode("utf-8"))
        st = HEADER.stat()
        os.chown(temp, st.st_uid, st.st_gid)
        os.chmod(temp, st.st_mode & 0o777)
        lint = subprocess.run(["php", "-l", str(temp)], capture_output=True, text=True)
        if lint.returncode != 0:
            fail("php -l reprovou o arquivo temporario: " + lint.stdout + lint.stderr)
        # Guard against concurrent production changes.
        if HEADER.read_bytes() != original:
            fail("header modificado concorrentemente")
        os.replace(temp, HEADER)
        print("APLICADO_OK: menu ocultado, sem excluir funcionalidades.")
        print("BACKUP: " + str(backup))
    finally:
        if temp.exists():
            temp.unlink()

if __name__ == "__main__":
    main()
