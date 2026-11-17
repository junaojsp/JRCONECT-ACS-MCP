<?php
declare(strict_types=1);
/** Read-only cached CPU/RAM audit. No task, refresh, cron or configuration change. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$serial = trim((string)($argv[1] ?? ''));
if ($serial === '' || strlen($serial) > 128) {
    fwrite(STDERR, "Uso: php scripts/audit-acs-device-resources.php SERIAL_DA_ONU\n"); exit(1);
}
try {
    require_once '/var/www/gacs/config/config.php';
    require_once __DIR__ . '/../lib/JrcDeviceResources.php';
    $db = getDBConnection();
    $q = $db->query('SELECT host,port,username,password FROM genieacs_credentials WHERE is_connected=1 ORDER BY id DESC LIMIT 1');
    $cred = $q ? $q->fetch_assoc() : null;
    if (!$cred || !in_array(trim((string)$cred['host']), ['localhost','127.0.0.1','::1'], true)) {
        throw new RuntimeException('A auditoria exige NBI local configurado.');
    }
    $port = (int)$cred['port'];
    if ($port < 1 || $port > 65535) throw new RuntimeException('Porta NBI inválida.');
    $params = http_build_query(['query'=>json_encode(['_deviceId._SerialNumber'=>$serial]),
        'projection'=>'_id,Device.DeviceInfo,InternetGatewayDevice.DeviceInfo','limit'=>1], '', '&', PHP_QUERY_RFC3986);
    $ch = curl_init('http://127.0.0.1:' . $port . '/devices/?' . $params);
    curl_setopt_array($ch, [CURLOPT_HTTPGET=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>['Accept: application/json']]);
    if (($cred['username'] ?? '') !== '') curl_setopt($ch, CURLOPT_USERPWD, $cred['username'] . ':' . ($cred['password'] ?? ''));
    $body = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); unset($cred);
    if (!is_string($body) || $status !== 200) throw new RuntimeException('Consulta NBI falhou; HTTP ' . $status);
    $records = json_decode($body, true, 512, JSON_THROW_ON_ERROR); unset($body);
    if (!$records || !isset($records[0])) throw new RuntimeException('Serial não localizado no cache do ACS.');
    $raw = $records[0]; $observed = [];
    $walk = static function ($node, string $path) use (&$walk, &$observed): void {
        if (!is_array($node)) return;
        if (array_key_exists('_value', $node)) {
            if (preg_match('/CPU|Memory|RAM|MemUsage/i', $path) && is_numeric($node['_value'])) {
                $observed[] = ['parameter'=>$path,'value'=>0 + $node['_value'],'type'=>$node['_type'] ?? null];
            }
            return;
        }
        foreach ($node as $key=>$child) {
            if (str_starts_with((string)$key, '_')) continue;
            $walk($child, $path === '' ? (string)$key : $path . '.' . $key);
        }
    };
    $walk($raw, '');
    $normalized = jrcDeviceResources($raw);
    $cpu = array_key_exists('cpu_usage_percent', $normalized);
    $ram = array_key_exists('memory_usage_percent', $normalized);
    echo json_encode(['serial'=>$serial,'source'=>'GenieACS cache; somente GET',
        'cpu_status'=>$cpu?'DISPONIVEL':'AUSENTE_OU_NAO_MAPEADO_NO_CACHE',
        'memory_status'=>$ram?'DISPONIVEL':'AUSENTE_OU_NAO_MAPEADO_NO_CACHE',
        'normalized'=>$normalized,'observed_resource_parameters'=>$observed], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $error) {
    // Do not print exception context or connection details.
    $safe = ['A auditoria exige NBI local configurado.', 'Porta NBI inválida.',
             'Serial não localizado no cache do ACS.'];
    $message = in_array($error->getMessage(), $safe, true) ? $error->getMessage() : 'Não foi possível consultar os recursos no NBI local.';
    fwrite(STDERR, 'AUDITORIA_FALHOU: ' . $message . PHP_EOL); exit(1);
}
