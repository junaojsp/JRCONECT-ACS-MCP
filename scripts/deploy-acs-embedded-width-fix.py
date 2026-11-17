#!/usr/bin/env python3
"""Remove only the verified visual width IIFE embedded in production device-control-fix.js."""
import argparse
import hashlib
import importlib.util
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
spec = importlib.util.spec_from_file_location('base', REPO / 'scripts/deploy-acs-device-reference-complete.py')
base = importlib.util.module_from_spec(spec); spec.loader.exec_module(base)
FIXTURE = REPO / 'tests/device-reference/fixtures/jrc-acoes-width-v2.js'
SHA = '2611d18e4ce1853769ee06bd9c785addf1f83cbe254d6f67d24e3c5db1e55029'
MARK = b'/* JRC_EMBEDDED_ACOES_WIDTH_REMOVED_V3 */\n'


def patch_js(source):
    suffix = FIXTURE.read_bytes()
    if hashlib.sha256(suffix).hexdigest() != SHA:
        raise RuntimeError('Fragmento visual de referência inválido')
    if MARK in source:
        if b'JRC_ACOES_WIDTH_JS_V2' in source:
            raise RuntimeError('Código de largura reapareceu após a correção')
        return source
    if not source.endswith(suffix) or source.count(b'JRC_ACOES_WIDTH_JS_V2') != 2:
        raise RuntimeError('Trecho embutido difere da versão de produção inspecionada')
    prefix = source[:-len(suffix)]
    if b'JRC_ACOES_LOADER_V1 END' not in prefix:
        raise RuntimeError('Loader operacional original não reconhecido')
    return prefix + MARK


def inspect(root):
    path = root / 'assets/js/device-control-fix.js'
    if path.is_symlink() or not path.is_file() or any(p.is_symlink() for p in path.parents):
        raise RuntimeError('Arquivo instalado ausente ou simbólico')
    before = path.read_bytes(); after = patch_js(before)
    base.check(after.decode(), '.js', ['node','--check'])
    return [] if before == after else [(path,before,after)]


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('mode',choices=('plan','apply'))
    parser.add_argument('--root',type=Path,default=Path('/var/www/gacs'))
    args=parser.parse_args();jobs=inspect(args.root)
    if args.mode=='plan':
        for path,_,_ in jobs:print('ALTERAR:',path)
        print('PLAN_OK' if jobs else 'JA_INSTALADO')
    else:base.apply(jobs,args.root,Path('/var/backups/jrc-embedded-width-fix'))


if __name__=='__main__':main()
