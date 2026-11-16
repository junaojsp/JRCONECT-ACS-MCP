<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/JrcAdaptiveOpticalRead.php';
function sample(string $branch, mixed $value, string $date): array {
 return ['InternetGatewayDevice'=>['WANDevice'=>['1'=>[$branch=>[
  'RXPower'=>['_value'=>$value,'_timestamp'=>$date],
  'TransceiverTemperature'=>['_value'=>39,'_timestamp'=>$date]
 ]]]]];
}
$now=strtotime('2026-11-16T15:00:00Z');
$recent='2026-11-16T14:59:00Z';
$stale='2026-11-15T12:00:00Z';
$tests=[
 ['Huawei recente',sample('X_GponInterfaceConfig',-22.7,$recent),[],true],
 ['FiberHome recente',sample('X_FH_GponInterfaceConfig',-23.2,$recent),[],true],
 ['RX antigo',sample('X_GponInterfaceConfig',-21.4,$stale),[],false],
 ['Escala desconhecida',sample('X_GponInterfaceConfig',2451,$recent),[],false],
 ['Valor invalido',sample('X_GponInterfaceConfig','indisponivel',$recent),[],false],
 ['Preservar leitor atual',sample('X_GponInterfaceConfig',-22.7,$recent),['rx_power'=>'-20.00','temperature'=>'37.0'],false],
];
foreach($tests as [$label,$raw,$parsed,$expected]) {
 $actual=JrcAdaptiveOpticalRead::enrich($raw,$parsed,$now);
 if (isset($actual['rx_path']) !== $expected) throw new RuntimeException($label);
 if ($label==='Preservar leitor atual' && ($actual['rx_power']!=='-20.00'||$actual['temperature']!=='37.0')) throw new RuntimeException('Overwrite');
 if ($expected && ($actual['rx_measured_at_epoch']!==strtotime($recent)||$actual['temperature_measured_at_epoch']!==strtotime($recent))) throw new RuntimeException('timestamp');
}
echo "JRC_ADAPTIVE_OPTICAL_TESTS_OK\n";
