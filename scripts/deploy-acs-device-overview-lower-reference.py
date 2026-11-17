#!/usr/bin/env python3
"""Safely rearrange the existing lower device overview. No menu or API changes."""
import argparse, os, shutil, subprocess, tempfile
from datetime import datetime, timezone
from pathlib import Path
W=Path('/var/www/gacs')
JS=W/'assets/js/device-detail.js'
PHP=W/'device-detail.php'
FOOT=W/'views/layouts/footer.php'
CSS=W/'assets/css/jrc-device-overview-lower-reference.css'
SRC=Path(__file__).resolve().parent.parent/'assets/css/jrc-device-overview-lower-reference.css'
LINK=b'<link rel="stylesheet" href="/assets/css/jrc-device-overview-lower-reference.css?v=1">'
INCLUDE=(b'<?php if (basename((string)($_SERVER["SCRIPT_NAME"] ?? "")) === "device-detail.php"): ?>\n'
         +LINK+b'\n<?php endif; ?>\n')
def patch(data):
 s=data.decode('utf-8')
 if 'jrc-ref-optical-history' in s:return data
 if 'jrc-ref-device-top' not in s:raise RuntimeError('Visao Geral estrutural v1 nao identificada')
 start=s.find("        document.getElementById('overview-content').innerHTML = ")
 stop=s.find("\n        const historyMount =",start)
 if start<0 or stop<0:raise RuntimeError('Renderizador de Visao Geral nao reconhecido')
 block=s[start:stop]
 optical='$'+'{renderOpticalHistoryCard()}'
 pairs=[
  ('                <div class="fb-onu-split">\n                    '+optical+'\n                    <section class="acs-overview-card">',
   '                <div class="jrc-ref-bottom-label">Histórico de potência óptica</div>\n'
   '                <div class="jrc-ref-optical-history">\n                    '+optical+'\n                </div>\n'
   '                <section class="acs-overview-card jrc-ref-traffic-panel">'),
  ('                        <span id="live-traffic-source" hidden></span>\n                    </section>\n                </div>',
   '                        <span id="live-traffic-source" hidden></span>\n                </section>'),
  ('                <div class="fb-onu-split">\n                    <section class="acs-overview-card acs-card-wan acs-approved-wan">',
   '                <details class="jrc-ref-device-technical">\n'
   '                  <summary>Conexão, Wi-Fi e informações do equipamento</summary>\n'
   '                  <div class="fb-onu-split">\n'
   '                    <section class="acs-overview-card acs-card-wan acs-approved-wan">'),
  ('                    </section>\n                </div>\n\n                <section class="acs-overview-card fb-alerts-card">',
   '                    </section>\n                  </div>\n                </details>\n\n'
   '                <section class="acs-overview-card fb-alerts-card">')
 ]
 for old,new in pairs:
  count=block.count(old)
  if count!=1:raise RuntimeError('Ancora inferior divergente ('+str(count)+'): '+old[:65])
  block=block.replace(old,new,1)
 if block.count('id="bandwidth-bars"')!=1 or block.count(optical)!=1:
  raise RuntimeError('Grafico de trafego/historico inesperadamente alterado')
 return (s[:start]+block+s[stop:]).encode('utf-8')
def plan():
 for p in [JS,PHP,FOOT]:
  if not p.is_file() or p.is_symlink():raise RuntimeError('Arquivo de producao ausente/inseguro: '+str(p))
 if not all(x in PHP.read_bytes() for x in [b'id="overview-tab"',b'id="deviceTabs"',b'id="deviceTabContent"']):
  raise RuntimeError('Abas da producao divergentes')
 oldjs=JS.read_bytes();newjs=patch(oldjs)
 if CSS.is_symlink():raise RuntimeError('CSS e link simbolico')
 oldcss=CSS.read_bytes() if CSS.is_file() else None
 newcss=SRC.read_bytes()
 if oldcss is not None and oldcss!=newcss:raise RuntimeError('CSS existente divergente')
 oldfoot=FOOT.read_bytes()
 if b'jrc-device-detail-live-v' in oldfoot or b'JRC device detail dark NOC v1' in oldfoot:
  raise RuntimeError('Adapter de menus antigo ativo')
 if b'jrc-device-overview-reference-real.css' not in oldfoot:
  raise RuntimeError('Versao v1 do CSS ainda nao instalada')
 if oldfoot.count(LINK)>1:raise RuntimeError('Link CSS duplicado')
 if LINK in oldfoot:newfoot=oldfoot
 else:
  if oldfoot.count(b'</body>')!=1:raise RuntimeError('Footer desconhecido')
  newfoot=oldfoot.replace(b'</body>',INCLUDE+b'</body>',1)
 if subprocess.run(['php','-l'],input=newfoot,capture_output=True).returncode:
  raise RuntimeError('PHP footer invalido')
 if newjs!=oldjs and shutil.which('node'):
  fd,tmp=tempfile.mkstemp(suffix='.js')
  try:
   with os.fdopen(fd,'wb') as fp:fp.write(newjs)
   check=subprocess.run(['node','--check',tmp],capture_output=True,text=True)
   if check.returncode:raise RuntimeError('JS invalido: '+check.stderr[:350])
  finally:os.unlink(tmp)
 return [(p,a,b) for p,a,b in ((JS,oldjs,newjs),(CSS,oldcss,newcss),(FOOT,oldfoot,newfoot)) if a!=b]
def write(dest,data):
 dest.parent.mkdir(parents=True,exist_ok=True)
 fd,tmp=tempfile.mkstemp(prefix='.jrc-low-',dir=dest.parent)
 try:
  with os.fdopen(fd,'wb') as fp:fp.write(data);fp.flush();os.fsync(fp.fileno())
  if dest.is_file():
   st=dest.stat();os.chmod(tmp,st.st_mode&0o777);os.chown(tmp,st.st_uid,st.st_gid)
  else:os.chmod(tmp,0o644)
  os.replace(tmp,dest)
 finally:
  if os.path.exists(tmp):os.unlink(tmp)
def main():
 ar=argparse.ArgumentParser();ar.add_argument('mode',choices=('plan','apply'))
 mode=ar.parse_args().mode;pending=plan()
 if mode=='plan':
  for p,a,b in pending:print(('CRIAR: ' if a is None else 'ALTERAR: ')+str(p))
  print('PLAN_OK' if pending else 'JA_INSTALADO');return
 if not pending:print('JA_INSTALADO');return
 backup=Path('/var/backups/jrc-device-overview-lower')/datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')
 backup.mkdir(parents=True,mode=0o700)
 applied=[]
 try:
  for p,a,b in pending:
   if (p.read_bytes() if p.is_file() else None)!=a:raise RuntimeError('Arquivo mudou: '+str(p))
   if a is not None:
    saved=backup/p.relative_to(W);saved.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(p,saved)
   write(p,b);applied.append((p,a));print('ATUALIZADO:',p.relative_to(W))
  print('APLICADO_OK');print('BACKUP:',backup)
  print('ESCOPO: metade inferior da Visao Geral, menus intocados')
 except BaseException:
  for p,a in reversed(applied):
   if a is None:p.unlink(missing_ok=True)
   else:shutil.copy2(backup/p.relative_to(W),p)
  raise
if __name__=='__main__':main()
