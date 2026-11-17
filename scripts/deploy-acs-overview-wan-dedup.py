#!/usr/bin/env python3
"""Hide only duplicate WAN traffic in device overview. Native WAN tab untouched.

Uses a new versioned stylesheet, no JS edits, safe on previously patched production.
"""
import argparse, os, shutil, subprocess, tempfile
from datetime import datetime, timezone
from pathlib import Path

WEB=Path('/var/www/gacs')
SOURCE=Path(__file__).resolve().parent.parent
PAGE=WEB/'device-detail.php'
JS=WEB/'assets/js/device-detail.js'
FOOT=WEB/'views/layouts/footer.php'
CSS=WEB/'assets/css/jrc-device-overview-wan-dedup.css'
SRC=SOURCE/'assets/css/jrc-device-overview-wan-dedup.css'
LINK=b'<link rel="stylesheet" href="/assets/css/jrc-device-overview-wan-dedup.css?v=1">'
INCLUDE=(b'    <?php if (basename((string)($_SERVER["SCRIPT_NAME"] ?? "")) === "device-detail.php"): ?>\n'
         +b'    <!-- ACS overview WAN chart deduplication only -->\n'
         +b'    '+LINK+b'\n'
         +b'    <?php endif; ?>\n')

def inspect():
    for p in (PAGE,JS,FOOT):
        if not p.is_file() or p.is_symlink():
            raise RuntimeError('Arquivo de producao invalido ou ausente: '+str(p))
    page=PAGE.read_bytes()
    if not all(x in page for x in (b'id="overview-tab"',b'id="wan-tab"',b'id="deviceTabs"')):
        raise RuntimeError('Abas nativas nao reconhecidas')
    js=JS.read_bytes()
    if b'jrc-ref-traffic-panel' not in js or b'jrc-ref-device-top' not in js:
        raise RuntimeError('Painel WAN duplicado nao reconhecido no JS instalado; nao aplicar')
    if b'JRC_NATIVE_TABS_CLEAN_V4' not in js:
        raise RuntimeError('Correcao das abas v4 nao identificada')
    footer=FOOT.read_bytes()
    if b'jrc-device-overview-reference-real.css' not in footer:
        raise RuntimeError('CSS de Visao Geral real nao localizado')
    if footer.count(LINK)>1:raise RuntimeError('Link CSS duplicado')
    if LINK in footer:updated=footer
    else:
        if footer.count(b'</body>')!=1:raise RuntimeError('Footer nao reconhecido')
        updated=footer.replace(b'</body>', INCLUDE+b'</body>',1)
    result=subprocess.run(['php','-l'],input=updated,capture_output=True)
    if result.returncode:raise RuntimeError('Footer PHP nao passou lint')
    if CSS.is_symlink():raise RuntimeError('CSS destino e link simbolico')
    before_css=CSS.read_bytes() if CSS.is_file() else None
    after_css=SRC.read_bytes()
    if before_css is not None and before_css!=after_css:
        raise RuntimeError('CSS ja existe com conteudo diferente; nao sobrescrever')
    return [(p,before,after) for p,before,after in
            [(CSS,before_css,after_css),(FOOT,footer,updated)] if before!=after]

def atomic(p,data):
    p.parent.mkdir(parents=True,exist_ok=True)
    fd,temp=tempfile.mkstemp(prefix='.jrc-wan-dedup-',dir=p.parent)
    try:
        with os.fdopen(fd,'wb') as out:
            out.write(data);out.flush();os.fsync(out.fileno())
        if p.exists():
            st=p.stat()
            os.chmod(temp,st.st_mode&0o777)
            os.chown(temp,st.st_uid,st.st_gid)
        else:os.chmod(temp,0o644)
        os.replace(temp,p)
    finally:
        if os.path.exists(temp):os.unlink(temp)

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('mode',choices=('plan','apply'))
    mode=parser.parse_args().mode
    changes=inspect()
    if mode=='plan':
        for p,old,_ in changes:print(('CRIAR: ' if old is None else 'ALTERAR: ')+str(p))
        print('PLAN_OK' if changes else 'JA_INSTALADO')
        return
    if not changes:
        print('JA_INSTALADO')
        return
    backup=Path('/var/backups/jrc-device-overview-wan-dedup')/datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')
    backup.mkdir(parents=True,mode=0o700,exist_ok=False)
    applied=[]
    try:
        for p,old,new in changes:
            if (p.read_bytes() if p.is_file() else None)!=old:
                raise RuntimeError('Arquivo alterado durante deploy: '+str(p))
            if old is not None:
                saved=backup/p.relative_to(WEB)
                saved.parent.mkdir(parents=True,exist_ok=True)
                shutil.copy2(p,saved)
            atomic(p,new)
            applied.append((p,old))
            print('ATUALIZADO:',p.relative_to(WEB))
        print('APLICADO_OK')
        print('BACKUP:',backup)
        print('ESCOPO: somente ocultar grafico WAN duplicado na Visao Geral; aba WAN intacta')
    except BaseException:
        for p,old in reversed(applied):
            if old is None:p.unlink(missing_ok=True)
            else:shutil.copy2(backup/p.relative_to(WEB),p)
        raise

if __name__=='__main__':main()
