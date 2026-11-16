<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/JrcOnuTelemetryAudit.php';

function check(mixed $value, mixed $wanted, string $label): void {
    if ($value !== $wanted) {
        throw new RuntimeException($label . ': ' .
            var_export($value, true) . ' != ' . var_export($wanted, true));
    }
}
$now = strtotime('2026-11-16T14:00:00Z');
function device(string $model, int $timestamp, array $extras = []): array {
    return array_replace_recursive([
        '_deviceId' => ['_Manufacturer' => 'Teste', '_ProductClass' => $model],
        '_lastInform' => gmdate('c', $timestamp)
    ], $extras);
}
function parsed(string $model, string $rx = 'N/A', string $temp = 'N/A'): array {
    return [
        'manufacturer' => 'Teste',
        'product_class' => $model,
        'software_version' => 'v1',
        'rx_power' => $rx, 'temperature' => $temp
    ];
}

$router = JrcOnuTelemetryAudit::inspect(
    device('D-LINK Router', $now - 60),
    parsed('D-LINK Router'), $now
);
check($router['status'], 'ROTEADOR_SEM_RX_ESPERADO', 'Roteador comum');
check($router['type'], 'roteador', 'Tipo do roteador');

$onuOk = JrcOnuTelemetryAudit::inspect(
    device('HG6143D', $now - 65, [
        'VirtualParameters' => ['RXPower' => ['_value' => -23.3]]
    ]),
    parsed('HG6143D', '-23.3', '38.2'), $now
);
check($onuOk['status'], 'RX_PRESENTE_INFORM_RECENTE', 'RX valido');
check($onuOk['temp_valid'], true, 'Temperatura valida');

$missingPath = JrcOnuTelemetryAudit::inspect(
    device('HG6143D', $now - 120),
    parsed('HG6143D'), $now
);
check($missingPath['status'], 'CAMINHO_RX_NAO_DESCOBERTO',
      'ONU comunicando mas RX nao cadastrado');

$alternatePath = JrcOnuTelemetryAudit::inspect(
    device('HG6143D', $now - 30, [
        'InternetGatewayDevice' => ['WANDevice' => ['1' => [
            'X_VENDOR_OpticalInfo' => [
                'RxOpticalPower' => ['_value' => '-21.50', '_timestamp' => 123]
            ]
        ]]]
    ]),
    parsed('HG6143D'), $now
);
check($alternatePath['status'], 'CAMINHO_OU_FORMATO_RX_NAO_MAPEADO',
      'Modelo publica RX em ramo alternativo');
check(count($alternatePath['rx_paths']), 1, 'Caminho RX localizado');
check(str_contains($alternatePath['rx_paths'][0], 'X_VENDOR_OpticalInfo'),
      true, 'Variação fabricante detectada');

$emptyValue = JrcOnuTelemetryAudit::inspect(
    device('HG6245D', $now - 30, [
        'VirtualParameters' => [
            'RXPower' => ['_value' => null]
        ]
    ]), parsed('HG6245D'), $now
);
check($emptyValue['status'], 'PARAMETRO_RX_SEM_VALOR', 'Parametro existe mas vazio');

$oldInform = JrcOnuTelemetryAudit::inspect(
    device('EG8145V5', $now - 86400),
    parsed('EG8145V5'), $now
);
check($oldInform['status'], 'INFORM_ANTIGO_OU_AUSENTE',
      'Inform antigo nao deve ser chamado de incompatibilidade');

$historicalRx = JrcOnuTelemetryAudit::inspect(
    device('HG6143D', $now - 86400),
    parsed('HG6143D', '-22.40'), $now
);
check($historicalRx['status'], 'RX_CADASTRADO_INFORM_ANTIGO',
      'RX antigo nao e leitura atual');

$unknown = JrcOnuTelemetryAudit::inspect(
    device('DeviceX9', $now - 30), parsed('DeviceX9'), $now
);
check($unknown['status'], 'TIPO_A_CONFIRMAR',
      'Nao presumir que modelo desconhecido seja ONU com falha');

$fakeWifiSecret = JrcOnuTelemetryAudit::candidatePaths([
    'InternetGatewayDevice' => [
        'LANDevice' => ['1' => [
            'PreSharedKey' => [
                '1' => ['RxPower' => ['_value' => 'SHOULD_NEVER_LEAK']]
            ]
        ]]
    ]
], 'rx');
check($fakeWifiSecret, [], 'Nunca processar caminho de senha');

$report = JrcOnuTelemetryAudit::summarize([
    $router, $onuOk, $missingPath, $alternatePath,
    $emptyValue, $oldInform, $historicalRx, $unknown
]);
check($report['totais']['analisados'], 8, 'Total');
check($report['totais']['roteadores'], 1, 'Roteadores');
check($report['totais']['tipo_a_confirmar'], 1, 'Desconhecidos');
check($report['totais']['onus'], 6, 'ONUs reconhecidas');
check($report['totais']['onus_sem_rx'], 4, 'ONUs sem RX cadastrado');
check($report['totais']['onus_sem_rx_com_inform_recente'], 3,
      'Sem RX e comunicacao recente');

echo "JRC_ONU_TELEMETRY_AUDIT_TESTS_OK\n";
