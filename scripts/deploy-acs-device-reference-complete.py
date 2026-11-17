#!/usr/bin/env python3
"""Install the complete device layout against the operator-provided production version."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
from datetime import datetime, timezone

REPO = Path(__file__).resolve().parent.parent
MARK = '/* JRC_DEVICE_REFERENCE_COMPLETE_V2 */'
CSS_NAME = 'jrc-device-reference-complete.css'
LINK = '<link rel="stylesheet" href="/assets/css/' + CSS_NAME + '?v=2">'


def patch_js(source):
    if MARK in source:
        helper = (REPO / 'scripts/device-reference-v2/helpers.js').read_text()
        patches = json.loads((REPO / 'scripts/device-reference-v2/patches.json').read_text())
        if helper not in source or any(source.count(p['new']) != 1 for p in patches):
            raise RuntimeError('Instalação v2 foi alterada; revisar antes de reaplicar')
        return source
    for anchor in ('function renderWifiHealthCard(device)', 'function jrcEnhanceConnectedClientsTable(',
                   'function renderPhysicalPorts(', 'JRC_WAN_REFERENCE_LAYOUT_V1'):
        if source.count(anchor) != 1:
            raise RuntimeError('Versão instalada divergente: ' + anchor)
    for patch in json.loads((REPO / 'scripts/device-reference-v2/patches.json').read_text()):
        if hashlib.sha256(patch['old'].encode()).hexdigest() != patch['old_sha256']:
            raise RuntimeError('Fragmento de origem inválido')
        if source.count(patch['old']) != 1:
            raise RuntimeError('Código instalado diverge do arquivo enviado: ' + patch['old'][:70])
        source = source.replace(patch['old'], patch['new'], 1)
    return MARK + '\n' + (REPO / 'scripts/device-reference-v2/helpers.js').read_text() + '\n' + source


def patch_modal(source):
    start = source.index('<div class="modal fade" id="editWiFiModal"')
    end = source.index('<!-- Edit WAN Configuration Modal -->', start)
    block = source[start:end]
    translations = {
        'Edit WiFi Configuration': 'Editar rede Wi-Fi',
        'Info:': 'Informação:',
        'Perubahan akan dikirim ke ONU melalui GenieACS. Device mungkin perlu reboot untuk menerapkan konfigurasi baru.':
        'As alterações serão enviadas à ONU pelo ACS. O equipamento poderá precisar reiniciar para aplicar a configuração.',
        'WiFi SSID': 'Nome da rede (SSID)',
        'Enter WiFi SSID (1-32 characters)': 'Nome da rede (1 a 32 caracteres)',
        'SSID harus antara 1-32 karakter': 'O SSID deve conter de 1 a 32 caracteres.',
        'Security Mode': 'Segurança', 'WPA2-PSK (Recommended)': 'WPA2-PSK (Recomendado)',
        'WPA/WPA2-PSK Mixed': 'WPA/WPA2-PSK Misto', 'Open (No Security)': 'Aberta (sem segurança)',
        'Pilih mode keamanan WiFi': 'Selecione o modo de segurança da rede.',
        'WiFi Password': 'Senha', 'Enter WiFi Password (8-63 characters)': 'Senha (8 a 63 caracteres)',
        'Password harus antara 8-63 karakter untuk WPA/WPA2': 'A senha WPA/WPA2 deve conter de 8 a 63 caracteres.',
        'WLAN Interface': 'Interface WLAN', 'WLAN 1 (2.4GHz - Default)': 'WLAN 1 (2,4 GHz - Padrão)',
        'WLAN 2 (5GHz)': 'WLAN 2 (5 GHz)',
        'Pilih interface WLAN yang ingin diubah': 'Selecione a interface WLAN que deseja alterar.',
        'Update WiFi': 'Salvar alterações',
    }
    for old, new in translations.items():
        block = block.replace(old, new)
    for anchor in ('id="edit-wlan-index"', 'id="edit-security-mode"', 'onclick="confirmUpdateWiFi()"'):
        if block.count(anchor) != 1:
            raise RuntimeError('Controles originais do modal divergentes')
    return source[:start] + block + source[end:]


def check(code, suffix, command):
    fd, name = tempfile.mkstemp(suffix=suffix)
    try:
        with os.fdopen(fd, 'w') as stream:
            stream.write(code)
        result = subprocess.run(command + [name], capture_output=True, text=True)
        if result.returncode:
            raise RuntimeError('Validação falhou: ' + result.stderr[:600] + result.stdout[:200])
    finally:
        os.unlink(name)


def inspect(root):
    paths = ['assets/js/device-detail.js', 'device-detail.php', 'views/layouts/footer.php',
             'views/device-detail/modals.php']
    old = {}
    for name in paths + ['assets/css/' + CSS_NAME]:
        path = root / name
        if path.is_symlink() or any(p.is_symlink() for p in path.parents):
            raise RuntimeError('Link simbólico recusado: ' + str(path))
        if name in paths and not path.is_file():
            raise RuntimeError('Arquivo ausente: ' + str(path))
        old[name] = path.read_bytes() if path.is_file() else None
    page = old['device-detail.php'].decode()
    if not all(x in page for x in ('id="deviceTabs"', 'id="dhcp-content"', 'id="devices-content"', 'acs-device-tabs-wrap')):
        raise RuntimeError('Página instalada não reconhecida')
    for name in ('jrc-device-wan-reference-layout.css', 'jrc-device-wifi-reference-cards.css'):
        if not (root / 'assets/css' / name).is_file():
            raise RuntimeError('Instale primeiro os layouts WAN/Wi-Fi das PRs #254 e #255')
    new = dict(old)
    new['assets/js/device-detail.js'] = patch_js(old['assets/js/device-detail.js'].decode()).encode()
    # Existing DHCP tab becomes LAN; all Bootstrap targets and IDs remain the same.
    new['device-detail.php'] = page.replace('role="tab">DHCP</button>', 'role="tab">LAN</button>').encode()
    new['views/device-detail/modals.php'] = patch_modal(old['views/device-detail/modals.php'].decode()).encode()
    footer = old['views/layouts/footer.php'].decode()
    if LINK not in footer:
        if footer.count('</body>') != 1:
            raise RuntimeError('Rodapé instalado não reconhecido')
        include = '<?php if (basename((string)($_SERVER["SCRIPT_NAME"] ?? "")) === "device-detail.php"): ?>\n' + LINK + '\n<?php endif; ?>\n'
        footer = footer.replace('</body>', include + '</body>')
    new['views/layouts/footer.php'] = footer.encode()
    new['assets/css/' + CSS_NAME] = (REPO / 'assets/css' / CSS_NAME).read_bytes()
    if old['assets/css/' + CSS_NAME] not in (None, new['assets/css/' + CSS_NAME]):
        raise RuntimeError('CSS de destino foi alterado; revisar antes de substituir')
    if not shutil.which('node') or not shutil.which('php'):
        raise RuntimeError('PHP e Node.js são necessários para validar antes de escrever')
    check(new['assets/js/device-detail.js'].decode(), '.js', ['node', '--check'])
    for name in ('device-detail.php', 'views/layouts/footer.php', 'views/device-detail/modals.php'):
        check(new[name].decode(), '.php', ['php', '-l'])
    return [(root / name, before, new[name]) for name, before in old.items() if before != new[name]]


def atomic(path, data):
    fd, name = tempfile.mkstemp(prefix='.jrc-ref-', dir=path.parent)
    try:
        with os.fdopen(fd, 'wb') as stream:
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
        if path.exists():
            stat = path.stat()
            os.chmod(name, stat.st_mode & 0o777)
            os.chown(name, stat.st_uid, stat.st_gid)
        else:
            os.chmod(name, 0o644)
        os.replace(name, path)
    finally:
        if os.path.exists(name):
            os.unlink(name)


def apply(jobs, root, backup_root):
    if not jobs:
        print('JA_INSTALADO')
        return
    backup = backup_root / datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')
    backup.mkdir(parents=True, mode=0o700)
    applied = []
    try:
        for path, old, new in jobs:
            if (path.read_bytes() if path.exists() else None) != old:
                raise RuntimeError('Arquivo alterado durante implantação: ' + str(path))
            if old is not None:
                target = backup / path.relative_to(root)
                target.parent.mkdir(parents=True, exist_ok=True)
                shutil.copy2(path, target)
            atomic(path, new)
            applied.append((path, old))
            print('ATUALIZADO:', path.relative_to(root))
        print('APLICADO_OK\nBACKUP:', backup)
        print('Conferir no navegador com Ctrl+F5; instalação não confirma resultado visual.')
    except BaseException:
        for path, old in reversed(applied):
            if old is None:
                path.unlink(missing_ok=True)
            else:
                shutil.copy2(backup / path.relative_to(root), path)
        raise


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('mode', choices=('plan', 'apply'))
    parser.add_argument('--root', type=Path, default=Path('/var/www/gacs'))
    parser.add_argument('--backup-root', type=Path, default=Path('/var/backups/jrc-device-reference-complete'))
    args = parser.parse_args()
    jobs = inspect(args.root)
    if args.mode == 'plan':
        for path, _, _ in jobs:
            print('ALTERAR:', path)
        print('PLAN_OK' if jobs else 'JA_INSTALADO')
    else:
        apply(jobs, args.root, args.backup_root)


if __name__ == '__main__':
    main()
