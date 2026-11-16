#!/usr/bin/env python3
"""Opt-in, reversible overlay for the customized JR Conect dashboard.

Only install two new read-only PHP/JS files, a new classifier helper, and
one defer script include in dashboard.php. Never replace any customized
page/IXC/TR-069 API, cron, credentials or DB configuration.
"""
from __future__ import annotations

import argparse
from datetime import datetime, timezone
import os
from pathlib import Path
import shutil
import subprocess
import tempfile

SOURCE = Path(__file__).resolve().parent.parent
DEFAULT_ROOT = Path('/var/www/gacs')
DEFAULT_BACKUPS = Path('/var/backups/jrc-acs-router-signal')
NEW_FILES = (
    'lib/JrcEquipmentClassification.php',
    'api/jrc-router-signal-summary.php',
    'assets/js/jrc-router-signal.js',
)
DASHBOARD = 'dashboard.php'
INCLUDE = '<script defer src="/assets/js/jrc-router-signal.js?v=1"></script>'
MARK = '/assets/js/jrc-router-signal.js'


class UnsafeInstall(RuntimeError):
    pass


def syntax_check(path: str, data: bytes) -> None:
    if not path.endswith('.php'):
        return
    with tempfile.NamedTemporaryFile(suffix='.php') as temp:
        temp.write(data)
        temp.flush()
        proc = subprocess.run(
            ['php', '-l', temp.name],
            capture_output=True, text=True, check=False,
        )
    if proc.returncode:
        raise UnsafeInstall('PHP invalido: ' + path)


def dashboard_patch(before: bytes) -> bytes:
    try:
        text = before.decode('utf-8')
    except UnicodeDecodeError as exc:
        raise UnsafeInstall('Dashboard nao esta em UTF-8') from exc
    if MARK in text:
        if text.count(INCLUDE) != 1 or text.count(MARK) != 1:
            raise UnsafeInstall('Marcador encontrado, mas script diferente: nao sobrepor')
        return before
    if text.lower().count('</body>') != 1:
        raise UnsafeInstall('Dashboard sem </body> unico: nao modificar')
    if 'dashboard' not in text.lower():
        raise UnsafeInstall('Pagina inesperada: dashboard')
    close = text.lower().rfind('</body>')
    return (text[:close] + INCLUDE + '\n' + text[close:]).encode('utf-8')


def plan(root: Path) -> dict[str, tuple[bytes | None, bytes]]:
    if not (root / 'config/config.php').is_file():
        raise UnsafeInstall('ACS nao encontrado')
    changes = {}
    for name in NEW_FILES + (DASHBOARD,):
        target = root / name
        if target.is_symlink():
            raise UnsafeInstall('Arquivo simbolico recusado: ' + name)
        if name == DASHBOARD:
            if not target.is_file():
                raise UnsafeInstall('Dashboard personalizado nao encontrado')
            before = target.read_bytes()
            after = dashboard_patch(before)
        else:
            source = SOURCE / name
            if not source.is_file():
                raise UnsafeInstall('Fonte nao encontrada: ' + name)
            after = source.read_bytes()
            before = target.read_bytes() if target.exists() else None
            if before is not None and before != after:
                raise UnsafeInstall('Arquivo customizado recusado: ' + name)
        syntax_check(name, after)
        if after != before:
            changes[name] = (before, after)
    return changes


def apply(root: Path, backups: Path,
          changes: dict[str, tuple[bytes | None, bytes]]) -> Path | None:
    if not changes:
        return None
    dest = backups / datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')
    dest.mkdir(parents=True, mode=0o700, exist_ok=False)
    installed = []
    try:
        for name, (before, after) in changes.items():
            target = root / name
            if target.is_symlink() or (
                (target.read_bytes() if target.exists() else None) != before
            ):
                raise UnsafeInstall('Arquivo alterado durante a instalacao: ' + name)
            if before is not None:
                saved = dest / name
                saved.parent.mkdir(parents=True, mode=0o700, exist_ok=True)
                shutil.copy2(target, saved)
            target.parent.mkdir(parents=True, exist_ok=True)
            fd, tmp = tempfile.mkstemp(prefix='.jrc-router-', dir=target.parent)
            try:
                with os.fdopen(fd, 'wb') as out:
                    out.write(after)
                    out.flush()
                    os.fsync(out.fileno())
                if before is None:
                    os.chmod(tmp, 0o644)
                else:
                    st = target.stat()
                    os.chmod(tmp, st.st_mode & 0o777)
                    os.chown(tmp, st.st_uid, st.st_gid)
                os.replace(tmp, target)
                installed.append(name)
            finally:
                if os.path.exists(tmp):
                    os.unlink(tmp)
        (dest / 'created-files.txt').write_text(
            '\n'.join(n for n in NEW_FILES if changes.get(n, (1,))[0] is None),
            encoding='utf-8',
        )
        return dest
    except BaseException:
        for name in reversed(installed):
            target = root / name
            if changes[name][0] is None:
                target.unlink(missing_ok=True)
            else:
                shutil.copy2(dest / name, target)
        raise


def main() -> None:
    args_parser = argparse.ArgumentParser()
    args_parser.add_argument('mode', choices=['plan', 'apply'])
    args_parser.add_argument('--root', type=Path, default=DEFAULT_ROOT)
    args_parser.add_argument('--backups', type=Path, default=DEFAULT_BACKUPS)
    args = args_parser.parse_args()

    changes = plan(args.root)
    for name in changes:
        print('ARQUIVO:', name)
    print('Mantidos: IXC, TR-069, firmware, WAN, Wi-Fi, cron, MCP e flags.')
    if args.mode == 'plan':
        print('PLAN_OK' if changes else 'JA_INSTALADO')
    else:
        dest = apply(args.root, args.backups, changes)
        print('APLICADO_OK' + ('; backup=' + str(dest) if dest else '; JA_INSTALADO'))


if __name__ == '__main__':
    main()
