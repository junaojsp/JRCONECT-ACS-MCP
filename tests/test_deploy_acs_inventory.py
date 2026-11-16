"""Offline safety tests for customized ACS installation."""
import importlib.util
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

SCRIPT = Path(__file__).resolve().parent.parent / 'scripts' / 'deploy-acs-inventory.py'
spec = importlib.util.spec_from_file_location('deploy_acs_inventory', SCRIPT)
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)

SAMPLE_API = '''<?php
requireLogin();
$conn = getDBConnection();
$credentials = $result->fetch_assoc();
$genieacs = new GenieACS(
);
// custom logic kept
// Get pagination parameters (optional)
// custom deployed code
'''
SOURCE_API = SAMPLE_API.replace(
    '// Get pagination parameters (optional)',
    """// O cache e opt-in: sem snapshot valido, comportamento legado permanece.
if (getConfig('jrc_inventory_cache_enabled') === '1') {
    $cached = JrcDeviceInventoryCache::page($conn, 100, 0);
}

// Get pagination parameters (optional)"""
)


class DeployTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name) / 'gacs'
        self.source = Path(self.tmp.name) / 'source'
        (self.root / 'config').mkdir(parents=True)
        (self.root / 'config/config.php').write_text('<?php')
        (self.root / 'api').mkdir()
        (self.root / mod.API_PATH).write_text(SAMPLE_API)
        for rel in mod.NEW_FILES:
            dest = self.source / rel
            dest.parent.mkdir(parents=True, exist_ok=True)
            dest.write_text('<?php // fixture')
        (self.source / 'api').mkdir(exist_ok=True)
        (self.source / mod.API_PATH).write_text(SOURCE_API)
        self.backups = Path(self.tmp.name) / 'backups'

    def test_install_idempotent_preserves_layout(self):
        changes = mod.prepare(self.source, self.root)
        self.assertEqual(len(changes), 5)
        self.assertFalse((self.root / mod.NEW_FILES[0]).exists())
        backup = mod.apply(self.root, self.backups, changes)
        self.assertTrue(backup.is_dir())
        self.assertEqual(mod.prepare(self.source, self.root), {})
        installed = (self.root / mod.API_PATH).read_text()
        self.assertIn('// custom deployed code', installed)
        self.assertIn('JrcDeviceInventoryCache::page', installed)
        self.assertIn(SAMPLE_API, installed.replace(mod.block_from_source(self.source), ''))

    def test_refuses_unknown_live_api(self):
        (self.root / mod.API_PATH).write_text('<?php echo "unknown";')
        with self.assertRaises(mod.UnsafeInstallation):
            mod.prepare(self.source, self.root)

    def test_refuses_customized_module(self):
        dest = self.root / mod.NEW_FILES[0]
        dest.parent.mkdir(parents=True)
        dest.write_text('<?php // customized')
        with self.assertRaises(mod.UnsafeInstallation):
            mod.prepare(self.source, self.root)

    def test_concurrent_change_refused(self):
        changes = mod.prepare(self.source, self.root)
        (self.root / mod.API_PATH).write_text('<?php // changed concurrently')
        with self.assertRaises(mod.UnsafeInstallation):
            mod.apply(self.root, self.backups, changes)
        self.assertFalse((self.root / mod.NEW_FILES[0]).exists())

    def test_rolls_back_failed_install(self):
        changes = mod.prepare(self.source, self.root)
        real_replace = mod.os.replace
        attempt = 0
        def fail_second(src, dst):
            nonlocal attempt
            attempt += 1
            if attempt == 2:
                raise OSError('simulated disk error')
            return real_replace(src, dst)
        with patch.object(mod.os, 'replace', side_effect=fail_second):
            with self.assertRaises(OSError):
                mod.apply(self.root, self.backups, changes)
        self.assertFalse((self.root / mod.NEW_FILES[0]).exists())
        self.assertEqual((self.root / mod.API_PATH).read_text(), SAMPLE_API)


if __name__ == '__main__':
    unittest.main()
