<?php
declare(strict_types=1);
require_once __DIR__.'/config/config.php';
requireLogin();
$pageTitle='Dashboard ACS 2.0';
$currentPage='dashboard';
include __DIR__.'/views/layouts/header.php';
?>
<link rel="stylesheet" href="/assets/css/jrc-dashboard-reference.css?v=2">
<main class="jrc-overview-light jrc-ref-layout">
 <header class="jr-head">
  <div><span class="jr-eyebrow">JR CONECT TELECOM · NETWORK OPERATIONS</span><h1>Dashboard ACS 2.0</h1><p>Monitoramento do inventário TR-069 · dados reais armazenados</p></div>
  <div class="jr-head-actions"><span class="jr-source"><span></span> GENIEACS</span><a href="/dashboard-legacy.php">Versão anterior</a><button id="jrc20-refresh" type="button"><i class="bi bi-arrow-clockwise"></i> Atualizar</button></div>
 </header>
 <section class="jr-panel jr-timeseries" aria-label="Histórico do inventário ACS">
  <div class="jr-panel-heading">
   <div class="jr-heading-left"><span class="jr-icon blue"><i class="bi bi-activity"></i></span><div><h2>Histórico do inventário ACS</h2><p>Evolução dos equipamentos cadastrados por snapshot · não representa clientes conectados</p></div></div>
   <div class="jr-chart-actions"><span id="jr-history-range" class="jr-chip">Últimos 30 dias</span><span class="jr-chip">Inventário ACS</span></div>
  </div>
  <div class="jr-history-area">
   <div class="jr-history-top"><div><span>Dispositivos no último snapshot</span><strong id="jrc-overview-total">—</strong></div><div class="jr-series-key"><i></i> Inventário total</div></div>
   <div id="jr-history-chart" class="jr-chart" role="img" aria-label="Histórico real dos equipamentos cadastrados no ACS"><div class="jr-empty">Carregando histórico...</div></div>
  </div>
  <div class="jr-history-footer"><span id="jr-history-status">Lendo snapshots disponíveis</span><span id="jrc20-fleet-note">Inventário armazenado</span></div>
 </section>
 <section class="jr-duo" aria-label="Análise da frota">
  <article class="jr-panel jr-makers">
   <div class="jr-panel-heading"><div class="jr-heading-left"><span class="jr-icon orange"><i class="bi bi-bar-chart-fill"></i></span><div><h2>Distribuição por fabricante</h2><p>Equipamentos do inventário ACS · último snapshot</p></div></div><span class="jr-chip">Fabricantes</span></div>
   <div id="jrc20-fleet-bars" class="jrc20-fleet-bars">Carregando fabricantes...</div>
   <div id="jrc-makers-note" class="jr-panel-footer">Fonte: cache GenieACS</div>
  </article>
  <article class="jr-panel jr-tr069">
   <div class="jr-panel-heading"><div class="jr-heading-left"><span class="jr-icon violet"><i class="bi bi-broadcast"></i></span><div><h2>Comunicação TR-069</h2><p>Qualidade da informação de contato no inventário ACS</p></div></div><span class="jr-chip">ACS</span></div>
   <div class="jr-tr069-body">
     <div id="jrc-coverage-ring" class="reference-ring"><div class="reference-hole"><b id="jrc-coverage-total">—</b><small>Dispositivos</small></div></div>
     <div class="jr-tr069-side"><span>Com Inform nas 24h do snapshot</span><strong id="jrc20-tr069-rate">Pendente</strong><div id="jrc-coverage-legend" class="reference-legend">Dados do ACS em processamento</div></div>
   </div>
   <div class="jr-panel-footer" id="jrc-coverage-note">Somente ACS; não consulta IXC ou contratos.</div>
  </article>
 </section>
 <section class="jr-bottom" aria-label="Resumo operacional">
  <article class="jr-panel jr-models"><div class="jr-panel-heading"><div class="jr-heading-left"><span class="jr-icon cyan"><i class="bi bi-cpu"></i></span><div><h2>Principais modelos de CPE</h2><p>Modelos identificados na base GenieACS</p></div></div><span class="jr-chip">Top 9</span></div>
   <table class="reference-table"><thead><tr><th>Modelo</th><th>Total</th></tr></thead><tbody id="jrc-models-rows"><tr><td colspan="2">Carregando...</td></tr></tbody></table>
  </article>
  <article class="jr-panel jr-operational"><div class="jr-panel-heading"><div class="jr-heading-left"><span class="jr-icon green"><i class="bi bi-grid-1x2-fill"></i></span><div><h2>Resumo operacional</h2><p>Estado da coleta e acessos do ACS</p></div></div></div>
   <div class="jr-kpis"><div><span><i class="bi bi-clock-history"></i> Última coleta</span><strong id="jrc-overview-age">—</strong></div><div><span><i class="bi bi-database-check"></i> Condição do snapshot</span><strong id="jrc-overview-state">—</strong></div></div>
   <nav class="jr-links"><a href="/devices-light.php"><i class="bi bi-router"></i> Dispositivos <i class="bi bi-arrow-up-right"></i></a><a href="/view-logs.php"><i class="bi bi-graph-up"></i> Relatórios <i class="bi bi-arrow-up-right"></i></a><a href="/configuration.php"><i class="bi bi-sliders"></i> Configurações <i class="bi bi-arrow-up-right"></i></a></nav>
   <div id="jrc-overview-warning" class="jr-warning" role="status" hidden></div>
  </article>
 </section>
 <p class="jr-footnote">Dashboard de inventário histórico. Não exibe conexões online, IPs duplicados ou desconexões sem fontes temporais verificadas. Nenhuma consulta a CPE é executada na abertura da página.</p>
</main>
<script src="/assets/js/jrc-dashboard-light.js?v=8" defer></script>
<script src="/assets/js/jrc-dashboard-history.js?v=1" defer></script>
<?php include __DIR__.'/views/layouts/footer.php'; ?>
