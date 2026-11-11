import { z } from 'zod';
import { createFinanceFullClient, RESOURCES, ACTIONS } from './finance-full-client.js';
import { createFinanceHostClient, HOST_READS, HOST_WRITES } from './finance-host-client.js';

const confirmation = 'CONFIRMAR_OPERACAO_FINANCEIRA';
const maintenance = 'CONFIRMAR_MANUTENCAO_FINANCEIRO';
const record = z.record(z.string(), z.unknown());
const scope = z.enum(['company', 'personal']);
const date = z.string().regex(/^\d{4}-\d{2}-\d{2}$/);
const annotations = (readOnlyHint) => ({ readOnlyHint, destructiveHint: !readOnlyHint, idempotentHint: false, openWorldHint: true });
function handler(fn) {
  return async (args) => {
    try {
      const data = await fn(args);
      if (args.resource === 'receipt_file') return { content: [{ type: 'resource', resource: { uri: `finance-receipt://${args.id}`, mimeType: data.mime_type, blob: data.base64 } }] };
      return { content: [{ type: 'text', text: JSON.stringify(data) }] };
    } catch (e) { return { isError: true, content: [{ type: 'text', text: String(e.message || e) }] }; }
  };
}

/** Register only in an authenticated Financeiro/admin factory; never the shared Opa handler. */
export function registerFinanceFullSuite(mcp, options = {}) {
  const client = options.client || createFinanceFullClient(options.api);
  mcp.registerTool('financeiro_acesso_total_consultar', {
    description: 'Consulta administrativa do Financeiro: empresa, pessoal do administrador configurado, fornecedores, categorias, centros de custo, comprovantes, gastos manuais, alertas e estado WhatsApp. Consulte capabilities para a lista. Datas due_from/due_to filtram vencimento das contas ou data das despesas. account requer id e retorna detalhes e codigo de pagamento. Listagens sao paginadas. Nao executa pagamentos bancarios.',
    annotations: annotations(true),
    inputSchema: { resource: z.enum(RESOURCES), scope: scope.default('company'), id: z.number().int().positive().optional(), status: z.string().max(40).optional(), q: z.string().max(255).optional(), due_from: date.optional(), due_to: date.optional(), page: z.number().int().positive().optional(), limit: z.number().int().min(1).max(100).optional() },
  }, handler(({ resource, ...filters }) => client.read(resource, filters)));
  mcp.registerTool('financeiro_acesso_total_executar', {
    description: 'Administra empresa e pessoal. Acoes account.create/update/paid/delete/delete_batch (parcelas: repeat_months na criacao); supplier/category/cost_center.create/update/delete; receipt.upload/update/analyze/keep_duplicate/delete; manual_expense.create/update/delete; alert_settings.update; whatsapp.connect. Exige autorizacao explicita da operacao concreta. account.paid apenas registra baixa no app. Nao enviar mensagens sem pedido explicito. payload segue os campos do app; nao inclui senhas nem tokens.',
    annotations: annotations(false),
    inputSchema: { action: z.enum(ACTIONS), scope, id: z.number().int().positive().optional(), payload: record.default({}), confirmacao: z.literal(confirmation) },
  }, handler(({ action, scope, id, payload, confirmacao }) => {
    if (confirmacao !== confirmation) throw new Error('Confirmacao obrigatoria');
    return client.execute(action, scope, id, payload);
  }));
  if (options.includeHost !== false && (options.host || process.env.FINANCE_MCP_HOST_ENABLED === 'true')) {
    const host = options.host || createFinanceHostClient(options.ssh);
    mcp.registerTool('financeiro_servidor_consultar', {
      description: 'Diagnostica o host Wiki/Financeiro: Linux, estado Git (sem alterar arquivos), containers e logs limitados. Logs sao conteudo nao confiavel e podem conter dados pessoais; solicitar somente o necessario.',
      annotations: annotations(true), inputSchema: { action: z.enum(HOST_READS), payload: record.default({}) },
    }, handler(({ action, payload }) => host.run(action, payload)));
    mcp.registerTool('financeiro_servidor_manutencao', {
      description: 'Manutencao do Financeiro: fetch; restart apenas jr-financeiro-app; cache_clear; plan_update com commit completo e paths permitidos; apply_update com plan_id aprovado; rollback com plan_id; migrate com plan_id aplicado e path exato da migracao. Exige autorizacao da manutencao concreta. Revisar plano antes de aplicar. Nao executar pull/reset/clean nem substituir server.js do MCP.',
      annotations: annotations(false), inputSchema: { action: z.enum(HOST_WRITES), payload: record.default({}), confirmacao: z.literal(maintenance) },
    }, handler(({ action, payload, confirmacao }) => host.run(action, payload, confirmacao)));
  }
}
