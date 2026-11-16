#!/usr/bin/env python3
"""Safely upgrade only the already-installed router indicator JavaScript.

The first install (PR #191) created these files under /var/www/gacs.
The original installer refuses overwriting an existing JS. This tool accepts
exactly that first Git blob or identical v2 content, never arbitrary custom JS.
No dashboard.php, footer.php, IXC, TR-069, flags or cron edits.
"""
from __future__ import annotations

import argparse
from datetime import datetime, timezone
import hashlib
import os
from pathlib import Path
import shutil
import tempfile

DEFAULT_ROOT = Path('/var/www/gacs')
DEFAULT_BACKUPS = Path('/var/backups/jrc-acs-router-signal')
SOURCE = Path(__file__).resolve().parent.parent / 'assets/js/jrc-router-signal.js'
RELATIVE = Path('assets/js/jrc-router-signal.js')

# Original JS blob introduced in PR #189 and installed by PR #191.
FIRST_INSTALL_GIT_BLOB = '3578515095161b1b882eba626624f280b65bb379'


class UnsafeInstall(RuntimeError):
    pass


def git_blob_sha(data: bytes) -> str:
    return hashlib.sha1(b'blob ' + str(len(data)).encode() + b'\0' + data).hexdigest()


def plan(root: Path, old_sha: str = FIRST_INSTALL_GIT_BLOB) -> tuple[bytes, bytes] | None:
    if not (root / 'config/config.php').is_file():
        raise UnsafeInstall('Raiz do ACS nao encontrada')
    target = root / RELATIVE
    if target.is_symlink() or not target.is_file():
        raise UnsafeInstall('JS de roteadores nao instalado ou link simbolico')
    old = target.read_bytes()
    new = SOURCE.read_bytes()
    if old == new:
        return None
    if git_blob_sha(old) != old_sha:
        raise UnsafeInstall('Arquivo JS customizado: nao sobrepor')
    if b'jrc-router-signal' not in new or b'Roteadores' not in new:
        raise UnsafeInstall('Nova implementacao inesperada')
    return old, new


def apply(root: Path, backups: Path, changes: tuple[bytes, bytes] | None) -> Path | None:
    if changes is None:
        return None
    before, after = changes
    target = root / RELATIVE
    if target.is_symlink() or target.read_bytes() != before:
        raise UnsafeInstall('Arquivo alterado apos simulacao; nao aplicar')
    dest = backups / datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')
    dest.mkdir(parents=True, exist_ok=False, mode=0o700)
    backup = dest / RELATIVE
    backup.parent.mkdir(parents=True, mode=0o700, exist_ok=True)
    shutil.copy2(target, backup)
    fd, temp = tempfile.mkstemp(prefix='.jrc-router-v2-', dir=target.parent)
    try:
        with os.fdopen(fd, 'wb') as f:
            f.write(after)
            f.flush()
            os.fsync(f.fileno())
        meta = target.stat()
        os.chmod(temp, meta.st_mode & 0o777)
        os.chown(temp, meta.st_uid, meta.st_gid)
        if target.read_bytes() != before:
            raise UnsafeInstall('Arquivo mudou durante a preparacao')
        os.replace(temp, target)
    finally:
        if os.path.exists(temp):
            os.unlink(temp)
    return dest


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument('mode', choices=['plan', 'apply'])
    parser.add_argument('--root', type=Path, default=DEFAULT_ROOT)
    parser.add_argument('--backups', type=Path, default=DEFAULT_BACKUPS)
    args = parser.parse_args()
    changes = plan(args.root)
    if args.mode == 'plan':
        print('PLAN_OK' if changes else 'JA_INSTALADO')
        if changes:
            print('ARQUIVO:', RELATIVE, '(JS isolado; sem alterar dashboard.php)')
    else:
        backup = apply(args.root, args.backups, changes)
        print('APLICADO_OK' + ('; backup=' + str(backup) if backup else '; JA_INSTALADO'))


if __name__ == '__main__':
    main()
