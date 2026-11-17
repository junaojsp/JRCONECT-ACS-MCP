/* JR Conect — light dashboard: one snapshot metadata request, no active telemetry. */
(() => {
  'use strict';
  const total=document.getElementById('jrc-overview-total');
  if (!total) return;
  const age=document.getElementById('jrc-overview-age');
  const state=document.getElementById('jrc-overview-state');
  const warning=document.getElementById('jrc-overview-warning');
  async function load() {
    try {
      const response=await fetch('/api/device-inventory-status.php', {credentials:'same-origin',cache:'no-store'});
      if(!response.ok) throw new Error('falha_http');
      const data=await response.json();
      if(!data.success || !data.ready) {
        total.textContent='—';age.textContent='—';state.textContent='Indisponivel';
        warning.hidden=false;
        warning.textContent='Nenhum snapshot disponivel. O coletor precisa publicar o inventario antes de exibirmos os totais.';
        return;
      }
      const n=Number(data.total);
      if(!Number.isSafeInteger(n)||n<0) throw new Error('total_invalido');
      total.textContent=n.toLocaleString('pt-BR');
      age.textContent=data.last_collection || '—';
      state.textContent=data.stale ? 'Historico' : 'Atualizado';
      warning.hidden=!data.stale;
      warning.textContent=data.stale ? 'O ultimo inventario esta desatualizado. Os dados mostrados sao historicos, nao representam o estado atual da frota.' : '';
    } catch (_) {
      total.textContent='—';age.textContent='—';state.textContent='Indisponivel';
      warning.hidden=false;
      warning.textContent='Nao foi possivel consultar o estado do inventario.';
    }
  }

  const palette=['#54b4ef','#26d1ba','#efa94f','#a18afa','#e97290','#77b34d','#6f92b9','#c98c54'];
  function table(id,entries,field) {
    const body=document.getElementById(id);if(!body)return;
    body.replaceChildren();
    for (const item of entries.slice(0,9)) {
      const tr=document.createElement('tr');
      const td=document.createElement('td');td.textContent=String(item.name||'Nao identificado');
      const n=document.createElement('td');n.textContent=Number(item.count).toLocaleString('pt-BR');
      tr.append(td,n);body.append(tr);
    }
    if(!entries.length){const tr=document.createElement('tr');const td=document.createElement('td');td.colSpan=2;td.textContent='Sem dados';tr.append(td);body.append(tr);}
  }
  function donut(ringId,totalId,legendId,items,total) {
    const ring=document.getElementById(ringId),num=document.getElementById(totalId),legend=document.getElementById(legendId);
    if(!ring||!num||!legend)return;
    num.textContent=total.toLocaleString('pt-BR');
    legend.replaceChildren();
    let running=0;const stops=[];
    // Group tail to keep colors and legend readable.
    const head=items.slice(0,6);
    const rest=items.slice(6).reduce((x,it)=>x+Number(it.count),0);
    if(rest)head.push({name:'Outros',count:rest});
    for(let i=0;i<head.length;i++) {
      const item=head[i],pct=total ? Math.max(0,Math.min(100,100*Number(item.count)/total)) : 0;
      const next=running+pct,color=palette[i%palette.length];
      stops.push(color+' '+running.toFixed(4)+'% '+next.toFixed(4)+'%');running=next;
      const label=document.createElement('div');label.className='reference-legend-item';
      const dot=document.createElement('span');dot.className='reference-dot';dot.style.background=color;
      const text=document.createElement('span');text.textContent=String(item.name)+' · '+Number(item.count).toLocaleString('pt-BR')+' ('+pct.toFixed(1)+'%)';
      label.append(dot,text);legend.append(label);
    }
    ring.style.background=total>0&&head.length ? 'conic-gradient('+stops.join(',')+')' : '#214563';
  }
  function fleetBars(items,total){
    const host=document.getElementById('jrc20-fleet-bars');
    const note=document.getElementById('jrc20-fleet-note');
    if(!host)return;
    host.replaceChildren();
    const ranked=items.slice(0,6);
    const remaining=items.slice(6).reduce((n,x)=>n+Number(x.count),0);
    if(remaining>0)ranked.push({name:'Outros',count:remaining});
    const max=Math.max(1,...ranked.map(x=>Number(x.count)));
    for(const [i,item] of ranked.entries()){
      const n=Number(item.count);
      const line=document.createElement('div');line.className='jrc20-fleet-line';
      const label=document.createElement('span');label.className='jrc20-fleet-name';
      label.textContent=String(item.name||'Não identificado');label.title=label.textContent;
      const track=document.createElement('div');track.className='jrc20-track';
      const fill=document.createElement('div');fill.className='jrc20-fill';
      fill.style.width=(100*n/max).toFixed(2)+'%';fill.style.background=palette[i%palette.length];
      track.append(fill);
      const count=document.createElement('span');count.className='jrc20-fleet-number';
      count.textContent=n.toLocaleString('pt-BR');
      line.append(label,track,count);host.append(line);
    }
    if(note)note.textContent=total.toLocaleString('pt-BR')+' CPEs no snapshot';
  }
  async function loadCharts(){
    try {
      const response=await fetch('/api/jrc-dashboard-reference-charts.php',{credentials:'same-origin',cache:'no-store'});
      if(!response.ok)throw Error('unavailable');
      const data=await response.json();
      if(!data.success||!Array.isArray(data.manufacturers)||!Array.isArray(data.models))throw Error('invalid');
      const total=Number(data.total);
      if(!Number.isSafeInteger(total)||total<=0 ||
         data.manufacturers.reduce((n,x)=>n+Number(x.count),0)!==total)throw Error('inconsistent');
      fleetBars(data.manufacturers,total);
      table('jrc-models-rows',data.models,'model');
      const note=document.getElementById('jrc-makers-note');
      if(note)note.textContent=(data.stale?'Inventario historico · ':'')+'Coleta: '+String(data.cached_at||'indisponivel');
      const coverage=data.client_tr069_coverage;
      if(coverage?.ready===true) {
        const yes=Number(coverage.with_tr069),no=Number(coverage.without_tr069);
        if(Number.isSafeInteger(yes)&&Number.isSafeInteger(no)&&yes>=0&&no>=0&&yes+no>0) {
          donut('jrc-coverage-ring','jrc-coverage-total','jrc-coverage-legend',
            [{name:'Com TR-069',count:yes},{name:'Sem TR-069',count:no}],yes+no);
        }
      }
    } catch (_) {
      const legend=document.getElementById('jrc-makers-legend');
      if(legend)legend.textContent='Dados do inventario indisponiveis';
      for(const id of ['jrc-models-rows']) {
        const body=document.getElementById(id);
        if(body){body.replaceChildren();const tr=document.createElement('tr'),td=document.createElement('td');td.colSpan=2;td.textContent='Sem dados';tr.append(td);body.append(tr);}
      }
    }
  }
  const refresh=document.getElementById('jrc20-refresh');
  if(refresh) refresh.addEventListener('click',()=>{
    refresh.disabled=true;
    Promise.allSettled([loadCharts(),load()]).finally(()=>{ refresh.disabled=false; });
  });
  loadCharts();
  load();
})();
