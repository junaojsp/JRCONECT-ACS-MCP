<?php
declare(strict_types=1);

/**
 * Auxiliary badge count from the existing ACS inventory snapshot.
 *
 * Does NOT replace uplink-stats.php: the optical-signal overview may use a
 * different source/denominator (IXC). Never subtract this count from its
 * "sem leitura" value without proving both populations are identical.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/JrcEquipmentClassification.php';

header('Content-Type: application/json; charset=utf-8');
requireLogin();
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

try {
    $db = getDBConnection();
    $state = $db->query('SELECT snapshot_id,total,completed_at,
        TIMESTAMPDIFF(SECOND,completed_at,NOW()) AS age_seconds
        FROM jrc_device_inventory_state WHERE id=1')->fetch_assoc();

    if (!$state) {
        jsonResponse(['success' => false, 'message' => 'Inventario ainda nao coletado'], 503);
    }
    $expected = (int)$state['total'];
    if ($expected < 1 || $expected > 100000) {
        throw new RuntimeException('Quantidade de dispositivos invalida');
    }
    $id = (string)$state['snapshot_id'];
    $stmt = $db->prepare('SELECT payload_json FROM jrc_device_inventory_rows WHERE snapshot_id=?');
    $stmt->bind_param('s', $id);
    $stmt->execute();
    $result = $stmt->get_result();

    $devices = [];
    while ($row = $result->fetch_assoc()) {
        $device = json_decode($row['payload_json'], true);
        if (!is_array($device)) throw new RuntimeException('Snapshot invalido');
        $devices[] = $device;
    }
    $stmt->close();

    if (count($devices) !== $expected) {
        throw new RuntimeException('Snapshot incompleto/trocado');
    }

    $totals = JrcEquipmentClassification::summarize($devices);
    // No subscriber details, serials, IPs or passwords are returned.
    jsonResponse([
        'success' => true,
        'source' => 'jrc_inventory_snapshot',
        'scope' => 'genieacs',
        'routers' => $totals['roteadores_sem_rx'],
        'total' => $totals['total'],
        'confirmed_optical' => $totals['onus'],
        'unknown_type' => $totals['desconhecidos'],
        // Same snapshot and classification as the router count above.
        // These are NEW independent ACS-inventory dimensions, not a
        // replacement for the live optical/IXC fleet counter.
        'optical_no_reading' => $totals['onus_sem_leitura'],
        'optical_with_reading' => $totals['onus_com_rx'],
        'unclassified_no_reading' => $totals['desconhecidos_sem_leitura'],
        'cached_at' => $state['completed_at'],
        'cache_age_seconds' => max(0, (int)$state['age_seconds']),
        'cache_stale' => (int)$state['age_seconds'] > 900
    ]);
} catch (Throwable $error) {
    error_log('JRC router classification read failed: ' . get_class($error));
    jsonResponse(['success' => false, 'message' => 'Resumo de tipos indisponivel'], 503);
}
