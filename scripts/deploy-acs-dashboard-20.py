#!/usr/bin/env python3
"""Deploy only JR Conect dashboard 2.0 pilot styling; preserve all device routes."""
import argparse
import os
import shutil
import subprocess
import tempfile
from datetime import datetime, timezone
from pathlib import Path

SOURCE = Path(__file__).resolve().parent.parent
TARGET = Path('/var/www/gacs')
FILES = ('dashboard-light.php', 'assets/js/jrc-dashboard-light.js',
         'assets/css/jrc-dashboard-20.css',
         'api/jrc-dashboard-reference-charts.php',
         'cron/collect-client-tr069-coverage.php')
ANCHORS = {
    'dashboard-light.php': ('jrc-dashboard-20.css?v=1', 'jrc-dashboard-light.js?v=2', 'jrc20-eyebrow'),
    'assets/js/jrc-dashboard-light.js': ('jrc-dashboard-reference-charts.php', 'jrc20-refresh', 'Promise.allSettled'),
    'assets/css/jrc-dashboard-20.css': ('JR Conect ACS 2.0 - interface piloto isolada.', '--jrc20-card:#1b2231', '.jrc-overview-light .jrc20-footer'),
    'api/jrc-dashboard-reference-charts.php': ('jrc_background_inventory', 'client_tr069_coverage', 'ixc_distinct_eligible_clients_not_integrated'),
}

def check():
    if not (TARGET / 'config/config.php').is_file():
        raise RuntimeError('ACS nao encontrado em /var/www/gacs')
    changes = []
    for relative in FILES:
        src, dst = SOURCE / relative, TARGET / relative
        if not src.is_file() or dst.is_symlink():
            raise RuntimeError('Arquivo de origem ausente ou destino inseguro: ' + relative)
        incoming = src.read_bytes()
        if relative.endswith('.php') and subprocess.run(
            ['php', '-l', str(src)], capture_output=True
        ).returncode:
            raise RuntimeError('PHP invalido: ' + relative)
        current = dst.read_bytes() if dst.is_file() else None
        if current == incoming:
            continue
        if current is None and relative not in ('assets/css/jrc-dashboard-20.css', 'cron/collect-client-tr069-coverage.php'):
            raise RuntimeError('Pagina piloto nao instalada: ' + relative)
        if current is not None and relative in ANCHORS:
            if not all(marker.encode() in current for marker in ANCHORS[relative]):
                raise RuntimeError('Customizacao inesperada: ' + relative)
        changes.append((relative, dst, current, incoming))
    return changes

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('mode', choices=('plan', 'apply'))
    args = parser.parse_args()
    changes = check()
    if args.mode == 'plan':
        for name, _, _, _ in changes: print('ALTERAR:', name)
        print('PLAN_OK' if changes else 'JA_INSTALADO')
        return
    backup = Path('/var/backups/jrc-dashboard-20') / datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')
    backup.mkdir(parents=True, exist_ok=False, mode=0o700)
    applied = []
    try:
        for name, dst, old, new in changes:
            if old is not None:
                target_backup = backup / name
                target_backup.parent.mkdir(parents=True, exist_ok=True)
                shutil.copy2(dst, target_backup)
            dst.parent.mkdir(parents=True, exist_ok=True)
            fd, staged = tempfile.mkstemp(prefix='.jrc20-', dir=dst.parent)
            try:
                with os.fdopen(fd, 'wb') as stream:
                    stream.write(new)
                    stream.flush()
                    os.fsync(stream.fileno())
                os.chmod(staged, dst.stat().st_mode & 0o777 if old is not None else 0o644)
                if old is not None:
                    stat = dst.stat()
                    os.chown(staged, stat.st_uid, stat.st_gid)
                if dst.is_symlink() or (dst.read_bytes() if dst.exists() else None) != old:
                    raise RuntimeError('Alteracao concorrente: ' + name)
                os.replace(staged, dst)
                applied.append((name, dst, old))
                print('ATUALIZADO:', name)
            finally:
                if os.path.exists(staged): os.unlink(staged)
        print('APLICADO_OK')
        print('BACKUP:', backup)
        print('ESCOPO: somente dashboard-light.php e assets relacionados')
    except BaseException:
        for name, dst, old in reversed(applied):
            if old is None: dst.unlink(missing_ok=True)
            else: shutil.copy2(backup / name, dst)
        raise

if __name__ == '__main__':
    main()
