import importlib.util
from pathlib import Path
import tempfile
import unittest
from contextlib import redirect_stdout
from io import StringIO

ROOT = Path(__file__).resolve().parent.parent
spec = importlib.util.spec_from_file_location('deploy', ROOT / 'scripts/deploy-firmware-updates.py')
deploy = importlib.util.module_from_spec(spec)
spec.loader.exec_module(deploy)


class Deployment(unittest.TestCase):
    def test_custom_device_page_is_optional(self):
        for source in [
            '<button class="custom-action">Comunicar</button>',
            '        <button\n            class="btn btn-primary">A</button>\n'
            '        <button\n            class="btn btn-primary">B</button>',
        ]:
            with self.subTest(source=source), redirect_stdout(StringIO()) as output:
                self.assertEqual(deploy.patch('device-detail.php', source), source)
                self.assertIn('preservado sem adicionar atalho', output.getvalue())

    def test_custom_device_does_not_block_installation(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder) / 'web'
            (root / 'config').mkdir(parents=True)
            (root / 'config/config.php').write_text('<?php // local config')
            (root / 'firmware-library.php').write_text('<h2>Repositorio Central de Firmwares</h2>')
            # This critical entry point is already linked (idempotent).
            (root / 'firmware-homologation.php').write_text('<a href="/firmware-updates.php">Atualizar</a>')
            original = b'<?php // custom device page\n?><button class="custom">Comunicar</button>'
            (root / 'device-detail.php').write_bytes(original)
            with redirect_stdout(StringIO()):
                changes = deploy.prepare(root)
            self.assertNotIn('device-detail.php', changes)
            self.assertIn('firmware-updates.php', changes)
            self.assertEqual((root / 'device-detail.php').read_bytes(), original)
            self.assertFalse((root / 'firmware-updates.php').exists())
            backup = deploy.install(root, changes, Path(folder) / 'backup')
            self.assertEqual((root / 'device-detail.php').read_bytes(), original)
            self.assertFalse((backup / 'device-detail.php').exists())
            self.assertIn('/firmware-updates.php', (root / 'firmware-library.php').read_text())
            with redirect_stdout(StringIO()):
                self.assertEqual(deploy.prepare(root), {})

    def test_preserve_customizations_and_repeat(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder) / 'web'
            (root / 'config').mkdir(parents=True)
            (root / 'config/config.php').write_text('<?php // local configuration')
            for name in ['firmware-library.php', 'firmware-homologation.php', 'device-detail.php']:
                original = {
                    'firmware-library.php': '<h2>Repositorio Central de Firmwares</h2><td class="fw-tag">Nao homologado</td>',
                    'firmware-homologation.php': '<div class="card"><h3>Atualização</h3><div class="notice">Envio bloqueado enquanto não validarmos a integração do GenieACS e o procedimento oficial desta imagem.</div><button type="button" disabled aria-disabled="true" class="fw-simple-disabled">Atualizar ONU (ainda indisponível)</button></div>',
                    'device-detail.php': '        <button\n            class="btn btn-primary" onclick="summonDevice()">Comunicar</button>',
                }[name]
                (root / name).write_text(original + '\n<!-- production customization -->')
            changes = deploy.prepare(root)
            self.assertFalse((root / 'firmware-updates.php').exists())
            backup = deploy.install(root, changes, Path(folder) / 'backup')
            self.assertTrue((backup / 'firmware-library.php').exists())
            self.assertIn('production customization', (root / 'firmware-library.php').read_text())
            self.assertIn('firmware-updates.php', (root / 'firmware-homologation.php').read_text())
            self.assertEqual(deploy.prepare(root), {})
            self.assertEqual((root / 'config/config.php').read_text(), '<?php // local configuration')

    def test_unknown_structure_stops(self):
        with self.assertRaises(ValueError):
            deploy.patch('firmware-homologation.php', 'custom page with different structure')

    def test_concurrent_change_rolls_back(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            (root / 'first.php').write_bytes(b'old')
            (root / 'second.php').write_bytes(b'concurrent')
            with self.assertRaises(ValueError):
                deploy.install(root, {'first.php': (b'old', b'new'), 'second.php': (b'expected', b'new')}, root / 'backup')
            self.assertEqual((root / 'first.php').read_bytes(), b'old')
            self.assertEqual((root / 'second.php').read_bytes(), b'concurrent')


unittest.main()
