import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import test from 'node:test';
import assert from 'node:assert/strict';
const source=readFileSync(new URL('../assets/js/devices-fleet.js',import.meta.url),'utf8');
const deferred=()=>{let resolve,reject;const promise=new Promise((a,b)=>{resolve=a;reject=b});return {promise,resolve,reject}};
function setup(){
 const elements=new Map();
 function el(){return {value:'',textContent:'',innerHTML:'',children:[],hidden:false,disabled:false,
  style:{setProperty(k,v){this[k]=v}},classList:{toggle(){},add(){},remove(){}},setAttribute(){},
  appendChild(x){this.children.push(x)},querySelector(){return null},querySelectorAll(){return []},scrollIntoView(){}};}
 for(const id of ['search-input','devices-tbody','device-stats-badges','count-onu','device-count','pagination-container','pagination-info','pagination-first','pagination-prev','pagination-next','pagination-last','items-per-page','devices-table']) elements.set(id,el());
 elements.get('search-input').parentElement=el();
 const timers=[];
 const c=vm.createContext({console:{warn(){},error(){},log(){}},Set,Map,Date,Promise,
  allDevices:[],allMapItems:[],currentPage:1,itemsPerPage:20,totalDevices:0,currentFilterType:'onu',
  currentSortColumn:null,currentSortDirection:'asc',autoRefreshTimer:null,
  document:{getElementById:id=>elements.get(id),createElement:el,querySelectorAll:()=>[],addEventListener(){}},
  window:{GENIEACS_CONFIGURED:false,addEventListener(){}},
  clearOnuReadCache(){},fetchOnuBatch:async()=>({success:false}),
  fetch:async()=>({ok:true,json:async()=>({serials:[]})}),
  setTimeout(fn){timers.push(fn);return timers.length},clearTimeout(id){if(id)timers[id-1]=null},setInterval(){},clearInterval(){}
 });
 vm.runInContext(source,c);
 // These only draw static headers/icons unrelated to filtering.
 c.generateTableHeader=()=>{};c.updateSortIcons=()=>{};
 return {c,e:elements,run:s=>vm.runInContext(s,c),timers};
}
function onu(i,extra={}){return {device_id:'id'+i,serial_number:'SERIAL'+i,manufacturer:'FiberHome',product_class:'HG6143D3',pppoe_username:'onu'+i,mac_address:'02-00-00-11-22-33',...extra}}
const flush=async()=>{await Promise.resolve();await Promise.resolve();await Promise.resolve()};
test('serial and MAC variations match the same device',()=>{
 const {c}=setup();const d=onu(1,{serial_number:'FHTTA1B2C3D4'});
 for(const q of ['FHTTa1b2c3d4','a1b2c3d4','02:00:00:11:22:33','020000112233','HG6143D3','FiberHome'])assert.equal(c.deviceMatchesLocalSearch(d,q),true,q);
});
test('zero and five results reset totals and hidden pagination even after a large inventory',()=>{
 const {c,e}=setup();c.allDevices=Array.from({length:600},(_,i)=>onu(i));c.renderCurrentDeviceResults();assert.equal(c.totalDevices,600);
 e.get('search-input').value='no-such-serial';c.filterDevices();assert.equal(c.totalDevices,0);assert.equal(c.currentPage,1);assert.equal(e.get('pagination-info').textContent,'Nenhum resultado');assert.equal(e.get('pagination-container').hidden,true);
 c.allDevices=Array.from({length:5},(_,i)=>onu(i));e.get('search-input').value='onu';c.filterDevices();assert.equal(c.totalDevices,5);assert.equal(e.get('pagination-info').textContent,'Página 1 de 1');assert.equal(e.get('pagination-container').style.display,'none');
});
test('navigation, sorting and page size preserve search and IXC matches',()=>{
 const {c,e,run}=setup();c.allDevices=Array.from({length:30},(_,i)=>onu(i));e.get('search-input').value='customer';
 run("clientMatchTerm='customer';clientMatchSerials=new Set(allDevices.slice(0,18).map(d=>d.serial_number));");
 c.filterDevices();c.goToPage(2);assert.equal(c.currentPage,2);assert.equal(e.get('search-input').value,'customer');assert.equal(c.totalDevices,18);
 c.sortTable('serial_number');assert.equal(c.totalDevices,18);assert.equal(e.get('search-input').value,'customer');
 e.get('items-per-page').value='4';c.changeItemsPerPage();assert.equal(c.itemsPerPage,4);assert.equal(e.get('pagination-info').textContent,'Página 1 de 5');
});
test('refresh publishes only a complete snapshot and reapplies the normalized matcher',async()=>{
 const {c,e}=setup();c.allDevices=[onu('old')];e.get('search-input').value='02:00:00:11:22:33';const second=deferred();let calls=0;
 c.fetchAPI=async()=>++calls===1?{success:true,devices:[onu(1)],hasMore:true}:second.promise;
 const load=c.loadDevices(true);await flush();assert.equal(c.allDevices[0].device_id,'idold');
 c.filterDevices();assert.equal(c.totalDevices,1);
 second.resolve({success:true,devices:[onu(2)],hasMore:false});await load;
 assert.equal(c.allDevices.length,2);assert.equal(c.totalDevices,2);assert.equal(e.get('search-input').value,'02:00:00:11:22:33');
});
test('failed later chunk keeps the previous complete inventory',async()=>{
 const {c}=setup();c.allDevices=[onu('old')];let calls=0;c.fetchAPI=async()=>++calls===1?{success:true,devices:[onu(1)],hasMore:true}:{success:false};
 await c.loadDevices();assert.equal(c.allDevices.length,1);assert.equal(c.allDevices[0].device_id,'idold');
});
test('initial loading never reports device absent from a partial list',async()=>{
 const {c,e}=setup();const d=deferred();c.fetchAPI=()=>d.promise;const load=c.loadDevices();e.get('search-input').value='FHTT';c.filterDevices();
 assert.match(e.get('devices-tbody').innerHTML,/Carregando inventário completo/);
 d.resolve({success:false});await load;assert.match(e.get('devices-tbody').innerHTML,/Inventário indisponível/);
});
test('overlapping refreshes do not start another snapshot',async()=>{
 const {c}=setup();const d=deferred();let calls=0;c.fetchAPI=()=>{calls++;return d.promise};const first=c.loadDevices();await c.loadDevices(true);assert.equal(calls,1);d.resolve({success:true,devices:[],hasMore:false});await first;
});
test('outdated IXC response cannot replace a newer term, including clearing search',async()=>{
 const {c,e,run}=setup();c.allDevices=[onu(1),onu(2)];const d=deferred();c.fetch=()=>d.promise;
 e.get('search-input').value='customer';c.filterDevices();const request=run('clientSearchRequest');const task=c.searchClientDevices('customer',request,0);
 c.clearSearch();assert.equal(c.totalDevices,2);d.resolve({ok:true,json:async()=>({serials:['SERIAL1']})});await task;assert.equal(c.totalDevices,2);assert.equal(run('clientMatchSerials.size'),0);
});
test('old enrichment cannot overwrite the latest filtered counters',async()=>{
 const {c,e}=setup();const d=deferred();c.fetchOnuBatch=()=>d.promise;c.allDevices=[onu(1),onu(2)];c.renderCurrentDeviceResults();
 e.get('search-input').value='absent';c.filterDevices();const stats=e.get('device-stats-badges').innerHTML;
 d.resolve({success:true,devices:{}});await flush();assert.equal(e.get('device-stats-badges').innerHTML,stats);assert.equal(c.totalDevices,0);
});
test('failed IXC response never restores a list from before refresh',async()=>{
 const {c,e,run}=setup();c.allDevices=[onu(1)];e.get('search-input').value='onu';c.filterDevices();const d=deferred();c.fetch=()=>d.promise;
 const old=c.searchClientDevices('onu',run('clientSearchRequest'),0);
 c.allDevices=[onu(2),onu(3)];run('inventoryRevision++');c.filterDevices(false);d.reject(Error('offline'));await old;assert.equal(c.totalDevices,2);
});
