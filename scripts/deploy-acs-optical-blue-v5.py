#!/usr/bin/env python3
"""Install optical table styling into customized live report without changing data."""
from datetime import datetime, timezone
from pathlib import Path
import os, shutil, subprocess, tempfile

root=Path('/var/www/gacs')
page=root/'view-logs.php'
asset=root/'assets/js/jrc-optical-report-blue-v5.js'
source=Path(__file__).resolve().parent.parent/'assets/js/jrc-optical-report-blue-v5.js'
backup_root=Path('/var/backups/jrc-optical-blue-v5')
mark='jrc-optical-report-blue-v5.js'
tag='<script defer src="/assets/js/jrc-optical-report-blue-v5.js?v=5"></script>'

def fail(message):
    raise SystemExit('BLOQUEADO: '+message)

if not (root/'config/config.php').is_file() or not page.is_file() or page.is_symlink():
    fail('ACS ou pagina personalizada ausente')
if asset.is_symlink() or (asset.exists() and not asset.is_file()):
    fail('Asset destino inseguro')
if not source.is_file():
    fail('JS fonte ausente')
js=source.read_bytes()
if b'ACS Optical Report v5' not in js:
    fail('JS inesperado')
old=page.read_bytes()
try:
    text=old.decode('utf-8')
except UnicodeError:
    fail('Pagina nao UTF-8')
if mark in text:
    if text.count(mark)!=1:
        fail('Referencia do script duplicada')
    updated=text
else:
    # Existing customized ACS page already emits CSS via PHP echo.
    # Append a script tag safely immediately before the final PHP footer include.
    anchor="<?php include __DIR__ . '/views/layouts/footer.php'; ?>"
    if text.count(anchor)!=1:
        fail('Footer esperado nao localizado; pagina preservada')
    updated=text.replace(anchor,tag+"\n"+anchor,1)
if updated!=text:
    with tempfile.NamedTemporaryFile(suffix='.php') as temp:
        temp.write(updated.encode('utf-8'))
        temp.flush()
        if subprocess.run(['php','-l',temp.name],capture_output=True).returncode:
            fail('Sintaxe PHP invalida; pagina preservada')
if asset.exists() and asset.read_bytes()!=js:
    fail('JS ja existente difere do GitHub')
changes=[]
if not asset.exists(): changes.append((asset,None,js))
if updated!=text: changes.append((page,old,updated.encode('utf-8')))
if not changes:
    print('JA_INSTALADO')
    raise SystemExit(0)
backup=backup_root/datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')
backup.mkdir(parents=True,mode=0o700)
done=[]
try:
    for target,before,after in changes:
        target.parent.mkdir(parents=True,exist_ok=True)
        saved=backup/target.name
        st=None
        if before is not None:
            shutil.copy2(target,saved)
            st=target.stat()
        fd,tmp=tempfile.mkstemp(dir=target.parent,prefix='.jrc-optical-')
        try:
            with os.fdopen(fd,'wb') as stream:
                stream.write(after)
                stream.flush()
                os.fsync(stream.fileno())
            if st:
                os.chmod(tmp,st.st_mode & 0o777)
                os.chown(tmp,st.st_uid,st.st_gid)
            else:
                os.chmod(tmp,0o644)
            if target.is_symlink() or (target.read_bytes() if target.exists() else None)!=before:
                fail('Mudanca concorrente: '+target.name)
            os.replace(tmp,target)
            done.append((target,before,saved))
        finally:
            if os.path.exists(tmp): os.unlink(tmp)
    print('APLICADO_OK')
    print('BACKUP:',backup)
    print('APENAS_LAYOUT: SIM; COLETA_ONU: INALTERADA')
except BaseException:
    for target,before,saved in reversed(done):
        if before is None: target.unlink(missing_ok=True)
        else: shutil.copy2(saved,target)
    raise
