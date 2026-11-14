<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/DeviceInventoryCache.php';

use App\DeviceInventoryCache;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

requireLogin();

try {
    $db = getDBConnection();

    if (!DeviceInventoryCache::isReady($db)) {
        require __DIR__ . '/get-devices-page-precache.php';
        exit;
    }

    $limit = (int)($_GET['limit'] ?? 20);
    if (!in_array($limit, [20, 50, 100], true)) $limit = 20;

    $page = max(1, (int)($_GET['page'] ?? 1));
    $term = trim((string)($_GET['q'] ?? ''));
    if (mb_strlen($term) > 128) {
        jsonResponse(['success' => false, 'message' => 'Busca muito longa.'], 400);
    }

    $serials = [];
    $serialsParam = trim((string)($_GET['serials'] ?? ''));
    if ($serialsParam !== '') {
        $serials = array_slice(
            array_filter(array_map('trim', explode(',', $serialsParam))),
            0,
            50
        );
    }

    $pageData = DeviceInventoryCache::paged(
        $db,
        $page,
        $limit,
        $term,
        $serials
    );

    $stats = DeviceInventoryCache::stats($db);
    $meta = DeviceInventoryCache::meta($db);

    jsonResponse([
        'success' => true,
        'source' => 'local_inventory',
        'devices' => $pageData['devices'],
        'count' => count($pageData['devices']),
        'total' => $pageData['total'],
        'page' => $pageData['page'],
        'limit' => $pageData['limit'],
        'pages' => $pageData['pages'],
        'hasMore' => $pageData['hasMore'],
        'stats' => $stats,
        'cache' => [
            'last_success_at' => $meta['last_success_at'] ?? null,
            'last_full_sync_at' => $meta['last_full_sync_at'] ?? null,
            'last_duration_ms' => isset($meta['last_duration_ms']) ? (int)$meta['last_duration_ms'] : null,
            'device_count' => isset($meta['device_count']) ? (int)$meta['device_count'] : null,
            'mode' => $meta['last_mode'] ?? null,
        ],
        'search' => [
            'term' => $term,
            'ixc_serials' => count($serials),
        ],
    ]);
} catch (Throwable $e) {
    error_log('[JRC DEVICE PAGE CACHE] ' . $e->getMessage());

    // Safety fallback: keep the screen operational even if the cache is unavailable.
    require __DIR__ . '/get-devices-page-precache.php';
}
