import importlib.util
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

SCRIPT = Path(__file__).resolve().parent.parent / 'scripts' / 'deploy-acs-overview-fast.py'
spec = importlib.util.spec_from_file_location('deploy_acs_overview_fast', SCRIPT)
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)

BLOCK = """// JRC OVERVIEW SNAPSHOT FAST PATH (opt-in, read-only).
if (getConfig('jrc_dashboard_cache_enabled') === '1') {
    $cachedOverview = JrcDashboardSnapshot::response(getDBConnection(), 'stats');
}
// END JRC OVERVIEW SNAPSHOT FAST PATH"""

API = """<?php
require_once 'config.php';
requireLogin();
header('Content-Type: application/json');
// Other production customizations are preserved.
$deviceCount = 10;
"""


class OverviewDeployTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name) / 'live'
        self.source = Path(self.temp.name) / 'source'
        self.backups = Path(self.temp.name) / 'backups'
        (self.root / 'config').mkdir(parents=True)
        (self.root / 'config/config.php').write_text('<?php')
        for name in mod.ENDPOINTS:
            live = self.root / name
            live.parent.mkdir(parents=True, exist_ok=True)
            live.write_text(API, encoding='utf-8')
            original = self.source / name
            original.parent.mkdir(parents=True, exist_ok=True)
            original.write_text(API.replace('requireLogin();', 'requireLogin();\n' + BLOCK), encoding='utf-8')
        lib = self.source / mod.LIBRARY
        lib.parent.mkdir(parents=True)
        lib.write_text('<?php // test helper', encoding='utf-8')

    def test_apply_plan_idempotency(self):
        planned = mod.prepare(self.source, self.root)
        self.assertEqual(len(planned), 4)
        self.assertNotIn(mod.START, (self.root / mod.ENDPOINTS[0]).read_text())
        backup = mod.apply(self.root, self.backups, planned)
        self.assertTrue(backup.exists())
        self.assertEqual(mod.prepare(self.source, self.root), {})
        deployed = (self.root / mod.ENDPOINTS[0]).read_text()
        self.assertIn('Other production customizations are preserved.', deployed)
        self.assertIn(mod.START, deployed)

    def test_missing_authentication_rejected(self):
        (self.root / mod.ENDPOINTS[0]).write_text('<?php header("Content-Type: application/json");')
        with self.assertRaises(mod.UnsafeInstallation):
            mod.prepare(self.source, self.root)

    def test_existing_partial_patch_rejected(self):
        (self.root / mod.ENDPOINTS[1]).write_text(API + '\n' + mod.START)
        with self.assertRaises(mod.UnsafeInstallation):
            mod.prepare(self.source, self.root)

    def test_concurrent_change_rejected(self):
        plan = mod.prepare(self.source, self.root)
        (self.root / mod.ENDPOINTS[2]).write_text('<?php // edited during install')
        with self.assertRaises(mod.UnsafeInstallation):
            mod.apply(self.root, self.backups, plan)

    def test_atomic_rollback(self):
        plan = mod.prepare(self.source, self.root)
        real = mod.os.replace
        count = 0

        def fail_after_first(src, dest):
            nonlocal count
            count += 1
            if count == 2:
                raise OSError('simulated filesystem failure')
            return real(src, dest)

        with patch.object(mod.os, 'replace', side_effect=fail_after_first):
            with self.assertRaises(OSError):
                mod.apply(self.root, self.backups, plan)
        self.assertFalse((self.root / mod.LIBRARY).exists())
        self.assertEqual((self.root / mod.ENDPOINTS[0]).read_text(), API)


if __name__ == '__main__':
    unittest.main()
