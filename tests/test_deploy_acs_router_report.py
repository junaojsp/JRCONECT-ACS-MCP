#!/usr/bin/env python3
"""Validate the isolated report install against a fixture, never the host ACS."""
import importlib.util
from pathlib import Path
import tempfile

module_file = Path(__file__).resolve().parents[1] / 'scripts/deploy-acs-router-report.py'
spec = importlib.util.spec_from_file_location('jrc_router_report_deploy', module_file)
assert spec is not None and spec.loader is not None
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)

with tempfile.TemporaryDirectory() as root_dir, tempfile.TemporaryDirectory() as backup_dir:
    root = Path(root_dir)
    (root / 'config').mkdir()
    (root / 'config/config.php').write_text('<?php // mock')
    dashboard = root / 'dashboard.php'
    original_dashboard = b"<?php // dashboard ?>\n<script src='/assets/js/jrc-router-signal.js'></script>"
    dashboard.write_bytes(original_dashboard)
    js = root / mod.JS
    js.parent.mkdir(parents=True)
    previous = b'/* original router labels v2 */'
    js.write_bytes(previous)

    changes = mod.plan(root, old_js_sha=mod.git_blob_sha(previous))
    assert sorted(changes) == sorted(mod.FILES)
    assert dashboard.read_bytes() == original_dashboard
    assert js.read_bytes() == previous

    backup = mod.apply(root, Path(backup_dir), changes)
    assert backup
    assert (backup / mod.JS).read_bytes() == previous
    assert (root / 'router-report.php').is_file()
    assert (root / 'lib/JrcRouterReportRows.php').is_file()
    assert dashboard.read_bytes() == original_dashboard
    assert mod.plan(root) == {}, 'Second run should not change files'

    # Protect a modified report page.
    (root / 'router-report.php').write_text('<?php // administrator customization')
    try:
        mod.plan(root)
        raise AssertionError('Must refuse replacing customized report')
    except mod.UnsafeInstall:
        pass

with tempfile.TemporaryDirectory() as root_dir:
    root = Path(root_dir)
    (root / 'config').mkdir()
    (root / 'config/config.php').write_text('<?php // mock')
    (root / 'dashboard.php').write_bytes(b'/assets/js/jrc-router-signal.js')
    js = root / mod.JS
    js.parent.mkdir(parents=True)
    js.write_bytes(b'/* unknown custom JS */')
    try:
        mod.plan(root)
        raise AssertionError('Must refuse unknown JS')
    except mod.UnsafeInstall:
        pass

print('JRC_ROUTER_REPORT_DEPLOY_TESTS_OK')
