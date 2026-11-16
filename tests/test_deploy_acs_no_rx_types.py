#!/usr/bin/env python3
"""Check no-RX tri-state rollout on a fake ACS, not the production server."""
import importlib.util
from pathlib import Path
import tempfile

file = Path(__file__).resolve().parents[1] / 'scripts/deploy-acs-no-rx-types.py'
spec = importlib.util.spec_from_file_location('acs_no_rx_deploy', file)
assert spec and spec.loader
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)

with tempfile.TemporaryDirectory() as base, tempfile.TemporaryDirectory() as backups:
    root = Path(base)
    (root / 'config').mkdir()
    (root / 'config/config.php').write_text('<?php // mock')
    dashboard = root / 'dashboard.php'
    before_dashboard = b'<script src="/assets/js/jrc-router-signal.js"></script>'
    dashboard.write_bytes(before_dashboard)

    expected = {}
    for name in mod.EXPECTED:
        target = root / name
        target.parent.mkdir(parents=True, exist_ok=True)
        original = ('/* first version of ' + name + ' */\n').encode()
        target.write_bytes(original)
        expected[name] = mod.git_blob(original)

    steps = mod.plan(root, expected=expected)
    assert set(steps) == set(mod.EXPECTED)
    assert dashboard.read_bytes() == before_dashboard
    assert (root / 'api/jrc-router-signal-summary.php').read_bytes() == steps[
        'api/jrc-router-signal-summary.php'][0]
    backup = mod.apply(root, Path(backups), steps)
    assert backup
    for name, (old, new) in steps.items():
        assert (backup / name).read_bytes() == old
        assert (root / name).read_bytes() == new
    assert dashboard.read_bytes() == before_dashboard
    assert mod.plan(root) == {}, 'Running again must do nothing'

    # A customized/deviating file must fail the plan, never be overwritten.
    custom_file = root / 'assets/js/jrc-router-signal.js'
    custom_file.write_bytes(b'/* changed by administrator */')
    try:
        mod.plan(root)
        raise AssertionError('must refuse custom JavaScript')
    except mod.UnsafeInstall:
        pass

with tempfile.TemporaryDirectory() as base, tempfile.TemporaryDirectory() as backups:
    root = Path(base)
    (root / 'config').mkdir()
    (root / 'config/config.php').write_text('<?php // mock')
    (root / 'dashboard.php').write_bytes(
        b'<script src="/assets/js/jrc-router-signal.js"></script>'
    )
    expected = {}
    for name in mod.EXPECTED:
        target = root / name
        target.parent.mkdir(parents=True, exist_ok=True)
        previous = (name + ' before').encode()
        target.write_bytes(previous)
        expected[name] = mod.git_blob(previous)
    steps = mod.plan(root, expected=expected)
    first = root / next(iter(steps))
    first.write_bytes(b'/* modified by another process */')
    try:
        mod.apply(root, Path(backups), steps)
        raise AssertionError('must detect concurrent modification')
    except mod.UnsafeInstall:
        pass
    assert first.read_bytes() == b'/* modified by another process */'

print('JRC_NO_RX_TYPES_DEPLOY_TESTS_OK')
