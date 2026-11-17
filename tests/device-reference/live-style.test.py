"""Migration regression using the exact visual-only width helper observed in production."""
import hashlib
import importlib.util
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

REPO = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('theme', REPO / 'scripts/deploy-acs-approved-theme.py')
m = importlib.util.module_from_spec(spec); spec.loader.exec_module(m)


class LiveStyleMigration(unittest.TestCase):
    def test_v1_migrates_and_cache_version_changes(self):
        old = m.OLD_MARK + '\n' + m.OLD_LINK + '\n' + m.INIT + '\n</body>'
        result = m.patch_document(old)
        self.assertIn(m.LINK, result)
        self.assertNotIn(m.OLD_LINK, result)
        self.assertEqual(m.patch_document(result), result)
        self.assertIn(hashlib.sha256((REPO / 'assets/css/jrc-approved-acs.css').read_bytes()).hexdigest()[:12], m.LINK)

    def test_remove_only_verified_width_helper_and_preserve_commands(self):
        fixture = (REPO / 'tests/device-reference/fixtures/jrc-acoes-width-v2.js').read_bytes()
        self.assertEqual(hashlib.sha256(fixture).hexdigest(), m.WIDTH_SHA)
        with tempfile.TemporaryDirectory() as tmp, tempfile.TemporaryDirectory() as backup:
            root = Path(tmp)
            for name in ('views/layouts','assets/css','assets/js','scripts'):
                (root / name).mkdir(parents=True)
            (root/'views/layouts/footer.php').write_text(m.OLD_MARK+'\n'+m.OLD_LINK+'\n'+m.INIT+'\n</body>')
            (root/'device-detail.php').write_text('<div id="jrc-device-reference"><ul id="deviceTabs"></ul><div id="deviceTabContent"></div></div><script src="/assets/js/jrc-acoes.js"></script><script src="/assets/js/jrc-acoes-width.js?v=2"></script>')
            (root/'assets/js/jrc-acoes-width.js').write_bytes(fixture)
            (root/'assets/js/jrc-acoes.js').write_text('/* operational command renderer unchanged */')
            # Known deployed v1 CSS must be accepted for upgrade.
            oldcss = subprocess.check_output(['git','show','3d8dcbff8c1949c5fd5d7c030bee18515a09a816:assets/css/jrc-approved-acs.css'],cwd=REPO)
            (root/'assets/css/jrc-approved-acs.css').write_bytes(oldcss)
            with patch.object(m.base,'check'):
                jobs=m.inspect(root)
                m.base.apply(jobs,root,Path(backup))
                self.assertEqual(m.inspect(root),[])
            self.assertNotIn('src="/assets/js/jrc-acoes-width.js', (root/'device-detail.php').read_text())
            self.assertIn('src="/assets/js/jrc-acoes.js', (root/'device-detail.php').read_text())
            self.assertEqual((root/'assets/js/jrc-acoes.js').read_text(),'/* operational command renderer unchanged */')
            self.assertEqual((root/'assets/js/jrc-acoes-width.js').read_bytes(),fixture)


if __name__ == '__main__': unittest.main()
