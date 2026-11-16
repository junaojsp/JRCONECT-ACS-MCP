<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/JrcOnuPilotPaths.php';

function assertPilot(bool $truth, string $message): void {
    if (!$truth) throw new RuntimeException($message);
}

$now = strtotime('2026-11-16T17:00:00Z');
$raw = [
    'InternetGatewayDevice' => [
        '_timestamp' => '2026-11-16T16:59:00Z',
        'WANDevice' => ['1' => [
            'X_GponInterfaceConfig' => [
                '_timestamp' => '2026-11-16T16:58:00Z',
                'RXPower' => [
                    '_value' => '-22.60',
                    '_timestamp' => '2026-11-16T16:57:00Z'
                ],
                'TransceiverTemperature' => [
                    '_value' => 36.4,
                    '_timestamp' => '2026-11-16T16:52:00Z'
                ],
                'SecretToken' => [
                    'RxPower' => ['_value' => 'SECRET_EXCLUDE']
                ]
            ],
            'X_FH_GponInterfaceConfig' => [
                'RXPower' => [
                    '_value' => null,
                    '_timestamp' => '2026-11-16T16:48:00Z'
                ]
            ],
            'WANConnectionDevice' => ['1' => [
                'WANPPPConnection' => ['1' => [
                    'Username' => ['_value' => 'leak_user'],
                    'Password' => ['_value' => 'leak_pass'],
                ]]
            ]]
        ]]
    ],
    'VirtualParameters' => [
        'RXPower' => ['_value' => -23.2, '_timestamp' => '2026-11-16T16:59:00Z'],
        'gettemp' => ['_value' => 'N/A', '_timestamp' => '2026-11-16T16:30:00Z'],
    ]
];

$paths = JrcOnuPilotPaths::inspect($raw, $now);
$byPath = [];
foreach ($paths as $record) {
    $byPath[$record['path']] = $record;
}
$h = 'InternetGatewayDevice.WANDevice.1.X_GponInterfaceConfig.RXPower';
$f = 'InternetGatewayDevice.WANDevice.1.X_FH_GponInterfaceConfig.RXPower';
assertPilot(isset($byPath[$h]), 'Huawei RX must be discovered beneath a timestamped object');
assertPilot($byPath[$h]['mapped'] === false, 'Huawei path not mapped by current fast parser');
assertPilot($byPath[$h]['state'] === 'NUMERICO', 'Huawei numeric RX only state, not raw value');
assertPilot($byPath[$h]['age_minutes'] === 3, 'Timestamp age should be 3 min');
assertPilot($byPath[$h]['raw_optical_sample'] === '-22.60', 'Raw RX sample, units unverified');
assertPilot($byPath[$f]['raw_optical_sample'] === null, 'Empty FiberHome RX has no sample');
assertPilot($byPath['InternetGatewayDevice.WANDevice.1.X_GponInterfaceConfig.TransceiverTemperature']['raw_optical_sample'] === '36.4', 'Huawei raw temperature sample');
assertPilot($byPath['VirtualParameters.RXPower']['raw_optical_sample'] === null, 'Generic virtual params never expose raw values');
assertPilot(isset($byPath[$f]) && $byPath[$f]['state'] === 'VAZIO',
    'Fiberhome RX path present but empty must not be absent');
assertPilot(isset($byPath['VirtualParameters.RXPower']) &&
    $byPath['VirtualParameters.RXPower']['mapped'] === true,
    'VirtualParameters RX path is mapped');
assertPilot($byPath['VirtualParameters.gettemp']['state'] === 'NAO_NUMERICO',
    'Invalid temperature is not numeric');
$encoded = json_encode($paths);
assertPilot(is_string($encoded), 'JSON encoding');
foreach (['SECRET_EXCLUDE', 'leak_user', 'leak_pass', '-23.2'] as $secret) {
    assertPilot(!str_contains($encoded, $secret), 'No raw values in audit output');
}
assertPilot(!str_contains($encoded, 'SecretToken'), 'Sensitive path suppressed');
assertPilot(JrcOnuPilotPaths::inspect([], $now) === [], 'Empty tree');
assertPilot(count(JrcOnuPilotPaths::inspect($raw, $now, 1)) === 1,
    'Bound optical metadata output');

echo "JRC_ONU_PILOT_PATHS_TESTS_OK\n";
