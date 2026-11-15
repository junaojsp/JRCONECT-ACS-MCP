<?php
/* Read-only device finder for firmware bench UI. GET only; NEVER writes to GenieACS. */
require_once __DIR__ . '/config/config.php';
requireLogin();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function result($status, array $data) {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function valueAt($source, $path) {
    $v = $source;
    foreach (explode('.', $path) as $key) {
        if (!is_array($v) || !array_key_exists($key, $v)) return '';
        $v = $v[$key];
    }
    if (is_array($v) && array_key_exists('_value', $v)) $v = $v['_value'];
    return is_scalar($v) ? trim((string)$v) : '';
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') result(405, ['error'=>'Somente consulta GET.']);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['jrc_fwb_csrf']) || !hash_equals($_SESSION['jrc_fwb_csrf'], (string)($_GET['csrf'] ?? ''))) {
    result(403, ['error'=>'Sessao de homologacao invalida. Recarregue a pagina.']);
}
$type = (string)($_GET['type'] ?? '');
$term = trim((string)($_GET['term'] ?? ''));
if (!in_array($type, ['device', 'model'], true) || strlen($term) < 3 || strlen($term) > 64 ||
    !preg_match('/^[A-Za-z0-9:._\- ]+$/D', $term)) {
    result(422, ['error'=>'Informe pelo menos 3 caracteres validos de MAC, serial, ID ou modelo.']);
}
$escaped = preg_quote($term, '/');
$rx = ['$regex' => $escaped, '$options' => 'i'];
if ($type === 'device') {
    $mac = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $term));
    $macText = strlen($mac) === 12 ? implode(':', str_split($mac, 2)) : $term;
    $macRx = ['$regex' => preg_quote($macText, '/'), '$options' => 'i'];
    $query = ['$or'=>[
        ['_id'=>$rx],
        ['_deviceId._SerialNumber'=>$rx],
        ['Device.DeviceInfo.SerialNumber'=>$rx],
        ['InternetGatewayDevice.DeviceInfo.SerialNumber'=>$rx],
        ['InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.MACAddress'=>$macRx],
        ['Device.Ethernet.Interface.1.MACAddress'=>$macRx],
        ['InternetGatewayDevice.LANDevice.1.LANEthernetInterfaceConfig.1.MACAddress'=>$macRx]
    ]];
} else {
    $query = ['$or'=>[
        ['_deviceId._ProductClass'=>$rx],
        ['Device.DeviceInfo.ModelName'=>$rx],
        ['InternetGatewayDevice.DeviceInfo.ModelName'=>$rx]
    ]];
}
$base = rtrim((string)(getenv('JRC_FW_NBI_URL') ?: 'http://127.0.0.1:7557'), '/');
// Restrict NBI access to loopback: this endpoint cannot proxy arbitrary requests.
if (!preg_match('~^http://(?:127\.0\.0\.1|localhost):[0-9]{2,5}$~D', $base)) {
    result(503, ['error'=>'Configure JRC_FW_NBI_URL para endereco local autorizado.']);
}
$projection = implode(',', [
    '_id','_deviceId','_lastInform',
    'Device.DeviceInfo.Manufacturer','Device.DeviceInfo.ModelName',
    'Device.DeviceInfo.HardwareVersion','Device.DeviceInfo.SoftwareVersion','Device.DeviceInfo.SerialNumber',
    'InternetGatewayDevice.DeviceInfo.Manufacturer','InternetGatewayDevice.DeviceInfo.ModelName',
    'InternetGatewayDevice.DeviceInfo.HardwareVersion','InternetGatewayDevice.DeviceInfo.SoftwareVersion',
    'InternetGatewayDevice.DeviceInfo.SerialNumber',
    'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.MACAddress',
    'Device.Ethernet.Interface.1.MACAddress'
]);
$url = $base . '/devices/?' . http_build_query([
    'query'=>json_encode($query, JSON_UNESCAPED_SLASHES),
    'projection'=>$projection,
    'limit'=>'40'
], '', '&', PHP_QUERY_RFC3986);
if (!function_exists('curl_init')) result(503, ['error'=>'Modulo PHP cURL nao disponivel.']);
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>8, CURLOPT_CONNECTTIMEOUT=>2,
    CURLOPT_FOLLOWLOCATION=>false, CURLOPT_PROTOCOLS=>CURLPROTO_HTTP,
    CURLOPT_HTTPHEADER=>['Accept: application/json'],
    CURLOPT_MAXFILESIZE=>2097152
]);
$raw = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);
if ($raw === false || $http !== 200) {
    result(502, ['error'=>'Consulta ao GenieACS NBI indisponivel; confira endpoint local.','detail'=>$http ?: $curlErr]);
}
try {
    $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    result(502, ['error'=>'Resposta NBI invalida.']);
}
if (!is_array($data) || !array_is_list($data)) result(502, ['error'=>'Resposta de dispositivos inesperada.']);
$items=[];
foreach (array_slice($data, 0, 40) as $d) {
    if (!is_array($d)) continue;
    $pick = function ($paths) use ($d) {
        foreach ($paths as $path) {
            $v=valueAt($d, $path);
            if ($v!=='') return $v;
        }
        return '';
    };
    $items[]=[
        'id'=>$pick(['_id']),
        'serial'=>$pick(['_deviceId._SerialNumber','Device.DeviceInfo.SerialNumber','InternetGatewayDevice.DeviceInfo.SerialNumber']),
        'manufacturer'=>$pick(['Device.DeviceInfo.Manufacturer','InternetGatewayDevice.DeviceInfo.Manufacturer','_deviceId._Manufacturer']),
        'model'=>$pick(['Device.DeviceInfo.ModelName','InternetGatewayDevice.DeviceInfo.ModelName','_deviceId._ProductClass']),
        'hardware'=>$pick(['Device.DeviceInfo.HardwareVersion','InternetGatewayDevice.DeviceInfo.HardwareVersion']),
        'version'=>$pick(['Device.DeviceInfo.SoftwareVersion','InternetGatewayDevice.DeviceInfo.SoftwareVersion']),
        'mac'=>$pick(['InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.MACAddress','Device.Ethernet.Interface.1.MACAddress']),
        'lastInform'=>$pick(['_lastInform'])
    ];
}
result(200, ['items'=>$items, 'truncated'=>count($data)>=40, 'source'=>'genieacs_nbi_readonly']);
