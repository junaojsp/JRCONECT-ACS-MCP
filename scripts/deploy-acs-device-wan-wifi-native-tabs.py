#!/usr/bin/env python3
"""Separate native WAN and Wi-Fi tabs, without changing the ACS menu or device actions.

Designed for the deployed user-supplied renderer with v1+v2 overview updates.
"""
import argparse
import os
import shutil
import subprocess
import tempfile
from datetime import datetime, timezone
from pathlib import Path

ROOT=Path('/var/www/gacs')
JS=ROOT/'assets/js/device-detail.js'
PHP=ROOT/'device-detail.php'
FOOT=ROOT/'views/layouts/footer.php'
CSS=ROOT/'assets/css/jrc-device-wan-native-tab.css'
SRC=Path(__file__).resolve().parent.parent/'assets/css/jrc-device-wan-native-tab.css'
LINK=b'<link rel="stylesheet" href="/assets/css/jrc-device-wan-native-tab.css?v=1">'
INCLUDE=(b'<?php if (basename((string)($_SERVER["SCRIPT_NAME"] ?? "")) === "device-detail.php"): ?>\n'
         +LINK+b'\n<?php endif; ?>\n')
MARKER='JRC_WAN_WIFI_NATIVE_TABS_V3'
TAB_ANCHOR="        document.getElementById('wan-content').innerHTML = renderWANDetailsTab(device.wan_details);"
OLD_SUMMARY='<summary>Conexão, Wi-Fi e informações do equipamento</summary>'
NEW_SUMMARY='<summary>Informações do equipamento</summary>'
# Original tabs are registered by PHP; this code only places a summary into WAN.
NATIVE_PATCH="""
        // JRC_WAN_WIFI_NATIVE_TABS_V3 — relocate only the existing WAN
        // summary; the native Wi-Fi tab already handles SSIDs/configuration.
        (() => {
            const overview = document.getElementById('overview-content');
            const wan = document.getElementById('wan-content');
            const mixed = overview?.querySelector('details.jrc-ref-device-technical');
            const summary = mixed?.querySelector('section.acs-card-wan.acs-approved-wan');
            if (!mixed || !summary || !wan ||
                !document.getElementById('wan-tab') ||
                !document.getElementById('wifi-tab')) {
                console.warn('ACS: painel WAN nao encontrado, mantendo layout original.');
                return;
            }
            // Native Wi-Fi already displays active SSIDs and edits. These
            // static rows are duplicates, not inputs or action handlers.
            const fields = summary.querySelector('.acs-reference-list');
            for (const item of Array.from(fields?.children || [])) {
                const name = item.querySelector('span')?.textContent?.trim() || '';
                if (/^Wi-Fi (2[,.]4|5)\s*GHz$/i.test(name)) item.remove();
            }
            // Navigation to Wi-Fi remains available in the existing tab row.
            summary.querySelector('.acs-toolbar-action')?.remove();
            const title = summary.querySelector('.acs-kicker');
            if (title) title.textContent = 'Resumo da conexão WAN';
            summary.classList.add('jrc-ref-wan-summary');
            wan.prepend(summary);
        })();
"""

def patch_js(original):
    text=original.decode('utf-8')
    if MARKER in text:return original
    if 'jrc-ref-optical-history' not in text or 'jrc-ref-device-technical' not in text:
        raise RuntimeError('Visao Geral atualizada v1/v2 nao encontrada; nenhuma alteracao')
    if text.count(OLD_SUMMARY)!=1:
        raise RuntimeError('Bloco misto WAN/Wi-Fi inesperado')
    if text.count(TAB_ANCHOR)!=1:
        raise RuntimeError('Aba nativa WAN nao reconhecida')
    if text.count('section class="acs-overview-card acs-card-wan acs-approved-wan"')!=1:
        raise RuntimeError('Painel WAN de resumo divergente')
    if text.count('section class="acs-overview-card acs-card-device acs-approved-device"')!=1:
        raise RuntimeError('Cartao de equipamento divergente')
    # Do not move the equipment block, and do not duplicate IDs. Only
    # relocate the WAN DOM node *after* its native tab has rendered.
    text=text.replace(OLD_SUMMARY, NEW_SUMMARY,1)
    text=text.replace(TAB_ANCHOR,TAB_ANCHOR+'\n'+NATIVE_PATCH,1)
    return text.encode('utf-8')

