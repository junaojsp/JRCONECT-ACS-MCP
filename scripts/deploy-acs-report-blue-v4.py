#!/usr/bin/env python3
"""Safely install v4 report-only CSS and a cache-busted link into live view-logs.php."""
from pathlib import Path
from datetime import datetime, timezone
import os
import shutil
import subprocess
import tempfile

root=Path("/var/www/gacs")
page=root/"view-logs.php"
style=root/"assets/css/jrc-signal-report-rows-v4.css"
source=Path(__file__).resolve().parent.parent/"assets/css/jrc-signal-report-rows-v4.css"
backup_root=Path("/var/backups/jrc-acs-report-blue-v4")
old_link="jrc-signal-report-final-blue.css?v=3"
new_link="jrc-signal-report-rows-v4.css?v=4"

def fail(reason):
    raise SystemExit("BLOQUEADO: "+reason)

if not (root/"config/config.php").is_file() or not page.is_file() or page.is_symlink():
    fail("Pagina ACS nao encontrada ou symlink")
if style.is_symlink() or (style.exists() and not style.is_file()):
    fail("Arquivo CSS inseguro")
if not source.is_file():
    fail("Overlay v4 ausente")
css=source.read_bytes()
if css.count(b"JRC_SIGNAL_ROW_BLUE_V4")!=1:
    fail("Overlay CSS inesperado")
original=page.read_bytes()
try:
    text=original.decode("utf-8")
except UnicodeError:
    fail("Pagina PHP nao UTF-8")
if new_link in text:
    if text.count(new_link)!=1:
        fail("Inclusao duplicada do CSS")
    updated=text
else:
    lines=text.splitlines(keepends=True)
    matches=[i for i,line in enumerate(lines) if old_link in line and line.lstrip().startswith("echo ")]
    if len(matches)!=1:
        fail("Link do CSS anterior nao encontrado de forma unica")
    i=matches[0]
    indent=lines[i][:len(lines[i])-len(lines[i].lstrip())]
    line=indent+"echo '<link rel=\"stylesheet\" href=\"/assets/css/"+new_link+"\">';\n"
    lines.insert(i+1,line)
    updated="".join(lines)
if updated!=text:
    with tempfile.NamedTemporaryFile(suffix=".php") as temp:
        temp.write(updated.encode("utf-8"))
        temp.flush()
        check=subprocess.run(["php","-l",temp.name],capture_output=True,text=True)
        if check.returncode:
            fail("PHP invalido: "+check.stderr)
if style.exists() and style.read_bytes()!=css:
    fail("CSS v4 divergente ja presente")
changes=[]
if not style.exists():
    changes.append((style,None,css))
if updated!=text:
    changes.append((page,original,updated.encode("utf-8")))
if not changes:
    print("JA_INSTALADO")
    raise SystemExit(0)
backup=backup_root/datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%S%fZ")
backup.mkdir(parents=True,mode=0o700)
completed=[]
try:
    for target,before,after in changes:
        target.parent.mkdir(parents=True,exist_ok=True)
        saved=backup/target.name
        st=None
        if before is not None:
            shutil.copy2(target,saved)
            st=target.stat()
        fd,tmp=tempfile.mkstemp(dir=target.parent,prefix=".jrc-report-")
        try:
            with os.fdopen(fd,"wb") as f:
                f.write(after)
                f.flush()
                os.fsync(f.fileno())
            if st:
                os.chmod(tmp,st.st_mode & 0o777)
                os.chown(tmp,st.st_uid,st.st_gid)
            else:
                os.chmod(tmp,0o644)
            if target.is_symlink() or (target.read_bytes() if target.exists() else None)!=before:
                fail("Alteracao concorrente: "+target.name)
            os.replace(tmp,target)
            completed.append((target,before,saved))
        finally:
            if os.path.exists(tmp):
                os.unlink(tmp)
    print("APLICADO_OK")
    print("BACKUP:",backup)
    print("SOMENTE_LAYOUT=SIM; ONUS_E_COLETA_PRESERVADAS=SIM")
except BaseException:
    for target,before,saved in reversed(completed):
        if before is None:
            target.unlink(missing_ok=True)
        else:
            shutil.copy2(saved,target)
    raise
