import importlib.util
import json
from pathlib import Path
import unittest
from unittest.mock import patch

path=Path(__file__).resolve().parents[1]/"scripts/audit-acs-mcpv2-readonly.py"
spec=importlib.util.spec_from_file_location("acs_runtime",path)
m=importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)

class ReadOnlyRuntimeAuditTests(unittest.TestCase):
    def test_monitor_reports_destinations_but_never_secret_sources_or_env_values(self):
        raw={
            "State":{"Running":True},
            "Image":"sha256:aabbccdd112233445566",
            "Config":{"User":"node","Env":["TOP_SECRET=NEVER_REPORT_ME"]},
            "HostConfig":{"ReadonlyRootfs":True,
                          "Tmpfs":{"/tmp":"rw"},
                          "PortBindings":{"3100/tcp":[{"HostIp":"127.0.0.1","HostPort":"3104"}]}},
            "NetworkSettings":{"Networks":{"jrconect-auth_auth-internal":{},"jrconect-mcp_default":{}}},
            "Mounts":[{"Destination":"/run/secrets/opa-api-token","Type":"bind",
                       "Source":"/root/NEVER_REPORT_SOURCE","RW":False},
                      {"Destination":"/data","Type":"bind",
                       "Source":"/root/NEVER_REPORT_PRIVATE_DATA","RW":True}]
        }
        with patch.object(m,"command",return_value=json.dumps([raw])):
            result=m.container_info(m.PRIMARY)
        dump=json.dumps(result)
        self.assertNotIn("NEVER_REPORT_",dump)
        self.assertTrue(result["read_only_rootfs"])
        self.assertEqual(result["mounts"][0]["destination"],"/run/secrets/opa-api-token")
        self.assertEqual(result["mounts"][0]["read_only"],True)
        self.assertEqual(result["mounts"][1]["read_only"],False)
        self.assertEqual(result["host_port_bindings"]["3100/tcp"][0]["port"],"3104")

    def test_only_known_targets_can_be_inspected(self):
        with self.assertRaises(RuntimeError):
            m.container_info("another-container")
    
    def test_no_shell_write_or_container_restart_instruction_in_child_program(self):
        audit=m.NODE_AUDIT
        for forbidden in ("writeFile","unlinkSync","docker.sock","process.exit","execSync",
                          "spawnSync","eval("):
            self.assertNotIn(forbidden,audit)
        self.assertIn("files[name]={present:true,lines:",audit)
        self.assertNotIn("console.log(code)",audit)

    def test_health_checks_have_fixed_local_ports(self):
        with self.assertRaises(RuntimeError):
            m.health(9999)
        calls=[]
        def fake(args,**kwargs):
            calls.append(args)
            return ""
        with patch.object(m,"command",side_effect=fake):
            self.assertEqual(m.health(3104),"HTTP_OK")
        self.assertEqual(calls[0][0],"curl")
        self.assertEqual(calls[0][-1],"http://127.0.0.1:3104/health")

if __name__=="__main__":
    unittest.main()
