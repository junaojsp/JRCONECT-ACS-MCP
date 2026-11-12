import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { registerIxcOsSupportTools } from './ixc-os-support-tools.js';

const row = {id:'123',id_assunto:'7',status:'F',data_abertura:'2026-11-01 10:00:00',mensagem:'Pedido de cancelamento',mensagem_resposta:'Concluido',cpf:'omitido',senha:'omitida'};
function setup(response) {
  const tools = new Map(), calls = [];
  registerIxcOsSupportTools({registerTool:(name,schema,fn)=>tools.set(name,{schema,fn})}, {
    list:async (...args)=>{calls.push(args); if(response instanceof Error) throw response; return response;},
  });
  return {tools,calls,call:async(name,input)=>{
    const r=await tools.get(name).fn(input);
    return r.isError ? r : JSON.parse(r.content[0].text);
  }};
}
const list='ixc_os_listar_por_assunto', detail='ixc_os_consultar_detalhes';
test('preserves original tools and adds two read-only tools',()=>{
  const {tools}=setup({});
  assert.deepEqual([...tools.keys()],['ixc_os_localizar_assunto','ixc_os_contagem_assunto_status',list,detail]);
  for(const name of [list,detail]) assert.equal(tools.get(name).schema.annotations.readOnlyHint,true);
});
test('lists by exact subject and optional status, with bounded pagination and projection',async()=>{
  const t=setup({total:'21',registros:[row]});
  const r=await t.call(list,{id_assunto:'7',status:'F',pagina:2,limite:10});
  assert.equal(r.proxima_pagina,3);
  assert.deepEqual(t.calls,[['os',{page:2,limit:10,filters:[{field:'id_assunto',oper:'=',value:'7'},{field:'status',oper:'=',value:'F'}]}]]);
  assert.equal(r.registros[0].id,'123');
  for(const key of ['mensagem','cpf','senha']) assert(!(key in r.registros[0]));
});
test('rejects ignored filters without leaking any row',async()=>{
  for(const bad of [{...row,id_assunto:'8'},{...row,status:'A'}]) {
    const t=setup({total:1,registros:[bad]});
    assert.equal((await t.call(list,{id_assunto:'7',status:'F'})).isError,true);
  }
  for(const bad of [{...row,id:'124'},{...row,id_assunto:'8'}]) {
    assert.equal((await setup({total:1,registros:[bad]}).call(detail,{id_os:'123',id_assunto:'7'})).isError,true);
  }
});
test('detail uses equality, not the default id >= filter; only selected fields returned',async()=>{
  const t=setup({total:'1',registros:[row]});
  const r=await t.call(detail,{id_os:'123',id_assunto:'7'});
  assert.deepEqual(t.calls[0],['os',{page:1,limit:1,filters:[{field:'id',oper:'=',value:'123'},{field:'id_assunto',oper:'=',value:'7'}]}]);
  assert.equal(r.os.descricao,row.mensagem);
  assert.equal(r.os.solucao,row.mensagem_resposta);
  assert.equal(r.historico_completo,false);
  assert(!JSON.stringify(r).includes('omitid'));
});
test('missing, empty and truncated fields remain distinguishable',async()=>{
  let r=await setup({total:1,registros:[{...row,mensagem:undefined,mensagem_resposta:''}]}).call(detail,{id_os:'123',id_assunto:'7'});
  assert.equal(r.os.descricao,null); assert.equal(r.os.solucao,'');
  assert.deepEqual(r.campos_ausentes,['mensagem']);
  r=await setup({total:1,registros:[{...row,mensagem:'x'.repeat(12001)}]}).call(detail,{id_os:'123',id_assunto:'7'});
  assert.equal(r.os.descricao.length,12000); assert.deepEqual(r.campos_truncados,['mensagem']);
});
test('not found differs from errors and malformed responses',async()=>{
  assert.equal((await setup({total:0}).call(detail,{id_os:'123',id_assunto:'7'})).encontrado,false);
  for(const result of [null,{total:1},{total:1,registros:[]},{total:2,registros:[row]}, {registros:[row]}, {total:null,registros:[]},{total:0,registros:[row]},{status:'error',total:0},new Error('secret credential')]) {
    const r=await setup(result).call(detail,{id_os:'123',id_assunto:'7'});
    assert.equal(r.isError,true); assert(!JSON.stringify(r).includes('secret credential'));
  }
});
test('invalid inputs do not reach client',async()=>{
  for(const [name,input] of [[list,{id_assunto:'7',limite:21}],[list,{id_assunto:'7',pagina:0}],[list,{id_assunto:'7',status:'F OR 1=1'}],[detail,{id_os:'0',id_assunto:'7'}],[detail,{id_os:'123'}],[list,{}]]) {
    const t=setup({total:0}); assert.equal((await t.call(name,input)).isError,true); assert.equal(t.calls.length,0);
  }
});
test('list rejects excess rows and malformed identifiers',async()=>{
  for(const result of [{total:2,registros:[row,row]}, {total:1,registros:[{...row,id:'invalid'}]}, {total:1,registros:[null]}]) {
    assert.equal((await setup(result).call(list,{id_assunto:'7',limite:1})).isError,true);
  }
});
test('existing count and subject search still work',async()=>{
  const r=await setup({total:1,registros:[row]}).call('ixc_os_contagem_assunto_status',{id_assunto:'7',status:'F'});
  assert.equal(r.total,1);
  const s=await setup({total:1,registros:[{id:'7',assunto:'Cancelamento'}]}).call('ixc_os_localizar_assunto',{termo:'cancelamento'});
  assert.deepEqual(s.encontrados,[{id:'7',assunto:'Cancelamento'}]);
});
test('full IXC registrar preserves all eleven previous names and adds exactly two',async()=>{
  const {registerIxcWebserviceTools}=await import('./ixc-webservice-tools.js');
  const names=[];
  registerIxcWebserviceTools({registerTool:name=>names.push(name)},{});
  const expected=['ixc_catalogo_relatorios','ixc_relatorio_contagem','ixc_voip_listar_contratos_produto74','ixc_voip_consultar_contrato','ixc_os_localizar_assunto','ixc_os_contagem_assunto_status','ixc_clientes','ixc_contratos','ixc_financeiro','ixc_os_resumo','ixc_diagnostico_api',list,detail];
  assert.deepEqual(names.toSorted(),expected.toSorted());
  assert.equal(new Set(names).size,13);
});
