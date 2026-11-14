<?php
require_once __DIR__ . '/config/config.php';
requireLogin();
$inventoryId = $_GET['id'] ?? '';
if (!is_string($inventoryId) || $inventoryId === '' || strlen($inventoryId) > 1024) {
    header('Location: /devices-noc.php'); exit;
}
$inventoryDetail = true;
include __DIR__ . '/views/layouts/noc-inventory-header.php';
?>
<a class="device-back" href="/devices-noc.php">← Equipamentos</a>
<div class="device-titlebar"><span class="device-router"><i class="bi bi-hdd-stack"></i></span><div><div class="device-title"><h2 id="device-model">Consultando equipamento…</h2><span class="noc-chip" id="device-status">Aguardando</span></div><p id="device-identity">—</p></div><a class="noc-button device-action" href="/device-detail.php?id=<?php echo rawurlencode($inventoryId); ?>"><i class="bi bi-lightning-charge"></i> Ações do equipamento ↗</a></div>
<div class="device-tabs" role="tablist" aria-label="Seções do equipamento">
<?php foreach (['overview' => 'Visão geral', 'actions' => 'Ações', 'history' => 'Histórico', 'optical' => 'Óptico', 'wifi' => 'Wi-Fi', 'wan' => 'WAN', 'system' => 'Sistema', 'voice' => 'Voz', 'hosts' => 'Rede / Hosts'] as $key => $label): ?>
<button type="button" role="tab" id="tab-<?php echo $key; ?>" data-section="<?php echo $key; ?>" aria-controls="device-panels" aria-selected="<?php echo $key === 'overview' ? 'true' : 'false'; ?>" tabindex="<?php echo $key === 'overview' ? '0' : '-1'; ?>"><?php echo $label; ?></button>
<?php endforeach; ?>
</div>
<div class="device-subnav"><span>Dados disponíveis</span><a href="/device-detail.php?id=<?php echo rawurlencode($inventoryId); ?>">Parâmetros completos na tela atual ↗</a></div>
<div id="device-panels" role="tabpanel" aria-labelledby="tab-overview" tabindex="0">
<article class="noc-panel device-diagnostic-panel" id="device-overview" data-sections="overview"><h3>Diagnóstico rápido</h3><div class="inventory-diagnostics" id="device-diagnostics"><p>Consultando parâmetros…</p></div></article>
<div class="device-visual-grid">
  <article class="noc-panel device-signal-panel" id="device-optical" data-sections="overview optical"><h3>Sinal óptico <small>TR-069 / IXC</small></h3><div id="device-gauge"></div><div class="device-rx-chart" id="device-rx-chart"></div><p class="device-chart-note">Última leitura disponível · série histórica ainda não integrada.</p></article>
  <article class="noc-panel device-traffic-panel" data-sections="overview wan"><h3>Tráfego WAN <small>Dados reportados</small></h3><div id="device-traffic"></div></article>
</div>
<section class="device-indicators" data-sections="overview optical system"><h3><i class="bi bi-activity"></i> Indicadores</h3><div class="inventory-metrics" id="device-metrics"></div></section>
<div class="device-properties-grid">
  <article class="noc-panel" data-sections="overview"><h3>Conexão &amp; Wi-Fi</h3><dl class="inventory-properties" id="device-connections"></dl></article>
  <article class="noc-panel" id="device-system" data-sections="overview system"><h3>Equipamento</h3><dl class="inventory-properties" id="device-properties"></dl></article>
</div>
<section class="device-current-issues" data-sections="overview"><h3><i class="bi bi-bell"></i> Ocorrências desta leitura</h3><div id="device-current-issues"></div></section>
<article class="noc-panel" id="device-wan" data-sections="wan" hidden><h3>Conexões WAN</h3><div id="device-wans"></div></article>
<article class="noc-panel" id="device-wifi" data-sections="wifi" hidden><h3>Redes Wi-Fi</h3><div id="device-networks"></div></article>
<article class="noc-panel device-standalone" data-sections="history" hidden><h3>Histórico do equipamento</h3><p>Esta prévia exibe as leituras reportadas com seus horários. A série histórica óptica e de tráfego ainda não está integrada nesta tela.</p><a href="/device-detail.php?id=<?php echo rawurlencode($inventoryId); ?>">Consultar histórico na tela atual ↗</a></article>
<article class="noc-panel device-standalone" data-sections="actions" hidden><h3>Ações do equipamento</h3><p>Diagnósticos, Wi-Fi e manutenção continuam disponíveis na tela atual, com os controles existentes.</p><a class="noc-button" href="/device-detail.php?id=<?php echo rawurlencode($inventoryId); ?>">Abrir ações do equipamento ↗</a></article>
<article class="noc-panel device-standalone" data-sections="voice" hidden><h3>Voz</h3><div id="device-voice"></div></article>
<article class="noc-panel device-standalone" data-sections="hosts" hidden><h3>Rede / Hosts</h3><div id="device-hosts"></div></article>
</div>
<p class="noc-disclaimer">Leitura somente · Dados ausentes aparecem como N/D. Comunicação ACS não confirma acesso à Internet. RX: normal &gt; -25; atenção -28 &lt; RX ≤ -25; crítico ≤ -28 dBm.</p>
</section></main><script src="/assets/js/noc-device.js?v=2"></script><script src="/assets/js/noc-inventory.js?v=3"></script></body></html>
