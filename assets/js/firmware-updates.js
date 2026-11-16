(() => {
  'use strict';
  const get = id => document.getElementById(id);
  const form = get('fw-form');
  if (!form) return;
  const data = JSON.parse(get('fw-data').textContent);
  let selected = null;
  const norm = x => String(x || '').trim().toLowerCase();
  function choices() {
    const bench = get('fw-operation').value === 'bench';
    const files = data.files.filter(f => selected && norm(f.manufacturer) === norm(selected.manufacturer)
      && norm(f.model) === norm(selected.model) && norm(f.hw_revision) === norm(selected.hardware)
      && !!norm(selected.hardware) && f.version !== selected.version && (bench || f.status === 'homologado'));
    get('fw-file').replaceChildren(new Option('Selecione uma imagem', ''), ...files.map(f => new Option(f.version + ' — ' + (f.status === 'homologado' ? 'Homologado' : 'Teste de bancada'), f.id)));
    if (files.length === 1) get('fw-file').value = files[0].id;
    get('fw-match').textContent = files.length ? files.length + ' imagem(ns) compatível(is) no catálogo.' : 'Nenhuma imagem elegível. Cadastre o firmware ou selecione o teste de bancada para uma imagem ainda não homologada.';
    get('fw-validate').disabled = !files.length;
  }
  function choose(d) {
    selected = d; form.hidden = false;
    get('fw-device-id').value = d.id;
    get('fw-device').textContent = [d.manufacturer, d.model, 'Serial: ' + d.serial, 'Hardware: ' + d.hardware, 'Firmware: ' + d.version].join(' · ');
    choices();
  }
  async function search() {
    selected = null; form.hidden = true; get('fw-results').replaceChildren();
    const q = get('fw-query').value.trim();
    if (q.length < 3) { get('fw-status').textContent = 'Digite ao menos 3 caracteres.'; return; }
    get('fw-search').disabled = true; get('fw-status').textContent = 'Buscando no ACS…';
    try {
      const response = await fetch('/api/firmware-device-search.php?' + new URLSearchParams({type:get('fw-mode').value, term:q, csrf:data.csrf}), {credentials:'same-origin'});
      const result = await response.json();
      if (!response.ok) throw Error(result.error || 'Falha na busca.');
      get('fw-status').textContent = result.items.length + ' equipamento(s).' + (result.truncated ? ' Refine a busca para ver outros resultados.' : '');
      result.items.forEach(d => {
        const row = document.createElement('div');
        const button = document.createElement('button'); button.type = 'button';
        button.textContent = [d.manufacturer, d.model, d.serial].join(' · ');
        button.addEventListener('click', () => choose(d)); row.append(button); get('fw-results').append(row);
      });
    } catch (e) { get('fw-status').textContent = e.message || 'Falha ao consultar o ACS.'; }
    finally { get('fw-search').disabled = false; }
  }
  get('fw-search').addEventListener('click', search);
  get('fw-query').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); search(); } });
  get('fw-operation').addEventListener('change', choices);
  if (data.selected) choose(data.selected);
})();
