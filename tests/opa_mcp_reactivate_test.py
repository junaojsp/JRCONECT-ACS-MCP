import hashlib
import importlib.util
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

source = Path(__file__).resolve().parents[1] / "scripts" / "opa-mcp-bluegreen.py"
spec = importlib.util.spec_from_file_location("opa_bluegreen_reactivate", source)
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)

NGINX_ORIGINAL = """server {
    location = /mcp {
        proxy_pass http://127.0.0.1:3100;
        proxy_http_version 1.1;
    }
    location = /mcp-finance {
        proxy_pass http://127.0.0.1:3100;
    }
    location = /mcp-admin {
        proxy_pass http://127.0.0.1:3100;
    }
    location = /mcp-acs {
        proxy_pass http://127.0.0.1:3103/mcp;
    }
}
"""


class ReactivationTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.nginx = self.root / "mcp.conf"
        self.nginx.write_text(NGINX_ORIGINAL)
        self.backup = self.root / "mcp-backup.conf"
        self.backup.write_text(NGINX_ORIGINAL)
        self.persist = self.root / "persist"
        self.persist.mkdir()
        (self.persist / "connections.json").write_text('{"connections":[]}')
        self.state = {
            "activated": False,
            "green_id": "candidate-id",
            "candidate_image": "candidate-image",
            "production_image": "production-image",
            "persistent_data": str(self.persist),
            "nginx_path": str(self.nginx),
            "nginx_original_sha256": hashlib.sha256(NGINX_ORIGINAL.encode()).hexdigest(),
        }
        self.green = {
            "State": {"Running": True},
            "Id": "candidate-id",
            "Image": "candidate-image",
            "Mounts": [{
                "Destination": "/data",
                "Type": "bind",
                "Source": str(self.persist),
                "RW": True,
            }],
        }
        self.patches = [
            patch.object(mod.os, "geteuid", return_value=0),
            patch.object(mod, "STATE_DIR", self.root),
            patch.object(mod, "BACKUP", self.backup),
            patch.object(mod, "load_state", return_value=self.state),
            patch.object(mod, "ensure_live", return_value={"Image": "production-image"}),
            patch.object(mod, "inspect", return_value=self.green),
            patch.object(mod, "health", return_value=True),
            patch.object(mod, "config_path", return_value=self.nginx),
            patch.object(mod, "call", return_value=None),
            patch.object(mod, "store_state"),
        ]
        for item in self.patches:
            item.start()
            self.addCleanup(item.stop)

    def test_reactivate_after_rollback_preserves_existing_backup(self):
        mod.activate()
        changed = self.nginx.read_text()
        self.assertEqual(mod.upstream(changed), 3104)
        self.assertIn("proxy_pass http://127.0.0.1:3103/mcp;", changed)
        self.assertIn("location = /mcp-finance {\n        proxy_pass http://127.0.0.1:3100;", changed)
        self.assertEqual(self.backup.read_text(), NGINX_ORIGINAL)
        self.assertTrue(self.state["activated"])

    def test_rejects_mismatched_backup_without_changing_nginx(self):
        self.backup.write_text("unexpected backup")
        with self.assertRaisesRegex(RuntimeError, "Backup Nginx"):
            mod.activate()
        self.assertEqual(self.nginx.read_text(), NGINX_ORIGINAL)

    def test_rejects_already_active_state_without_changing_nginx(self):
        self.state["activated"] = True
        with self.assertRaisesRegex(RuntimeError, "ativacao em vigor"):
            mod.activate()
        self.assertEqual(self.nginx.read_text(), NGINX_ORIGINAL)

    def test_rejects_changed_nginx_even_if_backup_is_valid(self):
        self.nginx.write_text(NGINX_ORIGINAL + "# manual change\n")
        with self.assertRaisesRegex(RuntimeError, "Nginx modificado"):
            mod.activate()
        self.assertEqual(self.backup.read_text(), NGINX_ORIGINAL)


if __name__ == "__main__":
    unittest.main()
