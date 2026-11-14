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
    # Production uses a PHP-computed href. Match the specific nav-firmware
    # anchor by id, on its own source line, without HTML regex parsing of ?>.
    lines = src.splitlines(keepends=True)
    indexes = [i for i, line in enumerate(lines)
               if re.search(r'<a\\s+id=["\\']nav-firmware["\\']', line)]
    if len(indexes) != 1:
        die("esperada uma unica ancora nav-firmware")
    idx = indexes[0]
    line = lines[idx]
    if not any(re.search(r'<span>\\s*Firmware\\s*</span>', x, re.I)
               for x in lines[idx+1:idx+5]):
        die("rotulo Firmware nao corresponde ao id")
    expected = 'href="<?php echo htmlspecialchars($firmwareHref, ENT_QUOTES, \'UTF-8\'); ?>"'
    if line.count(expected) == 1:
        # The dynamic original route resolves to device detail/firmware
        # when an ONU id is known, and /devices.php otherwise.
        old_href = "/devices.php"
        old_menu_expression = "PHP firmwareHref (device detail or devices.php)"
        changed_line = line.replace(expected, 'href="/firmware-manager.php"', 1)
    else:
        literal = re.findall(r'href="(/[A-Za-z0-9_-]+\\.php)"', line)
        if len(literal) != 1 or literal[0] == "/firmware-manager.php":
            die("rota Firmware desconhecida; manter producao intacta")
        old_href = literal[0]
        old_menu_expression = old_href
        changed_line = line.replace('href="' + old_href + '"',
                                    'href="/firmware-manager.php"', 1)
    if not (WEB / old_href.lstrip("/")).is_file():
        die("rota de retorno anterior inexistente")
    if changed_line == line:
        die("ancora nao alterada")
    lines[idx] = changed_line
    new_header = "".join(lines)
    # Preserve legacy workflow by returning to Devices, where the operator
    # selects an ONU and opens its already existing firmware tab.
    link = ('<p><a class="btn btn-outline-info btn-sm" href="'
            + html.escape(old_href, quote=True)
            + '">Abrir Dispositivos e o firmware da ONU (fluxo anterior)</a></p>')
    new_page = page.replace(MARKER, link)
    print("MENU_ATUAL:", old_menu_expression)
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
