<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?php echo $pageTitle ?? 'JR CONECT ACS'; ?></title>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg?v=20260921">

    <!-- Google Fonts - Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap"
        rel="stylesheet"
    >

    <!-- CSS Libraries -->
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
    >

    <link
        rel="stylesheet"
        href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
    >

    <link
        rel="stylesheet"
        href="/assets/css/style.css?v=<?php echo (int) filemtime(__DIR__ . '/../../assets/css/style.css'); ?>"
    >

    <link rel="stylesheet" href="/assets/css/sidebar.css?v=20261005-toggle">
    <link rel="stylesheet" href="/assets/css/scout-nav.css?v=20261022-menu2">
    <?php if (($currentPage ?? '') === 'dashboard' || ($pageTitle ?? '') === 'Equipamentos'): ?>
    <link rel="stylesheet" href="/assets/css/page-readability.css?v=20261005-1">
    <script src="/assets/js/page-readability.js?v=20261005-1"></script>
    <?php endif; ?>

    <!-- Logger -->
    <script
        src="/assets/js/client-logger.js?v=<?php echo (int) filemtime(__DIR__ . '/../../assets/js/client-logger.js'); ?>"
    ></script>
</head>

<body>

    <!-- Menu lateral -->
    <?php
    $nav = $currentPage ?? '';
    $navUser = $_SESSION['username'] ?? 'operador';
    $navRole = trim((string) ($_SESSION['role'] ?? ''));
    if ($navRole === '') {
        $navRole = 'Operador';
    }
    $navInitials = strtoupper(substr(preg_replace('/[^a-z0-9]/i', '', $navUser), 0, 2) ?: 'JR');
    $firmwareDeviceId = (basename($_SERVER['SCRIPT_NAME'] ?? '') === 'device-detail.php' && !empty($_GET['id']))
        ? (string) $_GET['id']
        : '';
    $firmwareHref = $firmwareDeviceId !== ''
        ? '/device-detail.php?id=' . rawurlencode($firmwareDeviceId) . '&aba=firmware'
        : '/devices.php';
    ?>
    <div class="sidebar" id="sidebar">

        <div class="fb-brand">
            <span class="fb-mark">J</span>
            <span class="fb-brand-text">
                <strong>JRCONECT</strong>
                <small>TELECOM</small>
            </span>
        </div>
<style>/* JRC_BRAND_V1 */#sidebar .fb-brand-text strong{font-size:13px!important;letter-spacing:.01em!important;white-space:nowrap!important}#sidebar .fb-brand-text small{white-space:nowrap!important;letter-spacing:.08em!important}</style>

        <ul class="fb-nav" id="sidebar-menu">
            <li class="fb-nav-label">Gestão</li>
            <li>
                <a href="/dashboard.php" class="<?php echo $nav === 'dashboard' ? 'active' : ''; ?>">
                    <i class="bi bi-grid"></i>
                    <span>Visão geral</span>
                </a>
            </li>
            <li>
                <a href="/devices.php" class="<?php echo $nav === 'devices' ? 'active' : ''; ?>">
                    <i class="bi bi-router"></i>
                    <span>Dispositivos</span>
                </a>
            </li>
            <li>
                <a href="/provisionamento-onu.php" class="<?php echo $nav === 'discovery' ? 'active' : ''; ?>">
                    <i class="bi bi-diagram-3"></i>
                    <span>Provisionamento</span>
                </a>
            </li>
            <li>
                <a
                    href="/voip-central.php"
                    class="<?php echo ($currentPage ?? '') === 'voip-central' ? 'active' : ''; ?>"
                    data-tooltip="Central VoIP"
                >
                    <i class="bi bi-telephone"></i>
                    <span>Central VoIP</span>
                </a>
            </li>

            <li>
                <a href="/configuration.php" class="<?php echo $nav === 'configuration' ? 'active' : ''; ?>">
                    <i class="bi bi-gear"></i>
                    <span>Configuração</span>
                </a>
            </li>

<li class="jrc-sidebar-mcp-shortcut">
    <a
        href="/mcp-server.php"
        data-tooltip="MCP Server"
     class="<?php echo ($currentPage ?? '') === 'mcp-server' ? 'active' : ''; ?>">
        <i class="bi bi-stack"></i>
        <span>MCP Server</span>
    </a>
</li>

            <li>
                <a id="nav-firmware" href="<?php echo htmlspecialchars($firmwareHref, ENT_QUOTES, 'UTF-8'); ?>" class="<?php echo $nav === 'firmware' ? 'active' : ''; ?>">
                    <i class="bi bi-cpu"></i>
                    <span>Firmware</span>
                </a>
            </li>
            <li>
                <a id="nav-diagnostico" href="/devices.php">
                    <i class="bi bi-activity"></i>
                    <span>Diagnóstico</span>
                </a>
