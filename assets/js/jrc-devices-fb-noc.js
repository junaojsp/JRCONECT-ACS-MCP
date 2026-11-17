/* Devices NOC 2.0: adapt deployed .fb-devices DOM. No data or network calls. */
(()=>{
'use strict';
const root=document.querySelector('.fb-devices');
const table=document.getElementById('devices-table');
if(!root||!table)return;
root.classList.add('jdn-live');
const el=(tag,cls,text)=>{const x=document.createElement(tag);if(cls)x.className=cls;if(text)x.textContent=text;return x;};
if(!root.querySelector('.jdn-page-heading')){
 const intro=el('header','jdn-page-heading');
 const a=el('div','jdn-heading-copy');
 a.append(el('span','jdn-kicker','JR CONECT TELECOM / INVENTARIO ACS'),el('h1','','Dispositivos'),el('p','','Inventário de equipamentos · gerenciamento individual sob demanda'));
 const source=el('span','jdn-source','GENIEACS');
 intro.append(a,source);
 root.insertBefore(intro,root.firstChild);
}
if(!root.querySelector('.jdn-inventory-hero')){
 const hero=el('section','jdn-inventory-hero');
 hero.setAttribute('aria-label','Total de dispositivos');
 const head=el('div','jdn-hero-head');
 head.append(el('span','jdn-hero-icon','▣'),el('span','jdn-hero-label','TOTAL DE DISPOSITIVOS'));
 const number=el('strong','jdn-hero-count','—');
 number.id='jdn-hero-count';
 hero.append(head,number,el('p','jdn-hero-sub','Equipamentos cadastrados no inventário ACS'));
 const firstCard=root.querySelector('.card');
 if(firstCard)root.insertBefore(hero,firstCard);else root.insertBefore(hero,root.firstChild.nextSibling);
 const badge=document.getElementById('device-stats-badges');
 if(badge){
   const sync=()=>{const t=badge.textContent||'';const m=t.match(/Total\s*\[?\s*([\d.,]+)/i);if(m)number.textContent=m[1];};
   new MutationObserver(sync).observe(badge,{childList:true,subtree:true,characterData:true});sync();
 }
}
const search=document.getElementById('search-input');
if(search){
 const controls=search.closest('.row')||search.parentElement;
 if(controls)controls.classList.add('jdn-search-panel');
}
const wrap=document.getElementById('devices-table-wrap')||table.parentElement;
if(wrap){
 wrap.classList.add('jdn-table-wrap');
 if(!root.querySelector('.jdn-list-heading')){
  const title=el('div','jdn-list-heading');
  const icon=el('span','jdn-list-icon','▤');
  const detail=el('div');
  detail.append(el('h2','','Lista de dispositivos'),el('p','','Pesquisa e gerenciamento de equipamentos — sem consulta de sinal na lista'));
  title.append(icon,detail);
  wrap.parentElement.insertBefore(title,wrap);
 }
}
/* Existing table renderer builds headers/rows dynamically. Only hide RX,
   preserving all row actions, data, tabs and detail pages. */
const hideRx=()=>{
 const onu=document.getElementById('onu-tab');
 const isOnu=!onu||onu.classList.contains('active');
 const heads=[...table.querySelectorAll('thead tr:last-child th')];
 let idx=heads.findIndex(th=>/^rx$|^sinal\s*rx$|^rx\s*power$/i.test(th.textContent.trim().replace(/[↕↑↓]/g,'')));
 if(idx<0)idx=7; // Existing ONU renderer has RX as column 8.
 const cells=[...table.querySelectorAll('tr')];
 for(const tr of cells){
  for(const td of tr.children){
   if(td.classList.contains('jdn-rx-hidden'))td.classList.remove('jdn-rx-hidden');
  }
  if(isOnu&&tr.children.length>=10&&tr.children[idx])tr.children[idx].classList.add('jdn-rx-hidden');
 }
};
const thead=document.getElementById('table-header')||table.tHead;
const tbody=document.getElementById('devices-tbody')||table.tBodies[0];
if(thead)new MutationObserver(hideRx).observe(thead,{childList:true,subtree:true});
if(tbody)new MutationObserver(hideRx).observe(tbody,{childList:true,subtree:true});
document.getElementById('deviceTypeTabs')?.addEventListener('click',()=>queueMicrotask(hideRx));
hideRx();
})();