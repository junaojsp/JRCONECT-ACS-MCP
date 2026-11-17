#!/usr/bin/env python3
"""Remove the duplicated WAN/Wi-Fi block from the device overview at its source.

The existing WAN/PPPoE and Wi-Fi tabs remain unchanged. Fail closed on drift.
"""
import argparse
import os
import re
import shutil
import subprocess
import tempfile
from datetime import datetime, timezone
from pathlib import Path

ROOT=Path('/var/www/gacs')
PAGE=ROOT/'device-detail.php'
JS=ROOT/'assets/js/device-detail.js'
MARK='JRC_WAN_WIFI_NATIVE_TABS_V3'
BANNER='acs-card-wan acs-approved-wan'
FIND_BLOCK=re.compile(
    r'\n[ \t]*<section class="acs-overview-card acs-card-wan acs-approved-wan">.*?\n[ \t]*</section>',
    re.DOTALL
)
# The v3 attempt added an auto-relocation IIFE; remove it entirely.
FIND_V3=re.compile(
    r'\n[ \t]*// JRC_WAN_WIFI_NATIVE_TABS_V3[^\n]*\n.*?\n[ \t]*\}\)\(\);[ \t]*',
    re.DOTALL
)

def patch(current):
    source=current.decode('utf-8')
    if 'jrc-ref-device-top' not in source or 'jrc-ref-device-technical' not in source:
        raise RuntimeError('Visao Geral do pacote real nao reconhecida')
    begin=source.find("        document.getElementById('overview-content').innerHTML = ")
    end=source.find('\n        const historyMount =',begin)
    if begin < 0 or end < 0:
        raise RuntimeError('Limites da Visao Geral nao encontrados')
    part=source[begin:end]
    if 'JRC_NATIVE_TABS_CLEAN_V4' in source:
        if BANNER in part:raise RuntimeError('Instalacao parcial detectada')
        return current
    if part.count(BANNER)!=1:
        raise RuntimeError('Bloco WAN da Visao Geral inesperado: '+str(part.count(BANNER)))
    part,n=FIND_BLOCK.subn('',part,count=1)
    if n!=1 or BANNER in part:
        raise RuntimeError('Nao foi possivel remover somente o bloco misto')
    # Only equipment details remain inside the existing native <details>.
    part=part.replace('<summary>Informações do equipamento</summary>',
                      '<summary>Dados do equipamento</summary>')
    part=part.replace('<summary>Conexão, Wi-Fi e informações do equipamento</summary>',
                      '<summary>Dados do equipamento</summary>')
    if part.count('<summary>Dados do equipamento</summary>')!=1:
        raise RuntimeError('Titulo do acordeao de equipamento nao identificado')
    source=source[:begin]+part+source[end:]
    source,n=FIND_V3.subn('\n',source,count=1)
    if n!=1:
        raise RuntimeError('Adaptacao v3 de movimento de DOM nao encontrada; nenhuma alteracao')
    # Keep the native tab's own WAN renderer and Wi-Fi renderer unchanged.
    if source.count("document.getElementById('wan-content').innerHTML = renderWANDetailsTab(device.wan_details);")!=1:
        raise RuntimeError('Renderizador WAN nativo nao reconhecido')
    if 'id="wan-ip"' in part or 'id="wan-pppoe-user"' in part:
        raise RuntimeError('ID antigo da WAN ainda permanece na Visao Geral')
    source=source.replace('        // Populate WAN Connections Tab',
                          '        // JRC_NATIVE_TABS_CLEAN_V4: WAN and Wi-Fi live only in their native tabs.\n'
                          '        // Populate WAN Connections Tab',1)
    return source.encode('utf-8')

def inspect():
    if not PAGE.is_file() or PAGE.is_symlink() or not JS.is_file() or JS.is_symlink():
        raise RuntimeError('Arquivos personalizados nao encontrados')
    page=PAGE.read_bytes()
    if not all(x in page for x in (b'id="overview-tab"',b'id="wifi-tab"',b'id="wan-tab"',
                                   b'id="deviceTabs"',b'id="deviceTabContent"')):
        raise RuntimeError('Abas nativas nao reconhecidas')
    before=JS.read_bytes()
    after=patch(before)
    if before!=after and shutil.which('node'):
        fd,temp=tempfile.mkstemp(suffix='.js')
        try:
            with os.fdopen(fd,'wb') as file:file.write(after)
            check=subprocess.run(['node','--check',temp],capture_output=True,text=True)
            if check.returncode:raise RuntimeError('Erro de sintaxe JS: '+check.stderr[:600])
        finally:os.unlink(temp)
    return before,after

def main():
    cli=argparse.ArgumentParser()
    cli.add_argument('mode',choices=('plan','apply'))
    mode=cli.parse_args().mode
    before,after=inspect()
    if mode=='plan':
        print('ALTERAR: '+str(JS) if before!=after else 'JA_INSTALADO')
        print('PLAN_OK' if before!=after else 'JA_INSTALADO')
        return
    if before==after:
        print('JA_INSTALADO')
        return
    backup=Path('/var/backups/jrc-native-tabs-clean-v4')/datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')
    backup.mkdir(parents=True,mode=0o700)
    saved=backup/'device-detail.js'
    shutil.copy2(JS,saved)
    if JS.read_bytes()!=before:raise RuntimeError('JS mudou durante instalacao')
    fd,temp=tempfile.mkstemp(prefix='.jrc-native-v4-',dir=JS.parent)
    try:
        with os.fdopen(fd,'wb') as file:
            file.write(after);file.flush();os.fsync(file.fileno())
        st=JS.stat()
        os.chmod(temp,st.st_mode & 0o777)
        os.chown(temp,st.st_uid,st.st_gid)
        os.replace(temp,JS)
        print('APLICADO_OK')
        print('BACKUP:',saved)
        print('ESCOPO: remover bloco WAN/Wi-Fi duplicado da Visao Geral; abas nativas mantidas')
    except BaseException:
        if os.path.exists(temp):os.unlink(temp)
        shutil.copy2(saved,JS)
        raise
if __name__=='__main__':main()
