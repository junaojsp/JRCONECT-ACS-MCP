/* ACS dashboard history: honest snapshot timeline, no synthetic series. */
(()=>{'use strict';
 const root=document.getElementById('jr-history-chart'),status=document.getElementById('jr-history-status');
 if(!root||!status)return;
 const svgNS='http://www.w3.org/2000/svg';
 const node=(name,attrs={})=>{const el=document.createElementNS(svgNS,name);for(const [k,v] of Object.entries(attrs))el.setAttribute(k,String(v));return el;};
 const ptTime=s=>Date.parse(String(s).replace(' ','T'));
 const fmt=d=>new Date(d).toLocaleDateString('pt-BR',{day:'2-digit',month:'2-digit'});
 let request=null;
 async function load(){
  request?.abort();request=new AbortController();
  try{
   const res=await fetch('/api/jrc-dashboard-inventory-history.php',{credentials:'same-origin',cache:'no-store',signal:request.signal});
   if(!res.ok)throw Error('Sem histórico');
   const data=await res.json();
   if(!data.success||!Array.isArray(data.points))throw Error('Histórico indisponível');
   const points=data.points.map(p=>({t:ptTime(p.at),v:Number(p.total)}))
    .filter(p=>Number.isFinite(p.t)&&Number.isSafeInteger(p.v)&&p.v>=0);
   root.replaceChildren();
   const w=1050,h=270,left=58,right=20,top=18,bottom=37,pw=w-left-right,ph=h-top-bottom;
   const svg=node('svg',{viewBox:'0 0 '+w+' '+h,role:'presentation',preserveAspectRatio:'none'});
   svg.classList.add('jr-history-svg');
   if(!points.length){root.textContent='Sem snapshots disponíveis.';status.textContent='Histórico indisponível';return;}
   const minT=Math.min(...points.map(p=>p.t)),maxT=Math.max(...points.map(p=>p.t));
   const minV=Math.min(...points.map(p=>p.v)),maxV=Math.max(...points.map(p=>p.v));
   const spread=Math.max(2,maxV-minV),low=Math.max(0,Math.floor(minV-spread*.35)),high=Math.ceil(maxV+spread*.35);
   const x=p=>left+(maxT===minT?pw/2:((p.t-minT)/(maxT-minT))*pw);
   const y=p=>top+ph-((p.v-low)/(high-low))*ph;
   for(let i=0;i<5;i++){const value=low+(high-low)*i/4,yy=top+ph-i*ph/4;
    svg.append(node('line',{x1:left,y1:yy,x2:w-right,y2:yy,stroke:'#334055','stroke-width':.75,'stroke-dasharray':'3 6'}));
    const label=node('text',{x:left-12,y:yy+4,'text-anchor':'end',fill:'#8194ae','font-size':12});label.textContent=Math.round(value).toLocaleString('pt-BR');svg.append(label);
   }
   const xLabels=[minT,minT+(maxT-minT)/2,maxT];
   for(const [i,t] of xLabels.entries()){const lab=node('text',{x:left+(pw*i/2),y:h-8,'text-anchor':i===0?'start':i===2?'end':'middle',fill:'#8194ae','font-size':12});lab.textContent=fmt(t);svg.append(lab);}
   const coords=points.map(p=>[x(p),y(p)]);
   const defs=node('defs'),gradient=node('linearGradient',{id:'jr-inv-fill',x1:'0',y1:'0',x2:'0',y2:'1'});
   gradient.append(node('stop',{offset:'0%','stop-color':'#20cab9','stop-opacity':'.23'}),node('stop',{offset:'100%','stop-color':'#20cab9','stop-opacity':'0'}));
   defs.append(gradient);svg.append(defs);
   if(coords.length>=2){
    const path=coords.map((p,i)=>(i?'L':'M')+p[0].toFixed(2)+' '+p[1].toFixed(2)).join(' ');
    const area=path+' L'+coords[coords.length-1][0]+' '+(top+ph)+' L'+coords[0][0]+' '+(top+ph)+' Z';
    svg.append(node('path',{d:area,fill:'url(#jr-inv-fill)'}));
    svg.append(node('path',{d:path,fill:'none',stroke:'#20cab9','stroke-width':2.8,'stroke-linejoin':'round','stroke-linecap':'round'}));
   }
   for(const p of coords.slice(-80))svg.append(node('circle',{cx:p[0],cy:p[1],r:coords.length===1?5:2.8,fill:'#36e4c6',stroke:'#172738','stroke-width':1.5}));
   root.append(svg);
   status.textContent=points.length===1?'1 snapshot real registrado · a linha histórica aparecerá após novas coletas':points.length.toLocaleString('pt-BR')+' snapshots reais · mínimo '+minV.toLocaleString('pt-BR')+' · máximo '+maxV.toLocaleString('pt-BR');
  }catch(e){if(e.name==='AbortError')return;root.textContent='Histórico indisponível';status.textContent='Não foi possível ler os snapshots';}
 }
 document.getElementById('jrc20-refresh')?.addEventListener('click',load);
 load();
})();
