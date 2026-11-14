<?php
/* JR Conect ACS — Firmware Lab UI v1.
 * Presentation only. No upload, firmware task, TR-069 writes or database writes.
 */
require_once __DIR__ . '/config/config.php';
requireLogin();
$pageTitle = 'Gerenciamento de Firmware';
$currentPage = 'firmware';
include __DIR__ . '/views/layouts/header.php';
?>
<style>
.jrc-fw {--fw-bg:#071827;--fw-card:#0c2136;--fw-border:#1c4b70;--fw-accent:#1c6cc2;color:#e5edf8;max-width:1520px;margin:auto}
.jrc-fw .lead{font-size:13px;color:#9bb7d0}
.jrc-fw .fw-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:13px;margin:18px 0}
.jrc-fw .fw-card{background:var(--fw-card);border:1px solid var(--fw-border);border-radius:12px;padding:18px}
.jrc-fw .fw-num{font-size:25px;font-weight:700;color:#eef8ff}
.jrc-fw .fw-label{font-size:12px;color:#9db5c9}
.jrc-fw .fw-pill{display:inline-block;background:#173b56;color:#b7e6ff;border:1px solid #285c7d;border-radius:20px;padding:4px 10px;font-size:12px}
.jrc-fw .fw-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:20px 0 14px}
.jrc-fw .fw-tabs button{background:#112a43;border:1px solid #31516d;color:#e7f3fc;border-radius:8px;padding:10px 15px}
.jrc-fw .fw-tabs button[aria-selected="true"]{background:var(--fw-accent);border-color:#378ada}
.jrc-fw .fw-panel[hidden]{display:none}
.jrc-fw table{width:100%;color:#d9e8f5;border-collapse:collapse;font-size:13px}
.jrc-fw td,.jrc-fw th{padding:12px;border-bottom:1px solid #254057;text-align:left}
.jrc-fw th{color:#a0c1d8}
.jrc-fw .fw-note{border-left:3px solid #47b8ed;background:#0e283d;border-radius:5px;padding:13px;font-size:13px;color:#c5dfef}
.jrc-fw .fw-steps{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin:14px 0}
.jrc-fw .fw-step{padding:12px;border:1px dashed #3f6279;border-radius:9px;color:#bcd3e5;font-size:12px}
@media(max-width:850px){.jrc-fw .fw-grid,.jrc-fw .fw-steps{grid-template-columns:1fr 1fr}}
@media(max-width:540px){.jrc-fw .fw-grid,.jrc-fw .fw-steps{grid-template-columns:1fr}}
</style>
<section class="jrc-fw" aria-label="Gerenciador de firmware para bancada">
  <header>
    <h2 style="font-size:22px;font-weight:700;margin:0 0 6px">Gerenciamento de Firmware</h2>
    <p class="lead">Catálogo, homologação, atualizações supervisionadas e histórico de execução.</p>
    <span class="fw-pill">Modo de bancada — somente leitura</span>
  </header>
  <div class="fw-grid" aria-label="Resumo do catálogo">
    <div class="fw-card"><div class="fw-label">Imagens homologadas</div><div class="fw-num">0</div><div class="fw-label">Nenhum firmware cadastrado neste módulo</div></div>
    <div class="fw-card"><div class="fw-label">ONUs de bancada vinculadas</div><div class="fw-num">0</div><div class="fw-label">Aguardando escolha do equipamento</div></div>
    <div class="fw-card"><div class="fw-label">Campanhas ativas</div><div class="fw-num">0</div><div class="fw-label">Execução desabilitada nesta fase</div></div>
  </div>
  <!-- JRC_FIRMWARE_LEGACY_LINK -->
  <div class="fw-note" role="status">Esta primeira versão é apenas a interface. Os valores acima pertencem ao novo módulo, não ao inventário geral. Nenhum firmware será enviado nem será executada qualquer tarefa no GenieACS.</div>
  <nav class="fw-tabs" aria-label="Abas do firmware">
    <button type="button" role="tab" id="fw-tab-catalogo" aria-controls="fw-panel-catalogo" aria-selected="true" data-fw-tab="catalogo">Catálogo</button>
    <button type="button" role="tab" id="fw-tab-atualizacoes" aria-controls="fw-panel-atualizacoes" aria-selected="false" data-fw-tab="atualizacoes">Atualizações</button>
    <button type="button" role="tab" id="fw-tab-historico" aria-controls="fw-panel-historico" aria-selected="false" data-fw-tab="historico">Histórico</button>
  </nav>
  <div id="fw-panel-catalogo" role="tabpanel" aria-labelledby="fw-tab-catalogo" class="fw-panel fw-card">
    <h3 style="font-size:17px">Catálogo de imagens homologadas</h3>
    <table><thead><tr><th>Fabricante / Modelo</th><th>Versão</th><th>Revisão de hardware</th><th>Status</th></tr></thead>
    <tbody><tr><td colspan="4">Nenhum firmware cadastrado. Para começar, selecione uma ONU de bancada e obtenha a imagem oficial compatível.</td></tr></tbody></table>
    <p class="lead" style="margin-top:15px">Cadastro e upload serão habilitados apenas após definição de armazenamento, validação de integridade e política de homologação.</p>
  </div>
  <div id="fw-panel-atualizacoes" role="tabpanel" aria-labelledby="fw-tab-atualizacoes" class="fw-panel fw-card" hidden>
    <h3 style="font-size:17px">Atualizações supervisionadas</h3>
    <div class="fw-steps"><div class="fw-step">1. Validar modelo, revisão e versão</div><div class="fw-step">2. Preparar arquivo e ONU de bancada</div><div class="fw-step">3. Executar tarefa supervisionada</div><div class="fw-step">4. Confirmar versão após reconexão</div></div>
    <div class="fw-note">Nenhuma atualização programada. Envio e execução permanecem desabilitados.</div>
  </div>
  <div id="fw-panel-historico" role="tabpanel" aria-labelledby="fw-tab-historico" class="fw-panel fw-card" hidden>
    <h3 style="font-size:17px">Histórico das atualizações</h3>
    <table><thead><tr><th>Equipamento</th><th>Versão anterior</th><th>Nova versão</th><th>Data</th><th>Resultado</th></tr></thead>
    <tbody><tr><td colspan="5">Nenhuma execução registrada neste módulo.</td></tr></tbody></table>
  </div>
</section>
<script>
(function(){
  const tabs=Array.from(document.querySelectorAll('[data-fw-tab]'));
  function selectTab(button){
    tabs.forEach(function(tab){
      const selected=tab===button;
      const id=tab.getAttribute('data-fw-tab');
      tab.setAttribute('aria-selected',selected?'true':'false');
      document.getElementById('fw-panel-'+id).hidden=!selected;
    });
  }
  tabs.forEach(function(button){
    button.addEventListener('click',function(){selectTab(button);});
    button.addEventListener('keydown',function(event){
      if(event.key!=='ArrowLeft'&&event.key!=='ArrowRight')return;
      event.preventDefault();
      const index=tabs.indexOf(button);
      const next=tabs[(index+(event.key==='ArrowRight'?1:-1)+tabs.length)%tabs.length];
      selectTab(next);next.focus();
    });
  });
})();
</script>
<?php include __DIR__ . '/views/layouts/footer.php'; ?>
