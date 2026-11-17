#!/usr/bin/env python3
"""Deploy a structural patch for the exact uploaded production device-detail.js.
Only the overview template is changed. Existing menus, tabs, IDs and actions remain.
"""
import argparse
import hashlib
import os
import shutil
import subprocess
import tempfile
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(os.environ.get('JRC_ACS_TEST_ROOT', '/var/www/gacs'))
SOURCE = Path(__file__).resolve().parent
JS = ROOT/'assets/js/device-detail.js'
PHP = ROOT/'device-detail.php'
FOOTER = ROOT/'views/layouts/footer.php'
CSS = ROOT/'assets/css/jrc-device-overview-reference-real.css'
CSS_SOURCE = SOURCE.parent/'assets/css/jrc-device-overview-reference-real.css'
ORIGINAL_SHA = '908bd07bb473754137972f1647e9752d51271c83f1491d410a7a484e6b5c49e9'
MARKER = b'jrc-ref-device-top'
LINK = b'<link rel="stylesheet" href="/assets/css/jrc-device-overview-reference-real.css?v=1">'
CONDITION = (b'    <?php if (basename((string)($_SERVER["SCRIPT_NAME"] ?? "")) === "device-detail.php"): ?>\n'
             b'    <!-- ACS overview reference: native template, no navigation modifications -->\n'
             b'    '+LINK+b'\n'
             b'    <?php endif; ?>\n')


def patch(source):
    text = source.decode('utf-8')
    var_anchor = '        const uptimeLabel = formatUptimeValue(device.uptime);\n'
    if text.count(var_anchor) != 1:
        raise RuntimeError('Variaveis da visao geral nao reconhecidas')
    vars_text = (SOURCE/'reference-device-overview-vars.inc').read_text()
    text = text.replace(var_anchor, var_anchor + vars_text, 1)
    start_anchor = "        document.getElementById('overview-content').innerHTML = "
    begin = text.find(start_anchor)
    if begin < 0: raise RuntimeError('Renderizador da visao geral nao encontrado')
    end = text.find('\n        const historyMount =', begin)
    if end < 0: raise RuntimeError('Fim do renderizador nao reconhecido')
    block = text[begin:end]
    first = '            <div class="fb-onu">\n'
    diag = '                <section class="acs-overview-card fb-diag-card">\n'
    pair_end = '                </section>\n\n                <div class="fb-onu-split">\n'
    indicators = '                <section class="fb-indicators">\n'
    tail = '            </div>\n        '+chr(96)+';'
    for name, value in [('ONU',first),('diagnostico',diag),('indicadores',indicators),('fecho',tail)]:
        if block.count(value) != 1:
            raise RuntimeError('Ancora '+name+' nao reconhecida: '+str(block.count(value)))
    if block.count(pair_end) != 2:
        raise RuntimeError('Dois paines originais nao reconhecidos')
    html = (SOURCE/'reference-device-overview-template.inc').read_text()
    block = block.replace(first,html + first,1)
    block = block.replace(diag,'                <details class="jrc-ref-technical"><summary>Diagnóstico detalhado</summary>\n'+diag,1)
    block = block.replace(pair_end,'                </section>\n                </details>\n\n                <div class="fb-onu-split">\n',1)
    block = block.replace(indicators,'                <details class="jrc-ref-technical"><summary>Mais indicadores e medições</summary>\n'+indicators,1)
    block = block.replace(pair_end,'                </section>\n                </details>\n\n                <div class="fb-onu-split">\n',1)
    block = block.replace(tail,'            </div>\n            </div>\n        '+chr(96)+';',1)
    text = text[:begin] + block + text[end:]
    return text.encode('utf-8')


