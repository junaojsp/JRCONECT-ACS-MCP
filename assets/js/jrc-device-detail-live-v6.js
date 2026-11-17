/* JR Conect ACS - Live device detail v6 / structural overview cleanup.
 * No new requests, no actions replaced, no fabricated device health scores.
 * Reads only visible runtime telemetry in the existing diagnostic panel. */
(() => {
 'use strict';
 const tabs=document.getElementById('deviceTabs');
 const panes=document.getElementById('deviceTabContent');
 if(!tabs||!panes)return;
 document.body.classList.add('jrc-live-device-v4','jrc-live-device-v5','jrc-live-device-v6');
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
  {key:'hosts',label:'Hosts conectados',prefix:/^hosts\s+conectados/i,icon:'bi-diagram-3'},
  {key:'wan',label:'Conexão WAN',prefix:/^conex[aã]o\s+WAN/i,icon:'bi-globe2'}
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
  // Classify ORIGINAL content blocks, keeping operational buttons in place.
  // Use the title and a strict known-card ancestor; never reorganize WAN controls.
  const headings=[...overview.querySelectorAll('h2,h3,h4,h5,h6,.fb-section-title')];
  for(const h of headings){
   const title=clean(h.textContent||'');
   const card=h.closest('.acs-overview-card,.fb-card,.card');
   if(card&&/^tr[aá]fego WAN$/i.test(title)){
    const pending=/aguardando dados de tr[aá]fego/i.test(esc(card));
    const liveGraph=card.querySelector('canvas,svg');
    card.classList.toggle('jrc-live-traffic-empty',pending&&!liveGraph);
   }
   if(card&&/^sinal [óo]ptico/i.test(title))card.classList.add('jrc-live-optical-panel');
   if(card&&/^diagn[oó]stico r[aá]pido$/i.test(title))card.classList.add('jrc-live-diagnostic-panel');
  }
  // Repeated "Indicadores" area contains values already visible in the KPI
  // strip. Collapse rather than remove it, preserving all native content.
  const indicatorsHead=headings.find(h=>/^indicadores$/i.test(clean(h.textContent||'')));
  if(indicatorsHead&&!indicatorsHead.closest('.jrc-live-extra-details')){
   const section=indicatorsHead.parentElement;
   const next=section?.nextElementSibling;
   const canCollapse=section&&next&&
    !section.querySelector('input,button,select,textarea')&&
    !next.querySelector('form,input,select,textarea')&&
    next.querySelectorAll('button').length===0;
   if(canCollapse){
    const details=document.createElement('details');
    details.className='jrc-live-extra-details';
    const summary=document.createElement('summary');
    summary.textContent='Mais indicadores e medições';
    section.before(details);
    details.append(summary,section,next);
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
