#!/usr/bin/env python3
"""Guarded deployment for reference-inspired dashboard charts, parallel light page only."""
import argparse
from datetime import datetime, timezone
from pathlib import Path
import os, shutil, subprocess, tempfile

SRC=Path(__file__).resolve().parent.parent
ROOT=Path('/var/www/gacs')
FILES=('dashboard-light.php','assets/js/jrc-dashboard-light.js','api/jrc-dashboard-reference-charts.php')
OLD_MARKERS={
 'dashboard-light.php':'jrc-dashboard-light.js?v=1',
 'assets/js/jrc-dashboard-light.js':'light dashboard: one snapshot metadata request'
}
def fail(msg):raise SystemExit('BLOQUEADO: '+msg)
def changes():
    if not (ROOT/'config/config.php').is_file():fail('ACS ausente')
    delta=[]
    for name in FILES:
        origin=SRC/name;dest=ROOT/name
        if not origin.is_file() or dest.is_symlink():fail('caminho invalido '+name)
        new=origin.read_bytes()
        if name.endswith('.php') and subprocess.run(['php','-l',str(origin)],capture_output=True).returncode:
            fail('PHP invalido '+name)
        if dest.exists():
            if not dest.is_file():fail('destino nao e arquivo '+name)
            old=dest.read_bytes()
            if old==new:continue
            if name not in OLD_MARKERS or OLD_MARKERS[name].encode() not in old:
                fail('personalizacao desconhecida '+name)
        else:
            if name in OLD_MARKERS:fail('pagina piloto anterior ausente '+name)
            old=None
        delta.append((dest,old,new))
    return delta
def deploy(delta):
    if not delta:print('JA_INSTALADO');return
    backup=Path('/var/backups/jrc-dashboard-light-charts')/datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')
    backup.mkdir(parents=True,mode=0o700)
    complete=[]
    try:
        for dest,old,new in delta:
            if old is not None:shutil.copy2(dest,backup/dest.name)
            previous=dest.stat() if old is not None else None
            fd,tmp=tempfile.mkstemp(prefix='.jrc-chart-',dir=dest.parent)
            try:
                with os.fdopen(fd,'wb') as handle:
                    handle.write(new);handle.flush();os.fsync(handle.fileno())
                os.chmod(tmp,(previous.st_mode&0o777) if previous else 0o644)
                if previous:os.chown(tmp,previous.st_uid,previous.st_gid)
                if dest.is_symlink() or (dest.read_bytes() if dest.exists() else None)!=old:
                    fail('alteracao concorrente '+dest.name)
                os.replace(tmp,dest)
                complete.append((dest,old))
                print('ATUALIZADO:',dest)
            finally:
                if os.path.exists(tmp):os.unlink(tmp)
        print('APLICADO_OK')
        print('BACKUP:',backup)
        print('PRESERVADOS: dashboard.php, devices.php, device-detail.php, TR-069, WAN, Wi-Fi, IXC, MCP')
    except BaseException:
        for dest,old in reversed(complete):
            if old is None:dest.unlink(missing_ok=True)
            else:shutil.copy2(backup/dest.name,dest)
        raise
def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('mode',choices=('plan','apply'))
    args=parser.parse_args()
    delta=changes()
    if args.mode=='plan':
        for dest,_,_ in delta:print('ALTERAR:',dest)
        print('PLAN_OK' if delta else 'JA_INSTALADO')
    else:deploy(delta)
if __name__=='__main__':main()
