import test from 'node:test';
import assert from 'node:assert/strict';
import {
  acsMonitorAuthorized, collectAcsResources, collectAcsIntegrationStatuses,
  registerAcsMonitorTools
} from '../mcp/integrations/acs-monitor-central-tools.js';

const USER = 'b33b099a-c5c8-499b-bc0d-4cd9d4d9a315';
const env = {ACS_MONITOR_MCP_ENABLED:'true', ACS_MONITOR_ALLOWED_SUB:USER};
const authorized = {sub:USER, azp:'chatgpt-mcp', realm_access:{roles:['acs-mcp-admin']}};

test('ACS role requires enabled flag, allowlist, Keycloak azp and role', () => {
  assert.equal(acsMonitorAuthorized(authorized, env), true);
  assert.equal(acsMonitorAuthorized(authorized, {...env, ACS_MONITOR_MCP_ENABLED:'false'}), false);
  assert.equal(acsMonitorAuthorized(authorized, {...env, ACS_MONITOR_ALLOWED_SUB:''}), false);
  assert.equal(acsMonitorAuthorized({...authorized,sub:'other'}, env), false);
  assert.equal(acsMonitorAuthorized({...authorized,azp:'opa-suite'}, env), false);
  assert.equal(acsMonitorAuthorized({...authorized,realm_access:{roles:[]}}, env), false);
  assert.equal(acsMonitorAuthorized(null, env), false);
});

test('exactly two tools are registered, with no exposed write functions', () => {
  const map = new Map();
  const mcp = {registerTool:(n,c,fn)=>map.set(n,fn)};
  let identity = authorized;
  let run = 0;
  const registered = registerAcsMonitorTools(mcp,()=>identity,{
    env, resources:()=>{run++;return {uptime_horas:4}}, statuses:async()=>[{acessivel:true}]
  });
  assert.equal(registered, 2);
  assert.deepEqual([...map.keys()].sort(),['acs_recursos_host','acs_status_integracoes']);
  identity = {...authorized,realm_access:{roles:[]}};
  return map.get('acs_recursos_host')().then(res=>{
    assert.equal(res.isError,true);
    assert.equal(run,0);
    identity=authorized;
    return map.get('acs_recursos_host')();
  }).then(res=>{
    assert.equal(res.isError,undefined);
    assert.match(res.content[0].text,/"uptime_horas":4/);
    assert.equal(run,1);
  });
});

test('disabled integration registers no tools', () => {
  const mcp = {registerTool:()=>{throw new Error('should not register')}};
  assert.equal(registerAcsMonitorTools(mcp,()=>authorized,{env:{...env,ACS_MONITOR_MCP_ENABLED:'false'}}),0);
});

test('metrics read only three specific host /proc files', () => {
  const files = new Map([
    ['/host/proc/uptime','7201.0 0'],
    ['/host/proc/loadavg','0.25 0.4 0.6 1/44 33'],
    ['/host/proc/meminfo','MemTotal: 4096000 kB\nMemAvailable: 2048000 kB\nSwapTotal: 102400 kB\nSwapFree: 51200 kB\n']
  ]);
  const visited = [];
  const metrics=collectAcsResources((path)=>{visited.push(path);return files.get(path)});
  assert.deepEqual(visited,['/host/proc/uptime','/host/proc/loadavg','/host/proc/meminfo']);
  assert.equal(metrics.uptime_horas,2);
  assert.equal(metrics.memoria.uso_percentual_estimado,50);
  assert.equal(metrics.swap.livre_mib,50);
});

test('status checks use fixed endpoints, never arbitrary user-supplied URLs', async () => {
  const requests = [];
  const fetchStub=async (url, opts)=>{
    requests.push({url,method:opts.method,redirect:opts.redirect});
    return {ok:true,status:200,body:{cancel:async()=>{}}};
  };
  const statuses=await collectAcsIntegrationStatuses(fetchStub);
  assert.equal(statuses.length,2);
  assert.deepEqual(requests.map(x=>x.url),[
    'https://mcp.jrconect.com/.well-known/oauth-protected-resource',
    'https://auth.jrconect.com/realms/jrconect/.well-known/openid-configuration',
  ]);
  assert.equal(requests.every(x=>x.method==='GET'&&x.redirect==='error'),true);
});

test('monitor errors do not reveal filesystem paths or internal details', async () => {
  const map = new Map();
  registerAcsMonitorTools({registerTool:(n,c,fn)=>map.set(n,fn)},()=>authorized,{
    env, resources:()=>{throw new Error('SECRET /root/data/secret')},statuses:()=>[]
  });
  const result=await map.get('acs_recursos_host')();
  assert.equal(result.isError,true);
  assert.equal(result.content[0].text,'ACS_MONITOR_CONSULTA_INDISPONIVEL');
});
