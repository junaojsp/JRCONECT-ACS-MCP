<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/JrcSnmpTelemetry.php';
function check($actual, $expected, $message) {
    if ($actual !== $expected) throw new RuntimeException($message . ': ' . json_encode($actual));
}
check(JrcSnmpTelemetry::serial('485754438FEEDBA7'), 'HWTC8FEEDBA7', 'hex serial');
$old = ['serial_number'=>'HWTC8feedba7', 'rx_power'=>-10, 'ixc_rx_power'=>-12,
        'temperature'=>30, 'status'=>'online'];
$empty = JrcSnmpTelemetry::apply($old, [], 1100, true);
check($empty['rx_power'], null, 'no fallback');
check($empty['ixc_rx_power'], null, 'no IXC fallback');
check($empty['status'], 'unknown', 'missing SNMP never inherits TR069 status');
$snapshot = ['completed_at_epoch'=>1000, 'last_attempt'=>[
    'success'=>true, 'attempted_at_epoch'=>900, 'finished_at_epoch'=>1000],
    'devices'=>['HWTC8FEEDBA7'=>['serial'=>'HWTC8FEEDBA7', 'state'=>'online',
        'location'=>'PON 11/4/2', 'metrics'=>['rx'=>[
            'candidate_value'=>-23.87, 'polled_at_epoch'=>990]]]]];
$row = JrcSnmpTelemetry::apply($old, $snapshot, 1100, true);
check($row['rx_power'], -23.87, 'SNMP value');
check($row['rx_measured_at_epoch'], null, 'poll is not measurement');
check($row['rx_status'], 'normal', 'classification');
check($row['management_status'], 'online', 'keep management evidence separately');
check(JrcSnmpTelemetry::optical($row)['optical']['last_update'], null, 'unknown measurement date');
$snapshot['last_attempt']['success'] = false;
check(JrcSnmpTelemetry::apply($old, $snapshot, 1100, true)['rx_power'], null, 'failed last attempt');
$snapshot['last_attempt']['success'] = true;
check(JrcSnmpTelemetry::apply($old, $snapshot, 3000, true)['rx_power'], null, 'stale snapshot');
$snapshot['devices']['HWTC8FEEDBA7']['state'] = 'offline';
check(JrcSnmpTelemetry::apply($old, $snapshot, 1100, true)['rx_power'], null, 'offline optics not current');
echo "SNMP snapshot reader: OK\n";
