#!/usr/bin/env python3
"""Safely deploy structural Devices reference layout, preserving production device features."""
import argparse
import os
import shutil
import subprocess
import tempfile
from datetime import datetime, timezone
from pathlib import Path

SOURCE=Path(__file__).resolve().parent.parent
WEB=Path('/var/www/gacs')
PAGE='devices.php'
CSS='assets/css/jrc-devices-reference.css'
OPEN=b'<div class="jrc-inventory-page">'
HERO=b'''<div class="jrc-inventory-page jrc-devices-reference">
    <header class="jdn-page-title">
      <div>
        <div class="jdn-eyebrow">JR CONECT TELECOM / INVENTARIO TR-069</div>
        <h1>Dispositivos</h1>
        <p>Gerenciamento de roteadores \xc2\xb7 consulta e a\xc3\xa7\xc3\xb5es sob demanda</p>
      </div>
      <span class="jdn-data-source"><i class="bi bi-database-check"></i> GENIEACS</span>
    </header>
    <section class="jdn-total-panel" aria-label="Resumo do inventario">
      <div class="jdn-total-top"><span><i class="bi bi-router"></i> TOTAL DE DISPOSITIVOS</span><i class="bi bi-hdd-network" aria-hidden="true"></i></div>
      <p>Invent\xc3\xa1rio de equipamentos cadastrados no ACS</p>
      <div class="jdn-total-description">Os totais e estados dependem da consulta atual do GenieACS.</div>
    </section>'''
TABLE=b'            <!-- Tabela -->'
LIST=b'''            <div class="jdn-list-heading">
                <div class="jdn-list-icon"><i class="bi bi-hdd-stack"></i></div>
                <div><h2>Lista de dispositivos</h2><p>Pesquise equipamentos e abra o gerenciamento individual</p></div>
            </div>
            <!-- Tabela -->'''
STYLE=b'<link rel="stylesheet" href="/assets/css/jrc-inventory.css?v=1">'
NEW_STYLE=STYLE+b'\n<link rel="stylesheet" href="/assets/css/jrc-devices-reference.css?v=1">'
def lint(content):
    return subprocess.run(['php','-l'],input=content,capture_output=True).returncode==0
def inspect():
    if not (WEB/'config/config.php').is_file():
        raise RuntimeError('Instalacao ACS ausente')
    page=WEB/PAGE
    css=WEB/CSS
    if not page.is_file() or page.is_symlink() or css.is_symlink():
        raise RuntimeError('Destino de pagina ou CSS inseguro')
    old=page.read_bytes()
    if b'jrc-devices-reference.css?v=1' in old:
        if old.count(b'jrc-devices-reference.css?v=1')!=1 or b'jdn-total-panel' not in old or b'jdn-list-heading' not in old:
            raise RuntimeError('Publicacao anterior incompleta')
        new=old
    else:
        if old.count(OPEN)!=1 or old.count(TABLE)!=1 or old.count(STYLE)!=1:
            raise RuntimeError('Estrutura de pagina customizada; nao aplicar automaticamente')
        # Patch only known markup. All device JS, API, actions, bulk controls stay unchanged.
        new=old.replace(OPEN,HERO,1).replace(TABLE,LIST,1).replace(STYLE,NEW_STYLE,1)
    if not lint(new):
        raise RuntimeError('PHP invalido apos alteracao')
    css_old=css.read_bytes() if css.is_file() else None
    css_new=(SOURCE/CSS).read_bytes()
    if css_old is not None and css_old!=css_new:
        raise RuntimeError('CSS ja existe e difere, revisao necessaria')
    result=[]
    if old!=new:result.append((PAGE,page,old,new))
    if css_old!=css_new:result.append((CSS,css,css_old,css_new))
    return result
def write(dst,data,original_exists):
    dst.parent.mkdir(parents=True,exist_ok=True)
    fd,temp=tempfile.mkstemp(prefix='.jrc-dir-v2-',dir=dst.parent)
    try:
        with os.fdopen(fd,'wb') as out:
            out.write(data);out.flush();os.fsync(out.fileno())
        if original_exists:
            st=dst.stat();os.chmod(temp,st.st_mode & 0o777);os.chown(temp,st.st_uid,st.st_gid)
        else:os.chmod(temp,0o644)
        os.replace(temp,dst)
    finally:
        if os.path.exists(temp):os.unlink(temp)
def main():
    p=argparse.ArgumentParser();p.add_argument('mode',choices=('plan','apply'));mode=p.parse_args().mode
    changes=inspect()
    if mode=='plan':
        for name,_,old,_ in changes:print('ATUALIZAR:' if old is not None else 'CRIAR:',name)
        print('PLAN_OK' if changes else 'JA_INSTALADO')
        return
    if not changes:
        print('JA_INSTALADO')
        return
    backup=Path('/var/backups/jrc-devices-reference')/datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')
    backup.mkdir(parents=True,exist_ok=False,mode=0o700)
    applied=[]
    try:
        for name,dst,old,new in changes:
            if old is not None:
                b=backup/name;b.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(dst,b)
            if dst.is_symlink() or (dst.read_bytes() if dst.exists() else None)!=old:
                raise RuntimeError('Alteracao concorrente: '+name)
            write(dst,new,old is not None)
            applied.append((name,dst,old))
            print('ATUALIZADO:',name)
        print('APLICADO_OK')
        print('BACKUP:',backup)
        print('ESCOPO: estrutura e estilo em /devices.php; RX oculto na lista; acoes preservadas')
    except BaseException:
        for name,dst,old in reversed(applied):
            if old is None:dst.unlink(missing_ok=True)
            else:shutil.copy2(backup/name,dst)
        raise
if __name__=='__main__':main()
