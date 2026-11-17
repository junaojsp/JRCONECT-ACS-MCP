/* JR Conect ACS - Live device detail v5 / Made4Graph-inspired overview.
 * No new requests, no actions replaced, no fabricated device health scores.
 * Reads only visible runtime telemetry in the existing diagnostic panel. */
(() => {
 'use strict';
 const tabs=document.getElementById('deviceTabs');
 const panes=document.getElementById('deviceTabContent');
 if(!tabs||!panes)return;
 document.body.classList.add('jrc-live-device-v4','jrc-live-device-v5');
 const originalCard=tabs.closest('.card')||tabs.parentElement;
 originalCard?.classList.add('jrc-live-detail-card');
 // Preserve native Bootstrap triggers, form state, and action handlers.
 const names={'overview-tab':'Informações','actions-tab':'Ações','history-tab':'Histórico',
  'optical-tab':'Óptico','wifi-tab':'Wi-Fi','wan-tab':'WAN / PPPoE',
  'voice-tab':'Voz','devices-tab':'Rede / Hosts'};
 for(const [id,name] of Object.entries(names)){
  const tab=document.getElementById(id);
  if(tab){tab.title=name;tab.classList.add('jrc-live-menu-tab');}
 }
 let parent=tabs.parentElement;
 while(parent&&!parent.contains(panes)&&parent!==document.body)parent=parent.parentElement;
 if(parent&&parent!==document.body&&!tabs.closest('.jrc-live-inner-grid')){
  const grid=document.createElement('div');grid.className='jrc-live-inner-grid';
  const side=document.createElement('aside');side.className='jrc-live-inner-nav';
  side.setAttribute('aria-label','Seções internas do equipamento');
  const caption=document.createElement('div');caption.className='jrc-live-side-title';
  caption.textContent='GERENCIAMENTO DO DISPOSITIVO';
  side.append(caption);parent.insertBefore(grid,tabs);grid.append(side,panes);
  side.append(tabs);tabs.classList.add('jrc-live-tabs');
 }
 const overview=document.getElementById('overview-content')||document.getElementById('overview');
 const esc=(el)=>String(el?.textContent||'').replace(/\s+/g,' ').trim();
 const clean=(s)=>s.replace(/\s+/g,' ').trim();
 const specs=[
  {key:'uptime',label:'Uptime',prefix:/^uptime\b/i,icon:'bi-clock-history'},
  {key:'temp',label:'Temperatura',prefix:/^temperatura\b/i,icon:'bi-thermometer-half'},
  {key:'rx',label:'Sinal óptico (RX)',prefix:/^sinal\s+[óo]ptico\s*\(rx\)|^rx\s*$/i,icon:'bi-reception-4'},
  {key:'hosts',label:'Hosts conectados',prefix:/^hosts\s+conectados/i,icon:'bi-diagram-3'}
 ];
 function render(){
  for(const el of panes.querySelectorAll('.fb-section-title'))el.classList.add('jrc-live-section-title');
  for(const el of panes.querySelectorAll('.fb-diag-item'))el.classList.add('jrc-live-diagnostic');
  for(const el of panes.querySelectorAll('.acs-overview-card'))el.classList.add('jrc-live-overview-card');
  for(const el of panes.querySelectorAll('.acs-wan-page'))el.classList.add('jrc-live-wan');
  if(!overview)return;
  const items=[...overview.querySelectorAll('.fb-diag-item')];
  if(!items.length)return;
  const metrics=specs.map(spec=>{
   const row=items.find(item=>spec.prefix.test(clean(item.querySelector('span')?.textContent||'')));
   if(!row)return null;
   const label=clean(row.querySelector('span')?.textContent||'');
   const strong=clean(row.querySelector('strong')?.textContent||'');
   // No placeholders: display a card only for a present, actual measurement.
   if(!strong||strong==='-'||strong==='—'||/^(n\/a|indispon[ií]vel)$/i.test(strong))return null;
   return {...spec,value:strong};
  }).filter(Boolean);
  const current=overview.querySelector(':scope > .jrc-live-kpi-strip');
  if(metrics.length<2){if(current)current.remove();return;}
  const fingerprint=metrics.map(m=>m.key+':'+m.value).join('|');
  if(current?.dataset.fingerprint===fingerprint)return;
  const strip=current||document.createElement('section');
  strip.className='jrc-live-kpi-strip';
  strip.setAttribute('aria-label','Resumo do equipamento');
  strip.dataset.fingerprint=fingerprint;
  const frag=document.createDocumentFragment();
  for(const metric of metrics){
   const card=document.createElement('article');card.className='jrc-live-kpi-card jrc-live-kpi-'+metric.key;
   const head=document.createElement('div');head.className='jrc-live-kpi-head';
   const icon=document.createElement('i');icon.className='bi '+metric.icon;icon.setAttribute('aria-hidden','true');
   const label=document.createElement('span');label.textContent=metric.label;
   const value=document.createElement('strong');value.textContent=metric.value;
   head.append(icon,label);card.append(head,value);frag.append(card);
  }
  strip.replaceChildren(frag);
  if(!current)overview.prepend(strip);
  // Distinguish unavailable live WAN telemetry from a functional error.
  for(const node of overview.querySelectorAll('*')){
   if(node.children.length||node.closest('.jrc-live-kpi-strip'))continue;
   if(/aguardando dados de tr[aá]fego/i.test(esc(node))){
    const card=node.closest('.acs-overview-card')||node.closest('.fb-card')||node.parentElement;
    if(card&&card!==overview&&card.querySelectorAll('canvas,svg').length===0)card.classList.add('jrc-live-traffic-awaiting');
   }
  }
 }
 let queued=false;
 function schedule(){
  if(queued)return;
  queued=true;
  requestAnimationFrame(()=>{queued=false;render();});
 }
 // Renderer refreshes via innerHTML; batch updates and never alter original metrics.
 const observer=new MutationObserver(schedule);
 observer.observe(panes,{childList:true,subtree:true,characterData:true});
 schedule();
})();
