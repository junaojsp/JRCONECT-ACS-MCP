#!/usr/bin/env python3
"""Install bench preflight without overwriting production firmware pages.
Usage: python3 scripts/deploy-firmware-bench.py plan|apply
"""
from pathlib import Path
from datetime import datetime, timezone
import os
import shutil
import subprocess
import sys

WEB = Path("/var/www/gacs")
LIBRARY = WEB / "firmware-library.php"
TARGET = WEB / "firmware-homologation.php"
SOURCE = Path(__file__).resolve().parent.parent / "firmware-homologation.php"
OLD = '<div class="fw-card"><h3>Proximas etapas</h3>'
NEW = ('<div class="fw-card"><h3>Homologacao de bancada</h3>'
       '<p><a href="/firmware-homologation.php" style="color:#86caff">'
       'Selecionar imagem e vincular ONU de bancada (sem executar atualizacao)</a></p></div>\n'
       + OLD)

def fail(message):
    raise SystemExit("ABORTADO: " + message)

def main():
    if len(sys.argv) != 2 or sys.argv[1] not in ("plan", "apply"):
        fail("uso: plan | apply")
    mode = sys.argv[1]
    if not SOURCE.is_file() or not LIBRARY.is_file():
        fail("fonte ou pagina de repositorio ausente")
    if TARGET.exists() or TARGET.is_symlink():
        fail("pagina de homologacao ja existe; preservar")
    original = LIBRARY.read_bytes()
    page = original.decode("utf-8")
    if page.count(OLD) != 1 or "firmware-homologation.php" in page:
        fail("estrutura de pagina desconhecida; nenhuma alteracao")
    updated = page.replace(OLD, NEW, 1)
    print("PAGINA: firmware-homologation.php (nova)")
    print("MENU: mantido")
    print("REPOSITORIO: preservado; apenas link de acesso adicionado")
    print("GENIEACS: nenhuma tarefa, upload nem execucao")
    if mode == "plan":
        print("PLAN_OK: nenhuma alteracao feita")
        return
    if os.geteuid() != 0:
        fail("apply exige sudo/root")
    ts = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    backup = LIBRARY.with_name("firmware-library.php.before-bench-" + ts + ".bak")
    if backup.exists():
        fail("backup ja existe")
    tmp_library = LIBRARY.with_name(".firmware-library.php.bench." + str(os.getpid()))
    tmp_target = TARGET.with_name(".firmware-homologation.php." + str(os.getpid()))
    target_done = False
    try:
        stat = LIBRARY.stat()
        tmp_library.write_text(updated, encoding="utf-8")
        shutil.copy2(SOURCE, tmp_target)
        for item in (tmp_library, tmp_target):
            os.chown(item, stat.st_uid, stat.st_gid)
            os.chmod(item, stat.st_mode & 0o777)
            lint = subprocess.run(["php", "-l", str(item)], capture_output=True, text=True)
            if lint.returncode != 0:
                fail("falha PHP lint: " + item.name + " " + lint.stderr.strip())
        if LIBRARY.read_bytes() != original or TARGET.exists():
            fail("pagina mudou durante preparacao")
        shutil.copy2(LIBRARY, backup)
        os.replace(tmp_target, TARGET)
        target_done = True
        os.replace(tmp_library, LIBRARY)
        print("APLICADO_OK")
        print("BACKUP:", backup)
        print("URL: /firmware-homologation.php")
    except BaseException:
        if target_done:
            TARGET.unlink(missing_ok=True)
        raise
    finally:
        tmp_library.unlink(missing_ok=True)
        tmp_target.unlink(missing_ok=True)

if __name__ == "__main__":
    main()
