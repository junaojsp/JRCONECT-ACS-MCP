import importlib.util
import json
from pathlib import Path
import unittest
from unittest.mock import patch

source = Path(__file__).resolve().parents[1]/"scripts/diagnose-acs-oauth-route-3105.py"
spec = importlib.util.spec_from_file_location("acs_guard_diagnostic", source)
diag = importlib.util.module_from_spec(spec)
spec.loader.exec_module(diag)

class Result:
    def __init__(self,code=0,out=""):
        self.returncode=code
        self.stdout=out
        self.stderr=""

class ReadOnlyGuardsTest(unittest.TestCase):
    def test_uses_only_fixed_hml_container_with_inline_node(self):
        seen=[]
        def run(cmd,**kwargs):
            seen.append(cmd)
            if cmd[:2]==["docker","inspect"]:
                return Result(out="true\n")
            return Result(out=json.dumps({"flags":{"monitor_enabled":True},"simulacao_jwt_autorizado":True}))
        with patch.object(diag.subprocess,"run",side_effect=run):
            data=diag.diagnosticar()
        self.assertTrue(data["simulacao_jwt_autorizado"])
        self.assertEqual(len(seen),2)
        self.assertEqual(seen[0][0:2],["docker","inspect"])
        self.assertEqual(seen[1],["docker","exec","-i","jrconect-mcp-acs-hml","node","-"])
    
    def test_does_not_query_any_production_container(self):
        for old in ("jrconect-mcp-opa-ready","jrconect-mcp","jrconect-acs-monitor-teste"):
            self.assertNotIn('"'+old+'"',diag.JS)
        for prohibited in ('docker.sock','process.env.JWT', 'console.log(code)',
                           'console.log(sub)', 'console.log(process.env)',
                           '"docker", "restart"', '"docker", "stop"',
                           '"docker", "rm"', '"docker", "compose"'):
            self.assertNotIn(prohibited,source.read_text())

    def test_refuses_missing_hml(self):
        with patch.object(diag.subprocess,"run",return_value=Result(out="false\n")):
            with self.assertRaisesRegex(RuntimeError,"NAO_ESTA_RUNNING"):
                diag.diagnosticar()

    def test_real_jwt_cannot_be_claimed_as_tested(self):
        self.assertIn("JWT REAL NAO ANALISADO", diag.JS)
        self.assertIn("simulacao_sem_role_bloqueada", diag.JS)
        self.assertIn("guards_adicionais_requeridos", diag.JS)
        self.assertIn("shield_tambem_requerido", diag.JS)

if __name__ == "__main__":
    unittest.main()
