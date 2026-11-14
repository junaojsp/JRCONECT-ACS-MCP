<?php
/* JRC_REDE_HOSTS_V1 — clientes Wi-Fi e cabo só na aba Rede / Hosts. */
require_once __DIR__ . '/../config/config.php';

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
requireLogin();

$deviceId = isset($_GET['device_id']) ? trim((string) $_GET['device_id']) : '';
if ($deviceId === '') {
    jsonResponse(['success' => false, 'message' => 'Informe o equipamento.']);
}

if (!isGenieACSConfigured()) {
    jsonResponse(['success' => false, 'message' => 'GenieACS não configurado.']);
}

$conn = getDBConnection();
$result = $conn->query("SELECT * FROM genieacs_credentials WHERE is_connected = 1 ORDER BY id DESC LIMIT 1");
$credentials = $result ? $result->fetch_assoc() : null;
if (!$credentials) {
    jsonResponse(['success' => false, 'message' => 'GenieACS não conectado.']);
}

require_once __DIR__ . '/../lib/jrc-connected-hosts.php';

use App\GenieACS;

try {
    @set_time_limit(45);
    $genieacs = new GenieACS(
        $credentials['host'],
        $credentials['port'],
        $credentials['username'],
        $credentials['password']
    );
    $deviceResult = $genieacs->getDevice($deviceId);
    if (empty($deviceResult['success'])) {
        jsonResponse(['success' => false, 'message' => 'Equipamento não encontrado.']);
    }
    $raw = $deviceResult['data'];
    $hosts = jrcExtractConnectedDevices($raw);
    $signal = jrcExtractOnuSignal($raw);
    $askedLive = isset($_GET['live']) && (string) $_GET['live'] === '1';
    $tasks = jrcRedeHostsLiveTasks($raw, $hosts, $signal);
    if ($askedLive && $tasks !== []) {
        $live = jrcRedeHostsAskOnu(
            (string) $credentials['host'],
            (int) $credentials['port'],
            (string) ($credentials['username'] ?? ''),
            (string) ($credentials['password'] ?? ''),
            $deviceId,
            $tasks
        );
        if ($live['ok']) {
            $again = $genieacs->getDevice($deviceId);
            if (!empty($again['success'])) {
                $raw = $again['data'];
                $hosts = jrcExtractConnectedDevices($raw);
                $signal = jrcExtractOnuSignal($raw);
            }
        } elseif ($hosts === []) {
            jsonResponse([
                'success' => true,
                'count' => 0,
                'devices' => [],
                'signal' => $signal,
                'message' => 'A ONU não respondeu à leitura ao vivo. Abra a ficha de novo em alguns segundos.',
            ]);
        }
    }
    $devices = [];
    foreach ($hosts as $host) {
        if (!is_array($host)) {
            continue;
        }
        $devices[] = [
            'hostname' => jrcRedeHostsText($host['hostname'] ?? ''),
            'ip_address' => jrcRedeHostsText($host['ip_address'] ?? '-'),
            'mac_address' => jrcRedeHostsText($host['mac_address'] ?? ''),
            'interface_type' => jrcRedeHostsText($host['interface_type'] ?? ''),
            'wifi_band' => jrcRedeHostsText($host['wifi_band'] ?? ''),
            'lan_port' => isset($host['lan_port']) ? (int) $host['lan_port'] : null,
            'active' => !empty($host['active']),
        ];
        if (count($devices) >= 80) {
            break;
        }
    }
    jsonResponse([
        'success' => true,
        'count' => count($devices),
        'devices' => $devices,
        'signal' => $signal,
    ]);
} catch (Throwable $e) {
    jsonResponse(['success' => false, 'message' => 'Não foi possível ler os clientes deste equipamento.']);
}

function jrcRedeHostsPathText($device, array $path)
{
    $node = $device;
    foreach ($path as $key) {
        if (!is_array($node) || !isset($node[$key])) {
            return '';
        }
        $node = $node[$key];
    }
    if (is_array($node) && array_key_exists('_value', $node)) {
        return trim((string) $node['_value']);
    }
    return is_scalar($node) ? trim((string) $node) : '';
}

function jrcRedeHostsWlanIndexes(array $device)
{
    $indexes = [];
    foreach (jrcCwmpChildren($device['InternetGatewayDevice']['LANDevice'] ?? []) as $lan) {
        foreach (jrcCwmpChildren($lan['WLANConfiguration'] ?? []) as $index => $wlan) {
            $indexes[(string) $index] = true;
        }
    }
    $label = jrcRedeHostsPathText($device, ['InternetGatewayDevice', 'DeviceInfo', 'ProductClass'])
        . ' ' . jrcRedeHostsPathText($device, ['InternetGatewayDevice', 'DeviceInfo', 'ModelName'])
        . ' ' . jrcRedeHostsPathText($device, ['Device', 'DeviceInfo', 'ProductClass'])
        . ' ' . jrcRedeHostsPathText($device, ['Device', 'DeviceInfo', 'ModelName'])
        . ' ' . jrcRedeHostsPathText($device, ['_deviceId', '_ProductClass']);
    if (preg_match('/HG6143|HG6145|FiberHome|FHTT/i', $label) === 1 || isset($indexes['5'])) {
        $indexes['1'] = true;
        $indexes['5'] = true;
    }
    if ($indexes === [] && (isset($device['InternetGatewayDevice']) || !isset($device['Device']))) {
        $indexes['1'] = true;
    }
    $keys = array_keys($indexes);
    sort($keys, SORT_NATURAL);
    return $keys;
}