def planned():
    for p in (JS,PHP,FOOTER):
        if not p.is_file() or p.is_symlink():
            raise RuntimeError('Arquivo ausente ou simbolico: '+str(p))
    original = JS.read_bytes()
    if MARKER in original:
        upgraded = original
    else:
        sha = hashlib.sha256(original).hexdigest()
        if sha != ORIGINAL_SHA:
            raise RuntimeError('O JS da producao mudou desde o pacote revisado. SHA: '+sha)
        upgraded = patch(original)
    php = PHP.read_bytes()
    if not all(x in php for x in [b'id="overview-tab"',b'id="deviceTabs"',b'id="deviceTabContent"']):
        raise RuntimeError('Abas do PHP nao correspondem ao pacote revisado')
    footer = FOOTER.read_bytes()
    if b'JRC device detail dark NOC v1' in footer or b'jrc-device-detail-live-v' in footer:
        raise RuntimeError('Adaptador v4-v6 ainda esta ativo no footer')
    if footer.count(LINK) > 1:raise RuntimeError('Link CSS duplicado')
    if LINK in footer: new_footer=footer
    else:
        if footer.count(b'</body>') != 1:raise RuntimeError('Footer nao reconhecido')
        new_footer=footer.replace(b'</body>',CONDITION+b'</body>',1)
    if CSS.is_symlink():raise RuntimeError('CSS e link simbolico')
    old_css=CSS.read_bytes() if CSS.is_file() else None
    new_css=CSS_SOURCE.read_bytes()
    if old_css is not None and old_css != new_css:raise RuntimeError('CSS existente divergente')
    node=shutil.which('node')
    if node:
        tmp=tempfile.NamedTemporaryFile(suffix='.js',delete=False)
        try:
            tmp.write(upgraded);tmp.close()
            syntax=subprocess.run([node,'--check',tmp.name],capture_output=True,text=True)
            if syntax.returncode:raise RuntimeError('JS nao valida: '+syntax.stderr[:500])
        finally:os.unlink(tmp.name)
    php_check=subprocess.run(['php','-l'],input=new_footer,capture_output=True)
    if php_check.returncode:raise RuntimeError('Footer PHP invalido')
    return [(path,before,after) for path,before,after in [
        (JS,original,upgraded),(CSS,old_css,new_css),(FOOTER,footer,new_footer)] if before != after]


def atomic(dest,data):
    dest.parent.mkdir(parents=True,exist_ok=True)
    fd,tmp=tempfile.mkstemp(prefix='.acs-reference-',dir=dest.parent)
    try:
        with os.fdopen(fd,'wb') as out:
            out.write(data);out.flush();os.fsync(out.fileno())
        if dest.exists():
            stat=dest.stat()
            os.chmod(tmp,stat.st_mode & 0o777)
            os.chown(tmp,stat.st_uid,stat.st_gid)
        else:os.chmod(tmp,0o644)
        os.replace(tmp,dest)
    finally:
        if os.path.exists(tmp):os.unlink(tmp)


def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('mode',choices=['plan','apply'])
    mode=parser.parse_args().mode
    changes=planned()
    if mode == 'plan':
        for path,before,_ in changes:
            print(('CRIAR: ' if before is None else 'ALTERAR: ')+str(path))
        print('PLAN_OK' if changes else 'JA_INSTALADO')
        return
    if not changes:
        print('JA_INSTALADO')
        return
    backup=Path('/var/backups/jrc-device-reference-real')/datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')
    backup.mkdir(parents=True,mode=0o700,exist_ok=False)
    applied=[]
    try:
        for path,before,after in changes:
            if (path.read_bytes() if path.is_file() else None) != before:
                raise RuntimeError('Arquivo alterado durante o deploy: '+str(path))
            if before is not None:
                saved=backup/path.relative_to(ROOT)
                saved.parent.mkdir(parents=True,exist_ok=True)
                shutil.copy2(path,saved)
            atomic(path,after)
            applied.append((path,before))
            print('ATUALIZADO:',path.relative_to(ROOT))
        print('APLICADO_OK')
        print('BACKUP:',backup)
        print('ESCOPO: apenas HTML da Visao Geral e CSS; nenhuma mudanca no menu ou outras abas')
    except BaseException:
        for path,before in reversed(applied):
            if before is None:path.unlink(missing_ok=True)
            else:shutil.copy2(backup/path.relative_to(ROOT),path)
        raise


if __name__ == '__main__':
    main()
