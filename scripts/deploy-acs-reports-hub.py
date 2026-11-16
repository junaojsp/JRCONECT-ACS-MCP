#!/usr/bin/env python3
"""Opt-in ACS reports separation: safe link-only navigation overlay.

Install a new /reports-hub.php, change the single customized sidebar
"Relatórios" link to that hub, and the router report's "Voltar aos
relatórios" link to the hub.

DO NOT alter view-logs.php, its IXC/TR-069 queries or fetches, dashboard,
firmware, device actions, frontend report filters, credentials, cron or flags.
"""
from __future__ import annotations

import argparse
from datetime import datetime, timezone
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile

SOURCE = Path(__file__).resolve().parent.parent
DEFAULT_ROOT = Path("/var/www/gacs")
DEFAULT_BACKUPS = Path("/var/backups/jrc-acs-reports-hub")
NAV = "views/layouts/header.php"
ROUTER = "router-report.php"
HUB = "reports-hub.php"
FILES = (HUB, NAV, ROUTER)

ANCHOR_RE = re.compile(r"(?is)<a\b[^>]{0,900}>.{0,750}?</a>")
LABEL_RE = re.compile(r"(?i)\brelat[oó]rios\b")
HREF_RE = re.compile(
    r"""(?i)\bhref(?P<equals>\s*=\s*)(?P<quote>['"])(?P<url>/?(?:view-logs|reports-hub)\.php)(?P=quote)"""
)
OLD_BACK = 'href="/view-logs.php">Voltar aos relatórios'
NEW_BACK = 'href="/reports-hub.php">Voltar aos relatórios'


class UnsafeInstall(RuntimeError):
    pass


def patch_navigation(before: bytes) -> bytes:
    try:
        text = before.decode("utf-8")
    except UnicodeDecodeError as exc:
        raise UnsafeInstall("Menu não está em UTF-8") from exc

    matches = []
    for anchor in ANCHOR_RE.finditer(text):
        # Match the label seen by the user, not PHP variable names, comments
        # or unrelated direct links to optical filters.
        visible = re.sub(r"<[^>]*>", " ", anchor.group(0))
        if not LABEL_RE.search(visible):
            continue
        href = HREF_RE.search(anchor.group(0))
        if href is not None:
            matches.append((anchor, href))

    if len(matches) != 1:
        raise UnsafeInstall(
            "Não foi encontrado um único link Relatórios apontando para view-logs.php"
        )
    anchor, href = matches[0]
    if href.group("url").lstrip("/") == "reports-hub.php":
        return before
    if href.group("url").lstrip("/") != "view-logs.php":
        raise UnsafeInstall("Destino de Relatórios inesperado")

    offset = anchor.start() + href.start("url")
    end = anchor.start() + href.end("url")
    replacement = "/reports-hub.php"
    result = text[:offset] + replacement + text[end:]
    if result.count(replacement) - text.count(replacement) != 1:
        raise UnsafeInstall("O link de navegação não mudou uma única vez")
    return result.encode("utf-8")


def patch_router_back(before: bytes) -> bytes:
    try:
        text = before.decode("utf-8")
    except UnicodeDecodeError as exc:
        raise UnsafeInstall("Relatório de roteadores não está em UTF-8") from exc

    if NEW_BACK in text and OLD_BACK not in text:
        return before
    if text.count(OLD_BACK) != 1 or NEW_BACK in text:
        raise UnsafeInstall("Botão Voltar aos relatórios diferente do homologado")
    return text.replace(OLD_BACK, NEW_BACK).encode("utf-8")


def syntax_check(path: str, content: bytes) -> None:
    if not path.endswith(".php"):
        return
    with tempfile.NamedTemporaryFile(suffix=".php") as check:
        check.write(content)
        check.flush()
        result = subprocess.run(["php", "-l", check.name],
                                capture_output=True, text=True, check=False)
        if result.returncode != 0:
            raise UnsafeInstall("Sintaxe PHP inválida no arquivo " + path)


