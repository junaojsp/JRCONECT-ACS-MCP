#!/usr/bin/env python3
"""Visual correction: remove redundant WAN banner and translate existing Wi-Fi modal.

Uses exact anchors. Keeps actions and native form IDs unchanged.
"""
import argparse, os, shutil, subprocess, tempfile
from pathlib import Path
from datetime import datetime,timezone
ROOT=Path('/var/www/gacs')
JS=ROOT/'assets/js/device-detail.js'
MODAL=ROOT/'views/device-detail/modals.php'
OLD_WAN="""        ).join('') + '</div>' +
        '<div class="jrc-wan-ref-section"><i class="bi bi-globe2"></i><div><strong>Conexões WAN</strong>' +
        '<small>Detalhes das interfaces e gerenciamento do equipamento</small></div></div>';"""
NEW_WAN="""        ).join('') + '</div>';"""
TRANSLATIONS={
    'Edit WiFi Configuration':'Configurar rede Wi-Fi',
    'Perubahan akan dikirim ke ONU melalui GenieACS. Device mungkin perlu reboot untuk menerapkan konfigurasi baru.':'As alterações serão enviadas ao equipamento pelo GenieACS. Pode ser necessário reiniciá-lo para aplicar a configuração.',
    'WiFi SSID':'Nome da rede (SSID)',
    'Enter WiFi SSID (1-32 characters)':'Nome da rede (1 a 32 caracteres)',
    'SSID harus antara 1-32 karakter':'O SSID deve ter de 1 a 32 caracteres.',
    'Security Mode':'Segurança da rede',
    'WPA2-PSK (Recommended)':'WPA2-PSK (recomendado)',
    'WPA/WPA2-PSK Mixed':'WPA/WPA2-PSK (misto)',
    'Open (No Security)':'Aberta (sem senha)',
    'Pilih mode keamanan WiFi':'Selecione o modo de segurança.',
    'WiFi Password':'Senha da rede Wi-Fi',
    'Enter WiFi Password (8-63 characters)':'Senha Wi-Fi (8 a 63 caracteres)',
    'Password harus antara 8-63 karakter untuk WPA/WPA2':'Para WPA/WPA2, a senha deve ter de 8 a 63 caracteres.',
    'WLAN Interface':'Interface WLAN',
    'WLAN 1 (2.4GHz - Default)':'WLAN 1 (2,4 GHz — padrão)',
    'Pilih interface WLAN yang ingin diubah':'Selecione a interface WLAN que deseja alterar.',
    'Update WiFi':'Salvar alterações',
}
def transform():
    for p in (JS,MODAL):
        if not p.is_file() or p.is_symlink():
            raise RuntimeError('Arquivo de producao ausente ou simbolico: '+str(p))
    before_js=JS.read_text(encoding='utf-8')
    if before_js.count(OLD_WAN)==1:
        after_js=before_js.replace(OLD_WAN,NEW_WAN,1)
    elif before_js.count(NEW_WAN)>0 and 'jrcWANReferenceHeader' in before_js and 'jrc-wan-ref-section' not in before_js:
        after_js=before_js
    else:
        raise RuntimeError('Cabecalho WAN duplicado nao identificado; nao aplicar')
    original=MODAL.read_text(encoding='utf-8')
    start=original.find('<!-- Edit WiFi Configuration Modal -->')
    end=original.find('<!-- Edit WAN Configuration Modal -->',start)
    if start<0 or end<0:raise RuntimeError('Modal Wi-Fi nao identificado')
    section=original[start:end]
    if not all(x in section for x in ('id="editWiFiModal"','id="editWiFiForm"',
                                      'onclick="confirmUpdateWiFi()"','id="edit-wlan-index"')):
        raise RuntimeError('Estrutura do formulario Wi-Fi divergente')
    for old,new in TRANSLATIONS.items():
        if old in section:section=section.replace(old,new)
        elif new not in section:raise RuntimeError('Texto Wi-Fi nao reconhecido: '+old)
    updated=original[:start]+section+original[end:]
    if subprocess.run(['php','-l'],input=updated.encode(),capture_output=True).returncode:
        raise RuntimeError('Modal nao passou no lint PHP')
    if after_js!=before_js:
        if not shutil.which('node'):raise RuntimeError('Node.js necessario para validar JS')
        fd,tmp=tempfile.mkstemp(suffix='.js')
        try:
            with os.fdopen(fd,'w',encoding='utf-8') as f:f.write(after_js)
            ck=subprocess.run(['node','--check',tmp],capture_output=True,text=True)
            if ck.returncode:raise RuntimeError('JavaScript invalido: '+ck.stderr[:500])
        finally:os.unlink(tmp)
    return [(JS,before_js.encode(),after_js.encode()),(MODAL,original.encode(),updated.encode())]
def atomic(path,content):
    fd,tmp=tempfile.mkstemp(prefix='.jrc-ui-',dir=path.parent)
    try:
        with os.fdopen(fd,'wb') as out:out.write(content);out.flush();os.fsync(out.fileno())
        st=path.stat();os.chmod(tmp,st.st_mode & 0o777);os.chown(tmp,st.st_uid,st.st_gid)
        os.replace(tmp,path)
    finally:
        if os.path.exists(tmp):os.unlink(tmp)
def main():
    arg=argparse.ArgumentParser();arg.add_argument('mode',choices=('plan','apply'))
    mode=arg.parse_args().mode
    pending=[(p,a,b) for p,a,b in transform() if a!=b]
    if mode=='plan':
        for p,_,_ in pending:print('ALTERAR:',p)
        print('PLAN_OK' if pending else 'JA_INSTALADO');return
    if not pending:print('JA_INSTALADO');return
    backup=Path('/var/backups/jrc-wan-header-wifi-modal')/datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')
    backup.mkdir(parents=True,mode=0o700)
    done=[]
    try:
        for p,original,new in pending:
            if p.read_bytes()!=original:raise RuntimeError('Arquivo mudou durante deploy: '+str(p))
            saved=backup/p.relative_to(ROOT)
            saved.parent.mkdir(parents=True,exist_ok=True)
            shutil.copy2(p,saved)
            atomic(p,new);done.append(p);print('ATUALIZADO:',p.relative_to(ROOT))
        print('APLICADO_OK');print('BACKUP:',backup)
        print('ESCOPO: titulo WAN duplicado e textos do modal Wi-Fi; dados e acoes preservados')
    except BaseException:
        for p in reversed(done):shutil.copy2(backup/p.relative_to(ROOT),p)
        raise
if __name__=='__main__':main()
