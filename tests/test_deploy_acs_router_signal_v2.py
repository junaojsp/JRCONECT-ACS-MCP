#!/usr/bin/env python3
"""Validate v2 rollout without changing an installed ACS."""
import importlib.util
from pathlib import Path
import tempfile

module_file = Path(__file__).resolve().parents[1] / 'scripts/deploy-acs-router-signal-v2.py'
spec = importlib.util.spec_from_file_location('router_v2_deploy', module_file)
assert spec and spec.loader
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)

with tempfile.TemporaryDirectory() as tempdir, tempfile.TemporaryDirectory() as backups:
    root = Path(tempdir)
    (root / 'config').mkdir()
    (root / 'config/config.php').write_text('<?php // mocked config')
    file = root / mod.RELATIVE
    file.parent.mkdir(parents=True)
    previous = b'/* fixture first-release router JavaScript */\n'
    file.write_bytes(previous)
    original_sha = mod.git_blob_sha(previous)

    try:
        mod.plan(root)
        raise AssertionError('Should refuse unknown custom script')
    except mod.UnsafeInstall:
        pass

    changes = mod.plan(root, old_sha=original_sha)
    assert changes and changes[0] == previous
    assert file.read_bytes() == previous, 'Plan must be read-only'

    backup = mod.apply(root, Path(backups), changes)
    assert backup and (backup / mod.RELATIVE).read_bytes() == previous
    assert file.read_bytes() == mod.SOURCE.read_bytes()
    assert mod.plan(root) is None, 'Second run idempotent'
    assert mod.apply(root, Path(backups), None) is None

    file.write_bytes(b'/* customized by operator */')
    try:
        mod.plan(root, old_sha=original_sha)
        raise AssertionError('Must refuse unexpected third-party customization')
    except mod.UnsafeInstall:
        pass

with tempfile.TemporaryDirectory() as tempdir, tempfile.TemporaryDirectory() as backups:
    root = Path(tempdir)
    (root / 'config').mkdir()
    (root / 'config/config.php').write_text('<?php // mocked config')
    file = root / mod.RELATIVE
    file.parent.mkdir(parents=True)
    previous = b'/* first-version fixture */'
    file.write_bytes(previous)
    changes = mod.plan(root, old_sha=mod.git_blob_sha(previous))
    assert changes
    file.write_bytes(b'/* concurrent modification */')
    try:
        mod.apply(root, Path(backups), changes)
        raise AssertionError('Must refuse concurrent modification')
    except mod.UnsafeInstall:
        pass
    assert file.read_bytes() == b'/* concurrent modification */'

print('JRC_ROUTER_V2_DEPLOY_TESTS_OK')
