#!/usr/bin/env python3
"""Plan/apply surgical SNMP reader hooks; never copy legacy files or credentials."""
import argparse
import os
from datetime import datetime, timezone
from pathlib import Path
import re
import shutil
import subprocess
import tempfile

SOURCE = Path(__file__).resolve().parent.parent
NEW_FILES = ['collector/snmp_pilot.py', 'collector/snmp_fleet.py',
             'lib/JrcSnmpTelemetry.php']


def replace_once(text, needle, value):
    if text.count(needle) != 1:
        raise ValueError('estrutura_divergente')
    return text.replace(needle, value, 1)


def overlay(path, text):
    if 'JrcSnmpTelemetry' in text:
        raise ValueError('hook_snmp_existente_requer_revisao')
    if path == 'lib/JrcDeviceInventoryCache.php':
        return replace_once(text, '            $devices[] = $decoded;',
            "            require_once __DIR__ . '/JrcSnmpTelemetry.php';\n"
            '            $devices[] = JrcSnmpTelemetry::apply($decoded);')
    if path == 'lib/JrcDashboardSnapshot.php':
        return replace_once(text, '            $devices[] = $item;',
            "            require_once __DIR__ . '/JrcSnmpTelemetry.php';\n"
            '            $devices[] = JrcSnmpTelemetry::apply($item);')
    if path == 'cron/collect-device-inventory.php':
        return replace_once(text, '    $published = JrcDeviceInventoryCache::publish',
            "    require_once __DIR__ . '/../lib/JrcSnmpTelemetry.php';\n"
            "    $devices = array_map([JrcSnmpTelemetry::class, 'apply'], $devices);\n"
            '    $published = JrcDeviceInventoryCache::publish')
    if path == 'api/get-devices.php':
        return replace_once(text, '            $devices[] = $parsed;',
            "            require_once __DIR__ . '/../lib/JrcSnmpTelemetry.php';\n"
            '            $devices[] = JrcSnmpTelemetry::apply($parsed);')
    raise ValueError('arquivo_nao_previsto')


def plan(root):
    changes = {}
    for relative in NEW_FILES:
        target = root / relative
        content = (SOURCE / relative).read_bytes()
        if target.exists() and target.read_bytes() != content:
            # Pilot already installed from the pinned PR can remain identical.
            raise ValueError('arquivo_novo_divergente:' + relative)
        changes[relative] = content
    for relative in ['lib/JrcDeviceInventoryCache.php', 'lib/JrcDashboardSnapshot.php',
                     'cron/collect-device-inventory.php', 'api/get-devices.php']:
        changes[relative] = overlay(relative, (root / relative).read_text()).encode()
    for relative, content in changes.items():
        if relative.endswith('.php'):
            with tempfile.NamedTemporaryFile(suffix='.php') as stream:
                stream.write(content)
                stream.flush()
                if subprocess.run(['php', '-l', stream.name], capture_output=True).returncode:
                    raise ValueError('php_invalido:' + relative)
    return changes


def main():
    os.umask(0o077)
    parser = argparse.ArgumentParser()
    parser.add_argument('--root', default='/var/www/gacs')
    parser.add_argument('--apply', action='store_true')
    args = parser.parse_args()
    root = Path(args.root)
    if (Path('/etc/jrc-collector') / 'snmp-source.enabled').exists():
        raise ValueError('desativar_fonte_antes_de_modificar_arquivos')
    changes = plan(root)
    print('SNMP_OVERLAY_PLAN ' + ', '.join(changes))
    if not args.apply:
        print('PLAN_ONLY; não ativa fonte, não agenda cron, não altera ações TR-069.')
        return
    backup = Path('/var/backups/jrc-acs-snmp') / datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')
    for relative in changes:
        target = root / relative
        if target.exists():
            saved = backup / relative
            saved.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(target, saved)
    for relative, content in changes.items():
        target = root / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        mode = target.stat().st_mode & 0o777 if target.exists() else 0o644
        fd, temporary = tempfile.mkstemp(dir=target.parent, prefix='.snmp-stage-')
        try:
            with os.fdopen(fd, 'wb') as stream:
                stream.write(content)
                os.fchmod(stream.fileno(), mode)
            os.replace(temporary, target)
        finally:
            Path(temporary).unlink(missing_ok=True)
    print('STAGED_NOT_ACTIVATED backup=' + str(backup))


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        # Never dump legacy source, HTTP response or credentials.
        print('SNMP_OVERLAY_REFUSED ' + str(error) if isinstance(error, ValueError)
              else 'SNMP_OVERLAY_REFUSED ' + type(error).__name__)
        raise SystemExit(1)
