#!/usr/bin/env python3
"""Promote ACS dashboard 2.0 to /dashboard.php, preserving live legacy and rollback."""
import argparse
import os
import shutil
import subprocess
import tempfile
from datetime import datetime, timezone
from pathlib import Path

SRC = Path(__file__).resolve().parent.parent
WEB = Path('/var/www/gacs')
FILES = ('dashboard.php', 'dashboard-light.php', 'views/layouts/header.php', 'index.php')
LINKS = {
    'dashboard-light.php': (b'href="/dashboard.php">Vers\xc3\xa3o anterior', b'href="/dashboard-legacy.php">Vers\xc3\xa3o anterior'),
    'views/layouts/header.php': (b'href="/dashboard-light.php"', b'href="/dashboard.php"'),
    'index.php': (b"redirect('/dashboard-light.php');", b"redirect('/dashboard.php');"),
}

def php_ok(source: bytes) -> bool:
    proc = subprocess.run(['php', '-l'], input=source, capture_output=True)
    return proc.returncode == 0

def atomic_write(dst: Path, payload: bytes, reference: Path) -> None:
    fd, temp = tempfile.mkstemp(prefix='.jrc-promotion-', dir=dst.parent)
    try:
        with os.fdopen(fd, 'wb') as stream:
            stream.write(payload)
            stream.flush()
            os.fsync(stream.fileno())
        stat = reference.stat()
        os.chmod(temp, stat.st_mode & 0o777)
        os.chown(temp, stat.st_uid, stat.st_gid)
        os.replace(temp, dst)
    finally:
        if os.path.exists(temp):
            os.unlink(temp)

def inspect():
    if not (WEB / 'config/config.php').is_file():
        raise RuntimeError('Instalacao ACS nao encontrada')
    if not (WEB / 'assets/js/jrc-dashboard-history.js').is_file():
        raise RuntimeError('Dashboard 2.0 nao esta instalada')
    original = {}
    replacements = {}
    for name in FILES:
        live, repo = WEB/name, SRC/name
        if not live.is_file() or live.is_symlink() or not repo.is_file():
            raise RuntimeError('Arquivo de producao ausente ou inseguro: '+name)
        old = live.read_bytes()
        original[name] = old
        if name == 'dashboard.php':
            if b"require __DIR__ . '/dashboard-light.php';" in old:
                new = old  # already promoted
            else:
                if b'requireLogin()' not in old or b'views/layouts/header.php' not in old:
                    raise RuntimeError('Dashboard antiga personalizada nao reconhecida')
                new = repo.read_bytes()
        else:
            before, after = LINKS[name]
            if old.count(before) == 1 and old.count(after) == 0:
                new = old.replace(before, after, 1)
            elif old.count(after) == 1 and old.count(before) == 0:
                new = old
            else:
                raise RuntimeError('Marcador de rota ambiguo: '+name)
        if not php_ok(new):
            raise RuntimeError('PHP de destino invalido: '+name)
        replacements[name] = new
    legacy = WEB/'dashboard-legacy.php'
    if legacy.is_symlink() or (legacy.exists() and not legacy.is_file()):
        raise RuntimeError('Destino legacy inseguro')
    live_old = original['dashboard.php']
    promoted = b"require __DIR__ . '/dashboard-light.php';" in live_old
    if not promoted and legacy.exists() and legacy.read_bytes() != live_old:
        raise RuntimeError('dashboard-legacy.php ja existe e diverge da dashboard atual: revisao manual necessaria')
    if promoted and not legacy.is_file():
        raise RuntimeError('Dashboard ja promovida mas copia legacy ausente: revisao manual necessaria')
    return original,replacements,not promoted and not legacy.exists()

def main():
    p = argparse.ArgumentParser()
    p.add_argument('mode',choices=('plan','apply'))
    mode = p.parse_args().mode
    old,new,create_legacy=inspect()
    modified=[name for name in FILES if old[name]!=new[name]]
    if mode == 'plan':
        if create_legacy:print('PRESERVAR: dashboard.php -> dashboard-legacy.php')
        for name in modified:print('ATUALIZAR:',name)
        print('PLAN_OK' if modified or create_legacy else 'JA_PROMOVIDO')
        return
    if not modified and not create_legacy:
        print('JA_PROMOVIDO')
        return
    backup=Path('/var/backups/jrc-dashboard-production')/datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')
    backup.mkdir(parents=True,mode=0o700,exist_ok=False)
    changed=[]
    legacy_path=WEB/'dashboard-legacy.php'
    legacy_created=False
    try:
        for name in FILES:
            dest=backup/name
            dest.parent.mkdir(parents=True,exist_ok=True)
            shutil.copy2(WEB/name,dest)
        if create_legacy:
            if legacy_path.exists() or legacy_path.is_symlink():
                raise RuntimeError('Criacao legacy bloqueada: destino alterado')
            atomic_write(legacy_path,old['dashboard.php'],WEB/'dashboard.php')
            legacy_created=True
            print('LEGACY_PRESERVADO: dashboard-legacy.php')
        for name in modified:
            target=WEB/name
            if target.is_symlink() or target.read_bytes()!=old[name]:
                raise RuntimeError('Arquivo alterado durante publicacao: '+name)
            atomic_write(target,new[name],target)
            changed.append(name)
            print('ATUALIZADO:',name)
        print('PROMOVIDO_OK')
        print('BACKUP:',backup)
        print('PRODUCAO: /dashboard.php')
        print('LEGACY: /dashboard-legacy.php')
    except BaseException:
        for name in reversed(changed):
            shutil.copy2(backup/name,WEB/name)
        if legacy_created:
            legacy_path.unlink(missing_ok=True)
        raise

if __name__ == '__main__':
    main()
