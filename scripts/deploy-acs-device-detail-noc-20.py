#!/usr/bin/env python3
"""Safely add visual NOC layout to production device detail without replacing device handlers."""
import argparse
import os
import shutil
import subprocess
import tempfile
from datetime import datetime, timezone
from pathlib import Path

SOURCE=Path(__file__).resolve().parent.parent
WEB=Path('/var/www/gacs')
FOOTER='views/layouts/footer.php'
CSS='assets/css/jrc-device-detail-noc-20.css'
JS='assets/js/jrc-device-detail-noc-20.js'
MARKER=b'<!-- JRC device detail dark NOC v1 -->'
INJECT=b'''    <?php if (basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'device-detail.php'): ?>
    <!-- JRC device detail dark NOC v1 -->
    <link rel="stylesheet" href="/assets/css/jrc-device-detail-noc-20.css?v=1">
    <script src="/assets/js/jrc-device-detail-noc-20.js?v=1" defer></script>
    <?php endif; ?>
'''
def lint(contents):
    return subprocess.run(['php','-l'],input=contents,capture_output=True).returncode==0

def get_changes():
    page=WEB/'device-detail.php'
    if not (WEB/'config/config.php').is_file() or not page.is_file() or page.is_symlink():
        raise RuntimeError('Instalacao ou detalhes de dispositivo ausentes')
    markup=page.read_bytes()
    # Only install for known existing Bootstrap tabs; do not overwrite the customized
    # WAN/Wi-Fi/device operations with an unverified replacement.
    if not all(token in markup for token in (b'id="deviceTabs"',b'id="deviceTabContent"',b'id="overview-tab"',b'id="wan-tab"')):
        raise RuntimeError('Estrutura de detalhes personalizada: revisao necessaria (sem alteracoes)')
    footer=WEB/FOOTER
    if not footer.is_file() or footer.is_symlink():
        raise RuntimeError('Footer ausente ou inseguro')
    old=footer.read_bytes()
    if MARKER in old:
        if old.count(MARKER)!=1 or b'jrc-device-detail-noc-20.js?v=1' not in old:
            raise RuntimeError('Instalacao anterior inconsistente')
        updated=old
    else:
        if old.count(b'</body>')!=1:raise RuntimeError('Footer nao reconhecido')
        updated=old.replace(b'</body>',INJECT+b'</body>',1)
    if not lint(updated):raise RuntimeError('PHP invalido apos alteracao')
    pending=[]
    for name in (CSS,JS):
        dest=WEB/name
        if dest.is_symlink():raise RuntimeError('Destino inseguro: '+name)
        current=dest.read_bytes() if dest.is_file() else None
        proposed=(SOURCE/name).read_bytes()
        if current is not None and current!=proposed:
            raise RuntimeError('Asset diferente ja existe (nao sobrescrito): '+name)
        if current!=proposed:pending.append((name,dest,current,proposed))
    if old!=updated:pending.append((FOOTER,footer,old,updated))
    return pending

def atomic_write(dest,data,original):
    dest.parent.mkdir(parents=True,exist_ok=True)
    fd,temp=tempfile.mkstemp(prefix='.jrc-device-noc-',dir=dest.parent)
    try:
        with os.fdopen(fd,'wb') as out:
            out.write(data);out.flush();os.fsync(out.fileno())
        if original is None:os.chmod(temp,0o644)
        else:
            st=dest.stat()
            os.chmod(temp,st.st_mode & 0o777)
            os.chown(temp,st.st_uid,st.st_gid)
        os.replace(temp,dest)
    finally:
        if os.path.exists(temp):os.unlink(temp)

def main():
    p=argparse.ArgumentParser()
    p.add_argument('mode',choices=('plan','apply'))
    mode=p.parse_args().mode
    changes=get_changes()
    if mode=='plan':
        for name,_,old,_ in changes:print('CRIAR:' if old is None else 'ATUALIZAR:',name)
        print('PLAN_OK' if changes else 'JA_APLICADO')
        return
    if not changes:
        print('JA_APLICADO')
        return
    backup=Path('/var/backups/jrc-device-detail-noc')/datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')
    backup.mkdir(parents=True,exist_ok=False,mode=0o700)
    applied=[]
    try:
        for name,dest,old,new in changes:
            if old is not None:
                saved=backup/name
                saved.parent.mkdir(parents=True,exist_ok=True)
                shutil.copy2(dest,saved)
            if dest.is_symlink() or (dest.read_bytes() if dest.is_file() else None)!=old:
                raise RuntimeError('Arquivo alterado durante deploy: '+name)
            atomic_write(dest,new,old)
            applied.append((name,dest,old))
            print('ATUALIZADO:',name)
        print('APLICADO_OK')
        print('BACKUP:',backup)
        print('ESCOPO: estilo e navegacao de detalhes; nenhuma funcao de dispositivo alterada')
    except BaseException:
        for name,dest,old in reversed(applied):
            if old is None:dest.unlink(missing_ok=True)
            else:shutil.copy2(backup/name,dest)
        raise
if __name__=='__main__':main()
