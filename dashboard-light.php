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
</style>
<section class="jrc-overview-light">
  <div class="head">
    <div><h2>Visao geral</h2><p class="sub">Acesso rapido ao ACS · diagnosticos detalhados somente quando necessarios</p></div>
    <a class="btn-alt" href="/dashboard.php">Dashboard anterior</a>
  </div>
  <div class="cards">
    <div class="metric"><i class="bi bi-router metric-icon"></i><div class="value" id="jrc-overview-total">—</div><div class="label">Equipamentos no inventario</div></div>
    <div class="metric"><i class="bi bi-clock-history metric-icon"></i><div class="value" id="jrc-overview-age">—</div><div class="label">Ultimo inventario</div></div>
    <div class="metric"><i class="bi bi-database-check metric-icon"></i><div class="value" id="jrc-overview-state">—</div><div class="label">Estado do snapshot</div></div>
  </div>
  <div class="panel">
    <strong>Acessos rapidos</strong>
    <div class="shortcut-grid">
      <a class="shortcut" href="/devices-light.php"><i class="bi bi-search"></i><span><strong>Dispositivos</strong><small>Pesquisar CPEs e abrir detalhes</small></span></a>
      <a class="shortcut" href="/view-logs.php"><i class="bi bi-activity"></i><span><strong>Relatorios</strong><small>Consulta de sinal optico</small></span></a>
      <a class="shortcut" href="/mcp-server.php"><i class="bi bi-hdd-network"></i><span><strong>MCP Server</strong><small>Integracoes e infraestrutura</small></span></a>
    </div>
    <div id="jrc-overview-warning" class="notice" hidden></div>
  </div>
  <p class="sub">Esta tela consulta apenas o estado do inventario armazenado. Nao consulta ONUs, sinal RX, temperatura ou IXC ao abrir.</p>
</section>
<script src="/assets/js/jrc-dashboard-light.js?v=1" defer></script>
<?php include __DIR__.'/views/layouts/footer.php'; ?>
