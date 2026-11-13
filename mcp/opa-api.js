import { readFileSync } from 'node:fs';
import { request } from 'node:https';

const endpoint = 'https://opa.jrconect.com/api/v1/atendimento';

function consultarOpa(token, limite) {
  return new Promise((resolve, reject) => {
    const body = JSON.stringify({ filter: {}, options: { limit: limite } });
    const req = request(endpoint, {
      method: 'GET',
      timeout: 15000,
      headers: {
        Authorization: `Bearer ${token}`,
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'Content-Length': Buffer.byteLength(body),
      },
    }, res => {
      // Nao seguir redirecionamentos com Authorization.
      let data = '';
      res.setEncoding('utf8');
      res.on('data', chunk => {
        data += chunk;
        if (data.length > 1024 * 1024) req.destroy(new Error('OPA_API_RESPOSTA_GRANDE'));
      });
      res.on('end', () => {
        if (res.statusCode !== 200) return reject(new Error(`OPA_API_HTTP_${res.statusCode}`));
        try { resolve(JSON.parse(data)); } catch { reject(new Error('OPA_API_JSON_INVALIDO')); }
      });
    });
    req.on('timeout', () => req.destroy(new Error('OPA_API_TIMEOUT')));
    req.on('error', reject);
    req.end(body);
  });
}

export async function listarAtendimentos({ limite = 1 } = {}) {
  if (!Number.isInteger(limite) || limite < 1 || limite > 10) {
    throw new Error('Limite invalido');
  }
  const token = readFileSync('/run/secrets/opa-api-token', 'utf8').trim();
  if (!token) throw new Error('Token da API do Opa nao configurado');
  const payload = await consultarOpa(token, limite);
  if (payload.status !== 'success' || !Array.isArray(payload.data)) {
    throw new Error('OPA_API_RESPOSTA_INVALIDA');
  }
  // Somente metadados operacionais. Exclui observacoes, descricao,
  // dados de contato, documentos e IDs de clientes.
  return payload.data.slice(0, limite).map(item => ({
    protocolo: item.protocolo ?? null,
    status: item.status ?? null,
    canal: item.canal ?? null,
    setor: item.setor ?? null,
    origem: item.origem ?? null,
    data: item.date ?? null,
    fim: item.fim ?? null,
  }));
}
