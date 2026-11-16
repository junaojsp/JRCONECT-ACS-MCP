#!/usr/bin/env python3
"""Fix firmware catalog rendering without changing existing ACS styling or JS.
Usage: python3 scripts/fix-firmware-catalog-render.py plan|apply
"""
from pathlib import Path
from datetime import datetime, timezone
import os, shutil, subprocess, sys

TARGET=Path("/var/www/gacs/firmware-homologation.php")
OLD="<?php foreach($files as $f): if(($f['status']??'')!=='nao_homologado') continue; ?>"
NEW="<?php foreach(fwbRead(FW_BENCH_DIR.'/catalog.json') as $f): if(($f['status']??'')!=='nao_homologado') continue; ?>"
OLD_NOTE='<p class="muted" id="fw-simple-firmware-info">Somente arquivos com fabricante, modelo e hardware correspondentes serão oferecidos.</p>'
NEW_NOTE='<p class="muted" id="fw-simple-firmware-info">As imagens cadastradas serão filtradas por fabricante, modelo e revisão de hardware.</p>'
CATALOG=Path("/var/lib/jrc-acs-firmware/catalog.json")

def fail(message):
    raise SystemExit("ABORTADO: "+message)

def main():
    if len(sys.argv)!=2 or sys.argv[1] not in ("plan","apply"):
        fail("uso: plan|apply")
    action=sys.argv[1]
    if not TARGET.is_file() or TARGET.is_symlink() or not CATALOG.is_file():
        fail("pagina ou catalogo ausente")
    before=TARGET.read_bytes()
    try:
        text=before.decode("utf-8")
    except UnicodeDecodeError:
        fail("codificacao inesperada")
    if text.count(OLD)!=1 or text.count(OLD_NOTE)!=1:
        fail("layout de producao inesperado; nada modificado")
    import json
    try:
        items=json.loads(CATALOG.read_text(encoding="utf-8"))
    except (OSError,ValueError) as ex:
        fail("catalogo nao pode ser lido: "+str(ex))
    if not isinstance(items,list):
        fail("catalogo nao e uma lista")
    candidates=[x for x in items if isinstance(x,dict) and x.get("status")=="nao_homologado"]
    print("CATALOGO: ",len(items),"arquivo(s),",len(candidates),"nao homologado(s)")
    print("CORRECAO: renderizar as opcoes lendo catalog.json diretamente")
    print("ESCOPO: somente foreach e mensagem da etapa 3; JS/CSS/GenieACS intactos")
    after=text.replace(OLD,NEW,1).replace(OLD_NOTE,NEW_NOTE,1)
    if action=="plan":
        print("PLAN_OK: nenhuma alteracao feita")
        return
    if os.geteuid()!=0:
        fail("apply exige root")
    ts=datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    backup=TARGET.with_name(TARGET.name+".before-catalog-render-"+ts+".bak")
    temp=TARGET.with_name(".firmware-homologation.catalog."+str(os.getpid())+".tmp")
    if backup.exists():
        fail("backup ja existe")
    try:
        st=TARGET.stat()
        temp.write_text(after,encoding="utf-8")
        os.chown(temp,st.st_uid,st.st_gid)
        os.chmod(temp,st.st_mode & 0o777)
        check=subprocess.run(["php","-l",str(temp)],capture_output=True,text=True)
        if check.returncode:
            fail("erro PHP: "+check.stderr.strip())
        if TARGET.read_bytes()!=before:
            fail("pagina mudou durante validacao")
        shutil.copy2(TARGET,backup)
        os.replace(temp,TARGET)
        print("APLICADO_OK")
        print("BACKUP:",backup)
    finally:
        temp.unlink(missing_ok=True)

if __name__=="__main__":
    main()
