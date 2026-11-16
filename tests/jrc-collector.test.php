<?php
declare(strict_types=1);

require_once __DIR__ . '/../collector/Config.php';
require_once __DIR__ . '/../collector/NbiReadOnly.php';
require_once __DIR__ . '/../collector/IxcReadOnly.php';
require_once __DIR__ . '/../collector/Diagnostics.php';
require_once __DIR__ . '/../collector/Consolidator.php';
require_once __DIR__ . '/../lib/GenieACS_Fast.php';

use JRC\Collector\Config;
use JRC\Collector\NbiReadOnly;
use JRC\Collector\IxcReadOnly;
use JRC\Collector\Diagnostics;
use JRC\Collector\Consolidator;

function same(mixed $a, mixed $b, string $label): void {
    if ($a !== $b) {
        throw new RuntimeException($label . ': got ' . var_export($a, true) .
                                   ' expected ' . var_export($b, true));
    }
}
function truth(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
}
$now = strtotime('2026-11-16T14:00:00Z');
$make = static function(string $id, string $serial, int $informAge, ?float $rx) use ($now): array {
    $raw = [
        '_id' => $id,
        '_deviceId' => ['_SerialNumber' => $serial, '_ProductClass' => 'ONU'],
        '_lastInform' => gmdate('c', $now - $informAge),
        'InternetGatewayDevice' => [
            'WANDevice' => ['1' => [
                'WANConnectionDevice' => ['1' => [
                    'WANPPPConnection' => ['1' => ['Username' => ['_value' => 'cliente_' . $id]]]
                ]]
            ]]
        ],
    ];
    if ($rx !== null) $raw['VirtualParameters']['RXPower'] = [
        '_value' => $rx, '_timestamp' => gmdate('c', $now - $informAge)
    ];
    return $raw;
};
$raw = [
    $make('A', 'AA:BB:01', 30, -22.1),
    $make('B', 'AA:BB:02', 40, -26.8),
    $make('C', 'AA:BB:03', 3800, -29.5),
];
$ixc = [
    IxcReadOnly::serial('AA:BB:01') => [
        'customer_name' => 'Cliente Teste',
        'id_login' => '12',
        'pppoe_username' => 'teste_fibra',
        'rx' => -28.23,
        'tx' => 2.5,
        'temperature' => 75.1,
        'voltage' => 3.3,
        'measured_at_epoch' => $now - 60
    ]
];
$flow = Consolidator::fleet($raw, $ixc, $now, 70.0);
same($flow['ixc_matched'], 1, 'matching by serial');
same($flow['summary']['total'], 3, 'total');
same($flow['summary']['online'], 2, 'online');
same($flow['summary']['offline'], 1, 'offline');
same($flow['summary']['sinal_critico'], 1, 'critico');
same($flow['summary']['sinal_fraco'], 1, 'fraco');
same($flow['summary']['sinal_sem_dados'], 1, 'stale no longer counts as live signal');
same($flow['summary']['temperatura_elevada'], 1, 'temperatura elevada');
same($flow['devices'][0]['rx_power'], -28.23, 'IXC fresh preferred');
same($flow['devices'][0]['tx_power'], 2.5, 'IXC tx');
same($flow['devices'][0]['temperature'], 75.1, 'IXC temperature');
same($flow['devices'][0]['pppoe_username'], 'cliente_A', 'existing TR-069 login preserved');
same($flow['devices'][0]['diagnostics']['rx_status'], 'critico', 'explicit critical');
same($flow['devices'][2]['diagnostics']['rx_status'], 'sem_dados', 'stale');
truth(!array_key_exists('wifi_password', $flow['devices'][0]), 'no wifi password');
truth(!array_key_exists('ping', $flow['devices'][0]), 'no fake ping');
truth(!str_contains(NbiReadOnly::projection(), 'Password'), 'never request passwords');

$ixc[IxcReadOnly::serial('AA:BB:01')]['measured_at_epoch'] = $now - 86400;
$stale = Consolidator::fleet([$raw[0]], $ixc, $now, 70.0);
same($stale['devices'][0]['rx_source'], 'tr069', 'old IXC may not override live TR-069');
same($stale['devices'][0]['diagnostics']['rx_status'], 'normal', 'fresh TR-069 still valid');
// A fresh Inform does not refresh optical data. Parameter timestamp must be fresh.
$withoutTimestamp = $make('D', 'AA:BB:04', 10, -22.1);
unset($withoutTimestamp['VirtualParameters']['RXPower']['_timestamp']);
$without = Consolidator::fleet([$withoutTimestamp], [], $now);
same($without['devices'][0]['rx_measured_at_epoch'], 0, 'missing measurement timestamp never inherits Inform');
same($without['devices'][0]['diagnostics']['rx_status'], 'sem_dados', 'missing timestamp must be unknown');

$oldOptic = $make('E', 'AA:BB:05', 10, -22.1);
$oldOptic['VirtualParameters']['RXPower']['_timestamp'] = gmdate('c', $now - 86400);
$oldResult = Consolidator::fleet([$oldOptic], [], $now);
same($oldResult['devices'][0]['diagnostics']['rx_status'], 'sem_dados', 'old RX cannot inherit fresh Inform');
same($oldResult['devices'][0]['rx_measured_at_epoch'], $now - 86400, 'real RX timestamp preserved');

same(Diagnostics::number('0', -80, -0.00001), null, 'zero is absent');
same(Diagnostics::number('-999', -80, -0.00001), null, 'sentinel');
same(Diagnostics::number('-26,34', -80, -0.00001), -26.34, 'comma decimal');

try {
    Config::requireSafeUrl('http://external.example:7557', true);
    throw new RuntimeException('HTTP publico deveria falhar');
} catch (RuntimeException $e) {
    if ($e->getMessage() === 'HTTP publico deveria falhar') throw $e;
}
same(Config::requireSafeUrl('http://127.0.0.1:7557', true),
     'http://127.0.0.1:7557', 'localhost GET allowed');
echo "JRC_COLLECTOR_UNIT_TESTS_OK\n";
