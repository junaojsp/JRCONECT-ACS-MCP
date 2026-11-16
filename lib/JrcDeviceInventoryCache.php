<?php
declare(strict_types=1);

/**
 * Inventario de leitura: snapshots completos, publicados atomicamente.
 * Nao executa tarefas TR-069 nem altera dispositivos.
 */
final class JrcDeviceInventoryCache
{
    public static function createTables(mysqli $db): void
    {
        $db->query("CREATE TABLE IF NOT EXISTS jrc_device_inventory_rows (
            snapshot_id CHAR(32) NOT NULL,
            device_id VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
            serial VARCHAR(128) DEFAULT NULL,
            payload_json MEDIUMTEXT NOT NULL,
            recorded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (snapshot_id, device_id),
            KEY idx_snapshot_serial (snapshot_id, serial)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->query("CREATE TABLE IF NOT EXISTS jrc_device_inventory_state (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            snapshot_id CHAR(32) NOT NULL,
            total INT UNSIGNED NOT NULL,
            ixc_matched INT UNSIGNED NOT NULL DEFAULT 0,
            collection_note VARCHAR(255) NOT NULL DEFAULT '',
            completed_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public static function state(mysqli $db): ?array
    {
        try {
            $result = $db->query('SELECT snapshot_id, total, ixc_matched, collection_note, completed_at
                                  FROM jrc_device_inventory_state WHERE id = 1');
            $row = $result ? $result->fetch_assoc() : null;
            if (!$row) return null;
            $row['stale'] = (time() - strtotime($row['completed_at'])) > 900;
            return $row;
        } catch (Throwable $e) {
            return null; // tables not installed yet: leave existing API operational
        }
    }

    /** Return null if no snapshot exists; never return an incomplete snapshot. */
    public static function page(mysqli $db, int $limit, int $skip): ?array
    {
        $state = self::state($db);
        if ($state === null) return null;
        $limit = max(1, min(500, $limit));
        $skip = max(0, $skip);

        $stmt = $db->prepare('SELECT payload_json FROM jrc_device_inventory_rows
                              WHERE snapshot_id = ? ORDER BY device_id LIMIT ? OFFSET ?');
        $stmt->bind_param('sii', $state['snapshot_id'], $limit, $skip);
        $stmt->execute();
        $rows = $stmt->get_result();
        $devices = [];
        while ($row = $rows->fetch_assoc()) {
            $decoded = json_decode($row['payload_json'], true);
            if (!is_array($decoded)) throw new RuntimeException('Cache corrompido');
            require_once __DIR__ . '/JrcSnmpTelemetry.php';
            $devices[] = JrcSnmpTelemetry::apply($decoded);
        }
        $stmt->close();

        $total = (int)$state['total'];
        return [
            'success' => true,
            'devices' => $devices,
            'count' => count($devices),
            'total' => $total,
            'hasMore' => $skip + count($devices) < $total,
            'pagination' => [
                'limit' => $limit, 'skip' => $skip,
                'returned' => count($devices), 'nextSkip' => $skip + $limit
            ],
            'source' => 'jrc_background_inventory',
            'snapshot' => $state['snapshot_id'],
            'cached_at' => $state['completed_at'],
            'cache_stale' => $state['stale'],
            'ixc_matched' => (int)$state['ixc_matched'],
            'collection_note' => $state['collection_note']
        ];
    }

    /**
     * Build all rows in one transaction; switch snapshot pointer last.
     * A failed collection cannot replace the previous usable snapshot.
     */
    public static function publish(mysqli $db, array $devices, int $ixcMatched, string $note): array
    {
        self::createTables($db);
        $previous = self::state($db);
        $count = count($devices);
        if ($count === 0) throw new RuntimeException('Inventario vazio: publicacao recusada');
        if ($previous && $count < (int)$previous['total'] * 0.75) {
            throw new RuntimeException('Queda superior a 25% no inventario: publicacao recusada');
        }

        $generation = bin2hex(random_bytes(16));
        $priorId = $previous['snapshot_id'] ?? $generation;
        $db->begin_transaction();
        try {
            $stmt = $db->prepare('INSERT INTO jrc_device_inventory_rows
                                  (snapshot_id, device_id, serial, payload_json) VALUES (?, ?, ?, ?)');
            $seen = [];
            foreach ($devices as $device) {
                $id = (string)($device['device_id'] ?? '');
                if ($id === '' || $id === 'N/A' || strlen($id) > 255 || isset($seen[$id])) {
                    throw new RuntimeException('ID de dispositivo ausente, duplicado ou invalido');
                }
                $seen[$id] = true;
                $serial = substr((string)($device['serial_number'] ?? ''), 0, 128);
                unset($device['wifi_password'], $device['ping']); // no Wi-Fi credentials in cache
                $payload = json_encode($device, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
                if ($payload === false) throw new RuntimeException('JSON de dispositivo invalido');
                $stmt->bind_param('ssss', $generation, $id, $serial, $payload);
                $stmt->execute();
            }
            $stmt->close();
            $statement = $db->prepare('INSERT INTO jrc_device_inventory_state
                (id, snapshot_id, total, ixc_matched, collection_note, completed_at)
                VALUES (1, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE snapshot_id = VALUES(snapshot_id),
                total = VALUES(total), ixc_matched = VALUES(ixc_matched),
                collection_note = VALUES(collection_note), completed_at = NOW()');
            $note = substr($note, 0, 255);
            $statement->bind_param('siis', $generation, $count, $ixcMatched, $note);
            $statement->execute();
            $statement->close();
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            throw $e;
        }

        // Keep previous snapshot for in-flight requests. Cleanup cannot undo publication.
        try {
            $stmt = $db->prepare('DELETE FROM jrc_device_inventory_rows
                                  WHERE snapshot_id NOT IN (?, ?)');
            $stmt->bind_param('ss', $generation, $priorId);
            $stmt->execute();
            $stmt->close();
        } catch (Throwable $e) {
            error_log('JRC inventory cleanup: ' . $e->getMessage());
        }
        return ['snapshot' => $generation, 'total' => $count, 'ixc_matched' => $ixcMatched];
    }
}
