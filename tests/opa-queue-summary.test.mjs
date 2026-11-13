import test from 'node:test';
import assert from 'node:assert/strict';
import {aggregateOpaQueue} from '../mcp/integrations/opa-queue-summary.js';

test('counts the queue by department without returning customer information', async () => {
  const rows = [
    {status:'AG',setor:'financeiro',nome:'Cliente 1',cpf:'123'},
    {status:'AG',setor:'suporte',nome:'Cliente 2'},
    {status:'AG',setor:'financeiro',telefone:'555'},
  ];
  const result = await aggregateOpaQueue(async ({limit,skip}) => rows.slice(skip,skip+limit),{pageSize:2});
  assert.equal(result.aguardando,3);
  assert.deepEqual(result.por_setor,[
    {setor:'financeiro',aguardando:2},
    {setor:'suporte',aguardando:1},
  ]);
  assert.equal(result.auditoria.paginacao_completa,true);
  assert.equal(JSON.stringify(result).includes('Cliente'),false);
  assert.equal(JSON.stringify(result).includes('123'),false);
  assert.equal(JSON.stringify(result).includes('555'),false);
});

test('rejects responses when status filter is ignored',async()=>{
  await assert.rejects(
    aggregateOpaQueue(async()=>[{status:'F',setor:'suporte'}],{pageSize:2}),
    /Filtro AG nao confirmado/
  );
});

test('rejects an incomplete paginated response instead of reporting a partial total',async()=>{
  await assert.rejects(
    aggregateOpaQueue(async()=>[{status:'AG',setor:'suporte'}],{pageSize:1,maxPages:2}),
    /Paginacao incompleta/
  );
});

test('rejects oversized pages and unexpected schema',async()=>{
  await assert.rejects(
    aggregateOpaQueue(async()=>[{status:'AG'},{status:'AG'}],{pageSize:1}),
    /paginacao invalida/
  );
  await assert.rejects(
    aggregateOpaQueue(async()=>({data:[]}),{pageSize:2}),
    /paginacao invalida/
  );
});

test('categorizes unknown or structured department without exposing its label',async()=>{
  const result=await aggregateOpaQueue(async()=>[
    {status:'AG',setor:{_id:'setor-4',nome:'Suporte'}},
    {status:'AG',setor:{nome:'Financeiro'}},
  ],{pageSize:3});
  assert.deepEqual(result.por_setor,[
    {setor:'sem_setor_identificado',aguardando:1},
    {setor:'setor-4',aguardando:1}
  ]);
});
