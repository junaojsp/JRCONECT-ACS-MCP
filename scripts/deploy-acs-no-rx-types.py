#!/usr/bin/env python3
"""Safe plan/apply update of "Sem dado" classifications in customized ACS.

Only updates the installed isolated router/RX script and its existing
read-only classifier/summary. No dashboard.php, signal source endpoints,
GenieACS/IXC/TR-069 API, view-logs, firmware, cron, flags or actions.
"""
from __future__ import annotations

import argparse
from datetime import datetime, timezone
import hashlib
import os
from pathlib import Path
import shutil
import subprocess
import tempfile

ROOT = Path("/var/www/gacs")
SOURCE = Path(__file__).resolve().parent.parent
BACKUPS = Path("/var/backups/jrc-acs-no-rx-types")

# Exact Git blobs for files previously deployed by PRs #189, #192, #193.
EXPECTED = {
    "lib/JrcEquipmentClassification.php": "362bee6e180db0a0a05d72ebcffd942ae912c77f",
    "api/jrc-router-signal-summary.php": "24819d0c9be1251d487d71f63dc21a6db370700b",
    "assets/js/jrc-router-signal.js": "c975dbc99b879e4622f0b7af65b83f02b806584e",
}


class UnsafeInstall(RuntimeError):
    pass


def git_blob(data: bytes) -> str:
    return hashlib.sha1(b"blob " + str(len(data)).encode() + b"\0" + data).hexdigest()


def syntax_check(name: str, content: bytes) -> None:
    if not name.endswith(".php"):
        return
    with tempfile.NamedTemporaryFile(suffix=".php") as temp:
        temp.write(content)
        temp.flush()
        result = subprocess.run(
            ["php", "-l", temp.name],
            capture_output=True, text=True, check=False,
        )
    if result.returncode:
        raise UnsafeInstall("Erro na sintaxe PHP: " + name)


def plan(root: Path, expected: dict[str, str] = EXPECTED) -> dict[str, tuple[bytes, bytes]]:
    if not (root / "config/config.php").is_file():
        raise UnsafeInstall("ACS nao localizado")
    dashboard = root / "dashboard.php"
    if (dashboard.is_symlink() or not dashboard.is_file() or
            b"/assets/js/jrc-router-signal.js" not in dashboard.read_bytes()):
        raise UnsafeInstall("Indicador Roteador da versao anterior nao encontrado")

    changes = {}
    for name, expected_sha in expected.items():
        target = root / name
        source = SOURCE / name
        if target.is_symlink() or not target.is_file() or not source.is_file():
            raise UnsafeInstall("Arquivo de overlay nao encontrado ou inseguro: " + name)
        old = target.read_bytes()
        new = source.read_bytes()
        if old == new:
            continue
        if git_blob(old) != expected_sha:
            raise UnsafeInstall("Arquivo customizado; nao sobrepor: " + name)
        syntax_check(name, new)
        changes[name] = (old, new)
    return changes


def apply(root: Path, backups: Path,
          changes: dict[str, tuple[bytes, bytes]]) -> Path | None:
    if not changes:
        return None
    target_backup = backups / datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%S%fZ")
    target_backup.mkdir(parents=True, mode=0o700, exist_ok=False)
    updated = []
    try:
        for name, (before, after) in changes.items():
            target = root / name
            if target.is_symlink() or not target.is_file() or target.read_bytes() != before:
                raise UnsafeInstall("Arquivo mudou durante instalacao: " + name)
            dest = target_backup / name
            dest.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
            shutil.copy2(target, dest)

            st = target.stat()
            fd, temp_path = tempfile.mkstemp(prefix=".jrc-no-rx-", dir=target.parent)
            try:
                with os.fdopen(fd, "wb") as output:
                    output.write(after)
                    output.flush()
                    os.fsync(output.fileno())
                os.chmod(temp_path, st.st_mode & 0o777)
                os.chown(temp_path, st.st_uid, st.st_gid)
                if target.is_symlink() or target.read_bytes() != before:
                    raise UnsafeInstall("Mudanca concorrente detectada: " + name)
                os.replace(temp_path, target)
                updated.append(name)
            finally:
                if os.path.exists(temp_path):
                    os.unlink(temp_path)
        return target_backup
    except BaseException:
        for name in reversed(updated):
            shutil.copy2(target_backup / name, root / name)
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
    print("Preservados: dashboard, relatorios, IXC, TR-069, firmware, cron, MCP e comandos.")
    if args.mode == "plan":
        print("PLAN_OK" if changes else "JA_INSTALADO")
    else:
        backup = apply(args.root, args.backups, changes)
        print("APLICADO_OK" + ("; backup=" + str(backup) if backup else "; JA_INSTALADO"))


if __name__ == "__main__":
    main()
