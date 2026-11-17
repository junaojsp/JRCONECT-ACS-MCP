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
