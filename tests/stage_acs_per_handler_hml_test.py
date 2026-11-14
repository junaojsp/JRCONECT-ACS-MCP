import importlib.util
from pathlib import Path
import unittest

script = Path(__file__).resolve().parents[1]/"scripts/stage-acs-per-handler-hml.py"
spec = importlib.util.spec_from_file_location("acs_per_handler_hml", script)
stage = importlib.util.module_from_spec(spec)
spec.loader.exec_module(stage)

# Synthetic excerpt of the known already-staged V1 HML server.js.
SOURCE = """import { AsyncLocalStorage } from 'node:async_hooks';
const acsRequestContext = new AsyncLocalStorage();
const makeMcpHandler = (includeShield, includeFinance = false, includeOpa = false, includeIxc = false, includePabx = false, includePabxRead = false, includeAcs = false) => createMcpHandler(() => {
  const mcp = new McpServer({});
  if (includeAcs) registerAcsMonitorTools(mcp, () => acsRequestContext.getStore());
  return mcp;
});
const handler = createMcpHandler(() => {
  const mcp = new McpServer({});
  return mcp;
});
const mcpHandler = toNodeHandler(handler);
const acsCentralReadHandler = toNodeHandler(makeMcpHandler(true, true, true, true, true, true, true));
const financeHandler = toNodeHandler(makeMcpHandler(false, true, false, false, false, false));
const opaHandler = toNodeHandler(makeMcpHandler(false, false, true, false, false, false));
const ixHandler = toNodeHandler(makeMcpHandler(false, false, false, true, false, false));
const shieldHandler = toNodeHandler(makeMcpHandler(true, false, false, false, false, false));
const combinedHandlers = [
  toNodeHandler(makeMcpHandler(true, true, true, true, false, false)),
  toNodeHandler(makeMcpHandler(true, false, true, true, false, false)),
  toNodeHandler(makeMcpHandler(true, true, false, true, false, false)),
  toNodeHandler(makeMcpHandler(true, false, false, true, false, false)),
];
const pabxCentralMcpHandlers = [
  toNodeHandler(makeMcpHandler(true, false, false, false, true)),
  toNodeHandler(makeMcpHandler(true, true, true, true, true, false)),
  toNodeHandler(makeMcpHandler(true, true, true, true, false, true)),
];
async function route(req, res, match) {
  try {
    const payload = await validarToken(match[1]);
    // JRC_ACS_READONLY_OVERLAY_V1: only all-scopes admins; strict per-request OAuth context.
    if (acsMonitorAuthorized(payload) &&
        financeMcpAuthorized(payload) && opaApiAuthorized(payload) &&
        ixcWebserviceAuthorized(payload) && pabxReadAuthorized(payload) &&
        pabxAdminAuthorized(payload) &&
        process.env.SHIELD_MCP_ALLOWED_SUB === payload.sub) {
      req.mcpUser = { sub: payload.sub, client: payload.azp };
      return acsRequestContext.run(payload, () => acsCentralReadHandler(req, res));
    }
    if (process.env.SHIELD_MCP_ALLOWED_SUB === payload.sub &&
        /^[a-f0-9-]{36}$/i.test(process.env.SHIELD_MCP_ALLOWED_SUB || '')) {
      const permissions =
        (financeMcpAuthorized(payload) ? 1 : 0) |
        (opaApiAuthorized(payload) ? 2 : 0);
      return pabxCentralMcpHandlers[permissions](req, res);
    }
    if (opaApiAuthorized(payload)) {
      return opaHandler(req,res);
    }
    if (financeMcpAuthorized(payload)) {
      return financeHandler(req,res);
    }
    return mcpHandler(req,res);
  } catch {
    return unauthorized(res);
  }
}
"""

