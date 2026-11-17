import importlib.util
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

REPO = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('shared_theme', REPO / 'scripts/deploy-acs-approved-theme.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

OLD = '''<script src="/assets/js/operations.js"></script>
<link id="jrc-command-center" rel="stylesheet" href="/assets/css/command-center.css?v=old">
<script>(function () { var id='jrc-command-center'; function pinCommandCenter() { return id; } var observer = new MutationObserver(pinCommandCenter); })();</script>
</body></html>'''


class SharedThemeTests(unittest.TestCase):
    def test_remove_old_observer_keep_operations_and_reapply(self):
        result = module.patch_document(OLD)
        self.assertNotIn('pinCommandCenter', result)
        self.assertNotIn('command-center.css', result)
        self.assertIn('/assets/js/operations.js', result)
        self.assertEqual(module.patch_document(result), result)

    def test_unknown_observer_fails_closed(self):
        with self.assertRaises(RuntimeError):
            module.patch_document('<script>pinCommandCenter()</script></body>')

    def test_common_footer_and_standalone_module_without_touching_login(self):
        with tempfile.TemporaryDirectory() as name, tempfile.TemporaryDirectory() as backups:
            root = Path(name)
            for folder in ('views/layouts', 'assets/css', 'scripts'):
                (root / folder).mkdir(parents=True)
            (root / 'views/layouts/footer.php').write_text(OLD)
            for route in ('voip-central', 'configuration', 'firmware-manager', 'alerts', 'reports-hub', 'ip-addressing', 'users'):
                (root / (route + '.php')).write_text("<?php require 'views/layouts/footer.php'; ?>")
            (root / 'mcp-server.php').write_text('<html><body><button onclick="original()">MCP</button></body></html>')
            (root / 'login.php').write_text('<html><body>Login</body></html>')
            # Runtime PHP validation is enforced by the deploy; this test covers patch semantics.
            with patch.object(module.base, 'check'):
                jobs = module.inspect(root)
                self.assertEqual(len(jobs), 4)
                module.base.apply(jobs, root, Path(backups))
                self.assertEqual(module.inspect(root), [])
            self.assertIn(module.MARK, (root / 'mcp-server.php').read_text())
            self.assertIn('original()', (root / 'mcp-server.php').read_text())
            self.assertNotIn(module.MARK, (root / 'login.php').read_text())


if __name__ == '__main__':
    unittest.main()
