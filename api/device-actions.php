<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/TrackedGenieACS.php';

use App\TrackedGenieACS;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse([
        'success' => false,
        'message' => 'Método não permitido.'
    ], 405);
}

requireLogin();

try {

    $data = json_decode(
        (string)file_get_contents('php://input'),
        true
    );

    if (!is_array($data)) {
        jsonResponse([
            'success' => false,
            'message' => 'Requisição inválida.'
        ], 400);
    }

    $deviceId = trim((string)($data['device_id'] ?? ''));
    $action   = trim((string)($data['action'] ?? ''));

    if (
        $deviceId === '' ||
        strlen($deviceId) > 512 ||
        preg_match('/[\x00-\x1f]/', $deviceId)
    ) {
        jsonResponse([
            'success' => false,
            'message' => 'Equipamento inválido.'
        ], 400);
    }

    if (!in_array($action, ['refresh', 'reboot'], true)) {
        jsonResponse([
            'success' => false,
            'message' => 'Ação não suportada.'
        ], 400);
    }

    $db = getDBConnection();

    $uid = (int)($_SESSION['user_id'] ?? 0);

    $stmt = $db->prepare(
        'SELECT role, active
         FROM users
         WHERE id = ?
         LIMIT 1'
    );

    $stmt->bind_param('i', $uid);
    $stmt->execute();

    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user || (int)$user['active'] !== 1) {
        jsonResponse([
            'success' => false,
            'message' => 'Usuário sem permissão.'
        ], 403);
    }

    $role = strtolower((string)$user['role']);

    if (
        $action === 'reboot' &&
        !in_array($role, ['admin', 'noc'], true)
    ) {
        jsonResponse([
            'success' => false,
            'message' => 'Reinício permitido apenas para Admin/NOC.'
        ], 403);
    }

    if (
        $action === 'reboot' &&
        ($data['confirm'] ?? '') !== 'REINICIAR'
    ) {
        jsonResponse([
            'success' => false,
            'message' => 'Confirmação inválida.'
        ], 400);
    }

    $result = $db->query(
        'SELECT host, port, username, password
         FROM genieacs_credentials
         WHERE is_connected = 1
         ORDER BY id DESC
         LIMIT 1'
    );

    $credentials = $result->fetch_assoc();

    if (!$credentials) {
        jsonResponse([
            'success' => false,
            'message' => 'GenieACS não conectado.'
        ], 503);
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $acs = new TrackedGenieACS(
        $credentials['host'],
        (int)$credentials['port'],
        $credentials['username'],
        $credentials['password']
    );

    if ($action === 'reboot') {

        $result = $acs->rebootDevice($deviceId);

        if (!empty($result['success'])) {
            jsonResponse([
                'success' => true,
                'message' => 'Comando de reinício enviado ao equipamento.'
            ]);
        }

        jsonResponse([
            'success' => false,
            'message' => 'O GenieACS não aceitou o comando de reinício.'
        ], 502);
    }

    /*
     * Atualiza os objetos utilizados pelo painel.
     * TR-098 + TR-181.
     */
    $result = $acs->refreshDeviceDiagnostics($deviceId);

    if (!empty($result['success'])) {
        jsonResponse([
            'success' => true,
            'message' => 'Nova coleta TR-069 solicitada ao equipamento.'
        ]);
    }

    jsonResponse([
        'success' => false,
        'message' => 'Não foi possível solicitar a nova coleta.'
    ], 502);

} catch (Throwable $e) {

    error_log(
        'JRC device action error: ' . $e->getMessage()
    );

    jsonResponse([
        'success' => false,
        'message' => 'Falha ao executar a ação.'
    ], 500);
}
