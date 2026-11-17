#!/usr/bin/env python3
"""Fail-closed installer for dashboard-only read deduplication bridge."""
from pathlib import Path
from datetime import datetime, timezone
import argparse
import os
import re
import shutil
import subprocess
import tempfile

SOURCE=Path(__file__).resolve().parent.parent
ROOT=Path('/var/www/gacs')
BACKUPS=Path('/var/backups/jrc-dashboard-bundle-bridge')
PAGE='dashboard.php'
JS='assets/js/jrc-dashboard-bundle-bridge-v2.js'
MARKER='jrc-dashboard-bundle-bridge-v2.js?v=2'
SCRIPT='<script src="/assets/js/'+MARKER+'"></script>\n'

def fail(reason):
    raise SystemExit('BLOQUEADO: '+reason)

def prepare(root):
    if not (root/'config/config.php').is_file():
        fail('ACS nao encontrado')
    page=root/PAGE
    dest=root/JS
    source=SOURCE/JS
    required=[root/'lib/JrcDashboardSnapshotV215.php',root/'api/jrc-dashboard-bundle-v215.php']
    if any(not p.is_file() or p.is_symlink() for p in required):
        fail('Arquivos isolados PR 216 nao instalados')
    if not page.is_file() or page.is_symlink() or dest.is_symlink():
        fail('Dashboard ou asset invalido')
    if not source.is_file():
        fail('JS de integracao nao encontrado')
    data=source.read_bytes()
    if b'JR ACS Dashboard bundle bridge v1' not in data:
        fail('JS inesperado')
    original=page.read_bytes()
    try:
        text=original.decode('utf-8')
    except UnicodeError:
        fail('PHP nao UTF-8')
    if not all(p in text for p in ['uplink-stats.php','recent-devices.php']):
        fail('Dashboard personalizado nao usa as duas rotas esperadas')
    if 'jrc-dashboard-bundle-bridge.js?v=1' in text:
        fail('Bridge v1 presente; nao empilhar versoes')
    if text.count(MARKER)>1:
        fail('Script duplicado')
    if MARKER in text:
        updated=text
    else:
        # The customized production dashboard contains a known, unique early
        # TR-069 helper <script>. Insert the bridge immediately after it, so
        # it is registered before the dashboard's later inline fetch routines.
        # Never depend on the footer's PHP formatting.
        pattern = r'(?m)^[ \\t]*<script[ \\t]+src=[\\x22\\x27]/assets/js/jrc-tr069-apto\\.js\\?v=[^\\x22\\x27]+[\\x22\\x27][ \\t]*></script>[ \\t]*(?:\\r?\\n|$)'
        matches = list(re.finditer(pattern, text))
        if len(matches) != 1:
            fail('Script de referencia TR-069 nao encontrado de forma unica')
        index = matches[0].end()
        updated = text[:index] + SCRIPT + text[index:]
    if updated != text:
        with tempfile.NamedTemporaryFile(suffix='.php') as tmp:
            tmp.write(updated.encode('utf-8'))
            tmp.flush()
            lint=subprocess.run(['php','-l',tmp.name],capture_output=True,text=True)
            if lint.returncode:
                fail('PHP lint: '+lint.stderr.strip())
    if dest.exists() and (not dest.is_file() or dest.read_bytes()!=data):
        fail('JS de destino personalizado: preservar')
    changes=[]
    if not dest.exists():
        changes.append((dest,None,data))
    if updated != text:
        changes.append((page,original,updated.encode('utf-8')))
    return changes

def apply(changes,backuproot):
    if not changes:
        print('JA_INSTALADO')
        return
    backup=backuproot/datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')
    backup.mkdir(parents=True,exist_ok=False,mode=0o700)
    done=[]
    try:
        for target,old,new in changes:
            target.parent.mkdir(parents=True,exist_ok=True)
            saved=backup/target.name
            st=None
            if old is not None:
                shutil.copy2(target,saved)
                st=target.stat()
            fd,tmp=tempfile.mkstemp(prefix='.jrc-dashboard-',dir=target.parent)
            try:
                with os.fdopen(fd,'wb') as out:
                    out.write(new)
                    out.flush()
                    os.fsync(out.fileno())
                if st is not None:
                    os.chmod(tmp,st.st_mode&0o777)
                    os.chown(tmp,st.st_uid,st.st_gid)
                else:
                    os.chmod(tmp,0o644)
                if target.is_symlink() or (target.read_bytes() if target.exists() else None)!=old:
                    fail('Alteracao concorrente: '+target.name)
                os.replace(tmp,target)
                done.append((target,old,saved))
            finally:
                if os.path.exists(tmp): os.unlink(tmp)
        print('APLICADO_OK')
        print('BACKUP:',backup)
        print('PRESERVADO: ONU, WAN, Wi-Fi, reboot, firmware, IXC, MCP')
    except BaseException:
        for target,old,saved in reversed(done):
            if old is None:target.unlink(missing_ok=True)
            else:shutil.copy2(saved,target)
        raise

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('mode',choices=['plan','apply'])
    parser.add_argument('--root',type=Path,default=ROOT)
    args=parser.parse_args()
    changes=prepare(args.root)
    for target,_,_ in changes:
        print('ALTERAR:',target)
    if args.mode=='plan':
        print('PLAN_OK' if changes else 'JA_INSTALADO')
    else:
        apply(changes,BACKUPS)

if __name__=='__main__':main()
