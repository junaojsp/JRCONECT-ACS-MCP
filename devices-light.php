<?php
declare(strict_types=1);
require_once __DIR__.'/config/config.php';
requireLogin();
$pageTitle='Dispositivos';
$currentPage='devices';
include __DIR__.'/views/layouts/header.php';
?>
<style>
.jrc-dir{max-width:none;width:100%;color:#dceeff;padding:0 4px}
.jrc-dir .jrc-dir-panel{background:#0b2136;border:1px solid #204561;border-radius:8px;margin-bottom:12px}
.jrc-dir .jrc-dir-top{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px}
.jrc-dir h2{font-size:22px;font-weight:600;margin:0}
.jrc-dir .jrc-dir-sub{color:#92b2ca;font-size:12px}
.jrc-dir .jrc-dir-toolbar{display:flex;flex-wrap:wrap;gap:9px;padding:13px}
.jrc-dir .jrc-dir-btn{font-size:12px;background:#176c9e;color:#fff;border:1px solid #2280b6;border-radius:5px;padding:8px 12px;cursor:pointer}
.jrc-dir .jrc-dir-btn:hover{background:#2189c2}
.jrc-dir .jrc-dir-btn-secondary{background:#123c59;border-color:#295b7b}
.jrc-dir .jrc-dir-filters{display:grid;grid-template-columns:minmax(250px,1.7fr) 1fr 1fr;gap:18px;padding:16px 18px}
.jrc-dir label{display:block;color:#b8d7e9;font-size:12px;font-weight:600;margin-bottom:6px}
.jrc-dir input,.jrc-dir select{width:100%;border-radius:4px;border:1px solid #2a5878;background:#112f49;color:#eff8ff;font-size:13px;padding:10px;min-height:39px}
.jrc-dir input::placeholder{color:#8ba8c0}
.jrc-dir .jrc-dir-search-row{display:flex;gap:7px;margin-top:8px}
.jrc-dir .jrc-dir-meta{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;padding:12px 16px;color:#9ab9ce;font-size:12px}
.jrc-dir .jrc-dir-notice{border:1px solid #8b6130;background:#3b2b1a;color:#ffda8e;padding:9px 12px;border-radius:5px;margin:0 16px 12px;font-size:12px}
.jrc-dir .jrc-dir-table-wrap{overflow-x:auto;margin:0 12px}
.jrc-dir table{width:100%;border-collapse:collapse;font-size:12px}
.jrc-dir th{background:#194462;color:#d1e9fa;letter-spacing:.025em;text-align:left;padding:11px 10px;border-bottom:1px solid #295a79}
.jrc-dir td{padding:10px;border-bottom:1px solid #254a66;color:#dceefa}
.jrc-dir tbody tr:nth-child(odd){background:#0f2a43}
.jrc-dir tbody tr:nth-child(even){background:#14344f}
.jrc-dir tbody tr:hover{background:#1a4969}
.jrc-dir .jrc-dir-chip{font-size:10px;background:#164e70;border-radius:5px;padding:4px 7px;color:#bce4fb}
.jrc-dir .jrc-dir-actions{white-space:nowrap}
.jrc-dir a.jrc-dir-open{display:inline-block;text-decoration:none;background:#176c9e;color:white;padding:7px 9px;border-radius:4px}
.jrc-dir .jrc-dir-pagination{display:flex;justify-content:space-between;align-items:center;padding:13px 15px;color:#a9c5d9;font-size:12px}
.jrc-dir button:disabled{opacity:.4;cursor:not-allowed}
.jrc-dir .jrc-dir-optional-hide .jrc-dir-optional{display:none}
@media(max-width:850px){.jrc-dir .jrc-dir-filters{grid-template-columns:1fr;gap:10px}.jrc-dir .jrc-dir-meta{align-items:flex-start}}
</style>
<main class="jrc-dir">
  <div class="jrc-dir-top"><div><h2>CPE / Dispositivos</h2><span class="jrc-dir-sub">Inventario leve · abra um equipamento para consultar dados tecnicos</span></div><a class="jrc-dir-btn jrc-dir-btn-secondary" href="/devices.php">Tela anterior</a></div>
  <section class="jrc-dir-panel">
    <div class="jrc-dir-toolbar">
      <button type="button" class="jrc-dir-btn jrc-dir-btn-secondary" id="dir-columns"><i class="bi bi-layout-three-columns"></i> Editar colunas</button>
      <button type="button" class="jrc-dir-btn" id="dir-reload"><i class="bi bi-arrow-clockwise"></i> Recarregar informacoes</button>
      <span class="jrc-dir-sub" style="align-self:center">Sem consultas de sinal RX, temperatura ou SNMP na lista</span>
    </div>
    <form id="light-filter" class="jrc-dir-filters">
      <div><label for="light-search">Pesquisar</label><input id="light-search" type="search" maxlength="100" placeholder="Informe serial, PPPoE, MAC, IP ou cliente" autocomplete="off">
        <div class="jrc-dir-search-row"><button class="jrc-dir-btn" type="submit"><i class="bi bi-search"></i> Buscar</button></div>
      </div>
      <div><label for="light-maker">Fabricante</label><select id="light-maker"><option value="">Selecione um fabricante...</option></select></div>
      <div><label for="light-model">Modelo</label><select id="light-model"><option value="">Selecione um modelo...</option></select></div>
    </form>
  </section>
  <section class="jrc-dir-panel">
    <div class="jrc-dir-meta">
      <label for="light-per-page" style="margin:0">Resultados por pagina <select id="light-per-page" style="display:inline-block;width:auto;min-height:30px;margin-left:5px;padding:4px"><option value="10">10</option><option value="20">20</option><option value="50">50</option></select></label>
      <span id="light-count" role="status">Carregando inventario...</span>
      <span id="light-age"></span>
    </div>
    <div id="light-stale" class="jrc-dir-notice" role="status" hidden></div>
    <div id="dir-table" class="jrc-dir-table-wrap">
      <table><thead><tr>
        <th>Ultima comunicacao</th><th>PPPoE / Cliente</th><th class="jrc-dir-optional">MAC</th><th class="jrc-dir-optional">IP WAN</th>
        <th>Fabricante</th><th>Modelo</th><th>Acoes</th>
      </tr></thead><tbody id="light-rows"><tr><td colspan="7">Carregando...</td></tr></tbody></table>
    </div>
    <div class="jrc-dir-pagination">
      <span id="light-pagination">Pagina 1</span>
      <div style="display:flex;gap:8px"><button type="button" id="light-prev" class="jrc-dir-btn jrc-dir-btn-secondary" disabled>Anterior</button><button type="button" id="light-next" class="jrc-dir-btn jrc-dir-btn-secondary" disabled>Proxima</button></div>
    </div>
  </section>
</main>
<script src="/assets/js/jrc-devices-light.js?v=2" defer></script>
<?php include __DIR__.'/views/layouts/footer.php'; ?>
