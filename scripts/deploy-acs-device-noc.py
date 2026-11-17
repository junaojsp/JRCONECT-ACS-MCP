#!/usr/bin/env python3
"""Safe deployment for parallel, read-only NOC device pilot. Never touches existing device-noc.php."""
import argparse
import os
import shutil
import subprocess
import tempfile
from datetime import datetime, timezone
from pathlib import Path

SOURCE=Path(__file__).resolve().parent.parent
TARGET=Path('/var/www/gacs')
FILES=('device-noc-v2.php','assets/css/jrc-device-noc.css','assets/js/jrc-device-noc.js')
def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('mode',choices=('plan','apply'))
    args=parser.parse_args()
    if not (TARGET/'config/config.php').is_file():
        raise RuntimeError('ACS nao encontrado')
    changes=[]
    for name in FILES:
        src=SOURCE/name
        dst=TARGET/name
        if not src.is_file() or dst.is_symlink() or (dst.exists() and not dst.is_file()):
            raise RuntimeError('Caminho inseguro: '+name)
        if name.endswith('.php') and subprocess.run(['php','-l',str(src)],capture_output=True).returncode:
            raise RuntimeError('PHP invalido: '+name)
        incoming=src.read_bytes()
        current=dst.read_bytes() if dst.exists() else None
        if incoming==current:continue
        if current is not None:
            raise RuntimeError('Arquivo existente diferente, nao sobrescrito: '+name)
        changes.append((name,dst,incoming))
    if args.mode=='plan':
        for name,_,_ in changes:print('CRIAR:',name)
        print('PLAN_OK' if changes else 'JA_INSTALADO')
        return
    created=[]
    try:
        for name,dst,incoming in changes:
            dst.parent.mkdir(parents=True,exist_ok=True)
            fd,temporary=tempfile.mkstemp(prefix='.jrc-noc-',dir=dst.parent)
            try:
                with os.fdopen(fd,'wb') as output:
                    output.write(incoming)
                    output.flush()
                    os.fsync(output.fileno())
                os.chmod(temporary,0o644)
                if dst.exists() or dst.is_symlink():
                    raise RuntimeError('Alteracao concorrente: '+name)
                os.replace(temporary,dst)
                created.append(dst)
                print('INSTALADO:',name)
            finally:
                if os.path.exists(temporary):os.unlink(temporary)
        print('APLICADO_OK')
        print('NAO ALTERADOS: device-noc.php, device-detail.php, WAN, Wi-Fi, reboot, senha, firmware e endpoints')
    except BaseException:
        for dst in reversed(created):dst.unlink(missing_ok=True)
        raise

if __name__=='__main__':
    main()
