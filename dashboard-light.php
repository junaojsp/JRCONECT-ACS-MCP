<?php
declare(strict_types=1);
require_once __DIR__.'/config/config.php';
requireLogin();
$pageTitle='Dashboard ACS 2.0';
$currentPage='dashboard';
include __DIR__.'/views/layouts/header.php';
?>
<link rel="stylesheet" href="/assets/css/jrc-dashboard-made4.css?v=1">
<main class="jrc-overview-light jrc-made4">
  <header class="j4-header">
    <div><div class="j4-kicker">JRCONECT <span>TELECOM</span> / CENTRO DE OPERAÇÕES</div><h1>Dashboard da rede</h1><p>Visão do inventário ACS · dados consolidados em cache</p></div>
    <div class="j4-header-actions"><span class="j4-live"><span></span> Painel operacional</span><a href="/dashboard.php">Versão anterior</a><button id="jrc20-refresh" type="button"><i class="bi bi-arrow-clockwise"></i> Atualizar</button></div>
  </header>
  <section class="j4-hero" aria-label="Indicadores da rede">
    <article class="j4-hero-main">
      <div class="j4-hero-tag"><i class="bi bi-hdd-network"></i> INVENTÁRIO TR-069</div>
      <div class="j4-hero-content"><div><span class="j4-muted">Equipamentos no inventário</span><div class="j4-number" id="jrc-overview-total">—</div><span class="j4-cue"><i class="bi bi-database"></i> GenieACS · snapshot armazenado</span></div><div class="j4-hero-symbol"><i class="bi bi-router"></i></div></div>
      <div class="j4-hero-bottom"><span id="jrc20-fleet-note">Aguardando inventário</span><a href="/devices-light.php">Consultar dispositivos <i class="bi bi-arrow-up-right"></i></a></div>
    </article>
    <article class="j4-mini j4-mini-green"><div class="j4-mini-title"><span class="j4-mini-icon"><i class="bi bi-clock-history"></i></span><span>Última coleta</span></div><div class="j4-mini-value j4-date" id="jrc-overview-age">—</div><p>Inventário armazenado</p></article>
    <article class="j4-mini j4-mini-orange"><div class="j4-mini-title"><span class="j4-mini-icon"><i class="bi bi-shield-check"></i></span><span>Estado do inventário</span></div><div class="j4-mini-value" id="jrc-overview-state">—</div><p>Validade do snapshot</p></article>
    <article class="j4-mini j4-mini-purple"><div class="j4-mini-title"><span class="j4-mini-icon"><i class="bi bi-diagram-3"></i></span><span>Cobertura TR-069</span></div><div class="j4-mini-value" id="jrc20-tr069-rate">—</div><p>Clientes únicos com PPPoE no IXC</p></article>
  </section>
  <section class="j4-analytics" aria-label="Análise do inventário">
    <article class="j4-panel j4-manufacturers">
      <div class="j4-panel-head"><div><span class="j4-panel-icon blue"><i class="bi bi-bar-chart-fill"></i></span><div><h2>Fabricantes</h2><p>Participação na frota cadastrada</p></div></div><span class="j4-chip">GenieACS</span></div>
      <div class="j4-fleet-total"><span>Distribuição por fabricante</span><strong>INVENTÁRIO</strong></div>
      <div id="jrc20-fleet-bars" class="jrc20-fleet-bars" aria-live="polite">Carregando fabricantes...</div>
      <footer id="jrc-makers-note" class="j4-panel-foot">Aguardando dados do cache</footer>
    </article>
    <article class="j4-panel j4-coverage">
      <div class="j4-panel-head"><div><span class="j4-panel-icon purple"><i class="bi bi-broadcast"></i></span><div><h2>Clientes e TR-069</h2><p>Cobertura por cliente único</p></div></div><span class="j4-chip">IXC + ACS</span></div>
      <div class="j4-coverage-content">
        <div id="jrc-coverage-ring" class="reference-ring"><div class="reference-hole"><b id="jrc-coverage-total">—</b><small>Clientes</small></div></div>
        <div id="jrc-coverage-legend" class="reference-legend"><span>Coleta pendente. Nenhuma estimativa será exibida.</span></div>
      </div>
      <footer id="jrc-coverage-note" class="j4-panel-foot">Base: clientes distintos com PPPoE no IXC, sem contratos.</footer>
    </article>
  </section>
  <section class="j4-lower" aria-label="Modelos e operação">
    <article class="j4-panel j4-models">
      <div class="j4-panel-head"><div><span class="j4-panel-icon cyan"><i class="bi bi-cpu"></i></span><div><h2>Principais modelos</h2><p>Modelos identificados no inventário</p></div></div><span class="j4-chip">Top 9</span></div>
      <table class="reference-table"><thead><tr><th>Modelo da CPE</th><th>Unidades</th></tr></thead><tbody id="jrc-models-rows"><tr><td colspan="2">Carregando...</td></tr></tbody></table>
    </article>
    <article class="j4-panel j4-ops">
      <div class="j4-panel-head"><div><span class="j4-panel-icon green"><i class="bi bi-grid-1x2-fill"></i></span><div><h2>Central operacional</h2><p>Acesso rápido às ferramentas do ACS</p></div></div></div>
      <nav class="j4-ops-links">
        <a href="/devices-light.php"><span class="j4-op-ico"><i class="bi bi-router"></i></span><span><strong>Dispositivos</strong><small>Inventário e consultas</small></span><i class="bi bi-arrow-up-right"></i></a>
        <a href="/view-logs.php"><span class="j4-op-ico"><i class="bi bi-graph-up"></i></span><span><strong>Relatórios</strong><small>Históricos e registros</small></span><i class="bi bi-arrow-up-right"></i></a>
        <a href="/configuration.php"><span class="j4-op-ico"><i class="bi bi-sliders"></i></span><span><strong>Configurações</strong><small>Parâmetros do ACS</small></span><i class="bi bi-arrow-up-right"></i></a>
      </nav>
      <div class="j4-health"><i class="bi bi-info-circle"></i><span>Valores históricos. A abertura do painel não realiza consultas individuais aos dispositivos.</span></div>
      <div id="jrc-overview-warning" class="j4-warning" role="status" hidden></div>
    </article>
  </section>
  <footer class="j4-footer">JRCONECT ACS · Visão de inventário em cache <span>Dados de dispositivos não são coletados durante a abertura desta página.</span></footer>
</main>
<script src="/assets/js/jrc-dashboard-light.js?v=6" defer></script>
<?php include __DIR__.'/views/layouts/footer.php'; ?>
