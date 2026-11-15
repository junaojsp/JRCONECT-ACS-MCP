#!/usr/bin/env python3
"""Safe hotfix for existing firmware search API root config include.
Usage: python3 scripts/fix-firmware-search-config.py plan|apply
"""
from pathlib import Path
from datetime import datetime,timezone
import os
import shutil
import subprocess
import sys
ROOT=Path("/var/www/gacs")
TARGET=ROOT/"api/firmware-device-search.php"
CONFIG=ROOT/"config/config.php"
SOURCE=Path(__file__).resolve().parent.parent/"api/firmware-device-search.php"
OLD="require_once __DIR__ . '/config/config.php';"
NEW="require_once dirname(__DIR__) . '/config/config.php';"

def die(msg):
    raise SystemExit("ABORTADO: "+msg)

def main():
    if len(sys.argv)!=2 or sys.argv[1] not in ("plan","apply"): die("uso: plan|apply")
    mode=sys.argv[1]
    if not TARGET.is_file() or not SOURCE.is_file() or not CONFIG.is_file():
        die("arquivo de producao/fonte/configuracao ausente")
    current=TARGET.read_bytes()
    text=current.decode("utf-8")
    source=SOURCE.read_text(encoding="utf-8")
    if text.count(OLD)!=1 or NEW not in source:
        die("include inesperado; nenhuma alteracao")
    expected=text.replace(OLD,NEW,1)
    if expected!=source:
        # Refuse to replace any unrelated production customization.
        die("API de producao difere da fonte aprovada; revisar antes de corrigir")
    print("CAUSA: caminho relativo incorreto em api/firmware-device-search.php")
    print("CORRECAO: config/config.php na raiz do ACS")
    print("ALTERACOES: somente include no endpoint; nenhuma tarefa GenieACS")
    if mode=="plan":
        print("PLAN_OK: nenhuma alteracao feita")
        return
    if os.geteuid()!=0: die("apply exige root")
    timestamp=datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    backup=TARGET.with_name(TARGET.name+".before-config-fix-"+timestamp+".bak")
    temp=TARGET.with_name(".firmware-device-search.fix."+str(os.getpid())+".php")
    if backup.exists(): die("backup ja existente")
    try:
        stat=TARGET.stat()
        temp.write_text(expected,encoding="utf-8")
        os.chown(temp,stat.st_uid,stat.st_gid)
        os.chmod(temp,stat.st_mode & 0o777)
        chk=subprocess.run(["php","-l",str(temp)],capture_output=True,text=True)
        if chk.returncode!=0: die("PHP lint falhou: "+chk.stderr.strip())
        if TARGET.read_bytes()!=current: die("API modificada durante a preparacao")
        shutil.copy2(TARGET,backup)
        os.replace(temp,TARGET)
        print("APLICADO_OK")
        print("BACKUP:",backup)
    finally:
        temp.unlink(missing_ok=True)
if __name__=="__main__":
    main()
