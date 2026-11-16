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


# Production-shaped dashboard.php delegates </body> to a shared footer.
# The installer must add one include before the footer PHP statement,
# without modifying the footer, existing JS, or the dashboard structure.
with tempfile.TemporaryDirectory() as root, tempfile.TemporaryDirectory() as backups:
    root = Path(root)
    (root / 'config').mkdir()
    (root / 'config/config.php').write_text('<?php // fixture\n')
    original = (
        "<?php\nrequire_once __DIR__ . '/config/config.php';\n"
        "include __DIR__ . '/views/layouts/header.php';\n?>\n"
        '<div>Dashboard - Sinal da frota</div>\n'
        '<script>console.log("existing");</script>\n'
        "<?php\n\ninclude __DIR__ . '/views/layouts/footer.php';\n\n?>\n"
    ).encode()
    dashboard = root / 'dashboard.php'
    dashboard.write_bytes(original)
    changes = mod.plan(root)
    assert set(changes) == set(mod.NEW_FILES) | {'dashboard.php'}, changes
    assert dashboard.read_bytes() == original, 'plan modified footer-based dashboard'
    backup = mod.apply(root, Path(backups), changes)
    assert backup is not None
    patched = dashboard.read_text()
    assert patched.count(mod.INCLUDE) == 1
    assert patched.index(mod.INCLUDE) < patched.index("include __DIR__ . '/views/layouts/footer.php';")
    assert patched.replace(mod.INCLUDE + '\n', '').encode() == original
    assert (backup / 'dashboard.php').read_bytes() == original
    assert not mod.plan(root), 'footer layout not idempotent'

# Unknown layouts must still be refused; do not insert blindly.
with tempfile.TemporaryDirectory() as root:
    root = Path(root)
    (root / 'config').mkdir()
    (root / 'config/config.php').write_text('<?php // fixture\n')
    (root / 'dashboard.php').write_text('<?php // Dashboard without footer ?>')
    try:
        mod.plan(root)
        raise AssertionError('Unknown layout must be rejected')
    except mod.UnsafeInstall:
        pass

print('JRC_ROUTER_DEPLOY_TESTS_OK')
