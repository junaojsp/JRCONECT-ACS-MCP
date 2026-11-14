import importlib.util
from pathlib import Path
from unittest.mock import patch
import unittest

SCRIPT=Path(__file__).resolve().parents[1]/"scripts/acs-v2-hml-3106.py"
spec=importlib.util.spec_from_file_location("acs_hml_3106",SCRIPT)
hml=importlib.util.module_from_spec(spec)
spec.loader.exec_module(hml)

UUID="b33b099a-c5c8-499b-bc0d-4cd9d4d9a315"

def inspect_fake(*, bad_data=False, missing_proc=False):
    mounts=[
        dict(Destination="/data",Source="/root/jrc-acs-hml-3105/data",
             Type="bind",RW=not bad_data),
        dict(Destination="/run/secrets/opa-token",Source="/etc/hosts",Type="bind",RW=False),
    ]
    if not missing_proc:
        mounts.extend([
            dict(Destination="/host/proc/"+file,Source="/proc/"+file,Type="bind",RW=False)
            for file in ("uptime","loadavg","meminfo")
        ])
    e=[
        "ACS_MONITOR_MCP_ENABLED=true",
        "ACS_ADMIN_MCP_ENABLED=false",
        "ACS_MONITOR_ALLOWED_SUB="+UUID,
        "OPA_API_ALLOWED_SUB="+UUID,
        "SHIELD_MCP_ALLOWED_SUB="+UUID,
        "OPA_API_MCP_ENABLED=true",
        "OPA_API_ADMIN_ENABLED=false",
        "FINANCE_MCP_ENABLED=true",
    ]
    return dict(
        State=dict(Running=True),
        Image="sha256:v1",
        NetworkSettings=dict(Networks={x:{} for x in hml.NETWORKS}),
        HostConfig=dict(ReadonlyRootfs=True,
                        PortBindings={"3100/tcp":[{"HostIp":"127.0.0.1","HostPort":"3105"}]}),
        Config=dict(User="1000:1000",Env=e),
        Mounts=mounts,
    )

class HmlV2SafetyTests(unittest.TestCase):
    def test_whitelist_of_mounts_preserves_secret_ro_and_host_proc_ro(self):
        data,secrets,proc=hml.expected_mounts(inspect_fake())
        self.assertEqual(data,"/root/jrc-acs-hml-3105/data")
        self.assertEqual(len(secrets),1)
        self.assertEqual(len(proc),3)
        self.assertIn(("/proc/uptime","/host/proc/uptime"),proc)

    def test_refuse_writable_secret_or_shared_data(self):
        f=inspect_fake()
        f["Mounts"][1]["RW"]=True
        with self.assertRaisesRegex(hml.Blocked,"SEGREDO"):
            hml.expected_mounts(f)
        with self.assertRaisesRegex(hml.Blocked,"DATA"):
            hml.expected_mounts(inspect_fake(bad_data=True))
        with self.assertRaisesRegex(hml.Blocked,"INCOMPLETAS"):
            hml.expected_mounts(inspect_fake(missing_proc=True))

    def test_refuse_unexpected_mount(self):
        f=inspect_fake()
        f["Mounts"].append(dict(Destination="/var/run/docker.sock",
                                Source="/var/run/docker.sock",Type="bind",RW=True))
        with self.assertRaisesRegex(hml.Blocked,"DESCONHECIDA"):
            hml.expected_mounts(f)

    def test_existing_container_or_port_blocks_plan(self):
        with patch.object(hml,"new_exists",return_value=True):
            with self.assertRaisesRegex(hml.Blocked,"JA_EXISTE"):
                hml.prereqs()
        with patch.object(hml,"new_exists",return_value=False),\
             patch.object(hml,"available_port",return_value=False):
            with self.assertRaisesRegex(hml.Blocked,"OCUPADA"):
                hml.prereqs()

    def test_verified_candidate_differs_from_hml_and_protects_old_ports(self):
        item=inspect_fake()
        with patch.object(hml,"new_exists",return_value=False),\
             patch.object(hml,"available_port",return_value=True),\
             patch.object(hml.Path,"exists",return_value=False),\
             patch.object(hml,"health",return_value=True),\
             patch.object(hml,"inspect",return_value=item),\
             patch.object(hml,"call",side_effect=lambda argv,**kw:
                "sha256:v1" if hml.OLD_IMAGE in argv else "sha256:v2"):
            cfg=hml.prereqs()
        self.assertEqual(cfg["image_id"],"sha256:v2")
        self.assertEqual(cfg["env"]["ACS_ADMIN_MCP_ENABLED"],"false")
        self.assertEqual(cfg["env"]["FINANCE_MCP_ENABLED"],"true")

    def test_no_write_to_existing_services(self):
        script=SCRIPT.read_text()
        for bad in ('"docker","restart"', '"docker","stop"', '"docker","compose"',
                    '"nginx","-s"', "docker.sock",
                    "--privileged", "--network=host"):
            self.assertNotIn(bad,script)
        self.assertIn('"127.0.0.1:3106:3100"',script)
        self.assertIn('"--cap-drop","ALL"',script)
        self.assertIn('"--read-only"',script)
        self.assertIn('"--restart","no"',script)
        self.assertIn('"--user","1000:1000"',script)
        self.assertIn('HEALTH_PORTS=(3100,3103,3104,3105)',script)

    def test_status_on_missing_hml_is_readonly(self):
        with patch.object(hml,"new_exists",return_value=False):
            hml.status()

if __name__=="__main__":
    unittest.main()
