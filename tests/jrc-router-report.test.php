<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/JrcRouterReportRows.php';

function expect(mixed $got, mixed $want, string $label): void {
    if ($got !== $want) {
        throw new RuntimeException($label . ': ' .
            var_export($got, true) . ' !== ' . var_export($want, true));
    }
}

$router = [
    'manufacturer' => 'D-LINK',
    'product_class' => 'Router',
    'pppoe_username' => 'cliente_roteador',
    'serial_number' => 'TK181JB009564',
    'ip_address' => '100.127.23.28',
    'rx_power' => 'N/A',
    'last_inform' => '2026-11-16 09:00:00'
];
$row = JrcRouterReportRows::project($router);
expect(is_array($row), true, 'Roteador deve constar no relatorio');
expect($row['equipment'], 'D-LINK Router', 'Modelo mostrado');
expect($row['pppoe'], 'cliente_roteador', 'Nome PPPoE');
expect($row['serial'], 'TK181JB009564', 'Serial mostrado');
expect(JrcRouterReportRows::matches($row, 'CLIENTE_ROTEADOR'), true, 'Buscar PPPoE');
expect(JrcRouterReportRows::matches($row, '100.127.23'), true, 'Buscar IP');
expect(JrcRouterReportRows::matches($row, 'nonexistent'), false, 'Excluir nao corresponde');
expect(JrcRouterReportRows::matches($row, ''), true, 'Sem filtro');

$onu = [
    'manufacturer' => 'FiberHome', 'product_class' => 'HG6143D',
    'rx_power' => 'N/A', 'pppoe_username' => 'onu_sem_leitura'
];
expect(JrcRouterReportRows::project($onu), null, 'ONU sem RX nao e roteador');

$ontRouter = [
    'product_class' => 'GPON Router ONT',
    'rx_power' => 'N/A'
];
expect(JrcRouterReportRows::project($ontRouter), null, 'ONT com roteamento nao vira roteador simples');

$routerWithRx = [
    'product_class' => 'D-LINK Router', 'rx_power' => '-26.38'
];
expect(JrcRouterReportRows::project($routerWithRx), null, 'Com optica real e ONU');

$unknown = ['product_class' => 'Generico', 'rx_power' => 'N/A'];
expect(JrcRouterReportRows::project($unknown), null, 'Desconhecido nao aparece');

echo "JRC_ROUTER_REPORT_TESTS_OK\n";
