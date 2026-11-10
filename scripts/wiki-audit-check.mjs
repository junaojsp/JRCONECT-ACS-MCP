#!/usr/bin/env node
/**
 * Auditoria offline de links de Wiki.js — somente leitura.
 * Entrada: JSON sanitizado de metadados e links, sem conteudo HTML ou credenciais:
 * [
 *   {"id": 15, "path": "atendimento-suporte", "links": ["/home", "/atendimento-suporte/atendimento"]},
 *   {"id": 24, "path": "atendimento-suporte/atendimento", "anchors": ["regras-gerais"], "links": ["/home"]}
 * ]
 *
 * Exemplo: node scripts/wiki-audit-check.mjs inventario-sanitizado.json
 * Codigo de saida 1 em referencias internas quebradas; zero em referencias validas.
 */
import { readFileSync } from 'node:fs';
import { pathToFileURL } from 'node:url';

export const normalizePath = (value) => {
  const path = String(value || '').trim().replace(/^\/+|\/+$/g, '');
  return decodeURIComponent(path).replace(/^pt-br\//, '');
};

export function auditWiki(pages) {
  if (!Array.isArray(pages)) throw new TypeError('Inventario deve ser um array');
  const byPath = new Map();
  const problems = [];
  const warnings = [];
  for (const page of pages) {
    if (!Number.isInteger(page.id) || !page.path || !Array.isArray(page.links ?? [])) {
      throw new TypeError('Pagina invalida: fornecer id, path e links');
    }
    const key = normalizePath(page.path);
    if (byPath.has(key)) problems.push({type:'duplicate-path',path:key,ids:[byPath.get(key).id,page.id]});
    byPath.set(key,page);
  }

  for (const page of pages) {
    const sources = page.links ?? [];
    const exact = new Map();
    for (const raw of sources) {
      if (typeof raw !== 'string') throw new TypeError('Link deve ser texto');
      if (!raw.startsWith('/') && !raw.startsWith('#')) continue;
      const [withoutHash, fragment = ''] = raw.split('#', 2);
      const pathname = withoutHash.split('?')[0];
      const targetPath = pathname ? normalizePath(pathname) : normalizePath(page.path);
      const target = byPath.get(targetPath);
      if (!target) {
        problems.push({type:'missing-target',source:page.path,link:raw});
        continue;
      }
      if (fragment && Array.isArray(target.anchors) && !target.anchors.includes(fragment)) {
        problems.push({type:'missing-anchor',source:page.path,link:raw});
      }
      const normalized = pathname ? '/' + targetPath : '';
      if (normalized) exact.set(normalized,(exact.get(normalized)||0)+1);
    }
    for (const [target,count] of exact) {
      if (count>1) warnings.push({type:'repeated-target',source:page.path,target,count,note:'Pode ser intencional para links com ancoras diferentes'});
    }
  }

  const atendimentoPaths = pages.filter(p=>normalizePath(p.path).startsWith('atendimento-suporte/'));
  const atendimento = byPath.get('atendimento-suporte');
  if (atendimento) {
    const targets = new Set((atendimento.links??[]).filter(l=>l.startsWith('/')).map(l=>normalizePath(l.split(/[?#]/)[0])));
    for (const page of atendimentoPaths) {
      if (!targets.has(normalizePath(page.path))) {
        warnings.push({type:'not-in-department-index',page:page.path,index:'atendimento-suporte'});
      }
    }
  } else warnings.push({type:'missing-department-index',path:'atendimento-suporte'});

  for (const path of ['painel-wiki-moderno','painel-moderno-jrconect','painel-moderno-jrconect-v2']) {
    if(byPath.has(path)) warnings.push({type:'legacy-preview-review',path,note:'Somente revisar/arquivar apos confirmacao; nao excluir automaticamente'});
  }
  return {pages:pages.length,errors:problems,warnings};
}

function main() {
  const file=process.argv[2];
  if (!file) {
    process.stderr.write('Uso: node scripts/wiki-audit-check.mjs inventario-sanitizado.json\n');
    process.exitCode=2;
    return;
  }
  const data=JSON.parse(readFileSync(file,'utf8'));
  const result=auditWiki(data);
  process.stdout.write(JSON.stringify(result,null,2)+'\n');
  if(result.errors.length) process.exitCode=1;
}

if(process.argv[1] && import.meta.url===pathToFileURL(process.argv[1]).href) main();
