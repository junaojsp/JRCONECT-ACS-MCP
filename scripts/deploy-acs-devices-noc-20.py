#!/usr/bin/env python3
"""Safely deploy device-page-only NOC styling without replacing production device features."""
import argparse
import os
import shutil
import subprocess
import tempfile
from datetime import datetime, timezone
from pathlib import Path

SOURCE=Path(__file__).resolve().parent.parent
WEB=Path('/var/www/gacs')
CSS='assets/css/jrc-devices-noc-20.css'
FOOTER='views/layouts/footer.php'
MARKER=b'<!-- JR Conect ACS 2.0: approved NOC palette on device list only. -->'
INJECT=b'''    <?php if (basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'devices.php'): ?>
    <!-- JR Conect ACS 2.0: approved NOC palette on device list only. -->
    <link rel="stylesheet" href="/assets/css/jrc-devices-noc-20.css?v=1">
    <?php endif; ?>
'''
def php_valid(contents):
    return subprocess.run(['php','-l'],input=contents,capture_output=True).returncode==0
def plan_changes():
    if not (WEB/'config/config.php').is_file() or not (WEB/'devices.php').is_file():
        raise RuntimeError('ACS ou pagina de dispositivos ausente')
    css_target=WEB/CSS
    footer=WEB/FOOTER
    if css_target.is_symlink() or footer.is_symlink() or not footer.is_file():
        raise RuntimeError('Destino inseguro')
    contents=(SOURCE/CSS).read_bytes()
    css_old=css_target.read_bytes() if css_target.is_file() else None
    if css_old is not None and css_old!=contents:
        raise RuntimeError('CSS ja existe com conteudo diferente; revisao necessaria')
    old_footer=footer.read_bytes()
    if MARKER in old_footer:
        if old_footer.count(MARKER)!=1 or b'jrc-devices-noc-20.css?v=1' not in old_footer:
            raise RuntimeError('Marcador duplicado ou incompleto no rodape')
        new_footer=old_footer
    else:
        if old_footer.count(b'</body>')!=1:
            raise RuntimeError('Rodape personalizado nao reconhecido; sem alteracoes')
        new_footer=old_footer.replace(b'</body>',INJECT+b'</body>',1)
    if not php_valid(new_footer):
        raise RuntimeError('Rodape PHP invalido depois do patch')
    changes=[]
    if css_old!=contents: changes.append((CSS,css_target,css_old,contents))
    if old_footer!=new_footer: changes.append((FOOTER,footer,old_footer,new_footer))
    return changes
def atomic(dst,payload,reference=None):
    dst.parent.mkdir(parents=True,exist_ok=True)
    fd,tmp=tempfile.mkstemp(prefix='.jrc-devices-',dir=dst.parent)
    try:
        with os.fdopen(fd,'wb') as stream:
            stream.write(payload)
            stream.flush()
            os.fsync(stream.fileno())
        if reference and reference.is_file():
            stat=reference.stat()
            os.chmod(tmp,stat.st_mode & 0o777)
            os.chown(tmp,stat.st_uid,stat.st_gid)
        else: os.chmod(tmp,0o644)
        os.replace(tmp,dst)
    finally:
        if os.path.exists(tmp):os.unlink(tmp)
def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('mode',choices=('plan','apply'))
    mode=parser.parse_args().mode
    changes=plan_changes()
    if mode=='plan':
        for name,_,_,_ in changes:print('ALTERAR:' if name==FOOTER else 'CRIAR:',name)
        print('PLAN_OK' if changes else 'JA_APLICADO')
        return
    if not changes:
        print('JA_APLICADO')
        return
    backup=Path('/var/backups/jrc-devices-noc')/datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')
    backup.mkdir(parents=True,exist_ok=False,mode=0o700)
    applied=[]
    try:
        for name,dst,old,new in changes:
            if old is not None:
                location=backup/name
                location.parent.mkdir(parents=True,exist_ok=True)
                shutil.copy2(dst,location)
            if dst.is_symlink() or (dst.read_bytes() if dst.exists() else None)!=old:
                raise RuntimeError('Arquivo alterado durante implantacao: '+name)
            atomic(dst,new,dst if old is not None else None)
            applied.append((name,dst,old))
            print('ATUALIZADO:',name)
        print('APLICADO_OK')
        print('BACKUP:',backup)
        print('ESCOPO: somente estilo de /devices.php; logica de equipamentos nao alterada')
    except BaseException:
        for name,dst,old in reversed(applied):
            if old is None:dst.unlink(missing_ok=True)
            else:shutil.copy2(backup/name,dst)
        raise
if __name__=='__main__':
    main()
