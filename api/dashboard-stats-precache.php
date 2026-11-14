<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/GenieACS_DashboardLean.php';

use App\GenieACS_DashboardLean;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=10');

requireLogin();

if (!isGenieACSConfigured()) {
    jsonResponse(['success' => false, 'message' => 'GenieACS não configurado.'], 503);
}

$conn = getDBConnection();
$result = $conn->query(
    "SELECT host, port, username, password
     FROM genieacs_credentials
     WHERE is_connected = 1
     ORDER BY id DESC
     LIMIT 1"
);
$credentials = $result->fetch_assoc();

if (!$credentials) {
    jsonResponse(['success' => false, 'message' => 'GenieACS não conectado.'], 503);
}

try {
    $genieacs = new GenieACS_DashboardLean(
        (string)$credentials['host'],
        (int)$credentials['port'],
        (string)($credentials['username'] ?? ''),
        (string)($credentials['password'] ?? '')
    );

    // HEAD no NBI: conta os documentos sem transferir a árvore TR-069.
    $total = $genieacs->count();

    $onlineThreshold = gmdate('Y-m-d\TH:i:s.000\Z', time() - 300);
    $online = $genieacs->count([
        '_lastInform' => ['$gte' => $onlineThreshold],
    ]);

    jsonResponse([
        'success' => true,
        'stats' => [
            'total' => $total,
            'online' => $online,
            'offline' => max(0, $total - $online),
        ],
    ]);
} catch (Throwable $e) {
    error_log('[JRC DASHBOARD STATS] ' . $e->getMessage());
    jsonResponse([
        'success' => false,
        'message' => 'Falha ao carregar estatísticas do ACS.',
    ], 502);
}
