<?php
require_once __DIR__ . '/config/config.php';
requireLogin();

$pageTitle = 'Alertas';
$currentPage = 'alerts';
$genieacsConfigured = isGenieACSConfigured();

include __DIR__ . '/views/layouts/header.php';
?>
<div id="jrc-alertas-root" data-jrc="JRC_ALERTAS_V1"></div>
<?php include __DIR__ . '/views/layouts/footer.php'; ?>
<link rel="stylesheet" href="/assets/css/jrc-alertas.css?v=f535d249c8">
<script src="/assets/js/jrc-alertas.js?v=4781a39bd9" defer></script>

