<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/GenieACS_Fast.php';
require_once __DIR__ . '/../lib/DeviceInventoryCache.php';

use App\DeviceInventoryCache;
use App\GenieACS_Fast;

$options = getopt('', ['full', 'quiet']);
$forceFull = array_key_exists('full', $options);
$quiet = array_key_exists('quiet', $options);

function logLine(string $message, bool $quiet = false): void
{
    if (!$quiet) {
        echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    }
}

function nbiRequest(
    array $credentials,
    int $limit,
    int $skip,
    array $query,
    array $projection
): array {
    $host = trim((string)$credentials['host']);
    $port = (int)($credentials['port'] ?? 7557);

    $params = [
        'limit' => $limit,
        'skip' => $skip,
        'sort' => json_encode(['_id' => 1], JSON_UNESCAPED_SLASHES),
        'projection' => implode(',', $projection),
    ];

    if ($query) {
        $params['query'] = json_encode(
            $query,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    $url = 'http://' . $host . ':' . $port . '/devices/?' .
        http_build_query($params, '', '&', PHP_QUERY_RFC3986);

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Falha ao iniciar consulta no GenieACS.');
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);

    $username = (string)($credentials['username'] ?? '');
    $password = (string)($credentials['password'] ?? '');
    if ($username !== '' || $password !== '') {
        curl_setopt($ch, CURLOPT_USERPWD, $username . ':' . $password);
    }

    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false || $http < 200 || $http >= 300) {
        throw new RuntimeException(
            'GenieACS HTTP ' . $http . ($error ? ': ' . $error : '')
        );
    }

    $data = json_decode((string)$body, true);
    if (!is_array($data)) {
        throw new RuntimeException('Resposta inválida do GenieACS.');
    }

    return array_values(array_filter($data, 'is_array'));
}

$db = getDBConnection();
DeviceInventoryCache::ensureSchema($db);

$lockPath = '/tmp/jrc-device-inventory-sync.lock';
$lock = fopen($lockPath, 'c');
if ($lock === false) {
    throw new RuntimeException('Falha ao abrir lock do inventário.');
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    logLine('Sincronização já está em andamento; encerrando.', $quiet);
    exit(0);
}

$started = microtime(true);
$generation = bin2hex(random_bytes(10));
$mode = 'incremental';

