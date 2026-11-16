<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/JrcTr069OpticalTelemetry.php';
function deviceFor(?string $value, ?string $timestamp, bool $withPath = true): array {
    $d = ['_deviceId' => ['_OUI'=>'00259E','_Manufacturer'=>'Huawei','_ProductClass'=>'HG8546M','_SerialNumber'=>'TEST1234'],
      'InternetGatewayDevice'=>['DeviceInfo'=>['SoftwareVersion'=>['_value'=>'V3R017']]]];
    if ($withPath) {
        $node = [];
        if ($value !== null) $node['_value'] = $value;
        if ($timestamp !== null) $node['_timestamp'] = $timestamp;
        $d['InternetGatewayDevice']['WANDevice'][1]['X_GponInterfaceConfig']['RXPower'] = $node;
    }
    return $d;
}
$now = strtotime('2026-11-16T15:00:00Z');
$cases = [
 ['CAMINHO_AUSENTE',deviceFor(null,null,false)],
 ['PARAMETRO_SEM_VALOR',deviceFor(null,'2026-11-16T14:59:00Z')],
 ['VALOR_NAO_NUMERICO',deviceFor('indisponivel','2026-11-16T14:59:00Z')],
 ['LEITURA_DESATUALIZADA',deviceFor('-22.59','2026-11-16T12:00:00Z')],
 ['LEITURA_RECENTE',deviceFor('-22.59','2026-11-16T14:59:00Z')],
];
foreach ($cases as [$expected,$device]) {
    $got = JrcTr069OpticalTelemetry::inspect($device,$now);
    if ($got['status'] !== $expected || $got['can_display_dbm'] !== false) {
        throw new RuntimeException("Expected $expected, got " . $got['status']);
    }
    if ($expected !== 'CAMINHO_AUSENTE' && count($got['rx_paths_unmapped']) !== 1) {
        throw new RuntimeException('Caminho desconhecido nao detectado');
    }
}
echo "JRC_TR069_OPTICAL_STATES_OK\n";
