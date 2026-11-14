#!/usr/bin/env python3
"""Safe installer for firmware repository catalog. Does NOT trigger device updates.
Usage:
  python3 scripts/deploy-firmware-repository.py plan
  sudo python3 scripts/deploy-firmware-repository.py apply
Optional: --php-user=www-data (change if PHP-FPM uses another account).
"""
import datetime
import os
import pwd
import shutil
import subprocess
import sys
from pathlib import Path

WEB = Path("/var/www/gacs")
HEADER = WEB / "views/layouts/header.php"
TARGET = WEB / "firmware-library.php"
SOURCE = Path(__file__).resolve().parent.parent / "firmware-library.php"
STORE = Path("/var/lib/jrc-acs-firmware")
FROM = 'href="/firmware-manager.php"'
TO = 'href="/firmware-library.php"'

def fail(message):
    raise SystemExit("ABORTADO: " + message)

def main():
    args = sys.argv[1:]
    if not args or args[0] not in ("plan", "apply") or len(args) > 2:
        fail("uso: plan|apply [--php-user=www-data]")
    mode = args[0]
    user_name = "www-data"
    if len(args) == 2:
        if not args[1].startswith("--php-user="):
            fail("opcao desconhecida")
        user_name = args[1].split("=", 1)[1]
    try:
        user = pwd.getpwnam(user_name)
    except KeyError:
        fail("usuario PHP nao encontrado: " + user_name)
    if not HEADER.is_file() or not SOURCE.is_file():
        fail("menu ou fonte ausente")
    if TARGET.exists() or TARGET.is_symlink():
        fail("pagina firmware-library.php ja existe; nao sobrescrever")
    if STORE.exists() or STORE.is_symlink():
        fail("repositorio de arquivos ja existe; revisar para preservar conteudo")
    before = HEADER.read_bytes()
    text = before.decode("utf-8")
    lines = text.splitlines(keepends=True)
    positions = [i for i, line in enumerate(lines)
                 if 'id="nav-firmware"' in line and '<a ' in line]
    if len(positions) != 1:
        fail("menu Firmware nao identificado de forma unica")
    i = positions[0]
    if lines[i].count(FROM) != 1:
        fail("menu Firmware nao aponta para a pagina anterior conhecida")
    lines[i] = lines[i].replace(FROM, TO, 1)
    new_header = "".join(lines)
    print("MENU: /firmware-manager.php -> /firmware-library.php")
    print("PAGINA ANTERIOR: preservada")
    print("ARMAZENAMENTO: " + str(STORE) + " (fora da raiz web)")
    print("USUARIO_PHP_CONFIGURADO: " + user_name + " (confirmar com PHP-FPM/Apache)")
    print("GENIEACS: nenhuma operacao")
    if mode == "plan":
        print("PLAN_OK: nenhuma alteracao feita")
        return
    if os.geteuid() != 0:
        fail("apply deve executar com sudo")
    now = datetime.datetime.now(datetime.timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    backup = HEADER.with_name("header.php.before-fw-repository-" + now + ".bak")
    if backup.exists():
        fail("backup ja existe")
    temp_page = WEB / (".firmware-library.php." + str(os.getpid()))
    temp_header = HEADER.with_name(".header.php.fwrepo." + str(os.getpid()))
    created_store = False
    installed_page = False
    try:
        # Prepare and lint both files before touching live files.
        shutil.copy2(SOURCE, temp_page)
        temp_header.write_text(new_header, encoding="utf-8")
        mode_stat = HEADER.stat()
        os.chown(temp_header, mode_stat.st_uid, mode_stat.st_gid)
        os.chmod(temp_header, mode_stat.st_mode & 0o777)
        os.chown(temp_page, mode_stat.st_uid, mode_stat.st_gid)
        os.chmod(temp_page, 0o644)
        for p in (temp_header, temp_page):
            check = subprocess.run(["php", "-l", str(p)], capture_output=True, text=True)
            if check.returncode != 0:
                fail("php -l: " + p.name + ": " + check.stderr.strip())
        if HEADER.read_bytes() != before or TARGET.exists() or STORE.exists():
            fail("conflito com alteracoes em producao")
        shutil.copy2(HEADER, backup)
        STORE.mkdir(mode=0o750, parents=False)
        created_store = True
        os.chown(STORE, user.pw_uid, user.pw_gid)
        os.chmod(STORE, 0o750)
        os.replace(temp_page, TARGET)
        installed_page = True
        os.replace(temp_header, HEADER)
        print("APLICADO_OK")
        print("BACKUP:", backup)
        print("URL: /firmware-library.php")
        print("NOTA: confirme que PHP executa como " + user_name)
    except BaseException:
        if installed_page:
            TARGET.unlink(missing_ok=True)
        if created_store and STORE.is_dir() and not list(STORE.iterdir()):
            STORE.rmdir()
        raise
    finally:
        temp_page.unlink(missing_ok=True)
        temp_header.unlink(missing_ok=True)

if __name__ == "__main__":
    main()
