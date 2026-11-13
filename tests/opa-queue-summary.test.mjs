import test from 'node:test';
import assert from 'node:assert/strict';
import {aggregateOpaQueue} from '../mcp/integrations/opa-queue-summary.js';

test('counts two pages by department without returning customer information', async () => {
  const rows = [
    {_id:'id-a',status:'AG',setor:'financeiro',nome:'Cliente 1',cpf:'123'},
    {_id:'id-b',status:'AG',setor:'suporte',nome:'Cliente 2'},
    {_id:'id-c',status:'AG',setor:'financeiro',telefone:'555'},
  ];
  const calls = [];
  const result = await aggregateOpaQueue(async ({limit,skip}) => {
    calls.push({limit,skip});
    return rows.slice(skip,skip+limit);
  },{pageSize:2});
  assert.equal(result.aguardando,3);
  assert.deepEqual(result.por_setor,[
    {setor:'financeiro',aguardando:2},
    {setor:'suporte',aguardando:1},
  ]);
  assert.deepEqual(calls,[{limit:2,skip:0},{limit:2,skip:2},{limit:1,skip:3}]);
  assert.equal(result.auditoria.paginacao_completa,true);
  assert.equal(result.auditoria.registros_unicos,3);
  assert.equal(result.auditoria.fim_verificado,'skip');
  assert.equal(JSON.stringify(result).includes('Cliente'),false);
  assert.equal(JSON.stringify(result).includes('123'),false);
  assert.equal(JSON.stringify(result).includes('555'),false);
  assert.equal(JSON.stringify(result).includes('id-a'),false);
});

test('AG skip=0/1 returns distinct records, then page is empty', async () => {
  const rows=[{_id:'mongo-a',status:'AG'}, {_id:'mongo-b',status:'AG'}];
  const calls=[];
  const result=await aggregateOpaQueue(async ({skip,limit})=>{
    calls.push(skip);
    return rows.slice(skip,skip+limit);
  },{pageSize:1});
  assert.equal(result.aguardando,2);
  assert.deepEqual(calls,[0,1,2]);
  assert.equal(result.auditoria.fim_verificado,'pagina_vazia');
});

test('rejects responses when status AG filter is ignored',async()=>{
  await assert.rejects(
    aggregateOpaQueue(async()=>[{_id:'id-a',status:'F',setor:'suporte'}],{pageSize:2}),
    /Filtro AG nao confirmado/
  );
});

test('rejects repeated pages even if the API reports AG consistently',async()=>{
  await assert.rejects(
    aggregateOpaQueue(async()=>[{_id:'same-id',status:'AG',setor:'suporte'}],{pageSize:1,maxPages:2}),
    /OPA_FILA_PAGINA_REPETIDA/
  );
});

test('rejects duplicate attendance IDs within a single page',async()=>{
  await assert.rejects(
    aggregateOpaQueue(async()=>[
      {_id:'duplicate',status:'AG'},
      {_id:'duplicate',status:'AG'},
    ],{pageSize:3}),
    /OPA_FILA_PAGINA_REPETIDA/
  );
});

test('rejects short page if skip after the last row still yields records',async()=>{
  const calls=[];
  await assert.rejects(
    aggregateOpaQueue(async ({skip})=>{
      calls.push(skip);
      return [{_id:'same',status:'AG'}];
    },{pageSize:5}),
    /OPA_FILA_SKIP_NAO_CONFIRMADO/
  );
  assert.deepEqual(calls,[0,1]);
});

test('rejects an incomplete pagination instead of reporting a partial total',async()=>{
  await assert.rejects(
    aggregateOpaQueue(async ({skip})=>[{_id:'id-'+skip,status:'AG'}],{pageSize:1,maxPages:2}),
    /Paginacao incompleta/
  );
});

test('rejects an ignored limit or unexpected page schema',async()=>{
  await assert.rejects(
    aggregateOpaQueue(async()=>[{_id:'a',status:'AG'},{_id:'b',status:'AG'}],{pageSize:1}),
    /paginacao invalida/
  );
  await assert.rejects(
    aggregateOpaQueue(async()=>({data:[]}),{pageSize:2}),
    /paginacao invalida/
  );
  await assert.rejects(
    aggregateOpaQueue(async({skip})=>skip ? {} : [{_id:'a',status:'AG'}],{pageSize:2}),
    /OPA_FILA_SKIP_NAO_CONFIRMADO/
  );
});

test('rejects missing or unstable attendance IDs',async()=>{
  await assert.rejects(
    aggregateOpaQueue(async()=>[{status:'AG'}],{pageSize:3}),
    /OPA_FILA_SEM_ID/
  );
  await assert.rejects(
    aggregateOpaQueue(async()=>[{_id:{value:'abc'},status:'AG'}],{pageSize:3}),
    /OPA_FILA_SEM_ID/
  );
});

test('categorizes unknown or structured department without exposing its label',async()=>{
  const rows=[
    {_id:'abc',status:'AG',setor:{_id:'setor-4',nome:'Suporte'}},
    {_id:'def',status:'AG',setor:{nome:'Financeiro'}},
  ];
  const result=await aggregateOpaQueue(async ({skip,limit})=>
    rows.slice(skip,skip+limit),{pageSize:3});
  assert.deepEqual(result.por_setor,[
    {setor:'sem_setor_identificado',aguardando:1},
    {setor:'setor-4',aguardando:1}
  ]);
  assert.equal(JSON.stringify(result).includes('Financeiro'),false);
});

test('returns zero only for valid empty first page',async()=>{
  let called=0;
  const result=await aggregateOpaQueue(async()=>{called++;return [];});
  assert.equal(called,1);
  assert.equal(result.aguardando,0);
  assert.equal(result.auditoria.fim_verificado,'pagina_vazia');
});

test('validates pagination settings before any API calls',async()=>{
  let called=false;
  await assert.rejects(aggregateOpaQueue(async()=>{called=true;return []},{pageSize:0}),/Limites/);
  await assert.rejects(aggregateOpaQueue(async()=>{called=true;return []},{maxPages:101}),/Limites/);
  assert.equal(called,false);
});
