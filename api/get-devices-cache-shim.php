<?php
declare(strict_types=1);

/*
 * Include shim for api/get-devices.php.
 * Returns false when cache is not ready so the legacy GenieACS path can continue.
 * When ready, jsonResponse() exits after serving the request from local MySQL.
 */

require_once __DIR__ . '/../lib/DeviceInventoryCache.php';

use App\DeviceInventoryCache;

try {
    $cacheDb = getDBConnection();

    if (!DeviceInventoryCache::isReady($cacheDb)) {
        return false;
    }

    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
    $skip = isset($_GET['skip']) ? (int)$_GET['skip'] : 0;

    $result = DeviceInventoryCache::legacy($cacheDb, $limit, $skip);
    $devices = $result['devices'];

    jsonResponse([
        'success' => true,
        'source' => 'local_inventory',
        'devices' => $devices,
        'count' => count($devices),
        'total' => $result['total'],
        'hasMore' => $result['hasMore'],
        'pagination' => [
            'limit' => max(1, min(500, $limit)),
            'skip' => max(0, $skip),
            'returned' => count($devices),
            'nextSkip' => max(0, $skip) + count($devices),
        ],
    ]);
} catch (Throwable $e) {
    error_log('[JRC DEVICE CACHE SHIM] ' . $e->getMessage());
    return false;
}
