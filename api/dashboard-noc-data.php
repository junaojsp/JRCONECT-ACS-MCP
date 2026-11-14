<?php
/**
 * JRC-ACS NOC v2 - agregacao de leitura (somente consulta).
 * Fonte: GenieACS TR-069; nao representa status SNMP das OLTs.
 * Nunca retorna senhas de Wi-Fi, PPPoE ou credenciais.
 */
declare(strict_types=1);

// Install the JSON boundary before loading configuration/autoload/database.
ini_set('display_errors', '0');
ob_start();
$nocBufferLevel = ob_get_level();
$nocResponseSent = false;
$nocMemoryReserve = str_repeat(' ', 64 * 1024);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');
register_shutdown_function(static function () use (&$nocMemoryReserve, &$nocResponseSent): void {
    $nocMemoryReserve = null;
    if (!$nocResponseSent) {
        $error = error_get_last();
        // Do not log exception messages, database errors or upstream bodies.
        error_log('[JRC NOC] Request terminated; PHP error type=' . ($error['type'] ?? 0));
        nocReply(500, ['success' => false, 'message' => 'Falha interna ao consultar o painel NOC. Consulte o log PHP.']);
    }
});

function nocReply(int $status, array $body): void
{
    global $nocBufferLevel, $nocResponseSent;
    $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        $status = 500;
        $json = '{"success":false,"message":"Falha ao gerar resposta do NOC."}';
    }
    while (ob_get_level() >= $nocBufferLevel) {
        ob_end_clean();
    }
    header_remove('Location');
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store, max-age=0');
    http_response_code($status);
    $nocResponseSent = true;
    echo $json;
    exit;
}

function nocReadRx(array $device): ?float
{
    $value = $device['VirtualParameters']['RXPower']['_value']
        ?? $device['InternetGatewayDevice']['WANDevice']['1']['X_CT-COM_EponInterfaceConfig']['RXPower']['_value']
        ?? $device['Device']['Optical']['Interface']['1']['RxPower']['_value']
        ?? null;

    if (!is_numeric($value)) {
        return null;
    }
    $rx = (float) $value;
    if ($rx > 100) {
        $rx = ($rx / 100) - 40;
    }
    // Leituras zeradas e valores fora de faixa nao sao potencia valida.
    if (!is_finite($rx) || $rx >= 0 || $rx < -50) {
        return null;
    }
    return round($rx, 2);
}

