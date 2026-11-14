<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

requireLogin();

$ch = curl_init('http://127.0.0.1:3100/connections');
if ($ch === false) {
    jsonResponse(['success' => false, 'message' => 'Falha ao iniciar consulta ao MCP.'], 500);
}

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 1,
    CURLOPT_TIMEOUT => 3,
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
]);

$body = curl_exec($ch);
$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($body === false || $http !== 200) {
    jsonResponse([
        'success' => false,
        'message' => 'MCP indisponível para consulta local.',
        'detail' => $error !== '' ? $error : ('HTTP ' . $http),
    ], 502);
}

$data = json_decode((string)$body, true);
if (!is_array($data) || !($data['success'] ?? false) || !is_array($data['connections'] ?? null)) {
    jsonResponse(['success' => false, 'message' => 'Resposta inválida do MCP.'], 502);
}

jsonResponse([
    'success' => true,
    'connections' => array_values($data['connections']),
    'services' => is_array($data['services'] ?? null) ? array_values($data['services']) : [],
    'count' => count($data['connections']),
]);
