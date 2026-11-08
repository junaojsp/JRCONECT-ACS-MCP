"""Tests for fail-closed PABX admin deployment checks."""
import importlib.util
from pathlib import Path
import tempfile
import unittest

path = Path(__file__).with_name("check-pabx-admin.py")
spec = importlib.util.spec_from_file_location("pabx_admin_check", path)
check = importlib.util.module_from_spec(spec)
spec.loader.exec_module(check)

class AdminPreflightTests(unittest.TestCase):
    def test_subject_must_be_uuid(self):
        self.assertTrue(check.validate_oauth_subject("b33b099a-c5c8-499b-bc0d-4cd9d4d9a315"))
        self.assertFalse(check.validate_oauth_subject(""))
        self.assertFalse(check.validate_oauth_subject("chatgpt-mcp"))
        self.assertFalse(check.validate_oauth_subject("anything' OR true"))

    def test_secret_not_world_readable(self):
        with tempfile.TemporaryDirectory() as d:
            p = Path(d) / "admin.secret"
            p.write_text("a" * 64)
            p.chmod(0o600)
            self.assertEqual(check.validate_secret(str(p)), (True, "ok"))
            p.chmod(0o644)
            self.assertEqual(check.validate_secret(str(p)), (False, "world_access_not_allowed"))
            p.chmod(0o640)
            self.assertEqual(check.validate_secret(str(p)), (True, "ok"))

    def test_invalid_secret_does_not_pass(self):
        with tempfile.TemporaryDirectory() as d:
            p = Path(d) / "admin.secret"
            p.write_text("not-a-token")
            p.chmod(0o600)
            self.assertEqual(check.validate_secret(str(p)), (False, "invalid_token_format"))
        self.assertEqual(check.validate_secret("/file/that/does/not/exist"), (False, "missing_or_unreadable"))

    def test_requires_subject_and_role_guards(self):
        good = """
export function pabxAdminAuthorized(payload) {
 const role = process.env.PABX_ADMIN_ROLE;
 return process.env.PABX_ADMIN_MCP_ENABLED && process.env.PABX_ADMIN_ALLOWED_SUB &&
  process.env.PABX_ADMIN_AUTH_MODE &&
  payload?.azp === 'chatgpt-mcp' && roles.includes(role);
}
"""
        self.assertEqual(check.verify_admin_module(good), [])
        self.assertIn("roles.includes(role)", check.verify_admin_module(good.replace("roles.includes(role)", "true")))
        self.assertIn("PABX_ADMIN_ALLOWED_SUB", check.verify_admin_module(good.replace("PABX_ADMIN_ALLOWED_SUB", "PABX_ALLOWED")))

    def test_requires_separate_readonly_path(self):
        valid = """
registerPabxAdminTools(mcp);
if (pabxAdminAuthorized(payload)) {
registerPabxTools(mcp);
registerPabxQueueReport(mcp);
if (pabxReadAuthorized(payload)) {
"""
        self.assertEqual(check.verify_server(valid), [])
        self.assertIn("if (pabxAdminAuthorized(payload)) {", check.verify_server(valid.replace("pabxAdminAuthorized(payload)", "true")))

if __name__ == "__main__":
    unittest.main()
