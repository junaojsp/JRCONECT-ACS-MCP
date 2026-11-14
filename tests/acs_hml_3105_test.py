import importlib.util
from pathlib import Path
from unittest.mock import patch
import unittest

path=Path(__file__).resolve().parents[1]/"scripts/acs-hml-3105.py"
spec=importlib.util.spec_from_file_location("acs_hml_3105",path)
hml=importlib.util.module_from_spec(spec)
spec.loader.exec_module(hml)

SUB="b33b099a-c5c8-499b-bc0d-4cd9d4d9a315"

def live_info(env=None,extra_mounts=None):
    return {
        "State":{"Running":True},
        "HostConfig":{"ReadonlyRootfs":True},
        "NetworkSettings":{"Networks":{a:{} for a in hml.EXPECTED_NETWORKS}},
        "Config":{"Env":[f"{k}={v}" for k,v in (env or {
            "OPA_API_MCP_ENABLED":"true","OPA_API_ADMIN_ENABLED":"false",
            "OPA_API_ALLOWED_SUB":SUB,"SHIELD_MCP_ALLOWED_SUB":SUB,
            "FINANCE_MCP_ENABLED":"true","KEYCLOAK_JWKS_URL":"https://internal.example.test"
        }).items()]},
        "Mounts":[
            {"Destination":"/data","Source":"/root/production-data","Type":"bind","RW":True},
            {"Destination":"/run/secrets/opa-api-token","Source":"/etc/hosts","Type":"bind","RW":False}
        ]+(extra_mounts or [])
    }

class IsolatedHmlTests(unittest.TestCase):
    def _conditions(self,live=None,exists=False,free=True):
        live=live or live_info()
        with patch.object(hml,"health",return_value=True),\
             patch.object(hml,"hml_exists",return_value=exists),\
             patch.object(hml,"free_port",return_value=free),\
             patch.object(hml,"inspect",return_value=live),\
             patch.object(hml,"run",return_value="sha256:abc123"):
            return hml.conditions()

    def test_healthy_clone_keeps_env_and_adds_only_acs_readonly(self):
        d=self._conditions()
        self.assertEqual(d["env"]["ACS_MONITOR_MCP_ENABLED"],"true")
        self.assertEqual(d["env"]["ACS_ADMIN_MCP_ENABLED"],"false")
        self.assertEqual(d["env"]["ACS_MONITOR_ALLOWED_SUB"],SUB)
        self.assertEqual(d["env"]["FINANCE_MCP_ENABLED"],"true")
        self.assertEqual(d["secrets"],[("/etc/hosts","/run/secrets/opa-api-token")])
        self.assertEqual(d["data_source"],"/root/production-data")

    def test_never_uses_arbitrary_or_multi_user_subjects(self):
        cases=[
            {"OPA_API_ALLOWED_SUB":SUB+",other","SHIELD_MCP_ALLOWED_SUB":SUB},
            {"OPA_API_ALLOWED_SUB":SUB,"SHIELD_MCP_ALLOWED_SUB":"other"},
            {"OPA_API_ALLOWED_SUB":"","SHIELD_MCP_ALLOWED_SUB":""},
        ]
        for case in cases:
            with self.subTest(case=case):
                env={"OPA_API_MCP_ENABLED":"true","OPA_API_ADMIN_ENABLED":"false",**case}
                with self.assertRaisesRegex(RuntimeError,"Allowlist"):
                    self._conditions(live_info(env))

    def test_production_admin_or_new_acs_flags_fail_closed(self):
        env={"OPA_API_MCP_ENABLED":"true","OPA_API_ADMIN_ENABLED":"true",
             "OPA_API_ALLOWED_SUB":SUB,"SHIELD_MCP_ALLOWED_SUB":SUB}
        with self.assertRaisesRegex(RuntimeError,"Opa"):
            self._conditions(live_info(env))
        env["OPA_API_ADMIN_ENABLED"]="false"
        env["ACS_MONITOR_MCP_ENABLED"]="true"
        with self.assertRaisesRegex(RuntimeError,"ACS ja habilitado"):
            self._conditions(live_info(env))

    def test_refuse_unexpected_writable_mount(self):
        with self.assertRaisesRegex(RuntimeError,"Montagem desconhecida"):
            self._conditions(live_info(extra_mounts=[
                {"Destination":"/var/run/docker.sock","Source":"/var/run/docker.sock",
                 "Type":"bind","RW":True}
            ]))

    def test_preflight_refuses_port_or_existing_container(self):
        with self.assertRaisesRegex(RuntimeError,"ja existe"):
            self._conditions(exists=True)
        with self.assertRaisesRegex(RuntimeError,"ocupada"):
            self._conditions(free=False)

    def test_only_3105_is_new_and_bound_on_loopback(self):
        self.assertEqual(hml.HML_PORT,3105)
        self.assertEqual(hml.HML,"jrconect-mcp-acs-hml")
        self.assertEqual(hml.IMAGE,"jrconect-mcp:acs-readonly-stage-20261114T022110Z")
        self.assertNotEqual(hml.HML,hml.LIVE)
        self.assertNotEqual(hml.HML,hml.LEGACY)
        self.assertNotEqual(hml.HML,hml.MONITOR)
        source=path.read_text()
        for marker in ("127.0.0.1:3105:3100","--read-only","--cap-drop",
                       "no-new-privileges","/host/proc/","ACS_ADMIN_MCP_ENABLED",
                       "dst=/data","--restart","no"):
            self.assertIn(marker,source)
        for forbidden in ('"docker","restart"', '"docker","stop"',
                          '"docker","compose"', '"nginx","-s"',
                          'docker.sock'):
            self.assertNotIn(forbidden,source)

    def test_status_does_not_modify_other_containers(self):
        with patch.object(hml,"hml_exists",return_value=False):
            hml.status()

if __name__=="__main__":
    unittest.main()
