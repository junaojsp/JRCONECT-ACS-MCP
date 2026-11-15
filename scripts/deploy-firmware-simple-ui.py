#!/usr/bin/env python3
"""Deploy simplified firmware bench UI without changing backend behavior.
Usage: python3 scripts/deploy-firmware-simple-ui.py plan|apply
"""
from pathlib import Path
from datetime import datetime, timezone
import os
import shutil
import subprocess
import sys

PAGE = Path("/var/www/gacs/firmware-homologation.php")
SOURCE = Path(__file__).resolve().parent.parent / "firmware-homologation.php"
SECTION_START = '<section class="jrc-fwb">'
SECTION_END = '</section>'
SCRIPT_START = '<script>\n(function(){'
SCRIPT_END = '</script>'
STYLE_END = '</style>'
CSS_START = '/* JRC_FW_SIMPLE_FLOW_V1 */'
OLD_MARKERS = ('Vincular ONU de bancada a imagem cadastrada',
               'fw-search-button', 'Registrar bancada (sem atualizar)')
NEW_MARKERS = ('Firmware de ONU', 'fw-simple-form', 'fw-simple-find')

def fail(text):
    raise SystemExit("ABORTADO: " + text)

def segment(text, start, end, label):
    if text.count(start) != 1:
        fail("marcador inicial inesperado: " + label)
    a = text.index(start)
    b = text.find(end, a)
    if b < 0:
        fail("marcador final nao encontrado: " + label)
    return a, b + len(end)

def patch(live, source):
    if any(token not in live for token in OLD_MARKERS):
        fail("tela de producao nao corresponde ao layout anterior conhecido")
    if any(token in live for token in NEW_MARKERS):
        fail("layout simplificado ja instalado")
    if any(token not in source for token in NEW_MARKERS):
        fail("fonte simplificada incompleta")
    lo, hi = segment(live, SECTION_START, SECTION_END, "painel atual")
    so, si = segment(source, SECTION_START, SECTION_END, "painel novo")
    live = live[:lo] + source[so:si] + live[hi:]
    lo, hi = segment(live, SCRIPT_START, SCRIPT_END, "script atual")
    so, si = segment(source, SCRIPT_START, SCRIPT_END, "script novo")
    live = live[:lo] + source[so:si] + live[hi:]
    if source.count(CSS_START) != 1 or STYLE_END not in source:
        fail("CSS simplificado incompleto")
    css = source.split(CSS_START, 1)[1].split(STYLE_END, 1)[0]
    if not css.strip():
        fail("CSS vazio")
    if live.count(STYLE_END) != 1:
        fail("bloco de CSS do painel inesperado")
    live = live.replace(STYLE_END, "\n" + CSS_START + css + STYLE_END, 1)
    return live

def main():
    if len(sys.argv) != 2 or sys.argv[1] not in ("plan", "apply"):
        fail("uso: plan | apply")
    mode = sys.argv[1]
    if not PAGE.is_file() or not SOURCE.is_file():
        fail("pagina de producao ou fonte ausente")
    original = PAGE.read_bytes()
    try:
        new_page = patch(original.decode("utf-8"), SOURCE.read_text(encoding="utf-8"))
    except UnicodeDecodeError:
        fail("codificacao inesperada")
    print("INTERFACE: buscar ONU -> imagem compativel -> preparar teste")
    print("BACKEND: mantido; firmware e historico preservados")
    print("GENIEACS: nenhuma tarefa de escrita ou atualizacao")
    if mode == "plan":
        print("PLAN_OK: nenhuma alteracao feita")
        return
    if os.geteuid() != 0:
        fail("apply exige sudo/root")
    backup = PAGE.with_name("firmware-homologation.php.before-simple-ui-" +
        datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ") + ".bak")
    temp = PAGE.with_name(".firmware-homologation.simple-ui." + str(os.getpid()))
    if backup.exists():
        fail("backup ja existente")
    try:
        stat = PAGE.stat()
        temp.write_text(new_page, encoding="utf-8")
        os.chown(temp, stat.st_uid, stat.st_gid)
        os.chmod(temp, stat.st_mode & 0o777)
        check = subprocess.run(["php", "-l", str(temp)], capture_output=True, text=True)
        if check.returncode:
            fail("php -l falhou: " + check.stderr.strip())
        if PAGE.read_bytes() != original:
            fail("pagina alterada durante preparacao")
        shutil.copy2(PAGE, backup)
        os.replace(temp, PAGE)
        print("APLICADO_OK")
        print("BACKUP:", backup)
        print("URL: /firmware-homologation.php")
    finally:
        temp.unlink(missing_ok=True)

if __name__ == "__main__":
    main()
