#!/usr/bin/env python3
"""Install the shared firmware theme without replacing production PHP/custom layouts.
Usage:
    python3 scripts/deploy-firmware-shared-theme.py plan
    sudo python3 scripts/deploy-firmware-shared-theme.py apply
"""
from pathlib import Path
from datetime import datetime, timezone
import os
import shutil
import subprocess
import sys

ROOT=Path("/var/www/gacs")
CSS=ROOT/"assets/css/firmware-shared-theme.css"
SOURCE=Path(__file__).resolve().parent.parent/"assets/css/firmware-shared-theme.css"
PAGES=[
    ("firmware-manager.php", 'class="jrc-fw"'),
    ("firmware-library.php", 'class="jrc-fw-lib"'),
    ("firmware-homologation.php", 'class="jrc-fwb"')
]
LINK='<link rel="stylesheet" href="/assets/css/firmware-shared-theme.css?v=1">'
STYLE_END='</style>'

def fail(msg):
    raise SystemExit("ABORTADO: "+msg)

def main():
    if len(sys.argv)!=2 or sys.argv[1] not in ("plan","apply"):
        fail("uso: plan|apply")
    action=sys.argv[1]
    if not SOURCE.is_file() or not (ROOT/"views/layouts/footer.php").is_file():
        fail("fonte CSS ou rodape ausente")
    if CSS.exists() or CSS.is_symlink():
        fail("stylesheet ja existe, nao sobrescrever")
    prepared=[]
    for filename,marker in PAGES:
        p=ROOT/filename
        if not p.is_file() or p.is_symlink():
            fail("pagina ausente ou link: "+filename)
        before=p.read_bytes()
        try:
            content=before.decode("utf-8")
        except UnicodeDecodeError:
            fail("codificacao inesperada: "+filename)
        if content.count(STYLE_END)!=1 or marker not in content:
            fail("pagina nao corresponde ao modulo esperado: "+filename)
        if LINK in content or "firmware-shared-theme.css" in content:
            fail("tema ja referenciado: "+filename)
        after=content.replace(STYLE_END,STYLE_END+"\n"+LINK,1)
        prepared.append((p,before,after))
    print("TEMA: visual azul-marinho padrao JR Conect ACS")
    print("PAGINAS: Repositorio, Homologacao, Gerenciamento legado")
    print("RODAPE: faixa global escondida apenas nessas paginas via CSS")
    print("CSS GLOBAL/FOOTER.PHP: nao modificados")
    print("FIRMWARE/GENIEACS: nenhuma operacao")
    if action=="plan":
        print("PLAN_OK: nenhuma alteracao feita")
        return
    if os.geteuid()!=0:
        fail("apply exige sudo/root")
    ts=datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    temp_files=[]
    installed_css=False
    replaced=[]
    backup_paths=[]
    try:
        # Prepare all files and validate PHP before committing any changes.
        css_temp=CSS.with_name(".firmware-shared-theme."+str(os.getpid())+".tmp")
        shutil.copyfile(SOURCE,css_temp)
        os.chmod(css_temp,0o644)
        temp_files.append(css_temp)
        for p,original,modified in prepared:
            temp=p.with_name("."+p.name+".theme."+str(os.getpid())+".tmp")
            temp.write_text(modified,encoding="utf-8")
            st=p.stat()
            os.chown(temp,st.st_uid,st.st_gid)
            os.chmod(temp,st.st_mode & 0o777)
            temp_files.append(temp)
            chk=subprocess.run(["php","-l",str(temp)],capture_output=True,text=True)
            if chk.returncode!=0:
                fail("PHP lint falhou em "+p.name+": "+chk.stderr.strip())
        if any(p.read_bytes()!=original for p,original,_ in prepared) or CSS.exists():
            fail("mudanca concorrente, nenhuma alteracao realizada")
        for p,original,_ in prepared:
            backup=p.with_name(p.name+".before-shared-theme-"+ts+".bak")
            if backup.exists(): fail("backup ja existe: "+p.name)
            shutil.copy2(p,backup)
            backup_paths.append((p,backup))
        os.replace(css_temp,CSS)
        installed_css=True
        for i,(p,_,_) in enumerate(prepared):
            os.replace(temp_files[i+1],p)
            replaced.append(p)
        print("APLICADO_OK")
        print("STYLESHEET:",CSS)
        for _,backup in backup_paths:
            print("BACKUP:",backup)
    except BaseException:
        # Restore the previous pages only if any swap occurred.
        for p,backup in backup_paths:
            if p in replaced and backup.is_file():
                shutil.copy2(backup,p)
        if installed_css and CSS.is_file():
            CSS.unlink()
        raise
    finally:
        for p in temp_files:
            p.unlink(missing_ok=True)
if __name__=="__main__":
    main()
