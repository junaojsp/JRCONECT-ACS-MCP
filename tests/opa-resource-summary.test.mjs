import test from 'node:test';
import assert from 'node:assert/strict';
import {
  OPA_RESOURCE_CATALOG,
  querySpecification,
  summarizeOpaResource,
} from '../mcp/integrations/opa-resource-summary.js';

test('catalog contains 12 categories and distinguishes validated GET paths',()=>{
  assert.equal(OPA_RESOURCE_CATALOG.length,12);
  const pending=OPA_RESOURCE_CATALOG.filter(r=>!r.consulta_resumida);
  assert.deepEqual(pending.map(r=>r.recurso),
    ['clientes','contatos','etiquetas','notificacoes','departamentos','motivos','periodos']);
  assert.equal(OPA_RESOURCE_CATALOG.find(r=>r.recurso==='canais').rotas_get_documentadas[0],'/api/v1/canal-comunicacao/');
});

test('only fixed allowlisted routes and valid filters are accepted',()=>{
  assert.deepEqual(querySpecification({recurso:'atendimentos',status:'AG'}),
    {url:'/api/v1/atendimento',filter:{status:'AG'}});
  assert.deepEqual(querySpecification({recurso:'usuarios',status:'I'}),
    {url:'/api/v1/usuario/',filter:{status:'I'}});
  assert.throws(()=>querySpecification({recurso:'departamentos'}),/nao habilitado/);
  assert.throws(()=>querySpecification({recurso:'/admin/secrets'}),/nao habilitado/);
  assert.throws(()=>querySpecification({recurso:'atendimentos',status:'F'}),/AG, EA ou PS/);
  assert.throws(()=>querySpecification({recurso:'usuarios',status:'AG'}),/A ou I/);
  assert.throws(()=>querySpecification({recurso:'mensagens'}),/id_rota invalido/);
  assert.throws(()=>querySpecification({recurso:'contatos'}),/OPA_CONTATOS_PENDENTE/);
  assert.throws(()=>querySpecification({recurso:'contatos',id_rota:'a'.repeat(24)}),/nao permitido/);
});

test('sums multiple pages; returns only aggregated metadata',async()=>{
  const rows=[
    {_id:'a1',status:'EA',setor:'tecnico',nome:'Nome Secreto',cpf:'123.456.789-00'},
    {_id:'a2',status:'EA',setor:'financeiro',mensagem:'conteudo privado'},
    {_id:'a3',status:'EA',setor:'tecnico',telefone:'11912345678'},
  ];
  const result=await summarizeOpaResource(
    async({path,filter,skip,limit})=>{
      assert.equal(path,'/api/v1/atendimento');
      assert.equal(filter.status,'EA');
      return rows.slice(skip,skip+limit);
    },
    {recurso:'atendimentos',status:'EA'},
    {pageSize:2}
  );
  assert.equal(result.total,3);
  assert.deepEqual(result.por_grupo,[
    {codigo:'tecnico',quantidade:2},{codigo:'financeiro',quantidade:1}
  ]);
  assert.equal(result.auditoria.paginacao_completa,true);
  const plain=JSON.stringify(result);
  assert.ok(!plain.includes('Nome Secreto'));
  assert.ok(!plain.includes('123.456'));
  assert.ok(!plain.includes('conteudo privado'));
  assert.ok(!plain.includes('11912345678'));
});

test('requires full pagination, never returns a partial count',async()=>{
  await assert.rejects(
    summarizeOpaResource(async({skip})=>[{_id:'record-'+skip,status:'AG'}],
      {recurso:'atendimentos',status:'AG'}, {pageSize:1,maxPages:2}),
    /Limite de paginas/
  );
});

test('fails if server ignores a status filter',async()=>{
  await assert.rejects(
    summarizeOpaResource(async()=>[{status:'F'}],
      {recurso:'atendimentos',status:'AG'}, {pageSize:2}),
    /Filtro status nao aplicado/
  );
});

test('fails on invalid schema and oversized page',async()=>{
  await assert.rejects(
    summarizeOpaResource(async()=>({data:[]}),
      {recurso:'templates'}, {pageSize:2}),
    /fora do contrato/
  );
  await assert.rejects(
    summarizeOpaResource(async()=>[{},{}],
      {recurso:'templates'}, {pageSize:1}),
    /OPA_LIMITE_IGNORADO/
  );
});

