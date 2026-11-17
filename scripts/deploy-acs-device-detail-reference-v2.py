#!/usr/bin/env python3
"""Install device detail NOC visual refinement v2 without changing operational PHP/JS."""
import argparse
import os
import shutil
import subprocess
import tempfile
from datetime import datetime, timezone
from pathlib import Path

SOURCE=Path(__file__).resolve().parent.parent
WEB=Path('/var/www/gacs')
FOOTER=WEB/'views/layouts/footer.php'
FILES=('assets/css/jrc-device-detail-noc-v2.css','assets/js/jrc-device-detail-noc-v2.js')
OLD_CSS=b'/assets/css/jrc-device-detail-noc-20.css?v=1'
OLD_JS=b'/assets/js/jrc-device-detail-noc-20.js?v=1'
NEW_CSS=b'/assets/css/jrc-device-detail-noc-v2.css?v=1'
NEW_JS=b'/assets/js/jrc-device-detail-noc-v2.js?v=1'
MARKER=b'<!-- JRC device detail dark NOC v1 -->'

def inspect():
    page=WEB/'device-detail.php'
    if not page.is_file() or page.is_symlink() or not FOOTER.is_file() or FOOTER.is_symlink():
        raise RuntimeError('ACS nao encontrado ou links inseguros')
    html=page.read_bytes()
    if not all(marker in html for marker in (b'id="deviceTabs"',b'id="deviceTabContent"',b'id="wan-tab"')):
        raise RuntimeError('Estrutura detalhes alterada; nenhuma alteracao feita')
    old=FOOTER.read_bytes()
    if old.count(MARKER)!=1:
        raise RuntimeError('Adapter anterior nao encontrado exatamente uma vez')
    if OLD_CSS in old or OLD_JS in old:
        if old.count(OLD_CSS)!=1 or old.count(OLD_JS)!=1 or NEW_CSS in old or NEW_JS in old:
            raise RuntimeError('Links da versao anterior ambiguos')
        updated=old.replace(OLD_CSS,NEW_CSS,1).replace(OLD_JS,NEW_JS,1)
    elif old.count(NEW_CSS)==1 and old.count(NEW_JS)==1:
        updated=old
    else:
        raise RuntimeError('CSS e JS do adapter nao reconhecidos')
    if subprocess.run(['php','-l'],input=updated,capture_output=True).returncode:
        raise RuntimeError('PHP invalido apos mudanca do footer')
    pending=[]
    for name in FILES:
        dest=WEB/name
        if dest.is_symlink():raise RuntimeError('Destino inseguro: '+name)
        existing=dest.read_bytes() if dest.is_file() else None
        content=(SOURCE/name).read_bytes()
        if existing is not None and existing!=content:
            raise RuntimeError('Asset personalizado existente: '+name)
        if existing!=content:pending.append((name,dest,existing,content))
    if old!=updated:pending.append(('views/layouts/footer.php',FOOTER,old,updated))
    return pending

def write(dest,payload,old):
    dest.parent.mkdir(parents=True,exist_ok=True)
    fd,temp=tempfile.mkstemp(prefix='.jrc-detail-v2-',dir=dest.parent)
    try:
        with os.fdopen(fd,'wb') as out:
            out.write(payload)
            out.flush()
            os.fsync(out.fileno())
        if old is None:
            os.chmod(temp,0o644)
        else:
            st=dest.stat()
            os.chmod(temp,st.st_mode & 0o777)
            os.chown(temp,st.st_uid,st.st_gid)
        os.replace(temp,dest)
    finally:
        if os.path.exists(temp):os.unlink(temp)

def main():
    ap=argparse.ArgumentParser()
    ap.add_argument('mode',choices=('plan','apply'))
    mode=ap.parse_args().mode
    pending=inspect()
    if mode=='plan':
        for name,_,old,_ in pending:print('CRIAR:' if old is None else 'ATUALIZAR:',name)
        print('PLAN_OK' if pending else 'JA_APLICADO')
        return
    if not pending:
        print('JA_APLICADO')
        return
    backup=Path('/var/backups/jrc-device-detail-refinement-v2')/datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')
    backup.mkdir(parents=True,exist_ok=False,mode=0o700)
    applied=[]
    try:
        for name,dest,old,new in pending:
            if old is not None:
                saved=backup/name
                saved.parent.mkdir(parents=True,exist_ok=True)
                shutil.copy2(dest,saved)
            if dest.is_symlink() or (dest.read_bytes() if dest.is_file() else None)!=old:
                raise RuntimeError('Arquivo alterado concorrentemente: '+name)
            write(dest,new,old)
            applied.append((name,dest,old))
            print('ATUALIZADO:',name)
        print('APLICADO_OK')
        print('BACKUP:',backup)
        print('ESCOPO: tela interna de dispositivo - visual; sem alteracao operacional')
    except BaseException:
        for name,dest,old in reversed(applied):
            if old is None:dest.unlink(missing_ok=True)
            else:shutil.copy2(backup/name,dest)
        raise

if __name__=='__main__':
    main()
