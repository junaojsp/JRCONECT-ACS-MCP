<?php
require_once __DIR__ . '/../config/config.php';

// Increase timeout for large dataset
set_time_limit(20);

requireLogin();
 
// JRC OVERVIEW SNAPSHOT FAST PATH (opt-in, read-only).
// Keep the deployed dashboard layout and existing JSON response contract unchanged.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
if (getConfig('jrc_dashboard_cache_enabled') === '1') {
    require_once __DIR__ . '/../lib/JrcDashboardSnapshot.php';
    try {
        $cachedOverview = JrcDashboardSnapshot::response(getDBConnection(), 'recent');
        if ($cachedOverview !== null) {
            header('Cache-Control: private, no-store');
            jsonResponse($cachedOverview);
        }
    } catch (Throwable $overviewError) {
        error_log('JRC overview cache recent: ' . $overviewError->getMessage());
        // Cache not available: old GenieACS endpoint is unchanged.
    }
}


use App\GenieACS;
use App\GenieACS_Fast;

header('Content-Type: application/json');

try {
    // Get GenieACS credentials from database
    $db = getDBConnection();
    $stmt = $db->prepare("SELECT host, port, username, password FROM genieacs_credentials LIMIT 1");
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        jsonResponse([
            'success' => false,
            'message' => 'GenieACS not configured'
        ]);
        exit;
    }

    $config = $result->fetch_assoc();
    $stmt->close();

    // Initialize GenieACS client
    $genieacs = new GenieACS(
        $config['host'],
        $config['port'],
        $config['username'],
        $config['password']
    );

    // Get all devices
    $result = $genieacs->getDevices();

    if (!$result['success']) {
        jsonResponse([
            'success' => false,
            'message' => 'Failed to fetch devices from GenieACS'
        ]);
        exit;
    }

    $devices = $result['data'];
    $recentDevices = [];

    // Parse and sort by last inform time (use fast parser for performance)
    foreach ($devices as $device) {
        $parsed = GenieACS_Fast::parseDeviceDataFast($device);

        // Add last inform timestamp for sorting
        if (isset($device['_lastInform'])) {
            $parsed['last_inform_timestamp'] = strtotime($device['_lastInform']);
        } else {
            $parsed['last_inform_timestamp'] = 0;
        }

        $recentDevices[] = $parsed;
    }

    // Sort by last inform (most recent first)
    usort($recentDevices, function($a, $b) {
        return $b['last_inform_timestamp'] - $a['last_inform_timestamp'];
    });

    // Take only top 10 most recent
    $recentDevices = array_slice($recentDevices, 0, 10);

    jsonResponse([
        'success' => true,
        'devices' => $recentDevices
    ]);

} catch (Exception $e) {
    jsonResponse([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
