/* JR Conect - light CPE list v2; snapshot only, no optical polling. */
(() => {
 'use strict';
 const $=id=>document.getElementById(id);
 const form=$('light-filter'), q=$('light-search'), maker=$('light-maker'), model=$('light-model');
 const rows=$('light-rows'), count=$('light-count'), age=$('light-age'), stale=$('light-stale');
 const prev=$('light-prev'), next=$('light-next'), pagination=$('light-pagination');
 const per=$('light-per-page'), reload=$('dir-reload'), cols=$('dir-columns'), table=$('dir-table');
 if(!form || !rows) return;
 let page=1,token=0,controller=null;
 function cell(tr,value,optional=false){
   const td=document.createElement('td');
   td.textContent=String(value??'') || '—';
   if(optional) td.className='jrc-dir-optional';
   tr.append(td); return td;
 }
 function line(message){
   rows.replaceChildren();
   const tr=document.createElement('tr');
   cell(tr,message).colSpan=7;rows.append(tr);
 }
 function option(select,value){
   const el=document.createElement('option');el.value=value;el.textContent=value;select.append(el);
 }
 async function filters(){
   try{
     const reply=await fetch('/api/jrc-devices-light.php?filters=1',{credentials:'same-origin',cache:'no-store'});
     if(!reply.ok) return;
     const result=await reply.json();
     if(!result.success)return;
     for(const item of result.manufacturers||[])option(maker,item);
     for(const item of result.models||[])option(model,item);
   }catch(_){}
 }
 async function load(){
   const current=++token;
   controller?.abort(); controller=new AbortController();
   line('Carregando equipamentos...');
   const params=new URLSearchParams({page:String(page),per_page:per.value,q:q.value.trim(),manufacturer:maker.value,model:model.value});
   try{
     const response=await fetch('/api/jrc-devices-light.php?'+params.toString(),{
       credentials:'same-origin',cache:'no-store',signal:controller.signal});
     const data=await response.json();
     if(current!==token)return;
     if(!response.ok||!data.success)throw Error(data.message||'Inventario indisponivel');
     rows.replaceChildren();
     for(const item of data.items){
       const tr=document.createElement('tr');
       cell(tr,item.last_inform);
       const who=cell(tr,item.pppoe||item.client||'—');
       if(item.pppoe && item.client && item.client!==item.pppoe){
         const secondary=document.createElement('small');
         secondary.style.display='block';secondary.style.opacity='.72';secondary.textContent=item.client;who.append(secondary);
       }
       cell(tr,item.mac,true);cell(tr,item.ip,true);
       cell(tr,item.manufacturer);
       const product=cell(tr,item.model);
       const serial=document.createElement('small');serial.style.display='block';serial.style.opacity='.72';serial.textContent=item.serial||'';
       product.append(serial);
       const td=document.createElement('td');td.className='jrc-dir-actions';
       const a=document.createElement('a');
       a.className='jrc-dir-open';a.textContent='Acoes';a.href='/device-detail.php?id='+encodeURIComponent(item.id);
       a.title='Abrir detalhes do dispositivo';td.append(a);tr.append(td);rows.append(tr);
     }
     if(!data.items.length)line('Nenhum equipamento encontrado.');
     const total=Number(data.total)||0;
     count.textContent='Mostrando '+(total ? (page-1)*Number(data.per_page)+1 : 0)+' a '+Math.min(page*Number(data.per_page),total)+' de '+total.toLocaleString('pt-BR')+' registros';
     pagination.textContent='Pagina '+data.page+' de '+Math.max(1,data.pages);
     age.textContent='Inventario: '+(data.cached_at||'desconhecido');
     stale.hidden=!data.stale;
     stale.textContent=data.stale?'Atencao: inventario historico, atualizado em '+data.cached_at+'. Nao representa estado atual das ONUs.':'';
     prev.disabled=page<=1;next.disabled=page>=data.pages;
   }catch(error){
     if(error.name==='AbortError')return;
     line(error.message);
     count.textContent='Consulta indisponivel';age.textContent='';
     stale.hidden=true;prev.disabled=true;next.disabled=true;
   }
 }
 form.addEventListener('submit',e=>{e.preventDefault();page=1;load();});
 maker.addEventListener('change',()=>{page=1;load();});
 model.addEventListener('change',()=>{page=1;load();});
 per.addEventListener('change',()=>{page=1;load();});
 reload.addEventListener('click',load);
 cols.addEventListener('click',()=>{table.classList.toggle('jrc-dir-optional-hide');});
 prev.addEventListener('click',()=>{if(page>1){page--;load();}});
 next.addEventListener('click',()=>{page++;load();});
 filters();load();
})();