def plan(root: Path) -> dict[str, tuple[bytes | None, bytes]]:
    if not (root / "config/config.php").is_file():
        raise UnsafeInstall("ACS não localizado")
    if not (root / "view-logs.php").is_file():
        raise UnsafeInstall("Consulta de sinal existente não localizada")

    changes: dict[str, tuple[bytes | None, bytes]] = {}
    for path in FILES:
        target = root / path
        if target.is_symlink() or (target.exists() and not target.is_file()):
            raise UnsafeInstall("Arquivo não seguro: " + path)
        before = target.read_bytes() if target.exists() else None

        if path == HUB:
            source = SOURCE / HUB
            if not source.is_file():
                raise UnsafeInstall("Nova Central de Relatórios indisponível")
            after = source.read_bytes()
            if before is not None and before != after:
                raise UnsafeInstall("Relatórios existentes personalizados: não sobrescrever")
        elif path == NAV:
            if before is None:
                raise UnsafeInstall("Menu personalizado não encontrado")
            after = patch_navigation(before)
        else:
            if before is None:
                raise UnsafeInstall("Relatório de roteadores não encontrado")
            after = patch_router_back(before)

        syntax_check(path, after)
        if before != after:
            changes[path] = (before, after)
    return changes


def apply(root: Path, backup_root: Path,
          changes: dict[str, tuple[bytes | None, bytes]]) -> Path | None:
    if not changes:
        return None

    backup = backup_root / datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%S%fZ")
    backup.mkdir(parents=True, mode=0o700, exist_ok=False)
    installed: list[str] = []

    try:
        for path, (before, after) in changes.items():
            target = root / path
            if target.is_symlink() or (
                (target.read_bytes() if target.exists() else None) != before
            ):
                raise UnsafeInstall("O arquivo foi alterado durante a instalação: " + path)
            if before is not None:
                saved = backup / path
                saved.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
                shutil.copy2(target, saved)
                meta = target.stat()
            else:
                meta = None

            target.parent.mkdir(parents=True, exist_ok=True)
            fd, tmp = tempfile.mkstemp(prefix=".jrc-reports-", dir=target.parent)
            try:
                with os.fdopen(fd, "wb") as output:
                    output.write(after)
                    output.flush()
                    os.fsync(output.fileno())
                if meta is None:
                    os.chmod(tmp, 0o644)
                else:
                    os.chmod(tmp, meta.st_mode & 0o777)
                    os.chown(tmp, meta.st_uid, meta.st_gid)
                if target.is_symlink() or (
                    (target.read_bytes() if target.exists() else None) != before
                ):
                    raise UnsafeInstall("Arquivo modificado após backup: " + path)
                os.replace(tmp, target)
                installed.append(path)
            finally:
                if os.path.exists(tmp):
                    os.unlink(tmp)

        (backup / "created-files.txt").write_text(
            "\n".join(path for path in installed if changes[path][0] is None),
            encoding="utf-8",
        )
        return backup
    except BaseException:
        for path in reversed(installed):
            target = root / path
            if changes[path][0] is None:
                target.unlink(missing_ok=True)
            else:
                shutil.copy2(backup / path, target)
        raise


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("mode", choices=["plan", "apply"])
    parser.add_argument("--root", type=Path, default=DEFAULT_ROOT)
    parser.add_argument("--backups", type=Path, default=DEFAULT_BACKUPS)
    args = parser.parse_args()

    changes = plan(args.root)
    for path in changes:
        print("ARQUIVO:", path)
    print("Preservados: view-logs.php, filtros IXC/TR-069, dashboard, firmware e comandos ONU.")
    if args.mode == "plan":
        print("PLAN_OK" if changes else "JA_INSTALADO")
    else:
        destination = apply(args.root, args.backups, changes)
        print("APLICADO_OK" + (
            "; backup=" + str(destination) if destination else "; JA_INSTALADO"
        ))


if __name__ == "__main__":
    main()
