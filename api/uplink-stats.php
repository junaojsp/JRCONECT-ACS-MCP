<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/DeviceInventoryCache.php';

use App\DeviceInventoryCache;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=20');

requireLogin();

try {
    $db = getDBConnection();

    if (!DeviceInventoryCache::isReady($db)) {
        require __DIR__ . '/uplink-stats-precache.php';
        exit;
    }

    jsonResponse([
        'success' => true,
        'source' => 'local_inventory',
        'data' => DeviceInventoryCache::opticalStats($db),
        'cache' => DeviceInventoryCache::meta($db),
    ]);
} catch (Throwable $e) {
    error_log('[JRC UPLINK CACHE] ' . $e->getMessage());
    require __DIR__ . '/uplink-stats-precache.php';
}
