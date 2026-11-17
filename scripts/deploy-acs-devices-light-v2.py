#!/usr/bin/env python3
"""Guarded upgrade of the parallel devices-light pilot to v2."""
from pathlib import Path
from datetime import datetime, timezone
import argparse, os, shutil, subprocess, tempfile

ROOT=Path('/var/www/gacs')
SOURCE=Path(__file__).resolve().parent.parent
FILES={
 'devices-light.php':('jrc-devices-light.js?v=1','jrc-devices-light.js?v=2'),
 'assets/js/jrc-devices-light.js':('lightweight device directory.', 'light CPE list v2'),
 'api/jrc-devices-light.php':('Inventario desatualizado; nao mostrar valores como atuais',
                              "'stale'=>$stale"),
}
def fail(message): raise SystemExit('BLOQUEADO: '+message)
def changes():
    if not (ROOT/'config/config.php').is_file():fail('Instalacao ACS ausente')
    ret=[]
    for name,(old_marker,new_marker) in FILES.items():
        target=ROOT/name;src=SOURCE/name
        if not src.is_file() or not target.is_file() or target.is_symlink():
            fail('Arquivo ausente ou link: '+name)
        original=target.read_bytes();new=src.read_bytes()
        if original==new:continue
        if old_marker.encode() not in original or new_marker.encode() not in new:
            fail('Versao personalizada nao reconhecida: '+name)
        if name.endswith('.php'):
            with tempfile.NamedTemporaryFile(suffix='.php') as tmp:
                tmp.write(new);tmp.flush()
                if subprocess.run(['php','-l',tmp.name],capture_output=True).returncode:
                    fail('Falha php-lint: '+name)
        ret.append((target,original,new))
    return ret
def apply(items):
    if not items:
        print('JA_INSTALADO');return
    backup=Path('/var/backups/jrc-devices-light-v2')/datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')
    backup.mkdir(parents=True,mode=0o700)
    done=[]
    try:
        for target,original,new in items:
            saved=backup/target.name
            shutil.copy2(target,saved)
            st=target.stat()
            fd,tmp=tempfile.mkstemp(dir=target.parent,prefix='.jrc-light-v2-')
            try:
                with os.fdopen(fd,'wb') as fp:
                    fp.write(new);fp.flush();os.fsync(fp.fileno())
                os.chmod(tmp,st.st_mode&0o777);os.chown(tmp,st.st_uid,st.st_gid)
                if target.is_symlink() or target.read_bytes()!=original:
                    fail('Arquivo alterado durante operacao: '+target.name)
                os.replace(tmp,target)
                done.append((target,saved))
                print('ATUALIZADO:',target)
            finally:
                if os.path.exists(tmp):os.unlink(tmp)
        print('APLICADO_OK')
        print('BACKUP:',backup)
        print('PRESERVADO: devices.php, dashboard.php, detalhe ONU, TR-069, WAN, Wi-Fi, firmware, MCP')
    except BaseException:
        for target,saved in reversed(done):shutil.copy2(saved,target)
        raise
def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('mode',choices=['plan','apply'])
    args=parser.parse_args()
    items=changes()
    if args.mode=='plan':
        for target,_,_ in items:print('ALTERAR:',target)
        print('PLAN_OK' if items else 'JA_INSTALADO')
    else:apply(items)
if __name__=='__main__':main()
