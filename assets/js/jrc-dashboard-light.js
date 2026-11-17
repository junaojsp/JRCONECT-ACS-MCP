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
  load();
})();
