#!/usr/bin/env python3
"""Safely patch the *deployed* ACS MCP server without replacing its local integrations.

Default is read-only validation. --apply backs up and atomically updates server.js.
No OAuth bypass: PABX read access requires explicitly allowlisted Keycloak subject.
"""
import argparse
import datetime
import os
from pathlib import Path
import shutil
import subprocess
import tempfile


def replace_once(data, old, new, label):
    count = data.count(old)
    if count != 1:
        raise ValueError("%s: expected exactly one anchor, got %d" % (label, count))
    return data.replace(old, new, 1)


def patch(source):
    if "PABX_READ_ALLOWED_SUB" in source:
        raise ValueError("readonly PABX patch already appears to be installed")
    for required in (
        "registerPabxTools(mcp);",
        "registerPabxQueueReport(mcp);",
        "registerPabxAdminTools(mcp);",
        "const pabxCentralMcpHandlers = [",
        "if (pabxAdminAuthorized(payload)) {",
        "import { registerPabxQueueReport }",
    ):
        if required not in source:
            raise ValueError("production prerequisite missing: " + required)

    source = replace_once(
        source,
        "const makeMcpHandler = (includeShield, includeFinance = false, includeOpa = false, includeIxc = false, includePabx = false) =>",
        "const makeMcpHandler = (includeShield, includeFinance = false, includeOpa = false, includeIxc = false, includePabx = false, includePabxRead = false) =>",
        "handler signature",
    )
    # Do not touch the existing administrative registration or its role check.
    anchor = (
        "  if (includePabx) {\n"
        "    registerPabxTools(mcp);\n"
        "    registerPabxQueueReport(mcp);\n"
        "    registerPabxAdminTools(mcp);\n"
        "  }"
    )
    replacement = anchor + (
        "\n  if (includePabxRead) {\n"
        "    registerPabxTools(mcp);\n"
        "    registerPabxQueueReport(mcp);\n"
        "  }"
    )
    source = replace_once(source, anchor, replacement, "read-only tools registration")

    auth = """// Read-only PABX access: explicit OAuth subject allowlist, never the admin role.
function pabxReadAuthorized(payload) {
  const sub = process.env.PABX_READ_ALLOWED_SUB || '';
  return process.env.PABX_API_ENABLED === 'true' &&
    /^[a-f0-9]{8}(-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i.test(sub) &&
    payload?.azp === 'chatgpt-mcp' && payload?.sub === sub;
}

"""
    source = replace_once(source, "const makeMcpHandler =", auth + "const makeMcpHandler =", "auth function insertion")

    variants = []
    for bits in range(8):
        fin = "true" if bits & 1 else "false"
        opa = "true" if bits & 2 else "false"
        ixc = "true" if bits & 4 else "false"
        variants.append("  toNodeHandler(makeMcpHandler(true, %s, %s, %s, false, true))," % (fin, opa, ixc))
    handlers = "// Dedicated PABX read-only catalogs: preserve all other granted integration tools.\n"
    handlers += "const pabxReadCentralMcpHandlers = [\n" + "\n".join(variants) + "\n];\n\n"
    handlers += "const pabxReadFallbackHandler = toNodeHandler(makeMcpHandler(false, false, false, false, false, true));\n\n"
    source = replace_once(source, "const pabxCentralMcpHandlers = [", handlers + "const pabxCentralMcpHandlers = [", "read-only catalog")

    # Insert after the unchanged PABX-admin branch, before IXC routing.
    route_anchor = "    if (ixcWebserviceAuthorized(payload)) {"
    route = (
        "    if (pabxReadAuthorized(payload)) {\n"
        "      const permissions =\n"
        "        (financeMcpAuthorized(payload) ? 1 : 0) |\n"
        "        (opaApiAuthorized(payload) ? 2 : 0) |\n"
        "        (ixcWebserviceAuthorized(payload) ? 4 : 0);\n"
        "      return pabxReadCentralMcpHandlers[permissions](req, res);\n"
        "    }\n"
    )
    source = replace_once(source, route_anchor, route + route_anchor, "OAuth read catalog dispatch")
    # For an OAuth subject outside the Shield allowlist, expose only Wiki + PABX
    # queries: no Finance, Opa, IXC, Shield or admin privilege escalation.
    fallback = "    return mcpHandler(req, res);\n  } catch {"
    source = replace_once(
        source, fallback,
        "    if (pabxReadAuthorized(payload)) return pabxReadFallbackHandler(req, res);\n" + fallback,
        "OAuth basic catalog dispatch",
    )
    return source


def main():
    cli = argparse.ArgumentParser()
    cli.add_argument("--server", default="/opt/jrconect-mcp/server.js")
    cli.add_argument("--apply", action="store_true", help="modify only after successful checks")
    args = cli.parse_args()
    target = Path(args.server)
    base = target.parent
    for name in ("pabx.js", "integrations/pabx-queue-report.js", "pabx-admin.js"):
        if not (base / name).is_file():
            cli.error("missing production prerequisite: " + name)

    original = target.read_text(encoding="utf-8")
    try:
        updated = patch(original)
    except ValueError as e:
        cli.error(str(e))

    fd, temp = tempfile.mkstemp(prefix=".pabx-readonly-", suffix=".js", dir=str(base))
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as f:
            f.write(updated)
        subprocess.run(["node", "--check", temp], check=True)
        print("PABX_READ_ONLY_PATCH_CHECK_OK")
        if not args.apply:
            print("DRY_RUN_ONLY: no production files changed")
            return
        meta = target.stat()
        os.chmod(temp, meta.st_mode & 0o777)
        os.chown(temp, meta.st_uid, meta.st_gid)
        backup = str(target) + ".bak-pabx-readonly-" + datetime.datetime.now().strftime("%Y%m%d-%H%M%S")
        shutil.copy2(str(target), backup)
        os.replace(temp, str(target))
        print("PATCH_APPLIED", "BACKUP=" + backup, "RESTART_NOT_PERFORMED")
    finally:
        if os.path.exists(temp):
            os.unlink(temp)


if __name__ == "__main__":
    main()
