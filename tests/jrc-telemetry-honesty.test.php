<?php
require_once __DIR__ . '/../lib/GenieACS.php';
require_once __DIR__ . '/../lib/GenieACS_Fast.php';
$normal = new App\GenieACS();
foreach ([null, 1200, -22.5, 0, -999] as $rx) {
    $device = ['_id' => 'test', '_lastInform' => gmdate('c'),
        'VirtualParameters' => ['RXPower' => ['_value' => $rx],
            'Ping' => ['_value' => 7, '_timestamp' => gmdate('c')]]];
    foreach ([$normal->parseDeviceData($device), App\GenieACS_Fast::parseDeviceDataFast($device)] as $row) {
        if ($row['ping'] !== null || $row['ping_issue'] !== 'medicao_nao_verificada') {
            throw new RuntimeException('Unverified latency must never become a measurement');
        }
        $expected = $rx === -22.5 ? '-22.50' : 'N/A';
        if ($row['rx_power'] !== $expected) throw new RuntimeException('RX conversion guessed or parsers disagree');
    }
}
echo "Telemetry honesty: OK\n";
