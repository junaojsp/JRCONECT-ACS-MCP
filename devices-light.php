<?php
declare(strict_types=1);
require_once __DIR__.'/config/config.php';
requireLogin();
$pageTitle='Dispositivos - Pesquisa rapida';
$currentPage='devices';
include __DIR__.'/views/layouts/header.php';
?>
<style>
.jrc-light{max-width:1600px;margin:0 auto;color:#dceeff}
.jrc-light .card{background:#0c2137!important;border:1px solid #1b466a!important;border-radius:12px}
.jrc-light .card-body{padding:18px}
.jrc-light .form-control,.jrc-light .form-select{background:#102d47!important;color:#eaf6ff!important;border:1px solid #24567b!important}
.jrc-light .form-control::placeholder{color:#91aac0}
.jrc-light .table{--bs-table-bg:#0e2944;--bs-table-color:#e6f5ff;--bs-table-border-color:#28516c;--bs-table-hover-bg:#173b5b;font-size:13px}
.jrc-light .table thead th{background:#154261!important;color:#c0dded!important;font-size:11px;letter-spacing:.03em}
.jrc-light .table tbody td{vertical-align:middle}
.jrc-light a{color:#77cbff}
.jrc-light small,.jrc-light .muted{color:#9cb9ce}
.jrc-light .toolbar{display:grid;grid-template-columns:minmax(250px,2fr) 1fr 1fr auto;gap:10px;align-items:end}
@media(max-width:850px){.jrc-light .toolbar{grid-template-columns:1fr 1fr}}
@media(max-width:500px){.jrc-light .toolbar{grid-template-columns:1fr}}
</style>
<main class="jrc-light">
 <div class="d-flex align-items-center justify-content-between mb-3">
  <div><h3 class="mb-1">Dispositivos</h3><small>Pesquisa de CPEs · detalhes tecnicos apenas ao abrir o dispositivo</small></div>
  <a class="btn btn-outline-info btn-sm" href="/devices.php">Lista anterior</a>
 </div>
 <div class="card mb-3"><div class="card-body">
   <form id="light-filter" class="toolbar">
     <div><label class="form-label" for="light-search">Pesquisar</label>
       <input id="light-search" class="form-control" type="search" maxlength="100" placeholder="Serial, PPPoE, MAC, IP, cliente ou ID" autocomplete="off"></div>
     <div><label class="form-label" for="light-maker">Fabricante</label>
       <input id="light-maker" class="form-control" maxlength="70" placeholder="Todos"></div>
     <div><label class="form-label" for="light-model">Modelo</label>
       <input id="light-model" class="form-control" maxlength="70" placeholder="Todos"></div>
     <div><button class="btn btn-primary w-100" type="submit">Pesquisar</button></div>
   </form>
 </div></div>
 <div class="card"><div class="card-body">
   <div class="d-flex align-items-center justify-content-between mb-2">
     <span id="light-count" class="muted" role="status">Carregando inventario...</span>
     <span id="light-age" class="muted"></span>
   </div>
   <div class="table-responsive"><table class="table table-hover">
     <thead><tr><th>Equipamento</th><th>Cliente / PPPoE</th><th>MAC</th><th>IP WAN</th><th>Ultima comunicacao</th><th>Abrir</th></tr></thead>
     <tbody id="light-rows"><tr><td colspan="6">Carregando...</td></tr></tbody>
   </table></div>
   <div class="d-flex justify-content-between align-items-center mt-2">
     <button type="button" class="btn btn-outline-info btn-sm" id="light-prev" disabled>Anterior</button>
     <span id="light-pagination" class="muted"></span>
     <button type="button" class="btn btn-outline-info btn-sm" id="light-next" disabled>Proxima</button>
   </div>
 </div></div>
</main>
<script src="/assets/js/jrc-devices-light.js?v=1" defer></script>
<?php include __DIR__.'/views/layouts/footer.php'; ?>
