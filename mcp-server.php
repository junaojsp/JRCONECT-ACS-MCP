<?php
require_once __DIR__ . '/config/config.php';
requireLogin();

$pageTitle = 'MCP Server';
$currentPage = 'mcp-server';

if (empty($_SESSION['mcp_instance_csrf']) || !is_string($_SESSION['mcp_instance_csrf'])) {
    $_SESSION['mcp_instance_csrf'] = bin2hex(random_bytes(32));
}

include __DIR__ . '/views/layouts/header.php';
?>

<link rel="stylesheet" href="/assets/css/mcp-connections.css?v=20261101-11">
<script>
document.body.classList.add("jrc-mcp-standalone");
window.JRC_MCP_INSTANCE_CSRF = <?php echo json_encode($_SESSION['mcp_instance_csrf'], JSON_UNESCAPED_SLASHES); ?>;
</script>

<div class="jrc-mcp-page">
    <div class="jrc-mcp-page__header">
        <div>
            <div class="jrc-mcp-page__eyebrow">
                <i class="bi bi-stack"></i>
                JR Conect MCPv2
            </div>
            <h1>MCP Server</h1>
            <p>
                Serviços disponíveis, ferramentas registradas e clientes autenticados
                no servidor MCP da JR Conect.
            </p>
        </div>

    </div>

    <div id="mcp-server-hub"></div>
</div>

<script src="/assets/js/mcp-connections-panel.js?v=29857118c0" defer></script>

<?php
include __DIR__ . '/views/layouts/footer.php';
?>
