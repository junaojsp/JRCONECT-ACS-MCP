#!/usr/bin/env python3
"""Apply native WAN/PPPoE layout to installed production device detail.

Does not edit Wi-Fi, overview, device actions, endpoints, or the menu.
"""
import argparse,os,shutil,subprocess,tempfile
from datetime import datetime,timezone
from pathlib import Path
WEB=Path('/var/www/gacs')
REPO=Path(__file__).resolve().parent.parent
JS=WEB/'assets/js/device-detail.js'
PHP=WEB/'device-detail.php'
FOOT=WEB/'views/layouts/footer.php'
CSS=WEB/'assets/css/jrc-device-wan-reference-layout.css'
CSS_SOURCE=REPO/'assets/css/jrc-device-wan-reference-layout.css'
JS_SOURCE=REPO/'assets/js/jrc-device-wan-reference-layout.js'
MARK='JRC_WAN_REFERENCE_LAYOUT_V1'
TARGET="        document.getElementById('wan-content').innerHTML = renderWANDetailsTab(device.wan_details);"
LINK=b'<link rel="stylesheet" href="/assets/css/jrc-device-wan-reference-layout.css?v=1">'
INCLUDE=(b'<?php if (basename((string)($_SERVER["SCRIPT_NAME"] ?? "")) === "device-detail.php"): ?>\n'
         +LINK+b'\n<?php endif; ?>\n')

def patch_js(before):
    s=before.decode('utf-8')
    if MARK in s:return before
    if s.count(TARGET)!=1:raise RuntimeError('Renderizador WAN nao corresponde a pagina instalada')
    if 'function renderWANDetailsTab(' not in s:raise RuntimeError('Funcao de conexoes WAN nao encontrada')
    if "jrc-ref-device-top" not in s:raise RuntimeError('Visao Geral personalizada atual nao identificada')
    if 'JRC_NATIVE_TABS_CLEAN_V4' not in s:raise RuntimeError('Versao WAN/Wi-Fi v4 nao identificada')
    helper=JS_SOURCE.read_text(encoding='utf-8')
    marker='/* '+MARK+' */\n'
    s=marker+helper+'\n'+s
    replacement=TARGET+"\n        if (document.getElementById('wan-content')) {\n"+(
        "            document.getElementById('wan-content').insertAdjacentHTML('afterbegin', jrcWANReferenceHeader(device.wan_details));\n"
    )+"        }"
    s=s.replace(TARGET,replacement,1)
    return s.encode('utf-8')

def inspect():
    for path in [JS,PHP,FOOT]:
        if not path.is_file() or path.is_symlink():raise RuntimeError('Arquivo de producao ausente ou simbolico: '+str(path))
    php=PHP.read_bytes()
    if not all(x in php for x in [b'id="wan-tab"',b'id="wan-content"',b'id="wifi-tab"',b'id="overview-tab"']):
        raise RuntimeError('Abas nativas nao reconhecidas no PHP')
    old_js=JS.read_bytes()
    new_js=patch_js(old_js)
    old_foot=FOOT.read_bytes()
    if old_foot.count(LINK)>1:raise RuntimeError('Link CSS duplicado')
    if LINK in old_foot:new_foot=old_foot
    else:
        if old_foot.count(b'</body>')!=1:raise RuntimeError('Rodape PHP nao reconhecido')
        new_foot=old_foot.replace(b'</body>',INCLUDE+b'</body>',1)
    if CSS.is_symlink():raise RuntimeError('CSS e link simbolico')
    old_css=CSS.read_bytes() if CSS.is_file() else None
    new_css=CSS_SOURCE.read_bytes()
    if old_css is not None and old_css!=new_css:raise RuntimeError('CSS de destino diverge do projeto')
    if subprocess.run(['php','-l'],input=new_foot,capture_output=True).returncode:raise RuntimeError('Sintaxe PHP invalida')
    if new_js!=old_js:
        if not shutil.which('node'):raise RuntimeError('Node.js indisponivel para validar sintaxe JavaScript')
        fd,tmp=tempfile.mkstemp(suffix='.js')
        try:
            with os.fdopen(fd,'wb') as f:f.write(new_js)
            result=subprocess.run(['node','--check',tmp],capture_output=True,text=True)
            if result.returncode:raise RuntimeError('JS invalido: '+result.stderr[:600])
        finally:os.unlink(tmp)
    return [(p,a,b) for p,a,b in [(JS,old_js,new_js),(CSS,old_css,new_css),(FOOT,old_foot,new_foot)] if a!=b]

def write(path,data):
    path.parent.mkdir(parents=True,exist_ok=True)
    fd,tmp=tempfile.mkstemp(prefix='.acs-wan-v1-',dir=path.parent)
    try:
        with os.fdopen(fd,'wb') as f:f.write(data);f.flush();os.fsync(f.fileno())
        if path.is_file():
            st=path.stat();os.chmod(tmp,st.st_mode&0o777);os.chown(tmp,st.st_uid,st.st_gid)
        else:os.chmod(tmp,0o644)
        os.replace(tmp,path)
    finally:
        if os.path.exists(tmp):os.unlink(tmp)

def main():
    arg=argparse.ArgumentParser()
    arg.add_argument('mode',choices=['plan','apply'])
    mode=arg.parse_args().mode
    jobs=inspect()
    if mode=='plan':
        for p,old,_ in jobs:print(('CRIAR: ' if old is None else 'ALTERAR: ')+str(p))
        print('PLAN_OK' if jobs else 'JA_INSTALADO')
        return
    if not jobs:
        print('JA_INSTALADO')
        return
    backup=Path('/var/backups/jrc-device-wan-reference-layout')/datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')
    backup.mkdir(parents=True,mode=0o700,exist_ok=False)
    applied=[]
    try:
        for p,old,new in jobs:
            if (p.read_bytes() if p.is_file() else None)!=old:raise RuntimeError('Arquivo mudou durante deploy: '+str(p))
            if old is not None:
                dest=backup/p.relative_to(WEB)
                dest.parent.mkdir(parents=True,exist_ok=True)
                shutil.copy2(p,dest)
            write(p,new)
            applied.append((p,old))
            print('ATUALIZADO:',p.relative_to(WEB))
        print('APLICADO_OK')
        print('BACKUP:',backup)
        print('ESCOPO: somente aba WAN/PPPoE; Wi-Fi, Visao Geral e comandos intocados')
    except BaseException:
        for p,old in reversed(applied):
            if old is None:p.unlink(missing_ok=True)
            else:shutil.copy2(backup/p.relative_to(WEB),p)
        raise

if __name__=='__main__':main()
