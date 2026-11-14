<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/GenieACS_DashboardLean.php';

use App\GenieACS_DashboardLean;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=20');

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

$readValue = static function (array $device, array $path) {
    $node = $device;
    foreach ($path as $part) {
        if (!is_array($node) || !array_key_exists($part, $node)) {
            return null;
        }
        $node = $node[$part];
    }

    if (is_array($node) && array_key_exists('_value', $node)) {
        return $node['_value'];
    }

    return is_scalar($node) ? $node : null;
};

try {
    $genieacs = new GenieACS_DashboardLean(
        (string)$credentials['host'],
        (int)$credentials['port'],
        (string)($credentials['username'] ?? ''),
        (string)($credentials['password'] ?? '')
    );

    // Traz somente os campos de potência óptica necessários para o gráfico.
    // Antes o endpoint transferia a árvore TR-069 inteira de todas as ONUs.
    $devices = $genieacs->devices(
        0,
        0,
        [],
        [],
        [
            '_id',
            'VirtualParameters.RXPower',
            'InternetGatewayDevice.WANDevice.1.X_CT-COM_EponInterfaceConfig.RXPower',
            'Device.Optical.Interface.1.RxPower',
        ]
    );

    $excellent = 0;
    $good = 0;
    $fair = 0;
    $poor = 0;
    $noSignal = 0;

    foreach ($devices as $device) {
        $rxPower =
            $readValue($device, ['VirtualParameters', 'RXPower']) ??
            $readValue($device, ['InternetGatewayDevice', 'WANDevice', '1', 'X_CT-COM_EponInterfaceConfig', 'RXPower']) ??
            $readValue($device, ['Device', 'Optical', 'Interface', '1', 'RxPower']);

        if ($rxPower === null || $rxPower === '' || !is_numeric($rxPower)) {
            $noSignal++;
            continue;
        }

        $rx = (float)$rxPower;

        // Mantém a mesma normalização usada pelo parser atual.
        if ($rx > 100) {
            $rx = ($rx / 100) - 40;
        }

        if ($rx > -20) {
            $excellent++;
        } elseif ($rx >= -25) {
            $good++;
        } elseif ($rx >= -28) {
            $fair++;
        } else {
            $poor++;
        }
    }

    jsonResponse([
        'success' => true,
        'data' => [
            'excellent' => $excellent,
            'good' => $good,
            'fair' => $fair,
            'poor' => $poor,
            'no_signal' => $noSignal,
            'total' => count($devices),
        ],
    ]);
} catch (Throwable $e) {
    error_log('[JRC UPLINK STATS] ' . $e->getMessage());
    jsonResponse([
        'success' => false,
        'message' => 'Falha ao carregar estatísticas ópticas.',
    ], 502);
}
