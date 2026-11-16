#!/usr/bin/env python3
"""Repair only the recent-device query, preserving production dashboard/custom API."""
import argparse
import importlib.util
from pathlib import Path

SOURCE = Path(__file__).resolve().parent.parent
spec = importlib.util.spec_from_file_location('overview_deploy', SOURCE / 'scripts/deploy-acs-overview-fast.py')
base = importlib.util.module_from_spec(spec)
spec.loader.exec_module(base)
LIB = 'lib/JrcRecentDevices.php'
API = 'api/recent-devices.php'


def patch(before):
    text = before.decode('utf-8')
    if text.count('requireLogin();') != 1:
        raise base.UnsafeInstallation('Autenticacao inesperada em ' + API)
    old = '$result = $genieacs->getDevices();'
    new = "require_once __DIR__ . '/../lib/JrcRecentDevices.php';\n    $result = JrcRecentDevices::fetch($config);"
    if old in text:
        if text.count(old) != 1 or 'JrcRecentDevices::fetch' in text:
            raise base.UnsafeInstallation('Consulta ambigua em ' + API)
        text = text.replace(old, new)
    elif text.count(new) != 1:
        raise base.UnsafeInstallation('Consulta personalizada desconhecida em ' + API)
    text = text.replace('SELECT host, port, username, password FROM genieacs_credentials LIMIT 1',
                        'SELECT host, port, username, password FROM genieacs_credentials WHERE is_connected = 1 ORDER BY id DESC LIMIT 1')
    text = text.replace('catch (Exception $e)', 'catch (Throwable $e)')
    # Free the session even if the older optional snapshot overlay is absent.
    if 'session_write_close()' not in text:
        text = text.replace('requireLogin();', 'requireLogin();\nif (session_status() === PHP_SESSION_ACTIVE) session_write_close();')
    return text.encode('utf-8')


def prepare(root):
    if not (root / 'config/config.php').is_file():
        raise base.UnsafeInstallation('Diretorio ACS nao encontrado')
    changes = {}
    for name in (LIB, API):
        path = root / name
        if path.is_symlink():
            raise base.UnsafeInstallation('Destino simbolico: ' + name)
        before = path.read_bytes() if path.exists() else None
        if name == LIB:
            after = (SOURCE / name).read_bytes()
            if before is not None and before != after:
                raise base.UnsafeInstallation('Biblioteca personalizada: ' + name)
        else:
            if before is None:
                raise base.UnsafeInstallation('API ausente')
            after = patch(before)
        base.php_lint(name, after)
        if before != after:
            changes[name] = (before, after)
    return changes


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('mode', choices=['plan', 'apply'])
    parser.add_argument('--root', type=Path, default=Path('/var/www/gacs'))
    parser.add_argument('--backups', type=Path, default=Path('/var/backups/jrc-dashboard-query'))
    args = parser.parse_args()
    changes = prepare(args.root)
    for name in changes:
        print('ARQUIVO:', name)
    if args.mode == 'apply':
        backup = base.apply(args.root, args.backups, changes)
        print('APLICADO_OK; backup=' + str(backup) if backup else 'JA_INSTALADO')
    else:
        print('PLAN_OK' if changes else 'JA_INSTALADO')
