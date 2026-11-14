import importlib.util
from pathlib import Path
import unittest

file=Path(__file__).resolve().parents[1]/"scripts/stage-acs-readonly-central.py"
spec=importlib.util.spec_from_file_location("acs_stage", file)
stage=importlib.util.module_from_spec(spec)
spec.loader.exec_module(stage)

SOURCE="""import { createServer } from 'node:http';
import { createMcpHandler } from '@modelcontextprotocol/server';
const handler = createMcpHandler(() => {
  const mcp = new McpServer({});
  return mcp;
});
const makeMcpHandler = (includeShield, includeFinance = false, includeOpa = false, includeIxc = false, includePabx = false, includePabxRead = false) => createMcpHandler(() => {
  const mcp = new McpServer({});
  if (includeShield) registerShieldTools(mcp);
  if (includeFinance) registerFinanceSuite(mcp);
  if (includeOpa) registerOpaApiTools(mcp);
  if (includeIxc) registerIxcTools(mcp);
  if (includePabx) registerPabxAdmin(mcp);
  if (includePabxRead) registerPabxRead(mcp);
  return mcp;
});
const mcpHandler = toNodeHandler(handler);
const otherHandler = toNodeHandler(makeMcpHandler(true, true, false, false, false, true));
function route(req, res, match) {
  return (async () => {
    const payload = await validarToken(match[1]);
    if (process.env.SHIELD_MCP_ALLOWED_SUB === payload.sub) {
      return shieldMcpHandler(req, res);
    }
    if (pabxReadAuthorized(payload)) return pabxReadMcpHandler(req,res);
    if (pabxAdminAuthorized(payload)) return pabxAdminMcpHandler(req,res);
    if (financeMcpAuthorized(payload)) return financeMcpHandler(req,res);
    if (opaApiAuthorized(payload)) return opaCentralMcpHandler(req,res);
    if (ixcWebserviceAuthorized(payload)) return ixcMcpHandler(req,res);
    return mcpHandler(req,res);
  })();
}
"""

class StagingTests(unittest.TestCase):
    def test_stages_only_one_new_restricted_handler_and_preserves_old_routing(self):
        result=stage.patch_server(SOURCE)
        self.assertIn("const makeMcpHandler = (includeShield, includeFinance = false, includeOpa = false, includeIxc = false, includePabx = false, includePabxRead = false, includeAcs = false) => createMcpHandler(() => {", result)
        self.assertIn("acsCentralReadHandler",result)
        self.assertIn("registerAcsMonitorTools(mcp, () => acsRequestContext.getStore())",result)
        self.assertIn("acsMonitorAuthorized(payload)",result)
        self.assertIn("financeMcpAuthorized(payload)",result)
        self.assertIn("opaApiAuthorized(payload)",result)
        self.assertIn("pabxAdminAuthorized(payload)",result)
        self.assertIn("pabxReadAuthorized(payload)",result)
        self.assertIn("process.env.SHIELD_MCP_ALLOWED_SUB === payload.sub",result)
        self.assertIn("return shieldMcpHandler(req, res)",result)
        self.assertIn("return opaCentralMcpHandler(req,res)",result)
        self.assertEqual(result.count("JRC_ACS_READONLY_OVERLAY_V1"),2)
        self.assertEqual(result.count("registerOpaApiTools(mcp)"),1)
        self.assertIn("const handler = createMcpHandler(() => {\n  const mcp = new McpServer({});\n  return mcp;\n});", result)
        self.assertEqual(result.count("registerAcsMonitorTools(mcp"),1)
        self.assertEqual(result.count("includeAcs = false"),1)
        self.assertTrue(result.index("acsMonitorAuthorized(payload)") < result.index("if (process.env.SHIELD_MCP_ALLOWED_SUB === payload.sub)"))

    def test_second_staging_run_fails_closed(self):
        result=stage.patch_server(SOURCE)
        with self.assertRaisesRegex(RuntimeError,"ACS ja esta presente"):
            stage.patch_server(result)

    def test_changed_function_signature_fails_closed(self):
        altered=SOURCE.replace("includePabxRead = false)","includePabxRead = false, other = false)")
        with self.assertRaisesRegex(RuntimeError,"esperado 6"):
            stage.patch_server(altered)

    def test_swapped_permission_argument_names_fail_closed(self):
        altered=SOURCE.replace("includePabx = false, includePabxRead = false",
                               "includePabxRead = false, includePabx = false")
        with self.assertRaisesRegex(RuntimeError,"ordem ou nome"):
            stage.patch_server(altered)

    def test_changed_authentication_block_fails_closed(self):
        altered=SOURCE.replace("process.env.SHIELD_MCP_ALLOWED_SUB === payload.sub",
                               "checkShield(payload)")
        with self.assertRaisesRegex(RuntimeError,"SHIELD_MCP_ALLOWED_SUB"):
            stage.patch_server(altered)

    def test_missing_existing_mcp_handler_fails_closed(self):
        altered=SOURCE.replace("const mcpHandler = toNodeHandler(handler);","const mcpHandler = null;")
        with self.assertRaisesRegex(RuntimeError,"mcpHandler"):
            stage.patch_server(altered)

    def test_missing_permission_checker_fails_closed(self):
        altered=SOURCE.replace("pabxAdminAuthorized","otherGuard")
        with self.assertRaisesRegex(RuntimeError,"pabxAdminAuthorized"):
            stage.patch_server(altered)

    def test_admin_restart_not_exposed_or_imported(self):
        patched=stage.patch_server(SOURCE)
        self.assertNotIn("acs_admin_executar", patched)
        self.assertNotIn("acs_admin_planejar", patched)
        self.assertNotIn("docker.sock", patched)
        self.assertNotIn("registerAcsAdminTools", patched)
        self.assertNotIn("ACS_ADMIN_MCP_ENABLED",patched)

if __name__=="__main__":
    unittest.main()
