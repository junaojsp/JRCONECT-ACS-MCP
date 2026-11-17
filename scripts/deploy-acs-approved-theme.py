#!/usr/bin/env python3
"""Apply the approved shared design to the installed ACS, including standalone pages."""
import argparse
import hashlib
import importlib.util
from pathlib import Path
import re

REPO = Path(__file__).resolve().parent.parent
spec = importlib.util.spec_from_file_location('base', REPO / 'scripts/deploy-acs-device-reference-complete.py')
base = importlib.util.module_from_spec(spec); spec.loader.exec_module(base)
OLD_LINK = '<link id="jrc-approved-acs-theme" rel="stylesheet" href="/assets/css/jrc-approved-acs.css?v=1">'
LINK = '<link id="jrc-approved-acs-theme" rel="stylesheet" href="/assets/css/jrc-approved-acs.css?v=2-15af090c2e11">'
INIT = '<script id="jrc-approved-acs-init">document.documentElement.classList.add("jrc-approved-acs");</script>'
OLD_MARK = '<!-- JRC_APPROVED_SHARED_THEME_V1 -->'
MARK = '<!-- JRC_APPROVED_SHARED_THEME_V2 -->'
V1_CSS_SHA = '7d621fb3374f4d528b3fd529e1255082f5a8a6178e5dbfcf28edd43987d694eb'
WIDTH_SHA = '2611d18e4ce1853769ee06bd9c785addf1f83cbe254d6f67d24e3c5db1e55029'
WIDTH_SCRIPT = re.compile(r'<script\b[^>]*\bsrc=[\'\"]/assets/js/jrc-acoes-width\.js(?:\?[^\'\"]*)?[\'\"][^>]*>\s*</script>', re.I)
FILES = ('assets/css/jrc-approved-acs.css', 'scripts/audit-acs-device-resources.php')


def patch_document(source):
    # The old observer continuously re-appends the blue theme. Remove that mechanism,
    # not command handlers or any of the page's operational scripts.
    if 'pinCommandCenter' in source:
        pattern = r'<script\b[^>]*>\s*\(function\s*\(\)\s*\{(?:(?!</script>).)*?pinCommandCenter(?:(?!</script>).)*?</script>'
        matches = list(re.finditer(pattern, source, re.S))
        if len(matches) != 1 or 'jrc-command-center' not in matches[0].group():
            raise RuntimeError('Observador Command Center divergente; revisar rodapé instalado')
        source = source[:matches[0].start()] + source[matches[0].end():]
    source = re.sub(r'<link\b[^>]*href=[\'\"][^\'\"]*/assets/css/command-center\.css[^\'\"]*[\'\"][^>]*>\s*', '', source)
    if 'pinCommandCenter' in source or re.search(r'(?:src|href)=[\'\"][^\'\"]*command-center\.css', source):
        raise RuntimeError('Referência antiga não reconhecida')
    if OLD_MARK in source:
        if source.count(OLD_MARK) != 1 or source.count(OLD_LINK) != 1 or source.count(INIT) != 1:
            raise RuntimeError('Instalação v1 divergente; revisar antes de migrar')
        source = source.replace(OLD_MARK, MARK, 1).replace(OLD_LINK, LINK, 1)
    if MARK not in source:
        if source.count('</body>') != 1:
            raise RuntimeError('Documento HTML sem fechamento único')
        source = source.replace('</body>', MARK + '\n' + LINK + '\n' + INIT + '\n</body>', 1)
    elif source.count(LINK) != 1 or source.count(INIT) != 1:
        raise RuntimeError('Instalação do tema foi alterada')
    return source


def inspect(root):
    changes = []
    paths = [root / 'views/layouts/footer.php']
    # Modules such as MCP Server may own their document and skip the common footer.
    for path in sorted(list(root.glob('*.php')) + list((root / 'views').rglob('*.php'))):
        if path in paths or path.name in ('login.php', 'logout.php', 'init.php'): continue
        if path.is_symlink() or any(p.is_symlink() for p in path.parents):
            raise RuntimeError('Documento simbólico recusado: ' + str(path))
        text = path.read_text(errors='replace')
        if '</body>' in text and re.search(r'<(?:html|body)\b', text, re.I) and 'layouts/footer.php' not in text:
            paths.append(path)
    device = root / 'device-detail.php'
    if device.is_file() and device not in paths:
        paths.append(device)
    for path in paths:
        if path.is_symlink() or not path.is_file() or any(p.is_symlink() for p in path.parents):
            raise RuntimeError('Documento inseguro/ausente: ' + str(path))
        old = path.read_bytes()
        text = old.decode()
        if WIDTH_SCRIPT.search(text):
            helper = root / 'assets/js/jrc-acoes-width.js'
            if helper.is_symlink() or not helper.is_file() or hashlib.sha256(helper.read_bytes()).hexdigest() != WIDTH_SHA:
                raise RuntimeError('Adaptador de largura diverge do script inspecionado no navegador')
            text = WIDTH_SCRIPT.sub('<!-- JRC_APPROVED_GRID: legacy visual width adapter removed -->', text)
        if path == device:
            if 'id="jrc-device-reference"' not in text:
                raise RuntimeError('Página do dispositivo diverge da versão inspecionada')
            new = text.encode()
        else:
            new = patch_document(text).encode()
        base.check(new.decode(), '.php', ['php','-l'])
        if old != new: changes.append((path,old,new))
    for name in FILES:
        path = root / name
        if path.is_symlink() or any(p.is_symlink() for p in path.parents): raise RuntimeError('Destino simbólico: ' + name)
        old = path.read_bytes() if path.is_file() else None; new = (REPO / name).read_bytes()
        if old is not None and old != new:
            if name != 'assets/css/jrc-approved-acs.css' or hashlib.sha256(old).hexdigest() != V1_CSS_SHA:
                raise RuntimeError('Asset de destino foi alterado: ' + name)
        if name.endswith('.php'): base.check(new.decode(), '.php', ['php','-l'])
        if old != new: changes.append((path,old,new))
    return changes


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('mode',choices=('plan','apply'))
    parser.add_argument('--root',type=Path,default=Path('/var/www/gacs'))
    args=parser.parse_args(); jobs=inspect(args.root)
    if args.mode=='plan':
        for path,_,_ in jobs: print('ALTERAR:',path)
        print('PLAN_OK' if jobs else 'JA_INSTALADO')
    else:
        for path,_,_ in jobs: path.parent.mkdir(parents=True,exist_ok=True)
        base.apply(jobs,args.root,Path('/var/backups/jrc-approved-shared-theme'))
        print('AUDITORIA_CPU_RAM: php /opt/jrc-acs-snmp-source/scripts/audit-acs-device-resources.php SERIAL_DA_ONU')


if __name__=='__main__': main()
