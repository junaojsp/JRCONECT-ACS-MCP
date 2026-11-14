        </div>

        <!-- Footer -->
        <div class="footer">
            <img src="/assets/img/jrconect-logo.svg?v=20260921" alt="JR CONECT Telecom" style="width: 92px; height: auto; object-fit: contain;">
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="/assets/js/main.js?v=20261005-sidebar-toggle"></script>
<script src="/assets/js/jrc-ipam.js?v=e84f7dea0d" defer></script>
<!-- JRC_IPAM_NAV_V1 -->

<script src="/assets/js/jrc-sinal.js?v=20261030-scoped"></script>
<!-- JRC_SINAL_PREFETCH_V1 -->

    <?php if (($currentPage ?? '') === 'dashboard'): ?>
    <!-- Scoped to the current overview; other pages keep their existing assets. -->
    <link rel="stylesheet" href="/assets/css/dashboard-chart-fix.css?v=chartfix-1">
    <script src="/assets/js/dashboard-chart-fix.js?v=chartfix-1"></script>
    <script src="/assets/js/dashboard-source-policy.js?v=20260921-3"></script>
    <?php endif; ?>
    <?php if (basename($_SERVER['SCRIPT_NAME'] ?? '') === 'device-detail.php'): ?>
    <!-- Read-only unified-network identification; no device tasks on page load. -->
    <link rel="stylesheet" href="/assets/css/wifi-network-groups.css?v=groups-1">
    <script src="/assets/js/wifi-network-groups.js?v=20261022-onu4"></script>
    <link rel="stylesheet" href="/assets/css/scout-device.css?v=20261022-onu9">
    <?php endif; ?>
    <link rel="stylesheet" href="/assets/css/command-center.css?v=20261114-cc1">
</body>
</html>
