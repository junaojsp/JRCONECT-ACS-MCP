        </div>

        <!-- Footer -->
        <div class="footer">
            <img src="/assets/img/jrconect-logo.svg?v=20260921" alt="JR CONECT Telecom" style="width: 92px; height: auto; object-fit: contain;">
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="/assets/js/main.js"></script>
    <?php if (basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'devices.php'): ?>
    <!-- JR Conect ACS 2.0: approved NOC palette on device list only. -->
    <link rel="stylesheet" href="/assets/css/jrc-devices-noc-20.css?v=1">
    <?php endif; ?>
</body>
</html>
