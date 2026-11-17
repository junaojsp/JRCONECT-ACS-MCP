<?php
declare(strict_types=1);
require_once __DIR__.'/config/config.php';
requireLogin();
$pageTitle = 'Visao geral - Leve';
$currentPage = 'dashboard';
include __DIR__.'/views/layouts/header.php';
?>
<style>
.jrc-overview-light{width:100%;color:#e7f3ff}
.jrc-overview-light .sub{font-size:12px;color:#91b2c9}
.jrc-overview-light .head{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:18px}
.jrc-overview-light h2{font-size:23px;font-weight:650;margin:0 0 5px}
.jrc-overview-light .cards{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-bottom:18px}
.jrc-overview-light .metric{background:#0c243b;border:1px solid #215176;padding:18px;border-radius:11px;min-height:125px}
.jrc-overview-light .metric-icon{font-size:19px;color:#53b9f5;margin-bottom:8px}
.jrc-overview-light .value{font-size:29px;font-weight:700;color:#65cafa;line-height:1.25}
.jrc-overview-light .label{font-size:12px;color:#a4c1d6;margin-top:5px}
.jrc-overview-light .panel{background:#0c2137;border:1px solid #215176;border-radius:11px;padding:18px;margin-bottom:14px}
.jrc-overview-light .shortcut-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-top:13px}
.jrc-overview-light .shortcut{display:flex;align-items:center;gap:11px;text-decoration:none;border:1px solid #285777;border-radius:8px;padding:14px;background:#11304b;color:#d9efff}
.jrc-overview-light .shortcut:hover{background:#1a4564;color:#fff}
.jrc-overview-light .shortcut i{color:#67bfff;font-size:22px}
.jrc-overview-light .shortcut strong{display:block;font-size:14px}
.jrc-overview-light .shortcut small{display:block;color:#9fbed2;font-size:11px}
.jrc-overview-light .notice{padding:10px 12px;border-radius:7px;background:#3b2b1a;border:1px solid #876137;color:#ffd390;font-size:12px;margin-top:12px}
.jrc-overview-light .btn-alt{border:1px solid #386587;color:#b8d9ed;text-decoration:none;border-radius:7px;padding:8px 11px;font-size:12px}
@media(max-width:900px){.jrc-overview-light .cards,.jrc-overview-light .shortcut-grid{grid-template-columns:1fr}}

/* Reference-inspired layout: two large donut panels, two compact ranked tables. */
.jrc-overview-light .reference-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin-bottom:16px}
.jrc-overview-light .reference-panel{background:#0c2137;border:1px solid #215176;border-radius:11px;padding:18px;min-height:250px}
.jrc-overview-light .reference-panel h3{font-size:15px;color:#edf8ff;margin:0 0 14px;font-weight:650}
.jrc-overview-light .reference-body{display:flex;gap:20px;justify-content:center;align-items:center;flex-wrap:wrap;min-height:192px}
.jrc-overview-light .reference-ring{width:180px;height:180px;border-radius:50%;background:#18435d;display:grid;place-items:center;flex:none}
.jrc-overview-light .reference-hole{background:#0c2137;width:112px;height:112px;border-radius:50%;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center}
.jrc-overview-light .reference-hole b{font-size:24px;color:#f1faff}
.jrc-overview-light .reference-hole small{font-size:10px;color:#aac3d8}
.jrc-overview-light .reference-legend{display:grid;gap:8px;font-size:12px;min-width:135px}
.jrc-overview-light .reference-legend-item{display:flex;gap:9px;align-items:center;color:#d4e8f5}
.jrc-overview-light .reference-dot{width:10px;height:10px;border-radius:50%;flex:none}
.jrc-overview-light .reference-note{font-size:12px;color:#a5c6da;text-align:center;max-width:340px}
.jrc-overview-light .reference-table{width:100%;border-collapse:collapse;font-size:12px}
.jrc-overview-light .reference-table th,.jrc-overview-light .reference-table td{padding:10px;border-bottom:1px solid #244964;text-align:left}
.jrc-overview-light .reference-table th{color:#b7d9ec;background:#15405d}
.jrc-overview-light .reference-table td{color:#daeffd}
.jrc-overview-light .reference-table tbody tr:hover{background:#173650}
.jrc-overview-light .reference-table a{color:#76ccff;text-decoration:none}
.jrc-overview-light .reference-label{color:#9ebbd0;font-size:11px;margin-top:8px}
@media(max-width:980px){.jrc-overview-light .reference-grid{grid-template-columns:1fr}}
</style>
<link rel="stylesheet" href="/assets/css/jrc-dashboard-20.css?v=2">
<section class="jrc-overview-light">
  <div class="head">
    <div><span class="jrc20-eyebrow">JR CONECT · ACS 2.0</span><h2>Visão geral da rede</h2><p class="sub">Inventário em cache · detalhes técnicos sob demanda</p></div>
    <div class="jrc20-actions"><a href="/dashboard.php">Versão anterior</a><button type="button" id="jrc20-refresh">Atualizar visualização</button></div>
  </div>
  <div class="jrc20-summary-strip">
    <div class="metric"><i class="bi bi-router metric-icon"></i><div><div class="value" id="jrc-overview-total">—</div><div class="label">CPEs no inventário ACS</div></div></div>
    <div class="metric"><i class="bi bi-clock-history metric-icon"></i><div><div class="value jrc20-date" id="jrc-overview-age">—</div><div class="label">Último inventário</div></div></div>
    <div class="metric"><i class="bi bi-database-check metric-icon"></i><div><div class="value" id="jrc-overview-state">—</div><div class="label">Condição do snapshot</div></div></div>
  </div>
  <section class="reference-panel jrc20-wide" aria-label="Visão analítica do inventário">
    <div class="jrc20-panel-heading"><div><h3><i class="bi bi-activity"></i> Visão analítica da frota</h3><p>Distribuição por fabricante no último inventário; não é histórico de conexões</p></div><span class="jrc20-meta" id="jrc20-fleet-note">Aguardando snapshot</span></div>
    <div id="jrc20-fleet-bars" class="jrc20-fleet-bars" aria-live="polite"><p class="reference-note">Carregando distribuição da frota...</p></div>
    <div class="jrc20-wide-foot"><span id="jrc-makers-note">Aguardando inventário armazenado</span><span>Fonte: GenieACS · cache local</span></div>
  </section>
  <div class="jrc20-two">
    <section class="reference-panel" aria-label="Distribuição por fabricante">
      <div class="jrc20-panel-heading"><h3><i class="bi bi-pie-chart"></i> Fabricantes</h3><span class="jrc20-meta">Inventário ACS</span></div>
      <div class="reference-body">
        <div id="jrc-makers-ring" class="reference-ring"><div class="reference-hole"><b id="jrc-makers-total">—</b><small>CPEs</small></div></div>
        <div id="jrc-makers-legend" class="reference-legend" aria-live="polite">Carregando fabricantes...</div>
      </div>
    </section>
    <section class="reference-panel" aria-label="Clientes com e sem TR-069">
      <div class="jrc20-panel-heading"><h3><i class="bi bi-diagram-3"></i> Cobertura TR-069</h3><span class="jrc20-meta">Clientes IXC</span></div>
      <div class="reference-body">
        <div id="jrc-coverage-ring" class="reference-ring"><div class="reference-hole"><b id="jrc-coverage-total">—</b><small>Clientes</small></div></div>
        <div id="jrc-coverage-legend" class="reference-legend"><div class="reference-note">Aguardando vinculação única de clientes IXC e dispositivos TR-069. Sem estimativas.</div></div>
      </div>
      <p class="reference-label" id="jrc-coverage-note">Sem consultas a contratos. Total de ONUs não representa clientes únicos.</p>
    </section>
  </div>
  <div class="jrc20-three">
    <section class="reference-panel">
      <div class="jrc20-panel-heading"><h3><i class="bi bi-cpu"></i> Modelos de CPE</h3></div>
      <table class="reference-table"><thead><tr><th>Modelo</th><th>Total</th></tr></thead><tbody id="jrc-models-rows"><tr><td colspan="2">Carregando...</td></tr></tbody></table>
    </section>
    <section class="reference-panel">
      <div class="jrc20-panel-heading"><h3><i class="bi bi-hdd-stack"></i> Fabricantes</h3></div>
      <table class="reference-table"><thead><tr><th>Fabricante</th><th>Total</th></tr></thead><tbody id="jrc-makers-rows"><tr><td colspan="2">Carregando...</td></tr></tbody></table>
    </section>
    <section class="reference-panel jrc20-links">
      <div class="jrc20-panel-heading"><h3><i class="bi bi-grid"></i> Acesso operacional</h3></div>
      <div class="shortcut-grid">
        <a class="shortcut" href="/devices-light.php"><i class="bi bi-search"></i><span><strong>Dispositivos</strong><small>Pesquisar equipamentos e abrir detalhes técnicos</small></span></a>
        <a class="shortcut" href="/view-logs.php"><i class="bi bi-activity"></i><span><strong>Relatórios</strong><small>Histórico e sinal óptico</small></span></a>
        <a class="shortcut" href="/configuration.php"><i class="bi bi-sliders"></i><span><strong>Configurações</strong><small>Administração ACS</small></span></a>
      </div>
      <div id="jrc-overview-warning" class="notice" hidden></div>
    </section>
  </div>
  <p class="jrc20-footer">Dados provenientes do último inventário armazenado. Esta tela não consulta ONUs, RX, temperatura, IXC ou TR-069 ao abrir.</p>
</section>
<script src="/assets/js/jrc-dashboard-light.js?v=3" defer></script>
<?php include __DIR__.'/views/layouts/footer.php'; ?>
