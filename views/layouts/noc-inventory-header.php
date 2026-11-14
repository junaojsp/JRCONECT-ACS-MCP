<?php
$operator = htmlspecialchars((string) ($_SESSION['username'] ?? 'Administrador'), ENT_QUOTES, 'UTF-8');
$title = $inventoryDetail ? 'Detalhes do equipamento' : 'Equipamentos';
?>
<!doctype html>
<html lang="pt-BR"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo $title; ?> | JRC-ACS</title>
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link rel="stylesheet" href="/assets/css/dashboard-noc.css?v=5">
<link rel="stylesheet" href="/assets/css/noc-inventory.css?v=2">
<!-- JRC NOC SHARED THEME -->
<link rel="stylesheet" href="/assets/css/jrc-theme.css?v=1">
<script src="/assets/js/jrc-theme.js?v=1" defer></script>
<!-- /JRC NOC SHARED THEME -->
</head><body class="noc-app<?php echo $inventoryDetail ? ' inventory-device' : ''; ?>">
<a class="noc-skip" href="#noc-main">Ir para o conteúdo</a>
<aside class="noc-sidebar" aria-label="Menu do ACS">
  <button class="noc-button inventory-menu-close" id="inventory-menu-close" type="button">Fechar menu ×</button>
  <a class="noc-brand" href="/dashboard-noc.php"><img src="/assets/img/jrconect-logo.svg" alt="JR Conect Telecom"></a>
  <nav aria-label="Navegação principal">
    <a href="/dashboard-noc.php"><i class="bi bi-house-door"></i> Visão geral</a>
    <a class="active" href="/devices-noc.php" aria-current="page"><i class="bi bi-router"></i> Equipamentos</a>
    <a href="/discovery.php"><i class="bi bi-search"></i> Discovery TR-069</a>
    <a href="/dashboard-noc.php#noc-optical"><i class="bi bi-activity"></i> Rede óptica</a>
    <a href="/dashboard-noc.php#noc-pons"><i class="bi bi-hdd-rack"></i> OLTs e PONs <small>IXC</small></a>
    <a href="/map.php"><i class="bi bi-map"></i> Mapa da rede</a>
    <a href="/dashboard-noc.php#noc-occurrences"><i class="bi bi-bell"></i> Ocorrências</a>
    <a href="/configuration.php"><i class="bi bi-gear"></i> Configurações</a>
  </nav>
  <div class="noc-sidebar-bottom"><i class="bi bi-cloud-check"></i><div><b>JRC-ACS 2.0</b><span>Central de operações</span></div><p>TR-069 · GenieACS<br>Integração IXC · FTTH</p><a href="/devices.php">Abrir lista atual ↗</a></div>
</aside>
<main class="noc-main" id="noc-main">
<section class="noc inventory" data-device-id="<?php echo htmlspecialchars($inventoryId ?? '', ENT_QUOTES, 'UTF-8'); ?>" id="inventory-app">
  <div class="noc-heading">
    <button class="noc-mobile-menu" id="inventory-menu" type="button" aria-label="Abrir menu" aria-expanded="false"><i class="bi bi-list"></i></button>
    <div><p class="noc-eyebrow">JR CONECT TELECOM · CENTRAL DE EQUIPAMENTOS</p><h1><?php echo $title; ?></h1><p>Inventário ACS · Dados reportados pelo equipamento</p></div>
    <div class="noc-actions"><span class="noc-captured" id="inventory-captured">Aguardando leitura</span><button type="button" class="noc-button" id="inventory-refresh"><i class="bi bi-arrow-clockwise"></i> Atualizar</button><span class="noc-user"><i class="bi bi-person-circle"></i><span><?php echo $operator; ?><small>Operação da rede</small></span></span></div>
  </div>
  <div class="noc-alert" id="inventory-error" role="alert" hidden></div>
  <div class="inventory-optical-warning" id="inventory-optical-warning" role="status" hidden></div>
