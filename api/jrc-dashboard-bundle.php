<?php
declare(strict_types=1);

/**
 * One read-only response for the three dashboard sections, from exactly the
 * same inventory generation. Intended for a future opt-in dashboard frontend.
 * Existing APIs and custom dashboard are deliberately left untouched.
 */
require_once __DIR__ . '/../config/config.php';
requireLogin();
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
if (getConfig('jrc_dashboard_cache_enabled') !== '1') {
    jsonResponse(['success' => false, 'message' => 'Snapshot da Visao geral desativado'], 503);
}
try {
    require_once __DIR__ . '/../lib/JrcDashboardSnapshot.php';
    $snapshot = JrcDashboardSnapshot::load(getDBConnection());
    if ($snapshot === null) {
        jsonResponse(['success' => false, 'message' => 'Snapshot indisponivel'], 503);
    }
    $devices = $snapshot['devices'];
    $state = $snapshot['state'];
    $age = max(0, (int)$state['age_seconds']);
    jsonResponse([
        'success' => true,
        'source' => 'jrc_background_inventory',
        'snapshot' => $state['snapshot_id'],
        'cached_at' => $state['completed_at'],
        'cache_age_seconds' => $age,
        'cache_stale' => $age > 900,
        'stats' => JrcDashboardSnapshot::calculate($devices, 'stats'),
        'uplink' => JrcDashboardSnapshot::calculate($devices, 'uplink'),
        'recent' => JrcDashboardSnapshot::calculate($devices, 'recent'),
    ]);
} catch (Throwable $error) {
    error_log('JRC dashboard bundle: ' . get_class($error));
    jsonResponse(['success' => false, 'message' => 'Resumo indisponivel'], 503);
}
