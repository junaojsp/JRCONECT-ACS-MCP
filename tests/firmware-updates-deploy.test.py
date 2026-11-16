import importlib.util
from pathlib import Path
import tempfile
import unittest

ROOT = Path(__file__).resolve().parent.parent
spec = importlib.util.spec_from_file_location('deploy', ROOT / 'scripts/deploy-firmware-updates.py')
deploy = importlib.util.module_from_spec(spec)
spec.loader.exec_module(deploy)


class Deployment(unittest.TestCase):
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
