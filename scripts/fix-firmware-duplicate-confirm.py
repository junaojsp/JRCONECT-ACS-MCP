#!/usr/bin/env python3
"""Fix duplicate 'const confirm' in installed Firmware bench page.
Usage:
    python3 scripts/fix-firmware-duplicate-confirm.py plan
    sudo python3 scripts/fix-firmware-duplicate-confirm.py apply
Only removes the second exact duplicate declaration, keeps CSS and all PHP untouched.
"""
from pathlib import Path
from datetime import datetime, timezone
import os
import shutil
import subprocess
import sys

TARGET=Path("/var/www/gacs/firmware-homologation.php")
CANONICAL=Path(__file__).resolve().parent.parent/"firmware-homologation.php"
LINE="  const confirm=get('fw-simple-confirm'),register=get('fw-simple-register');"
BEGIN="<script>"
END="</script>"

def abort(message):
    raise SystemExit("ABORTADO: "+message)

def script_slice(content):
    if content.count(BEGIN)!=1 or content.count(END)!=1:
        abort("estrutura de script inesperada")
    a=content.index(BEGIN)
    b=content.index(END,a)+len(END)
    return a,b

def main():
    if len(sys.argv)!=2 or sys.argv[1] not in ("plan","apply"):
        abort("uso: plan | apply")
    action=sys.argv[1]
    if not TARGET.is_file() or TARGET.is_symlink() or not CANONICAL.is_file():
        abort("pagina em producao ou referencia ausente")
    data=TARGET.read_bytes()
    try:
        original=data.decode("utf-8")
        reference=CANONICAL.read_text(encoding="utf-8")
    except UnicodeDecodeError:
        abort("codificacao inesperada")
    a,b=script_slice(original)
    ra,rb=script_slice(reference)
    script=original[a:b]
    ref_script=reference[ra:rb]
    if script.count(LINE)!=2:
        abort("esperadas exatamente duas declaracoes identicas; foram encontradas "+str(script.count(LINE)))
    if ref_script.count(LINE)!=1:
        abort("fonte GitHub nao contem declaracao unica")
    if script.count("const catalogueOptions=")!=1:
        abort("script de filtro de firmware diferente do esperado")
    lines=original.splitlines(keepends=True)
    dup_indices=[i for i,line in enumerate(lines) if line.rstrip("\r\n")==LINE]
    if len(dup_indices)!=2:
        abort("declaracoes nao identicas na forma completa")
    del lines[dup_indices[1]]
    fixed="".join(lines)
    x,y=script_slice(fixed)
    if fixed[x:y].count(LINE)!=1:
        abort("resultado nao tem declaracao unica")
    print("CAUSA: duplicacao de const confirm no JavaScript da pagina instalada")
    print("CORRECAO: remover somente a segunda declaracao identica")
    print("CSS, FORMULARIO, FIRMWARE, GENIEACS: preservados")
    if action=="plan":
        print("PLAN_OK: nenhuma alteracao feita")
        return
    if os.geteuid()!=0:
        abort("apply exige sudo/root")
    stamp=datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    backup=TARGET.with_name(TARGET.name+".before-confirm-js-"+stamp+".bak")
    temp=TARGET.with_name(".firmware-homologation.confirm."+str(os.getpid())+".tmp")
    if backup.exists():
        abort("backup ja existe")
    try:
        st=TARGET.stat()
        temp.write_text(fixed,encoding="utf-8")
        os.chown(temp,st.st_uid,st.st_gid)
        os.chmod(temp,st.st_mode & 0o777)
        checked=subprocess.run(["php","-l",str(temp)],capture_output=True,text=True)
        if checked.returncode!=0:
            abort("falha PHP lint: "+checked.stderr.strip())
        if TARGET.read_bytes()!=data:
            abort("arquivo de producao alterado durante verificacao")
        shutil.copy2(TARGET,backup)
        os.replace(temp,TARGET)
        print("APLICADO_OK")
        print("BACKUP:",backup)
        print("RESULTADO: uma declaracao const confirm")
    finally:
        temp.unlink(missing_ok=True)
if __name__=="__main__":
    main()
