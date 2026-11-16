#!/usr/bin/env python3
"""Check safe overlay on a fixture, without touching a real ACS host."""
import importlib.util
from pathlib import Path
import tempfile

script = Path(__file__).resolve().parents[1] / 'scripts/deploy-acs-router-signal.py'
spec = importlib.util.spec_from_file_location('jrc_router_deploy', script)
assert spec and spec.loader
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)

with tempfile.TemporaryDirectory() as root, tempfile.TemporaryDirectory() as backups:
    root = Path(root)
    (root / 'config').mkdir()
    (root / 'config/config.php').write_text('<?php // fixture\n')
    original = (
        '<?php // customized dashboard ?>\n'
        '<!doctype html><html><body>\n'
        '<h1>Dashboard</h1>\n'
        '<section>Sinal da frota - Sem leitura - Sinal RX</section>\n'
        '</body></html>\n'
    ).encode()
    (root / 'dashboard.php').write_bytes(original)
    changes = mod.plan(root)
    assert set(changes) == set(mod.NEW_FILES) | {'dashboard.php'}, changes
    assert (root / 'dashboard.php').read_bytes() == original, 'plan mutated dashboard'
    applied = mod.apply(root, Path(backups), changes)
    assert applied
    assert mod.MARK in (root / 'dashboard.php').read_text()
    assert (applied / 'dashboard.php').read_bytes() == original
    assert (root / mod.NEW_FILES[0]).exists()
    assert not mod.plan(root), 'apply not idempotent'
    # A custom script tag must not be overwritten.
    dashboard = (root / 'dashboard.php')
    dashboard.write_text(dashboard.read_text().replace(mod.INCLUDE,
        '<script src="/assets/js/jrc-router-signal.js?different=true"></script>'))
    try:
        mod.plan(root)
        raise AssertionError('Must fail on customized script')
    except mod.UnsafeInstall:
        pass

print('JRC_ROUTER_DEPLOY_TESTS_OK')
