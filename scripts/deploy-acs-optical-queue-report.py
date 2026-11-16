#!/usr/bin/env python3
"""Opt-in, backup-safe rollout of report CSS and optical pilot source only."""
import argparse
from datetime import datetime
from pathlib import Path
import os
import shutil
import subprocess
import tempfile

SOURCE = Path(__file__).resolve().parent.parent
FILES = [
    "scripts/pilot-acs-onu-optical-refresh.php",
    "lib/JrcTr069OpticalTelemetry.php",
    "lib/JrcTr069ProfileCompare.php",
]
CSS = "assets/css/jrc-signal-report-blue.css"
MARKER = b"/* Optical report readability refinements."

def fail(msg):
    raise SystemExit("BLOQUEADO: " + msg)

def plan(root, stage):
    view = root / "view-logs.php"
    css = root / CSS
    if not (root / "config/config.php").is_file():
        fail("ACS nao encontrado")
    if any(x.is_symlink() or not x.is_file() for x in [view, css]):
        fail("Relatorio ou CSS indisponivel")
    if b"jrc-signal-report-blue.css" not in view.read_bytes():
        fail("Relatorio atual nao referencia CSS esperado")
    src_css = (SOURCE / CSS).read_bytes()
    old_css = css.read_bytes()
    if src_css.count(MARKER) != 1:
        fail("Marcador CSS inesperado")
    addition = src_css[src_css.index(MARKER):]
    changes = []
    if MARKER in old_css:
        if old_css[old_css.index(MARKER):] != addition:
            fail("CSS customizado com alteracao divergente")
    else:
        changes.append((css, old_css, old_css.rstrip(b"\n") + b"\n\n" + addition))
    if stage.is_symlink() or (stage.exists() and not stage.is_dir()):
        fail("Diretorio de ferramentas inseguro")
    for rel in FILES:
        source = SOURCE / rel
        if not source.is_file():
            fail("Fonte ausente: " + rel)
        data = source.read_bytes()
        if rel == FILES[0] and b"REFRESH_OPTICO_PENDENTE" not in data:
            fail("Protecao contra duplicacao ausente")
        with tempfile.NamedTemporaryFile(suffix=".php") as check:
            check.write(data)
            check.flush()
            if subprocess.run(["php", "-l", check.name], capture_output=True).returncode:
                fail("PHP invalido: " + rel)
        target = stage / rel
        if target.is_symlink() or (target.exists() and not target.is_file()):
            fail("Arquivo destino inseguro: " + rel)
        old = target.read_bytes() if target.exists() else None
        if old is not None and old != data:
            fail("Arquivo destino ja customizado: " + rel)
        if old is None:
            changes.append((target, None, data))
    return changes

def apply(changes, backup_root):
    if not changes:
        print("JA_INSTALADO")
        return
    backup = backup_root / datetime.utcnow().strftime("%Y%m%d-%H%M%S-%f")
    backup.mkdir(parents=True, mode=0o700)
    done = []
    try:
        for target, old, new in changes:
            target.parent.mkdir(parents=True, exist_ok=True)
            saved = backup / target.as_posix().lstrip("/")
            if old is not None:
                saved.parent.mkdir(parents=True, exist_ok=True)
                shutil.copy2(target, saved)
                st = target.stat()
            else:
                st = None
            fd, tmp = tempfile.mkstemp(prefix=".jrc-optical-", dir=target.parent)
            try:
                with os.fdopen(fd, "wb") as f:
                    f.write(new)
                    f.flush()
                    os.fsync(f.fileno())
                if st:
                    os.chmod(tmp, st.st_mode & 0o777)
                    os.chown(tmp, st.st_uid, st.st_gid)
                else:
                    os.chmod(tmp, 0o644)
                if target.is_symlink() or (target.read_bytes() if target.exists() else None) != old:
                    fail("Alteracao concorrente: " + str(target))
                os.replace(tmp, target)
                done.append((target, old, saved))
            finally:
                if os.path.exists(tmp):
                    os.unlink(tmp)
        print("APLICADO_OK")
        print("BACKUP:", backup)
    except BaseException:
        for target, old, saved in reversed(done):
            if old is None:
                target.unlink(missing_ok=True)
            else:
                shutil.copy2(saved, target)
        raise

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("mode", choices=["plan", "apply"])
    parser.add_argument("--root", type=Path, default=Path("/var/www/gacs"))
    parser.add_argument("--stage", type=Path, default=Path("/opt/jrc-optical-pilot"))
    parser.add_argument("--backups", type=Path, default=Path("/var/backups/jrc-optical-rollout"))
    args = parser.parse_args()
    changes = plan(args.root, args.stage)
    for target, _, _ in changes:
        print("ALTERAR:", target)
    if args.mode == "apply":
        apply(changes, args.backups)
    else:
        print("PLAN_OK" if changes else "JA_INSTALADO")
    print("PRESERVADO: PHP personalizado, GenieACS, fila, CPE, IXC e MCP")

if __name__ == "__main__":
    main()
