<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
requireLogin();
require_once __DIR__ . '/../lib/JrcDeviceInventoryCache.php';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$state = JrcDeviceInventoryCache::state(getDBConnection());
if ($state === null) {
    jsonResponse(['success' => true, 'ready' => false, 'enabled' => getConfig('jrc_inventory_cache_enabled') === '1']);
}
jsonResponse([
    'success' => true, 'ready' => true,
    'enabled' => getConfig('jrc_inventory_cache_enabled') === '1',
    'total' => (int)$state['total'],
    'ixc_matched' => (int)$state['ixc_matched'],
    'last_collection' => $state['completed_at'],
    'stale' => $state['stale'],
    'note' => $state['collection_note']
]);
