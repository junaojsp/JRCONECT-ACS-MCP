#!/usr/bin/env python3
"""Update only firmware select JS in customized production homologation page.
Usage: python3 scripts/deploy-firmware-dropdown-fix.py plan|apply
"""
from pathlib import Path
from datetime import datetime, timezone
import os
import shutil
import subprocess
import sys

ROOT=Path("/var/www/gacs")
TARGET=ROOT/"firmware-homologation.php"
SOURCE=Path(__file__).resolve().parent.parent/"firmware-homologation.php"
OLD_ANCHOR="  const note=get('fw-simple-firmware-info'),compat=get('fw-simple-compat-note');"
OLD_SYNC_START="  function syncFirmware(){"
OLD_SYNC_END="  function selectDevice(d){"
NEW_ANCHOR="  // Keep an immutable local copy of the catalogue options."
NEW_SYNC_FLAG="firmware.replaceChildren(...choices);"

def die(msg):
    raise SystemExit("ABORTADO: "+msg)

def segment(s,start,end):
    if s.count(start)!=1 or s.count(end)!=1:
        die("marcadores de codigo diferentes do esperado")
    a=s.index(start)
    b=s.index(end,a)
    return a,b

def patch(live,source):
    if live.count(OLD_ANCHOR)!=1 or source.count(OLD_ANCHOR)!=1:
        die("marcador da busca nao encontrado")
    if NEW_ANCHOR in live or NEW_SYNC_FLAG in live:
        die("correcao ja presente; nao sobrescrever")
    if NEW_ANCHOR not in source or NEW_SYNC_FLAG not in source:
        die("fonte incompleta")
    # Add the constant just once. It is deliberately kept inside current script,
    # leaving production custom styles and handlers untouched.
    const_tail=source.split(OLD_ANCHOR,1)[1].split("  let current=null;",1)[0]
    if "catalogueOptions" not in const_tail:
        die("constante do catalogo nao encontrada")
    live=live.replace(OLD_ANCHOR,OLD_ANCHOR+const_tail,1)
    a,b=segment(live,OLD_SYNC_START,OLD_SYNC_END)
    x,y=segment(source,OLD_SYNC_START,OLD_SYNC_END)
    return live[:a]+source[x:y]+live[b:]

def main():
    if len(sys.argv)!=2 or sys.argv[1] not in ("plan","apply"):
        die("uso: plan|apply")
    mode=sys.argv[1]
    if not TARGET.is_file() or not SOURCE.is_file() or TARGET.is_symlink():
        die("arquivo de homologacao invalido")
    before=TARGET.read_bytes()
    try:
        result=patch(before.decode("utf-8"),SOURCE.read_text(encoding="utf-8"))
    except UnicodeDecodeError:
        die("codificacao inesperada")
    print("CORRECAO: lista de firmwares reconstruida por fabricante/modelo/hardware")
    print("DIAGNOSTICO: mostra diferencas de hardware ou catalogo vazio")
    print("PRESERVACAO: layout, banco de imagens, registros e API GenieACS intactos")
    if mode=="plan":
        print("PLAN_OK: nenhuma alteracao feita")
        return
    if os.geteuid()!=0:
        die("apply requer root")
    stamp=datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    backup=TARGET.with_name(TARGET.name+".before-dropdown-"+stamp+".bak")
    temp=TARGET.with_name(".firmware-homologation.dropdown."+str(os.getpid())+".tmp")
    if backup.exists():
        die("backup ja existente")
    try:
        st=TARGET.stat()
        temp.write_text(result,encoding="utf-8")
        os.chown(temp,st.st_uid,st.st_gid)
        os.chmod(temp,st.st_mode&0o777)
        chk=subprocess.run(["php","-l",str(temp)],capture_output=True,text=True)
        if chk.returncode:
            die("PHP lint falhou: "+chk.stderr.strip())
        if TARGET.read_bytes()!=before:
            die("arquivo mudou; nao instalar")
        shutil.copy2(TARGET,backup)
        os.replace(temp,TARGET)
        print("APLICADO_OK")
        print("BACKUP:",backup)
    finally:
        temp.unlink(missing_ok=True)
if __name__=="__main__":
    main()
