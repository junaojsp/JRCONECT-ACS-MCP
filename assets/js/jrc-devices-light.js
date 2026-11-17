/* JR Conect ACS — lightweight device directory. No telemetry requests. */
(() => {
 'use strict';
 const form=document.getElementById('light-filter');
 if(!form) return;
 const q=document.getElementById('light-search'),maker=document.getElementById('light-maker'),model=document.getElementById('light-model');
 const rows=document.getElementById('light-rows'),count=document.getElementById('light-count'),age=document.getElementById('light-age');
 const prev=document.getElementById('light-prev'),next=document.getElementById('light-next'),pagination=document.getElementById('light-pagination');
 let page=1,serial=0,controller=null;
 function cell(row,text){
   const td=document.createElement('td');
   td.textContent=String(text??'') || '—';
   row.append(td);return td;
 }
 async function load(){
   const sequence=++serial;
   controller?.abort();controller=new AbortController();
   rows.replaceChildren();
   const tr=document.createElement('tr');cell(tr,'Carregando...').colSpan=6;rows.append(tr);
   const params=new URLSearchParams({page:String(page),q:q.value.trim(),manufacturer:maker.value.trim(),model:model.value.trim()});
   try{
     const res=await fetch('/api/jrc-devices-light.php?'+params.toString(),{credentials:'same-origin',cache:'no-store',signal:controller.signal});
     const body=await res.json();
     if(sequence!==serial)return;
     if(!res.ok||!body.success)throw Error(body.message||'Falha ao consultar inventario');
     rows.replaceChildren();
     for(const item of body.items){
       const tr=document.createElement('tr');
       const td=cell(tr,item.model||'Modelo nao informado');
       const small=document.createElement('small');small.style.display='block';small.textContent=item.serial||'';td.append(small);
       cell(tr,item.client||item.pppoe||'—');
       cell(tr,item.mac);cell(tr,item.ip);cell(tr,item.last_inform);
       const action=document.createElement('td'),a=document.createElement('a');
       a.href='/device-detail.php?id='+encodeURIComponent(item.id);
       a.className='btn btn-sm btn-primary';a.textContent='Abrir dispositivo';action.append(a);tr.append(action);rows.append(tr);
     }
     if(!body.items.length){const tr=document.createElement('tr');cell(tr,'Nenhum dispositivo encontrado.').colSpan=6;rows.append(tr);}
     count.textContent=Number(body.total).toLocaleString('pt-BR')+' equipamentos encontrados';
     age.textContent='Snapshot: '+String(body.cached_at||'indisponivel');
     pagination.textContent='Pagina '+body.page+' de '+Math.max(1,body.pages);
     prev.disabled=page<=1;next.disabled=page>=body.pages;
   }catch(error){
     if(error.name==='AbortError')return;
     rows.replaceChildren();const tr=document.createElement('tr');cell(tr,error.message).colSpan=6;rows.append(tr);
     count.textContent='Consulta indisponivel';age.textContent='';prev.disabled=next.disabled=true;
   }
 }
 form.addEventListener('submit',event=>{event.preventDefault();page=1;load();});
 prev.addEventListener('click',()=>{if(page>1){page--;load();}});
 next.addEventListener('click',()=>{page++;load();});
 load();
})();
