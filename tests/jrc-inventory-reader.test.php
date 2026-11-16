<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/JrcIxcInventoryReader.php';
function check($actual, $expected, $message): void {
    if ($actual !== $expected) throw new RuntimeException($message . ': ' . var_export($actual, true));
}
check(JrcIxcInventoryReader::normalSerial('ciot:086a:3a7f'), 'CIOT086A3A7F', 'normalizacao');
check(JrcIxcInventoryReader::rx('-24,75'), -24.75, 'decimal IXC');
check(JrcIxcInventoryReader::rx(-27.2), -27.2, 'numeric');
check(JrcIxcInventoryReader::rx('0'), null, 'zero nao e sinal');
check(JrcIxcInventoryReader::rx('-999'), null, 'sentinela');
check(JrcIxcInventoryReader::rx('abc'), null, 'valor invalido');
check(JrcIxcInventoryReader::rx(''), null, 'vazio');
echo "TESTES_OK - normalizacao e RX\n";
