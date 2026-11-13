// Aggregate Opa! Suite waiting queue without exposing customer records.
// The Opa documentation lists AG as "aguardando" and supports a status filter.
import { readFileSync } from 'node:fs';
import { request } from 'node:https';

const PAGE_SIZE = 100;
const MAX_PAGES = 100;
const MAX_RESPONSE_BYTES = 5 * 1024 * 1024;

function departmentKey(row) {
  const value = row.setor ?? row.departamento;
  const id = (value && typeof value === 'object') ? (value._id ?? value.id) : value;
  return typeof id === 'string' && /^[A-Za-z0-9_-]{1,80}$/.test(id)
    ? id : 'sem_setor_identificado';
}

export async function aggregateOpaQueue(fetchPage, {pageSize = PAGE_SIZE, maxPages = MAX_PAGES} = {}) {
  if (typeof fetchPage !== 'function') throw new Error('fetchPage ausente');
  if (!Number.isInteger(pageSize) || pageSize < 1 || pageSize > 200 ||
      !Number.isInteger(maxPages) || maxPages < 1 || maxPages > 100) {
    throw new Error('Limites de paginacao invalidos');
  }
  const setores = new Map();
  const seenIds = new Set();
  let total = 0;
  for (let pageNumber = 0; pageNumber < maxPages; pageNumber++) {
    const rows = await fetchPage({limit: pageSize, skip: pageNumber * pageSize});
    if (!Array.isArray(rows) || rows.length > pageSize) {
      throw new Error('Resposta de atendimentos com paginacao invalida');
    }
    for (const row of rows) {
      // Never invent queue figures if the API ignores a filter or changes schema.
      if (!row || typeof row !== 'object' || Array.isArray(row) || row.status !== 'AG') {
        throw new Error('Filtro AG nao confirmado na resposta do Opa; total indisponivel');
      }
      // The ACS probe observed distinct Mongo _id values at skip=0 and skip=1.
      // Keep these identifiers in memory ONLY to detect repeated pages.
      const id = row._id ?? row.id;
      if (typeof id !== 'string' || !/^[A-Za-z0-9_-]{1,128}$/.test(id)) {
        throw new Error('OPA_FILA_SEM_ID: nao e possivel validar unicidade de atendimentos');
      }
      if (seenIds.has(id)) {
        throw new Error('OPA_FILA_PAGINA_REPETIDA: atendimento duplicado na paginacao');
      }
      seenIds.add(id);
      const setor = departmentKey(row);
      setores.set(setor, (setores.get(setor) || 0) + 1);
      total++;
    }
    if (rows.length < pageSize) {
      // A short page is NOT proof of completion: some Opa resources ignore
      // limit/skip. Verify the position immediately after the last row.
      if (rows.length > 0) {
        const next = await fetchPage({
          limit: 1, skip: pageNumber * pageSize + rows.length,
        });
        if (!Array.isArray(next) || next.length !== 0) {
          throw new Error('OPA_FILA_SKIP_NAO_CONFIRMADO: fim da fila nao comprovado');
        }
      }
      return {
        sistema: 'Opa Suite',
        criterio: 'status AG (aguardando)',
        aguardando: total,
        por_setor: [...setores].map(([setor, aguardando]) => ({setor, aguardando}))
          .sort((a, b) => b.aguardando - a.aguardando || a.setor.localeCompare(b.setor)),
        consultado_em: new Date().toISOString(),
        auditoria: {paginas: pageNumber + 1, registros_analisados: total, registros_unicos: seenIds.size, paginacao_completa: true, fim_verificado: rows.length ? 'skip' : 'pagina_vazia'},
        observacao: 'Consulta paginada e nao transacional; alteracoes durante a leitura podem variar a contagem. Nenhum dado pessoal e retornado.',
      };
    }
  }
  throw new Error('Paginacao incompleta; nenhuma contagem parcial foi retornada');
}

export function createOpaQueueClient({
  baseUrl = process.env.OPA_API_BASE_URL,
  tokenFile = '/run/secrets/opa-api-token',
} = {}) {
  if (!baseUrl) throw new Error('OPA_API_BASE_URL ausente');
  const base = new URL(baseUrl);
  if (base.protocol !== 'https:' || base.username || base.password ||
      base.pathname !== '/' || base.search || base.hash) {
    throw new Error('OPA_API_BASE_URL invalida');
  }
  const url = new URL('/api/v1/atendimento', base);

  async function fetchPage({limit, skip}) {
    const token = readFileSync(tokenFile, 'utf8').trim();
    if (!token || /\s/.test(token)) throw new Error('Token Opa ausente ou invalido');
    const body = JSON.stringify({filter: {status: 'AG'}, options: {limit, skip}});
    return new Promise((resolve, reject) => {
      const req = request(url, {
        method: 'GET',
        timeout: 15000,
        headers: {
          Authorization: 'Bearer ' + token,
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'Content-Length': Buffer.byteLength(body),
        },
      }, res => {
        const parts = [];
        let bytes = 0;
        res.on('data', chunk => {
          bytes += chunk.length;
          if (bytes > MAX_RESPONSE_BYTES) {
            req.destroy(new Error('Resposta Opa excedeu limite'));
            return;
          }
          parts.push(chunk);
        });
        res.on('end', () => {
          if (res.statusCode !== 200) return reject(new Error('Opa API HTTP ' + res.statusCode));
          try {
            const parsed = JSON.parse(Buffer.concat(parts).toString('utf8'));
            if (parsed.status !== 'success' || !Array.isArray(parsed.data)) {
              throw new Error('Resposta Opa com estrutura inesperada');
            }
            resolve(parsed.data);
          } catch (error) {
            reject(error);
          }
        });
        res.on('error', reject);
      });
      req.on('timeout', () => req.destroy(new Error('Timeout na API Opa')));
      req.on('error', reject);
      req.end(body);
    });
  }
  return {resumo: () => aggregateOpaQueue(fetchPage)};
}
