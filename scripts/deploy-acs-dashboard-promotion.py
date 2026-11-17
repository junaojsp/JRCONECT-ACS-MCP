#!/usr/bin/env python3
"""Promote approved dashboard by changing only the ACS navigation and home redirect."""
import argparse
import os
import shutil
import subprocess
import tempfile
from datetime import datetime, timezone
from pathlib import Path

SOURCE = Path(__file__).resolve().parent.parent
TARGET = Path('/var/www/gacs')
FILES = {
    'views/layouts/header.php': ('href="/dashboard.php"', 'href="/dashboard-light.php"'),
    'index.php': ("redirect('/dashboard.php');", "redirect('/dashboard-light.php');"),
}
def plan():
    if not (TARGET / 'config/config.php').is_file() or not (TARGET / 'dashboard-light.php').is_file():
        raise RuntimeError('Dashboard piloto ou instalacao ACS ausente')
    pending = []
    for name, (old_link, new_link) in FILES.items():
        src = SOURCE / name
        dst = TARGET / name
        if not src.is_file() or not dst.is_file() or dst.is_symlink():
            raise RuntimeError('Arquivo inseguro ou ausente: ' + name)
        incoming = src.read_bytes()
        current = dst.read_bytes()
        if subprocess.run(['php', '-l', str(src)], capture_output=True).returncode:
            raise RuntimeError('PHP de origem invalido: ' + name)
        if current == incoming:
            continue
        before = old_link.encode()
        after = new_link.encode()
        if current.count(before) != 1 or current.count(after) != 0:
            raise RuntimeError('Menu modificado fora do esperado, nao sobrescrito: ' + name)
        # Preserve customized sidebar markup; only the unique href is replaced.
        # Do not overwrite site-specific customizations. Patch only one known target.
        replacement = current.replace(before, after, 1)
        if subprocess.run(['php', '-l'], input=replacement, capture_output=True).returncode:
            raise RuntimeError('PHP apos patch invalido: ' + name)
        pending.append((name, dst, current, replacement))
    return pending

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('mode', choices=('plan', 'apply'))
    args = parser.parse_args()
    pending = plan()
    if args.mode == 'plan':
        for name, _, _, _ in pending: print('AJUSTAR LINK:', name)
        print('PLAN_OK' if pending else 'JA_ATIVO')
        return
    if not pending:
        print('JA_ATIVO')
        return
    backup = Path('/var/backups/jrc-dashboard-promotion') / datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')
    backup.mkdir(parents=True, mode=0o700, exist_ok=False)
    changed = []
    try:
        for name, dst, original, replacement in pending:
            saved = backup / name
            saved.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(dst, saved)
            fd, temp = tempfile.mkstemp(prefix='.jrc-nav-', dir=dst.parent)
            try:
                with os.fdopen(fd, 'wb') as stream:
                    stream.write(replacement)
                    stream.flush()
                    os.fsync(stream.fileno())
                info = dst.stat()
                os.chmod(temp, info.st_mode & 0o777)
                os.chown(temp, info.st_uid, info.st_gid)
                if dst.is_symlink() or dst.read_bytes() != original:
                    raise RuntimeError('Arquivo alterado concorrentemente: ' + name)
                os.replace(temp, dst)
                changed.append((name, dst))
                print('LINK_ATUALIZADO:', name)
            finally:
                if os.path.exists(temp): os.unlink(temp)
        print('PROMOVIDO_OK')
        print('BACKUP:', backup)
        print('DASHBOARD_ANTIGA: /dashboard.php')
        print('DASHBOARD_NOVA: /dashboard-light.php')
    except BaseException:
        for name, dst in reversed(changed):
            shutil.copy2(backup / name, dst)
        raise
if __name__ == '__main__':
    main()
