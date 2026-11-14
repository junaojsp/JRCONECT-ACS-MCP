import importlib.util
import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

spec=importlib.util.spec_from_file_location("persist",str(Path(__file__).resolve().parents[1]/"scripts/opa-mcp-migrate-green-data.py"))
mod=importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)

class TestMigrationSafety(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.data=Path(self.tmp.name)/"data"
        self.snapshot=Path(self.tmp.name)/"snapshot"
        self.old_data=mod.DATA_DIR
        self.old_snapshot=mod.SNAPSHOT
        mod.DATA_DIR=self.data
        mod.SNAPSHOT=self.snapshot
        self.addCleanup(lambda: setattr(mod,"DATA_DIR",self.old_data))
        self.addCleanup(lambda: setattr(mod,"SNAPSHOT",self.old_snapshot))
        self.live={"State":{"Running":True},"Image":"sha256:production"}
        self.green={
            "Id":"green-original","Image":"sha256:green-image","State":{"Running":True},
            "Config":{"Env":["OPA_API_MCP_ENABLED=true"]},
            "HostConfig":{"ReadonlyRootfs":True,"NetworkMode":mod.BASE_NET,
              "PortBindings":{"3100/tcp":[{"HostIp":"127.0.0.1","HostPort":"3104"}]},
              "Tmpfs":{"/data":"rw,nosuid,nodev"}},
            "Mounts":[{"Destination":"/run/secrets/opa-api-token","Type":"bind","RW":False,
                       "Source":"/root/secret"}],
            "NetworkSettings":{"Networks":{mod.BASE_NET:{},mod.AUTH_NET:{}}},
        }
        state={"activated":False,"production_image":"sha256:production",
               "green_id":"green-original","candidate_image":"sha256:green-image"}
        self.patches=[
            patch.object(mod.os,"geteuid",return_value=0),
            patch.object(mod,"load_state",return_value=state),
            patch.object(mod,"nginx_is_live",return_value=True),
            patch.object(mod,"healthy",return_value=True),
            patch.object(mod,"inspect",side_effect=lambda name: self.live if name==mod.LIVE else self.green),
        ]
        for p in self.patches:p.start();self.addCleanup(p.stop)

    def test_tmpfs_from_hostconfig_is_allowed(self):
        state,live,green,mounts=mod.validate()
        self.assertEqual(state["green_id"],"green-original")
        self.assertTrue("data" not in mounts)

    def test_bind_mount_is_not_migrated_as_tmpfs(self):
        self.green["Mounts"].append({"Destination":"/data","Type":"bind",
                                     "RW":True,"Source":"/opt/shared"})
        with self.assertRaisesRegex(RuntimeError,"ja usa bind"):
            mod.validate()

    def test_refuses_changes_if_nginx_already_migrated(self):
        with patch.object(mod,"nginx_is_live",return_value=False):
            with self.assertRaisesRegex(RuntimeError,"Nginx"):
                mod.validate()

    def test_refuses_mismatched_green_container(self):
        self.green["Id"]="other"
        with self.assertRaisesRegex(RuntimeError,"mudou desde prepare"):
            mod.validate()

    def test_refuses_existing_persistent_data(self):
        self.data.mkdir()
        with self.assertRaisesRegex(RuntimeError,"ja existe"):
            mod.validate()

if __name__=="__main__":
    unittest.main()
