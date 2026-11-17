<?php
declare(strict_types=1);
require_once __DIR__.'/config/config.php';
requireLogin();
$id=trim((string)($_GET['id'] ?? ''));
if($id==='') { header('Location: /devices-light.php'); return; }
$pageTitle='Dispositivo | ACS 2.0';
$currentPage='devices';
include __DIR__.'/views/layouts/header.php';
?>
<link rel="stylesheet" href="/assets/css/jrc-device-noc.css?v=1">
<main class="jrc-noc" data-device-id="<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>">
 <header class="jrc-noc-head">
  <div><a href="/devices-light.php" class="jrc-noc-back"><i class="bi bi-arrow-left"></i> Voltar à lista</a>
   <div class="jrc-noc-kicker">JR CONECT · ACS 2.0</div>
   <h1 id="noc-title">Detalhes do dispositivo</h1>
   <p id="noc-sub">Consultando dados técnicos quando necessário...</p></div>
  <div class="jrc-noc-toolbar">
   <span id="noc-status" class="jrc-noc-pill">Aguardando</span>
   <button id="noc-refresh" type="button"><i class="bi bi-arrow-clockwise"></i> Atualizar</button>
   <a id="noc-classic" href="/device-detail.php?id=<?= rawurlencode($id) ?>"><i class="bi bi-sliders"></i> Gerenciar dispositivo</a>
  </div>
 </header>
 <p class="jrc-noc-alert" id="noc-error" role="status" hidden></p>
 <section class="jrc-noc-cards" aria-label="Indicadores do dispositivo">
  <article class="jrc-noc-card green"><i class="bi bi-reception-4"></i><span>Sinal óptico</span><strong id="noc-rx">—</strong><small>Última leitura disponível</small></article>
  <article class="jrc-noc-card blue"><i class="bi bi-clock-history"></i><span>Uptime</span><strong id="noc-uptime">—</strong><small>Informação reportada pela CPE</small></article>
  <article class="jrc-noc-card orange"><i class="bi bi-thermometer-half"></i><span>Temperatura</span><strong id="noc-temp">—</strong><small>Se fornecida pelo equipamento</small></article>
  <article class="jrc-noc-card violet"><i class="bi bi-pc-display"></i><span>Hosts conectados</span><strong id="noc-hosts">—</strong><small>Contagem disponível no ACS</small></article>
 </section>
 <section class="jrc-noc-grid">
  <article class="jrc-noc-panel"><h2><i class="bi bi-diagram-3"></i> Conexão e WAN</h2><dl>
    <div><dt>Endereço WAN</dt><dd id="noc-wan">—</dd></div>
    <div><dt>Usuário PPPoE</dt><dd id="noc-pppoe">—</dd></div>
    <div><dt>IP TR-069</dt><dd id="noc-tr069">—</dd></div>
    <div><dt>Último Inform</dt><dd id="noc-last">—</dd></div>
  </dl><p class="jrc-noc-note">Consultas e alterações avançadas continuam no gerenciador existente.</p></article>
  <article class="jrc-noc-panel"><h2><i class="bi bi-router"></i> Identificação da CPE</h2><dl>
    <div><dt>Fabricante</dt><dd id="noc-maker">—</dd></div>
    <div><dt>Modelo</dt><dd id="noc-model">—</dd></div>
    <div><dt>Serial</dt><dd id="noc-serial">—</dd></div>
    <div><dt>MAC</dt><dd id="noc-mac">—</dd></div>
    <div><dt>Firmware</dt><dd id="noc-firmware">—</dd></div>
    <div><dt>Hardware</dt><dd id="noc-hardware">—</dd></div>
  </dl></article>
  <article class="jrc-noc-panel"><h2><i class="bi bi-wifi"></i> Wi-Fi</h2>
    <dl><div><dt>SSID</dt><dd id="noc-ssid">—</dd></div></dl>
    <p class="jrc-noc-note">A senha Wi-Fi não é consultada nem exibida nesta visão resumida. Use o gerenciador para operações autorizadas.</p>
    <a class="jrc-noc-action" href="/device-detail.php?id=<?= rawurlencode($id) ?>">Abrir configurações Wi-Fi</a>
  </article>
  <article class="jrc-noc-panel"><h2><i class="bi bi-shield-check"></i> Diagnóstico</h2>
    <p class="jrc-noc-note">Estado de comunicação com base no último Inform. Não é um score de saúde calculado nem uma medição SNMP em tempo real.</p>
    <div class="jrc-noc-diagnostic"><span>Comunicação</span><strong id="noc-communication">—</strong></div>
    <a class="jrc-noc-action" href="/device-detail.php?id=<?= rawurlencode($id) ?>">Diagnósticos e ações da CPE</a>
  </article>
 </section>
 <footer class="jrc-noc-foot">Visualização somente leitura. WAN, Wi-Fi, reboot, senha, firmware, gráficos e tarefas permanecem na tela original.</footer>
</main>
<script src="/assets/js/jrc-device-noc.js?v=1" defer></script>
<?php include __DIR__.'/views/layouts/footer.php'; ?>
