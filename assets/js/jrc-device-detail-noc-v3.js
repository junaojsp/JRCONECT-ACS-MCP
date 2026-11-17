/* ACS 2.0 - device detail UI refinement, based only on native tabs/actions. */
(() => {
  'use strict';
  const tabs = document.getElementById('deviceTabs');
  const content = document.getElementById('deviceTabContent');
  if (!tabs || !content) return;
  const card = tabs.closest('.card');
  if (!card) return;
  document.body.classList.add('jrc-device-detail-page','jrc-device-detail-v2-page');
  card.classList.add('jrc-device-detail-noc','jrc-device-detail-v2');
  const body = content.parentElement;
  if (!body || tabs.parentElement !== body) return;

  // Reuse original Bootstrap tab triggers and IDs; do not clone inputs/buttons.
  const grid = document.createElement('div');
  grid.className = 'jrc-device-detail-grid jrc-device-detail-v2-grid';
  const sidebar = document.createElement('aside');
  sidebar.className = 'jrc-device-detail-sidebar';
  sidebar.setAttribute('aria-label','Navegação interna do dispositivo');
  const heading = document.createElement('div');
  heading.className = 'jrc-device-detail-sidebar-heading';
  heading.textContent = 'GERENCIAMENTO';
  sidebar.append(heading);
  body.insertBefore(grid,tabs);
  grid.append(sidebar,content);
  sidebar.append(tabs);
  tabs.classList.add('jrc-device-detail-menu');

  // Keep the five existing features; names match their real implementation.
  const labels = {
    'overview-tab':'Informações',
    'topology-tab':'Topologia',
    'wan-tab':'Conexões WAN',
    'dhcp-tab':'Rede / DHCP',
    'devices-tab':'Dispositivos conectados'
  };
  for (const [id,label] of Object.entries(labels)) {
    const button = document.getElementById(id);
    if (!button) continue;
    const icons = [...button.children].filter(el => el.tagName === 'I');
    const badges = [...button.querySelectorAll('.badge')];
    // Remove only the visible text nodes, not badges, icons, or handlers.
    for (const node of [...button.childNodes]) {
      if (node.nodeType===Node.TEXT_NODE) node.remove();
    }
    for (const old of button.querySelectorAll('.jrc-detail-nav-label')) old.remove();
    const span=document.createElement('span');
    span.className='jrc-detail-nav-label';
    span.textContent=label;
    if (icons.length) icons[0].after(span); else button.prepend(span);
    for (const badge of badges) badge.classList.add('jrc-detail-nav-count');
  }

  // Existing page toolbar and identification get reference hierarchy.
  const previous = card.previousElementSibling;
  if (previous && previous.querySelector('a[href="/devices.php"]')) {
    previous.classList.add('jrc-detail-actionbar');
  }
  const badge = document.getElementById('device-id-badge');
  if (badge) badge.classList.add('jrc-device-identity');

  const head=card.querySelector(':scope > .card-header');
  if(head){
    head.classList.add('jrc-detail-device-hero');
    const annotation=document.createElement('small');
    annotation.className='jrc-detail-caption';
    annotation.textContent='Informações e gerenciamento do equipamento';
    head.append(annotation);
  }

  // A side note is informational and does not suggest capabilities unavailable.
  const note=document.createElement('p');
  note.className='jrc-detail-operations-note';
  note.textContent='As ações de configuração e diagnóstico permanecem nos controles originais desta tela.';
  sidebar.append(note);
})();


/* Reference detail panels v3: uses existing rendered data only.
   Never requests additional data or replaces equipment inputs/actions. */
(() => {
  'use strict';
  const overview=document.getElementById('overview-content');
  const root=document.getElementById('deviceTabContent');
  if(!overview||!root)return;
  document.body.classList.add('jrc-device-detail-v3-page');
  root.classList.add('jrc-device-detail-v3-content');

  const allowed=[
    {title:'Uptime',keys:['Uptime'],icon:'bi-clock-history'},
    {title:'Último contato',keys:['Last Inform'],icon:'bi-broadcast'},
    {title:'Fabricante',keys:['Manufacturer'],icon:'bi-cpu'},
    {title:'Modelo',keys:['Product Class'],icon:'bi-router'}
  ];
  const valueFor=(keys)=>{
    for(const tr of overview.querySelectorAll('tr')){
      const key=tr.querySelector('th');
      const val=tr.querySelector('td');
      if(!key||!val)continue;
      if(keys.some(k=>key.textContent.trim().toLowerCase()===k.toLowerCase())){
        return val.textContent.trim().replace(/\s+/g,' ').slice(0,120)||'—';
      }
    }
    return '—';
  };
  const enhance=()=>{
    if(!overview.firstElementChild || overview.querySelector('.jrc-detail-kpis'))return;
    const strip=document.createElement('section');
    strip.className='jrc-detail-kpis';
    strip.setAttribute('aria-label','Resumo técnico do dispositivo');
    for(const item of allowed){
      const card=document.createElement('div');
      card.className='jrc-detail-kpi';
      const heading=document.createElement('div');
      heading.className='jrc-detail-kpi-heading';
      const icon=document.createElement('i');
      icon.className='bi '+item.icon;
      icon.setAttribute('aria-hidden','true');
      heading.append(icon,document.createTextNode(item.title));
      const value=document.createElement('strong');
      value.textContent=valueFor(item.keys);
      card.append(heading,value);
      strip.append(card);
    }
    overview.prepend(strip);
    // Label only existing blocks. All forms, links, password toggles and IDs stay intact.
    for(const section of overview.querySelectorAll(':scope > .row > .col-md-6,:scope > .row > .col-md-12')){
      section.classList.add('jrc-detail-section-column');
    }
    for(const heading of overview.querySelectorAll('h6')){
      heading.classList.add('jrc-detail-section-title');
    }
  };
  // Original device-detail.js redraws overview on manual/automatic refresh.
  // Observe only direct children to avoid reacting to labels or optical updates.
  new MutationObserver(()=>enhance()).observe(overview,{childList:true});
  enhance();
})();
