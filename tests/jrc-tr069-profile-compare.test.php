<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/JrcTr069ProfileCompare.php';

function verifyCmp(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
$now = strtotime('2026-11-16T15:00:00Z');
function deviceCmp(string $serial, string $model, string $firmware, int $inform,
    array $optical = [], string $maker = 'Huawei Technologies Co., Ltd'): array {
    $now = strtotime('2026-11-16T15:00:00Z');
    $device = [
        '_id' => 'fake-' . $serial,
        '_deviceId' => [
            '_Manufacturer' => $maker,
            '_ProductClass' => $model,
            '_SerialNumber' => $serial,
            '_OUI' => '485754',
        ],
        '_lastInform' => gmdate('c', $now - $inform),
        'InternetGatewayDevice' => [
            'DeviceInfo' => ['SoftwareVersion' => ['_value' => $firmware]]
        ]
    ];
    return array_replace_recursive($device, $optical);
}
function rxCmp(string $branch, mixed $value, int $ageSec): array {
    $now = strtotime('2026-11-16T15:00:00Z');
    return [
        'InternetGatewayDevice' => ['WANDevice' => ['1' => [$branch => [
            'RXPower' => ['_value' => $value, '_timestamp' => gmdate('c', $now - $ageSec)]
        ]]]]
    ];
}
$targetRaw = deviceCmp('485754436BCD129E', 'HG8546M', 'V3R017C00S125', 120);
$okSameModel = deviceCmp('48575443ABCDEF00', 'HG8546M', 'V3R017C00S125',
    60, rxCmp('X_GponInterfaceConfig', -21.2, 120));
$okOtherFirmware = deviceCmp('48575443ABCDEF11', 'HG8546M', 'V3R019C00S100',
    60, rxCmp('X_GponInterfaceConfig', -22.1, 60));
$okOtherModel = deviceCmp('48575443ABCDEF22', 'EG8145V5', 'V5R019C00S100',
    60, ['VirtualParameters' => ['RXPower' => [
        '_value' => -23.0, '_timestamp' => gmdate('c', $now - 60)
    ]]]);
$stale = deviceCmp('48575443ABCDEF33', 'HG8546M', 'V3R017C00S125',
    60, rxCmp('X_GponInterfaceConfig', -22.3, 15 * 86400));
$router = deviceCmp('TK181000000', 'D-LINK Router', 'v1', 60, [], 'D-LINK');

$a = JrcTr069ProfileCompare::profile($targetRaw, $now);
$b = JrcTr069ProfileCompare::profile($okSameModel, $now);
$c = JrcTr069ProfileCompare::profile($okOtherFirmware, $now);
$d = JrcTr069ProfileCompare::profile($okOtherModel, $now);
$e = JrcTr069ProfileCompare::profile($stale, $now);
verifyCmp(!$a['rx_numeric'] && $a['rx'] === [],
    'A Huawei com Inform recente pode nao ter caminho RX cadastrado');
verifyCmp($b['rx_numeric'] && $b['rx_fresh_numeric'], 'B tem RX cadastrado e recente');
verifyCmp($b['rx'][0]['reader_mapped'] === false,
    'Huawei publica caminho nao reconhecido pelo leitor rapido');
verifyCmp($e['rx_numeric'] && !$e['rx_fresh_numeric'],
    'Inform recente com parametro RX velho nao e sinal atual');
verifyCmp(!JrcTr069ProfileCompare::isHuawei($router), 'Roteador D-LINK nao e Huawei');

$profiles = [$d, $e, $c, $b];
$choice = JrcTr069ProfileCompare::chooseComparator($a, $profiles);
verifyCmp($choice !== null && $choice['serial'] === $b['serial'],
    'Priorizar RX fresco, modelo igual e firmware igual');
$choice = JrcTr069ProfileCompare::chooseComparator($a, [$e, $d]);
verifyCmp($choice !== null && $choice['serial'] === $d['serial'],
    'Nao escolher RX obsoleto por ser do mesmo modelo');
$choice = JrcTr069ProfileCompare::chooseComparator($a, [$e]);
verifyCmp($choice !== null && !$choice['rx_fresh_numeric'],
    'Historico e comparavel, mas nao e prova de RX atual');
verifyCmp(JrcTr069ProfileCompare::chooseComparator($a, [$a]) === null,
    'Nao comparar uma ONU com ela mesma');

$diff = JrcTr069ProfileCompare::differences($a, $b);
verifyCmp($diff['same_model'] && $diff['same_firmware'],
    'Modelos e firmware iguais com disponibilidade distinta');
verifyCmp(count($diff['paths_only_reference']) === 1 &&
    str_contains($diff['paths_only_reference'][0], 'X_GponInterfaceConfig.RXPower'),
    'Caminho so na ONU funcional: possivel descoberta/provisionamento');

$secret = deviceCmp('48575443ABCDEF99', 'HG8546M', 'test', 90, [
    'InternetGatewayDevice' => ['WANDevice' => ['1' => [
        'WANConnectionDevice' => ['1' => ['WANPPPConnection' => ['1' => [
            'Username' => ['_value' => 'subscriber@example.com'],
            'Password' => ['_value' => 'secret']
        ]]]],
        'X_GponInterfaceConfig' => [
            'RXPower' => ['_value' => -24.2, '_timestamp' => gmdate('c', $now - 40)],
            'SecretToken' => ['RxPower' => ['_value' => 300]],
            'Stats' => ['RxPackets' => ['_value' => 999]],
            'TransceiverTemperature' => ['_value' => 42.0,
                '_timestamp' => gmdate('c', $now - 40)]
        ]
    ]]]
]);
$parsed = JrcTr069ProfileCompare::profile($secret, $now);
verifyCmp(count($parsed['rx']) === 1, 'Contadores RX e caminhos de segredo nao sao RX optico');
verifyCmp(count($parsed['temperature']) === 1, 'Temperatura optica extraida');
verifyCmp(!$parsed['rx'][0]['unit_verified'], 'Nao adivinhar unidades');
$encoded = json_encode($parsed);
foreach (['subscriber@example.com', 'secret', 'RxPackets', '-24.2'] as $private) {
    verifyCmp(!str_contains((string)$encoded, $private), 'Nao imprimir dados brutos ou PPPoE');
}

// Unknown equipment models can be profiled by observed path pattern instead
// of a manually registered entry per new manufacturer.
$unknown = deviceCmp('OTHER-MODEL-001', 'CPE-X927', '1.0.9', 60, [
    'InternetGatewayDevice' => ['WANDevice' => ['1' => [
        'X_NewBrand_OpticalInfo' => ['OpticalRxPower' => [
            '_value' => '-19.4', '_timestamp' => gmdate('c', $now - 30)
        ]]
    ]]]
], 'New Brand');
$u = JrcTr069ProfileCompare::profile($unknown, $now);
verifyCmp(count($u['rx']) === 1 && $u['rx_fresh_numeric'],
    'Novo modelo com caminho conhecido por padrao generico, sem cadastro manual');
verifyCmp(!$u['rx'][0]['reader_mapped'],
    'Caminho dinamico e candidato, nao esta homologado no leitor');

echo "JRC_TR069_HUAWEI_COMPARE_TESTS_OK\n";
