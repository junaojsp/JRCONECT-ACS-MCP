#!/usr/bin/env python3
"""Apply only the inventory collector backend to an existing, customized GACS panel.

No cron schedule, database flag, credentials, firmware, JavaScript or ONU tasks.
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
DEFAULT_BACKUPS = Path('/var/backups/jrc-acs-inventory')
NEW_FILES = [
    'lib/JrcDeviceInventoryCache.php',
    'lib/JrcIxcInventoryReader.php',
    'cron/collect-device-inventory.php',
    'api/device-inventory-status.php',
]
API_PATH = 'api/get-devices.php'
INJECT_START = '// O cache e opt-in: sem snapshot valido, comportamento legado permanece.'
INJECT_END = '// Get pagination parameters (optional)'


class UnsafeInstallation(RuntimeError):
    pass


def block_from_source(source: Path) -> str:
    text = (source / API_PATH).read_text(encoding='utf-8')
    if text.count(INJECT_START) != 1 or text.count(INJECT_END) != 1:
        raise UnsafeInstallation('Marcadores esperados ausentes na API de origem')
    start, end = text.index(INJECT_START), text.index(INJECT_END)
    block = text[start:end]
    if 'jrc_inventory_cache_enabled' not in block or 'JrcDeviceInventoryCache::page' not in block:
        raise UnsafeInstallation('Bloco cache nao corresponde a implementacao esperada')
    return block


def overlay_api(before: bytes, injection: str) -> bytes:
    try:
        text = before.decode('utf-8')
    except UnicodeDecodeError as exc:
        raise UnsafeInstallation('API instalada nao e UTF-8') from exc
    if INJECT_START in text:
        start = text.index(INJECT_START)
        if INJECT_END not in text[start:]:
            raise UnsafeInstallation('Bloco de cache existente esta incompleto')
        end = text.index(INJECT_END, start)
        if text[start:end] != injection:
            raise UnsafeInstallation('Bloco cache modificado em producao: nao sobrepor')
        return before
    if text.count(INJECT_END) != 1:
        raise UnsafeInstallation('Marcador de paginacao ausente ou ambiguo em producao')
    prefix = text[:text.index(INJECT_END)]
    required = [
        'requireLogin();',
        '$conn = getDBConnection();',
        '$genieacs = new GenieACS(',
        '$credentials = $result->fetch_assoc();',
    ]
    if any(prefix.count(item) != 1 for item in required):
        raise UnsafeInstallation('Estrutura de autenticacao/GenieACS diferente em producao')
    if 'JrcDeviceInventoryCache' in text:
        raise UnsafeInstallation('Referencias parciais ao cache ja existem')
    return text.replace(INJECT_END, injection + INJECT_END, 1).encode('utf-8')


def lint_php(path: str, content: bytes) -> None:
    with tempfile.NamedTemporaryFile(suffix='.php') as tmp:
        tmp.write(content)
        tmp.flush()
        result = subprocess.run(['php', '-l', tmp.name], text=True, capture_output=True)
        if result.returncode != 0:
            raise UnsafeInstallation(
                f'PHP lint falhou: {path}: {result.stdout.strip()} {result.stderr.strip()}'
            )


def prepare(source: Path, root: Path) -> dict[str, tuple[bytes | None, bytes]]:
    if not (root / 'config/config.php').is_file():
        raise UnsafeInstallation('Painel nao encontrado: config/config.php ausente')
    if not (root / API_PATH).is_file():
        raise UnsafeInstallation('API get-devices.php ausente')
    injection = block_from_source(source)
    changes = {}
    for rel in NEW_FILES:
        src = source / rel
        if not src.is_file():
            raise UnsafeInstallation(f'Fonte ausente: {rel}')
        dest = root / rel
        if dest.is_symlink():
            raise UnsafeInstallation(f'Destino e link simbolico: {rel}')
        before = dest.read_bytes() if dest.is_file() else None
        after = src.read_bytes()
        if before is not None and before != after:
            raise UnsafeInstallation(f'Modulo customizado ja existe: {rel}')
        lint_php(rel, after)
        if before != after:
            changes[rel] = (before, after)
    dest = root / API_PATH
    if dest.is_symlink():
        raise UnsafeInstallation('API e link simbolico')
    before = dest.read_bytes()
    after = overlay_api(before, injection)
    lint_php(API_PATH, after)
    if before != after:
        changes[API_PATH] = (before, after)
    return changes


def apply(root: Path, backup_root: Path, changes: dict[str, tuple[bytes | None, bytes]]) -> Path | None:
    if not changes:
        print('JA_INSTALADO - nenhuma mudanca pendente')
        return None
    stamp = datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')
    backup = backup_root / stamp
    backup.mkdir(parents=True, mode=0o700, exist_ok=False)
    installed = []
    try:
        for rel, (before, after) in changes.items():
            dest = root / rel
            if dest.is_symlink() or (dest.read_bytes() if dest.is_file() else None) != before:
                raise UnsafeInstallation(f'Mudanca concorrente detectada em {rel}')
            if before is not None:
                preserved = backup / rel
                preserved.parent.mkdir(parents=True, exist_ok=True)
                shutil.copy2(dest, preserved)
            dest.parent.mkdir(parents=True, exist_ok=True)
            fd, temp = tempfile.mkstemp(prefix='.jrc-inventory-', dir=dest.parent)
            try:
                with os.fdopen(fd, 'wb') as stream:
                    stream.write(after)
                    stream.flush()
                    os.fsync(stream.fileno())
                if before is None:
                    os.chmod(temp, 0o644)
                else:
                    stat = dest.stat()
                    os.chmod(temp, stat.st_mode & 0o777)
                    os.chown(temp, stat.st_uid, stat.st_gid)
                os.replace(temp, dest)
                installed.append(rel)
            finally:
                if os.path.exists(temp):
                    os.unlink(temp)
        (backup / 'new-files.txt').write_text(
            '\n'.join(rel for rel, (before, _) in changes.items() if before is None),
            encoding='utf-8'
        )
    except BaseException:
        for rel in reversed(installed):
            dest = root / rel
            before, _ = changes[rel]
            if before is None:
                dest.unlink(missing_ok=True)
            else:
                shutil.copy2(backup / rel, dest)
        raise
    return backup


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument('mode', choices=('plan', 'apply'))
    parser.add_argument('--root', type=Path, default=DEFAULT_ROOT)
    parser.add_argument('--backups', type=Path, default=DEFAULT_BACKUPS)
    args = parser.parse_args()
    changes = prepare(SOURCE, args.root)
    for path in changes:
        print('ARQUIVO:', path)
    print('Layout, TR-069, firmware, MCP, credenciais, cron e banco preservados.')
    if args.mode == 'plan':
        print('PLAN_OK' if changes else 'JA_INSTALADO')
    else:
        backup = apply(args.root, args.backups, changes)
        print('APLICADO_OK' + ('; backup=' + str(backup) if backup else ''))


if __name__ == '__main__':
    main()
