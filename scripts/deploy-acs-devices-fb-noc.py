#!/usr/bin/env python3
"""Deploy NOC skin for the actual customized fb-devices.php production DOM."""
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
CSS='assets/css/jrc-devices-fb-noc.css'
JS='assets/js/jrc-devices-fb-noc.js'
MARKER=b'<!-- JRC devices fb-layout adapter v1 -->'
INJECT=b'''    <?php if (basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'devices.php'): ?>
    <!-- JRC devices fb-layout adapter v1 -->
    <link rel="stylesheet" href="/assets/css/jrc-devices-fb-noc.css?v=1">
    <script src="/assets/js/jrc-devices-fb-noc.js?v=1" defer></script>
    <?php endif; ?>
'''
def php_valid(payload):
    return subprocess.run(['php','-l'],input=payload,capture_output=True).returncode==0

def changes():
    page=WEB/'devices.php'
    if not (WEB/'config/config.php').is_file() or not page.is_file() or page.is_symlink():
        raise RuntimeError('ACS ou dispositivos nao encontrados')
    # Live page is customized fb-devices, not jrc-inventory-page.
    current=page.read_bytes()
    required=(b'fb-devices',b'id="deviceTypeTabs"',b'id="devices-table"',b'id="search-input"')
    if not all(m in current for m in required):
        raise RuntimeError('Estrutura fb-devices esperada nao encontrada; nenhuma alteracao')
    footer=WEB/FOOTER
    if not footer.is_file() or footer.is_symlink():
        raise RuntimeError('Footer ausente ou inseguro')
    old_footer=footer.read_bytes()
    if MARKER in old_footer:
        if old_footer.count(MARKER)!=1 or b'jrc-devices-fb-noc.js?v=1' not in old_footer:
            raise RuntimeError('Adapter do footer inconsistente')
        new_footer=old_footer
    else:
        if old_footer.count(b'</body>')!=1:raise RuntimeError('Footer modificado fora do esperado')
        new_footer=old_footer.replace(b'</body>',INJECT+b'</body>',1)
    if not php_valid(new_footer):raise RuntimeError('PHP invalido na versao proposta')
    pending=[]
    for name in (CSS,JS):
        dst=WEB/name
        if dst.is_symlink():raise RuntimeError('Destino inseguro: '+name)
        old=dst.read_bytes() if dst.is_file() else None
        new=(SOURCE/name).read_bytes()
        if old is not None and old!=new:
            raise RuntimeError('Arquivo existente modificado, nao sobrescrito: '+name)
        if old!=new:pending.append((name,dst,old,new))
    if new_footer!=old_footer:pending.append((FOOTER,footer,old_footer,new_footer))
    return pending

def atomic(target,payload,old):
    target.parent.mkdir(parents=True,exist_ok=True)
    fd,tmp=tempfile.mkstemp(prefix='.jrc-fb-',dir=target.parent)
    try:
        with os.fdopen(fd,'wb') as out:
            out.write(payload);out.flush();os.fsync(out.fileno())
        if old is not None:
            meta=target.stat()
            os.chmod(tmp,meta.st_mode & 0o777)
            os.chown(tmp,meta.st_uid,meta.st_gid)
        else:os.chmod(tmp,0o644)
        os.replace(tmp,target)
    finally:
        if os.path.exists(tmp):os.unlink(tmp)
def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('mode',choices=('plan','apply'))
    mode=parser.parse_args().mode
    pending=changes()
    if mode=='plan':
        for name,_,old,_ in pending:print('CRIAR:' if old is None else 'ATUALIZAR:',name)
        print('PLAN_OK' if pending else 'JA_INSTALADO')
        return
    if not pending:
        print('JA_INSTALADO');return
    backup=Path('/var/backups/jrc-devices-fb-noc')/datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')
    backup.mkdir(parents=True,exist_ok=False,mode=0o700)
    applied=[]
    try:
        for name,dst,old,new in pending:
            if old is not None:
                saved=backup/name
                saved.parent.mkdir(parents=True,exist_ok=True)
                shutil.copy2(dst,saved)
            if dst.is_symlink() or (dst.read_bytes() if dst.is_file() else None)!=old:
                raise RuntimeError('Arquivo alterado durante implantacao: '+name)
            atomic(dst,new,old)
            applied.append((name,dst,old))
            print('ATUALIZADO:',name)
        print('APLICADO_OK')
        print('BACKUP:',backup)
        print('ESCOPO: fb-devices visual somente. Nenhuma consulta de sinal na lista.')
    except BaseException:
        for name,dst,old in reversed(applied):
            if old is None:dst.unlink(missing_ok=True)
            else:shutil.copy2(backup/name,dst)
        raise

if __name__=='__main__':main()
