<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

use App\GenieACS_Fast;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

requireLogin();

if (!isGenieACSConfigured()) {
    jsonResponse(['success' => false, 'message' => 'GenieACS não configurado.'], 503);
}

$conn = getDBConnection();
$result = $conn->query("SELECT host, port, username, password FROM genieacs_credentials WHERE is_connected = 1 ORDER BY id DESC LIMIT 1");
$credentials = $result->fetch_assoc();

if (!$credentials) {
    jsonResponse(['success' => false, 'message' => 'GenieACS não conectado.'], 503);
}

function jrcDevicesNbiUrl(array $credentials): string {
    $host = trim((string)$credentials['host']);
    $port = (int)($credentials['port'] ?? 7557);
    return 'http://' . $host . ':' . $port . '/devices/';
}

function jrcDevicesApplyAuth($ch, array $credentials): void {
    $username = (string)($credentials['username'] ?? '');
    $password = (string)($credentials['password'] ?? '');
    if ($username !== '' || $password !== '') {
        curl_setopt($ch, CURLOPT_USERPWD, $username . ':' . $password);
    }
}

function jrcDevicesBuildUrl(array $credentials, array $params): string {
    return jrcDevicesNbiUrl($credentials) . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

function jrcDevicesCount(array $credentials, array $query = []): int {
    $params = [];
    if ($query) {
        $params['query'] = json_encode($query, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    $url = jrcDevicesBuildUrl($credentials, $params);
    $headers = [];

    $ch = curl_init($url);
    if ($ch === false) throw new RuntimeException('Falha ao iniciar contagem GenieACS.');

    curl_setopt_array($ch, [
        CURLOPT_NOBODY => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_HEADERFUNCTION => static function($curl, string $line) use (&$headers): int {
            $len = strlen($line);
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return $len;
        },
    ]);
    jrcDevicesApplyAuth($ch, $credentials);

    curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($http < 200 || $http >= 300) {
        throw new RuntimeException('Contagem GenieACS falhou HTTP ' . $http . ($error ? ': ' . $error : ''));
    }

    foreach (['total', 'x-total-count'] as $name) {
        if (isset($headers[$name]) && ctype_digit((string)$headers[$name])) {
            return (int)$headers[$name];
        }
    }

    throw new RuntimeException('GenieACS não retornou o total da coleção.');
}

function jrcDevicesFetchPage(
    array $credentials,
    array $query,
    int $limit,
    int $skip
): array {
    $params = [
        'limit' => $limit,
        'skip' => $skip,
        'sort' => json_encode(['_lastInform' => -1, '_id' => 1], JSON_UNESCAPED_SLASHES),
    ];

    if ($query) {
        $params['query'] = json_encode($query, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    $url = jrcDevicesBuildUrl($credentials, $params);
    $ch = curl_init($url);
    if ($ch === false) throw new RuntimeException('Falha ao iniciar consulta GenieACS.');

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    jrcDevicesApplyAuth($ch, $credentials);

    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false || $http < 200 || $http >= 300) {
        throw new RuntimeException('Consulta GenieACS falhou HTTP ' . $http . ($error ? ': ' . $error : ''));
    }

    $json = json_decode((string)$body, true);
    if (!is_array($json)) {
        throw new RuntimeException('Resposta inválida do GenieACS.');
    }

    return array_values(array_filter($json, 'is_array'));
}

function jrcDevicesSafeRegex(string $value): string {
    return preg_quote($value, '/');
}

function jrcDevicesSearchQuery(string $term, array $serials = []): array {
    $or = [];

    if ($term !== '') {
        $pattern = jrcDevicesSafeRegex($term);

        $regex = ['$regex' => $pattern, '$options' => 'i'];

        $or[] = ['_id' => $regex];
        $or[] = ['_deviceId._SerialNumber' => $regex];
        $or[] = ['_deviceId._ProductClass' => $regex];
        $or[] = ['_tags' => $regex];

        $or[] = ['InternetGatewayDevice.ManagementServer.ConnectionRequestURL' => $regex];
        $or[] = ['Device.ManagementServer.ConnectionRequestURL' => $regex];

        $or[] = ['InternetGatewayDevice.LANDevice.1.LANEthernetInterfaceConfig.1.MACAddress' => $regex];
        $or[] = ['InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.BSSID' => $regex];

        $or[] = ['InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID' => $regex];
        $or[] = ['Device.WiFi.SSID.1.SSID' => $regex];

        for ($i = 1; $i <= 8; $i++) {
            $or[] = [
                "InternetGatewayDevice.WANDevice.1.WANConnectionDevice.{$i}.WANPPPConnection.1.Username" => $regex
            ];
        }

        $or[] = ['Device.PPP.Interface.1.Username' => $regex];
    }

    $serials = array_values(array_unique(array_filter(array_map(static function($value): string {
        $normalized = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string)$value) ?? '');
        return substr($normalized, 0, 64);
    }, $serials))));

    if ($serials) {
        $or[] = ['_deviceId._SerialNumber' => ['$in' => $serials]];
    }

    if (!$or) return [];
    if (count($or) === 1) return $or[0];

    return ['$or' => $or];
}

function jrcDevicesStats(array $credentials): array {
    $total = jrcDevicesCount($credentials);

    $threshold = gmdate('Y-m-d H:i:s', time() - 300) . ' +0000';
    $online = jrcDevicesCount($credentials, [
        '_lastInform' => ['$gte' => $threshold]
    ]);

    return [
        'total' => $total,
        'online' => $online,
        'offline' => max(0, $total - $online),
    ];
}

try {
    $limit = (int)($_GET['limit'] ?? 20);
    if (!in_array($limit, [20, 50, 100], true)) $limit = 20;

    $page = max(1, (int)($_GET['page'] ?? 1));
    $term = trim((string)($_GET['q'] ?? ''));
    if (mb_strlen($term) > 128) {
        jsonResponse(['success' => false, 'message' => 'Busca muito longa.'], 400);
    }

    $serialsParam = trim((string)($_GET['serials'] ?? ''));
    $serials = [];
    if ($serialsParam !== '') {
        $serials = array_slice(array_filter(array_map('trim', explode(',', $serialsParam))), 0, 50);
    }

    $query = jrcDevicesSearchQuery($term, $serials);
    $totalFiltered = jrcDevicesCount($credentials, $query);

    $maxPage = max(1, (int)ceil($totalFiltered / $limit));
    if ($page > $maxPage) $page = $maxPage;

    $skip = ($page - 1) * $limit;
    $rawDevices = jrcDevicesFetchPage($credentials, $query, $limit, $skip);

    $devices = [];
    foreach ($rawDevices as $device) {
        $devices[] = GenieACS_Fast::parseDeviceDataFast($device);
    }

    $stats = ['total' => $totalFiltered, 'online' => null, 'offline' => null];
    try {
        $stats = jrcDevicesStats($credentials);
    } catch (Throwable $ignored) {
        // A lista continua funcional mesmo que o contador global falhe.
    }

    jsonResponse([
        'success' => true,
        'devices' => $devices,
        'count' => count($devices),
        'total' => $totalFiltered,
        'page' => $page,
        'limit' => $limit,
        'pages' => $maxPage,
        'hasMore' => $page < $maxPage,
        'stats' => $stats,
        'search' => [
            'term' => $term,
            'ixc_serials' => count($serials),
        ],
    ]);
} catch (Throwable $e) {
    error_log('[JRC DEVICES PAGE] ' . $e->getMessage());
    jsonResponse([
        'success' => false,
        'message' => 'Não foi possível carregar a página de equipamentos.',
    ], 502);
}