function jrcRedeHostsLiveTasks(array $device, array $hosts, array $signal)
{
    $tasks = [];
    if ($hosts === []) {
        if (isset($device['InternetGatewayDevice']) || !isset($device['Device'])) {
            foreach (jrcRedeHostsWlanIndexes($device) as $index) {
                $base = 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.' . $index . '.';
                $tasks[] = ['name' => 'refreshObject', 'objectName' => $base . 'AssociatedDevice.'];
                $tasks[] = ['name' => 'refreshObject', 'objectName' => $base . 'X_FH_AssociatedDevice.'];
                $tasks[] = ['name' => 'refreshObject', 'objectName' => $base . 'X_FH_WLANStaInfo.'];
            }
            $tasks[] = ['name' => 'refreshObject', 'objectName' => 'InternetGatewayDevice.LANDevice.1.Hosts.'];
            $tasks[] = ['name' => 'refreshObject', 'objectName' => 'InternetGatewayDevice.LANDevice.1.LANEthernetInterfaceConfig.'];
        }
        if (isset($device['Device'])) {
            $tasks[] = ['name' => 'refreshObject', 'objectName' => 'Device.Hosts.'];
            $tasks[] = ['name' => 'refreshObject', 'objectName' => 'Device.WiFi.AccessPoint.'];
            $tasks[] = ['name' => 'refreshObject', 'objectName' => 'Device.DHCPv4.Server.Pool.'];
        }
    }
    if ($signal['rx_power'] === null && $signal['tx_power'] === null) {
        if (isset($device['InternetGatewayDevice']) || !isset($device['Device'])) {
            $tasks[] = ['name' => 'refreshObject', 'objectName' => 'InternetGatewayDevice.WANDevice.1.'];
            $tasks[] = ['name' => 'refreshObject', 'objectName' => 'InternetGatewayDevice.DeviceInfo.'];
        }
        if (isset($device['Device'])) {
            $tasks[] = ['name' => 'refreshObject', 'objectName' => 'Device.Optical.'];
            $tasks[] = ['name' => 'refreshObject', 'objectName' => 'Device.DeviceInfo.'];
        }
    }
    return $tasks;
}

function jrcRedeHostsAskOnu($host, $port, $username, $password, $deviceId, array $tasks)
{
    if ($tasks === [] || $host === '' || $port < 1) {
        return ['ok' => false];
    }
    $url = 'http://' . $host . ':' . $port . '/devices/' . rawurlencode($deviceId) . '/tasks?timeout=20000&connection_request';
    $payload = count($tasks) === 1 ? $tasks[0] : $tasks;
    $response = jrcRedeHostsPost($url, $payload, $username, $password, 28);
    if ($response['code'] === 400 && count($tasks) > 1) {
        $queued = [];
        $plain = 'http://' . $host . ':' . $port . '/devices/' . rawurlencode($deviceId) . '/tasks';
        foreach ($tasks as $task) {
            $one = jrcRedeHostsPost($plain, $task, $username, $password, 8);
            if (in_array($one['code'], [200, 202], true)) {
                $queued[] = $task;
            }
        }
        $response = ['code' => 0, 'error' => ''];
        if ($queued !== []) {
            $response = jrcRedeHostsPost($url, $queued[count($queued) - 1], $username, $password, 28);
        }
    }
    if (!in_array($response['code'], [200, 202], true)) {
        return ['ok' => false];
    }
    if ($response['code'] === 202) {
        usleep(4000000);
    }
    return ['ok' => true];
}

function jrcRedeHostsPost($url, $payload, $username, $password, $timeout)
{
    if (!function_exists('curl_init')) {
        return ['code' => 0, 'error' => 'curl'];
    }
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    if ($username !== '' || $password !== '') {
        curl_setopt($ch, CURLOPT_USERPWD, $username . ':' . $password);
    }
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'error' => $error];
}

function jrcRedeHostsText($value)
{
    $text = (string) $value;
    if (function_exists('iconv')) {
        $clean = iconv('UTF-8', 'UTF-8//IGNORE', $text);
        if (is_string($clean)) {
            $text = $clean;
        }
    }
    if (strlen($text) > 180) {
        $text = substr($text, 0, 180);
    }
    return $text;
}
