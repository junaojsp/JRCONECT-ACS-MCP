#!/usr/bin/env python3
"""Install a CSS-only visual theme on the internal overview tab. No navigation/JS changes."""
import argparse
from datetime import datetime,timezone
from pathlib import Path
import shutil
import subprocess
import os
import tempfile

WEB=Path('/var/www/gacs')
SOURCE=Path(__file__).resolve().parent.parent
FOOTER=WEB/'views/layouts/footer.php'
PAGE=WEB/'device-detail.php'
ASSET='assets/css/jrc-device-overview-dashboard-style.css'
LINK=b'<link rel="stylesheet" href="/assets/css/jrc-device-overview-dashboard-style.css?v=1">'
INSERT=(b'    <?php if (basename((string)($_SERVER["SCRIPT_NAME"] ?? "")) === "device-detail.php"): ?>\n'
        b'    <!-- JRC overview dashboard style only; no menu, no JS -->\n'
        b'    '+LINK+b'\n'
        b'    <?php endif; ?>\n')
def changes():
    if not PAGE.is_file() or PAGE.is_symlink() or not FOOTER.is_file() or FOOTER.is_symlink():
        raise RuntimeError('Detalhamento ou footer de producao nao reconhecidos')
    page=PAGE.read_bytes()
    if not all(x in page for x in (b'id="deviceTabs"',b'id="deviceTabContent"',b'id="overview-tab"')):
        raise RuntimeError('Abas originais nao reconhecidas; nao aplicar')
    old=FOOTER.read_bytes()
    if b'JRC device detail dark NOC v1' in old or b'jrc-device-detail-live-v' in old:
        raise RuntimeError('Adapter antigo encontrado no footer; remover antes desta instalacao')
    if old.count(LINK)>1:raise RuntimeError('CSS duplicado; nao aplicar')
    if LINK in old:new=old
    else:
        if old.count(b'</body>')!=1:raise RuntimeError('Footer nao reconhecido')
        new=old.replace(b'</body>',INSERT+b'</body>',1)
    if subprocess.run(['php','-l'],input=new,capture_output=True).returncode:
        raise RuntimeError('PHP invalido')
    dest=WEB/ASSET
    if dest.is_symlink():raise RuntimeError('CSS destino e link simbolico')
    before=dest.read_bytes() if dest.is_file() else None
    after=(SOURCE/ASSET).read_bytes()
    if before is not None and before!=after:
        raise RuntimeError('CSS ja existe com conteudo diferente; nao sobrescrever')
    pending=[]
    if before!=after:pending.append((dest,before,after))
    if old!=new:pending.append((FOOTER,old,new))
    return pending
def atomic(path,data):
    path.parent.mkdir(parents=True,exist_ok=True)
    fd,name=tempfile.mkstemp(prefix='.jrc-overview-',dir=path.parent)
    try:
        with os.fdopen(fd,'wb') as fp:fp.write(data);fp.flush();os.fsync(fp.fileno())
        if path.exists():
            st=path.stat();os.chmod(name,st.st_mode&0o777);os.chown(name,st.st_uid,st.st_gid)
        else:os.chmod(name,0o644)
        os.replace(name,path)
    finally:
        if os.path.exists(name):os.unlink(name)
def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('mode',choices=('plan','apply'))
    mode=parser.parse_args().mode
    pending=changes()
    if mode=='plan':
        for p,old,_ in pending:print(('CRIAR: ' if old is None else 'ALTERAR: ')+str(p))
        print('PLAN_OK' if pending else 'JA_INSTALADO')
        return
    if not pending:print('JA_INSTALADO');return
    backup=Path('/var/backups/jrc-device-overview-style')/datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')
    backup.mkdir(parents=True,mode=0o700)
    done=[]
    try:
        for p,old,new in pending:
            if (p.read_bytes() if p.exists() else None)!=old:
                raise RuntimeError('Arquivo mudou durante o deploy: '+str(p))
            if old is not None:
                path=backup/p.relative_to(WEB);path.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(p,path)
            atomic(p,new);done.append((p,old))
            print('INSTALADO:',p.relative_to(WEB))
        print('APLICADO_OK')
        print('BACKUP:',backup)
        print('ESCOPO: apenas estilo da aba Visao Geral interna; nenhuma alteracao de menu ou operacoes')
    except BaseException:
        for p,old in reversed(done):
            if old is None:p.unlink(missing_ok=True)
            else:shutil.copy2(backup/p.relative_to(WEB),p)
        raise
if __name__=='__main__':main()
