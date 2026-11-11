import { createFinanceClient } from './finance-client.js';
import { registerFinanceTools } from './finance-tools.js';
import { createFinanceAdminClient } from './finance-admin-client.js';
import { registerFinanceAdminTools } from './finance-admin-tools.js';
import { registerFinanceFullSuite } from './finance-full-tools.js';

/**
 * Register only in the existing OAuth-gated /mcp-admin handler.
 * NEVER register these tools in the shared /mcp handler used by Opa Suite.
 * Secret files are read on tool invocation, not on server startup.
 */
export function registerFinanceSuite(adminMcp, options = {}) {
  registerFinanceTools(adminMcp, createFinanceClient(options.read));
  registerFinanceAdminTools(adminMcp, createFinanceAdminClient(options.admin));
  registerFinanceFullSuite(adminMcp, {
    api: { ...options.admin, tokenFile: options.admin?.tokenFile || '/run/secrets/finance-admin-token' },
    includeHost: false,
  });
}

