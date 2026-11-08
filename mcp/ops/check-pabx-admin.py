#!/usr/bin/env python3
"""Read-only safety checks before enabling the EXISTING PABX admin catalog.

Does not create credentials, grant Keycloak roles, copy files or change the ACS.
"""
import argparse
import os
from pathlib import Path
import re
import stat
import sys

UUID = re.compile(r"^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$", re.I)
TOKEN = re.compile(r"^[a-f0-9]{64}$", re.I)
ROLE = "jrconect-pabx-admin"


def validate_oauth_subject(value):
    return bool(UUID.fullmatch(value or ""))


def validate_secret(path):
    try:
        p = Path(path)
        info = p.stat()
        if not stat.S_ISREG(info.st_mode):
            return False, "not_a_regular_file"
        if info.st_mode & (stat.S_IROTH | stat.S_IWOTH | stat.S_IXOTH):
            return False, "world_access_not_allowed"
        if info.st_mode & (stat.S_IWGRP | stat.S_IXGRP):
            return False, "group_write_or_execute_not_allowed"
        if not TOKEN.fullmatch(p.read_text(encoding="ascii").strip()):
            return False, "invalid_token_format"
        return True, "ok"
    except (OSError, UnicodeError):
        return False, "missing_or_unreadable"


def verify_admin_module(source):
    # Verify only the security boundaries visible in deployed server-side code.
    required = [
        "export function pabxAdminAuthorized(payload)",
        "PABX_ADMIN_MCP_ENABLED",
        "PABX_ADMIN_ALLOWED_SUB",
        "PABX_ADMIN_ROLE",
        "PABX_ADMIN_AUTH_MODE",
        "payload?.azp === 'chatgpt-mcp'",
        "roles.includes(role)",
    ]
    return [name for name in required if name not in source]


def verify_server(source):
    required = [
        "registerPabxAdminTools(mcp);",
        "if (pabxAdminAuthorized(payload)) {",
        "registerPabxTools(mcp);",
        "registerPabxQueueReport(mcp);",
        "if (pabxReadAuthorized(payload)) {",
    ]
    return [name for name in required if name not in source]


def main():
    cli = argparse.ArgumentParser(description="Safe preflight; never changes production")
    cli.add_argument("--root", default="/opt/jrconect-mcp")
    cli.add_argument("--token-file", default=os.environ.get("PABX_ADMIN_TOKEN_PATH", ""))
    cli.add_argument("--allowed-sub", default=os.environ.get("PABX_ADMIN_ALLOWED_SUB", ""))
    args = cli.parse_args()
    root = Path(args.root)
    errors = []
    if not validate_oauth_subject(args.allowed_sub):
        errors.append("A Keycloak subject UUID allowlist is required")
    if not args.token_file:
        errors.append("PABX_ADMIN_TOKEN_PATH is not configured")
    else:
        ok, reason = validate_secret(args.token_file)
        if not ok:
            errors.append("Admin token file: " + reason)
    for name, inspector in (
        ("pabx-admin.js", verify_admin_module),
        ("server.js", verify_server),
    ):
        try:
            data = (root / name).read_text(encoding="utf-8")
            absent = inspector(data)
            if absent:
                errors.append(name + ": missing required guards: " + ", ".join(absent))
        except OSError:
            errors.append(name + ": unavailable")
    if errors:
        print("PABX_ADMIN_PREFLIGHT=BLOCKED")
        for reason in errors:
            print(" - " + reason)
        return 1
    print("PABX_ADMIN_PREFLIGHT=OK")
    print("Admin tool catalog exists behind subject + role authorization.")
    print("Keycloak role assignment and API operations must be validated separately.")
    print("NO_PRODUCTION_CHANGES")
    return 0


if __name__ == "__main__":
    sys.exit(main())
