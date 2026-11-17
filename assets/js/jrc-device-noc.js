/* JR Conect ACS 2.0 device NOC: read-only existing detailed API, on demand. */
(() => {
  'use strict';
  const root=document.querySelector('.jrc-noc');
  if(!root)return;
  const deviceId=root.dataset.deviceId;
  const $=id=>document.getElementById(id);
  const set=(id,value)=>{
    const v=(value===undefined||value===null||value===''||value==='N/A')?'—':String(value);
    const el=$(id);if(el)el.textContent=v;
  };
  function text(value){if(value==null || typeof value==='object')return null;return String(value).trim();}
  function unit(value,unitName,options={}){
    const s=text(value);
    if(!s || s==='N/A')return '—';
    const n=Number(s.replace(',','.'));
    if(!Number.isFinite(n))return '—';
    if(options.negative && (n>=0||n< -80))return '—';
    if(options.positive && (n< -30||n>180))return '—';
    return n.toFixed(options.precision??1)+' '+unitName;
  }
  const error=$('noc-error'),refresh=$('noc-refresh');
  let controller=null;
  async function load(){
    controller?.abort();
    controller=new AbortController();
    refresh.disabled=true;
    error.hidden=true;
    try{
      const qs=new URLSearchParams({device_id:deviceId});
      const response=await fetch('/api/get-device-detail.php?'+qs.toString(),{
        credentials:'same-origin',cache:'no-store',signal:controller.signal
      });
      if(!response.ok)throw Error('Servidor retornou HTTP '+response.status);
      const data=await response.json();
      if(!data.success||!data.device||typeof data.device!=='object')throw Error('Detalhes indisponíveis');
      const d=data.device;
      set('noc-title',text(d.product_class)||'Dispositivo');
      set('noc-sub',[text(d.manufacturer),text(d.serial_number)].filter(Boolean).join(' · ')||'Equipamento no ACS');
      const status=(d.status==='online')?'online':(d.status==='offline'?'offline':'unknown');
      set('noc-status',status==='online'?'Online':status==='offline'?'Sem Inform recente':'Não confirmado');
      $('noc-status').className='jrc-noc-pill '+(status==='unknown'?'':status);
      set('noc-communication',status==='online'?'Inform recente':status==='offline'?'Inform antigo':'Não confirmado');
      set('noc-rx',unit(d.rx_power,'dBm',{negative:true,precision:2}));
      set('noc-uptime',text(d.uptime)||'—');
      set('noc-temp',unit(d.temperature,'°C',{positive:true}));
      const hosts=Number(d.connected_devices_count);
      set('noc-hosts',Number.isSafeInteger(hosts)&&hosts>=0?hosts:'—');
      set('noc-wan',text(d.ip_address));
      set('noc-pppoe',text(d.pppoe_username));
      set('noc-tr069',text(d.ip_tr069));
      set('noc-last',text(d.last_inform));
      set('noc-maker',text(d.manufacturer));
      set('noc-model',text(d.product_class));
      set('noc-serial',text(d.serial_number));
      set('noc-mac',text(d.mac_address));
      set('noc-firmware',text(d.software_version));
      set('noc-hardware',text(d.hardware_version));
      set('noc-ssid',text(d.wifi_ssid));
    }catch(e){
      if(e.name==='AbortError')return;
      error.hidden=false;
      error.textContent='Não foi possível consultar os dados técnicos: '+(e.message||'erro desconhecido')+'. Utilize o gerenciador original.';
      set('noc-status','Indisponível');
    }finally{refresh.disabled=false;}
  }
  refresh.addEventListener('click',load);
  load();
})();
