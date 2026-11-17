#!/usr/bin/env python3
"""Correct reference navigation and install final scoped visual overrides after #257."""
import argparse
import importlib.util
from pathlib import Path
import re

REPO = Path(__file__).resolve().parent.parent
spec = importlib.util.spec_from_file_location('reference_deploy', REPO / 'scripts/deploy-acs-device-reference-complete.py')
base = importlib.util.module_from_spec(spec)
spec.loader.exec_module(base)
CSS = 'jrc-device-reference-final.css'
LINK = '<link rel="stylesheet" href="/assets/css/' + CSS + '?v=1">'


def patch_page(page):
    names = [('overview-tab', 'Informações'), ('wan-tab', 'WAN'), ('dhcp-tab', 'LAN'),
             ('wifi-tab', 'Wi-Fi'), ('devices-tab', 'Dispositivos'), ('voice-tab', 'Voz'),
             ('diagnostics-tab', 'Ações'), ('history-tab', 'Histórico'), ('optical-tab', 'Óptico')]
    for order, (tab_id, label) in enumerate(names, 1):
        pattern = r'<li\b[^>]*>\s*<button\b[^>]*\bid="' + re.escape(tab_id) + r'"[^>]*>.*?</button>\s*</li>'
        matches = list(re.finditer(pattern, page, re.S))
        if len(matches) != 1:
            raise RuntimeError('Aba instalada divergente: ' + tab_id)
        old = matches[0].group()
        new = old.replace(' fb-nav-retired', '')
        # Only the original li order changes; IDs, badges and Bootstrap targets stay intact.
        opening, rest = new.split('>', 1)
        opening = re.sub(r'\sstyle="[^"]*"', '', opening)
        new = opening + f' style="order:{order}">' + rest
        new = re.sub(r'(<button\b[^>]*>)[^<]*', lambda m: m[1] + label + '\n                        ', new, count=1)
        page = page.replace(old, new, 1)
    if 'acs-device-tabs-wrap jrc-ref-final' not in page:
        if page.count('class="acs-device-tabs-wrap"') != 1:
            raise RuntimeError('Container do equipamento não reconhecido')
        page = page.replace('class="acs-device-tabs-wrap"', 'class="acs-device-tabs-wrap jrc-ref-final"', 1)
    return page


def inspect(root):
    names = ['device-detail.php', 'views/layouts/footer.php', 'assets/css/' + CSS]
    old = {}
    for name in names:
        path = root / name
        if path.is_symlink() or any(p.is_symlink() for p in path.parents):
            raise RuntimeError('Link simbólico recusado: ' + str(path))
        old[name] = path.read_bytes() if path.is_file() else None
    if any(old[n] is None for n in names[:2]):
        raise RuntimeError('Arquivos instalados ausentes')
    if not (root / 'assets/css/jrc-device-reference-complete.css').is_file():
        raise RuntimeError('Aplique primeiro a PR #257')
    new = dict(old)
    new['device-detail.php'] = patch_page(old['device-detail.php'].decode()).encode()
    footer = old['views/layouts/footer.php'].decode()
    if LINK not in footer:
        if footer.count('</body>') != 1:
            raise RuntimeError('Rodapé não reconhecido')
        include = '<?php if (basename((string)($_SERVER["SCRIPT_NAME"] ?? "")) === "device-detail.php"): ?>\n' + LINK + '\n<?php endif; ?>\n'
        footer = footer.replace('</body>', include + '</body>', 1)
    new['views/layouts/footer.php'] = footer.encode()
    new['assets/css/' + CSS] = (REPO / 'assets/css' / CSS).read_bytes()
    if old['assets/css/' + CSS] not in (None, new['assets/css/' + CSS]):
        raise RuntimeError('CSS de destino diverge; revisar antes de substituir')
    for name in names[:2]:
        base.check(new[name].decode(), '.php', ['php', '-l'])
    return [(root / name, before, new[name]) for name, before in old.items() if before != new[name]]


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('mode', choices=('plan', 'apply'))
    parser.add_argument('--root', type=Path, default=Path('/var/www/gacs'))
    args = parser.parse_args()
    jobs = inspect(args.root)
    if args.mode == 'plan':
        for path, _, _ in jobs:
            print('ALTERAR:', path)
        print('PLAN_OK' if jobs else 'JA_INSTALADO')
    else:
        base.apply(jobs, args.root, Path('/var/backups/jrc-device-reference-final'))


if __name__ == '__main__':
    main()
