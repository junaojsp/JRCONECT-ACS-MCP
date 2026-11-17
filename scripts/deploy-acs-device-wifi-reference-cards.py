#!/usr/bin/env python3
"""Deploy reference Wi-Fi cards into the real production renderer. No device actions changed."""
import argparse,os,shutil,subprocess,tempfile
from pathlib import Path
from datetime import datetime,timezone
ROOT=Path('/var/www/gacs')
REPO=Path(__file__).resolve().parent.parent
JS=ROOT/'assets/js/device-detail.js'
PAGE=ROOT/'device-detail.php'
FOOT=ROOT/'views/layouts/footer.php'
CSS=ROOT/'assets/css/jrc-device-wifi-reference-cards.css'
SRC=REPO/'assets/css/jrc-device-wifi-reference-cards.css'
SNIP=REPO/'scripts/jrc-wifi-reference-kpis.inc'
LINK=b'<link rel="stylesheet" href="/assets/css/jrc-device-wifi-reference-cards.css?v=1">'
INCLUDE=(b'<?php if (basename((string)($_SERVER["SCRIPT_NAME"] ?? "")) === "device-detail.php"): ?>\n'
         +LINK+b'\n<?php endif; ?>\n')
ANCHOR='    container.innerHTML = `\n        <div class="acs-wifi-health-list">'
def patch(before):
    source=before.decode('utf-8')
    if 'jrc-wifi-ref-kpis' in source:return before
    start=source.find('function renderWifiHealthCard(device)')
    end=source.find('\nconst FB_ACTION_CATALOG',start)
    if start<0 or end<0:raise RuntimeError('Renderizador Wi-Fi da producao nao reconhecido')
    block=source[start:end]
    if block.count(ANCHOR)!=1:raise RuntimeError('Ancora de cards nativos Wi-Fi divergente')
    if not all(x in block for x in ('openEditWiFiModal(', 'acs-wifi-health-row','acs-wifi-health-footer')):
        raise RuntimeError('Controles Wi-Fi originais nao encontrados')
    extra=SNIP.read_text(encoding='utf-8')
    block=block.replace(ANCHOR,'    container.innerHTML = `\n'+extra+
                        '        <div class="acs-wifi-health-list">',1)
    return (source[:start]+block+source[end:]).encode('utf-8')
def inspect():
    for p in (JS,PAGE,FOOT):
        if not p.is_file() or p.is_symlink():raise RuntimeError('Arquivo de producao inseguro/ausente: '+str(p))
    if not all(x in PAGE.read_bytes() for x in (b'id="wifi-tab"',b'id="wifi"',b'acs-wifi-reference-grid',b'fbOpenLegacyWifi')):
        raise RuntimeError('Aba Wi-Fi real nao encontrada')
    oldjs=JS.read_bytes();newjs=patch(oldjs)
    oldfoot=FOOT.read_bytes()
    if oldfoot.count(LINK)>1:raise RuntimeError('CSS duplicado')
    if LINK in oldfoot:newfoot=oldfoot
    else:
        if oldfoot.count(b'</body>')!=1:raise RuntimeError('Footer desconhecido')
        newfoot=oldfoot.replace(b'</body>',INCLUDE+b'</body>',1)
    if CSS.is_symlink():raise RuntimeError('Destino CSS simbolico')
    oldcss=CSS.read_bytes() if CSS.is_file() else None
    newcss=SRC.read_bytes()
    if oldcss is not None and oldcss!=newcss:raise RuntimeError('CSS destino divergente')
    if subprocess.run(['php','-l'],input=newfoot,capture_output=True).returncode:
        raise RuntimeError('PHP footer invalido')
    if newjs!=oldjs:
        if not shutil.which('node'):raise RuntimeError('Node.js necessario para validar JS')
        fd,tmp=tempfile.mkstemp(suffix='.js')
        try:
            with os.fdopen(fd,'wb') as fp:fp.write(newjs)
            check=subprocess.run(['node','--check',tmp],capture_output=True,text=True)
            if check.returncode:raise RuntimeError('JS invalido: '+check.stderr[:450])
        finally:os.unlink(tmp)
    return [(p,a,b) for p,a,b in ((JS,oldjs,newjs),(CSS,oldcss,newcss),(FOOT,oldfoot,newfoot)) if a!=b]
def atomic(path,data):
    path.parent.mkdir(parents=True,exist_ok=True)
    fd,tmp=tempfile.mkstemp(prefix='.jrc-wifi-',dir=path.parent)
    try:
        with os.fdopen(fd,'wb') as fp:fp.write(data);fp.flush();os.fsync(fp.fileno())
        if path.is_file():
            st=path.stat();os.chmod(tmp,st.st_mode&0o777);os.chown(tmp,st.st_uid,st.st_gid)
        else:os.chmod(tmp,0o644)
        os.replace(tmp,path)
    finally:
        if os.path.exists(tmp):os.unlink(tmp)
def main():
    args=argparse.ArgumentParser();args.add_argument('mode',choices=('plan','apply'))
    mode=args.parse_args().mode
    changes=inspect()
    if mode=='plan':
        for p,old,_ in changes:print(('CRIAR: ' if old is None else 'ALTERAR: ')+str(p))
        print('PLAN_OK' if changes else 'JA_INSTALADO');return
    if not changes:print('JA_INSTALADO');return
    backup=Path('/var/backups/jrc-device-wifi-reference')/datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')
    backup.mkdir(parents=True,mode=0o700,exist_ok=False)
    applied=[]
    try:
        for p,old,new in changes:
            if (p.read_bytes() if p.is_file() else None)!=old:raise RuntimeError('Arquivo alterado durante deploy: '+str(p))
            if old is not None:
                dest=backup/p.relative_to(ROOT);dest.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(p,dest)
            atomic(p,new);applied.append((p,old));print('ATUALIZADO:',p.relative_to(ROOT))
        print('APLICADO_OK');print('BACKUP:',backup)
        print('ESCOPO: aba Wi-Fi, edicao e outras abas preservadas')
    except BaseException:
        for p,old in reversed(applied):
            if old is None:p.unlink(missing_ok=True)
            else:shutil.copy2(backup/p.relative_to(ROOT),p)
        raise
if __name__=='__main__':main()
