import test from 'node:test';
import assert from 'node:assert/strict';
import { auditWiki, normalizePath } from './wiki-audit-check.mjs';

test('normaliza links de Wiki com prefixo de idioma',()=>{
  assert.equal(normalizePath('/pt-br/atendimento-suporte/'), 'atendimento-suporte');
  assert.equal(normalizePath('/home'), 'home');
});
test('detecta paginas faltantes e ancoras inexistentes',()=>{
  const out=auditWiki([
    {id:1,path:'home',links:['/atendimento-suporte','/nunca-existiu']},
    {id:15,path:'atendimento-suporte',links:['/atendimento-suporte/atendimento']},
    {id:24,path:'atendimento-suporte/atendimento',links:['/home','#faltando'],anchors:['regras-gerais']}
  ]);
  assert.equal(out.errors.filter(x=>x.type==='missing-target').length,1);
  assert.equal(out.errors.filter(x=>x.type==='missing-anchor').length,1);
});
test('links existentes, query e anchors validos nao sao erros',()=>{
  const out=auditWiki([
    {id:1,path:'home',links:['/atendimento-suporte']},
    {id:15,path:'atendimento-suporte',links:['/atendimento-suporte/atendimento']},
    {id:24,path:'atendimento-suporte/atendimento',anchors:['regras-gerais'],links:['/home','/home?x=1','#regras-gerais']}
  ]);
  assert.deepEqual(out.errors,[]);
});
test('sinaliza faltantes no indice mas nao faz edicoes',()=>{
  const pages=[
    {id:1,path:'home',links:['/atendimento-suporte']},
    {id:15,path:'atendimento-suporte',links:[]},
    {id:63,path:'atendimento-suporte/utilizacao-ramais',links:['/home']}
  ];
  const snapshot=JSON.stringify(pages);
  const out=auditWiki(pages);
  assert.equal(out.warnings.filter(x=>x.type==='not-in-department-index').length,1);
  assert.equal(JSON.stringify(pages),snapshot);
});