try {
    $result = $db->query(
        "SELECT host, port, username, password
         FROM genieacs_credentials
         WHERE is_connected = 1
         ORDER BY id DESC
         LIMIT 1"
    );
    $credentials = $result->fetch_assoc();

    if (!$credentials) {
        throw new RuntimeException('GenieACS não conectado.');
    }

    $meta = DeviceInventoryCache::meta($db);
    $lastSuccess = $meta['last_success_at'] ?? null;
    $lastFull = $meta['last_full_sync_at'] ?? null;

    $needsFull = $forceFull ||
        !$lastSuccess ||
        !$lastFull ||
        (time() - (strtotime((string)$lastFull) ?: 0)) >= 1800;

    if ($needsFull) {
        $mode = 'full';
        $query = [];
    } else {
        $since = max(
            0,
            (strtotime((string)$lastSuccess) ?: time()) - 120
        );
        $query = [
            '_lastInform' => [
                '$gte' => gmdate('Y-m-d\TH:i:s.000\Z', $since),
            ],
        ];
    }

    logLine('Modo: ' . $mode, $quiet);

    $projection = [
        '_id',
        '_deviceId',
        '_lastInform',
        '_tags',
        'InternetGatewayDevice.ManagementServer.ConnectionRequestURL',
        'Device.ManagementServer.ConnectionRequestURL',
        'InternetGatewayDevice.DeviceInfo.UpTime',
        'InternetGatewayDevice.DeviceInfo.HardwareVersion',
        'InternetGatewayDevice.DeviceInfo.SoftwareVersion',
        'Device.DeviceInfo.UpTime',
        'Device.DeviceInfo.HardwareVersion',
        'Device.DeviceInfo.SoftwareVersion',
        'InternetGatewayDevice.DeviceInfo.Temperature',
        'InternetGatewayDevice.LANDevice.1.LANEthernetInterfaceConfig.1.MACAddress',
        'InternetGatewayDevice.LANDevice.1.WLANConfiguration',
        'Device.WiFi',
        'InternetGatewayDevice.WANDevice.1.WANConnectionDevice',
        'VirtualParameters.RXPower',
        'VirtualParameters.gettemp',
        'VirtualParameters.Temperature',
        'InternetGatewayDevice.WANDevice.1.X_CT-COM_EponInterfaceConfig',
        'Device.Optical.Interface.1',
        'InternetGatewayDevice.LANDevice.1.Hosts.Host',
    ];

    $sql = "
        INSERT INTO " . DeviceInventoryCache::TABLE . " (
            device_id,
            serial_number,
            mac_address,
            manufacturer,
            oui,
            product_class,
            hardware_version,
            software_version,
            ip_tr069,
            ip_address,
            wifi_ssid,
            wifi_password,
            pppoe_username,
            rx_power,
            temperature,
            connected_devices_count,
            uptime,
            last_inform,
            tags_json,
            sync_generation,
            synced_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            serial_number = VALUES(serial_number),
            mac_address = VALUES(mac_address),
            manufacturer = VALUES(manufacturer),
            oui = VALUES(oui),
            product_class = VALUES(product_class),
            hardware_version = VALUES(hardware_version),
            software_version = VALUES(software_version),
            ip_tr069 = VALUES(ip_tr069),
            ip_address = VALUES(ip_address),
            wifi_ssid = VALUES(wifi_ssid),
            wifi_password = VALUES(wifi_password),
            pppoe_username = VALUES(pppoe_username),
            rx_power = VALUES(rx_power),
            temperature = VALUES(temperature),
            connected_devices_count = VALUES(connected_devices_count),
            uptime = VALUES(uptime),
            last_inform = VALUES(last_inform),
            tags_json = VALUES(tags_json),
            sync_generation = VALUES(sync_generation),
            synced_at = VALUES(synced_at)
    ";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Falha ao preparar upsert: ' . $db->error);
    }

    $chunkSize = 200;
    $skip = 0;
    $processed = 0;

    while (true) {
        $rows = nbiRequest(
            $credentials,
            $chunkSize,
            $skip,
            $query,
            $projection
        );

        if (!$rows) break;

        foreach ($rows as $raw) {
            $device = GenieACS_Fast::parseDeviceDataFast($raw);

            $deviceId = (string)($device['device_id'] ?? '');
            if ($deviceId === '' || $deviceId === 'N/A') continue;

            $serial = (string)($device['serial_number'] ?? '');
            $mac = (string)($device['mac_address'] ?? '');
            $manufacturer = (string)($device['manufacturer'] ?? '');
            $oui = (string)($device['oui'] ?? '');
            $productClass = (string)($device['product_class'] ?? '');
            $hardwareVersion = (string)($device['hardware_version'] ?? '');
            $softwareVersion = (string)($device['software_version'] ?? '');
            $ipTr069 = (string)($device['ip_tr069'] ?? '');
            $ipAddress = (string)($device['ip_address'] ?? '');
            $wifiSsid = (string)($device['wifi_ssid'] ?? '');
            $wifiPassword = (string)($device['wifi_password'] ?? '');
            $pppoe = (string)($device['pppoe_username'] ?? '');

            if ($serial === 'N/A') $serial = '';
            if ($mac === 'N/A') $mac = '';
            if ($manufacturer === 'N/A') $manufacturer = '';
            if ($oui === 'N/A') $oui = '';
            if ($productClass === 'N/A') $productClass = '';
            if ($hardwareVersion === 'N/A') $hardwareVersion = '';
            if ($softwareVersion === 'N/A') $softwareVersion = '';
            if ($ipTr069 === 'N/A') $ipTr069 = '';
            if ($ipAddress === 'N/A') $ipAddress = '';
            if ($wifiSsid === 'N/A') $wifiSsid = '';
            if ($wifiPassword === 'N/A') $wifiPassword = '';
            if ($pppoe === 'N/A') $pppoe = '';

            $rx = $device['rx_power'] ?? null;
            $rx = is_numeric($rx) ? (float)$rx : null;

            $temperature = $device['temperature'] ?? null;
            $temperature = is_numeric($temperature) ? (float)$temperature : null;

            $clients = max(0, (int)($device['connected_devices_count'] ?? 0));
            $uptime = max(0, (int)($device['uptime'] ?? 0));

            $lastInform = null;
            if (!empty($raw['_lastInform'])) {
                $ts = strtotime((string)$raw['_lastInform']);
                if ($ts !== false) {
                    $lastInform = date('Y-m-d H:i:s', $ts);
                }
            }

            $tagsJson = json_encode(
                is_array($device['tags'] ?? null) ? $device['tags'] : [],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            $syncedAt = date('Y-m-d H:i:s');

            $stmt->bind_param(
                'sssssssssssssddiissss',
                $deviceId,
                $serial,
                $mac,
                $manufacturer,
                $oui,
                $productClass,
                $hardwareVersion,
                $softwareVersion,
                $ipTr069,
                $ipAddress,
                $wifiSsid,
                $wifiPassword,
                $pppoe,
                $rx,
                $temperature,
                $clients,
                $uptime,
                $lastInform,
                $tagsJson,
                $generation,
                $syncedAt
            );
            $stmt->execute();
            $processed++;
        }

        if (count($rows) < $chunkSize) break;
        $skip += $chunkSize;
    }

    $stmt->close();

    if ($mode === 'full') {
        $delete = $db->prepare(
            "DELETE FROM " . DeviceInventoryCache::TABLE . "
             WHERE sync_generation <> ?"
        );
        $delete->bind_param('s', $generation);
        $delete->execute();
        $delete->close();
    }

    $deviceCount = (int)$db->query(
        "SELECT COUNT(*) AS c FROM " . DeviceInventoryCache::TABLE
    )->fetch_assoc()['c'];

    $durationMs = (int)round((microtime(true) - $started) * 1000);
    $now = date('Y-m-d H:i:s');
    $lastFullValue = $mode === 'full' ? $now : ($lastFull ?: null);
    $emptyError = null;

    $metaStmt = $db->prepare(
        "UPDATE " . DeviceInventoryCache::META_TABLE . "
         SET last_sync_at = ?,
             last_success_at = ?,
             last_full_sync_at = ?,
             last_duration_ms = ?,
             device_count = ?,
             last_mode = ?,
             last_error = ?
         WHERE id = 1"
    );
    $metaStmt->bind_param(
        'sssiiss',
        $now,
        $now,
        $lastFullValue,
        $durationMs,
        $deviceCount,
        $mode,
        $emptyError
    );
    $metaStmt->execute();
    $metaStmt->close();

    logLine(
        'OK: ' . $processed . ' atualizadas; ' .
        $deviceCount . ' no inventário; ' .
        $durationMs . ' ms.',
        $quiet
    );
} catch (Throwable $e) {
    $durationMs = (int)round((microtime(true) - $started) * 1000);
    $now = date('Y-m-d H:i:s');
    $message = substr($e->getMessage(), 0, 4000);

    try {
        $metaStmt = $db->prepare(
            "UPDATE " . DeviceInventoryCache::META_TABLE . "
             SET last_sync_at = ?,
                 last_duration_ms = ?,
                 last_mode = ?,
                 last_error = ?
             WHERE id = 1"
        );
        $metaStmt->bind_param('siss', $now, $durationMs, $mode, $message);
        $metaStmt->execute();
        $metaStmt->close();
    } catch (Throwable $ignored) {
    }

    fwrite(STDERR, '[JRC INVENTORY] ' . $message . PHP_EOL);
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
