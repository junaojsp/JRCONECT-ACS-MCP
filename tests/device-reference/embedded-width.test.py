import importlib.util
from pathlib import Path
import subprocess
import unittest

ROOT=Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('deploy',ROOT/'scripts/deploy-acs-embedded-width-fix.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)

class EmbeddedWidthTests(unittest.TestCase):
    def test_removes_only_suffix_and_preserves_operational_bytes(self):
        prefix=b'var operational = true;\n/* JRC_ACOES_LOADER_V1 BEGIN */\nvar loader=true;\n/* JRC_ACOES_LOADER_V1 END */\n'
        before=prefix+m.FIXTURE.read_bytes();after=m.patch_js(before)
        self.assertEqual(after,prefix+m.MARK)
        self.assertEqual(m.patch_js(after),after)
        self.assertNotIn(b"setProperty('grid-column'",after)
        result=subprocess.run(['node','--check'],input=after,capture_output=True)
        self.assertEqual(result.returncode,0,result.stderr)
    def test_rejects_changed_or_repeated_visual_blocks(self):
        suffix=m.FIXTURE.read_bytes()
        for source in [suffix,suffix+suffix,b'/* JRC_ACOES_LOADER_V1 END */\n'+suffix.replace(b'function widen()',b'function other()')]:
            with self.assertRaises(RuntimeError):m.patch_js(source)

if __name__=='__main__':unittest.main()
