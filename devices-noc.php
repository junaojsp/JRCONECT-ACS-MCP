<?php
require_once __DIR__ . '/config/config.php';
requireLogin();
$inventoryDetail = false;
include __DIR__ . '/views/layouts/noc-inventory-header.php';
?>
<div class="inventory-stats" aria-label="Resumo dos equipamentos carregados">
  <article><span>Equipamentos ACS</span><strong id="inventory-total">—</strong><small>Inventário carregado</small></article>
  <article><span>Online TR-069</span><strong class="inventory-green" id="inventory-online">—</strong><small>Inform há menos de 5 min</small></article>
  <article><span>Sem Inform recente</span><strong id="inventory-offline">—</strong><small>Não confirma falha da Internet</small></article>
  <article><span>RX crítico</span><strong class="inventory-red" id="inventory-critical">—</strong><small id="inventory-rx-coverage">RX ≤ -28 dBm · IXC / TR-069</small></article>
</div>
<article class="noc-panel">
  <header><div><h2><i class="bi bi-router"></i> Inventário de equipamentos</h2><span id="inventory-load-status" role="status">Consultando dispositivos…</span></div><a href="/devices.php">Lista atual e ações em lote ↗</a></header>
  <div class="inventory-tools">
    <label class="inventory-search">Buscar equipamento<input id="inventory-search" type="search" placeholder="Serial, fabricante, modelo ou IP" maxlength="200"></label>
    <label>Comunicação<select id="inventory-status"><option value="all">Todos</option><option value="online">Online TR-069</option><option value="offline">Sem Inform recente</option></select></label>
    <label>Sinal RX<select id="inventory-signal"><option value="all">Todos</option><option value="good">Normal (&gt; -25)</option><option value="attention">Atenção (-28 a -25)</option><option value="critical">Crítico (≤ -28)</option><option value="unavailable">Sem leitura</option></select></label>
  </div>
  <div class="noc-table-wrap"><table class="noc-table inventory-table"><thead><tr><th>Equipamento</th><th>IP reportado</th><th>Sinal RX</th><th>Comunicação ACS</th><th>Tempo ligado</th><th>Último Inform</th><th></th></tr></thead><tbody id="inventory-rows"><tr><td colspan="7" class="noc-empty">Aguardando consulta…</td></tr></tbody></table></div>
  <footer class="inventory-pagination"><span id="inventory-page-label">—</span><div><button class="noc-button" type="button" id="inventory-prev">← Anterior</button><button class="noc-button" type="button" id="inventory-next">Próxima →</button></div></footer>
</article>
<p class="noc-disclaimer">Prévia de leitura. RX IXC prioritário, TR-069 como alternativa; a data do sinal pode ser anterior ao último Inform. Busca e filtros abrangem os registros carregados.</p>
</section></main><script src="/assets/js/noc-inventory.js?v=3"></script></body></html>
