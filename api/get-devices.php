<?php
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');
requireLogin();

// Set time limit for this script to handle large datasets
set_time_limit(300); // Increased to 5 minutes for large datasets
ini_set('max_execution_time', 300);

if (!isGenieACSConfigured()) {
    jsonResponse(['success' => false, 'message' => 'GenieACS belum dikonfigurasi']);
}

$conn = getDBConnection();
$result = $conn->query("SELECT * FROM genieacs_credentials WHERE is_connected = 1 ORDER BY id DESC LIMIT 1");
$credentials = $result->fetch_assoc();

if (!$credentials) {
    jsonResponse(['success' => false, 'message' => 'GenieACS tidak terhubung']);
}

use App\GenieACS;
use App\GenieACS_Fast;

$genieacs = new GenieACS(
    $credentials['host'],
    $credentials['port'],
    $credentials['username'],
    $credentials['password']
);

// O cache e opt-in: sem snapshot valido, comportamento legado permanece.
// O coletor e independente do browser e nunca dispara tarefas TR-069.
if (getConfig('jrc_inventory_cache_enabled') === '1' && ($_GET['parser'] ?? 'fast') !== 'full') {
    require_once __DIR__ . '/../lib/JrcDeviceInventoryCache.php';
    try {
        $cacheLimit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
        $cacheSkip = isset($_GET['skip']) ? (int)$_GET['skip'] : 0;
        $cached = JrcDeviceInventoryCache::page($conn, $cacheLimit, $cacheSkip);
        if ($cached !== null) {
            header('Cache-Control: private, no-store');
            jsonResponse($cached);
        }
    } catch (Throwable $cacheError) {
        error_log('JRC inventory cache fallback: ' . $cacheError->getMessage());
    }
}

// Get pagination parameters (optional)
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100; // Default limit to 100 for performance
$skip = isset($_GET['skip']) ? (int)$_GET['skip'] : 0;

// Check if client wants all devices (chunked parameter)
$chunked = isset($_GET['chunked']) && $_GET['chunked'] === 'true';

// Parser selection: 'fast' (default for performance) or 'full' (for complete data)
$parser = isset($_GET['parser']) ? $_GET['parser'] : 'fast';
$useFastParser = ($parser === 'fast');

try {
    $devicesResult = $genieacs->getDevices([], $limit, $skip);

    if ($devicesResult['success']) {
        $devices = [];

        // Use selected parser
        foreach ($devicesResult['data'] as $device) {
            if ($useFastParser) {
                // Fast parser - optimized for performance (10x faster)
                $parsed = GenieACS_Fast::parseDeviceDataFast($device);
            } else {
                // Full parser - complete data extraction
                $parsed = $genieacs->parseDeviceData($device);
            }
            $devices[] = $parsed;
        }

        $response = [
            'success' => true,
            'devices' => $devices,
            'count' => count($devices),
            'total' => count($devices),
            'hasMore' => count($devices) === $limit,
            'pagination' => [
                'limit' => $limit,
                'skip' => $skip,
                'returned' => count($devices),
                'nextSkip' => $skip + $limit
            ]
        ];

        jsonResponse($response);
    } else {
        jsonResponse([
            'success' => false,
            'message' => 'Gagal mengambil data devices',
            'error' => $devicesResult['error'] ?? 'Unknown error'
        ]);
    }
} catch (Exception $e) {
    jsonResponse([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ], 500);
}
