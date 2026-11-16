<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/JrcEquipmentClassification.php';

function eq(mixed $a, mixed $b, string $name): void {
    if ($a !== $b) throw new RuntimeException(
        $name . ': ' . var_export($a, true) . ' != ' . var_export($b, true)
    );
}

$router = ['manufacturer' => 'D-LINK', 'product_class' => 'D-LINK Router',
           'rx_power' => 'N/A'];
$onu = ['manufacturer' => 'FiberHome', 'product_class' => 'HG6245D',
        'rx_power' => 'N/A'];
$huawei = ['manufacturer' => 'Huawei', 'product_class' => 'EG8145V5',
           'rx_power' => 'N/A'];
$missing = ['manufacturer' => 'ACME', 'product_class' => 'Device X9',
            'rx_power' => 'N/A'];
$ontWithRouter = ['product_class' => 'GPON Router ONT', 'rx_power' => 'N/A'];
$routerWithOptical = ['product_class' => 'D-LINK Router', 'rx_power' => '-26.42'];

eq(JrcEquipmentClassification::type($router), 'roteador', 'D-Link comum');
eq(JrcEquipmentClassification::signal($router), 'roteador', 'sem RX nao e falha');
eq(JrcEquipmentClassification::type($onu), 'onu', 'FiberHome ONU');
eq(JrcEquipmentClassification::type($huawei), 'onu', 'Huawei ONU');
eq(JrcEquipmentClassification::signal($onu), 'sem_leitura', 'ONU sem RX');
eq(JrcEquipmentClassification::type($missing), 'desconhecido',
   'Sem sinal isoladamente nao prova ser roteador');
eq(JrcEquipmentClassification::signal($missing), 'sem_leitura', 'Desconhecido');
eq(JrcEquipmentClassification::type($ontWithRouter), 'onu', 'Router GPON e ONU');
eq(JrcEquipmentClassification::type($routerWithOptical), 'onu',
   'Optica observada prevalece sobre rotulo Router');
eq(JrcEquipmentClassification::signal(['product_class' => 'HG6143D',
   'rx_power' => '-28.23']), 'critico', 'Critico real nao escondido');
eq(JrcEquipmentClassification::signal(['product_class' => 'HG6143D',
   'rx_power' => '-26,19']), 'fraco', 'Valor com virgula');
eq(JrcEquipmentClassification::signal(['product_class' => 'HG6143D',
   'rx_power' => '0']), 'sem_leitura', 'Zero nao e sinal valido');
eq(JrcEquipmentClassification::signal(['product_class' => 'HG6143D',
   'rx_power' => '-999']), 'sem_leitura', 'Sentinela');
eq(JrcEquipmentClassification::summarize(
   [$router, $onu, $missing, $routerWithOptical]
), [
    'total' => 4, 'roteadores' => 1, 'roteadores_sem_rx' => 1,
    'onus' => 2, 'desconhecidos' => 1
], 'Resumo consistente');

echo "JRC_ROUTER_CLASSIFICATION_TESTS_OK\n";
