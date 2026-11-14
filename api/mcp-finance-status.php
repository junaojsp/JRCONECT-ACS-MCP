<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
requireLogin();

// Connectivity only. Never pass bearer tokens, invoke financial tools or
// interpret a 401 as proof that an OAuth user is authenticated.
$ch = curl_init('http://127.0.0.1:3100/mcp-finance');
if ($ch === false) {
    jsonResponse(['success' => true, 'endpoint_reachable' => false, 'http_status' => 0]);
}
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 2,
    CURLOPT_TIMEOUT => 4,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_MAXREDIRS => 0,
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
]);
curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

jsonResponse([
    'success' => true,
    'endpoint_reachable' => $code === 401,
    'http_status' => $code,
    'endpoint' => 'https://mcp.jrconect.com/mcp-finance',
    'auth' => 'OAuth / Keycloak',
    'authenticated' => false,
    'tool_count_verified' => null,
]);
