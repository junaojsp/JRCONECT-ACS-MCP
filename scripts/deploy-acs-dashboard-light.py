#!/usr/bin/env python3
"""Create-only deployment of parallel, lightweight ACS dashboard."""
from pathlib import Path
import argparse
import os
import subprocess
import tempfile

ROOT=Path('/var/www/gacs')
SOURCE=Path(__file__).resolve().parent.parent
FILES=['dashboard-light.php','assets/js/jrc-dashboard-light.js']

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('mode',choices=['plan','apply'])
    args=parser.parse_args()
    if not (ROOT/'config/config.php').is_file():
        raise SystemExit('BLOQUEADO: ACS nao encontrado')
    for name in FILES:
        src=SOURCE/name
        dst=ROOT/name
        if not src.is_file() or dst.is_symlink():
            raise SystemExit('BLOQUEADO: arquivo invalido '+name)
        if dst.exists() and (not dst.is_file() or dst.read_bytes()!=src.read_bytes()):
            raise SystemExit('BLOQUEADO: destino personalizado '+name)
        if name.endswith('.php') and subprocess.run(['php','-l',str(src)],capture_output=True).returncode:
            raise SystemExit('BLOQUEADO: erro de sintaxe '+name)
    if args.mode=='plan':
        print('PLAN_OK: dashboard atual preservado')
        return
    for name in FILES:
        dst=ROOT/name
        if dst.exists():
            print('JA_INSTALADO:',name)
            continue
        dst.parent.mkdir(parents=True,exist_ok=True)
        fd,tmp=tempfile.mkstemp(dir=dst.parent,prefix='.jrc-dashboard-light-')
        try:
            with os.fdopen(fd,'wb') as f:
                f.write((SOURCE/name).read_bytes())
                f.flush()
                os.fsync(f.fileno())
            os.chmod(tmp,0o644)
            if dst.exists():
                raise SystemExit('BLOQUEADO: arquivo alterado '+name)
            os.replace(tmp,dst)
            print('INSTALADO:',name)
        finally:
            if os.path.exists(tmp):
                os.unlink(tmp)
    print('APLICADO_OK: /dashboard-light.php')
    print('PRESERVADO: dashboard.php, devices.php, device-detail.php, TR-069, WAN, Wi-Fi, IXC, MCP')

if __name__=='__main__':
    main()
