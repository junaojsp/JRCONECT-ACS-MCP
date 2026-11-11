/** Called only AFTER validarToken verifies the JWT signature, issuer and audience. */
export function financeFullMcpAuthorized(payload, env = process.env) {
  if (env.FINANCE_MCP_FULL_ENABLED !== 'true' || payload?.azp !== 'chatgpt-mcp') return false;
  if (typeof payload?.sub !== 'string' || !/^[a-f0-9-]{36}$/i.test(payload.sub)) return false;
  const owners = (env.FINANCE_MCP_FULL_ALLOWED_SUB || '').split(',').map(x => x.trim()).filter(Boolean);
  return owners.includes(payload.sub) && Array.isArray(payload.realm_access?.roles) && payload.realm_access.roles.includes('finance-mcp-owner');
}
