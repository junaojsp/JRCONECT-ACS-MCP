#!/usr/bin/env python3
"""Opt-in ACS optical report blue table and title overlay; no device actions."""
import argparse
from datetime import datetime, timezone
from pathlib import Path
import os
import re
import shutil
import subprocess
import tempfile

SOURCE=Path(__file__).resolve().parent.parent
DEFAULT_ROOT=Path("/var/www/gacs")
BACKUPS=Path("/var/backups/jrc-acs-signal-blue-title")
STYLE="assets/css/jrc-signal-report-final-blue.css"
PAGE="view-logs.php"
OLD_NAME="Client Logs Viewer"
NEW_NAME="Consulta de Sinal Optico"
LINK_MARKER="jrc-signal-report-final-blue.css"
OLD_STYLE_MARKER="jrc-signal-report-blue.css"

def abort(reason):
    raise SystemExit("BLOQUEADO: "+reason)

def proposal(root):
    if not (root/"config/config.php").is_file():
        abort("ACS nao encontrado")
    page=root/PAGE
    css=root/STYLE
    if page.is_symlink() or not page.is_file() or css.is_symlink():
        abort("Pagina ou CSS invalido")
    old=page.read_bytes()
    try:
        text=old.decode("utf-8")
    except UnicodeError:
        abort("Pagina nao esta em UTF-8")
    if OLD_STYLE_MARKER not in text:
        abort("CSS base do relatorio nao encontrado")
    if text.count(OLD_NAME) == 1:
        text=text.replace(OLD_NAME,NEW_NAME,1)
    elif OLD_NAME in text or NEW_NAME not in text:
        abort("Titulo personalizado nao corresponde ao esperado")
    if LINK_MARKER not in text:
        # Inject a later stylesheet reference in the existing PHP echo statement.
        # Avoid overriding the installed report markup or its JS.
        lines=text.splitlines(keepends=True)
        matches=[i for i,line in enumerate(lines) if OLD_STYLE_MARKER in line and "stylesheet" in line and "<link" in line]
        if len(matches)!=1:
            abort("Link CSS de origem nao encontrado de forma unica")
        original=lines[matches[0]]
        match=re.match(r"^(\\s*)echo\\s+(['\"])",original)
        if not match:
            abort("CSS personalizado nao usa echo PHP esperado")
        indent=match.group(1)
        injected=indent+"echo '<link rel=\\\"stylesheet\\\" href=\\\"/assets/css/jrc-signal-report-final-blue.css?v=3\\\">';\\n"
        # quotes are escaped in a Python string but the resulting PHP should have plain quotes
        injected=injected.replace('\\\\"','"')
        lines.insert(matches[0]+1,injected)
        text="".join(lines)
    if text.count(LINK_MARKER)!=1:
        abort("Referencia CSS duplicada")
    source=SOURCE/STYLE
    if not source.is_file():
        abort("Novo CSS ausente no checkout")
    data=source.read_bytes()
    if b"JRC SIGNAL REPORT BLUE FINAL" not in data:
        abort("Versao CSS inesperada")
    if css.exists() and css.read_bytes()!=data:
        abort("CSS final ja modificado na producao")
    after=text.encode("utf-8")
    if after!=old:
        with tempfile.NamedTemporaryFile(suffix=".php") as t:
            t.write(after);t.flush()
            check=subprocess.run(["php","-l",t.name],capture_output=True,text=True)
            if check.returncode:
                abort("Sintaxe PHP invalida: "+check.stderr)
    changes=[]
    if after!=old: changes.append((page,old,after))
    if not css.exists():changes.append((css,None,data))
    return changes

def apply(changes,backups):
    if not changes:
        print("JA_INSTALADO");return
    backup=backups/datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%S%fZ")
    backup.mkdir(parents=True,mode=0o700)
    completed=[]
    try:
        # Publish stylesheet first, page reference last.
        for target,old,new in sorted(changes,key=lambda x: x[0].suffix==".php"):
            target.parent.mkdir(parents=True,exist_ok=True)
            saved=backup/target.as_posix().lstrip("/")
            st=None
            if old is not None:
                saved.parent.mkdir(parents=True,exist_ok=True)
                shutil.copy2(target,saved)
                st=target.stat()
            fd,tmp=tempfile.mkstemp(dir=target.parent,prefix=".jrc-signal-")
            try:
                with os.fdopen(fd,"wb") as handle:
                    handle.write(new)
                    handle.flush()
                    os.fsync(handle.fileno())
                if st:
                    os.chmod(tmp,st.st_mode & 0o777)
                    os.chown(tmp,st.st_uid,st.st_gid)
                else:
                    os.chmod(tmp,0o644)
                if target.is_symlink() or (target.read_bytes() if target.exists() else None)!=old:
                    abort("Arquivo alterado durante instalacao: "+str(target))
                os.replace(tmp,target)
                completed.append((target,old,saved))
            finally:
                if os.path.exists(tmp):os.unlink(tmp)
        print("APLICADO_OK")
        print("BACKUP:",backup)
    except BaseException:
        for target,old,saved in reversed(completed):
            if old is None:target.unlink(missing_ok=True)
            else:shutil.copy2(saved,target)
        raise

def main():
    p=argparse.ArgumentParser()
    p.add_argument("mode",choices=["plan","apply"])
    p.add_argument("--root",type=Path,default=DEFAULT_ROOT)
    p.add_argument("--backups",type=Path,default=BACKUPS)
    args=p.parse_args()
    changes=proposal(args.root)
    for target,_,_ in changes:print("ALTERAR:",target)
    if args.mode=="apply":apply(changes,args.backups)
    else:print("PLAN_OK" if changes else "JA_INSTALADO")
    print("PRESERVADOS: consulta RX, filtros, contagens, JS, IXC, GenieACS e tarefas ONU")

if __name__=="__main__":
    main()
