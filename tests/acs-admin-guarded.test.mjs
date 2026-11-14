import test from 'node:test';
import assert from 'node:assert/strict';
import { acsAdminAuthorized, registerAcsAdminTools, ACS_ADMIN_ACTIONS } from '../mcp/integrations/acs-admin-guarded-tools.js';

const user = 'authorized-test-sub';
const env = {ACS_ADMIN_MCP_ENABLED:'true', ACS_ADMIN_ALLOWED_SUB:user};
const identity = {azp:'chatgpt-mcp',sub:user,realm_access:{roles:['acs-mcp-admin']}};
const ACTION = 'reiniciar_monitor_acs';
const PHRASE = 'CONFIRMO REINICIAR MONITOR ACS';

function setup(options={}) {
  const handlers=new Map();
  let principal=identity;
  let time=1000000;
  const audit=[];
  const executed=[];
  const mcp={registerTool:(name,spec,fn)=>handlers.set(name,{spec,fn})};
  const helper=async request=>{
    executed.push(request);
    return {ok:true,sensitive:'DO_NOT_OUTPUT_SECRET'};
  };
  const recorder=async item=>audit.push(item);
  const registered=registerAcsAdminTools(
    mcp,()=>principal,options.helper??helper,options.audit??recorder,
    {env:options.env??env,now:()=>time}
  );
  return {
    registered,handlers,executed,audit,
    setPrincipal:p=>{principal=p;},
    moveTime:ms=>{time+=ms;},
    plan:params=>handlers.get('acs_admin_planejar').fn(params),
    execute:params=>handlers.get('acs_admin_executar').fn(params),
  };
}

function data(result) {
  return JSON.parse(result.content[0].text);
}

test('requires flag, allowlisted subject, valid OAuth client AND realm role',()=>{
  assert.equal(acsAdminAuthorized(identity,env),true);
  for(const p of [
    null,
    {...identity,azp:'opa-suite'},
    {...identity,sub:'unlisted'},
    {...identity,realm_access:{roles:[]}},
    {...identity,realm_access:{roles:['acs-monitor']}},
  ]) assert.equal(acsAdminAuthorized(p,env),false);
  assert.equal(acsAdminAuthorized(identity,{...env,ACS_ADMIN_MCP_ENABLED:'false'}),false);
  assert.equal(acsAdminAuthorized(identity,{...env,ACS_ADMIN_ALLOWED_SUB:''}),false);
});

test('disabled flag registers nothing; helper and audit are mandatory',()=>{
  assert.equal(registerAcsAdminTools({registerTool:()=>{throw Error('NO')}},()=>identity,null,null,
    {env:{...env,ACS_ADMIN_MCP_ENABLED:'false'}}),0);
  assert.throws(()=>registerAcsAdminTools({registerTool:()=>{}},()=>identity,null,async()=>{},
    {env}),/OBRIGATORIOS/);
  assert.throws(()=>registerAcsAdminTools({registerTool:()=>{}},()=>identity,async()=>{},null,
    {env}),/OBRIGATORIOS/);
});

test('only a fixed non-core monitor restart operation is available',async ()=>{
  assert.deepEqual(Object.keys(ACS_ADMIN_ACTIONS),[ACTION]);
  assert.equal(ACS_ADMIN_ACTIONS[ACTION].destino,'jrconect-acs-monitor-teste');
  const s=setup();
  assert.equal(s.registered,2);
  assert.deepEqual([...s.handlers.keys()],['acs_admin_planejar','acs_admin_executar']);
  const blocked=await s.plan({acao:'reiniciar_jrconect_mcp'});
  assert.equal(blocked.isError,true);
  const plan=data(await s.plan({acao:ACTION}));
  assert.equal(plan.executado,false);
  assert.equal(plan.destino,'jrconect-acs-monitor-teste');
  assert.match(plan.ticket,/^[a-f0-9]{48}$/);
  assert.equal(s.executed.length,0);
});

test('human confirmation is explicit parameter, ticket is time-limited and one-use',async ()=>{
  const s=setup();
  const plan=data(await s.plan({acao:ACTION}));
  let bad=await s.execute({ticket:plan.ticket,confirmacao:'sim'});
  assert.equal(bad.isError,true);
  assert.equal(s.executed.length,0);
  let ok=data(await s.execute({ticket:plan.ticket,confirmacao:PHRASE}));
  assert.equal(ok.executado,true);
  assert.equal(s.executed.length,1);
  assert.equal(s.executed[0].target,'jrconect-acs-monitor-teste');
  assert.equal(s.audit.length,2);
  assert.ok(!JSON.stringify(ok).includes('DO_NOT_OUTPUT_SECRET'));
  const replay=await s.execute({ticket:plan.ticket,confirmacao:PHRASE});
  assert.equal(replay.isError,true);
  assert.equal(s.executed.length,1);
});

test('ticket cannot cross OAuth subjects or expire',async ()=>{
  const s=setup({env:{ACS_ADMIN_MCP_ENABLED:'true',ACS_ADMIN_ALLOWED_SUB:'authorized-test-sub,second-sub'}});
  const plan=data(await s.plan({acao:ACTION}));
  s.setPrincipal({...identity,sub:'second-sub'});
  const cross=await s.execute({ticket:plan.ticket,confirmacao:PHRASE});
  assert.equal(cross.isError,true);
  s.setPrincipal(identity);
  s.moveTime(120001);
  const expired=await s.execute({ticket:plan.ticket,confirmacao:PHRASE});
  assert.equal(expired.isError,true);
  assert.equal(s.executed.length,0);
});

test('audit failure blocks action; agent failure returns no internal details',async()=>{
  const s=setup({audit:async()=>{throw Error('secret DB credentials')}});
  const p=data(await s.plan({acao:ACTION}));
  const denied=await s.execute({ticket:p.ticket,confirmacao:PHRASE});
  assert.equal(denied.isError,true);
  assert.equal(denied.content[0].text,'ACS_ADMIN_AUDITORIA_INDISPONIVEL');
  assert.equal(s.executed.length,0);

  const s2=setup({helper:async()=>{throw Error('secret /var/run/docker.sock')}});
  const plan=data(await s2.plan({acao:ACTION}));
  const err=await s2.execute({ticket:plan.ticket,confirmacao:PHRASE});
  assert.equal(err.isError,true);
  assert.equal(err.content[0].text,'ACS_ADMIN_AGENTE_INDISPONIVEL');
});

test('non-authorized bearer cannot produce plans or run actions',async ()=>{
  const s=setup();
  s.setPrincipal({sub:user,azp:'opa-suite',realm_access:{roles:['acs-mcp-admin']}});
  const res=await s.plan({acao:ACTION});
  assert.equal(res.content[0].text,'ACS_ADMIN_ACESSO_NEGADO');
  assert.equal(s.audit.length,0);
});
