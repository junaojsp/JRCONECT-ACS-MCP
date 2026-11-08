#!/usr/bin/env python3
"""Patch deployed ACS server.js safely: add queue writes ONLY to PABX admin catalog.

Dry-run by default. Refuses partial matches and never overwrites repo's older server.js.
"""
from pathlib import Path
import argparse
from datetime import datetime
import os
import re
import shutil
import subprocess
import tempfile

IMPORT = "import { registerPabxQueueWriteTools } from './integrations/pabx-queue-write.js';"


def patch(source):
    if "registerPabxQueueWriteTools" in source:
        raise ValueError("Queue write tools already registered")
    if "function pabxReadAuthorized(payload)" not in source:
        raise ValueError("Read-only OAuth routing must remain installed")
    if "if (pabxAdminAuthorized(payload)) {" not in source:
        raise ValueError("PABX admin OAuth guard missing")
    if "registerPabxAdminTools(mcp);" not in source:
        raise ValueError("PABX admin tool registration missing")
    match = list(re.finditer(
        r"(?m)^([ \t]*)if \(includePabx\) \{\r?\n"
        r"(?P<body>(?:[^\n]*\n){1,8}?)"
        r"([ \t]*)\}",
        source,
    ))
    if len(match) != 1:
        raise ValueError("Expected exactly one PABX admin catalog")
    m = match[0]
    block = m.group(0)
    if block.count("registerPabxAdminTools(mcp);") != 1 or \
       block.count("registerPabxQueueReport(mcp);") != 1 or \
       "registerPabxQueueWriteTools(mcp);" in block:
        raise ValueError("Unexpected PABX admin catalog structure")
    line = re.search(r"(?m)^([ \t]*)registerPabxAdminTools\(mcp\);$", block)
    if not line:
        raise ValueError("Unexpected PABX admin tool registration line")
    addition = line.group(0) + "\n" + line.group(1) + "registerPabxQueueWriteTools(mcp);"
    adjusted = block.replace(line.group(0), addition, 1)
    result = source[:m.start()] + adjusted + source[m.end():]
    # The guarded read-only catalog must remain read-only.
    read_block = re.search(r"if \(includePabxRead\) \{([^}]*)\}", result, re.S)
    if not read_block or "registerPabxQueueWriteTools" in read_block.group(1) or \
       "registerPabxAdminTools" in read_block.group(1):
        raise ValueError("Read-only catalog must not contain admin tools")
    if "import { registerPabxQueueReport }" not in result:
        raise ValueError("Production PABX import missing")
    result = IMPORT + "\n" + result
    return result


def main():
    a = argparse.ArgumentParser()
    a.add_argument("--server", default="/opt/jrconect-mcp/server.js")
    a.add_argument("--apply", action="store_true")
    opt = a.parse_args()
    server = Path(opt.server)
    if not (server.parent / "integrations/pabx-queue-write.js").exists():
        a.error("Copy integration module first")
    try:
        updated = patch(server.read_text(encoding="utf-8"))
    except (OSError, ValueError) as exc:
        a.error(str(exc))
    fd, tmp = tempfile.mkstemp(dir=str(server.parent), prefix=".pabx-write-", suffix=".js")
    try:
        with os.fdopen(fd, "w", encoding="utf8") as f:
            f.write(updated)
        subprocess.run(["node", "--check", tmp], check=True)
        print("PABX_QUEUE_WRITE_PATCH_VALID")
        if not opt.apply:
            print("DRY_RUN_NO_CHANGE")
            return
        meta = server.stat()
        os.chmod(tmp, meta.st_mode & 0o777)
        os.chown(tmp, meta.st_uid, meta.st_gid)
        backup = str(server) + ".bak-queue-write-" + datetime.now().strftime("%Y%m%d-%H%M%S")
        shutil.copy2(str(server), backup)
        os.replace(tmp, str(server))
        print("PATCH_APPLIED", "BACKUP=" + backup, "NO_RESTART")
    finally:
        if os.path.exists(tmp):
            os.unlink(tmp)


if __name__ == "__main__":
    main()
