#!/usr/bin/env python3
"""Install device content and normalize cached CPU/RAM parameters, following #258."""
import argparse
import hashlib
import importlib.util
import json
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
spec = importlib.util.spec_from_file_location('reference_base', REPO / 'scripts/deploy-acs-device-reference-complete.py')
base = importlib.util.module_from_spec(spec)
spec.loader.exec_module(base)
MARK = '/* JRC_DEVICE_REFERENCE_CONTENT_V3 */'
CSS = 'jrc-device-reference-content.css'
LINK = '<link rel="stylesheet" href="/assets/css/' + CSS + '?v=1">'
API_ANCHOR = "    $parsed = $genieacs->parseDeviceData($deviceResult['data']);"
API_INSERT = "\n    require_once __DIR__ . '/../lib/JrcDeviceResources.php';\n    $parsed = array_merge($parsed, jrcDeviceResources($deviceResult['data']));"


def patch_js(source):
    patches = json.loads((REPO / 'scripts/device-reference-content/patches.json').read_text())
    visual = (REPO / 'scripts/device-reference-content/host-visual.js').read_text()
    if MARK in source:
        if visual not in source or any(source.count(p['new']) != 1 for p in patches):
            raise RuntimeError('Conteúdo v3 foi alterado; revisar antes de reaplicar')
        return source
    if 'JRC_DEVICE_REFERENCE_COMPLETE_V2' not in source:
        raise RuntimeError('Aplique primeiro a PR #257')
    for patch in patches:
        if hashlib.sha256(patch['old'].encode()).hexdigest() != patch['sha256'] or source.count(patch['old']) != 1:
            raise RuntimeError('Renderizador de produção divergente: ' + patch['old'][:70])
        source = source.replace(patch['old'], patch['new'], 1)
    return MARK + '\n' + visual + '\n' + source


def inspect(root):
    old = {}
    def read(name, required=True):
        p = root / name
        if p.is_symlink() or any(a.is_symlink() for a in p.parents):
            raise RuntimeError('Link simbólico recusado: ' + str(p))
        if required and not p.is_file():
            raise RuntimeError('Arquivo ausente: ' + str(p))
        old[name] = p.read_bytes() if p.is_file() else None
        return old[name]
    source = read('assets/js/device-detail.js').decode()
    page = read('device-detail.php').decode()
    footer = read('views/layouts/footer.php').decode()
    api = read('api/get-device-detail.php').decode()
    read('lib/JrcDeviceResources.php', False)
    read('assets/css/' + CSS, False)
    if 'jrc-ref-final' not in page:
        raise RuntimeError('Aplique primeiro a PR #258')
    for logo in sorted((REPO / 'assets/img/device-brands').glob('*.svg')):
        read('assets/img/device-brands/' + logo.name, False)
    new = dict(old)
    new['assets/js/device-detail.js'] = patch_js(source).encode()
    if 'id="jrc-device-reference"' not in page:
        if page.count('class="acs-device-tabs-wrap jrc-ref-final"') != 1:
            raise RuntimeError('Container de produção divergente')
        page = page.replace('class="acs-device-tabs-wrap jrc-ref-final"', 'id="jrc-device-reference" class="acs-device-tabs-wrap jrc-ref-final"', 1)
    new['device-detail.php'] = page.encode()
    if API_INSERT not in api:
        if api.count(API_ANCHOR) != 1:
            raise RuntimeError('API de detalhes divergente; revisar mapeamento CPU/RAM')
        api = api.replace(API_ANCHOR, API_ANCHOR + API_INSERT, 1)
    new['api/get-device-detail.php'] = api.encode()
    new['lib/JrcDeviceResources.php'] = (REPO / 'lib/JrcDeviceResources.php').read_bytes()
    if LINK not in footer:
        if footer.count('</body>') != 1:
            raise RuntimeError('Footer divergente')
        footer = footer.replace('</body>', '<?php if (basename((string)($_SERVER["SCRIPT_NAME"] ?? "")) === "device-detail.php"): ?>\n' + LINK + '\n<?php endif; ?>\n</body>', 1)
    new['views/layouts/footer.php'] = footer.encode()
    for name in list(old):
        if name == 'assets/css/' + CSS or name.startswith('assets/img/device-brands/'):
            new[name] = (REPO / name).read_bytes()
            if old[name] not in (None, new[name]):
                raise RuntimeError('Asset de destino alterado: ' + name)
    if old['lib/JrcDeviceResources.php'] not in (None, new['lib/JrcDeviceResources.php']):
        raise RuntimeError('Helper CPU/RAM de destino alterado')
    base.check(new['assets/js/device-detail.js'].decode(), '.js', ['node', '--check'])
    for name in ('device-detail.php', 'views/layouts/footer.php', 'api/get-device-detail.php', 'lib/JrcDeviceResources.php'):
        base.check(new[name].decode(), '.php', ['php', '-l'])
    return [(root / name, before, new[name]) for name, before in old.items() if before != new[name]]


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('mode', choices=('plan', 'apply'))
    parser.add_argument('--root', type=Path, default=Path('/var/www/gacs'))
    args = parser.parse_args()
    jobs = inspect(args.root)
    if args.mode == 'plan':
        for path, _, _ in jobs: print('ALTERAR:', path)
        print('PLAN_OK' if jobs else 'JA_INSTALADO')
    else:
        # The original atomic writer requires destination parents to exist.
        (args.root / 'assets/img/device-brands').mkdir(parents=True, exist_ok=True)
        base.apply(jobs, args.root, Path('/var/backups/jrc-device-reference-content'))


if __name__ == '__main__':
    main()