<?php if (($currentPage ?? '') === 'speedtest'): ?>
<a href="/speedtest.php" class="active" hidden>Teste de velocidade</a>
<?php endif; ?>
<script>
/* JRC_DIAG_ACOES_V1 — Diagnóstico abre a aba Ações do equipamento. */
(function () {
    var link = document.getElementById('nav-diagnostico');
    if (!link) return;
    var id = '';
    try {
        if (/\/device-detail\.php$/.test(location.pathname)) id = new URLSearchParams(location.search).get('id') || '';
        if (!id) id = localStorage.getItem('jrc_last_device') || '';
    } catch (e) {}
    link.href = id ? ('/device-detail.php?id=' + encodeURIComponent(id) + '&aba=acoes') : '/devices.php';
    link.addEventListener('click', function (ev) {
        if (ev.defaultPrevented || ev.button !== 0 || ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey) return;
        if (!/\/device-detail\.php$/.test(location.pathname)) return;
        var current = new URLSearchParams(location.search).get('id') || '';
        var target = '';
        try { target = new URL(link.href, location.href).searchParams.get('id') || ''; } catch (e) {}
        if (!current || current !== target) return;
        var tab = document.getElementById('diagnostics-tab');
        if (!tab) {
            var pane = document.getElementById('acs-diagnostics-content');
            pane = pane && pane.closest ? pane.closest('.tab-pane') : null;
            if (pane && pane.id) tab = document.querySelector('[data-bs-target="#' + pane.id + '"]');
        }
        if (!tab || !window.bootstrap || !bootstrap.Tab) return;
        ev.preventDefault();
        document.querySelectorAll('.fb-nav a.active').forEach(function (a) { a.classList.remove('active'); });
        link.classList.add('active');
        bootstrap.Tab.getOrCreateInstance(tab).show();
        var url = new URL(location.href);
        url.searchParams.set('aba', 'acoes');
        history.replaceState(null, '', url);
    });
})();
</script>
</li>
            <li>
                <a href="/alerts.php" class="<?php echo $nav === 'alerts' ? 'active' : ''; ?>">
                    <i class="bi bi-bell"></i>
                    <span>Alertas</span>
                </a>
            </li>
            <li>
                <a href="/view-logs.php" class="<?php echo $nav === 'logs' ? 'active' : ''; ?>">
                    <i class="bi bi-file-earmark-text"></i>
                    <span>Relatórios</span>
                </a>
            </li>
            <li>
                <a href="/users.php" class="<?php echo $nav === 'users' ? 'active' : ''; ?>">
                    <i class="bi bi-person-gear"></i>
                    <span>Administração</span>
                </a>
            </li>
        </ul>

        <div class="fb-user">
            <span class="fb-avatar"><?php echo htmlspecialchars($navInitials, ENT_QUOTES, 'UTF-8'); ?></span>
            <span class="fb-user-text">
                <strong><?php echo htmlspecialchars($navUser, ENT_QUOTES, 'UTF-8'); ?></strong>
                <small><?php echo htmlspecialchars($navRole, ENT_QUOTES, 'UTF-8'); ?></small>
            </span>
            <a class="fb-logout" href="/logout.php" title="Sair">
                <i class="bi bi-box-arrow-right"></i>
            </a>
        </div>

        <button
            class="sidebar-toggle"
            id="sidebarToggle"
            type="button"
            hidden
            title="Recolher menu"
            aria-label="Recolher menu"
            aria-controls="sidebar-menu"
            aria-expanded="true"
        >
            <i class="bi bi-chevron-left"></i>
        </button>

    </div>


    <!-- Conteúdo principal -->
    <div
        class="main-content"
        id="mainContent"
    >

        <!-- Barra superior -->
        <div class="topbar">

            <h4>
                <?php echo $pageTitle ?? 'JR CONECT ACS'; ?>
            </h4>

            <?php if (($currentPage ?? '') === 'devices' && basename($_SERVER['SCRIPT_NAME'] ?? '') === 'devices.php'): ?>
            <label class="fb-top-search-wrap">
                <i class="bi bi-search"></i>
                <input
                    id="fleet-search"
                    class="fb-top-search"
                    type="search"
                    placeholder="Buscar serial, cliente, IP..."
                    oninput="var box=document.getElementById('search-input'); if(box) box.value=this.value; if(window.filterDevices) filterDevices();"
                >
            </label>
            <?php endif; ?>


            <div class="user-info">

                <span>
                    <i class="bi bi-person-circle"></i>
                    <?php echo $_SESSION['username'] ?? 'Usuário'; ?>
                </span>

            </div>

        </div>


        <!-- Conteúdo -->
        <div class="content-wrapper">
