#!/usr/bin/env python3
"""Deploy additive, read-only ACS device search without overwriting customized production pages.
python3 scripts/deploy-firmware-device-search.py plan
sudo python3 scripts/deploy-firmware-device-search.py apply
"""
from pathlib import Path
from datetime import datetime, timezone
import os
import shutil
import subprocess
import sys

WEB=Path("/var/www/gacs")
PAGE=WEB/"firmware-homologation.php"
API=WEB/"api/firmware-device-search.php"
SOURCE_PAGE=Path(__file__).resolve().parent.parent/"firmware-homologation.php"
SOURCE_API=Path(__file__).resolve().parent.parent/"api/firmware-device-search.php"

FORM_MARK='<div class="grid">\n<div><label for="device-id">ID exato no GenieACS</label>'
BLOCK_START='<div class="card" style="margin:14px 0;background:#10283f">'
CSS_START='\n.jrc-fwb .fw-search-modes{display:flex;gap:18px;flex-wrap:wrap}'
SCRIPT_START='<script>\n(function(){'
END_PAGE="<?php include __DIR__.'/views/layouts/footer.php'; ?>"
OLD_DESC='Etapa de cadastro e conferencia. A verificacao ao vivo do dispositivo e o envio do firmware estao bloqueados ate integrarmos as rotinas existentes do ACS.'
NEW_DESC='Busque ONUs no inventario do GenieACS e vincule uma ONU de bancada ao firmware. Apenas leitura do cadastro; nenhuma atualizacao e enviada.'

def fail(msg):
    raise SystemExit("ABORTADO: "+msg)
def exactly(s,needle,label):
    if s.count(needle)!=1: fail("estrutura inesperada: "+label)
def patch(live, source):
    exactly(live,FORM_MARK,"formulario atual")
    exactly(source,FORM_MARK,"formulario fonte")
    exactly(source,BLOCK_START,"inicio de busca")
    new_form=source.split(BLOCK_START,1)[1].split(FORM_MARK,1)[0]
    if 'fw-search-button' in live or '/api/firmware-device-search.php' in live:
        fail("busca ja presente; preservar producao")
    if not new_form.endswith('\n'): fail("formulario fonte inesperado")
    live=live.replace(FORM_MARK,BLOCK_START+new_form+FORM_MARK,1)
    exactly(source,CSS_START,"CSS de busca")
    new_css=CSS_START+source.split(CSS_START,1)[1].split("</style>",1)[0]
    exactly(live,"</style>","style atual")
    live=live.replace("</style>",new_css+"</style>",1)
    exactly(source,SCRIPT_START,"script de busca")
    new_script=SCRIPT_START+source.split(SCRIPT_START,1)[1].split("</script>",1)[0]+"</script>"
    exactly(live,END_PAGE,"footer atual")
    live=live.replace(END_PAGE,new_script+"\n"+END_PAGE,1)
    if live.count(OLD_DESC)==1:
        live=live.replace(OLD_DESC,NEW_DESC,1)
    return live

def main():
    if len(sys.argv)!=2 or sys.argv[1] not in ("plan","apply"):
        fail("uso: plan | apply")
    action=sys.argv[1]
    for p in (PAGE,SOURCE_PAGE,SOURCE_API):
        if not p.is_file(): fail("arquivo ausente: "+str(p))
    if API.exists() or API.is_symlink(): fail("endpoint ja existe; nao sobrescrever")
    orig=PAGE.read_bytes()
    try:
        new=patch(orig.decode("utf-8"),SOURCE_PAGE.read_text(encoding="utf-8"))
    except UnicodeDecodeError:
        fail("codificacao desconhecida em producao")
    print("BUSCA: MAC, serial, ID e modelo no GenieACS NBI (GET somente)")
    print("PAGINA: adicao pontual; cadastro, links e registros existentes preservados")
    print("ENDPOINT: /api/firmware-device-search.php (novo)")
    print("ONU: nenhuma tarefa, atualizacao ou escrita")
    if action=="plan":
        print("PLAN_OK: nenhuma alteracao feita")
        return
    if os.geteuid()!=0: fail("apply exige sudo/root")
    ts=datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ")
    backup=PAGE.with_name("firmware-homologation.php.before-search-"+ts+".bak")
    if backup.exists(): fail("backup ja existe")
    temp_page=PAGE.with_name(".firmware-homologation.search."+str(os.getpid()))
    temp_api=API.with_name(".firmware-device-search."+str(os.getpid())+".php")
    api_done=False
    try:
        st=PAGE.stat()
        temp_page.write_text(new,encoding="utf-8")
        shutil.copy2(SOURCE_API,temp_api)
        for f in (temp_page,temp_api):
            os.chown(f,st.st_uid,st.st_gid)
            os.chmod(f,st.st_mode & 0o777)
            p=subprocess.run(["php","-l",str(f)],capture_output=True,text=True)
            if p.returncode:
                fail("PHP lint falhou: "+str(f)+" "+p.stderr)
        if PAGE.read_bytes()!=orig or API.exists(): fail("arquivos mudaram durante instalacao")
        shutil.copy2(PAGE,backup)
        os.replace(temp_api,API)
        api_done=True
        os.replace(temp_page,PAGE)
        print("APLICADO_OK")
        print("BACKUP:",backup)
        print("URL: /firmware-homologation.php")
        print("NBI: usa loopback 127.0.0.1:7557 por padrao; confirmar acesso na interface")
    except BaseException:
        if api_done:
            API.unlink(missing_ok=True)
        raise
    finally:
        temp_page.unlink(missing_ok=True)
        temp_api.unlink(missing_ok=True)
if __name__=="__main__":
    main()
