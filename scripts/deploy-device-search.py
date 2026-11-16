#!/usr/bin/env python3
"""Install the search correction against the exact production JS supplied by the operator."""
import argparse
from datetime import datetime, timezone
import hashlib
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile

SOURCE = Path(__file__).resolve().parent.parent / 'assets/js/devices-fleet.js'
EXPECTED = '463558a1ca09a6e1b9799a29550ac54a71bc51d6ed471e88d44c65303f8e6541'
VERSION = 'jrc-search-20261116-1'


def digest(data):
    return hashlib.sha256(data).hexdigest()


def prepare(root):
    js = root / 'assets/js/devices.js'
    page = root / 'devices.php'
    if any(p.is_symlink() or not p.is_file() for p in (js, page)):
        raise ValueError('JS ou página ausente, ou destino é link')
    fixed = SOURCE.read_bytes()
    before = js.read_bytes()
    if digest(before) != EXPECTED and before != fixed:
        raise ValueError('devices.js mudou desde o arquivo enviado. Nenhuma alteração aplicada.')
    original = page.read_bytes()
    text = original.decode('utf-8')
    pattern = r'(<script\b[^>]*\bsrc=[\"\x27])(/?assets/js/devices\.js)(?:\?[^\"\x27<>]*)?([\"\x27])'
    updated, count = re.subn(pattern, lambda m: m[1] + m[2] + '?v=' + VERSION + m[3], text, flags=re.I)
    if count != 1:
        raise ValueError('Referência única a devices.js não localizada; página preservada.')
    changes = {'assets/js/devices.js': (before, fixed), 'devices.php': (original, updated.encode('utf-8'))}
    if shutil.which('node'):
        subprocess.run(['node', '--check', str(SOURCE)], check=True, capture_output=True)
    else:
        print('AVISO: Node ausente no servidor; sintaxe JS validada pelos testes da PR.')
    with tempfile.NamedTemporaryFile(suffix='.php') as temp:
        temp.write(changes['devices.php'][1]); temp.flush()
        subprocess.run(['php', '-l', temp.name], check=True, capture_output=True)
    return {name: pair for name, pair in changes.items() if pair[0] != pair[1]}


def install(root, changes, backups):
    backup = backups / datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')
    backup.mkdir(parents=True, mode=0o700)
    replaced = []
    try:
        for name, (before, after) in changes.items():
            target = root / name
            if target.is_symlink() or target.read_bytes() != before:
                raise ValueError('Alteração concorrente: ' + name)
            saved = backup / name
            saved.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(target, saved)
            fd, temp = tempfile.mkstemp(prefix='.jrc-search-', dir=target.parent)
            try:
                with os.fdopen(fd, 'wb') as stream:
                    stream.write(after)
                st = target.stat()
                os.chmod(temp, st.st_mode & 0o777)
                os.chown(temp, st.st_uid, st.st_gid)
                os.replace(temp, target)
                replaced.append(name)
            finally:
                if os.path.exists(temp):
                    os.unlink(temp)
    except BaseException:
        for name in reversed(replaced):
            shutil.copy2(backup / name, root / name)
        raise
    return backup


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('mode', choices=['plan', 'apply'])
    parser.add_argument('--root', type=Path, default=Path('/var/www/gacs'))
    parser.add_argument('--backups', type=Path, default=Path('/var/backups/jrc-acs-device-search'))
    args = parser.parse_args()
    changes = prepare(args.root)
    for name in changes:
        print('ARQUIVO:', name)
    print('Layout preservado. Nenhuma alteração em ONUs, firmware, configuração ou serviços.')
    if args.mode == 'plan':
        print('PLAN_OK')
    elif changes:
        print('APLICADO_OK; backup:', install(args.root, changes, args.backups))
    else:
        print('JA_INSTALADO')


if __name__ == '__main__':
    main()
