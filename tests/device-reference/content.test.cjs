const {test}=require('node:test'),assert=require('node:assert/strict'),vm=require('node:vm'),fs=require('node:fs'),path=require('node:path');
const root=path.resolve(__dirname,'../..');
const context={jrcEscapeClientHtml:value=>String(value??'').replace(/[&<>"']/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch])),jrcConnectionText:c=>c.interface_type||'Rede',formatUptime:n=>n+' s',renderWanUsageCards:()=>'<div id="live-rx-mbps"></div>'};
vm.createContext(context);
for(const file of ['scripts/device-reference-v2/helpers.js','scripts/device-reference-content/host-visual.js','scripts/device-reference-content/renderers.js'])vm.runInContext(fs.readFileSync(path.join(root,file),'utf8'),context);
test('Uses manufacturer logos, never assigns a brand from a generic hostname',()=>{
 assert.match(context.jrcHostVisual({vendor:'Apple, Inc.'}),/apple\.svg/);
 assert.doesNotMatch(context.jrcHostVisual({hostname:'Apple iPhone',vendor:'Unknown'}),/apple\.svg/);
 assert.doesNotMatch(context.jrcHostVisual({vendor:'AzureWave Technology'}),/google\.svg/);
 assert.match(context.jrcHostVisual({hostname:'Impressora'}),/bi-printer/);
});
test('Host cards preserve traffic selectors, escape text, and distinguish unknown status',()=>{
 const html=context.renderConnectedDevicesTab([{hostname:'<script>bad</script>',mac_address:'aa:bb',ip_address:'192.0.2.2',active:'false'}]);
 assert.doesNotMatch(html,/<script>/);assert.match(html,/&lt;script&gt;/);
 assert.match(html,/class="hotspot-user" data-mac="aa:bb"/);assert.match(html,/class="hotspot-traffic" data-mac="aa:bb"/);
 assert.match(html,/>Offline</);assert.match(context.renderConnectedDevicesTab([{hostname:'Unknown'}]),/>Sem estado</);
});
test('LAN shows collected ports only and retains the original DHCP editor',()=>{
 assert.doesNotMatch(context.jrcRefLan({}),/jrc-port-socket/);
 const html=context.jrcRefLan({dhcp_server:{enabled:true,gateway:'192.168.1.1'},lan_ports:[{name:'LAN1',status:'Up',max_bit_rate:1000}]});
 assert.match(html,/openEditDHCPModal/);assert.match(html,/jrc-port-socket is-up/);assert.match(html,/1000/);
 assert.equal((html.match(/class="jrc-port-item"/g)||[]).length,1);
});
test('WAN retains actions and telemetry IDs, with graphs collapsed',()=>{
 const html=context.renderWANDetailsTab([{name:"Client's WAN",status:'Connected',username:'user',external_ip:'192.0.2.1'}]);
 for(const action of ['openEditWANModal','openDeleteWANModal','openAddWANModal'])assert.match(html,new RegExp(action));
 assert.match(html,/<details class="jrc-wan-telemetry">/);assert.match(html,/id="live-rx-mbps"/);
 assert.match(html,/Client&#39;s WAN/);assert.match(html,/Usuário PPPoE/);
});
