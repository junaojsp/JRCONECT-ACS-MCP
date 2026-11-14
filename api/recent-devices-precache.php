<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/GenieACS_DashboardLean.php';

use App\GenieACS_DashboardLean;
use App\GenieACS_Fast;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=10');

requireLogin();

try {
    $db = getDBConnection();
    $stmt = $db->prepare(
        "SELECT host, port, username, password
         FROM genieacs_credentials
         WHERE is_connected = 1
         ORDER BY id DESC
         LIMIT 1"
    );
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        jsonResponse([
            'success' => false,
            'message' => 'GenieACS não configurado.',
        ], 503);
    }

    $config = $result->fetch_assoc();
    $stmt->close();

    $genieacs = new GenieACS_DashboardLean(
        (string)$config['host'],
        (int)$config['port'],
        (string)($config['username'] ?? ''),
        (string)($config['password'] ?? '')
    );

    // O GenieACS ordena no MongoDB e devolve somente os 10 mais recentes.
    // Antes este endpoint transferia e processava o parque inteiro.
    $rawDevices = $genieacs->devices(
        10,
        0,
        [],
        ['_lastInform' => -1, '_id' => 1]
    );

    $recentDevices = [];

    foreach ($rawDevices as $device) {
        $parsed = GenieACS_Fast::parseDeviceDataFast($device);
        $parsed['last_inform_timestamp'] = isset($device['_lastInform'])
            ? (strtotime((string)$device['_lastInform']) ?: 0)
            : 0;
        $recentDevices[] = $parsed;
    }

    jsonResponse([
        'success' => true,
        'devices' => $recentDevices,
    ]);
} catch (Throwable $e) {
    error_log('[JRC RECENT DEVICES] ' . $e->getMessage());
    jsonResponse([
        'success' => false,
        'message' => 'Falha ao carregar equipamentos recentes.',
    ], 502);
}
