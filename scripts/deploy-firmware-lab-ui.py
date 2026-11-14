#!/usr/bin/env python3
"""Deploy a read-only firmware lab UI into the existing production Firmware menu.

Commands:
  python3 scripts/deploy-firmware-lab-ui.py plan
  sudo python3 scripts/deploy-firmware-lab-ui.py apply

Source repository must be checked out at an approved merged commit.
Never changes firmware files, GenieACS, databases, CWMP tasks or existing legacy UI.
"""
from pathlib import Path
from datetime import datetime, timezone
import html
import os
import re
import shutil
import subprocess
import sys

WEB = Path("/var/www/gacs")
HEADER = WEB / "views/layouts/header.php"
TARGET = WEB / "firmware-manager.php"
SOURCE = Path(__file__).resolve().parent.parent / "firmware-manager.php"
MARKER = "<!-- JRC_FIRMWARE_LEGACY_LINK -->"

def die(message):
    raise SystemExit("ABORTADO: " + message)

def main():
    if len(sys.argv) != 2 or sys.argv[1] not in ("plan", "apply"):
        die("uso: plan | apply")
    action = sys.argv[1]
    if not HEADER.is_file() or not SOURCE.is_file():
        die("arquivo fonte ou menu de producao ausente")
    if TARGET.exists():
        die("firmware-manager.php ja existe: preservar e revisar manualmente")
    original = HEADER.read_bytes()
    try:
        src = original.decode("utf-8")
        page = SOURCE.read_text(encoding="utf-8")
    except UnicodeError:
        die("codificacao inesperada")
    if page.count(MARKER) != 1:
        die("placeholder de acesso legado ausente ou duplicado")
    anchors = list(re.finditer(r"<a\b(?P<attrs>[^>]*)>(?P<body>(?:(?!</a>).)*?)</a>", src, re.I | re.S))
    matched = [m for m in anchors if re.search(r"<span\b[^>]*>\s*Firmware\s*</span>", m.group("body"), re.I)]
    if len(matched) != 1:
        die("esperada uma unica entrada Firmware no menu, encontrados: " + str(len(matched)))
    match = matched[0]
    attr = match.group("attrs")
    href_matches = list(re.finditer(r'\bhref\s*=\s*["\'](?P<path>[^"\']+)["\']', attr, re.I))
    if len(href_matches) != 1:
        die("href da entrada Firmware ambiguo")
    old_href = href_matches[0].group("path")
    if not re.fullmatch(r"/[a-zA-Z0-9_-]+\.php", old_href) or old_href == "/firmware-manager.php":
        die("rota anterior de Firmware inesperada")
    if not (WEB / old_href.lstrip("/")).is_file():
        die("rota de Firmware antiga inexistente; nao substitui menu")
    new_attrs = attr[:href_matches[0].start("path")] + "/firmware-manager.php" + attr[href_matches[0].end("path"):]
    old_anchor = match.group(0)
    new_anchor = "<a" + new_attrs + ">" + match.group("body") + "</a>"
    new_header = src[:match.start()] + new_anchor + src[match.end():]
    link = '<p><a class="btn btn-outline-info btn-sm" href="' + html.escape(old_href, quote=True) + '">Abrir gerenciamento de firmware anterior</a></p>'
    new_page = page.replace(MARKER, link)
    print("MENU_ATUAL:", old_href)
    print("MENU_NOVO: /firmware-manager.php")
    print("ACESSO_LEGADO: preservado na nova pagina")
    print("ACOES_GENIEACS: nenhuma")
    if action == "plan":
        print("PLAN_OK: nenhuma alteracao feita")
        return
    if os.geteuid() != 0:
        die("execute apply com sudo")
    backup = HEADER.with_name("header.php.before-firmware-lab-" + datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ") + ".bak")
    if backup.exists():
        die("nome de backup ja existente")
    temp_header = HEADER.with_name(".header.php.firmware-lab." + str(os.getpid()))
    temp_page = WEB / (".firmware-manager.php." + str(os.getpid()))
    try:
        shutil.copy2(HEADER, backup)
        temp_header.write_text(new_header, encoding="utf-8")
        temp_page.write_text(new_page, encoding="utf-8")
        stat = HEADER.stat()
        os.chown(temp_header, stat.st_uid, stat.st_gid)
        os.chmod(temp_header, stat.st_mode & 0o777)
        os.chown(temp_page, stat.st_uid, stat.st_gid)
        os.chmod(temp_page, 0o644)
        for item in (temp_header, temp_page):
            check = subprocess.run(["php", "-l", str(item)], capture_output=True, text=True)
            if check.returncode:
                die("php -l falhou para " + item.name)
        if HEADER.read_bytes() != original or TARGET.exists():
            die("conflito: arquivos alterados durante instalacao")
        os.replace(temp_page, TARGET)
        try:
            os.replace(temp_header, HEADER)
        except Exception:
            TARGET.unlink(missing_ok=True)
            raise
        print("APLICADO_OK")
        print("BACKUP:", backup)
        print("PAGINA:", TARGET)
    finally:
        temp_header.unlink(missing_ok=True)
        temp_page.unlink(missing_ok=True)

if __name__ == "__main__":
    main()
