<?php
/**
 * JRC-ACS NOC 2.0 - previa segura, separada da dashboard principal.
 * Acesso: /dashboard-noc.php (autenticacao existente).
 */
require_once __DIR__ . '/config/config.php';
requireLogin();
$nocUsername = htmlspecialchars((string) ($_SESSION['username'] ?? 'Administrador'), ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Visão Geral da Rede | JRC-ACS</title>
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/dashboard-noc.css?v=5">
<!-- JRC NOC SHARED THEME -->
<link rel="stylesheet" href="/assets/css/jrc-theme.css?v=1">
<script src="/assets/js/jrc-theme.js?v=1" defer></script>
<!-- /JRC NOC SHARED THEME -->
</head>
<body class="noc-app">
<a class="noc-skip" href="#noc-main">Ir para os indicadores</a>
<aside class="noc-sidebar" aria-label="Menu do ACS">
  <a class="noc-brand" href="/dashboard-noc.php"><img src="/assets/img/jrconect-logo.svg" alt="JR Conect Telecom"></a>
  <nav aria-label="Navegação principal">
    <a class="active" href="/dashboard-noc.php" aria-current="page"><i class="bi bi-house-door-fill"></i> Visão geral</a>
    <a href="/devices-noc.php"><i class="bi bi-router"></i> Equipamentos</a>
    <a href="/discovery.php"><i class="bi bi-search"></i> Discovery TR-069</a>
    <a href="#noc-optical"><i class="bi bi-activity"></i> Rede óptica</a>
    <a href="#noc-pons"><i class="bi bi-hdd-rack"></i> OLTs e PONs <small>IXC</small></a>
    <a href="/map.php"><i class="bi bi-map"></i> Mapa da rede</a>
    <a href="#noc-occurrences"><i class="bi bi-bell"></i> Ocorrências</a>
    <a href="#noc-priority"><i class="bi bi-list-ul"></i> Equipamentos em atenção</a>
    <a href="/configuration.php"><i class="bi bi-gear"></i> Configurações</a>
  </nav>
  <div class="noc-sidebar-bottom"><i class="bi bi-cloud-check"></i><div><b>JRC-ACS 2.0</b><span>Central de operações</span></div><p>TR-069 · GenieACS<br>Integração IXC · FTTH</p><a href="/dashboard.php">Abrir dashboard atual <i class="bi bi-arrow-up-right"></i></a></div>
</aside>
<main class="noc-main" id="noc-main">
<section class="noc" aria-label="Centro de operações da rede">
  <div class="noc-heading">
    <button class="noc-mobile-menu" id="noc-menu-toggle" type="button" aria-label="Abrir menu" aria-expanded="false"><i class="bi bi-list"></i></button>
    <div>
      <p class="noc-eyebrow"><i class="bi bi-diagram-3"></i> JR CONECT TELECOM · NETWORK OPERATIONS CENTER</p>
      <h1>Visão Geral da Rede <span class="noc-pill" id="noc-health">Consultando ACS…</span></h1>
      <p>Monitoramento ACS · TR-069 · FTTH</p>
    </div>
    <div class="noc-actions">
      <a class="noc-button noc-period" href="#noc-availability-panel"><i class="bi bi-calendar3"></i> Últimas 24 horas</a>
      <span id="noc-captured" class="noc-captured">Aguardando leitura</span>
      <button type="button" class="noc-button" id="noc-refresh"><i class="bi bi-arrow-clockwise"></i> Atualizar</button>
      <span class="noc-user"><i class="bi bi-person-circle"></i><span><?php echo $nocUsername; ?><small>Operação da rede</small></span></span>
    </div>
  </div>

  <div class="noc-alert" id="noc-error" role="alert" hidden></div>

  <div class="noc-kpis" aria-label="Indicadores operacionais">
    <div class="noc-kpi kpi-blue"><span class="noc-kpi-icon"><i class="bi bi-router"></i></span><span class="noc-kpi-label">Equipamentos ACS</span><strong id="noc-total">—</strong><small>Inventário GenieACS / TR-069</small></div>
    <div class="noc-kpi kpi-green"><span class="noc-kpi-icon"><i class="bi bi-wifi"></i></span><span class="noc-kpi-label">Online</span><strong id="noc-online">—</strong><small>Inform recebido há menos de 5 min</small></div>
    <div class="noc-kpi kpi-red"><span class="noc-kpi-icon"><i class="bi bi-wifi-off"></i></span><span class="noc-kpi-label">Offline TR-069</span><strong id="noc-offline">—</strong><small>Sem Inform recente</small></div>
    <div class="noc-kpi kpi-amber"><span class="noc-kpi-icon"><i class="bi bi-exclamation-triangle"></i></span><span class="noc-kpi-label">Sinal crítico IXC</span><strong id="noc-critical">—</strong><small>Inventário óptico · RX ≤ -28 dBm</small></div>
    <div class="noc-kpi kpi-teal"><span class="noc-kpi-icon"><i class="bi bi-bar-chart"></i></span><span class="noc-kpi-label">Comunicação ACS</span><strong id="noc-availability">—</strong><small>Online / total (não é SLA)</small></div>
  </div>

  <div class="noc-grid">
    <article class="noc-panel noc-availability" id="noc-availability-panel">
      <header><div><h2><i class="bi bi-activity"></i> Comunicação da rede <em>24 horas</em></h2><span>Observações reais do GenieACS · Inform há menos de 5 min</span></div><a href="/devices-noc.php">Ver equipamentos <i class="bi bi-arrow-up-right"></i></a></header>
      <div class="noc-availability-content">
        <div class="noc-big-value" id="noc-availability-large">—</div>
        <p>Online na última consulta · não representa SLA</p>
        <div class="noc-chart" id="noc-history-chart" aria-label="Histórico observado da comunicação ACS"></div>
        <div class="noc-progress" role="progressbar" aria-label="Disponibilidade atual" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="noc-progress"><div id="noc-progress-bar"></div></div>
        <div class="noc-legend"><span><i class="dot green"></i> Online <b id="noc-legend-online">—</b></span><span><i class="dot red"></i> Offline <b id="noc-legend-offline">—</b></span></div>
      </div>
      <footer id="noc-history-notice">O histórico começa com as consultas desta prévia. Lacunas não indicam queda.</footer>
    </article>
    <article class="noc-panel noc-optics" id="noc-optical">
      <header><div><h2><i class="bi bi-broadcast"></i> Saúde do sinal óptico</h2><span id="noc-optical-source">Inventário óptico completo · IXC</span></div><a href="/discovery.php">Discovery <i class="bi bi-arrow-up-right"></i></a></header>
      <div class="noc-optical-content">
        <div class="noc-donut" id="noc-donut"><div><strong id="noc-donut-total">—</strong><span id="noc-donut-label">Registros IXC</span></div></div>
        <div class="noc-optical-legend">
          <div><span><i class="dot green"></i> Normal (&gt; -25 dBm)</span><b id="noc-optical-good">—</b></div>
          <div><span><i class="dot amber"></i> Atenção (-28 &lt; RX ≤ -25 dBm)</span><b id="noc-optical-attention">—</b></div>
          <div><span><i class="dot red"></i> Crítico (≤ -28 dBm)</span><b id="noc-optical-critical">—</b></div>
          <div><span><i class="dot gray"></i> Sem leitura</span><b id="noc-optical-unknown">—</b></div>
        </div>
      </div>
      <footer><span id="noc-optical-notice">Aguardando leituras ópticas.</span><br>Limiares iniciais de triagem; ajustar por modelo, OLT e parâmetros da rede. Valores do cadastro IXC não provocam uma coleta na OLT.</footer>
    </article>
    <article class="noc-panel" id="noc-occurrences">
      <header><div><h2><i class="bi bi-bell-fill"></i> Alertas e ocorrências</h2><span>Triagem atual dos equipamentos ACS</span></div><a href="/devices-noc.php">Ver todos <i class="bi bi-arrow-up-right"></i></a></header>
      <div class="noc-list" id="noc-issues"><p class="noc-empty">Consultando dispositivos…</p></div>
    </article>
    <article class="noc-panel" id="noc-pons">
      <header><div><h2><i class="bi bi-hdd-rack"></i> PONs que exigem atenção</h2><span>Cadastro IXC · ordenação por quantidade de RX crítico</span></div><a href="/map.php">Mapa <i class="bi bi-arrow-right"></i></a></header>
      <div class="noc-table-wrap"><table class="noc-table noc-pon-table"><thead><tr><th>PON / OLT</th><th>RX crítico</th><th>Cadastradas</th><th>Sem RX</th></tr></thead><tbody id="noc-pon-body"><tr><td colspan="4" class="noc-empty">Aguardando consulta IXC…</td></tr></tbody></table></div>
      <footer>Estado, tráfego e capacidade das portas dependem da telemetria OLT / Zabbix. Os registros acima vêm do cadastro IXC.</footer>
    </article>
  </div>

  <article class="noc-panel noc-full" id="noc-priority">
    <header><div><h2><i class="bi bi-list-ul"></i> Equipamentos que exigem atenção</h2><span>Até 8 dispositivos ACS prioritários · leitura óptica IXC / TR-069</span></div><a href="/devices-noc.php">Lista completa <i class="bi bi-arrow-up-right"></i></a></header>
    <div class="noc-table-wrap"><table class="noc-table"><thead><tr><th>Dispositivo</th><th>Modelo</th><th>RX óptico</th><th>Situação</th><th>Último Inform</th><th>Ação</th></tr></thead><tbody id="noc-table-body"><tr><td colspan="6" class="noc-empty">Aguardando dados…</td></tr></tbody></table></div>
  </article>
  <p class="noc-disclaimer">JRC-ACS NOC 2.0 · Prévia em desenvolvimento · Leitura somente · Inventário e comunicação: GenieACS TR-069. Saúde óptica: inventário IXC completo. Triagem por equipamento ACS: IXC / TR-069. O status online mede a atualização no ACS, não confirma acesso à Internet do assinante.</p>
</section>
</main>
<script src="/assets/js/dashboard-noc.js?v=5" defer></script>
</body>
</html>