class PerHandlerPatchTests(unittest.TestCase):
    def test_preserves_original_dispatch_and_six_permission_flags(self):
        updated, count, defaults_used = stage.patch_server(SOURCE)
        self.assertEqual(count, 11)
        self.assertEqual(defaults_used, 1)
        self.assertEqual(updated.count("acsAwareNodeHandler("), count)
        self.assertNotIn("acsCentralReadHandler", updated)
        self.assertNotIn("only all-scopes admins", updated)
        self.assertIn("const mcpHandler = toNodeHandler(handler);", updated)
        self.assertIn("const financeHandler = acsAwareNodeHandler(false, true, false, false, false, false);", updated)
        self.assertIn("const opaHandler = acsAwareNodeHandler(false, false, true, false, false, false);", updated)
        self.assertIn("const ixHandler = acsAwareNodeHandler(false, false, false, true, false, false);", updated)
        self.assertIn("const shieldHandler = acsAwareNodeHandler(true, false, false, false, false, false);", updated)
        self.assertIn("acsAwareNodeHandler(true, false, false, false, true, false)", updated)
        self.assertIn("if (process.env.SHIELD_MCP_ALLOWED_SUB === payload.sub &&", updated)
        self.assertIn("return pabxCentralMcpHandlers[permissions](req, res);", updated)
        self.assertIn("return opaHandler(req,res);", updated)
        self.assertIn("return financeHandler(req,res);", updated)
        self.assertIn("return mcpHandler(req,res);", updated)
        self.assertIn("acsRequestContext.enterWith(payload);", updated)
        self.assertIn("if (!acsMonitorAuthorized(validatedJwt))",updated)
        self.assertIn("const existingHandler = toNodeHandler(makeMcpHandler(...legacyFlags));",updated)
        self.assertIn("makeMcpHandler(...legacyFlags, true)", updated)
        self.assertIn("if (includeAcs) registerAcsMonitorTools", updated)
        self.assertNotIn("registerAcsAdminTools", updated)
        self.assertEqual(updated.count(stage.OVERLAY_MARKER), 2)
        self.assertNotIn("toNodeHandler(makeMcpHandler(true", updated)

    def test_fails_on_second_patch(self):
        updated, _, _ = stage.patch_server(SOURCE)
        with self.assertRaisesRegex(stage.Blocked, "OVERLAY_V2_JA_APLICADO"):
            stage.patch_server(updated)

    def test_fails_when_new_or_unknown_handler_args_exist(self):
        altered = SOURCE.replace(
            "toNodeHandler(makeMcpHandler(false, true, false, false, false, false))",
            "toNodeHandler(makeMcpHandler(false, true, unknown, false, false, false))")
        with self.assertRaisesRegex(stage.Blocked, "HANDLER_ASSINATURA_NAO_SUPORTADA"):
            stage.patch_server(altered)

    def test_fails_if_original_route_diverged(self):
        altered = SOURCE.replace("if (process.env.SHIELD_MCP_ALLOWED_SUB === payload.sub &&",
                                 "if (shieldAuthorized(payload) &&")
        with self.assertRaises(stage.Blocked):
            stage.patch_server(altered)

    def test_accepts_shortened_legacy_signatures_with_default_false(self):
        # Caller explicitly sets first five flags; last omitted flag has default=false.
        updated, count, defaults_used = stage.patch_server(SOURCE)
        self.assertEqual(defaults_used, 1)
        self.assertIn("acsAwareNodeHandler(true, false, false, false, true, false)", updated)
        self.assertEqual(count, 11)

    def test_rejects_empty_or_seven_legacy_arguments(self):
        old = "toNodeHandler(makeMcpHandler(true, false, false, false, false, false))"
        for bad in ["toNodeHandler(makeMcpHandler())",
                    "toNodeHandler(makeMcpHandler(true, false, false, false, false, false, false))"]:
            with self.subTest(bad=bad):
                altered = SOURCE.replace(old, bad)
                with self.assertRaisesRegex(stage.Blocked, "HANDLER_ASSINATURA_NAO_SUPORTADA"):
                    stage.patch_server(altered)

    def test_rejects_factory_default_or_order_drift(self):
        for old, bad in [
            ("includePabxRead = false", "includePabxRead = true"),
            ("includePabx = false, includePabxRead = false",
             "includePabxRead = false, includePabx = false"),
        ]:
            with self.subTest(old=old):
                altered = SOURCE.replace(old, bad)
                with self.assertRaisesRegex(stage.Blocked, "ESTRUTURA_DIVERGENTE_FACTORY_DEFAULTS"):
                    stage.patch_server(altered)

    def test_no_prod_modification_in_script(self):
        s = script.read_text()
        for banned in (
            '"docker", "restart"', '"docker", "stop"', '"docker", "rm"',
            '"docker", "compose"', '"nginx", "-s"', 'docker.sock',
            '"docker", "exec"', '"docker", "run", "-d"',
            "ACS_ADMIN_MCP_ENABLED",
        ):
            self.assertNotIn(banned, s)
        self.assertIn('SOURCE_CONTAINER = "jrconect-mcp-acs-hml"', s)
        self.assertIn('PORTS_TO_PRESERVE = (3100, 3103, 3104, 3105)',s)

if __name__ == "__main__":
    unittest.main()