def plan():
    for p in (JS,PHP,FOOT):
        if not p.is_file() or p.is_symlink():
            raise RuntimeError('Arquivo de producao ausente ou link: '+str(p))
    if not all(x in PHP.read_bytes() for x in
               (b'id="overview-tab"',b'id="wan-tab"',b'id="wifi-tab"',
                b'id="deviceTabs"',b'id="deviceTabContent"')):
        raise RuntimeError('Abas de producao nao reconhecidas')
    oldjs=JS.read_bytes()
    newjs=patch_js(oldjs)
    oldfoot=FOOT.read_bytes()
    if b'jrc-device-detail-live-v' in oldfoot or b'JRC device detail dark NOC v1' in oldfoot:
        raise RuntimeError('Adapter antigo de menus ativo')
    if oldfoot.count(LINK)>1:
        raise RuntimeError('Link CSS duplicado')
    if b'jrc-device-overview-lower-reference.css' not in oldfoot:
        raise RuntimeError('Layout inferior v2 ainda nao instalado')
    if LINK in oldfoot:newfoot=oldfoot
    else:
        if oldfoot.count(b'</body>')!=1:raise RuntimeError('Footer nao reconhecido')
        newfoot=oldfoot.replace(b'</body>',INCLUDE+b'</body>',1)
    if CSS.is_symlink():raise RuntimeError('CSS simbolico')
    oldcss=CSS.read_bytes() if CSS.is_file() else None
    newcss=SRC.read_bytes()
    if oldcss is not None and oldcss!=newcss:
        raise RuntimeError('CSS existente com conteudo divergente')
    if subprocess.run(['php','-l'],input=newfoot,capture_output=True).returncode:
        raise RuntimeError('PHP do footer invalido')
    node=shutil.which('node')
    if node and newjs!=oldjs:
        fd,tmp=tempfile.mkstemp(suffix='.js')
        try:
            with os.fdopen(fd,'wb') as fp:fp.write(newjs)
            result=subprocess.run([node,'--check',tmp],capture_output=True,text=True)
            if result.returncode:raise RuntimeError('JS invalido: '+result.stderr[:400])
        finally:
            os.unlink(tmp)
    return [(p,old,new) for p,old,new in (
        (JS,oldjs,newjs),(CSS,oldcss,newcss),(FOOT,oldfoot,newfoot)) if old!=new]

def atomic(p,b):
    p.parent.mkdir(parents=True,exist_ok=True)
    fd,temp=tempfile.mkstemp(prefix='.jrc-native-',dir=p.parent)
    try:
        with os.fdopen(fd,'wb') as output:
            output.write(b);output.flush();os.fsync(output.fileno())
        if p.is_file():
            st=p.stat()
            os.chmod(temp,st.st_mode&0o777)
            os.chown(temp,st.st_uid,st.st_gid)
        else:os.chmod(temp,0o644)
        os.replace(temp,p)
    finally:
        if os.path.exists(temp):os.unlink(temp)

def main():
    p=argparse.ArgumentParser()
    p.add_argument('mode',choices=['plan','apply'])
    mode=p.parse_args().mode
    pending=plan()
    if mode=='plan':
        for dest,before,_ in pending:print(('CRIAR: ' if before is None else 'ALTERAR: ')+str(dest))
        print('PLAN_OK' if pending else 'JA_INSTALADO')
        return
    if not pending:
        print('JA_INSTALADO')
        return
    backup=Path('/var/backups/jrc-device-wan-wifi-native')/datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')
    backup.mkdir(parents=True,mode=0o700,exist_ok=False)
    applied=[]
    try:
        for dest,before,after in pending:
            if (dest.read_bytes() if dest.is_file() else None)!=before:
                raise RuntimeError('Arquivo mudou durante deploy: '+str(dest))
            if before is not None:
                saved=backup/dest.relative_to(ROOT)
                saved.parent.mkdir(parents=True,exist_ok=True)
                shutil.copy2(dest,saved)
            atomic(dest,after)
            applied.append((dest,before))
            print('ATUALIZADO:',dest.relative_to(ROOT))
        print('APLICADO_OK')
        print('BACKUP:',backup)
        print('ESCOPO: resumo WAN na aba WAN/PPPoE; Wi-Fi permanece em sua aba; equipamento na Visao Geral')
    except BaseException:
        for dest,before in reversed(applied):
            if before is None:dest.unlink(missing_ok=True)
            else:shutil.copy2(backup/dest.relative_to(ROOT),dest)
        raise

if __name__=='__main__':
    main()
