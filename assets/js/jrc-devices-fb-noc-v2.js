/* JR Conect ACS devices NOC v2. Presentation only: no API calls or data changes. */
(() => {
'use strict';
const root=document.querySelector('.fb-devices');
const table=document.getElementById('devices-table');
if(!root||!table)return;
root.classList.add('jdn-live','jdn-polished');
const $=(id)=>document.getElementById(id);
const mk=(tag,cls,text)=>{
 const n=document.createElement(tag);
 if(cls)n.className=cls;
 if(text)n.textContent=text;
 return n;
};
/* The previous adapter injected two redundant headings and guessed total from
   an ambiguous badge text, causing '3' even though the header showed '764'.
   The original production heading and its authoritative counters remain. */
root.querySelectorAll('.jdn-page-heading,.jdn-inventory-hero').forEach(n=>n.remove());
const headings=[...root.querySelectorAll('h1,h2,h3')];
const pageHeading=headings.find(h=>h.textContent.trim()==='Dispositivos');
if(pageHeading){
 const parent=pageHeading.parentElement;
 if(parent&&parent!==root)parent.classList.add('jdn-native-header');
}
/* Preserve all original search and filter controls. */
const search=$('search-input');
if(search){
 const panel=search.closest('.row')||search.parentElement;
 if(panel)panel.classList.add('jdn-search-panel');
}
const wrap=$('devices-table-wrap')||table.parentElement;
if(wrap){
 wrap.classList.add('jdn-table-wrap');
 if(!root.querySelector('.jdn-list-heading')){
  const title=mk('div','jdn-list-heading');
  const icon=mk('span','jdn-list-icon','▤');
  const details=mk('div');
  details.append(mk('h2','','Lista de dispositivos'),mk('p','','Busca e gerenciamento de equipamentos'));
  title.append(icon,details);
  wrap.parentElement.insertBefore(title,wrap);
 }
}
/* Larger controls with original onclick/handlers. Never recreate buttons. */
const paginator=$('pagination-container');
if(paginator)paginator.classList.add('jdn-pagination-panel');
const iconize=()=>{
 const body=$('devices-tbody')||table.tBodies[0];
 if(!body)return;
 for(const tr of body.rows){
  const first=tr.cells[0];
  if(!first)continue;
  const icon=first.querySelector('i[class*="bi-"],svg,[class*="device-icon"],[class*="equip-icon"]');
  if(!icon)continue;
  icon.classList.add('jdn-modem-glyph');
  const holder=icon.parentElement;
  if(holder&&holder!==first&&holder.children.length<=2&&holder.textContent.trim().length<=2){
   holder.classList.add('jdn-modem-mark');
  }
 }
};
let updating=false;
const hideRx=()=>{
 const onu=$('onu-tab');
 const isOnu=!onu||onu.classList.contains('active');
 const heads=[...table.querySelectorAll('thead tr:last-child th')];
 let idx=heads.findIndex(th=>/^(rx|sinal\s*rx|rx\s*power)$/i.test(th.textContent.trim().replace(/[↕↑↓]/g,'')));
 if(idx<0&&heads.length>=12)idx=7;
 for(const tr of table.querySelectorAll('tr')){
  for(const td of tr.children){
   if(td.classList.contains('jdn-rx-hidden'))td.classList.remove('jdn-rx-hidden');
  }
  if(isOnu&&idx>=0&&tr.children.length===heads.length&&tr.children[idx])tr.children[idx].classList.add('jdn-rx-hidden');
 }
};
const refresh=()=>{
 if(updating)return;
 updating=true;
 try{hideRx();iconize();}finally{updating=false;}
};
const head=$('table-header')||table.tHead;
const body=$('devices-tbody')||table.tBodies[0];
if(head)new MutationObserver(refresh).observe(head,{childList:true,subtree:true});
if(body)new MutationObserver(refresh).observe(body,{childList:true,subtree:true});
$('deviceTypeTabs')?.addEventListener('click',()=>queueMicrotask(refresh));
refresh();
})();
