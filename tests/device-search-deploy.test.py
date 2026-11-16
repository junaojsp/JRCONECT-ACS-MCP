import importlib.util
from pathlib import Path
import tempfile
import unittest

ROOT = Path(__file__).resolve().parent.parent
spec = importlib.util.spec_from_file_location('deploy', ROOT / 'scripts/deploy-device-search.py')
deploy = importlib.util.module_from_spec(spec)
spec.loader.exec_module(deploy)

class Installer(unittest.TestCase):
    def test_preserve_layout_backup_and_cache_bust(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            (root / 'assets/js').mkdir(parents=True)
            old = b'// test production version\n'
            old_hash = deploy.EXPECTED
            deploy.EXPECTED = deploy.digest(old)
            page = b'<h1>Custom layout</h1><script src="/assets/js/devices.js?v=old"></script>'
            (root / 'assets/js/devices.js').write_bytes(old)
            (root / 'devices.php').write_bytes(page)
            try:
                changes = deploy.prepare(root)
                self.assertEqual((root / 'assets/js/devices.js').read_bytes(), old)
                backup = deploy.install(root, changes, root / 'backup')
                self.assertEqual((backup / 'assets/js/devices.js').read_bytes(), old)
                self.assertEqual((root / 'assets/js/devices.js').read_bytes(), deploy.SOURCE.read_bytes())
                self.assertEqual((root / 'devices.php').read_bytes(), page.replace(b'?v=old', ('?v=' + deploy.VERSION).encode()))
                self.assertEqual(deploy.prepare(root), {})
            finally:
                deploy.EXPECTED = old_hash

    def test_unknown_js_fails_without_writing(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            (root / 'assets/js').mkdir(parents=True)
            (root / 'assets/js/devices.js').write_text('changed by operator')
            (root / 'devices.php').write_text('unchanged page')
            with self.assertRaisesRegex(ValueError, 'mudou'):
                deploy.prepare(root)
            self.assertEqual((root / 'devices.php').read_text(), 'unchanged page')

    def test_failure_reverts_previous_file(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            (root / 'first').write_bytes(b'old')
            (root / 'second').write_bytes(b'concurrent edit')
            with self.assertRaisesRegex(ValueError, 'concorrente'):
                deploy.install(root, {'first': (b'old', b'new'), 'second': (b'expected', b'new')}, root / 'backup')
            self.assertEqual((root / 'first').read_bytes(), b'old')
            self.assertEqual((root / 'second').read_bytes(), b'concurrent edit')

unittest.main()
