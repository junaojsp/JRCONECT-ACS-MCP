/* JR Conect ACS live-device-detail v4.
 * Enhances the REAL production inline renderer (.fb-*, .acs-*).
 * Does not create fake telemetric values, invoke APIs or replace commands. */
(() => {
 'use strict';
 const tabs=document.getElementById('deviceTabs');
 const panes=document.getElementById('deviceTabContent');
 if(!tabs||!panes)return;
 document.body.classList.add('jrc-live-device-v4');
 // The previous GitHub-based v3 renderer reads stale table fields from a
 // different page; hide its empty duplicate KPI strip rather than manipulating data.
 const wrap=tabs.closest('.card')||tabs.parentElement;
 if(wrap)wrap.classList.add('jrc-live-detail-card');
 const names={
  'overview-tab':'Informações','actions-tab':'Ações',
  'history-tab':'Histórico','optical-tab':'Óptico',
  'wifi-tab':'Wi-Fi','wan-tab':'WAN / PPPoE',
  'voice-tab':'Voz','devices-tab':'Rede / Hosts',
  'topology-tab':'Topologia','dhcp-tab':'LAN / DHCP'
 };
 for(const [id,name] of Object.entries(names)){
   const tab=document.getElementById(id);
   if(tab){tab.setAttribute('title',name);tab.classList.add('jrc-live-menu-tab');}
 }
 // Mount the EXISTING Bootstrap tab triggers as a side menu only if there
 // is one unambiguous parent for both controls and panels. Moving nodes
 // preserves listeners, IDs, values, and the original tab activation model.
 if(tabs.parentElement===panes.parentElement && !tabs.closest('.jrc-live-inner-grid')){
   const parent=tabs.parentElement;
   const grid=document.createElement('div');
   grid.className='jrc-live-inner-grid';
   const side=document.createElement('aside');
   side.className='jrc-live-inner-nav';
   side.setAttribute('aria-label','Seções internas do equipamento');
   const label=document.createElement('div');
   label.className='jrc-live-side-title';
   label.textContent='GERENCIAMENTO DO DISPOSITIVO';
   side.append(label);
   parent.insertBefore(grid,tabs);
   grid.append(side,panes);
   side.append(tabs);
   tabs.classList.add('jrc-live-tabs');
   grid.dataset.ready='1';
 }
 const tag=()=>{
   // Scope only real ACS panel classes already used by the live renderer.
   for(const el of document.querySelectorAll('.fb-section-title')){
     el.classList.add('jrc-live-section-title');
   }
   for(const el of document.querySelectorAll('.fb-diag-item')){
     el.classList.add('jrc-live-diagnostic');
   }
   for(const el of document.querySelectorAll('.acs-overview-card')){
     el.classList.add('jrc-live-overview-card');
   }
   for(const el of document.querySelectorAll('.acs-wan-page')){
     el.classList.add('jrc-live-wan');
   }
   // No copying credentials/password text into new DOM nodes.
 };
 // Inline JS redraws the panels asynchronously; class tagging is idempotent
 // and does not add children, so there is no MutationObserver feedback loop.
 const observer=new MutationObserver(tag);
 observer.observe(panes,{childList:true,subtree:true});
 tag();
})();
