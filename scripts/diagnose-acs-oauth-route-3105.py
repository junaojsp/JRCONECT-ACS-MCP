#!/usr/bin/env python3
"""Diagnostico da autorizacao ACS HML 3105 - SOMENTE LEITURA.

Nunca exibe token JWT, subject UUID, secrets, env values, codigo-fonte,
mensagens de atendimento, credenciais ou dados de clientes.
Consulta apenas o container NOVO HML na porta 3105; nao altera nada.
"""
import json
import subprocess
import sys

HML = "jrconect-mcp-acs-hml"

JS = r'''
const fs = require("node:fs");
const crypto = require("node:crypto");

(async () => {
  const code = fs.readFileSync("/app/server.js", "utf8");
  const module = await import("file:///app/integrations/acs-monitor-central-tools.js");
  const allowedSubs = (process.env.ACS_MONITOR_ALLOWED_SUB || "").split(",").map(x => x.trim()).filter(Boolean);
  const sub = allowedSubs[0] || "__none__";
  const sample = {
    azp: "chatgpt-mcp",
    sub,
    realm_access: { roles: ["acs-mcp-admin"] }
  };
  const negative = {
    ...sample, realm_access: { roles: [] }
  };

  const signatureFound =
    /const\s+makeMcpHandler\s*=\s*\(/.test(code);
  const acsRegistrationFound =
    /if\s*\(includeAcs\)\s*registerAcsMonitorTools\s*\(/.test(code);
  const routeStart = code.indexOf("if (acsMonitorAuthorized(payload) &&");
  const routeSlice = routeStart === -1 ? "" : code.slice(routeStart, routeStart + 800);
  const end = routeSlice.indexOf(") {");
  const clause = end > 0 ? routeSlice.slice(0, end + 3) : "";
  const knownGuards = [
    "financeMcpAuthorized", "opaApiAuthorized", "ixcWebserviceAuthorized",
    "pabxReadAuthorized", "pabxAdminAuthorized"
  ];
  const requiredGuards = knownGuards.filter(k => clause.includes(k + "(payload)"));
  const shieldRequired = clause.includes("SHIELD_MCP_ALLOWED_SUB");
  const selectionPresent = clause.includes("acsCentralReadHandler(req, res)") ||
    routeSlice.includes("acsCentralReadHandler(req, res)");
  const acsFlagsPresent = {
    monitor_enabled: process.env.ACS_MONITOR_MCP_ENABLED === "true",
    admin_disabled: process.env.ACS_ADMIN_MCP_ENABLED !== "true"
  };

  const compareNoValue = {
    allowed_sub_configured: allowedSubs.length > 0,
    allowlist_single_subject: allowedSubs.length === 1,
    opa_same_subject: allowedSubs.length === 1 &&
      (process.env.OPA_API_ALLOWED_SUB || "").split(",").map(x => x.trim()).includes(sub),
    shield_same_subject: allowedSubs.length === 1 &&
      (process.env.SHIELD_MCP_ALLOWED_SUB || "").trim() === sub
  };
  const result = {
    origem: "container_HML_3105",
    assinatura_makeMcpHandler: signatureFound,
    registro_condicional_ACS: acsRegistrationFound,
    rota_ACS_encontrada: routeStart !== -1,
    guards_adicionais_requeridos: requiredGuards,
    shield_tambem_requerido: shieldRequired,
    handler_ACS_presente_na_rota: selectionPresent,
    flags: acsFlagsPresent,
    allowlist: compareNoValue,
    simulacao_jwt_autorizado: module.acsMonitorAuthorized(sample),
    simulacao_sem_role_bloqueada: !module.acsMonitorAuthorized(negative),
    lembrete: "JWT REAL NAO ANALISADO; nao afirmar que role foi entregue"
  };
  console.log(JSON.stringify(result));
})().catch(() => {
  console.log(JSON.stringify({erro:"DIAGNOSTICO_INTERNO_INDISPONIVEL"}));
  process.exitCode = 2;
});
'''

def diagnosticar():
    verify = subprocess.run(["docker", "inspect", "-f", "{{.State.Running}}", HML],
                            capture_output=True, text=True, check=False, timeout=10)
    if verify.returncode or verify.stdout.strip() != "true":
        raise RuntimeError("HML_3105_NAO_ESTA_RUNNING")
    proc = subprocess.run(["docker", "exec", "-i", HML, "node", "-"],
                          input=JS, capture_output=True, text=True,
                          check=False, timeout=15)
    if proc.returncode:
        raise RuntimeError("DIAGNOSTICO_HML_FALHOU")
    info = json.loads(proc.stdout)
    if "erro" in info:
        raise RuntimeError(info["erro"])
    return info

if __name__ == "__main__":
    try:
        print("=== DIAGNOSTICO ACS 3105 - SEM LOGIN ===")
        info = diagnosticar()
        print(json.dumps(info, indent=2, ensure_ascii=False))
        print("=== FIM - NENHUMA ALTERACAO ===")
    except Exception as exc:
        print("BLOQUEADO:", str(exc), file=sys.stderr)
        sys.exit(2)
