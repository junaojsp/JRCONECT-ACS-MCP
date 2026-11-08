"""Guard against accidental exposure of PABX queue writes in read-only catalog."""
from pathlib import Path
import importlib.util
import unittest

mod_path = Path(__file__).with_name("enable-pabx-queue-write.py")
spec = importlib.util.spec_from_file_location("queue_patch", mod_path)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

FIXTURE = """
import { registerPabxQueueReport } from './integrations/pabx-queue-report.js';
function pabxReadAuthorized(payload) { return false; }
const makeMcpHandler = () => {
  if (includePabx) {
    registerPabxTools(mcp);
    registerPabxQueueReport(mcp);
      registerPabxAdminTools(mcp);
  }
  if (includePabxRead) {
    registerPabxTools(mcp);
    registerPabxQueueReport(mcp);
  }
};
if (pabxAdminAuthorized(payload)) {
  return adminCatalog();
}
"""

class QueueWritePatchTests(unittest.TestCase):
    def test_admin_only(self):
        result = module.patch(FIXTURE)
        self.assertEqual(result.count("registerPabxQueueWriteTools(mcp);"), 1)
        read_part = result.split("if (includePabxRead) {", 1)[1].split("}", 1)[0]
        self.assertNotIn("QueueWriteTools", read_part)
        self.assertNotIn("AdminTools", read_part)
        self.assertIn("registerPabxAdminTools(mcp);", result)

    def test_repeat_blocked(self):
        with self.assertRaisesRegex(ValueError, "already"):
            module.patch(module.patch(FIXTURE))

    def test_guard_required(self):
        with self.assertRaisesRegex(ValueError, "guard"):
            module.patch(FIXTURE.replace("if (pabxAdminAuthorized(payload)) {", "if (true) {"))

    def test_read_only_required(self):
        with self.assertRaisesRegex(ValueError, "Read-only"):
            module.patch(FIXTURE.replace("function pabxReadAuthorized(payload)", "function noGuard(payload)"))

    def test_admin_registration_required(self):
        with self.assertRaises(ValueError):
            module.patch(FIXTURE.replace("registerPabxAdminTools(mcp);", ""))

if __name__ == "__main__":
    unittest.main()
