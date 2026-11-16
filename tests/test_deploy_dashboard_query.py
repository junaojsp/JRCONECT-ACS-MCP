import importlib.util
from pathlib import Path
import tempfile
import unittest

ROOT = Path(__file__).resolve().parent.parent
spec = importlib.util.spec_from_file_location('deploy', ROOT / 'scripts/deploy-dashboard-query.py')
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)


class DeployTest(unittest.TestCase):
    def test_real_legacy_install_backup_idempotence(self):
        legacy = (ROOT / m.API).read_bytes().replace(
            b"require_once __DIR__ . '/../lib/JrcRecentDevices.php';\n    $result = JrcRecentDevices::fetch($config);",
            b'$result = $genieacs->getDevices();')
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp) / 'live'
            (root / 'config').mkdir(parents=True)
            (root / 'config/config.php').write_text('<?php')
            (root / 'api').mkdir()
            original = legacy + b'\n// production customization\n'
            (root / m.API).write_bytes(original)
            changes = m.prepare(root)
            backup = m.base.apply(root, Path(temp) / 'backups', changes)
            self.assertEqual((backup / m.API).read_bytes(), original)
            self.assertIn(b'production customization', (root / m.API).read_bytes())
            self.assertEqual(m.prepare(root), {})
            self.assertNotIn(b'->getDevices();', (root / m.API).read_bytes())

    def test_unknown_query_refused(self):
        with self.assertRaises(m.base.UnsafeInstallation):
            m.patch(b'<?php requireLogin(); customQuery();')

    def test_session_unlocked_without_cache_overlay(self):
        fixed = m.patch(b'<?php requireLogin(); $result = $genieacs->getDevices();')
        self.assertIn(b'session_write_close()', fixed)
        self.assertEqual(m.patch(fixed), fixed)

if __name__ == '__main__':
    unittest.main()
