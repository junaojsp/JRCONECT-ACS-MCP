"""No production access needed: test the read-only catalog patch against a fixture."""
import importlib.util
from pathlib import Path
import unittest

MODULE = Path(__file__).with_name("enable-pabx-readonly.py")
spec = importlib.util.spec_from_file_location("pabx_patch", MODULE)
patch_module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(patch_module)

FIXTURE = """
import { registerPabxQueueReport } from './integrations/pabx-queue-report.js';
const makeMcpHandler = (includeShield, includeFinance = false, includeOpa = false, includeIxc = false, includePabx = false) => createMcpHandler(() => {
  const mcp = new McpServer({ name: 'test' });
  if (includePabx) {
    registerPabxTools(mcp);
    registerPabxQueueReport(mcp);
    registerPabxAdminTools(mcp);
  }
  return mcp;
});
const pabxCentralMcpHandlers = [
  toNodeHandler(makeMcpHandler(true, false, false, false, true)),
];
async function processRequest(req, res) {
  if (validarTokenOpa('x')) {
    return mcpHandler(req, res);
  }
  try {
    const payload = await validarToken('x');
    if (pabxAdminAuthorized(payload)) {
      return pabxCentralMcpHandlers[0](req, res);
    }
    if (ixcWebserviceAuthorized(payload)) {
      return ixcCentralMcpHandler(req, res);
    }
    return mcpHandler(req, res);
  } catch {
    return unauthorized(res);
  }
}
"""

class ReadOnlyPatchTests(unittest.TestCase):
    def test_read_only_registration_does_not_include_admin(self):
        result = patch_module.patch(FIXTURE)
        section = result.split("if (includePabxRead) {", 1)[1].split("}", 1)[0]
        self.assertIn("registerPabxTools(mcp)", section)
        self.assertIn("registerPabxQueueReport(mcp)", section)
        self.assertNotIn("registerPabxAdminTools", section)
        self.assertIn("PABX_READ_ALLOWED_SUB", result)
        self.assertIn("payload?.azp === 'chatgpt-mcp'", result)
        self.assertIn("pabxReadFallbackHandler(req, res)", result)

    def test_admin_registration_untouched(self):
        result = patch_module.patch(FIXTURE)
        self.assertIn("if (includePabx) {\n    registerPabxTools(mcp);\n    registerPabxQueueReport(mcp);\n    registerPabxAdminTools(mcp);", result)

    def test_patch_is_not_repeatable(self):
        result = patch_module.patch(FIXTURE)
        with self.assertRaisesRegex(ValueError, "already"):
            patch_module.patch(result)

    def test_unknown_production_code_fails_closed(self):
        with self.assertRaises(ValueError):
            patch_module.patch("const makeMcpHandler = somethingElse;")

if __name__ == "__main__":
    unittest.main()
