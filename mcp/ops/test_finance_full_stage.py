import importlib.util
from pathlib import Path
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('stage', Path(__file__).with_name('stage-finance-full.py'))
stage = importlib.util.module_from_spec(spec); spec.loader.exec_module(stage)


class StageTests(unittest.TestCase):
    def test_stage_preserves_live_file_and_existing_handlers(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp); (root / 'integrations').mkdir()
            for name in ('finance-full-auth.js', 'finance-full-client.js', 'finance-full-tools.js', 'finance-host-client.js'):
                (root / 'integrations' / name).touch()
            original = "const mcpHandler = toNodeHandler(handler);\ncreateServer(async (req, res) => {\nif (req.url === '/wiki') wikiHandler(req,res);\n});\n"
            (root / 'server.js').write_text(original)
            out = stage.stage(root)
            self.assertEqual((root / 'server.js').read_text(), original)
            candidate = (out / 'server.js').read_text()
            self.assertIn("req.url === '/wiki'", candidate)
            self.assertLess(candidate.index("'/mcp-finance-full'"), candidate.index("req.url === '/wiki'"))
            self.assertIn('financeFullMcpAuthorized(payload)', candidate)
            (root / 'server.js').write_text(candidate)
            with self.assertRaises(ValueError): stage.stage(root)

    def test_unknown_live_version_is_rejected(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp); (root / 'server.js').write_text('unknown();')
            with self.assertRaises(ValueError): stage.stage(root)
            self.assertEqual((root / 'server.js').read_text(), 'unknown();')


if __name__ == '__main__': unittest.main()
