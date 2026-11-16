#!/usr/bin/env python3
"""Safely overlay only the read-only optical fallback onto the existing ACS collector."""
from pathlib import Path
from datetime import datetime, timezone
import os, shutil, subprocess, tempfile

SRC=Path(__file__).resolve().parent.parent
ROOT=Path('/var/www/gacs')
BACKUP=Path('/var/backups/jrc-acs-optical')
TARGETS=('lib/JrcAdaptiveOpticalRead.php','cron/collect-device-inventory.php')
START="require_once __DIR__ . '/../lib/JrcDeviceInventoryCache.php';"
HOOK="require_once __DIR__ . '/../lib/JrcAdaptiveOpticalRead.php';"
PARSER="$device = App\\GenieACS_Fast::parseDeviceDataFast($raw);"
CALL="$device = JrcAdaptiveOpticalRead::enrich($raw, $device);"

def fail(msg):
    raise SystemExit('INSTALACAO_BLOQUEADA: '+msg)

def main():
    if not (ROOT/'config/config.php').is_file(): fail('ACS nao encontrado')
    source=(SRC/TARGETS[1]).read_text()
    if source.count(HOOK)!=1 or source.count(CALL)!=1: fail('fonte inesperada')
    if not (ROOT/TARGETS[1]).is_file(): fail('coletor nao instalado')
    old=(ROOT/TARGETS[1]).read_text()
    if HOOK in old or CALL in old:
        if HOOK in old and CALL in old and old.count(HOOK)==1 and old.count(CALL)==1:
            print('JA_INSTALADO');return
        fail('coletor parcialmente modificado')
    if old.count(START)!=1 or old.count(PARSER)!=1: fail('coletor customizado: nao sobrescrever')
    new=old.replace(START,START+'\n'+HOOK,1).replace(PARSER,PARSER+'\n            '+CALL,1)
    if (ROOT/TARGETS[0]).exists(): fail('biblioteca ja existe: revisar manualmente')
    changes={TARGETS[0]:(None,(SRC/TARGETS[0]).read_bytes()),TARGETS[1]:(old.encode(),new.encode())}
    for rel,(before,after) in changes.items():
        with tempfile.NamedTemporaryFile(suffix='.php') as f:
            f.write(after);f.flush()
            result=subprocess.run(['php','-l',f.name],capture_output=True,text=True)
            if result.returncode: fail('PHP invalido: '+rel)
    # Test before touching production.
    test=subprocess.run(['php',str(SRC/'tests/jrc-adaptive-optical.test.php')],capture_output=True,text=True)
    if test.returncode: fail('teste falhou: '+test.stdout+test.stderr)
    stamp=datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')
    backup=BACKUP/stamp
    backup.mkdir(parents=True,mode=0o700)
    installed=[]
    try:
        for rel,(before,after) in changes.items():
            dest=ROOT/rel
            if dest.is_symlink() or (dest.read_bytes() if dest.exists() else None)!=before:
                raise RuntimeError('arquivo alterado durante instalacao: '+rel)
            if before is not None:
                b=backup/rel;b.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(dest,b)
            dest.parent.mkdir(parents=True,exist_ok=True)
            fd,tmp=tempfile.mkstemp(dir=dest.parent,prefix='.jrc-optical-')
            try:
                with os.fdopen(fd,'wb') as stream:stream.write(after);stream.flush();os.fsync(stream.fileno())
                if before is not None:
                    st=dest.stat();os.chmod(tmp,st.st_mode & 0o777);os.chown(tmp,st.st_uid,st.st_gid)
                else:os.chmod(tmp,0o644)
                os.replace(tmp,dest)
                installed.append(rel)
            finally:
                if os.path.exists(tmp):os.unlink(tmp)
    except BaseException:
        for rel in reversed(installed):
            dest=ROOT/rel
            if changes[rel][0] is None:dest.unlink(missing_ok=True)
            else:shutil.copy2(backup/rel,dest)
        raise
    print('APLICADO_OK backup='+str(backup))
    print('PRESERVADO: JS, API, IXC, presets, tasks, firmware, ONUs')
    print('OBS: novas leituras aparecem na proxima coleta do inventario; nao forca refreshObject')
if __name__=='__main__':main()
