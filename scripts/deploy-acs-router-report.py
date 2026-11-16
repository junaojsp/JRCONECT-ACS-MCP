#!/usr/bin/env python3
"""Opt-in router-report deployment for the customized JR CONECT ACS.

Install two new read-only report files and update ONLY the already installed
router-badge JS. No changes to dashboard.php, customized view-logs.php, IXC,
GenieACS/TR-069, firmware, tasks, flags, cron, MCP, credentials or database.
"""
from __future__ import annotations

import argparse
from datetime import datetime, timezone
import hashlib
import os
import re
from pathlib import Path
import shutil
import subprocess
import tempfile

ROOT = Path("/var/www/gacs")
SOURCE = Path(__file__).resolve().parent.parent
BACKUPS = Path("/var/backups/jrc-acs-router-report")

JS = "assets/js/jrc-router-signal.js"
VIEW = "view-logs.php"
CSS = "assets/css/jrc-signal-report-blue.css"
NEW = ("lib/JrcRouterReportRows.php", "router-report.php", CSS)
FILES = (*NEW, VIEW, JS)

CSS_MARK = "/assets/css/jrc-signal-report-blue.css"
CSS_INCLUDE = 'echo \'<link rel="stylesheet" href="/assets/css/jrc-signal-report-blue.css?v=1">\';'
HEADER_INCLUDE = re.compile(
    r"""(?:include|require)(?:_once)?\s+__DIR__\s*\.\s*
        (?P<q>['"])/views/layouts/header\.php(?P=q)\s*;""",
    re.VERBOSE
)
# The exact Git blob of the JS deployed from the earlier v2 correction.
INSTALLED_V2_JS_BLOB = "f652e05a39a33abe64ef629e260f480f732fc72e"


class UnsafeInstall(RuntimeError):
    pass


def git_blob_sha(data: bytes) -> str:
    return hashlib.sha1(b"blob " + str(len(data)).encode() + b"\0" + data).hexdigest()


def syntax_check(name: str, data: bytes) -> None:
    if not name.endswith(".php"):
        return
    with tempfile.NamedTemporaryFile(suffix=".php") as f:
        f.write(data)
        f.flush()
        done = subprocess.run(["php", "-l", f.name],
                              capture_output=True, text=True, check=False)
    if done.returncode:
        raise UnsafeInstall("Sintaxe PHP invalida: " + name)


def patch_existing_report(before: bytes) -> bytes:
    try:
        report = before.decode("utf-8")
    except UnicodeDecodeError as exc:
        raise UnsafeInstall("Relatorio personalizado nao esta em UTF-8") from exc

    if CSS_MARK in report:
        if report.count(CSS_INCLUDE) == 1 and report.count(CSS_MARK) == 1:
            return before
        raise UnsafeInstall("Estilo da pagina de relatorios difere do instalador")

    if "requireLogin" not in report:
        raise UnsafeInstall("Relatorio sem autenticacao reconhecida")
    found = list(HEADER_INCLUDE.finditer(report))
    if len(found) != 1:
        raise UnsafeInstall("Relatorio sem include do header unico e reconhecido")
    match = found[0]
    last_open = report.lower().rfind("<?php", 0, match.start())
    last_close = report.rfind("?>", 0, match.start())
    if last_open < 0 or last_open < last_close:
        raise UnsafeInstall("Include do header fora do bloco PHP")
    patched = report[:match.end()] + "\n" + CSS_INCLUDE + "\n" + report[match.end():]
    return patched.encode("utf-8")


def plan(root: Path, old_js_sha: str = INSTALLED_V2_JS_BLOB) -> dict[str, tuple[bytes | None, bytes]]:
    if not (root / "config/config.php").is_file():
        raise UnsafeInstall("Diretorio do ACS nao encontrado")
    dashboard = root / "dashboard.php"
    if (not dashboard.is_file() or dashboard.is_symlink()
            or b"/assets/js/jrc-router-signal.js" not in dashboard.read_bytes()):
        raise UnsafeInstall("Indicador de roteadores nao esta instalado no dashboard")
    changes = {}
    for name in FILES:
        target = root / name
        if target.is_symlink() or (target.exists() and not target.is_file()):
            raise UnsafeInstall("Caminho alvo inseguro: " + name)
        old = target.read_bytes() if target.exists() else None
        if name == VIEW:
            if old is None:
                raise UnsafeInstall("Relatorio de sinal personalizado nao encontrado")
            new = patch_existing_report(old)
        else:
            source = SOURCE / name
            if not source.is_file():
                raise UnsafeInstall("Arquivo de origem ausente: " + name)
            new = source.read_bytes()
        if old == new:
            continue
        if name in NEW and old is not None:
            raise UnsafeInstall("Arquivo de relatorio existente: nao sobrepor " + name)
        if name == JS:
            if old is None:
                raise UnsafeInstall("JS da etapa anterior ausente")
            if git_blob_sha(old) != old_js_sha:
                raise UnsafeInstall("JS difere da versao v2 homologada; nao sobrepor")
        syntax_check(name, new)
        changes[name] = (old, new)
    return changes


def apply(root: Path, backups: Path,
          changes: dict[str, tuple[bytes | None, bytes]]) -> Path | None:
    if not changes:
        return None
    backup = backups / datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%S%fZ")
    backup.mkdir(parents=True, mode=0o700, exist_ok=False)
    installed: list[str] = []
    try:
        for name, (before, after) in changes.items():
            target = root / name
            now = target.read_bytes() if target.exists() else None
            if target.is_symlink() or now != before:
                raise UnsafeInstall("Arquivo mudou durante a preparacao: " + name)
            if before is not None:
                dest = backup / name
                dest.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
                shutil.copy2(target, dest)
                info = target.stat()
            else:
                info = None
            target.parent.mkdir(parents=True, exist_ok=True)
            fd, temp = tempfile.mkstemp(prefix=".jrc-router-report-", dir=target.parent)
            try:
                with os.fdopen(fd, "wb") as fh:
                    fh.write(after)
                    fh.flush()
                    os.fsync(fh.fileno())
                if info is None:
                    os.chmod(temp, 0o644)
                else:
                    os.chmod(temp, info.st_mode & 0o777)
                    os.chown(temp, info.st_uid, info.st_gid)
                if target.is_symlink() or (
                    (target.read_bytes() if target.exists() else None) != before
                ):
                    raise UnsafeInstall("Arquivo modificado antes da substituicao: " + name)
                os.replace(temp, target)
                installed.append(name)
            finally:
                if os.path.exists(temp):
                    os.unlink(temp)

        (backup / "new-files.txt").write_text(
            "\n".join(name for name in NEW if name in changes),
            encoding="utf-8",
        )
        return backup
    except BaseException:
        for name in reversed(installed):
            target = root / name
            if changes[name][0] is None:
                target.unlink(missing_ok=True)
            else:
                shutil.copy2(backup / name, target)
        raise


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("mode", choices=["plan", "apply"])
    parser.add_argument("--root", type=Path, default=ROOT)
    parser.add_argument("--backups", type=Path, default=BACKUPS)
    args = parser.parse_args()
    changes = plan(args.root)
    for name in changes:
        print("ARQUIVO:", name)
    print("Mantidos: dashboard.php, HTML e filtros dos Relatorios, IXC, TR-069, firmware, cron e flags.")
    if args.mode == "plan":
        print("PLAN_OK" if changes else "JA_INSTALADO")
    else:
        backup = apply(args.root, args.backups, changes)
        print("APLICADO_OK" + ("; backup=" + str(backup) if backup else "; JA_INSTALADO"))


if __name__ == "__main__":
    main()
