#!/usr/bin/env python3
"""Deploy actual production device-detail fb/acs component polish, never operational code."""
import argparse
import os
import shutil
import subprocess
import tempfile
from datetime import datetime, timezone
from pathlib import Path

SOURCE=Path(__file__).resolve().parent.parent
WEB=Path('/var/www/gacs')
PAGE=WEB/'device-detail.php'
FOOTER=WEB/'views/layouts/footer.php'
FILES=('assets/css/jrc-device-detail-live-v4.css','assets/js/jrc-device-detail-live-v4.js')
V3_CSS=b'/assets/css/jrc-device-detail-noc-v3.css?v=1'
V3_JS=b'/assets/js/jrc-device-detail-noc-v3.js?v=1'
V4_CSS=b'/assets/css/jrc-device-detail-live-v4.css?v=1'
V4_JS=b'/assets/js/jrc-device-detail-live-v4.js?v=1'
MARKER=b'<!-- JRC device detail dark NOC v1 -->'
def get_changes():
    if PAGE.is_symlink() or not PAGE.is_file() or not FOOTER.is_file() or FOOTER.is_symlink():
        raise RuntimeError('Arquivos da producao ausentes ou inseguros')
    page=PAGE.read_bytes()
    # The installed production detail has its eight tab IDs in PHP and
    # renders the WAN panel through assets/js/device-detail.js. Previously
    # checking 'acs-wan-page' in PHP always rejected this valid installation.
    page_required=(b'id="deviceTabs"', b'id="deviceTabContent"',
                   b'id="overview-tab"', b'id="wan-tab"',
                   b'id="wifi-tab"', b'id="devices-tab"')
    missing_page=[x.decode('ascii') for x in page_required if x not in page]
    if missing_page:
        raise RuntimeError('Detalhamento nao reconhecido no PHP: '+', '.join(missing_page))
    renderer=WEB/'assets/js/device-detail.js'
    if not renderer.is_file() or renderer.is_symlink():
        raise RuntimeError('Renderizador de detalhes ausente ou inseguro')
    renderer_code=renderer.read_bytes()
    renderer_required=(b'acs-wan-page', b'fb-diag-item',
                       b'renderWANDetailsTab', b'overview-content')
    missing_renderer=[x.decode('ascii') for x in renderer_required
                      if x not in renderer_code]
    if missing_renderer:
        raise RuntimeError('Renderizador nao reconhecido: '+', '.join(missing_renderer))
    old=FOOTER.read_bytes()
    if old.count(MARKER)!=1:
        raise RuntimeError('Ancora do adapter v3 nao reconhecida')
    if old.count(V3_CSS)==1 and old.count(V3_JS)==1 and V4_CSS not in old and V4_JS not in old:
        new=old.replace(V3_CSS,V4_CSS,1).replace(V3_JS,V4_JS,1)
    elif old.count(V4_CSS)==1 and old.count(V4_JS)==1 and V3_CSS not in old and V3_JS not in old:
        new=old
    else:
        raise RuntimeError('Links CSS/JS da pagina nao reconhecidos; nenhum arquivo alterado')
    if subprocess.run(['php','-l'],input=new,capture_output=True).returncode:
        raise RuntimeError('PHP do footer invalido')
    pending=[]
    for name in FILES:
        dest=WEB/name
        if dest.is_symlink():raise RuntimeError('Destino inseguro: '+name)
        old_file=dest.read_bytes() if dest.is_file() else None
        new_file=(SOURCE/name).read_bytes()
        if old_file is not None and old_file!=new_file:
            raise RuntimeError('Asset ja alterado na producao: '+name)
        if old_file!=new_file:
            pending.append((name,dest,old_file,new_file))
    if old!=new:pending.append(('views/layouts/footer.php',FOOTER,old,new))
    return pending
def write_atomic(dest,data,old):
    dest.parent.mkdir(parents=True,exist_ok=True)
    fd,temp=tempfile.mkstemp(prefix='.jrc-detail-v4-',dir=dest.parent)
    try:
        with os.fdopen(fd,'wb') as stream:
            stream.write(data);stream.flush();os.fsync(stream.fileno())
        if old is not None:
            st=dest.stat()
            os.chmod(temp,st.st_mode&0o777)
            os.chown(temp,st.st_uid,st.st_gid)
        else:os.chmod(temp,0o644)
        os.replace(temp,dest)
    finally:
        if os.path.exists(temp):os.unlink(temp)
def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('mode',choices=('plan','apply'))
    mode=parser.parse_args().mode
    pending=get_changes()
    if mode=='plan':
        for name,_,old,_ in pending:
            print('CRIAR:' if old is None else 'ATUALIZAR:',name)
        print('PLAN_OK' if pending else 'JA_INSTALADO')
        return
    if not pending:
        print('JA_INSTALADO')
        return
    backup=Path('/var/backups/jrc-device-detail-live-v4')/datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')
    backup.mkdir(parents=True,mode=0o700,exist_ok=False)
    applied=[]
    try:
        for name,dest,old,new in pending:
            if old is not None:
                saved=backup/name
                saved.parent.mkdir(parents=True,exist_ok=True)
                shutil.copy2(dest,saved)
            if dest.is_symlink() or (dest.read_bytes() if dest.is_file() else None)!=old:
                raise RuntimeError('Arquivo alterado durante implantacao: '+name)
            write_atomic(dest,new,old)
            applied.append((name,dest,old))
            print('ATUALIZADO:',name)
        print('APLICADO_OK')
        print('BACKUP:',backup)
        print('ESCOPO: detalhamento do dispositivo real, somente CSS/JS visual')
    except BaseException:
        for name,dest,old in reversed(applied):
            if old is None:dest.unlink(missing_ok=True)
            else:shutil.copy2(backup/name,dest)
        raise
if __name__=='__main__':
    main()
