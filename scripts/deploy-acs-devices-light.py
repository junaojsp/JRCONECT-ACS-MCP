#!/usr/bin/env python3
"""Install the alternative lightweight devices page (create-only; no existing files changed)."""
from pathlib import Path
import argparse
import os
import subprocess
import tempfile

ROOT=Path('/var/www/gacs')
BASE=Path(__file__).resolve().parent.parent
FILES=['devices-light.php','api/jrc-devices-light.php','assets/js/jrc-devices-light.js']

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('mode',choices=['plan','apply'])
    args=parser.parse_args()
    if not (ROOT/'config/config.php').is_file():
        raise SystemExit('BLOQUEADO: ACS nao encontrado')
    for name in FILES:
        src=BASE/name;dest=ROOT/name
        if not src.is_file() or dest.is_symlink():
            raise SystemExit('BLOQUEADO: caminho invalido '+name)
        if dest.exists() and (not dest.is_file() or dest.read_bytes()!=src.read_bytes()):
            raise SystemExit('BLOQUEADO: arquivo existente personalizado '+name)
        if name.endswith('.php'):
            check=subprocess.run(['php','-l',str(src)],capture_output=True,text=True)
            if check.returncode:
                raise SystemExit('BLOQUEADO: PHP invalido '+name)
    if args.mode=='plan':
        print('PLAN_OK: nova pagina independente; atual preservada')
        return
    for name in FILES:
        dest=ROOT/name
        if dest.exists():
            print('JA_INSTALADO:',name)
            continue
        dest.parent.mkdir(parents=True,exist_ok=True)
        fd,tmp=tempfile.mkstemp(dir=dest.parent,prefix='.jrc-light-')
        try:
            with os.fdopen(fd,'wb') as f:
                f.write((BASE/name).read_bytes())
                f.flush();os.fsync(f.fileno())
            os.chmod(tmp,0o644)
            if dest.exists():raise SystemExit('BLOQUEADO: concorrencia '+name)
            os.replace(tmp,dest)
            print('INSTALADO:',name)
        finally:
            if os.path.exists(tmp):os.unlink(tmp)
    print('APLICADO_OK: /devices-light.php')
    print('SEM ALTERAR: dashboard.php, devices.php, detalhe ONU, TR-069, firmware, IXC, MCP')

if __name__=='__main__':main()