try {
    require_once __DIR__ . '/../config/config.php';
    if (!isLoggedIn()) {
        nocReply(401, ['success' => false, 'message' => 'Sessão expirada. Entre novamente para consultar o NOC.']);
    }
    // Release the session lock before querying GenieACS.
    session_write_close();
    set_time_limit(60);
    if (!isGenieACSConfigured()) {
        nocReply(503, ['success' => false, 'message' => 'GenieACS não configurado.']);
    }

    $db = getDBConnection();
    $result = $db->query(
        'SELECT host, port, username, password FROM genieacs_credentials '
        . 'WHERE is_connected = 1 ORDER BY id DESC LIMIT 1'
    );
    $credentials = $result ? $result->fetch_assoc() : null;
    if (!$credentials) {
        nocReply(503, ['success' => false, 'message' => 'Conexão GenieACS não configurada.']);
    }

    require_once __DIR__ . '/../lib/NocGenieACS.php';
    $genie = new \App\NocGenieACS(
        $credentials['host'],
        $credentials['port'],
        $credentials['username'],
        $credentials['password']
    );
    $now = time();
    $deadline = microtime(true) + 45;
    require_once __DIR__ . '/../lib/IXCConfig.php';
    require_once __DIR__ . '/../lib/NocIxcOptical.php';
    $ixcSnapshot = null;
    $ixcStatus = 'not_configured';
    try {
        $ixcConfig = getIxcConfig();
        $ixcStatus = 'unavailable';
        $ixcSnapshot = (new \App\NocIxcOptical($ixcConfig))->snapshot(12);
        $ixcStatus = 'ok';
    } catch (Throwable $e) {
        // Optical failure does not hide the independently valid ACS inventory.
        error_log('[JRC NOC] IXC optical status=' . $ixcStatus . '; ' . get_class($e));
    }
    $skip = 0;
    $pageSize = 100;
    $summary = [
        'total' => 0,
        'online' => 0,
        'offline' => 0,
        'optical_good' => 0,
        'optical_attention' => 0,
        'optical_critical' => 0,
        'optical_unavailable' => 0,
        'optical_from_ixc' => 0,
        'optical_from_tr069' => 0,
    ];
    $issues = [];

    do {
        $remaining = (int) ($deadline - microtime(true));
        if ($remaining < 1) {
            nocReply(504, ['success' => false, 'message' => 'A consulta do NOC excedeu o tempo limite. Tente novamente.']);
        }
        $response = $genie->getDevices($pageSize, $skip, min(15, $remaining));
        if (empty($response['success'])) {
            nocReply(502, ['success' => false, 'message' => 'Não foi possível consultar os dispositivos do GenieACS.']);
        }
        $pageCount = count($response['data']);
        foreach ($response['data'] as $device) {
            if (!is_array($device)) {
                continue;
            }
            $summary['total']++;
            $lastInform = $device['_lastInform'] ?? null;
            $timestamp = is_string($lastInform) ? strtotime($lastInform) : false;
            $isOnline = $timestamp !== false
                && $timestamp !== null
                && $timestamp <= $now
                && ($now - $timestamp) < 300;

            $summary[$isOnline ? 'online' : 'offline']++;
            $rx = null;
            $rxUpdatedAt = null;
            $rxSource = null;
            $identity = is_array($device['_deviceId'] ?? null) ? $device['_deviceId'] : [];
            $serial = is_scalar($identity['_SerialNumber'] ?? null) ? (string) $identity['_SerialNumber'] : '';
            if ($ixcSnapshot !== null) {
                foreach (\App\NocIxcOptical::serialAliases($serial) as $alias) {
                    $optical = $ixcSnapshot['devices'][$alias] ?? [];
                    $rx = \App\NocIxcOptical::normalizeRx($optical['rx'] ?? null);
                    if ($rx !== null) {
                        $rxSource = 'IXC';
                        $rxUpdatedAt = $optical['updated_at'] ?? null;
                        break;
                    }
                }
            }
            if ($rx === null) {
                $rx = nocReadRx($device);
                if ($rx !== null) $rxSource = 'TR-069';
            }
            if ($rxSource !== null) $summary[$rxSource === 'IXC' ? 'optical_from_ixc' : 'optical_from_tr069']++;
            if ($rx === null) {
                $summary['optical_unavailable']++;
            } elseif ($rx <= -28) {
                $summary['optical_critical']++;
            } elseif ($rx <= -25) {
                $summary['optical_attention']++;
            } else {
                $summary['optical_good']++;
            }

            // Ocorrencias atuais: nao representam alarmes persistidos nem falha massiva.
            $priority = null;
            $reason = '';
            if ($isOnline && $rx !== null && $rx <= -28) {
                $priority = 0;
                $reason = 'RX óptico crítico';
            } elseif (!$isOnline) {
                $priority = 1;
                $reason = 'Sem comunicação TR-069';
            } elseif ($rx !== null && $rx <= -25) {
                $priority = 2;
                $reason = 'RX óptico em atenção';
            }

            if ($priority !== null) {
                $issues[] = [
                    'device_id' => (string) ($device['_id'] ?? ''),
                    'serial' => (string) ($identity['_SerialNumber'] ?? 'N/D'),
                    'model' => (string) ($identity['_ProductClass'] ?? 'N/D'),
                    'manufacturer' => (string) ($identity['_Manufacturer'] ?? 'N/D'),
                    'status' => $isOnline ? 'online' : 'offline',
                    'rx' => $rx,
                    'rx_source' => $rxSource,
                    'rx_updated_at' => $rxUpdatedAt,
                    'reason' => $reason,
                    'priority' => $priority,
                    'last_inform' => $timestamp ? gmdate(DATE_ATOM, $timestamp) : null,
                ];
                if (count($issues) > 32) {
                    usort($issues, static fn($a, $b) =>
                        ($a['priority'] <=> $b['priority'])
                        ?: strcmp((string) ($b['last_inform'] ?? ''), (string) ($a['last_inform'] ?? ''))
                    );
                    $issues = array_slice($issues, 0, 12);
                }
            }
        }
        $skip += $pageCount;
        // Release each decoded page before requesting the next one.
        unset($response, $device);
    } while ($pageCount === $pageSize);

    usort($issues, static fn($a, $b) =>
        ($a['priority'] <=> $b['priority'])
        ?: strcmp((string) ($b['last_inform'] ?? ''), (string) ($a['last_inform'] ?? ''))
    );
    $summary['availability_percent'] = $summary['total'] > 0
        ? round(($summary['online'] / $summary['total']) * 100, 1)
        : null;
    $history = ['status' => 'unavailable', 'window_seconds' => 86400, 'points' => []];
    try {
        require_once __DIR__ . '/../lib/NocHistory.php';
        $history = \App\NocHistory::record($credentials, $summary, $now);
    } catch (Throwable $e) {
        error_log('[JRC NOC] History unavailable; ' . get_class($e));
    }

    nocReply(200, [
        'success' => true,
        'source' => 'TR-069 / GenieACS',
        'captured_at' => gmdate(DATE_ATOM, $now),
        'summary' => $summary,
        'issues' => array_slice($issues, 0, 8),
        'history' => $history,
        'optical' => [
            'status' => $ixcStatus,
            'source_policy' => 'IXC principal / TR-069 fallback',
            'inventory_total' => $ixcSnapshot['inventory_total'] ?? null,
            'summary' => $ixcSnapshot['summary'] ?? null,
            'pons' => $ixcSnapshot['pons'] ?? [],
            'critical_threshold_dbm' => -28,
            'critical_threshold_inclusive' => true,
            'captured_at' => $ixcSnapshot !== null ? gmdate(DATE_ATOM, $ixcSnapshot['captured_at']) : null,
            'cache_ttl_seconds' => 120,
        ],
        'capabilities' => [
            'historical_availability' => false,
            'observed_acs_history' => $history['status'] === 'ok',
            'pon_capacity' => false,
            'mass_outage_detection' => false,
            'ixc_optical_primary' => $ixcStatus === 'ok',
        ],
    ]);
} catch (Throwable $e) {
    error_log('[JRC NOC] Falha ao carregar snapshot; ' . get_class($e) . ' em ' . basename($e->getFile()) . ':' . $e->getLine());
    nocReply(500, ['success' => false, 'message' => 'Falha interna ao consultar o painel NOC.']);
}
