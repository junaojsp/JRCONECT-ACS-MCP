/* JR Conect ACS 2.0 - visual device detail adapter. Existing actions remain unchanged. */
(() => {
 'use strict';
 const tabs=document.getElementById('deviceTabs');
 const content=document.getElementById('deviceTabContent');
 if(!tabs||!content)return;
 const root=tabs.closest('.card');
 if(!root||root.classList.contains('jrc-device-detail-noc'))return;
 root.classList.add('jrc-device-detail-noc');
 document.body.classList.add('jrc-device-detail-page');
 const body=content.parentElement;
 if(!body||tabs.parentElement!==body)return;
 const grid=document.createElement('div');
 grid.className='jrc-device-detail-grid';
 const side=document.createElement('aside');
 side.className='jrc-device-detail-sidebar';
 side.setAttribute('aria-label','Seções do dispositivo');
 const heading=document.createElement('div');
 heading.className='jrc-device-detail-sidebar-heading';
 heading.textContent='GERENCIAMENTO DA CPE';
 side.append(heading);
 body.insertBefore(grid,tabs);
 grid.append(side,content);
 side.append(tabs);
 tabs.classList.add('jrc-device-detail-menu');
 const labelMap={
  'overview-tab':'Informações','topology-tab':'Topologia','wan-tab':'WAN',
  'dhcp-tab':'Rede / DHCP','devices-tab':'Dispositivos'
 };
 for(const [id,label] of Object.entries(labelMap)){
  const button=document.getElementById(id);
  if(!button)continue;
  const span=document.createElement('span');
  span.className='jrc-detail-nav-label';
  span.textContent=label;
  // Keep the existing Bootstrap tab trigger, callbacks and counts; no HTML replacement.
  button.append(span);
 }
 const back=document.querySelector('a[href="/devices.php"]');
 if(back)back.classList.add('jrc-device-back');
 const badge=document.getElementById('device-id-badge');
 if(badge)badge.classList.add('jrc-device-identity');
})();
