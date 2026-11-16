#!/usr/bin/env python3
"""Safe overlay for the ACS overview, preserving customized dashboard markup.

Installs only one new read-only library and small opt-in blocks in three APIs.
No database flag, scheduled job, web layout, credential or device modification.
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
DEFAULT_BACKUPS = Path('/var/backups/jrc-acs-overview')
LIBRARY = 'lib/JrcDashboardSnapshot.php'
ENDPOINTS = (
    'api/dashboard-stats.php',
    'api/uplink-stats.php',
    'api/recent-devices.php',
)
ANCHOR = 'requireLogin();'
START = '// JRC OVERVIEW SNAPSHOT FAST PATH'
END = '// END JRC OVERVIEW SNAPSHOT FAST PATH'


class UnsafeInstallation(RuntimeError):
    pass


def source_block(source: Path, filename: str) -> str:
    text = (source / filename).read_text(encoding='utf-8')
    if text.count(START) != 1 or text.count(END) != 1:
        raise UnsafeInstallation('Bloco de fonte ausente/ambiguo: ' + filename)
    start = text.index(START)
    end = text.index(END, start) + len(END)
    block = text[start:end]
    if "JrcDashboardSnapshot::response(getDBConnection()" not in block:
        raise UnsafeInstallation('Implementacao inesperada: ' + filename)
    if "getConfig('jrc_dashboard_cache_enabled')" not in block:
        raise UnsafeInstallation('Flag opt-in ausente: ' + filename)
    return block


def patch_endpoint(before: bytes, block: str, filename: str) -> bytes:
    try:
        text = before.decode('utf-8')
    except UnicodeDecodeError as exc:
        raise UnsafeInstallation('API nao e UTF-8: ' + filename) from exc
    if text.count(START) != 0 or text.count(END) != 0:
        if text.count(START) != 1 or text.count(END) != 1:
            raise UnsafeInstallation('Cache parcialmente instalado: ' + filename)
        from_pos = text.index(START)
        to_pos = text.index(END, from_pos) + len(END)
        if text[from_pos:to_pos] != block:
            raise UnsafeInstallation('Cache customizado, nao sobrepor: ' + filename)
        return before
    if text.count(ANCHOR) != 1:
        raise UnsafeInstallation('Autenticacao diferente/ambigua: ' + filename)
    if 'Content-Type: application/json' not in text:
        raise UnsafeInstallation('Resposta nao e JSON: ' + filename)
    if "JrcDashboardSnapshot" in text:
        raise UnsafeInstallation('Codigo de cache parcialmente presente: ' + filename)
    # Inject only after successful session validation, before legacy NBI work.
    return text.replace(ANCHOR, ANCHOR + '\n\n' + block + '\n', 1).encode('utf-8')


def php_lint(name: str, source_bytes: bytes) -> None:
    with tempfile.NamedTemporaryFile(suffix='.php') as tmp:
        tmp.write(source_bytes)
        tmp.flush()
        proc = subprocess.run(['php', '-l', tmp.name], text=True, capture_output=True)
    if proc.returncode:
        raise UnsafeInstallation('PHP lint falhou: ' + name + ': ' + proc.stderr.strip())


def prepare(source: Path, root: Path) -> dict[str, tuple[bytes | None, bytes]]:
    if not (root / 'config/config.php').is_file():
        raise UnsafeInstallation('Diretorio ACS nao encontrado/configurado')
    changes = {}
    for name in (LIBRARY,) + ENDPOINTS:
        target = root / name
        if target.is_symlink():
            raise UnsafeInstallation('Destino simbolico recusado: ' + name)
        if name in ENDPOINTS and not target.is_file():
            raise UnsafeInstallation('API nao encontrada: ' + name)
        before = target.read_bytes() if target.exists() else None
        if name == LIBRARY:
            after = (source / name).read_bytes()
            if before is not None and before != after:
                raise UnsafeInstallation('Modulo JRC customizado: ' + name)
        else:
            after = patch_endpoint(before, source_block(source, name), name)
        php_lint(name, after)
        if after != before:
            changes[name] = (before, after)
    return changes


def apply(root: Path, backups: Path, changes: dict[str, tuple[bytes | None, bytes]]) -> Path | None:
    if not changes:
        return None
    destination = backups / datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')
    destination.mkdir(mode=0o700, parents=True, exist_ok=False)
    installed = []
    try:
        for name, (before, after) in changes.items():
            path = root / name
            if path.is_symlink() or (path.read_bytes() if path.exists() else None) != before:
                raise UnsafeInstallation('Modificacao concorrente: ' + name)
            if before is not None:
                backup = destination / name
                backup.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
                shutil.copy2(path, backup)
            path.parent.mkdir(parents=True, exist_ok=True)
            fd, temp = tempfile.mkstemp(prefix='.jrc-overview-', dir=path.parent)
            try:
                with os.fdopen(fd, 'wb') as f:
                    f.write(after)
                    f.flush()
                    os.fsync(f.fileno())
                if before is None:
                    os.chmod(temp, 0o644)
                else:
                    stat = path.stat()
                    os.chmod(temp, stat.st_mode & 0o777)
                    os.chown(temp, stat.st_uid, stat.st_gid)
                os.replace(temp, path)
                installed.append(name)
            finally:
                if os.path.exists(temp):
                    os.unlink(temp)
        (destination / 'new-files.txt').write_text(
            '\n'.join(name for name, (before, _) in changes.items() if before is None),
            encoding='utf-8',
        )
    except BaseException:
        for name in reversed(installed):
            path = root / name
            if changes[name][0] is None:
                path.unlink(missing_ok=True)
            else:
                shutil.copy2(destination / name, path)
        raise
    return destination


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument('mode', choices=('plan', 'apply'))
    parser.add_argument('--root', type=Path, default=DEFAULT_ROOT)
    parser.add_argument('--backups', type=Path, default=DEFAULT_BACKUPS)
    args = parser.parse_args()
    changes = prepare(SOURCE, args.root)
    for name in changes:
        print('ARQUIVO:', name)
    print('Sem alterar dashboard.php, layout, firmware, TR-069, MCP, cron ou flags.')
    if args.mode == 'plan':
        print('PLAN_OK' if changes else 'JA_INSTALADO')
    else:
        backup = apply(args.root, args.backups, changes)
        print('APLICADO_OK' + ('; backup=' + str(backup) if backup else '; JA_INSTALADO'))


if __name__ == '__main__':
    main()
