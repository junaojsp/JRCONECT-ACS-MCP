<?php
declare(strict_types=1);
// Only local schema/coverage inspection. Never include installed API endpoints.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }
$root = $argv[1] ?? '/var/www/gacs';
$fields = ['success','data','devices','items','rows','count','total','source','optical',
    'device_id','serial','serial_number','rx_power','tx_power','temperature','voltage',
    'online','status','devices_ids','device_ids','deviceIds','ids','serials','limit','skip',
    'ip','ip_address','pppoe_username','manufacturer','product_class','model','name','nome',
    'pon','pon_id','slot','onu_number','summary','generated_at','cached','cached_at'];
function fragment(array $tokens, int $start, int $count, array $fields): string {
    $out = '';
    for ($j = $start; $j < min(count($tokens), $start + $count); $j++) {
        $t = $tokens[$j];
        if (!is_array($t)) { $out .= $t; continue; }
        if (in_array($t[0], [T_VARIABLE,T_STRING,T_DOUBLE_ARROW,T_OBJECT_OPERATOR,T_DOUBLE_COLON], true)) {
            $out .= $t[1];
        } elseif ($t[0] === T_CONSTANT_ENCAPSED_STRING) {
            $key = substr($t[1], 1, -1);
            $next = $tokens[$j + 1] ?? null;
            $prev = $tokens[$j - 1] ?? null;
            $isKey = $prev === '[' || (is_array($next) && $next[0] === T_DOUBLE_ARROW);
            $out .= $isKey && in_array($key, $fields, true) ? "'".$key."'" : '<literal>';
        } else $out .= '<value>';
    }
    return $out;
}
foreach (['api/get-devices-ixc-batch.php','api/discovery-ixc-tr069.php'] as $path) {
    $text = file_get_contents($root . '/' . $path);
    $tokens = array_values(array_filter(token_get_all((string)$text), static fn($t) =>
        !is_array($t) || !in_array($t[0], [T_WHITESPACE,T_COMMENT,T_DOC_COMMENT], true)));
    $info = ['file'=>$path, 'input'=>[], 'device_writes'=>[], 'output'=>[]];
    foreach ($tokens as $i => $t) {
        if (!is_array($t)) continue;
        if ($t[0] === T_VARIABLE && in_array($t[1], ['$_GET','$_POST','$_REQUEST','$_SERVER'], true)) {
            $info['input'][] = fragment($tokens, $i, 6, $fields);
        }
        if ($t[0] === T_STRING && strtolower($t[1]) === 'json_decode') {
            $info['input'][] = fragment($tokens, $i, 14, $fields);
        }
        if ($t[0] === T_VARIABLE && in_array($t[1], ['$devices','$out','$result','$input','$payload'], true)) {
            $following = array_slice($tokens, $i + 1, 7);
            if (in_array('=', $following, true) || in_array('[', $following, true)) {
                $info['device_writes'][] = fragment($tokens, $i, 16, $fields);
            }
        }
        if ($t[0] === T_CONSTANT_ENCAPSED_STRING &&
            in_array(substr($t[1], 1, -1), ['devices','data','summary','optical','count','total'], true)) {
            $next = $tokens[$i+1] ?? null;
            if (is_array($next) && $next[0] === T_DOUBLE_ARROW) $info['output'][] = fragment($tokens, $i, 10, $fields);
        }
    }
    foreach (['input','device_writes','output'] as $key) $info[$key] = array_values(array_unique($info[$key]));
    echo json_encode($info, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
}
// Local cached inventory coverage; no queries to IXC, OLT or GenieACS.
try {
    require_once $root . '/config/config.php';
    require_once $root . '/lib/JrcSnmpTelemetry.php';
    require_once $root . '/lib/JrcDashboardSnapshot.php';
    $snapshot = JrcSnmpTelemetry::load();
    $inventory = JrcDashboardSnapshot::load(getDBConnection());
    $stats = ['snapshot_loaded'=>$snapshot !== null, 'inventory_loaded'=>$inventory !== null,
        'inventory_total'=>0, 'snmp_matches'=>0, 'snmp_online_matches'=>0, 'usable_rx'=>0];
    foreach (($inventory['devices'] ?? []) as $device) {
        $stats['inventory_total']++;
        $serial = JrcSnmpTelemetry::serial((string)($device['serial_number'] ?? ''));
        $row = $snapshot['devices'][$serial] ?? null;
        if ($row === null) continue;
        $stats['snmp_matches']++;
        if (($row['state'] ?? '') === 'online') $stats['snmp_online_matches']++;
        $merged = JrcSnmpTelemetry::apply($device, $snapshot, time(), true);
        if (is_numeric($merged['rx_power'] ?? null)) $stats['usable_rx']++;
    }
    echo 'COVERAGE ' . json_encode($stats, JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $error) {
    echo 'COVERAGE_ERROR ' . get_class($error) . "\n";
}
