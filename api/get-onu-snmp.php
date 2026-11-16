<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
requireLogin();
require_once __DIR__ . '/../lib/JrcSnmpTelemetry.php';
header('Content-Type: application/json');
header('Cache-Control: private, no-store');
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
$id = (string)($_GET['device_id'] ?? '');
// GPON serial must be a strict final segment of a GenieACS device identifier.
$segments = explode('-', rawurldecode($id));
$serial = JrcSnmpTelemetry::serial((string)end($segments));
if ($serial === '') {
    http_response_code(400);
    echo json_encode(['success'=>false, 'message'=>'Serial GPON não reconhecido']);
    exit;
}
$device = JrcSnmpTelemetry::apply(['serial_number'=>$serial], null, time(), true);
$response = JrcSnmpTelemetry::optical($device);
$response['device_id'] = $id;
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