test('messages must be scoped by route ID and return only count',async()=>{
  const id='a'.repeat(24);
  const summary=await summarizeOpaResource(async({filter,skip})=>{
    assert.equal(filter.id_rota,id);
    return skip===0 ? [{id_rota:id,texto:'mensagem secreta'}] : [];
  },{recurso:'mensagens',id_rota:id},{pageSize:2});
  assert.equal(summary.total,1);
  assert.equal(JSON.stringify(summary).includes('mensagem secreta'),false);
});

test('user summaries disclose only type groups',async()=>{
  const summary=await summarizeOpaResource(async({skip})=>skip===0 ? [
    {status:'A',tipo:'bot',nome:'Charlotte'},
    {status:'A',tipo:'user',email:'example@internal.invalid'},
  ] : [],{recurso:'usuarios',status:'A'}, {pageSize:3});
  assert.equal(summary.total,2);
  assert.deepEqual(summary.por_grupo,[
    {codigo:'bot',quantidade:1},{codigo:'user',quantidade:1}
  ]);
  assert.ok(!JSON.stringify(summary).includes('Charlotte'));
});


test('blocks the real ACS anomaly: channels returning four despite limit one',async()=>{
  await assert.rejects(
    summarizeOpaResource(async()=>[
      {status:'A',canal:'Whatsapp'},
      {status:'A',canal:'Telegram'},
      {status:'A',canal:'Messenger'},
      {status:'A',canal:'Instagram'},
    ],{recurso:'canais',status:'A'},{pageSize:1}),
    /OPA_LIMITE_IGNORADO/
  );
});

test('blocks the real ACS anomaly: users returning eight despite limit one',async()=>{
  await assert.rejects(
    summarizeOpaResource(async()=>Array.from({length:8},()=>({status:'A'})),
      {recurso:'usuarios',status:'A'},{pageSize:1}),
    /OPA_LIMITE_IGNORADO/
  );
});

test('does not trust a short page when the API ignores skip',async()=>{
  const seen=[];
  await assert.rejects(
    summarizeOpaResource(async({skip})=>{
      seen.push(skip);
      return [{status:'A',tipo:'user'}];
    },{recurso:'usuarios',status:'A'},{pageSize:100}),
    /OPA_SKIP_NAO_CONFIRMADO/
  );
  assert.deepEqual(seen,[0,1]);
});

test('allows a verified terminal page, and never outputs customer data',async()=>{
  const calls=[];
  const result=await summarizeOpaResource(async({skip,limit})=>{
    calls.push({skip,limit});
    return skip===0 ? [{status:'A',canal:'Whatsapp',phone:'11900000000'}] : [];
  },{recurso:'canais',status:'A'});
  assert.equal(result.total,1);
  assert.equal(result.auditoria.skip_final_verificado,true);
  assert.deepEqual(calls,[{skip:0,limit:100},{skip:1,limit:1}]);
  assert.equal(JSON.stringify(result).includes('11900000000'),false);
});

test('rejects repeated IDs across pages before double counting',async()=>{
  await assert.rejects(
    summarizeOpaResource(async({skip})=>skip===0
      ? [{_id:'abc',status:'EA',setor:'suporte'}]
      : [{_id:'abc',status:'EA',setor:'suporte'}],
      {recurso:'atendimentos',status:'EA'},{pageSize:1,maxPages:2}),
    /OPA_PAGINA_REPETIDA/
  );
});

test('fails safely if full pages lack stable record IDs',async()=>{
  await assert.rejects(
    summarizeOpaResource(async()=>[{status:'EA'}],
      {recurso:'atendimentos',status:'EA'},{pageSize:1,maxPages:2}),
    /OPA_PAGINACAO_SEM_ID/
  );
});

test('requires id_rota in message result to verify server-side filter',async()=>{
  await assert.rejects(
    summarizeOpaResource(async()=>[{texto:'no id field'}],
      {recurso:'mensagens',id_rota:'b'.repeat(24)},{pageSize:100}),
    /Filtro id_rota nao aplicado/
  );
});
