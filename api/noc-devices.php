<?php
declare(strict_types=1);
ini_set('display_errors', '0');
ob_start();
$buffer = ob_get_level();
$sent = false;
$reserve = str_repeat(' ', 65536);
function inventoryReply(int $code, array $body): void
{
    global $buffer, $sent;
    while (ob_get_level() >= $buffer) ob_end_clean();
    header_remove('Location');
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store, max-age=0');
    http_response_code($code);
    $sent = true;
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
register_shutdown_function(static function () use (&$sent, &$reserve) {
    $reserve = null;
    if (!$sent) inventoryReply(500, ['success' => false, 'message' => 'Falha interna na consulta dos equipamentos.']);
});
try {
    require_once __DIR__ . '/../config/config.php';
    if (!isLoggedIn()) inventoryReply(401, ['success' => false, 'message' => 'Sessão expirada. Entre novamente.']);
    session_write_close();
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        header('Allow: GET');
        inventoryReply(405, ['success' => false, 'message' => 'Método não permitido.']);
    }
    $id = $_GET['id'] ?? null;
    $skip = $_GET['skip'] ?? '0';
    if (($id !== null && (!is_string($id) || $id === '' || strlen($id) > 1024))
        || !is_string($skip) || !ctype_digit($skip) || strlen($skip) > 5 || (int) $skip > 20000) {
        inventoryReply(400, ['success' => false, 'message' => 'Parâmetros inválidos.']);
    }
    if (!isGenieACSConfigured()) inventoryReply(503, ['success' => false, 'message' => 'GenieACS não configurado.']);
    $db = getDBConnection();
    $result = $db->query('SELECT host, port, username, password FROM genieacs_credentials WHERE is_connected = 1 ORDER BY id DESC LIMIT 1');
    $credentials = $result ? $result->fetch_assoc() : null;
    if (!$credentials) inventoryReply(503, ['success' => false, 'message' => 'Conexão GenieACS não configurada.']);
    require_once __DIR__ . '/../lib/NocGenieACS.php';
    require_once __DIR__ . '/../lib/NocInventory.php';
    require_once __DIR__ . '/../lib/NocIxcOptical.php';
    require_once __DIR__ . '/../lib/IXCConfig.php';
    $genie = new \App\NocGenieACS($credentials['host'], $credentials['port'], $credentials['username'], $credentials['password']);
    $response = $genie->getInventory(100, (int) $skip, 15, $id);
    if (empty($response['success'])) inventoryReply(502, ['success' => false, 'message' => 'Não foi possível consultar o GenieACS.']);
    if ($id !== null && (!$response['data'] || ($response['data'][0]['_id'] ?? null) !== $id)) {
        inventoryReply(404, ['success' => false, 'message' => 'Equipamento não encontrado.']);
    }
    $ixc = null;
    $ixcStatus = 'not_configured';
    $ixcReason = null;
    try {
        $config = getIxcConfig();
        $ixcStatus = 'unavailable';
        $ixc = (new \App\NocIxcOptical($config))->snapshot(12);
        $ixcStatus = 'ok';
    } catch (Throwable $e) {
        $ixcReason = $ixcStatus === 'not_configured' ? 'not_configured' : \App\NocIxcOptical::failureReason($e);
        error_log('[JRC NOC inventory] Optical status=' . $ixcStatus . '; reason=' . $ixcReason);
    }
    $now = time();
    $devices = [];
    foreach ($response['data'] as $device) {
        if (!is_array($device) || !is_string($device['_id'] ?? null)) {
            inventoryReply(502, ['success' => false, 'message' => 'Resposta inválida do inventário GenieACS.']);
        }
        $devices[] = \App\NocInventory::parse($device, $ixc, $now, $id !== null);
    }
    inventoryReply(200, ['success' => true, 'devices' => $devices, 'captured_at' => gmdate(DATE_ATOM, $now),
        'has_more' => $id === null && count($devices) === 100, 'next_skip' => (int) $skip + count($devices),
        'optical_status' => $ixcStatus, 'optical_reason' => $ixcReason]);
} catch (Throwable $e) {
    error_log('[JRC NOC inventory] ' . get_class($e) . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
    inventoryReply(500, ['success' => false, 'message' => 'Falha interna na consulta dos equipamentos.']);
}
